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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace report_ai_analysis\local;

use context;
use core_user;
use stdClass;

/**
 * Course and object authorisation shared by interactive and asynchronous operations.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_access {
    /** @var string[] Supported write capabilities; caller input cannot name an unrelated capability. */
    private const WRITE_CAPABILITIES = [
        'report/ai_analysis:create',
        'report/ai_analysis:delete',
        'report/ai_analysis:rerun',
    ];

    /**
     * Check an active account's current access to an existing course.
     *
     * @param context $context Report context
     * @param int|null $userid Acting user, defaults to the current user
     * @return bool Whether the course is accessible
     */
    public static function can_access_course(context $context, ?int $userid = null): bool {
        global $DB, $USER;

        $userid = $userid ?? (int) $USER->id;
        if ($context->contextlevel !== CONTEXT_COURSE || $userid <= 0) {
            return false;
        }
        $user = core_user::get_user($userid);
        if (!$user || !empty($user->deleted) || !empty($user->suspended) || empty($user->confirmed) || isguestuser($user)) {
            return false;
        }
        $course = $DB->get_record('course', ['id' => $context->instanceid]);
        return $course && can_access_course($course, $userid, '', true);
    }

    /**
     * Require current course access without introducing interactive redirects into a task.
     *
     * @param context $context Report context
     * @param int|null $userid Acting user
     */
    public static function require_course_access(context $context, ?int $userid = null): void {
        if (!self::can_access_course($context, $userid)) {
            throw new \moodle_exception('error_access_revoked', 'report_ai_analysis');
        }
    }

    /**
     * Determine whether the user can read this report, not just its course's report interface.
     *
     * @param stdClass $report Persisted report
     * @param int|null $userid Acting user
     * @return bool Whether reading is allowed
     */
    public static function can_view(stdClass $report, ?int $userid = null): bool {
        global $USER;

        $userid = $userid ?? (int) $USER->id;
        $context = context::instance_by_id($report->contextid, IGNORE_MISSING);
        if (
            !$context || !self::can_access_course($context, $userid) ||
                !has_capability('report/ai_analysis:view', $context, $userid)
        ) {
            return false;
        }
        return (int) $report->userid === $userid ||
            (bool) get_config('report_ai_analysis', 'share_reports_in_course') ||
            has_capability('report/ai_analysis:viewall', $context, $userid);
    }

    /**
     * Determine whether the user can perform the requested mutation.
     *
     * @param stdClass $report Persisted report
     * @param string $capability Full action capability
     * @param int|null $userid Acting user
     * @return bool Whether the mutation is allowed
     */
    public static function can_manage(stdClass $report, string $capability, ?int $userid = null): bool {
        global $USER;

        if (!in_array($capability, self::WRITE_CAPABILITIES, true)) {
            throw new \coding_exception('Unsupported report action capability');
        }
        $userid = $userid ?? (int) $USER->id;
        $context = context::instance_by_id($report->contextid, IGNORE_MISSING);
        if (!$context || !self::can_access_course($context, $userid) || !has_capability($capability, $context, $userid)) {
            return false;
        }
        return (int) $report->userid === $userid || has_capability('report/ai_analysis:manageall', $context, $userid);
    }

    /**
     * Require object-level reading permission.
     *
     * @param stdClass $report Persisted report
     */
    public static function require_view(stdClass $report): void {
        if (!self::can_view($report)) {
            self::deny('report/ai_analysis:view');
        }
    }

    /**
     * Require object-level write permission.
     *
     * @param stdClass $report Persisted report
     * @param string $capability Full action capability
     */
    public static function require_manage(stdClass $report, string $capability): void {
        if (!self::can_manage($report, $capability)) {
            self::deny($capability);
        }
    }

    /**
     * Reject access using Moodle's standard permission error, without revealing report contents.
     *
     * @param string $capability Required capability
     */
    private static function deny(string $capability): void {
        throw new \moodle_exception('nopermissions', 'error', '', get_capability_string($capability));
    }
}
