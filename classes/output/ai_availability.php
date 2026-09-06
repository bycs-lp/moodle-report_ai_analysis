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

namespace report_ai_analysis\output;

use local_ai_manager\ai_manager_utils;

/**
 * Presents AI Manager availability without exposing backend exceptions.
 *
 * This is early UI feedback, not a replacement for authorization in the worker.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_availability {
    /**
     * Get the effective availability of the singleprompt purpose.
     *
     * @param \context $context Course context
     * @return array State (available, disabled or hidden) and a safe HTML message
     */
    public function get_availability(\context $context): array {
        $fallback = ['state' => 'disabled', 'message' => s(get_string('aiunavailable', 'report_ai_analysis'))];

        try {
            $config = $this->get_config($context);
            $general = $config['availability'] ?? [];
            $purpose = [];
            foreach ($config['purposes'] ?? [] as $candidate) {
                if (($candidate['purpose'] ?? '') === 'singleprompt') {
                    $purpose = $candidate;
                    break;
                }
            }

            $states = [$general['available'] ?? 'disabled', $purpose['available'] ?? 'disabled'];
            if (in_array('hidden', $states, true)) {
                return ['state' => 'hidden', 'message' => ''];
            }
            if ($states === ['available', 'available']) {
                return ['state' => 'available', 'message' => ''];
            }

            $message = ($general['available'] ?? '') === 'disabled'
                ? ($general['errormessage'] ?? '') : ($purpose['errormessage'] ?? '');
            if ($message !== '') {
                $fallback['message'] = format_text($message, FORMAT_HTML, [
                    'context' => $context,
                    'clean' => true,
                    'trusted' => false,
                    'filter' => false,
                ]);
            }
        } catch (\Throwable $exception) {
            // Only the fixed, localised fallback is suitable for this public UI.
            unset($exception);
        }

        return $fallback;
    }

    /**
     * Retrieve the current user's configuration through the public Manager API.
     *
     * @param \context $context Course context
     * @return array Manager configuration
     */
    protected function get_config(\context $context): array {
        global $USER;

        return ai_manager_utils::get_ai_config($USER, $context->id, null, ['singleprompt']);
    }
}
