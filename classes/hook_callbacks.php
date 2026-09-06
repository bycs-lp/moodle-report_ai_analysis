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
 * Analysis report lifecycle and AI usage hook callbacks.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis;

use core_course\hook\before_course_deleted;
use local_ai_manager\hook\purpose_usage;
use report_ai_analysis\local\report_manager;

/**
 * Connect report cleanup and purpose transparency to the public hook APIs.
 *
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Invalidate reports before the course context and its enrolments disappear.
     *
     * @param before_course_deleted $hook The hook carrying the course record
     */
    public static function before_course_deleted(before_course_deleted $hook): void {
        report_manager::delete_course((int) $hook->course->id);
    }

    /**
     * Describe the use of single-prompt requests in the AI manager's overview.
     *
     * @param purpose_usage $hook The AI purpose usage collector
     */
    public static function purpose_usage(purpose_usage $hook): void {
        $hook->add_purpose_usage_description(
            'singleprompt',
            'report_ai_analysis',
            get_string('purposeplacedescription', 'report_ai_analysis')
        );
    }
}
