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
use context_course;
use core\clock;
use core\di;
use core\lock\lock;
use core\lock\lock_config;
use core\task\manager as task_manager;
use core_text;
use report_ai_analysis\event\report_deleted;
use report_ai_analysis\scope_builder;
use report_ai_analysis\task\process_analysis_task;
use stdClass;

/**
 * Serialises report mutations and queues a distinct generation for each new run.
 *
 * Locks protect short database transitions only, never an external AI request.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_manager {
    /**
     * Acquire the shared report lock, always released by the caller in finally.
     *
     * @param int $reportid Report identifier
     * @return lock Acquired lock
     */
    public static function get_lock(int $reportid): lock {
        $lock = lock_config::get_lock_factory('report_ai_analysis')->get_lock('report:' . $reportid, 10);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        return $lock;
    }

    /**
     * Create or edit a report and its task in one transaction.
     *
     * @param stdClass $data Author-supplied title and prompt; all other fields are ignored
     * @param scope_builder $scope Selected course and filters
     * @param int|null $reportid Existing report identifier
     * @return int Saved report identifier
     */
    public static function save(stdClass $data, scope_builder $scope, ?int $reportid = null): int {
        global $DB, $USER;

        $context = context_course::instance($scope->get_course_in_scope());
        report_access::require_course_access($context);
        require_capability('report/ai_analysis:create', $context);
        $title = clean_param((string) ($data->title ?? ''), PARAM_TEXT);
        $prompt = (string) ($data->prompt ?? '');
        if (core_text::strlen($title) > 255) {
            throw new \moodle_exception('error_title_too_long', 'report_ai_analysis');
        }
        if (core_text::strlen(trim($prompt)) < 10 || core_text::strlen($prompt) > 10000) {
            $code = core_text::strlen(trim($prompt)) < 10 ? 'error_prompt_too_short' : 'error_prompt_too_long';
            throw new \moodle_exception($code, 'report_ai_analysis');
        }
        $scope->validate_sources();
        $lock = $reportid ? self::get_lock($reportid) : null;
        try {
            $transaction = $DB->start_delegated_transaction();
            if ($reportid) {
                $report = $DB->get_record('report_ai_analysis_reports', ['id' => $reportid], '*', MUST_EXIST);
                report_access::require_manage($report, 'report/ai_analysis:create');
                if ((int) $report->contextid !== (int) $context->id) {
                    throw new \moodle_exception('error_contextmismatch', 'report_ai_analysis');
                }
                if ($report->status === 'running') {
                    throw new \moodle_exception('cannoteditrunningreport', 'report_ai_analysis');
                }
            } else {
                $report = (object) [
                    'contextid' => $context->id,
                    'userid' => $USER->id,
                    'timecreated' => di::get(clock::class)->time(),
                    'timemodified' => di::get(clock::class)->time(),
                    'runversion' => 0,
                ];
            }
            $report->title = $title !== '' ? $title : core_text::substr($prompt, 0, 80);
            $report->prompt = $prompt;
            $report->scope_details = $scope->build();
            if (!$reportid) {
                $report->id = $DB->insert_record('report_ai_analysis_reports', $report);
            }
            self::queue_run($report, $scope, 'create', (int) $USER->id);
            $transaction->allow_commit();
            return (int) $report->id;
        } catch (\Throwable $exception) {
            if (isset($transaction)) {
                $transaction->rollback($exception);
            }
            throw $exception;
        } finally {
            $lock?->release();
        }
    }

    /**
     * Queue a new generation for a report in a terminal state.
     *
     * @param int $reportid Report identifier
     */
    public static function rerun(int $reportid): void {
        global $DB, $USER;

        $lock = self::get_lock($reportid);
        try {
            $transaction = $DB->start_delegated_transaction();
            $report = $DB->get_record('report_ai_analysis_reports', ['id' => $reportid], '*', MUST_EXIST);
            report_access::require_manage($report, 'report/ai_analysis:rerun');
            if (!in_array($report->status, ['completed', 'failed', 'cancelled'], true)) {
                throw new \moodle_exception('cannotrerunreport', 'report_ai_analysis');
            }
            $scope = scope_builder::from_json($report->scope_details);
            if ((int) context_course::instance($scope->get_course_in_scope())->id !== (int) $report->contextid) {
                throw new \moodle_exception('error_contextmismatch', 'report_ai_analysis');
            }
            $scope->validate_sources();
            self::queue_run($report, $scope, 'rerun', (int) $USER->id);
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            if (isset($transaction)) {
                $transaction->rollback($exception);
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /**
     * Invalidate a pending or running generation.
     *
     * @param int $reportid Report identifier
     */
    public static function cancel(int $reportid): void {
        global $DB;

        $lock = self::get_lock($reportid);
        try {
            $transaction = $DB->start_delegated_transaction();
            $report = $DB->get_record('report_ai_analysis_reports', ['id' => $reportid], '*', MUST_EXIST);
            report_access::require_manage($report, 'report/ai_analysis:delete');
            if (in_array($report->status, ['pending', 'running'], true)) {
                $DB->update_record('report_ai_analysis_reports', (object) [
                    'id' => $reportid,
                    'status' => 'cancelled',
                    'runversion' => (int) $report->runversion + 1,
                    'timemodified' => di::get(clock::class)->time(),
                ]);
                self::remove_queued_tasks($reportid);
                // Existing responses are no longer needed; a still-running request wipes its late ID itself.
                log_store::anonymize_report((int) $report->contextid, $reportid);
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            if (isset($transaction)) {
                $transaction->rollback($exception);
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /**
     * Delete a report after current object-level authorisation.
     *
     * @param int $reportid Report identifier
     */
    public static function delete(int $reportid): void {
        self::delete_record($reportid, true);
    }

    /**
     * Delete data already authorised by the Privacy API or course lifecycle.
     *
     * @param int $reportid Report identifier in the approved context
     */
    public static function delete_for_privacy(int $reportid): void {
        self::delete_record($reportid, false);
    }

    /**
     * Remove course reports and residual logs before its context is deleted.
     *
     * @param int $courseid Course being deleted by core
     */
    public static function delete_course(int $courseid): void {
        global $DB;

        $context = context_course::instance($courseid);
        $reports = $DB->get_recordset('report_ai_analysis_reports', ['contextid' => $context->id], '', 'id');
        try {
            foreach ($reports as $report) {
                self::delete_for_privacy((int) $report->id);
            }
        } finally {
            $reports->close();
        }
        log_store::anonymize_context((int) $context->id);
    }

    /**
     * Atomically reset a record and queue its next generation. Caller holds the transaction and existing-report lock.
     *
     * @param stdClass $report Report being saved
     * @param scope_builder $scope Validated scope
     * @param string $action Permission required at execution time
     * @param int $userid Actual requesting actor
     */
    private static function queue_run(stdClass $report, scope_builder $scope, string $action, int $userid): void {
        global $DB;

        // Do not destroy a usable result or its discovery map when queueing is unavailable.
        di::get(ai_request_provider::class)->require_available((int) $report->contextid);
        log_store::anonymize_report((int) $report->contextid, (int) $report->id);
        if (!empty($report->legacydata)) {
            log_store::anonymize_legacy((int) $report->contextid);
        }
        $report->status = 'pending';
        $report->runversion = (int) $report->runversion + 1;
        $report->action = $action;
        $report->resultformat = FORMAT_HTML;
        $report->truncated = 0;
        $report->legacydata = 0;
        $report->retry_count = 0;
        $report->execution_time = 0;
        $report->timemodified = di::get(clock::class)->time();
        foreach (
            ['ai_result', 'raw_data', 'error_message', 'error_details', 'error_code',
            'timecompleted', 'ai_model_name', 'token_usage'] as $field
        ) {
            $report->{$field} = null;
        }
        $DB->update_record('report_ai_analysis_reports', $report);
        $DB->delete_records('report_ai_analysis_users', ['reportid' => $report->id]);
        // Keep selected subjects discoverable while queued; refine to actual authors before the first AI request.
        foreach ($scope->get_participants_in_scope() as $subjectid) {
            $DB->insert_record('report_ai_analysis_users', (object) ['reportid' => $report->id, 'userid' => $subjectid]);
        }
        self::remove_queued_tasks((int) $report->id);
        $task = new process_analysis_task();
        $task->set_userid($userid);
        $task->set_custom_data([
            'reportid' => (int) $report->id,
            'runversion' => $report->runversion,
            'contextid' => (int) $report->contextid,
        ]);
        task_manager::queue_adhoc_task($task, true);
    }

    /**
     * Delete the report, all subject mappings and its logged content under the same short lock.
     *
     * @param int $reportid Report identifier
     * @param bool $interactive Whether to enforce interactive permissions and emit the deletion event
     */
    private static function delete_record(int $reportid, bool $interactive): void {
        global $DB;

        $lock = self::get_lock($reportid);
        try {
            $transaction = $DB->start_delegated_transaction();
            $report = $DB->get_record('report_ai_analysis_reports', ['id' => $reportid]);
            if ($report) {
                if ($interactive) {
                    report_access::require_manage($report, 'report/ai_analysis:delete');
                }
                log_store::anonymize_report((int) $report->contextid, $reportid);
                if (!empty($report->legacydata)) {
                    log_store::anonymize_legacy((int) $report->contextid);
                }
                self::remove_queued_tasks($reportid);
                $DB->delete_records('report_ai_analysis_users', ['reportid' => $reportid]);
                $DB->delete_records('report_ai_analysis_reports', ['id' => $reportid]);
                $context = context::instance_by_id($report->contextid, IGNORE_MISSING);
                if ($interactive && $context) {
                    report_deleted::create([
                        'context' => $context,
                        'objectid' => $reportid,
                        'relateduserid' => $report->userid,
                        'other' => ['title' => $report->title],
                    ])->trigger();
                }
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($exception);
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /**
     * Remove pending plugin tasks only; workers already holding a task are stopped by their generation checks.
     *
     * @param int $reportid Report identifier
     */
    private static function remove_queued_tasks(int $reportid): void {
        foreach (task_manager::get_adhoc_tasks(process_analysis_task::class, false, true) as $task) {
            if ((int) ($task->get_custom_data()->reportid ?? 0) === $reportid) {
                task_manager::delete_adhoc_task((int) $task->get_id());
            }
        }
    }
}
