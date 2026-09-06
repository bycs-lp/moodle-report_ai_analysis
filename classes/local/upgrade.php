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
 * Data migration for generation-aware analysis reports.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis\local;

use core\lock\lock_config;
use core\task\manager;
use stdClass;
use Throwable;
use xmldb_table;

/**
 * Preserve legacy reports while recording conservative, explicitly incomplete subject mappings.
 *
 * These mappings support privacy discovery, never access rights or per-person content exports.
 * Historical filters and current enrolments cannot prove who appeared in an old aggregate.
 * Names, free text, deleted source records and external-service copies cannot be reconstructed.
 * Every migrated report therefore retains legacydata = 1, including reports without raw data.
 *
 * Missing contexts are not silently reparented and report/source history is retained. The privacy
 * provider discovers orphans under the system context for explicitly approved export/deletion.
 * Manager log text is anonymised: its actor-only export cannot isolate the historical subjects.
 * Run with normal Moodle upgrade isolation: an already executing pre-upgrade worker must be stopped.
 *
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upgrade {
    /** @var int Maximum reports or queued tasks visited in one resumable batch. */
    private const BATCH_SIZE = 100;

    /**
     * Migrate one batch after the XMLDB fields and subject table have been installed.
     *
     * A report's mappings and generation are committed together. Repeating the migration skips
     * completed batches and all modern reports; existing subject rows are never overwritten.
     * The caller renews the upgrade timeout between batches, keeping this helper usable in tests.
     *
     * @param int $afterid Last visited report ID, or zero to start/resume from the beginning
     * @return int Last visited report ID, or zero when no unmigrated reports remain after the cursor
     */
    public static function migrate_legacy_reports(int $afterid = 0): int {
        global $DB;

        $reports = $DB->get_recordset_select(
            'report_ai_analysis_reports',
            'id > :afterid AND runversion = 0 AND legacydata = 0',
            ['afterid' => $afterid],
            'id ASC',
            'id, contextid, userid, scope_details, raw_data, status',
            0,
            self::BATCH_SIZE
        );
        $lastid = 0;
        $contexts = [];
        try {
            foreach ($reports as $report) {
                self::migrate_report($report, !isset($contexts[(int) $report->contextid]));
                $contexts[(int) $report->contextid] = (int) $report->contextid;
                $lastid = (int) $report->id;
            }
        } finally {
            $reports->close();
        }

        return $lastid;
    }

    /**
     * Remove one batch of obsolete, non-running analysis tasks through the core task API.
     *
     * Tasks without a positive generation cannot execute under the new worker contract. Running
     * queue records are left for their runner to complete; modern generations and other components
     * are untouched. This does not interrupt a request which was already sent to an AI service.
     *
     * @param int $afterid Last visited task ID, or zero to start
     * @return int Last visited task ID, or zero when the queue scan is complete
     */
    public static function remove_legacy_tasks(int $afterid = 0): int {
        global $DB;

        $tasks = $DB->get_recordset_select(
            'task_adhoc',
            'id > :afterid AND component = :component AND classname = :classname AND timestarted IS NULL',
            [
                'afterid' => $afterid,
                'component' => 'report_ai_analysis',
                'classname' => '\\report_ai_analysis\\task\\process_analysis_task',
            ],
            'id ASC',
            'id, customdata',
            0,
            self::BATCH_SIZE
        );
        $lastid = 0;
        $lockfactory = lock_config::get_lock_factory('cron');
        try {
            foreach ($tasks as $task) {
                $data = json_decode($task->customdata ?? '');
                if (!($data instanceof stdClass) || self::positive_id($data->runversion ?? null) === null) {
                    // Do not race a runner which acquired this task after the recordset was opened.
                    $lock = $lockfactory->get_lock('adhoc_' . $task->id, 0);
                    if ($lock) {
                        try {
                            if ($DB->record_exists('task_adhoc', ['id' => $task->id, 'timestarted' => null])) {
                                manager::delete_adhoc_task((int) $task->id);
                            }
                        } finally {
                            $lock->release();
                        }
                    }
                }
                $lastid = (int) $task->id;
            }
        } finally {
            $tasks->close();
        }

        return $lastid;
    }

    /**
     * Migrate a legacy report without treating its content as safely separable data.
     *
     * @param stdClass $report Legacy report with generation zero
     * @param bool $wipelegacy Whether this is the first migrated report in its context in this batch
     */
    private static function migrate_report(stdClass $report, bool $wipelegacy): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            // Use the existing context, not untrusted scope JSON, to establish course membership.
            $context = $DB->get_record_sql(
                'SELECT ctx.id, ctx.instanceid, ctx.path
                   FROM {context} ctx
                   JOIN {course} c ON c.id = ctx.instanceid
                  WHERE ctx.id = :contextid AND ctx.contextlevel = :contextlevel',
                ['contextid' => $report->contextid, 'contextlevel' => CONTEXT_COURSE]
            );
            $scope = json_decode($report->scope_details);
            $userids = [$report->userid];
            if ($scope instanceof stdClass && isset($scope->filters) && $scope->filters instanceof stdClass) {
                foreach (['participants', 'students'] as $key) {
                    if (isset($scope->filters->{$key}) && is_array($scope->filters->{$key})) {
                        $userids = array_merge($userids, $scope->filters->{$key});
                    }
                }
            }

            // The old EN/DE format included IDs for chat users and discussion starters, but not reply authors.
            // Even these headers can occur in user text: they are conservative hints, not authenticated identities.
            preg_match_all(
                '/^(?:User|Nutzer|Started by|Gestartet von): [^\r\n]* \(ID: ([1-9][0-9]*)\)\r?$/m',
                $report->raw_data ?? '',
                $matches
            );
            self::add_explicit_users((int) $report->id, array_merge($userids, $matches[1]));
            if ($context) {
                self::add_course_users((int) $report->id, $context);
            }

            $update = (object) ['id' => $report->id, 'runversion' => 1, 'legacydata' => 1];
            if ($scope instanceof stdClass) {
                $originalscope = json_encode($scope, JSON_THROW_ON_ERROR);
                self::normalise_scope($scope, $context ? (int) $context->instanceid : null);
                $normalisedscope = json_encode($scope, JSON_THROW_ON_ERROR);
                if ($normalisedscope !== $originalscope) {
                    $update->scope_details = $normalisedscope;
                }
            }
            if (in_array($report->status, ['pending', 'running'], true)) {
                $update->status = 'cancelled';
                $update->timemodified = time();
            }
            // Keep resultformat's XMLDB Markdown fallback; never guess from HTML-looking content.
            log_store::anonymize_report((int) $report->contextid, (int) $report->id);
            // Keep unlinked cleanup inside the migration transaction so a failed wipe remains resumable.
            if ($wipelegacy) {
                log_store::anonymize_legacy((int) $report->contextid);
            }
            $DB->update_record('report_ai_analysis_reports', $update);
            $transaction->allow_commit();
        } catch (Throwable $exception) {
            $transaction->rollback($exception);
        }
    }

    /**
     * Add canonical scope keys without discarding legacy keys or widening an explicit empty selection.
     *
     * Invalid structures and existing canonical values are retained for manual correction. A missing
     * course ID can only be restored from a verified course context; conflicting IDs are not guessed.
     *
     * @param stdClass $scope Decoded legacy scope, modified in place
     * @param int|null $courseid Verified context course ID, or null for an orphan/non-course context
     */
    private static function normalise_scope(stdClass $scope, ?int $courseid): void {
        if ($courseid !== null && !property_exists($scope, 'courseid')) {
            $scope->courseid = $courseid;
        }
        if (!property_exists($scope, 'filters') || $scope->filters === []) {
            $scope->filters = new stdClass();
        }
        if (!($scope->filters instanceof stdClass)) {
            return;
        }

        $filters = $scope->filters;
        foreach (['students' => 'participants', 'roleids' => 'roles'] as $oldkey => $newkey) {
            if (!property_exists($filters, $newkey) && isset($filters->{$oldkey}) && is_array($filters->{$oldkey})) {
                $filters->{$newkey} = $filters->{$oldkey};
            }
        }
        if (
            !property_exists($filters, 'timerange') &&
            (property_exists($filters, 'timestart') || property_exists($filters, 'timeend'))
        ) {
            $start = property_exists($filters, 'timestart') ? $filters->timestart : 0;
            $end = property_exists($filters, 'timeend') ? $filters->timeend : 0;
            if (is_int($start) && is_int($end) && $start >= 0 && $end >= 0 && (!$start || !$end || $start <= $end)) {
                $filters->timerange = (object) ['start' => $start, 'end' => $end];
            }
        }
    }

    /**
     * Map existing user IDs from ownership, persisted filters and recognised raw-data headers.
     *
     * @param int $reportid Report ID
     * @param array $values Untrusted candidate IDs; names, booleans, floats and malformed strings are ignored
     */
    private static function add_explicit_users(int $reportid, array $values): void {
        global $DB;

        $userids = [];
        foreach ($values as $value) {
            $userid = self::positive_id($value);
            if ($userid !== null) {
                $userids[$userid] = $userid;
            }
        }
        foreach (array_chunk($userids, self::BATCH_SIZE) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'subject');
            $params['reportid'] = $reportid;
            self::insert_users(
                $reportid,
                "SELECT u.id
                   FROM {user} u
                  WHERE u.id {$insql}
                    AND NOT EXISTS (SELECT 1 FROM {report_ai_analysis_users} mapped
                                     WHERE mapped.reportid = :reportid AND mapped.userid = u.id)",
                $params
            );
        }
    }

    /**
     * Map course-linked candidates, deliberately ignoring historical selection/access filters.
     *
     * Include suspended, expired and disabled enrolments, course/subcontext role assignments, group
     * membership, surviving forum authors/starters and chat log owners, including soft-deleted logs.
     * This recovers some former participants but cannot recover those whose identifying records
     * are gone. Parent/system roles are excluded to avoid attributing every site user to each report.
     * Only IDs are read from source tables; no source text is fetched and no source record is modified.
     *
     * @param int $reportid Report ID
     * @param stdClass $context Verified existing course context
     */
    private static function add_course_users(int $reportid, stdClass $context): void {
        global $DB;

        $rolecontexts = 'rc.id = :rolecontext';
        $logcontexts = 'l.coursecontextid = :logcoursecontext OR l.contextid = :logcontext';
        $params = [
            'enrolcourse' => $context->instanceid,
            'rolecontext' => $context->id,
            'groupcourse' => $context->instanceid,
            'reportid' => $reportid,
        ];
        if (!empty($context->path)) {
            $rolecontexts .= ' OR rc.path LIKE :rolepath';
            $params['rolepath'] = $DB->sql_like_escape($context->path) . '/%';
        }
        $queries = [
            'SELECT ue.userid FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid WHERE e.courseid = :enrolcourse',
            "SELECT ra.userid FROM {role_assignments} ra
               JOIN {context} rc ON rc.id = ra.contextid WHERE {$rolecontexts}",
            'SELECT gm.userid FROM {groups_members} gm
               JOIN {groups} g ON g.id = gm.groupid WHERE g.courseid = :groupcourse',
        ];

        $dbman = $DB->get_manager();
        if ($dbman->table_exists(new xmldb_table('forum_discussions')) && $dbman->table_exists(new xmldb_table('forum_posts'))) {
            $queries[] = 'SELECT fp.userid FROM {forum_posts} fp
                            JOIN {forum_discussions} fd ON fd.id = fp.discussion WHERE fd.course = :postcourse';
            $queries[] = 'SELECT fd.userid FROM {forum_discussions} fd WHERE fd.course = :startercourse';
            $params['postcourse'] = $context->instanceid;
            $params['startercourse'] = $context->instanceid;
        }
        if ($dbman->table_exists(new xmldb_table('local_ai_manager_request_log'))) {
            if (!empty($context->path)) {
                $logcontexts .= ' OR lc.path LIKE :logpath';
                $params['logpath'] = $DB->sql_like_escape($context->path) . '/%';
            }
            $queries[] = "SELECT l.userid FROM {local_ai_manager_request_log} l
                       LEFT JOIN {context} lc ON lc.id = l.contextid
                           WHERE l.component = :logcomponent AND l.purpose = :logpurpose AND ({$logcontexts})";
            $params['logcoursecontext'] = $context->id;
            $params['logcontext'] = $context->id;
            $params['logcomponent'] = 'block_ai_chat';
            $params['logpurpose'] = 'chat';
        }

        $candidates = implode(' UNION ', $queries);
        self::insert_users(
            $reportid,
            "SELECT u.id
               FROM {user} u
               JOIN ({$candidates}) candidates ON candidates.userid = u.id
              WHERE u.id > 0
                AND NOT EXISTS (SELECT 1 FROM {report_ai_analysis_users} mapped
                                 WHERE mapped.reportid = :reportid AND mapped.userid = u.id)",
            $params
        );
    }

    /**
     * Stream distinct existing users into the map without copying inseparable report content.
     *
     * @param int $reportid Report ID
     * @param string $sql Query selecting distinct user IDs without an existing association
     * @param array $params Bound query parameters
     */
    private static function insert_users(int $reportid, string $sql, array $params): void {
        global $DB;

        $users = $DB->get_recordset_sql($sql, $params);
        try {
            foreach ($users as $user) {
                $DB->insert_record('report_ai_analysis_users', (object) [
                    'reportid' => $reportid,
                    'userid' => $user->id,
                    'source_data' => null,
                    'ai_result' => null,
                ]);
            }
        } finally {
            $users->close();
        }
    }

    /**
     * Accept positive integer identifiers without coercing arbitrary JSON values into user IDs.
     *
     * @param mixed $value Candidate identifier
     * @return int|null Valid positive integer, or null
     */
    private static function positive_id(mixed $value): ?int {
        if (!is_int($value) && (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value))) {
            return null;
        }
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $value === false ? null : $value;
    }
}
