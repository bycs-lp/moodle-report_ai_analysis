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
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis\privacy;

use context;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use report_ai_analysis\error_info;
use report_ai_analysis\local\log_store;
use report_ai_analysis\local\report_manager;

/**
 * Privacy provider for report_ai_analysis.
 *
 * Both report creators and the people whose contributions were included are
 * data subjects. Legacy reports use the conservative mappings saved by the
 * upgrade, not today's enrolments or a new interpretation of the report filters.
 *
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe stored data and processing through the AI manager and AI service.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection A listing of user data stored through this system.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'report_ai_analysis_reports',
            [
                'contextid' => 'privacy:metadata:report_ai_analysis_reports:contextid',
                'userid' => 'privacy:metadata:report_ai_analysis_reports:userid',
                'title' => 'privacy:metadata:report_ai_analysis_reports:title',
                'scope_details' => 'privacy:metadata:report_ai_analysis_reports:scope_details',
                'prompt' => 'privacy:metadata:report_ai_analysis_reports:prompt',
                'ai_result' => 'privacy:metadata:report_ai_analysis_reports:ai_result',
                'raw_data' => 'privacy:metadata:report_ai_analysis_reports:raw_data',
                'ai_model_name' => 'privacy:metadata:report_ai_analysis_reports:ai_model_name',
                'status' => 'privacy:metadata:report_ai_analysis_reports:status',
                'error_message' => 'privacy:metadata:report_ai_analysis_reports:error_message',
                'error_details' => 'privacy:metadata:report_ai_analysis_reports:error_details',
                'error_code' => 'privacy:metadata:report_ai_analysis_reports:error_code',
                'execution_time' => 'privacy:metadata:report_ai_analysis_reports:execution_time',
                'token_usage' => 'privacy:metadata:report_ai_analysis_reports:token_usage',
                'retry_count' => 'privacy:metadata:report_ai_analysis_reports:retry_count',
                'runversion' => 'privacy:metadata:report_ai_analysis_reports:runversion',
                'action' => 'privacy:metadata:report_ai_analysis_reports:action',
                'resultformat' => 'privacy:metadata:report_ai_analysis_reports:resultformat',
                'truncated' => 'privacy:metadata:report_ai_analysis_reports:truncated',
                'legacydata' => 'privacy:metadata:report_ai_analysis_reports:legacydata',
                'timecreated' => 'privacy:metadata:report_ai_analysis_reports:timecreated',
                'timemodified' => 'privacy:metadata:report_ai_analysis_reports:timemodified',
                'timecompleted' => 'privacy:metadata:report_ai_analysis_reports:timecompleted',
            ],
            'privacy:metadata:report_ai_analysis_reports'
        );

        $collection->add_database_table(
            'report_ai_analysis_users',
            [
                'reportid' => 'privacy:metadata:report_ai_analysis_users:reportid',
                'userid' => 'privacy:metadata:report_ai_analysis_users:userid',
                'source_data' => 'privacy:metadata:report_ai_analysis_users:source_data',
                'ai_result' => 'privacy:metadata:report_ai_analysis_users:ai_result',
            ],
            'privacy:metadata:report_ai_analysis_users'
        );

        $collection->add_plugintype_link(
            'local_ai_manager',
            [
                'userid' => 'privacy:metadata:local_ai_manager:userid',
                'contextid' => 'privacy:metadata:local_ai_manager:contextid',
                'itemid' => 'privacy:metadata:local_ai_manager:itemid',
                'prompttext' => 'privacy:metadata:local_ai_manager:prompttext',
                'promptcompletion' => 'privacy:metadata:local_ai_manager:promptcompletion',
                'requestoptions' => 'privacy:metadata:local_ai_manager:requestoptions',
                'timecreated' => 'privacy:metadata:local_ai_manager:timecreated',
            ],
            'privacy:metadata:local_ai_manager'
        );

        $collection->add_external_location_link(
            'ai_service',
            [
                'prompt' => 'privacy:metadata:ai_service:prompt',
                'source_data' => 'privacy:metadata:ai_service:source_data',
            ],
            'privacy:metadata:ai_service'
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
                $contextsql = self::privacy_context_sql();
                $sql = "SELECT DISTINCT {$contextsql} AS id
                                    FROM {report_ai_analysis_reports} r
                                 WHERE r.userid = :ownerid OR EXISTS (
                                               SELECT 1 FROM {report_ai_analysis_users} u
                                                WHERE u.reportid = r.id AND u.userid = :subjectid)";

        $contextlist->add_from_sql($sql, ['ownerid' => $userid, 'subjectid' => $userid]);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        $contextsql = self::privacy_context_sql();

        $sql = "SELECT r.userid
                  FROM {report_ai_analysis_reports} r
                 WHERE {$contextsql} = :ownercontext
                 UNION
                SELECT u.userid
                  FROM {report_ai_analysis_users} u
                  JOIN {report_ai_analysis_reports} r ON r.id = u.reportid
                 WHERE {$contextsql} = :subjectcontext";

        $userlist->add_from_sql('userid', $sql, [
            'ownercontext' => $context->id,
            'subjectcontext' => $context->id,
        ]);
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if ($contextlist->count() === 0) {
            return;
        }

        $user = $contextlist->get_user();
        [$contextsql, $contextparams] = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED, 'ctx');
        $privacycontext = self::privacy_context_sql();

        // Never select the parent's combined result, raw data, scope or diagnostic text for an export.
        $sql = "SELECT r.id, r.contextid, {$privacycontext} AS exportcontextid, r.userid, r.title, r.prompt,
                   r.ai_model_name, r.status,
                       r.error_code, r.execution_time, r.token_usage, r.retry_count,
                       r.runversion, r.action, r.resultformat, r.truncated, r.legacydata,
                       r.timecreated, r.timemodified, r.timecompleted, u.source_data, u.ai_result,
                       (SELECT COUNT(*) FROM {report_ai_analysis_users} subjects
                         WHERE subjects.reportid = r.id) AS subjectcount
                  FROM {report_ai_analysis_reports} r
             LEFT JOIN {report_ai_analysis_users} u ON u.reportid = r.id AND u.userid = :subjectid
                 WHERE {$privacycontext} {$contextsql}
                   AND (r.userid = :ownerid OR u.id IS NOT NULL)
              ORDER BY r.timecreated ASC, r.id ASC";

        $params = ['ownerid' => $user->id, 'subjectid' => $user->id] + $contextparams;
        $reports = $DB->get_records_sql($sql, $params);

        foreach ($reports as $report) {
            $context = context::instance_by_id($report->exportcontextid);
            // An orphan's historical content cannot be authenticated as a separable current source.
            $legacy = $report->legacydata || (int) $report->contextid !== (int) $report->exportcontextid;
            $data = [
                'reportid' => (int) $report->id,
                'status' => $report->status,
                'source_data' => $legacy ? null : $report->source_data,
                'ai_result' => $legacy ? null : $report->ai_result,
                'shared_data' => get_string('privacy:export:shareddata', 'report_ai_analysis'),
                'timecreated' => transform::datetime($report->timecreated),
                'timemodified' => transform::datetime($report->timemodified),
                'timecompleted' => $report->timecompleted ?
                    transform::datetime($report->timecompleted) : null,
            ];

            if ($legacy) {
                $data['legacy_data'] = get_string('privacy:export:legacydata', 'report_ai_analysis');
            }

            if ((int) $report->userid === (int) $user->id) {
                // Authorship does not authorise exporting other participants' source data or assessments.
                $errorcode = error_info::is_user_error_code((string) $report->error_code) ? $report->error_code : null;
                $data += [
                    'title' => $report->title,
                    'prompt' => $report->prompt,
                    'ai_model_name' => $report->ai_model_name,
                    'subject_count' => (int) $report->subjectcount,
                    'error_message' => $report->status === 'failed' ? error_info::get_description($errorcode) : null,
                    'error_code' => $errorcode,
                    'execution_time' => (int) $report->execution_time,
                    'token_usage' => $report->token_usage,
                    'retry_count' => (int) $report->retry_count,
                    'runversion' => (int) $report->runversion,
                    'action' => $report->action,
                    'resultformat' => (int) $report->resultformat,
                    'truncated' => (bool) $report->truncated,
                ];
            }

            writer::with_context($context)->export_data(
                [get_string('privacy:export:reports', 'report_ai_analysis'), (string) $report->id],
                (object) $data
            );
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param context $context The specific context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;

        $privacycontext = self::privacy_context_sql();
        $reportids = $DB->get_fieldset_sql("SELECT r.id FROM {report_ai_analysis_reports} r
            WHERE {$privacycontext} = :contextid", ['contextid' => $context->id]);
        foreach ($reportids as $reportid) {
            report_manager::delete_for_privacy((int) $reportid);
        }

        // Also cover unlinked legacy logs and logs whose parent no longer exists, in this context only.
        log_store::anonymize_context((int) $context->id);
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        self::delete_reports_for_users($contextlist->get_contextids(), [(int) $contextlist->get_user()->id]);
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        self::delete_reports_for_users([(int) $userlist->get_context()->id], $userlist->get_userids());
    }

    /**
     * Delete inseparable reports through the locked lifecycle, within the approved contexts only.
     *
     * @param int[] $contextids Approved context IDs
     * @param int[] $userids Approved subject or creator IDs
     */
    private static function delete_reports_for_users(array $contextids, array $userids): void {
        global $DB;

        if (!$contextids || !$userids) {
            return;
        }

        [$contextsql, $contextparams] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');
        [$ownersql, $ownerparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'owner');
        [$subjectsql, $subjectparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'subject');
        $privacycontext = self::privacy_context_sql();
        $sql = "SELECT r.id
                  FROM {report_ai_analysis_reports} r
             WHERE {$privacycontext} {$contextsql}
                   AND (r.userid {$ownersql}
                        OR EXISTS (SELECT 1
                                     FROM {report_ai_analysis_users} u
                                    WHERE u.reportid = r.id AND u.userid {$subjectsql}))";
        $reportids = $DB->get_fieldset_sql($sql, $contextparams + $ownerparams + $subjectparams);

        foreach ($reportids as $reportid) {
            report_manager::delete_for_privacy((int) $reportid);
        }
    }

    /**
     * Route orphan discovery through the system context without reparenting or deleting history.
     *
     * Missing contexts and course contexts whose course vanished cannot appear in a core contextlist.
     * The system context is an explicit privacy fallback, never an interactive report-access grant.
     * Only its approval permits orphan export/deletion; valid existing contexts remain independent.
     * The report keeps its original context ID so linked manager logs can still be anonymised.
     *
     * @return string SQL expression for report alias r, using trusted core constants only
     */
    private static function privacy_context_sql(): string {
        $systemid = (int) \context_system::instance()->id;
        $courselevel = CONTEXT_COURSE;
        return "CASE WHEN EXISTS (
                    SELECT 1 FROM {context} originalctx
                    LEFT JOIN {course} originalcourse ON originalcourse.id = originalctx.instanceid
                         AND originalctx.contextlevel = {$courselevel}
                    WHERE originalctx.id = r.contextid
                      AND (originalctx.contextlevel <> {$courselevel} OR originalcourse.id IS NOT NULL)
                ) THEN r.contextid ELSE {$systemid} END";
    }
}
