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
 * Adhoc task for processing AI analysis reports.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis\task;

use Closure;
use context;
use core\clock;
use core\di;
use core\lock\lock_config;
use core\task\adhoc_task;
use core\task\manager as task_manager;
use core_text;
use html_writer;
use local_ai_manager\local\prompt_response;
use report_ai_analysis\data_collector;
use report_ai_analysis\error_info;
use report_ai_analysis\local\ai_log_provider;
use report_ai_analysis\local\ai_request_provider;
use report_ai_analysis\local\forum_vault_provider;
use report_ai_analysis\local\log_store;
use report_ai_analysis\local\provider\base_provider;
use report_ai_analysis\local\report_access;
use report_ai_analysis\local\report_manager;
use report_ai_analysis\scope_builder;
use stdClass;
use Throwable;

/**
 * Process one generation as its queued actor, never as the cron administrator.
 *
 * A run lock excludes concurrent attempts. Report locks protect short database transitions only;
 * neither those locks nor delegated transactions span collection or an external request.
 *
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_analysis_task extends adhoc_task {
    /** @var string AI Manager purpose. */
    private const AI_PURPOSE = 'singleprompt';

    /** @var int Maximum Unicode characters in a COMPLETE request, including both instruction blocks. */
    private const MAX_PROMPT_LENGTH = 1000000;

    /** @var int Shared Unicode character budget for the parent AND all participant source copies. */
    private const MAX_RAW_DATA_LENGTH = 500000;

    /** @var int Hard maximum number of explicitly scheduled retries. */
    private const MAX_RETRIES = 3;

    /**
     * Get task name.
     *
     * @return string Task name
     */
    public function get_name(): string {
        return get_string('task_process_analysis', 'report_ai_analysis');
    }

    /**
     * Execute a current generation; obsolete and concurrently running tasks are harmless no-ops.
     */
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        // Old tasks without a generation cannot be associated safely with any current run.
        if (!($data instanceof stdClass)) {
            return;
        }
        $reportid = filter_var($data->reportid ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $runversion = filter_var($data->runversion ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $attempt = filter_var($data->retry_count ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($reportid === false || $runversion === false || $attempt === false) {
            return;
        }
        $run = (object) ['id' => $reportid, 'runversion' => $runversion, 'retry_count' => $attempt];
        $runlock = lock_config::get_lock_factory('report_ai_analysis')->get_lock("run:$reportid:$runversion", 0);
        if (!$runlock) {
            return;
        }
        // Generations may change during HTTP, but a replacement worker must not log concurrently.
        // This makes a post-insert exception's ID delta attributable without reading personal log text.
        $requestlock = lock_config::get_lock_factory('report_ai_analysis')->get_lock('requests:' . $reportid, 0);
        if (!$requestlock) {
            $runlock->release();
            // Let the core task runner retry, rather than failing or silently losing the replacement run.
            throw new \moodle_exception('locktimeout');
        }

        $logids = [];
        $logcontextid = 0;
        $starttime = microtime(true);
        try {
            $existing = $DB->get_record('report_ai_analysis_reports', ['id' => $reportid], 'id,contextid,runversion');
            $logcontextid = (int) ($existing->contextid ?? $data->contextid ?? 0);
            if ($logcontextid > 0 && (!$existing || (int) $existing->runversion === $runversion)) {
                // Also recover terminal/deleted attempts; never sweep a replacement generation's logs.
                $logids = log_store::get_report_log_ids($logcontextid, $reportid);
                $this->anonymize_responses($logcontextid, $reportid, $logids);
                $logids = [];
            }
            $run = $this->transition($run, function (stdClass $report): stdClass {
                global $DB;

                $this->assert_actor_access($report);
                $this->clear_attempt_data((int) $report->id);
                $DB->update_record('report_ai_analysis_reports', (object) [
                    'id' => $report->id,
                    'status' => 'running',
                    'error_message' => null,
                    'error_details' => null,
                    'error_code' => null,
                    'truncated' => 0,
                    'timemodified' => di::get(clock::class)->time(),
                ]);
                return $report;
            });
            $scope = $this->restore_scope($run);
            $provider = di::get(ai_request_provider::class);
            $provider->require_available((int) $run->contextid);
            $prefix = $this->get_prompt_prefix($run);
            $maxrecords = $this->get_record_limit();
            $this->assert_actor_access($this->get_current_report($run));
            $collector = new data_collector($scope, $maxrecords);
            $collected = $collector->collect();
            $individual = $scope->get_analysis_mode() === scope_builder::ANALYSIS_MODE_INDIVIDUAL;
            $userdata = $collector->get_user_data($collected);
            $truncated = $collector->is_truncated();
            $sources = $this->prepare_sources(
                $userdata,
                self::MAX_PROMPT_LENGTH - core_text::strlen($prefix),
                $individual,
                $truncated
            );

            $this->store_sources($run, $sources, $truncated);
            $requests = $individual ? $sources : [0 => implode("\n\n", $sources)];
            $results = [];
            $models = [];
            $tokens = 0;
            foreach ($requests as $userid => $source) {
                $provider->require_available((int) $run->contextid);
                $this->assert_snapshot_access($run, $scope, $collected);
                $beforeids = log_store::get_report_log_ids($logcontextid, $reportid);
                try {
                    $response = $provider->perform_request(
                        self::AI_PURPOSE,
                        $prefix . $source,
                        'report_ai_analysis',
                        (int) $run->contextid,
                        ['itemid' => $reportid]
                    );
                } catch (Throwable $exception) {
                    // The manager logs before events and purpose formatting, both of which can throw.
                    $logids += array_diff_key(log_store::get_report_log_ids($logcontextid, $reportid), $beforeids);
                    throw $exception;
                }
                // Capture the ID before any response inspection, formatting or database operation can fail.
                $logid = $this->get_response_log_id($response);
                if ($logid > 0) {
                    $logids[$logid] = $logid;
                }
                $results[$userid] = $this->get_response_html($response, context::instance_by_id($run->contextid));
                $models[] = core_text::substr($response->get_modelinfo(), 0, 100);
                $tokens += max(0, (int) $response->get_usage()->value);
                // Response data is now captured locally. The actor's manager export must never retain pupil data.
                $this->anonymize_responses($logcontextid, $reportid, $logids);
                $logids = [];
            }

            $this->assert_snapshot_access($run, $scope, $collected);
            $this->transition($run, function (stdClass $report) use ($results, $individual, $models, $tokens, $starttime): void {
                global $DB;

                $this->assert_actor_access($report);
                $html = $results[0] ?? '';
                if ($individual) {
                    $users = $DB->get_records_list(
                        'user',
                        'id',
                        array_keys($results),
                        '',
                        'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename'
                    );
                    foreach ($results as $userid => $result) {
                        if (!isset($users[$userid])) {
                            throw new \moodle_exception('error_source_forbidden', 'report_ai_analysis');
                        }
                        $mapping = $DB->get_record(
                            'report_ai_analysis_users',
                            ['reportid' => $report->id, 'userid' => $userid],
                            'id',
                            MUST_EXIST
                        );
                        $DB->update_record('report_ai_analysis_users', (object) ['id' => $mapping->id, 'ai_result' => $result]);
                        $html .= html_writer::tag('h3', s(fullname($users[$userid]))) . $result;
                    }
                }
                $now = di::get(clock::class)->time();
                $DB->update_record('report_ai_analysis_reports', (object) [
                    'id' => $report->id,
                    'status' => 'completed',
                    'ai_result' => $html,
                    'resultformat' => FORMAT_HTML,
                    'ai_model_name' => core_text::substr(implode(', ', array_unique($models)), 0, 100),
                    'token_usage' => $tokens,
                    'execution_time' => (int) ceil(max(0, microtime(true) - $starttime)),
                    'timecompleted' => $now,
                    'timemodified' => $now,
                ]);
            });
        } catch (Throwable $exception) {
            if (isset($collected, $scope, $maxrecords)) {
                try {
                    // Typed connector exceptions must not schedule retries after a concurrent withdrawal.
                    $this->assert_snapshot_access($run, $scope, $collected);
                } catch (Throwable $currentexception) {
                    $exception = $currentexception;
                }
            }
            if (!$this->is_obsolete($exception)) {
                $this->store_failure($run, $exception);
            }
        } finally {
            try {
                $this->anonymize_responses($logcontextid, $reportid, $logids);
            } finally {
                $requestlock->release();
                $runlock->release();
            }
        }
    }

    /**
     * Perform an atomic, generation- and attempt-checked transition under the manager's shared lock.
     *
     * @param stdClass $run Expected generation and optional immutable report fields
     * @param Closure $callback Short database operation, never collection or a request
     * @return mixed Callback result
     */
    private function transition(stdClass $run, Closure $callback): mixed {
        global $DB;

        $lock = report_manager::get_lock((int) $run->id);
        try {
            $report = $this->get_current_report($run);
            $hadtransaction = $DB->is_transaction_started();
            $transaction = $DB->start_delegated_transaction();
            $result = $callback($report);
            $transaction->allow_commit();
            return $result;
        } catch (Throwable $exception) {
            try {
                if (isset($transaction) && !$transaction->is_disposed()) {
                    $transaction->rollback($exception);
                }
            } finally {
                // A driver can fail after core disposes the handle. Never unwind a caller-owned transaction.
                if (isset($hadtransaction) && !$hadtransaction && $DB->is_transaction_started()) {
                    $DB->force_transaction_rollback();
                }
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /**
     * Reject deleted, terminal, superseded and already retried work without writing any old state.
     *
     * @param stdClass $run Expected generation
     * @return stdClass Current report
     */
    private function get_current_report(stdClass $run): stdClass {
        global $DB;

        $report = $DB->get_record('report_ai_analysis_reports', ['id' => $run->id]);
        if (
            !$report || (int) $report->runversion !== (int) $run->runversion ||
                (int) $report->retry_count !== (int) $run->retry_count ||
            !in_array($report->status, ['pending', 'running'], true)
        ) {
            throw new \moodle_exception('error_task_changed', 'report_ai_analysis');
        }
        foreach (['contextid', 'userid', 'action', 'scope_details', 'prompt'] as $field) {
            if (isset($run->{$field}) && (string) $run->{$field} !== (string) $report->{$field}) {
                throw new \moodle_exception('error_task_changed', 'report_ai_analysis');
            }
        }
        return $report;
    }

    /**
     * Recheck the account, course, action and ownership using the actual executing user.
     *
     * CLI access caches can outlive another process's permission changes. Reload enrolments and
     * assignments, and fail closed if cached definitions differ from the core uncached role API.
     * This does not purge site-wide caches, change capabilities or impersonate another user.
     *
     * @param stdClass $report Current report
     * @return context Authorised course context
     */
    private function assert_actor_access(stdClass $report): context {
        global $CFG, $DB, $USER;

        $userid = filter_var($this->get_userid(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($userid === false || $userid !== (int) ($USER->id ?? 0) || !in_array($report->action, ['create', 'rerun'], true)) {
            throw new \moodle_exception('error_access_revoked', 'report_ai_analysis');
        }
        reload_all_capabilities();
        $roleids = $DB->get_fieldset_select('role_assignments', 'DISTINCT roleid', 'userid = :userid', ['userid' => $userid]);
        $roleids = array_values(array_unique(array_filter(array_merge(
            $roleids,
            [$CFG->defaultuserroleid ?? 0, $CFG->defaultfrontpageroleid ?? 0]
        ))));
        if (get_role_definitions($roleids) != get_role_definitions_uncached($roleids)) {
            throw new \moodle_exception(
                'error_access_revoked',
                'report_ai_analysis',
                '',
                null,
                'Role definitions changed during this worker process'
            );
        }
        $context = context::instance_by_id($report->contextid, IGNORE_MISSING);
        if (!$context || $context->contextlevel !== CONTEXT_COURSE) {
            throw new \moodle_exception('error_access_revoked', 'report_ai_analysis');
        }
        report_access::require_course_access($context, $userid);
        if (!report_access::can_manage($report, 'report/ai_analysis:' . $report->action, $userid)) {
            throw new \moodle_exception('error_access_revoked', 'report_ai_analysis');
        }
        get_fast_modinfo((int) $context->instanceid, 0, true);
        return $context;
    }

    /**
     * Restore only recognised scope fields and bind them to the real report context.
     *
     * @param stdClass $report Current report
     * @return scope_builder Validated scope definition
     */
    private function restore_scope(stdClass $report): scope_builder {
        try {
            $scope = scope_builder::from_json($report->scope_details);
        } catch (Throwable $exception) {
            throw new \moodle_exception('error_source_forbidden', 'report_ai_analysis', '', null, $exception->getMessage());
        }
        $context = context::instance_by_id($report->contextid);
        if ($context->contextlevel !== CONTEXT_COURSE || (int) $context->instanceid !== $scope->get_course_in_scope()) {
            throw new \moodle_exception(
                'error_source_forbidden',
                'report_ai_analysis',
                '',
                null,
                'Stored scope does not belong to the report course'
            );
        }
        return $scope;
    }

    /**
     * Revalidate the captured payload, not the current course as a whole.
     *
     * Check captured identities, content and current permissions only. New posts, requests and authors
     * do not invalidate an otherwise unchanged snapshot and never enter an already prepared prompt.
     * No whole-course recollection or report lock is needed. Unknown source shapes fail closed.
     *
     * @param stdClass $run Expected report generation
     * @param scope_builder $scope Original scope
     * @param array $collected Original authorised payload
     */
    private function assert_snapshot_access(stdClass $run, scope_builder $scope, array $collected): void {
        $this->assert_actor_access($this->get_current_report($run));
        $scope->validate_sources();
        foreach ($collected as $type => $rows) {
            if ($type === 'mod_forum') {
                $this->assert_forum_snapshot($scope, $rows);
            } else if ($type === 'block_ai_chat') {
                $this->assert_chat_snapshot($scope, $rows);
            } else {
                throw new \moodle_exception(
                    'error_source_failed',
                    'report_ai_analysis',
                    '',
                    null,
                    'No snapshot validator for source ' . $type
                );
            }
        }
        $this->assert_actor_access($this->get_current_report($run));
    }

    /**
     * Validate only included forum posts through the forum entity and capability APIs.
     *
     * @param scope_builder $scope Original scope
     * @param array $rows Captured discussions and post trees
     */
    private function assert_forum_snapshot(scope_builder $scope, array $rows): void {
        global $USER;

        $adapter = new forum_vault_provider();
        $forums = $adapter->get_forum_vault()->get_from_ids(array_unique(array_column($rows, 'forumid')));
        $discussions = $adapter->get_vault_factory()->get_discussion_vault()->get_from_ids(array_column($rows, 'discussionid'));
        $cmids = $scope->get_activities_in_scope();
        $participants = [];
        foreach ($rows as $row) {
            $forum = $forums[$row['forumid']] ?? null;
            $discussion = $discussions[$row['discussionid']] ?? null;
            if (
                !$forum || !$discussion || $discussion->get_forum_id() !== $forum->get_id() ||
                    !in_array($forum->get_course_module_record()->id, $cmids) ||
                    $forum->get_course_id() !== $scope->get_course_in_scope()
            ) {
                throw new \moodle_exception('error_source_forbidden', 'report_ai_analysis');
            }
            $manager = $adapter->get_capability_manager($forum);
            if (!$manager->can_view_discussions($USER) || !$manager->can_view_discussion($USER, $discussion)) {
                throw new \moodle_exception('error_source_forbidden', 'report_ai_analysis');
            }
            $participants[$forum->get_id()] ??= $scope->get_participants_in_scope($forum->get_context());
            $posts = [];
            $pending = $row['posts'];
            while ($pending) {
                $post = array_pop($pending);
                $posts[$post['postid']] = $post;
                array_push($pending, ...$post['replies']);
            }
            foreach (array_chunk($posts, 100, true) as $chunk) {
                $entities = $adapter->get_post_vault()->get_from_ids(array_keys($chunk));
                foreach ($chunk as $id => $original) {
                    $post = $entities[$id] ?? null;
                    if (
                        !$post || $post->is_deleted() || $post->get_discussion_id() !== $discussion->get_id() ||
                            !in_array($post->get_author_id(), $participants[$forum->get_id()]) ||
                            !$manager->can_view_post($USER, $discussion, $post) ||
                            $post->get_author_id() !== (int) $original['authorid'] ||
                            $post->get_subject() !== $original['subject'] ||
                            $post->get_message() !== $original['message'] ||
                            $post->get_message_format() !== (int) $original['messageformat'] ||
                            $post->get_time_created() !== (int) $original['created'] ||
                            $post->get_time_modified() !== (int) $original['timemodified']
                    ) {
                        throw new \moodle_exception('error_source_forbidden', 'report_ai_analysis');
                    }
                }
            }
        }
    }

    /**
     * Validate captured chat requests and date rights through the public manager log API.
     *
     * The API has no ID-list filter. Read only captured authors/contexts under one shared hard
     * scan limit, and compare captured request IDs rather than the current thread as a whole.
     * Exhaustion is a source failure, not a claim that an absent ID proves revoked permission.
     *
     * @param scope_builder $scope Original scope
     * @param array $rows Captured chat threads
     */
    private function assert_chat_snapshot(scope_builder $scope, array $rows): void {
        global $USER;

        $adapter = di::get(ai_log_provider::class);
        $contexts = $scope->get_block_contexts_in_scope();
        $participants = [];
        $authorentries = [];
        $windowfull = [];
        $remaining = base_provider::HARD_RECORD_LIMIT;
        $timerange = $scope->get_timerange_in_scope();
        foreach ($rows as $row) {
            $contextid = (int) $row['contextid'];
            $userid = (int) $row['userid'];
            if (!in_array($contextid, $contexts)) {
                throw new \moodle_exception('error_source_forbidden', 'report_ai_analysis');
            }
            $context = context::instance_by_id($contextid);
            $participants[$contextid] ??= $scope->get_participants_in_scope($context);
            if (
                !in_array($userid, $participants[$contextid]) || ($userid !== (int) $USER->id &&
                    (!has_capability('local/ai_manager:viewprompts', $context) ||
                    ($row['canviewdates'] && !has_capability('local/ai_manager:viewpromptsdates', $context))))
            ) {
                throw new \moodle_exception('error_source_forbidden', 'report_ai_analysis');
            }
            // Legacy item-less threads use request ID as their display ID, so do not filter by it.
            $key = $contextid . ':' . $userid;
            if (!isset($authorentries[$key])) {
                if ($remaining <= 0) {
                    throw new \moodle_exception('error_source_failed', 'report_ai_analysis');
                }
                $authorentries[$key] = $adapter->get_log_entries(
                    'block_ai_chat',
                    $contextid,
                    $userid,
                    0,
                    false,
                    'id,userid,contextid,component,purpose,itemid,timecreated,prompttext,promptcompletion,deleted',
                    ['chat'],
                    $remaining + 1
                );
                $windowfull[$key] = count($authorentries[$key]) > $remaining;
                $remaining = max(0, $remaining - count($authorentries[$key]));
            }
            $entries = $authorentries[$key];
            foreach ($row['messages'] as $message) {
                $entry = $entries[$message['requestid']] ?? null;
                if (!$entry && $windowfull[$key]) {
                    throw new \moodle_exception(
                        'error_source_failed',
                        'report_ai_analysis',
                        '',
                        null,
                        'Captured chat request is outside the bounded verification window'
                    );
                }
                if (
                    !$entry || !empty($entry->deleted) || (int) $entry->userid !== $userid ||
                        (int) $entry->contextid !== $contextid || $entry->component !== 'block_ai_chat' ||
                        $entry->purpose !== 'chat' || (int) ($entry->itemid ?: $entry->id) !== (int) $row['threadid'] ||
                        (!empty($timerange->start) && $entry->timecreated < $timerange->start) ||
                        (!empty($timerange->end) && $entry->timecreated > $timerange->end)
                ) {
                    throw new \moodle_exception('error_source_forbidden', 'report_ai_analysis');
                }
                $content = $message['role'] === 'user' ? $entry->prompttext :
                    $adapter->format_purpose_output('chat', $entry->promptcompletion);
                if (
                    $content !== $message['content'] ||
                        (isset($message['timestamp']) && (int) $entry->timecreated !== $message['timestamp'])
                ) {
                    throw new \moodle_exception('error_source_forbidden', 'report_ai_analysis');
                }
            }
        }
    }

    /**
     * Get the validated global post/request budget; never multiply it by participants or providers.
     *
     * @return int Positive bounded record limit
     */
    private function get_record_limit(): int {
        $configured = get_config('report_ai_analysis', 'max_records_per_analysis');
        $limit = filter_var(
            $configured === false ? 1000 : $configured,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($limit === false) {
            throw new \moodle_exception(
                'error_source_failed',
                'report_ai_analysis',
                '',
                null,
                'Invalid max_records_per_analysis: a positive integer is required'
            );
        }
        return min($limit, base_provider::HARD_RECORD_LIMIT);
    }

    /**
     * Build instructions without shortening them, reserving space for source text and a truncation marker.
     *
     * @param stdClass $report Report containing user instructions
     * @return string Complete instruction prefix
     */
    private function get_prompt_prefix(stdClass $report): string {
        $system = get_config('report_ai_analysis', 'system_prompt');
        $system = $system !== false && trim($system) !== '' ? $system : get_string('system_prompt_default', 'report_ai_analysis');
        $prefix = $system . "\n\n" . $report->prompt . "\n\n";
        if (
            core_text::strlen($prefix) + core_text::strlen("\n" . get_string('export_truncated', 'report_ai_analysis')) >=
            self::MAX_PROMPT_LENGTH
        ) {
            throw new \moodle_exception('error_prompt_too_long', 'report_ai_analysis');
        }
        return $prefix;
    }

    /**
     * Bound formatted author text at Unicode character boundaries, including the visible marker.
     *
     * Aggregation concatenates these very same author strings. Entirely omitted authors get no map;
     * the last included author can have a partial formatted source, explicitly marked as truncated.
     * This is a text boundary, not a promise to retain complete posts or conversation pairs. Subject
     * copies are therefore prefixes of what was actually sent, never an uncut alternative payload.
     *
     * @param array<int, string> $userdata Actual authors' isolated source strings
     * @param int $budget Remaining characters after the instruction prefix
     * @param bool $individual Whether each author has their own request budget
     * @param bool $truncated Updated truncation flag
     * @return array<int, string> Source strings included in requests
     */
    private function prepare_sources(array $userdata, int $budget, bool $individual, bool &$truncated): array {
        $userdata = array_filter($userdata, static fn(string $text): bool => trim($text) !== '');
        if (!$userdata) {
            throw new \moodle_exception('error_no_data', 'report_ai_analysis');
        }
        $marker = "\n" . get_string('export_truncated', 'report_ai_analysis');
        $markerlength = core_text::strlen($marker);
        if ($individual) {
            foreach ($userdata as &$text) {
                if (core_text::strlen($text) > $budget) {
                    $text = core_text::substr($text, 0, $budget - $markerlength) . $marker;
                    $truncated = true;
                }
            }
            unset($text);
            return $userdata;
        }
        $length = array_sum(array_map([core_text::class, 'strlen'], $userdata)) + 2 * (count($userdata) - 1);
        if ($length <= $budget) {
            return $userdata;
        }
        $truncated = true;
        $remaining = $budget - $markerlength;
        $sources = [];
        foreach ($userdata as $userid => $text) {
            $remaining -= $sources ? 2 : 0;
            if ($remaining <= 0) {
                break;
            }
            $sources[$userid] = core_text::substr($text, 0, $remaining);
            $remaining -= core_text::strlen($sources[$userid]);
        }
        $sources[array_key_last($sources)] .= $marker;
        return $sources;
    }

    /**
     * Persist exact attribution before the first outbound request, even when raw storage is disabled.
     *
     * At most half the configured shared raw-copy budget is reserved for the parent; the remainder
     * is consumed by participant prefixes in request order. A depleted copy budget leaves null,
     * not another full copy per user. Raw-copy shortening does not imply an incomplete AI request.
     *
     * @param stdClass $run Expected generation
     * @param array<int, string> $sources Included author strings
     * @param bool $truncated Whether collection or request formatting was shortened
     */
    private function store_sources(stdClass $run, array $sources, bool $truncated): void {
        $raw = null;
        $copies = array_fill_keys(array_keys($sources), null);
        if (get_config('report_ai_analysis', 'store_raw_data')) {
            $limit = (int) get_config('report_ai_analysis', 'truncate_raw_data_length');
            $limit = $limit > 0 ? min($limit, self::MAX_RAW_DATA_LENGTH) : self::MAX_RAW_DATA_LENGTH;
            $parentbudget = intdiv($limit, 2);
            $parent = '';
            foreach ($sources as $source) {
                if ($parentbudget <= 0) {
                    break;
                }
                $part = core_text::substr(($parent !== '' ? "\n\n" : '') . $source, 0, $parentbudget);
                $parent .= $part;
                $parentbudget -= core_text::strlen($part);
            }
            $raw = $parent !== '' ? $parent : null;
            $remaining = $limit - core_text::strlen($parent);
            foreach ($sources as $userid => $source) {
                if ($remaining <= 0) {
                    break;
                }
                $copies[$userid] = core_text::substr($source, 0, $remaining);
                $remaining -= core_text::strlen($copies[$userid]);
            }
        }
        $this->transition($run, function (stdClass $report) use ($copies, $raw, $truncated): void {
            global $DB;

            $this->assert_actor_access($report);
            $DB->delete_records('report_ai_analysis_users', ['reportid' => $report->id]);
            foreach ($copies as $userid => $source) {
                $DB->insert_record('report_ai_analysis_users', (object) [
                    'reportid' => $report->id, 'userid' => $userid, 'source_data' => $source, 'ai_result' => null,
                ]);
            }
            $DB->update_record('report_ai_analysis_reports', (object) [
                'id' => $report->id, 'raw_data' => $raw, 'truncated' => (int) $truncated,
            ]);
        });
    }

    /**
     * Clear attempt-local copies without removing privacy discovery or mutating a loaded report object.
     *
     * @param int $reportid Report protected by its lock and transaction
     */
    private function clear_attempt_data(int $reportid): void {
        global $DB;

        $DB->update_record('report_ai_analysis_reports', (object) [
            'id' => $reportid,
            'ai_result' => null,
            'raw_data' => null,
            'ai_model_name' => null,
            'token_usage' => null,
            'execution_time' => 0,
            'timecompleted' => null,
            'resultformat' => FORMAT_HTML,
        ]);
        $DB->set_field('report_ai_analysis_users', 'source_data', null, ['reportid' => $reportid]);
        $DB->set_field('report_ai_analysis_users', 'ai_result', null, ['reportid' => $reportid]);
    }

    /**
     * Validate the response contract and clean purpose HTML without applying Markdown a second time.
     *
     * @param prompt_response $response Manager response
     * @param context $context Actual report course context
     * @return string Nonempty, untrusted, cleaned HTML
     */
    private function get_response_html(prompt_response $response, context $context): string {
        if ($response->get_code() !== 200) {
            throw new \moodle_exception(
                $this->get_response_error_code($response),
                'report_ai_analysis',
                '',
                null,
                $response->get_errormessage() . "\n" . $response->get_debuginfo()
            );
        }
        $html = format_text($response->get_content(), FORMAT_HTML, [
            'context' => $context, 'clean' => true, 'trusted' => false, 'filter' => false, 'para' => false,
        ]);
        if (preg_match('/[^\s\p{Z}\x{200B}\x{FEFF}]/u', html_to_text($html, 0, false)) !== 1) {
            throw new \moodle_exception('error_empty_response', 'report_ai_analysis');
        }
        return $html;
    }

    /**
     * Read optional linkage; the upstream error/result factories can leave this typed property unset.
     *
     * @param prompt_response $response Manager response
     * @return int Exact log ID, or zero when the manager created no log
     */
    private function get_response_log_id(prompt_response $response): int {
        try {
            return (int) ($response->get_logrecordid() ?? 0);
        } catch (\Error $exception) {
            if (!(new \ReflectionProperty(prompt_response::class, 'logrecordid'))->isInitialized($response)) {
                return 0;
            }
            throw $exception;
        }
    }

    /**
     * Clean only known response IDs, even after deletion or a replacement generation's new logs.
     *
     * @param int $contextid Original report context
     * @param int $reportid Original report ID
     * @param int[] $logids Exact responses from this attempt or its crashed predecessor
     */
    private function anonymize_responses(int $contextid, int $reportid, array $logids): void {
        log_store::anonymize_responses($contextid, $reportid, $logids);
    }

    /**
     * Save failure/retry state atomically; a failed queue/write gets one separate terminal-state attempt.
     *
     * @param stdClass $run Expected generation and attempt
     * @param Throwable $exception Processing failure
     */
    private function store_failure(stdClass $run, Throwable $exception): void {
        try {
            $this->transition($run, function (stdClass $report) use ($exception): void {
                $this->write_failure($report, $exception, true);
            });
        } catch (Throwable $writeexception) {
            if ($this->is_obsolete($writeexception)) {
                return;
            }
            try {
                $this->transition($run, function (stdClass $report) use ($writeexception): void {
                    $this->write_failure($report, $writeexception, false);
                });
            } catch (Throwable $finalexception) {
                if (!$this->is_obsolete($finalexception)) {
                    // Database unavailability cannot be reported as a successfully persisted failure.
                    throw $finalexception;
                }
            }
        }
    }

    /**
     * Write a minimal error object, never a partly populated success object. Caller owns the transaction.
     *
     * @param stdClass $report Current locked report
     * @param Throwable $exception Failure to classify
     * @param bool $allowretry Whether an explicitly typed transient error may schedule a retry
     */
    private function write_failure(stdClass $report, Throwable $exception, bool $allowretry): void {
        global $DB;

        $maxretries = min(self::MAX_RETRIES, max(0, (int) get_config('report_ai_analysis', 'retry_on_failure')));
        $retry = $allowretry && (int) $report->retry_count < $maxretries && $this->is_retryable_error($exception);
        if ($retry) {
            $this->assert_actor_access($report);
        }
        $this->clear_attempt_data((int) $report->id);
        $code = $this->get_error_code($exception);
        $description = error_info::get_description($code);
        $now = di::get(clock::class)->time();
        $update = (object) [
            'id' => $report->id,
            'status' => $retry ? 'pending' : 'failed',
            'error_code' => $code,
            'error_message' => $description,
            'error_details' => $this->get_exception_details($exception, $description),
            'timemodified' => $now,
            'timecompleted' => $retry ? null : $now,
        ];
        if ($retry) {
            $update->retry_count = (int) $report->retry_count + 1;
        }
        $DB->update_record('report_ai_analysis_reports', $update);
        if ($retry) {
            $task = new self();
            $task->set_userid((int) $this->get_userid());
            $task->set_custom_data([
                'reportid' => (int) $report->id,
                'contextid' => (int) $report->contextid,
                'runversion' => (int) $report->runversion,
                'retry_count' => $update->retry_count,
            ]);
            $task->set_next_run_time($now + 60 * (2 ** ($update->retry_count - 1)));
            if (!task_manager::queue_adhoc_task($task)) {
                throw new \coding_exception('Could not queue the analysis retry');
            }
        }
    }

    /**
     * Restrict retries to explicit typed transient failures, never translated text or diagnostics.
     *
     * @param Throwable $exception Processing failure
     * @return bool Whether retrying is explicitly allowed
     */
    private function is_retryable_error(Throwable $exception): bool {
        return $exception instanceof \moodle_exception && in_array(
            $exception->errorcode,
            ['error_api_timeout', 'error_api_connection_error', 'error_rate_limit'],
            true
        );
    }

    /**
     * Identify internal invalidation without persisting an obsolete run's error.
     *
     * @param Throwable $exception Processing failure
     * @return bool Whether the run was superseded
     */
    private function is_obsolete(Throwable $exception): bool {
        return $exception instanceof \moodle_exception && $exception->errorcode === 'error_task_changed';
    }

    /**
     * Return only allowlisted localised codes, including the manager's typed missing-purpose exception.
     *
     * @param Throwable $exception Processing failure
     * @return string Safe error code
     */
    private function get_error_code(Throwable $exception): string {
        if ($exception instanceof \moodle_exception) {
            if ($exception->errorcode === 'error_limitreached' && $exception->module === 'local_ai_manager') {
                return 'error_rate_limit';
            }
            if ($exception->errorcode === 'error_noaitoolassignedforpurpose') {
                return 'error_purposenotconfigured';
            }
            if (error_info::is_user_error_code($exception->errorcode)) {
                return $exception->errorcode;
            }
        }
        return 'error_unknown';
    }

    /**
     * Classify HTTP-like codes. Only 403 needs the manager's exact documented message to distinguish consent.
     *
     * @param prompt_response $response Unsuccessful manager response
     * @return string Localised error code
     */
    private function get_response_error_code(prompt_response $response): string {
        if ($response->get_code() === 403) {
            if ($response->get_errormessage() === get_string('error_http403notconfirmed', 'local_ai_manager')) {
                return 'error_terms_not_accepted';
            }
            if (
                $response->get_errormessage() === get_string(
                    'error_purposenotconfigured',
                    'local_ai_manager',
                    get_string('pluginname', 'aipurpose_singleprompt')
                )
            ) {
                return 'error_purposenotconfigured';
            }
        }
        return match ($response->get_code()) {
            408, 504 => 'error_api_timeout',
            429 => 'error_rate_limit',
            502, 503 => 'error_api_connection_error',
            default => 'error_ai_request',
        };
    }

    /**
     * Get technical details from an exception without exposing them to users.
     *
     * @param Throwable $exception Processing failure
     * @param string|null $description User-facing error description
     * @return string|null Technical diagnostics for the existing debug-gated presentation
     */
    private function get_exception_details(Throwable $exception, ?string $description = null): ?string {
        $details = [];
        if ($exception instanceof \moodle_exception && !empty($exception->debuginfo)) {
            $details[] = $exception->debuginfo;
        }
        if ($exception->getMessage() !== '' && $exception->getMessage() !== $description) {
            $details[] = $exception->getMessage();
        }
        $details = array_unique($details);
        return $details ? implode("\n", $details) : null;
    }
}
