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

/**
 * Error presentation helper.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis;

/**
 * Separates user-facing error descriptions from technical debugging details.
 *
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class error_info {
    /**
     * Error codes with user-facing language strings.
     */
    private const USER_ERROR_CODES = [
        'error_ai_chat_not_available',
        'error_ai_request',
        'error_api_connection_error',
        'error_api_timeout',
        'error_empty_response',
        'error_forum_not_available',
        'error_no_data',
        'error_prompt_too_long',
        'error_purposenotconfigured',
        'error_rate_limit',
        'error_terms_not_accepted',
        'error_unknown',
    ];

    /**
     * Whether an error code has a user-facing description.
     *
     * @param string $errorcode Error code
     * @return bool
     */
    public static function is_user_error_code(string $errorcode): bool {
        return in_array($errorcode, self::USER_ERROR_CODES, true);
    }

    /**
     * Get the safe, localised description for an error.
     *
     * @param string|null $errorcode Error code
     * @return string
     */
    public static function get_description(?string $errorcode): string {
        if (is_string($errorcode) && self::is_user_error_code($errorcode)) {
            return get_string($errorcode, 'report_ai_analysis');
        }

        return get_string('error_unknown', 'report_ai_analysis');
    }

    /**
     * Get technical details when Moodle is configured to display developer debugging.
     *
     * The error message parameter supports reports created before error details were
     * stored separately. It must never be returned unless debug display is enabled.
     *
     * @param string|null $errorcode Error code
     * @param string|null $errormessage Stored user message or legacy technical details
     * @param string|null $errordetails Stored technical details
     * @return string|null
     */
    public static function get_debug_details(
        ?string $errorcode,
        ?string $errormessage,
        ?string $errordetails = null
    ): ?string {
        if (!self::can_show_debug_details()) {
            return null;
        }

        $details = trim((string) $errordetails);
        if ($details !== '' && $details !== self::get_description($errorcode)) {
            return $details;
        }

        $legacymessage = trim((string) $errormessage);
        if ($legacymessage !== '' && $legacymessage !== self::get_description($errorcode)) {
            return $legacymessage;
        }

        return null;
    }

    /**
     * Whether Moodle permits technical error details to be displayed.
     *
     * @return bool
     */
    public static function can_show_debug_details(): bool {
        global $CFG;

        return (!defined('NO_DEBUG_DISPLAY') || !NO_DEBUG_DISPLAY) &&
            !empty($CFG->debugdeveloper) && !empty($CFG->debugdisplay);
    }
}
