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

use local_ai_manager\manager;

/**
 * Wrapper class for local_ai_manager\manager to enable dependency injection and mocking in tests.
 *
 * This provider abstracts the AI manager functionality, allowing for easy mocking
 * in unit tests without requiring actual AI service connections.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_request_provider {
    /**
     * Perform an AI request using local_ai_manager.
     *
     * @param string $purpose The purpose/type of AI request (e.g., 'singleprompt').
     * @param string $prompt The prompt text to send to the AI.
     * @param string $component The component identifier (e.g., 'report_ai_analysis').
     * @param int $contextid The context ID for the request.
     * @return \local_ai_manager\local\prompt_response The result object from the AI manager.
     * @throws \moodle_exception If the purpose is not configured or available.
     */
    public function perform_request(string $purpose, string $prompt, string $component, int $contextid) {
        $manager = new manager($purpose);
        return $manager->perform_request($prompt, $component, $contextid);
    }
}
