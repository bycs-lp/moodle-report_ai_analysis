<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace report_ai_analysis\local;

use core\di;
use local_ai_manager\manager;
use local_ai_manager\ai_manager_utils;
use local_ai_manager\local\config_manager;
use local_ai_manager\local\connector_factory;
use local_ai_manager\local\prompt_response;
use local_ai_manager\local\userinfo;
use local_ai_manager\local\userusage;
use local_ai_manager\plugininfo\aipurpose;

/**
 * Wrapper class for local_ai_manager\manager to enable dependency injection and mocking in tests.
 *
 * This provider abstracts the AI manager functionality, allowing for easy mocking
 * in unit tests without requiring actual AI service connections.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_request_provider {
    /**
     * Check backend availability before collecting data; the manager still enforces it at request time.
     *
     * @param int $contextid Actual course context
     */
    public function require_available(int $contextid): void {
        global $USER;

        $userinfo = new userinfo($USER->id);
        if (!$userinfo->is_confirmed()) {
            throw new \moodle_exception('error_terms_not_accepted', 'report_ai_analysis');
        }
        if (!in_array('singleprompt', aipurpose::get_enabled_plugins(), true)) {
            throw new \moodle_exception('error_purposenotconfigured', 'report_ai_analysis');
        }
        $config = ai_manager_utils::get_ai_config($USER, $contextid, null, ['singleprompt']);
        $general = $config['availability']['available'] ?? 'disabled';
        foreach ($config['purposes'] ?? [] as $purpose) {
            if ($general === 'available' && $purpose['purpose'] === 'singleprompt' && $purpose['available'] === 'available') {
                return;
            }
        }
        if ($general === 'available') {
            $factory = di::get(connector_factory::class);
            $instance = $factory->get_connector_instance_by_purpose('singleprompt', $userinfo->get_role());
            $purpose = $factory->get_purpose_by_purpose_string('singleprompt');
            $maximum = di::get(config_manager::class)->get_max_requests($purpose, $userinfo->get_role());
            if (!$instance || !$instance->is_enabled() || $maximum <= 0) {
                throw new \moodle_exception('error_purposenotconfigured', 'report_ai_analysis');
            }
            if ((new userusage($purpose, $USER->id))->get_currentusage() >= $maximum) {
                // A persisted quota is not a transient connector rate limit. Do not schedule retries for it.
                throw new \moodle_exception('error_limitreached', 'local_ai_manager');
            }
        }
        throw new \moodle_exception('error_ai_request', 'report_ai_analysis');
    }

    /**
     * Perform an AI request using local_ai_manager.
     *
     * @param string $purpose The purpose/type of AI request (e.g., 'singleprompt').
     * @param string $prompt The prompt text to send to the AI.
     * @param string $component The component identifier (e.g., 'report_ai_analysis').
     * @param int $contextid The context ID for the request.
     * @param array $options Options supported by the selected purpose.
     * @return prompt_response The result object from the AI manager.
     * @throws \moodle_exception If the purpose is not configured or available.
     */
    public function perform_request(
        string $purpose,
        string $prompt,
        string $component,
        int $contextid,
        array $options = []
    ): prompt_response {
        $this->require_available($contextid);
        $manager = new manager($purpose);
        $response = $manager->perform_request($prompt, $component, $contextid, $options);
        if (in_array($response->get_code(), [403, 429], true)) {
            // Re-read typed configuration state to distinguish concurrent consent/quota withdrawal.
            $this->require_available($contextid);
        }
        return $response;
    }
}
