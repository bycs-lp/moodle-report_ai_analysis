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
 * Privacy provider implementation for report_ai_analysis.
 *
 * @package    report_ai_analysis
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for report_ai_analysis.
 *
 * This plugin stores AI analysis reports created by users, including
 * prompts and potentially personal conversation data.
 *
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Get the language string identifier with the component's language
     * file to explain why this plugin stores no data.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection A listing of user data stored through this system.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'report_ai_analysis_reports',
            [
                'userid' => 'privacy:metadata:report_ai_analysis_reports:userid',
                'title' => 'privacy:metadata:report_ai_analysis_reports:title',
                'prompt' => 'privacy:metadata:report_ai_analysis_reports:prompt',
                'ai_result' => 'privacy:metadata:report_ai_analysis_reports:ai_result',
                'raw_data' => 'privacy:metadata:report_ai_analysis_reports:raw_data',
                'timecreated' => 'privacy:metadata:report_ai_analysis_reports:timecreated',
            ],
            'privacy:metadata:report_ai_analysis_reports'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist The contextlist containing the list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT DISTINCT contextid
                  FROM {report_ai_analysis_reports}
                 WHERE userid = :userid";

        $contextlist->add_from_sql($sql, ['userid' => $userid]);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        $sql = "SELECT userid
                  FROM {report_ai_analysis_reports}
                 WHERE contextid = :contextid";

        $userlist->add_from_sql('userid', $sql, ['contextid' => $context->id]);
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();
        [$contextsql, $contextparams] = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);

        $sql = "SELECT *
                  FROM {report_ai_analysis_reports}
                 WHERE userid = :userid
                   AND contextid {$contextsql}
              ORDER BY timecreated ASC";

        $params = ['userid' => $user->id] + $contextparams;
        $reports = $DB->get_records_sql($sql, $params);

        foreach ($reports as $report) {
            $context = \context::instance_by_id($report->contextid);
            $contextdata = \core_privacy\local\request\helper::get_context_data($context, $contextlist->get_user());

            $data = [
                'title' => $report->title,
                'scope_details' => $report->scope_details,
                'prompt' => $report->prompt,
                'ai_model_name' => $report->ai_model_name,
                'status' => $report->status,
                'ai_result' => $report->ai_result,
                'raw_data' => $report->raw_data,
                'error_message' => $report->error_message,
                'error_code' => $report->error_code,
                'execution_time' => $report->execution_time,
                'token_usage' => $report->token_usage,
                'retry_count' => $report->retry_count,
                'timecreated' => \core_privacy\local\request\transform::datetime($report->timecreated),
                'timemodified' => \core_privacy\local\request\transform::datetime($report->timemodified),
                'timecompleted' => $report->timecompleted ?
                    \core_privacy\local\request\transform::datetime($report->timecompleted) : null,
            ];

            writer::with_context($context)->export_data(['AI Analysis Reports', $report->id], (object) $data);
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context The specific context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        $DB->delete_records('report_ai_analysis_reports', ['contextid' => $context->id]);
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();
        [$contextsql, $contextparams] = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);

        $select = "userid = :userid AND contextid {$contextsql}";
        $params = ['userid' => $user->id] + $contextparams;

        $DB->delete_records_select('report_ai_analysis_reports', $select, $params);
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        $userids = $userlist->get_userids();

        if (empty($userids)) {
            return;
        }

        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        $select = "contextid = :contextid AND userid {$usersql}";
        $params = ['contextid' => $context->id] + $userparams;

        $DB->delete_records_select('report_ai_analysis_reports', $select, $params);
    }
}
