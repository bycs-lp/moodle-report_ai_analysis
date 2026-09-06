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

namespace report_ai_analysis\task;

use context_block;
use context_course;
use context_module;
use core\di;
use core\lock\db_record_lock_factory;
use core\lock\lock_config;
use core\task\manager as task_manager;
use core_text;
use local_ai_manager\local\data_wiper;
use local_ai_manager\local\prompt_response;
use local_ai_manager\local\usage;
use moodle_database;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use report_ai_analysis\data_collector;
use report_ai_analysis\error_info;
use report_ai_analysis\local\ai_log_provider;
use report_ai_analysis\local\ai_request_provider;
use report_ai_analysis\local\provider\provider_factory;
use report_ai_analysis\local\report_manager;
use report_ai_analysis\scope_builder;
use stdClass;

/**
 * Full worker regressions with real data and authorisation, without contacting an external AI service.
 *
 * PostgreSQL runs deliberately do not wrap each test in a reset transaction: network boundaries,
 * delegated rollback and non-reentrant record locks must behave as they do in a real worker.
 * These are deterministic single-process interleavings, not a claim of multiprocess verification.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(process_analysis_task::class)]
#[Group('report_ai_analysis')]
#[Group('local_mbs')]
#[Group('mebis')]
final class process_analysis_task_test extends \advanced_testcase {
    /**
     * Load test-only collaborators without adding production autoloading hooks.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        require_once(__DIR__ . '/fixtures/request_provider.php');
        require_once(__DIR__ . '/fixtures/database_fault.php');
    }

    /**
     * Release injected request/source objects before the next test.
     */
    protected function tearDown(): void {
        di::reset_container();
        parent::tearDown();
    }

    /**
     * Success, attribution and the write contract across both modes: linked aggregated and isolated individual
     * requests with pre-request attribution and released logs/locks under either raw policy, HTML cleaning and
     * escaped headings, single-author selection, integer/Unicode-safe metadata, factory responses without a log
     * ID, the manager save contract and write-fault rollback to a minimal separate failure.
     */
    public function test_success_attribution_and_write_contract(): void {
        $this->check_aggregated_completion_and_pre_request_attribution();
        $this->check_aggregated_raw_disabled_retains_attribution();
        foreach (self::raw_policy_provider() as $case) {
            $this->check_individual_requests_and_results_are_isolated(...$case);
        }
        $this->check_html_cleaning_and_escaped_participant_heading();
        $this->check_selected_author_excludes_other_thread_metadata();
        $this->check_success_write_uses_integer_metadata_and_bounded_unicode_model();
        $this->check_response_factory_without_log_id_is_supported();
        $this->check_task_from_manager_save_contract();
        foreach (self::database_fault_provider() as $case) {
            $this->check_database_failure_rolls_back_and_stores_minimal_error(...$case);
        }
    }

    /**
     * Failures are classified without parsing translated messages and retried within bounds: HTTP-like codes,
     * empty bodies, typed and untyped exceptions and the typed retry allowlist map to fixed local codes; retry
     * decisions honour configuration and the hard maximum with backoff, run the full sequence, clean a partial
     * individual attempt, and stay terminal after actor withdrawal or a known manager quota.
     */
    public function test_failure_classification_and_retry_lifecycle(): void {
        foreach (self::response_failure_provider() as $case) {
            $this->check_response_failure_classification(...$case);
        }
        foreach (self::empty_response_provider() as $case) {
            $this->check_empty_response_is_not_success(...$case);
        }
        foreach (self::exception_provider() as $case) {
            $this->check_exception_classification_does_not_parse_messages(...$case);
        }
        foreach (self::typed_retry_provider() as $case) {
            $this->check_typed_connector_exception_can_retry(...$case);
        }
        foreach (self::retry_limit_provider() as $case) {
            $this->check_retry_limits(...$case);
        }
        $this->check_full_retry_sequence_and_duplicate_old_attempt();
        $this->check_partial_individual_failure_and_clean_retry();
        $this->check_transient_exception_after_withdrawal_does_not_retry();
        $this->check_known_manager_quota_is_not_retried();
    }

    /**
     * Preflight, actor and source authorisation all fail closed before broadening data: backend availability,
     * consent and cancellation are checked before collection; actor, account, role, ownership and action rights
     * are enforced before and during a request; and persisted scope, participant, role, forum, chat and group
     * boundaries reject forged scope, empty selections, private replies, revoked access and invalid limits.
     */
    public function test_preflight_actor_and_source_authorization(): void {
        foreach (self::preflight_failure_provider() as $case) {
            $this->check_unavailable_backend_stops_before_collection(...$case);
        }
        $this->check_cancellation_during_preflight_stops_collection();
        $this->check_consent_withdrawal_before_next_individual_request();
        foreach (self::actor_revocation_provider() as $case) {
            $this->check_actor_revocation(...$case);
        }
        $this->check_stale_role_definition_cache_fails_closed();
        foreach (self::ownership_provider() as $case) {
            $this->check_actual_requester_ownership_policy(...$case);
        }
        foreach (self::action_provider() as $case) {
            $this->check_only_the_current_action_is_required(...$case);
        }
        foreach (self::invalid_actor_provider() as $case) {
            $this->check_missing_or_mismatched_actor(...$case);
        }
        foreach (self::forged_scope_provider() as $case) {
            $this->check_forged_scope_is_rejected(...$case);
        }
        $this->check_empty_participant_selection_is_no_data();
        $this->check_role_filter_with_no_selected_authors_is_no_data();
        $this->check_private_reply_is_never_sent_without_source_permission();
        $this->check_forum_access_revoked_before_execution();
        $this->check_foreign_chat_permission_denial_before_execution();
        $this->check_chat_date_privacy_and_real_log_filtering();
        $this->check_source_failure_is_not_a_partial_success_or_retry();
        foreach (self::invalid_limit_provider() as $case) {
            $this->check_invalid_record_limit_is_not_unlimited(...$case);
        }
        foreach (self::group_membership_provider() as $case) {
            $this->check_group_membership_is_a_source_boundary(...$case);
        }
    }

    /**
     * Generation recovery and the log-capture lifecycle: obsolete, invalid, duplicate, deleted and crashed
     * metadata never restart or overwrite a report while a released lock lets a crash resume and clear residue;
     * cancelled, deleted and replaced generations survive late old responses without wiping new logs; and
     * residual personal logs from terminal, deleted or crashed attempts are anonymised on repeat.
     */
    public function test_generation_recovery_and_log_lifecycle(): void {
        foreach (self::obsolete_provider() as $case) {
            $this->check_obsolete_work_is_a_noop(...$case);
        }
        $this->check_deleted_report_is_skipped();
        $this->check_duplicate_generation_lock_and_crash_recovery();
        $this->check_resuming_clears_partial_attempt_data_and_logs();
        foreach (self::lifecycle_provider() as $case) {
            $this->check_late_response_preserves_current_lifecycle(...$case);
        }
        $this->check_cancellation_cleans_all_earlier_response_ids();
        $this->check_late_response_without_log_id_preserves_new_generation_logs();
        $this->check_exception_after_log_insert_cleans_unreturned_response();
        $this->check_replacement_worker_does_not_log_concurrently();
        foreach (self::cleanup_state_provider() as $case) {
            $this->check_repeated_inactive_task_cleans_residual_logs(...$case);
        }
        $this->check_repeated_stale_task_preserves_new_generation_log();
        $this->check_crashed_attempt_log_is_cleaned_when_access_was_revoked();
    }

    /**
     * Snapshot stability and prompt/raw budgets: forum and chat withdrawals discard results while additional
     * allowed content never invalidates captured IDs, and one global record budget spans all sources; the
     * complete Unicode prompt is bounded, an oversized prefix fails before transmission, and the configured and
     * hard raw budgets are shared across every stored copy.
     */
    public function test_snapshot_stability_and_budgets(): void {
        foreach (self::forum_withdrawal_provider() as $case) {
            $this->check_forum_snapshot_withdrawal_discards_all_results(...$case);
        }
        foreach (self::chat_withdrawal_provider() as $case) {
            $this->check_chat_snapshot_withdrawal(...$case);
        }
        $this->check_new_forum_posts_do_not_invalidate_the_snapshot();
        $this->check_new_chat_entries_do_not_invalidate_the_snapshot();
        $this->check_global_record_budget_across_real_sources();
        foreach (self::mode_provider() as $case) {
            $this->check_complete_unicode_prompt_bound(...$case);
        }
        $this->check_oversized_instruction_prefix_fails_without_transmission();
        foreach (self::mode_provider() as $case) {
            $this->check_small_raw_budget_is_shared_by_all_copies(...$case);
        }
        foreach (self::raw_hard_limit_provider() as $case) {
            $this->check_raw_hard_limit_is_global(...$case);
        }
    }

    /**
     * Aggregation sends one correctly linked request and refines queued attribution before transmission.
     */
    private function check_aggregated_completion_and_pre_request_attribution(): void {
        $s = $this->scenario();
        $s->task->execute();

        $report = $this->report($s);
        $this->assertSame(get_string('task_process_analysis', 'report_ai_analysis'), $s->task->get_name());
        $this->assertSame('completed', $report->status);
        $this->assertSame(1, (int) $report->runversion);
        $this->assertSame((int) $s->teacher->id, (int) $report->userid);
        $this->assertSame((int) FORMAT_HTML, (int) $report->resultformat);
        $this->assertSame('fixture-model', $report->ai_model_name);
        $this->assertSame(12, (int) $report->token_usage);
        $this->assertGreaterThanOrEqual(0, (int) $report->execution_time);
        $this->assertGreaterThan(0, (int) $report->timecompleted);
        $this->assertNull($report->error_code);
        $this->assertNull($report->error_message);
        $this->assertNull($report->error_details);
        $this->assertSame(0, (int) $report->truncated);
        $this->assertCount(1, $s->provider->requests);
        $request = $s->provider->requests[0];
        $this->assert_request_contract($s, $request);
        $this->assertStringContainsString('ALPHA_SOURCE', $request['prompt']);
        $this->assertStringContainsString('BETA_SOURCE', $request['prompt']);
        $this->assertStringContainsString('SYSTEM INSTRUCTIONS', $request['prompt']);
        $this->assertStringContainsString($s->report->prompt, $request['prompt']);
        $this->assertSame('running', $request['report']->status);
        $this->assertNull($request['report']->ai_result);
        $this->assertSame([(int) $s->alpha->id, (int) $s->beta->id], array_keys($request['subjects']));
        $collector = new data_collector($s->scope);
        $expected = $collector->get_user_data($collector->collect());
        foreach ($request['subjects'] as $userid => $mapping) {
            $this->assertNull($mapping->ai_result);
            $this->assertNotNull($mapping->source_data);
            $this->assertSame($expected[$userid], $mapping->source_data);
            $this->assertStringContainsString($mapping->source_data, $request['prompt']);
            $this->assertNull($this->subjects($s)[$userid]->ai_result);
        }
        $this->assertStringContainsString('ALPHA_SOURCE', $report->raw_data);
        $this->assertStringContainsString('BETA_SOURCE', $report->raw_data);
        $this->assertSame([], $this->queued($s));
        $this->assert_logs($s, true);
        $this->assert_locks_released($s);
    }

    /**
     * Individual mode isolates authors, including the other author's root metadata, with either raw policy.
     *
     * @param bool $storeraw Whether raw copies are enabled
     */
    private function check_individual_requests_and_results_are_isolated(bool $storeraw): void {
        $s = $this->scenario('individual');
        set_config('store_raw_data', (int) $storeraw, 'report_ai_analysis');
        $s->task->execute();

        $this->assertSame('completed', $this->report($s)->status);
        $this->assertCount(2, $s->provider->requests);
        [$first, $second] = $s->provider->requests;
        foreach ([$first, $second] as $request) {
            $this->assert_request_contract($s, $request);
            $this->assertCount(2, $request['subjects']);
            foreach ($request['subjects'] as $mapping) {
                $this->assertNull($mapping->ai_result, 'Individual results must commit with the parent, not piecemeal');
            }
        }
        foreach (['ALPHA_SOURCE', 'ALPHA_ROOT_TITLE', fullname($s->alpha)] as $alpha) {
            $this->assertStringContainsString($alpha, $first['prompt']);
            $this->assertStringNotContainsString($alpha, $second['prompt']);
        }
        $this->assertStringContainsString('BETA_SOURCE', $second['prompt']);
        $this->assertStringNotContainsString('BETA_SOURCE', $first['prompt']);
        $this->assertStringNotContainsString(fullname($s->beta), $first['prompt']);
        $subjects = $this->subjects($s);
        $this->assertSame('<p>Result 1</p>', $subjects[$s->alpha->id]->ai_result);
        $this->assertSame('<p>Result 2</p>', $subjects[$s->beta->id]->ai_result);
        $parent = $this->report($s);
        $this->assertSame(24, (int) $parent->token_usage);
        foreach ([$s->alpha, $s->beta] as $author) {
            $this->assertStringContainsString('<h3>' . s(fullname($author)) . '</h3>', $parent->ai_result);
            $this->assertStringContainsString($subjects[$author->id]->ai_result, $parent->ai_result);
        }
        if ($storeraw) {
            $this->assertStringNotContainsString('BETA_SOURCE', $subjects[$s->alpha->id]->source_data);
            $this->assertStringNotContainsString('ALPHA_SOURCE', $subjects[$s->beta->id]->source_data);
            $this->assertStringContainsString($subjects[$s->alpha->id]->source_data, $first['prompt']);
            $this->assertStringContainsString($subjects[$s->beta->id]->source_data, $second['prompt']);
        } else {
            $this->assertNull($parent->raw_data);
            foreach ($subjects as $subject) {
                $this->assertNull($subject->source_data);
            }
        }
        $this->assert_logs($s, true);
    }

    /**
     * Raw policy cases.
     *
     * @return array Test cases
     */
    public static function raw_policy_provider(): array {
        return ['raw copies' => [true], 'attribution without raw copies' => [false]];
    }

    /**
     * Purpose HTML is cleaned, names come from the database and are escaped, and code/math stay HTML.
     */
    private function check_html_cleaning_and_escaped_participant_heading(): void {
        global $DB;

        $s = $this->scenario('individual');
        $DB->set_field('user', 'firstname', '<em>Alpha & name</em>', ['id' => $s->alpha->id]);
        $s->provider->onrequest = static fn(): prompt_response => request_provider::success(
            '<script>window.taskProbe = 1;</script><p onclick="taskProbe()">Safe <strong>result</strong></p>' .
            '<pre><code>&lt;example&gt;</code></pre><p>\\(x^2 + y^2\\)</p>'
        );
        $s->task->execute();

        $report = $this->report($s);
        $this->assertSame('completed', $report->status);
        $this->assertSame((int) FORMAT_HTML, (int) $report->resultformat);
        $this->assertStringNotContainsString('<script', $report->ai_result);
        $this->assertStringNotContainsString('onclick', $report->ai_result);
        $this->assertStringContainsString('<strong>result</strong>', $report->ai_result);
        $this->assertStringContainsString('<code>&lt;example&gt;</code>', $report->ai_result);
        $this->assertStringContainsString('\\(x^2 + y^2\\)', $report->ai_result);
        $author = $DB->get_record('user', ['id' => $s->alpha->id], '*', MUST_EXIST);
        $this->assertStringContainsString('<h3>' . s(fullname($author)) . '</h3>', $report->ai_result);
        $this->assertStringNotContainsString('<h3><em>', $report->ai_result);
        foreach ($this->subjects($s) as $subject) {
            $this->assertStringNotContainsString('<script', $subject->ai_result);
            $this->assertStringNotContainsString('onclick', $subject->ai_result);
        }
    }

    /**
     * Whitespace and markup-only success bodies are failures, not completed reports or retries.
     *
     * @param string $content Empty purpose response
     */
    private function check_empty_response_is_not_success(string $content): void {
        $s = $this->scenario();
        set_config('retry_on_failure', 3, 'report_ai_analysis');
        $s->provider->onrequest = static fn(): prompt_response => request_provider::success($content);
        $s->task->execute();

        $this->assert_failed($s, 'error_empty_response');
        $this->assertCount(1, $s->provider->requests);
        $this->assert_logs($s, true);
    }

    /**
     * Empty bodies, including Unicode/HTML whitespace and content removed by cleaning.
     *
     * @return array Test cases
     */
    public static function empty_response_provider(): array {
        return [
            'empty' => [''],
            'whitespace' => [" \n\t "],
            'markup' => ['<p><br></p><!-- No result -->'],
            'entities' => ['<p>&nbsp;&#160;&#x200b;</p>'],
            'removed script' => ['<script>window.taskProbe = 1;</script>'],
        ];
    }

    /**
     * Only explicit HTTP-like transient codes retry; consent and other failures remain visible and terminal.
     *
     * @param int $code HTTP-like code
     * @param string $expected Expected local error key
     * @param bool $retry Whether one retry should be queued
     * @param string $kind Exact message variant
     */
    private function check_response_failure_classification(int $code, string $expected, bool $retry, string $kind): void {
        $s = $this->scenario();
        set_config('retry_on_failure', 2, 'report_ai_analysis');
        $message = match ($kind) {
            'terms' => get_string('error_http403notconfirmed', 'local_ai_manager'),
            'purpose' => get_string('error_purposenotconfigured', 'local_ai_manager',
                get_string('pluginname', 'aipurpose_singleprompt')),
            default => 'timeout connection rate limit temporarily unavailable: not a typed retry reason',
        };
        $s->provider->onrequest = static fn(): prompt_response => request_provider::failure($code, $message);
        $before = time();
        $s->task->execute();
        $after = time();

        $this->assertCount(1, $s->provider->requests);
        $report = $this->report($s);
        $this->assertSame($expected, $report->error_code);
        $this->assertSame(error_info::get_description($expected), $report->error_message);
        $this->assertStringContainsString('Private connector diagnostics', $report->error_details);
        $this->assertStringNotContainsString('Private connector diagnostics', $report->error_message);
        if ($retry) {
            $this->assert_retry($s, 1, $before, $after);
        } else {
            $this->assert_failed($s, $expected);
        }
    }

    /**
     * HTTP-like response classification cases.
     *
     * @return array Test cases
     */
    public static function response_failure_provider(): array {
        return [
            'terms' => [403, 'error_terms_not_accepted', false, 'terms'],
            'missing purpose' => [403, 'error_purposenotconfigured', false, 'purpose'],
            'different forbidden' => [403, 'error_ai_request', false, 'other'],
            'request timeout' => [408, 'error_api_timeout', true, 'other'],
            'gateway timeout' => [504, 'error_api_timeout', true, 'other'],
            'quota or rate limit' => [429, 'error_rate_limit', true, 'other'],
            'bad gateway' => [502, 'error_api_connection_error', true, 'other'],
            'unavailable' => [503, 'error_api_connection_error', true, 'other'],
            'bad request with timeout text' => [400, 'error_ai_request', false, 'other'],
            'unclassified server error' => [500, 'error_ai_request', false, 'other'],
            'not the success code' => [201, 'error_ai_request', false, 'other'],
        ];
    }

    /**
     * Retry counts respect both configuration and the hard maximum, with exponential delays.
     *
     * @param int $configured Configured retry count
     * @param int $attempt Current attempt counter
     * @param bool $retry Expected retry decision
     */
    private function check_retry_limits(int $configured, int $attempt, bool $retry): void {
        global $DB;

        $s = $this->scenario();
        set_config('retry_on_failure', $configured, 'report_ai_analysis');
        $DB->set_field('report_ai_analysis_reports', 'retry_count', $attempt, ['id' => $s->report->id]);
        $s->report->retry_count = $attempt;
        $s->task = $this->task($s->report, (int) $s->teacher->id);
        $s->provider->onrequest = static fn(): prompt_response => request_provider::failure(408);
        $before = time();
        $s->task->execute();
        $after = time();

        $this->assertCount(1, $s->provider->requests);
        if ($retry) {
            $this->assert_retry($s, $attempt + 1, $before, $after);
        } else {
            $this->assert_failed($s, 'error_api_timeout');
            $this->assertSame($attempt, (int) $this->report($s)->retry_count);
        }
        $this->assert_locks_released($s);
    }

    /**
     * Retry configuration boundaries and all three backoff steps.
     *
     * @return array Test cases
     */
    public static function retry_limit_provider(): array {
        return [
            'negative disables' => [-5, 0, false],
            'zero disables' => [0, 0, false],
            'first retry' => [1, 0, true],
            'configured one exhausted' => [1, 1, false],
            'second retry' => [2, 1, true],
            'third retry' => [3, 2, true],
            'oversized configuration clamps' => [999, 2, true],
            'hard limit exhausted' => [999, 3, false],
        ];
    }

    /**
     * A complete failure sequence makes at most four requests; duplicate old attempts cannot bypass backoff.
     */
    private function check_full_retry_sequence_and_duplicate_old_attempt(): void {
        $s = $this->scenario();
        set_config('retry_on_failure', 100, 'report_ai_analysis');
        $s->provider->onrequest = static fn(): prompt_response => request_provider::failure(503);
        $current = $s->task;
        for ($attempt = 0; $attempt <= 3; $attempt++) {
            $before = time();
            $current->execute();
            $this->assertCount($attempt + 1, $s->provider->requests);
            if ($attempt < 3) {
                $this->assert_retry($s, $attempt + 1, $before, time());
                $snapshot = (array) $this->report($s);
                $s->task->execute();
                $this->assertSame($snapshot, (array) $this->report($s));
                $this->assertCount($attempt + 1, $s->provider->requests);
                $current = $this->queued($s)[0];
                task_manager::delete_adhoc_task($current->get_id());
            }
        }
        $this->assert_failed($s, 'error_api_connection_error');
        $this->assertSame(3, (int) $this->report($s)->retry_count);
        foreach ($s->provider->requests as $request) {
            $this->assert_request_contract($s, $request);
        }
    }

    /**
     * Arbitrary exception text never enables retries; exact typed configuration failures retain their code.
     *
     * @param string $kind Exception variant
     * @param string $expected Expected localised code
     */
    private function check_exception_classification_does_not_parse_messages(string $kind, string $expected): void {
        $s = $this->scenario();
        set_config('retry_on_failure', 3, 'report_ai_analysis');
        $s->provider->onrequest = static function () use ($kind): prompt_response {
            throw match ($kind) {
                'purpose' => new \moodle_exception('error_noaitoolassignedforpurpose', 'local_ai_manager', '', 'singleprompt'),
                'terms' => new \moodle_exception('error_terms_not_accepted', 'report_ai_analysis'),
                'source' => new \moodle_exception('error_source_failed', 'report_ai_analysis', '', null, 'timeout connection'),
                'coding' => new \coding_exception('timeout connection rate limit too long'),
                default => new \RuntimeException('timeout connection rate limit temporarily unavailable too long'),
            };
        };
        $s->task->execute();

        $this->assert_failed($s, $expected);
        $this->assertCount(1, $s->provider->requests);
    }

    /**
     * Typed and untyped exception cases, defined without instantiating Moodle objects in the data provider.
     *
     * @return array Test cases
     */
    public static function exception_provider(): array {
        return [
            'untyped message' => ['runtime', 'error_unknown'],
            'coding exception' => ['coding', 'error_unknown'],
            'purpose unavailable' => ['purpose', 'error_purposenotconfigured'],
            'consent' => ['terms', 'error_terms_not_accepted'],
            'source timeout is not a connector timeout' => ['source', 'error_source_failed'],
        ];
    }

    /**
     * Backend preflight occurs before any provider content is collected, not just before the HTTP boundary.
     *
     * @param string $code Preflight failure code
     */
    private function check_unavailable_backend_stops_before_collection(string $code): void {
        $s = $this->scenario();
        $factory = $this->getMockBuilder(provider_factory::class)->onlyMethods(['get_all_providers'])->getMock();
        $factory->expects($this->never())->method('get_all_providers');
        di::set(provider_factory::class, $factory);
        $s->provider->onavailable = static function () use ($code): void {
            throw new \moodle_exception($code, 'report_ai_analysis');
        };
        $s->task->execute();

        $this->assert_failed($s, $code);
        $this->assertSame([(int) $s->context->id], $s->provider->availabilitychecks);
        $this->assertSame([], $s->provider->requests);
        $this->assertSame([], $s->provider->logids);
    }

    /**
     * AI configuration/consent preflight variants; permissions themselves are never mocked.
     *
     * @return array Test cases
     */
    public static function preflight_failure_provider(): array {
        return [
            'availability' => ['error_ai_request'],
            'purpose' => ['error_purposenotconfigured'],
            'consent' => ['error_terms_not_accepted'],
        ];
    }

    /**
     * Real actor/account/course/action checks apply both before execution and after an in-flight request.
     *
     * @param string $change Persisted authorisation change
     * @param bool $during Whether to revoke inside the request boundary
     */
    private function check_actor_revocation(string $change, bool $during): void {
        global $DB;

        $s = $this->scenario('individual');
        if ($change === 'rerun') {
            // Select the action before execution; changing it in flight would invalidate the generation itself.
            $DB->set_field('report_ai_analysis_reports', 'action', 'rerun', ['id' => $s->report->id]);
        }
        set_config('retry_on_failure', 3, 'report_ai_analysis');
        if ($during) {
            $s->provider->onrequest = function () use ($s, $change): prompt_response {
                $this->revoke_actor($s, $change);
                return request_provider::success('<p>Late result</p>');
            };
        } else {
            $this->revoke_actor($s, $change);
        }
        $s->task->execute();

        $this->assert_failed($s, 'error_access_revoked');
        $this->assertCount($during ? 1 : 0, $s->provider->requests);
        if (!$during) {
            $this->assertSame([], $s->provider->availabilitychecks);
        } else {
            $this->assert_logs($s, true);
        }
    }

    /**
     * Persisted account, enrolment, role and course changes on each side of transmission.
     *
     * @return array Test cases
     */
    public static function actor_revocation_provider(): array {
        $cases = [];
        foreach (['suspended', 'deleted', 'unconfirmed', 'enrolment', 'unenrolled', 'course', 'create', 'rerun'] as $change) {
            foreach ([false, true] as $during) {
                $cases[$change . ($during ? ' during request' : ' before execution')] = [$change, $during];
            }
        }
        return $cases;
    }

    /**
     * A cached role definition must not authorise a CLI worker after a database-side permission change.
     */
    private function check_stale_role_definition_cache_fails_closed(): void {
        global $DB;

        $s = $this->scenario();
        $this->assertTrue(has_capability('report/ai_analysis:create', $s->context));
        get_role_definitions([$s->teacherrole]);
        $DB->insert_record('role_capabilities', (object) [
            'contextid' => $s->context->id,
            'roleid' => $s->teacherrole,
            'capability' => 'report/ai_analysis:create',
            'permission' => CAP_PROHIBIT,
            'timemodified' => time(),
            'modifierid' => $s->teacher->id,
        ]);
        $s->task->execute();

        $this->assert_failed($s, 'error_access_revoked');
        $this->assertSame([], $s->provider->requests);
    }

    /**
     * Sharing/viewall cannot grant mutation, while manageall still requires the action capability.
     *
     * @param bool $manageall Whether management of other owners is granted
     * @param bool $action Whether the action capability remains granted
     */
    private function check_actual_requester_ownership_policy(bool $manageall, bool $action): void {
        global $DB;

        $s = $this->scenario();
        $owner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($owner->id, $s->course->id, 'editingteacher');
        $DB->set_field('report_ai_analysis_reports', 'userid', $owner->id, ['id' => $s->report->id]);
        set_config('share_reports_in_course', 1, 'report_ai_analysis');
        assign_capability('report/ai_analysis:viewall', CAP_ALLOW, $s->teacherrole, $s->context->id, true);
        assign_capability(
            'report/ai_analysis:manageall',
            $manageall ? CAP_ALLOW : CAP_PROHIBIT,
            $s->teacherrole,
            $s->context->id,
            true
        );
        if (!$action) {
            assign_capability('report/ai_analysis:create', CAP_PROHIBIT, $s->teacherrole, $s->context->id, true);
        }
        $s->task->execute();

        $this->assertSame((int) $owner->id, (int) $this->report($s)->userid);
        if ($manageall && $action) {
            $this->assertSame('completed', $this->report($s)->status);
            $this->assertCount(1, $s->provider->requests);
            $this->assertSame((int) $s->teacher->id, $s->provider->requests[0]['userid']);
        } else {
            $this->assert_failed($s, 'error_access_revoked');
            $this->assertSame([], $s->provider->requests);
        }
    }

    /**
     * Object/action policy cases.
     *
     * @return array Test cases
     */
    public static function ownership_provider(): array {
        return ['sharing only' => [false, true], 'manager actor' => [true, true], 'manager without action' => [true, false]];
    }

    /**
     * The persisted action, not a universal create check, controls execution.
     *
     * @param string $action Required action
     * @param string $unrelated Capability which may be absent
     */
    private function check_only_the_current_action_is_required(string $action, string $unrelated): void {
        global $DB;

        $s = $this->scenario();
        $DB->set_field('report_ai_analysis_reports', 'action', $action, ['id' => $s->report->id]);
        assign_capability('report/ai_analysis:' . $unrelated, CAP_PROHIBIT, $s->teacherrole, $s->context->id, true);
        $s->task->execute();
        $this->assertSame('completed', $this->report($s)->status);
        $this->assertCount(1, $s->provider->requests);
    }

    /**
     * Independent action capabilities.
     *
     * @return array Test cases
     */
    public static function action_provider(): array {
        return ['create without rerun' => ['create', 'rerun'], 'rerun without create' => ['rerun', 'create']];
    }

    /**
     * Missing or mismatched queue actors never inherit the current administrator's authority.
     *
     * @param string $kind Invalid actor variant
     */
    private function check_missing_or_mismatched_actor(string $kind): void {
        global $USER;

        $s = $this->scenario();
        switch ($kind) {
            case 'null':
                $s->task->set_userid(null);
                break;
            case 'zero':
                $s->task->set_userid(0);
                break;
            case 'negative':
                $s->task->set_userid(-1);
                break;
            case 'other queue actor':
                $s->task->set_userid($s->alpha->id);
                break;
            case 'administrator runner':
                $this->setAdminUser();
                break;
            case 'guest':
                $this->setGuestUser();
                $s->task->set_userid($USER->id);
                break;
        }
        $actor = (int) $USER->id;
        $s->task->execute();

        $this->assertSame($actor, (int) $USER->id, 'The worker must not impersonate the owner or an administrator');
        $this->assert_failed($s, 'error_access_revoked');
        $this->assertSame([], $s->provider->requests);
        $this->assertSame([], $s->provider->availabilitychecks);
    }

    /**
     * Invalid queue/runner identity combinations.
     *
     * @return array Test cases
     */
    public static function invalid_actor_provider(): array {
        $cases = ['null', 'zero', 'negative', 'other queue actor', 'administrator runner', 'guest'];
        return array_combine($cases, array_map(static fn(string $case): array => [$case], $cases));
    }

    /**
     * Legacy, invalid and obsolete task metadata causes no state or attribution writes.
     *
     * @param string $kind Obsolete work variant
     */
    private function check_obsolete_work_is_a_noop(string $kind): void {
        global $DB;

        $s = $this->scenario();
        $data = ['reportid' => (int) $s->report->id, 'runversion' => 1];
        switch ($kind) {
            case 'missing generation':
                unset($data['runversion']);
                break;
            case 'zero generation':
                $data['runversion'] = 0;
                break;
            case 'negative generation':
                $data['runversion'] = -1;
                break;
            case 'invalid generation':
                $data['runversion'] = '1 forged';
                break;
            case 'newer stored generation':
                $DB->set_field('report_ai_analysis_reports', 'runversion', 2, ['id' => $s->report->id]);
                break;
            case 'future queue generation':
                $data['runversion'] = 2;
                break;
            case 'old attempt':
                $DB->set_field('report_ai_analysis_reports', 'retry_count', 1, ['id' => $s->report->id]);
                break;
            case 'null data':
                $data = null;
                break;
            case 'array data':
                $data = [1, 2];
                break;
            default:
                $DB->set_field('report_ai_analysis_reports', 'status', $kind, ['id' => $s->report->id]);
        }
        $s->task->set_custom_data($data);
        $before = $this->snapshot($s);
        $s->task->execute();

        $this->assertEquals($before, $this->snapshot($s));
        $this->assertSame([], $s->provider->requests);
        $this->assertSame([], $s->provider->availabilitychecks);
        $this->assertSame([], $this->queued($s));
    }

    /**
     * Metadata/status cases which may never restart a report.
     *
     * @return array Test cases
     */
    public static function obsolete_provider(): array {
        $cases = ['missing generation', 'zero generation', 'negative generation', 'invalid generation',
            'newer stored generation', 'future queue generation', 'old attempt', 'null data', 'array data',
            'completed', 'failed', 'cancelled'];
        return array_combine($cases, array_map(static fn(string $case): array => [$case], $cases));
    }

    /**
     * An already deleted report cannot be recreated by its old task.
     */
    private function check_deleted_report_is_skipped(): void {
        global $DB;

        $s = $this->scenario();
        report_manager::delete((int) $s->report->id);
        $s->task->execute();
        $this->assertFalse($DB->record_exists('report_ai_analysis_reports', ['id' => $s->report->id]));
        $this->assertSame([], $s->provider->requests);
        $this->assertSame([], $this->subjects($s));
    }

    /**
     * The generation lock prevents duplicate processing; releasing it allows crashed running work to resume.
     */
    private function check_duplicate_generation_lock_and_crash_recovery(): void {
        global $DB;

        $s = $this->scenario();
        $DB->set_field('report_ai_analysis_reports', 'status', 'running', ['id' => $s->report->id]);
        $before = $this->snapshot($s);
        $lock = lock_config::get_lock_factory('report_ai_analysis')->get_lock('run:' . $s->report->id . ':1', 0);
        $this->assertNotFalse($lock);
        try {
            $s->task->execute();
            $this->assertEquals($before, $this->snapshot($s));
            $this->assertSame([], $s->provider->requests);
            $this->assertSame([], $s->provider->availabilitychecks);
        } finally {
            $lock->release();
        }
        $s->task->execute();
        $this->assertSame('completed', $this->report($s)->status);
        $this->assertCount(1, $s->provider->requests);
        $completed = $this->snapshot($s);
        $s->task->execute();
        $this->assertEquals($completed, $this->snapshot($s));
        $this->assertCount(1, $s->provider->requests);
        $this->assert_locks_released($s);
    }

    /**
     * Resuming clears old copies/results and old exact log IDs, without losing queued privacy subjects early.
     */
    private function check_resuming_clears_partial_attempt_data_and_logs(): void {
        global $DB;

        $s = $this->scenario('individual');
        $DB->update_record('report_ai_analysis_reports', (object) [
            'id' => $s->report->id, 'status' => 'running', 'raw_data' => 'OLD_RAW', 'ai_result' => 'OLD_RESULT',
            'ai_model_name' => 'old-model', 'token_usage' => 99, 'execution_time' => 999, 'timecompleted' => 123,
            'error_code' => 'error_unknown', 'error_message' => 'Old error', 'error_details' => 'Old details',
        ]);
        $DB->set_field('report_ai_analysis_users', 'source_data', 'OLD_SUBJECT_RAW', ['reportid' => $s->report->id]);
        $DB->set_field('report_ai_analysis_users', 'ai_result', 'OLD_SUBJECT_RESULT', ['reportid' => $s->report->id]);
        $oldlog = $this->analysis_log($s, 'OLD_LOG');
        $otherlog = $this->analysis_log($s, 'OTHER_REPORT', ['itemid' => $s->report->id + 100]);
        $s->task->execute();

        $this->assertSame('completed', $this->report($s)->status);
        foreach ($s->provider->requests as $request) {
            $this->assertNull($request['report']->ai_result);
            $this->assertNull($request['report']->error_code);
            $this->assertNull($request['report']->timecompleted);
            $this->assertSame(0, (int) $request['report']->execution_time);
            $this->assertStringNotContainsString('OLD_', json_encode($request, JSON_THROW_ON_ERROR));
        }
        $this->assertSame(
            data_wiper::ANONYMIZE_STRING,
            $DB->get_field('local_ai_manager_request_log', 'prompttext', ['id' => $oldlog->id], MUST_EXIST)
        );
        $this->assertSame(
            'OTHER_REPORT',
            $DB->get_field('local_ai_manager_request_log', 'prompttext', ['id' => $otherlog->id], MUST_EXIST)
        );
        $this->assert_logs($s, true);
    }

    /**
     * Persisted scope/context metadata is not permission to read a different course.
     *
     * @param string $kind Forged or malformed scope variant
     */
    private function check_forged_scope_is_rejected(string $kind): void {
        global $DB;

        $s = $this->scenario();
        $expected = 'error_source_forbidden';
        switch ($kind) {
            case 'other course':
                $other = $this->getDataGenerator()->create_course();
                $this->getDataGenerator()->enrol_user($s->teacher->id, $other->id, 'editingteacher');
                $json = (new scope_builder($other->id))->build();
                break;
            case 'system context':
                $DB->set_field(
                    'report_ai_analysis_reports',
                    'contextid',
                    \context_system::instance()->id,
                    ['id' => $s->report->id]
                );
                $json = $s->scope->build();
                $expected = 'error_access_revoked';
                break;
            case 'unknown action':
                $DB->set_field('report_ai_analysis_reports', 'action', 'delete', ['id' => $s->report->id]);
                $json = $s->scope->build();
                $expected = 'error_access_revoked';
                break;
            case 'invalid json':
                $json = '{broken';
                break;
            case 'array filters':
                $json = json_encode(['courseid' => (int) $s->course->id, 'filters' => []]);
                break;
            default:
                $json = '{"courseid":0,"filters":{}}';
        }
        $DB->set_field('report_ai_analysis_reports', 'scope_details', $json, ['id' => $s->report->id]);
        $s->task->execute();

        $this->assert_failed($s, $expected);
        $this->assertSame([], $s->provider->requests);
        $this->assertSame([], $s->provider->availabilitychecks);
    }

    /**
     * Invalid persisted scope cases.
     *
     * @return array Test cases
     */
    public static function forged_scope_provider(): array {
        $cases = ['other course', 'system context', 'unknown action', 'invalid json', 'array filters', 'zero course'];
        return array_combine($cases, array_map(static fn(string $case): array => [$case], $cases));
    }

    /**
     * An explicit empty participant intersection is not broadened to all authors.
     */
    private function check_empty_participant_selection_is_no_data(): void {
        $s = $this->scenario();
        $this->scope($s, (new scope_builder($s->course->id))->with_sources(['cm_' . $s->forum->cmid])
            ->filter_by_participants([]));
        $s->task->execute();
        $this->assert_failed($s, 'error_no_data');
        $this->assertSame([], $s->provider->requests);
    }

    /**
     * Only an explicitly selected reply author is represented; root content and metadata remain excluded.
     */
    private function check_selected_author_excludes_other_thread_metadata(): void {
        $s = $this->scenario();
        $this->scope($s, (new scope_builder($s->course->id))->with_sources(['cm_' . $s->forum->cmid])
            ->filter_by_participants([$s->beta->id]));
        $s->task->execute();
        $this->assertSame('completed', $this->report($s)->status);
        $this->assertCount(1, $s->provider->requests);
        $prompt = $s->provider->requests[0]['prompt'];
        $this->assertStringContainsString('BETA_SOURCE', $prompt);
        foreach (['ALPHA_SOURCE', 'ALPHA_ROOT_TITLE', fullname($s->alpha)] as $excluded) {
            $this->assertStringNotContainsString($excluded, $prompt);
        }
        $this->assertSame([(int) $s->beta->id], array_keys($this->subjects($s)));
    }

    /**
     * Actual private-reply permissions exclude hidden content and attribution before any AI request.
     */
    private function check_private_reply_is_never_sent_without_source_permission(): void {
        global $DB;

        $s = $this->scenario('individual');
        $DB->set_field('forum_posts', 'privatereplyto', $s->alpha->id, ['id' => $s->reply->id]);
        assign_capability(
            'mod/forum:readprivatereplies',
            CAP_PROHIBIT,
            $s->teacherrole,
            context_module::instance($s->forum->cmid)->id,
            true
        );
        $s->task->execute();

        $this->assertSame('completed', $this->report($s)->status);
        $this->assertCount(1, $s->provider->requests);
        $this->assertStringContainsString('ALPHA_SOURCE', $s->provider->requests[0]['prompt']);
        $this->assertStringNotContainsString('BETA_SOURCE', $s->provider->requests[0]['prompt']);
        $this->assertSame([(int) $s->alpha->id], array_keys($this->subjects($s)));
    }

    /**
     * Revoked explicit forum access fails before the request, using real module permissions.
     */
    private function check_forum_access_revoked_before_execution(): void {
        $s = $this->scenario();
        assign_capability(
            'mod/forum:viewdiscussion',
            CAP_PROHIBIT,
            $s->teacherrole,
            context_module::instance($s->forum->cmid)->id,
            true
        );
        $s->task->execute();
        $this->assert_failed($s, 'error_source_forbidden');
        $this->assertSame([], $s->provider->requests);
    }

    /**
     * A changed actual forum snapshot invalidates earlier individual responses, even for automatic sources.
     *
     * @param string $change Source mutation during the second response
     * @param bool $automatic Whether sources were automatically discovered
     */
    private function check_forum_snapshot_withdrawal_discards_all_results(string $change, bool $automatic): void {
        global $DB;

        $s = $this->scenario('individual');
        $this->post($s, $s->silent, 'THIRD_AUTHOR_SOURCE');
        if ($automatic) {
            $this->scope($s, (new scope_builder($s->course->id))->set_analysis_mode('individual'));
        }
        set_config('retry_on_failure', 3, 'report_ai_analysis');
        $s->provider->onrequest = function (array $request, int $number) use ($s, $change, $DB): prompt_response {
            if ($number === 2) {
                $modulecontext = context_module::instance($s->forum->cmid);
                switch ($change) {
                    case 'forum permission':
                        assign_capability('mod/forum:viewdiscussion', CAP_PROHIBIT, $s->teacherrole, $modulecontext->id, true);
                        break;
                    case 'hidden module':
                        assign_capability(
                            'moodle/course:viewhiddenactivities',
                            CAP_PROHIBIT,
                            $s->teacherrole,
                            $modulecontext->id,
                            true
                        );
                        set_coursemodule_visible($s->forum->cmid, 0);
                        break;
                    case 'private old post':
                        $DB->set_field('forum_posts', 'privatereplyto', $s->beta->id, ['id' => $s->discussion->firstpost]);
                        assign_capability('mod/forum:readprivatereplies', CAP_PROHIBIT, $s->teacherrole, $modulecontext->id, true);
                        break;
                    case 'deleted old post':
                        $DB->set_field('forum_posts', 'deleted', 1, ['id' => $s->discussion->firstpost]);
                        break;
                    case 'edited old post':
                        $DB->set_field('forum_posts', 'message', 'REDACTED_OLD_POST', ['id' => $s->discussion->firstpost]);
                        break;
                    case 'suspended author':
                        $DB->set_field('user', 'suspended', 1, ['id' => $s->alpha->id]);
                        break;
                    case 'author enrolment':
                        $this->suspend_enrolment($s, (int) $s->alpha->id);
                        break;
                    case 'moved discussion':
                        $other = $this->getDataGenerator()->create_module('forum', ['course' => $s->course->id]);
                        $DB->set_field('forum_discussions', 'forum', $other->id, ['id' => $s->discussion->id]);
                        break;
                }
            }
            return request_provider::success('<p>Response ' . $number . '</p>');
        };
        $s->task->execute();

        $this->assert_failed($s, 'error_source_forbidden');
        $this->assertCount(2, $s->provider->requests, 'Withdrawal of an already analysed author must stop the third request');
        $this->assert_logs($s, true);
        $this->assert_locks_released($s);
    }

    /**
     * Automatic and explicit forum scopes must revalidate the same actual posts/authors.
     *
     * @return array Test cases
     */
    public static function forum_withdrawal_provider(): array {
        $cases = [];
        foreach (
            ['forum permission', 'hidden module', 'private old post', 'deleted old post', 'edited old post',
            'suspended author', 'author enrolment', 'moved discussion'] as $change
        ) {
            foreach ([false, true] as $automatic) {
                $cases[$change . ($automatic ? ' automatic' : ' explicit')] = [$change, $automatic];
            }
        }
        return $cases;
    }

    /**
     * Foreign chat access is a real source capability, not implied by the report action or tenant capability.
     */
    private function check_foreign_chat_permission_denial_before_execution(): void {
        $s = $this->scenario();
        $this->chat($s, $s->alpha, 'FORBIDDEN_CHAT');
        $this->scope($s, (new scope_builder($s->course->id))->with_sources(['block_' . $s->chatcontext->id])
            ->filter_by_participants([$s->alpha->id]));
        assign_capability('local/ai_manager:viewprompts', CAP_PROHIBIT, $s->teacherrole, $s->chatcontext->id, true);
        assign_capability('local/ai_manager:viewtenantprompts', CAP_ALLOW, $s->teacherrole, \context_system::instance()->id, true);
        $s->task->execute();

        $this->assert_failed($s, 'error_source_forbidden');
        $this->assertSame([], $s->provider->requests);
    }

    /**
     * Allowed chat date filtering does not disclose dates when the foreign-date capability is absent.
     */
    private function check_chat_date_privacy_and_real_log_filtering(): void {
        $s = $this->scenario();
        $this->chat($s, $s->alpha, 'OLD_CHAT', ['timecreated' => 1000]);
        $this->chat($s, $s->alpha, 'INCLUDED_CHAT', ['timecreated' => 2000]);
        $this->chat($s, $s->alpha, 'DELETED_CHAT', ['timecreated' => 2000, 'deleted' => 1]);
        $this->chat($s, $s->alpha, 'NON_CHAT_PURPOSE', ['timecreated' => 2000, 'purpose' => 'singleprompt']);
        $this->scope($s, (new scope_builder($s->course->id))->with_sources(['block_' . $s->chatcontext->id])
            ->filter_by_participants([$s->alpha->id])->with_timerange(2000, 0));
        assign_capability('local/ai_manager:viewpromptsdates', CAP_PROHIBIT, $s->teacherrole, $s->chatcontext->id, true);
        $s->task->execute();

        $this->assertSame('completed', $this->report($s)->status);
        $this->assertCount(1, $s->provider->requests);
        $prompt = $s->provider->requests[0]['prompt'];
        $this->assertStringContainsString('INCLUDED_CHAT', $prompt);
        foreach (['OLD_CHAT', 'DELETED_CHAT', 'NON_CHAT_PURPOSE', 'SECRET_HISTORY', '1970-01-01'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $prompt);
        }
        $this->assertStringNotContainsString(get_string('export_created', 'report_ai_analysis') . ':', $prompt);
    }

    /**
     * Actual chat blocks, prompt/date permissions and request entries are revalidated after transmission.
     *
     * @param string $change Chat mutation
     * @param bool $automatic Whether sources were discovered automatically
     */
    private function check_chat_snapshot_withdrawal(string $change, bool $automatic): void {
        global $DB;

        $s = $this->scenario();
        $entry = $this->chat($s, $s->alpha, 'CHAT_SNAPSHOT');
        $scope = (new scope_builder($s->course->id))->filter_by_participants([$s->alpha->id]);
        if (!$automatic) {
            $scope->with_sources(['block_' . $s->chatcontext->id]);
        }
        $this->scope($s, $scope);
        $s->provider->onrequest = static function () use ($s, $entry, $change, $DB): prompt_response {
            if (str_contains($change, ':')) {
                assign_capability($change, CAP_PROHIBIT, $s->teacherrole, $s->chatcontext->id, true);
            } else if ($change === 'delete request') {
                $DB->set_field('local_ai_manager_request_log', 'deleted', 1, ['id' => $entry->id]);
            } else if ($change === 'edit request') {
                $DB->set_field('local_ai_manager_request_log', 'prompttext', 'REDACTED_CHAT', ['id' => $entry->id]);
            } else {
                $DB->insert_record('block_positions', (object) [
                    'blockinstanceid' => $s->chatcontext->instanceid, 'contextid' => $s->context->id,
                    'pagetype' => 'course-view-topics', 'subpage' => '', 'visible' => 0, 'region' => 'side-pre', 'weight' => 0,
                ]);
            }
            return request_provider::success('<p>Late chat analysis</p>');
        };
        $s->task->execute();

        $this->assert_failed($s, 'error_source_forbidden');
        $this->assertCount(1, $s->provider->requests);
        $this->assert_logs($s, true);
    }

    /**
     * Changes to explicit and automatic chat sources.
     *
     * @return array Test cases
     */
    public static function chat_withdrawal_provider(): array {
        $cases = [];
        foreach (
            ['local/ai_manager:viewprompts', 'local/ai_manager:viewpromptsdates', 'block/ai_chat:view',
            'moodle/block:view', 'delete request', 'edit request', 'hidden position'] as $change
        ) {
            foreach ([false, true] as $automatic) {
                $cases[$change . ($automatic ? ' automatic' : ' explicit')] = [$change, $automatic];
            }
        }
        return $cases;
    }

    /**
     * A selected source failure is never an apparently complete report based on another source.
     */
    private function check_source_failure_is_not_a_partial_success_or_retry(): void {
        $s = $this->scenario();
        $this->chat($s, $s->alpha, 'CHAT_SOURCE');
        $this->scope($s, (new scope_builder($s->course->id))
            ->with_sources(['cm_' . $s->forum->cmid, 'block_' . $s->chatcontext->id]));
        set_config('retry_on_failure', 3, 'report_ai_analysis');
        $reader = $this->getMockBuilder(ai_log_provider::class)->onlyMethods(['get_log_entries'])->getMock();
        $reader->method('get_log_entries')->willThrowException(new \RuntimeException('timeout from a selected source'));
        di::set(ai_log_provider::class, $reader);
        // Discover both real providers; place the succeeding forum first to exercise partial collection explicitly.
        $factory = $this->getMockBuilder(provider_factory::class)->onlyMethods(['discover_providers'])->getMock();
        $factory->method('discover_providers')->willReturn([
            \report_ai_analysis\local\provider\mod_forum_provider::class,
            \report_ai_analysis\local\provider\block_ai_chat_provider::class,
        ]);
        di::set(provider_factory::class, $factory);
        $s->task->execute();

        $this->assert_failed($s, 'error_source_failed');
        $this->assertSame([], $s->provider->requests);
        $this->assertStringContainsString('selected source', $this->report($s)->error_details);
    }

    /**
     * A forum post and a chat request/response pair share one global record budget, without a user multiplier.
     */
    private function check_global_record_budget_across_real_sources(): void {
        $s = $this->scenario();
        $this->chat($s, $s->beta, 'CHAT_BUDGET_ENTRY', ['promptcompletion' => 'CHAT_BUDGET_COMPLETION']);
        $this->scope($s, (new scope_builder($s->course->id))
            ->with_sources(['cm_' . $s->forum->cmid, 'block_' . $s->chatcontext->id]));
        set_config('max_records_per_analysis', 2, 'report_ai_analysis');
        $s->task->execute();

        $this->assertSame('completed', $this->report($s)->status);
        $this->assertSame(1, (int) $this->report($s)->truncated);
        $this->assertCount(1, $s->provider->requests);
        $prompt = $s->provider->requests[0]['prompt'];
        foreach (['ALPHA_SOURCE', 'CHAT_BUDGET_ENTRY', 'CHAT_BUDGET_COMPLETION'] as $included) {
            $this->assertSame(1, substr_count($prompt, $included));
        }
        $this->assertStringNotContainsString('BETA_SOURCE', $prompt);
        $this->assertSame([(int) $s->alpha->id, (int) $s->beta->id], array_keys($this->subjects($s)));
    }

    /**
     * Cancellation, deletion and complete replacement generations are not overwritten by a late old response.
     *
     * @param string $operation Real manager mutation
     * @param string $mode Analysis mode
     * @param int $code Late old response code
     */
    private function check_late_response_preserves_current_lifecycle(string $operation, string $mode, int $code): void {
        global $DB;

        $s = $this->scenario($mode);
        set_config('retry_on_failure', 3, 'report_ai_analysis');
        $expected = null;
        $replacement = null;
        $s->provider->onrequest = function () use ($s, $operation, $code, &$expected, &$replacement): prompt_response {
            $id = (int) $s->report->id;
            if ($operation === 'delete') {
                report_manager::delete($id);
            } else {
                report_manager::cancel($id);
                if ($operation !== 'cancel') {
                    report_manager::rerun($id);
                    if ($operation === 'replacement completes') {
                        $replacement = new request_provider();
                        $replacement->onrequest = static fn(): prompt_response =>
                            request_provider::success('<p>NEW GENERATION</p>');
                        di::set(ai_request_provider::class, $replacement);
                        $newtask = $this->queued($s)[0];
                        try {
                            $newtask->execute();
                            $this->fail('Replacement must wait for the old request to capture its log IDs');
                        } catch (\moodle_exception $exception) {
                            $this->assertSame('locktimeout', $exception->errorcode);
                        }
                        $this->assertSame([], $replacement->requests);
                        di::set(ai_request_provider::class, $s->provider);
                    }
                }
            }
            $expected = $this->snapshot($s);
            return $code === 200 ? request_provider::success('<p>OBSOLETE RESPONSE</p>') : request_provider::failure($code);
        };
        $s->task->execute();

        $this->assertNotNull($expected);
        $this->assertEquals($expected, $this->snapshot($s));
        $this->assertCount(1, $s->provider->requests);
        if ($operation === 'delete') {
            $this->assertFalse($DB->record_exists('report_ai_analysis_reports', ['id' => $s->report->id]));
        } else {
            $this->assertGreaterThan(1, (int) $this->report($s)->runversion);
            $this->assertStringNotContainsString('OBSOLETE RESPONSE', (string) $this->report($s)->ai_result);
        }
        if ($code === 200) {
            $this->assert_logs($s, true);
        }
        if ($replacement) {
            di::set(ai_request_provider::class, $replacement);
            $newtask = $this->queued($s)[0];
            task_manager::delete_adhoc_task($newtask->get_id());
            $newtask->execute();
            di::set(ai_request_provider::class, $s->provider);
            $this->assertSame('completed', $this->report($s)->status);
            $this->assertStringContainsString('NEW GENERATION', $this->report($s)->ai_result);
            $this->assertNotEmpty($replacement->logids);
            foreach ($replacement->logids as $id) {
                $log = $DB->get_record('local_ai_manager_request_log', ['id' => $id], '*', MUST_EXIST);
                $this->assertNull($log->userid);
                $this->assertSame(data_wiper::ANONYMIZE_STRING, $log->promptcompletion);
                $this->assertSame(12, (int) $log->value);
            }
            $this->assertGreaterThan(max($s->provider->logids), min($replacement->logids));
            $completed = $this->snapshot($s);
            $s->task->execute();
            $this->assertEquals($completed, $this->snapshot($s));
        }
        $this->assert_locks_released($s);
    }

    /**
     * Real lifecycle mutations for successful and failed late responses in both modes.
     *
     * @return array Test cases
     */
    public static function lifecycle_provider(): array {
        $cases = [];
        foreach (['aggregated', 'individual'] as $mode) {
            foreach (['cancel', 'delete', 'rerun', 'replacement completes'] as $operation) {
                foreach ([200, 503] as $code) {
                    if ($operation === 'replacement completes' && $code !== 200) {
                        continue;
                    }
                    $cases["$mode $operation $code"] = [$operation, $mode, $code];
                }
            }
        }
        return $cases;
    }

    /**
     * A later individual failure erases all attempt copies/results and all known success logs before retry.
     */
    private function check_partial_individual_failure_and_clean_retry(): void {
        $s = $this->scenario('individual');
        set_config('retry_on_failure', 1, 'report_ai_analysis');
        $s->provider->onrequest = static fn(array $request, int $number): prompt_response =>
            $number === 1 ? request_provider::success('<p>PARTIAL RESULT</p>') : request_provider::failure(503);
        $before = time();
        $s->task->execute();

        $this->assert_retry($s, 1, $before, time());
        $this->assert_copies_cleared($s);
        $this->assertCount(2, $s->provider->requests);
        $this->assert_logs($s, true);
        $next = $this->queued($s)[0];
        task_manager::delete_adhoc_task($next->get_id());
        $fresh = new request_provider();
        di::set(ai_request_provider::class, $fresh);
        $next->execute();

        $this->assertSame('completed', $this->report($s)->status);
        $this->assertCount(2, $fresh->requests);
        $this->assertSame(1, (int) $this->report($s)->runversion);
        $this->assertSame(1, (int) $this->report($s)->retry_count);
        $this->assertStringNotContainsString('PARTIAL RESULT', $this->report($s)->ai_result);
        $this->assertNull($this->report($s)->error_code);
        foreach ($fresh->requests as $request) {
            $this->assertNull($request['report']->ai_result);
            foreach ($request['subjects'] as $subject) {
                $this->assertNull($subject->ai_result);
            }
        }
    }

    /**
     * Unicode final bounds include all instructions; source mappings contain only text actually included.
     *
     * @param string $mode Analysis mode
     */
    private function check_complete_unicode_prompt_bound(string $mode): void {
        global $DB;

        $s = $this->scenario($mode);
        $DB->set_field('forum_posts', 'message', str_repeat('甲', 2000), ['id' => $s->discussion->firstpost]);
        $DB->set_field('forum_posts', 'message', str_repeat('乙', 2000), ['id' => $s->reply->id]);
        $this->post($s, $s->silent, str_repeat('丙', 2000));
        $system = str_repeat('界', 1000000 - 1000 - core_text::strlen($s->report->prompt) - 4);
        set_config('system_prompt', $system, 'report_ai_analysis');
        $s->task->execute();

        $this->assertSame('completed', $this->report($s)->status);
        $this->assertSame(1, (int) $this->report($s)->truncated);
        $this->assertCount($mode === 'individual' ? 3 : 1, $s->provider->requests);
        foreach ($s->provider->requests as $request) {
            $this->assertSame(1000000, core_text::strlen($request['prompt']));
            $this->assertGreaterThan(1000000, strlen($request['prompt']));
            $this->assertStringStartsWith($system . "\n\n" . $s->report->prompt . "\n\n", $request['prompt']);
            $this->assertStringEndsWith("\n" . get_string('export_truncated', 'report_ai_analysis'), $request['prompt']);
            $this->assertNotFalse(json_encode($request['prompt'], JSON_THROW_ON_ERROR));
        }
        $subjects = $this->subjects($s);
        $this->assertCount($mode === 'individual' ? 3 : 1, $subjects);
        $texts = array_column($s->provider->requests, 'prompt');
        foreach ($subjects as $subject) {
            $this->assertNotNull($subject->source_data);
            $this->assertTrue((bool) array_filter(
                $texts,
                static fn(string $prompt): bool => str_contains($prompt, $subject->source_data)
            ));
        }
    }

    /**
     * Both supported modes.
     *
     * @return array Test cases
     */
    public static function mode_provider(): array {
        return ['aggregated' => ['aggregated'], 'individual' => ['individual']];
    }

    /**
     * A prefix which leaves no safe room for source text fails before collection or transmission.
     */
    private function check_oversized_instruction_prefix_fails_without_transmission(): void {
        $s = $this->scenario();
        set_config('system_prompt', str_repeat('界', 1000000), 'report_ai_analysis');
        $s->task->execute();
        $this->assert_failed($s, 'error_prompt_too_long');
        $this->assertSame([], $s->provider->requests);
    }

    /**
     * The configured raw budget covers the parent and every participant, not a fresh allowance per row.
     *
     * @param string $mode Analysis mode
     */
    private function check_small_raw_budget_is_shared_by_all_copies(string $mode): void {
        $s = $this->scenario($mode);
        set_config('truncate_raw_data_length', 120, 'report_ai_analysis');
        $s->task->execute();

        $this->assertSame('completed', $this->report($s)->status);
        $length = core_text::strlen((string) $this->report($s)->raw_data);
        foreach ($this->subjects($s) as $subject) {
            $length += core_text::strlen((string) $subject->source_data);
        }
        $this->assertSame(120, $length);
        $this->assertSame(0, (int) $this->report($s)->truncated, 'Shortening stored copies does not shorten requests');
        $this->assertSame([(int) $s->alpha->id, (int) $s->beta->id], array_keys($this->subjects($s)));
    }

    /**
     * Even invalid/oversized raw settings cannot duplicate more than 500000 characters across all rows.
     *
     * @param int $configured Raw setting requiring the hard cap
     */
    private function check_raw_hard_limit_is_global(int $configured): void {
        $s = $this->scenario();
        for ($index = 0; $index < 160; $index++) {
            $this->post($s, $index % 2 ? $s->alpha : $s->beta, str_repeat('界', 2000));
        }
        set_config('truncate_raw_data_length', $configured, 'report_ai_analysis');
        $s->task->execute();

        $report = $this->report($s);
        $this->assertSame('completed', $report->status);
        $length = core_text::strlen((string) $report->raw_data);
        $this->assertLessThanOrEqual(500000, $length);
        foreach ($this->subjects($s) as $subject) {
            $this->assertLessThanOrEqual(500000, core_text::strlen((string) $subject->source_data));
            $length += core_text::strlen((string) $subject->source_data);
            if ($subject->source_data !== null) {
                $this->assertStringContainsString($subject->source_data, $s->provider->requests[0]['prompt']);
            }
        }
        $this->assertSame(500000, $length);
        $this->assertNotFalse(json_encode($report->raw_data, JSON_THROW_ON_ERROR));
        $this->assertGreaterThan(250000, core_text::strlen($s->provider->requests[0]['prompt']));
    }

    /**
     * Raw settings which must use the global hard maximum.
     *
     * @return array Test cases
     */
    public static function raw_hard_limit_provider(): array {
        return ['unset-like zero' => [0], 'oversized' => [9000000]];
    }

    /**
     * All success metadata is integer/Unicode-safe before driver coercion, including on PostgreSQL.
     */
    private function check_success_write_uses_integer_metadata_and_bounded_unicode_model(): void {
        global $DB;

        $s = $this->scenario('individual');
        $model = str_repeat('模', 130);
        $s->provider->onrequest = static fn(): prompt_response => request_provider::success('<p>Result</p>', $model, 12.75);
        $database = $DB;
        $double = $this->createMock(moodle_database::class);
        $fault = new database_fault($database, $double, (int) $s->report->id, 'observe');
        $DB = $double;
        try {
            $s->task->execute();
        } finally {
            $DB = $database;
        }
        $this->assertSame('completed', $this->report($s)->status);
        $writes = array_values(array_filter(
            $fault->updates,
            static fn(stdClass $row): bool => ($row->status ?? '') === 'completed'
        ));
        $this->assertCount(1, $writes);
        $this->assertIsInt($writes[0]->execution_time);
        $this->assertIsInt($writes[0]->token_usage);
        $this->assertSame(24, $writes[0]->token_usage);
        $this->assertSame(str_repeat('模', 100), $writes[0]->ai_model_name);
        $this->assertFalse($DB->is_transaction_started());
        foreach ($s->provider->requests as $request) {
            $this->assertFalse($request['transaction']);
        }
    }

    /**
     * Failed completion/queue writes roll back and persist a separate minimal failure, without success residue.
     *
     * @param string $operation Failure point
     */
    private function check_database_failure_rolls_back_and_stores_minimal_error(string $operation): void {
        global $DB;

        $s = $this->scenario('individual');
        if ($operation === 'queue') {
            set_config('retry_on_failure', 3, 'report_ai_analysis');
            $s->provider->onrequest = static fn(array $request, int $number): prompt_response =>
                $number === 1 ? request_provider::success('<p>First result</p>') : request_provider::failure(503);
        }
        $database = $DB;
        $double = $this->createMock(moodle_database::class);
        $fault = new database_fault($database, $double, (int) $s->report->id, $operation);
        $DB = $double;
        try {
            $s->task->execute();
        } finally {
            $DB = $database;
        }

        $this->assertTrue($fault->triggered, 'The real worker must reach the injected persistence failure');
        $this->assert_failed($s, 'error_unknown');
        $this->assertSame(0, (int) $this->report($s)->retry_count, 'A failed queue insert must roll back the retry increment');
        $this->assertCount(2, $s->provider->requests);
        $this->assert_logs($s, true);
        $failed = array_values(array_filter($fault->updates, static fn(stdClass $row): bool => ($row->status ?? '') === 'failed'));
        $this->assertCount(1, $failed);
        $this->assertEqualsCanonicalizing(['id', 'status', 'error_code', 'error_message', 'error_details', 'timemodified',
            'timecompleted'], array_keys((array) $failed[0]));
        $this->assertFalse($DB->is_transaction_started());
        $this->assert_locks_released($s);
    }

    /**
     * Portable fault-injection points.
     *
     * @return array Test cases
     */
    public static function database_fault_provider(): array {
        return ['completion' => ['complete'], 'disposed transaction handle' => ['disposed'], 'retry queue' => ['queue']];
    }

    /**
     * The upstream factory's uninitialised optional log ID does not convert otherwise valid responses into failures.
     */
    private function check_response_factory_without_log_id_is_supported(): void {
        $s = $this->scenario();
        $s->provider->createlogs = false;
        $s->provider->onrequest = static fn(): prompt_response =>
            prompt_response::create_from_result('upstream-factory', new usage(1), '<p>Factory response</p>');
        $s->task->execute();
        $this->assertSame('completed', $this->report($s)->status);
        $this->assertSame('<p>Factory response</p>', $this->report($s)->ai_result);
        $this->assertSame([], $s->provider->logids);
    }

    /**
     * A task produced by the real save API needs no custom actor/generation fallback in the worker.
     */
    private function check_task_from_manager_save_contract(): void {
        global $DB;

        $s = $this->scenario();
        $original = $this->report($s);
        $id = report_manager::save((object) [
            'title' => 'Manager-created task', 'prompt' => 'Analyse the selected participant contributions',
        ], $s->scope);
        $s->report = $DB->get_record('report_ai_analysis_reports', ['id' => $id], '*', MUST_EXIST);
        $tasks = $this->queued($s);
        $this->assertCount(1, $tasks);
        $this->assertSame((int) $s->teacher->id, (int) $tasks[0]->get_userid());
        $this->assertSame((int) $s->report->runversion, $tasks[0]->get_custom_data()->runversion);
        task_manager::delete_adhoc_task($tasks[0]->get_id());
        $tasks[0]->execute();

        $this->assertSame('completed', $this->report($s)->status);
        $this->assertCount(1, $s->provider->requests);
        $this->assert_request_contract($s, $s->provider->requests[0]);
        $this->assertEquals($original, $DB->get_record('report_ai_analysis_reports', ['id' => $original->id], '*', MUST_EXIST));
    }

    /**
     * Explicit typed connector exceptions can retry without a response body or any text heuristics.
     *
     * @param string $code Typed transient failure
     */
    private function check_typed_connector_exception_can_retry(string $code): void {
        $s = $this->scenario();
        set_config('retry_on_failure', 1, 'report_ai_analysis');
        $s->provider->onrequest = static function () use ($code): prompt_response {
            throw new \moodle_exception($code, 'report_ai_analysis');
        };
        $before = time();
        $s->task->execute();
        $this->assert_retry($s, 1, $before, time());
        $this->assertSame($code, $this->report($s)->error_code);
    }

    /**
     * The explicit retry allowlist.
     *
     * @return array Test cases
     */
    public static function typed_retry_provider(): array {
        return [
            'timeout' => ['error_api_timeout'],
            'connection' => ['error_api_connection_error'],
            'rate' => ['error_rate_limit'],
        ];
    }

    /**
     * A transient exception after actor withdrawal is terminal and wipes prior successful response logs.
     */
    private function check_transient_exception_after_withdrawal_does_not_retry(): void {
        $s = $this->scenario('individual');
        set_config('retry_on_failure', 3, 'report_ai_analysis');
        $s->provider->onrequest = function (array $request, int $number) use ($s): prompt_response {
            if ($number === 2) {
                $this->suspend_enrolment($s, (int) $s->teacher->id);
                throw new \moodle_exception('error_api_timeout', 'report_ai_analysis');
            }
            return request_provider::success('<p>First result</p>');
        };
        $s->task->execute();

        $this->assert_failed($s, 'error_access_revoked');
        $this->assertCount(2, $s->provider->requests);
        $this->assertCount(1, $s->provider->logids);
        $this->assert_logs($s, true);
    }

    /**
     * AI preflight is repeated for each author; consent withdrawal stops the next transmission.
     */
    private function check_consent_withdrawal_before_next_individual_request(): void {
        $s = $this->scenario('individual');
        set_config('retry_on_failure', 3, 'report_ai_analysis');
        $s->provider->onavailable = static function (int $contextid, int $number): void {
            if ($number === 3) {
                throw new \moodle_exception('error_terms_not_accepted', 'report_ai_analysis');
            }
        };
        $s->task->execute();

        $this->assert_failed($s, 'error_terms_not_accepted');
        $this->assertCount(1, $s->provider->requests);
        $this->assertCount(3, $s->provider->availabilitychecks);
        $this->assert_logs($s, true);
    }

    /**
     * Preflight may itself observe cancellation; the worker checks again before reading any source content.
     */
    private function check_cancellation_during_preflight_stops_collection(): void {
        $s = $this->scenario();
        $factory = $this->getMockBuilder(provider_factory::class)->onlyMethods(['get_all_providers'])->getMock();
        $factory->expects($this->never())->method('get_all_providers');
        di::set(provider_factory::class, $factory);
        $s->provider->onavailable = static function () use ($s): void {
            report_manager::cancel((int) $s->report->id);
        };
        $s->task->execute();

        $this->assertSame('cancelled', $this->report($s)->status);
        $this->assertSame(2, (int) $this->report($s)->runversion);
        $this->assertSame([], $s->provider->requests);
        $this->assert_locks_released($s);
    }

    /**
     * Aggregation still records actual subjects with raw storage disabled; only source copies become null.
     */
    private function check_aggregated_raw_disabled_retains_attribution(): void {
        $s = $this->scenario();
        set_config('store_raw_data', 0, 'report_ai_analysis');
        $s->task->execute();

        $this->assertSame('completed', $this->report($s)->status);
        $this->assertNull($this->report($s)->raw_data);
        $this->assertSame([(int) $s->alpha->id, (int) $s->beta->id], array_keys($this->subjects($s)));
        foreach ($s->provider->requests[0]['subjects'] as $subject) {
            $this->assertNull($subject->source_data);
            $this->assertNull($subject->ai_result);
        }
        $this->assertStringContainsString('ALPHA_SOURCE', $s->provider->requests[0]['prompt']);
        $this->assertStringContainsString('BETA_SOURCE', $s->provider->requests[0]['prompt']);
        $this->assert_logs($s, true);
    }

    /**
     * Real separate groups restrict author selection and are rechecked when membership is withdrawn.
     *
     * @param bool $withdraw Whether to remove the included author during the request
     */
    private function check_group_membership_is_a_source_boundary(bool $withdraw): void {
        global $DB;

        $s = $this->scenario('individual');
        $DB->set_field('course', 'groupmode', SEPARATEGROUPS, ['id' => $s->course->id]);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $s->forum->cmid]);
        rebuild_course_cache($s->course->id, true);
        $group = $this->getDataGenerator()->create_group(['courseid' => $s->course->id]);
        $other = $this->getDataGenerator()->create_group(['courseid' => $s->course->id]);
        $DB->set_field('forum_discussions', 'groupid', $group->id, ['id' => $s->discussion->id]);
        groups_add_member($group, $s->teacher);
        groups_add_member($group, $s->alpha);
        groups_add_member($other, $s->beta);
        assign_capability('moodle/site:accessallgroups', CAP_PROHIBIT, $s->teacherrole, $s->context->id, true);
        $cm = get_fast_modinfo($s->course->id)->get_cm($s->forum->cmid);
        $this->assertSame(SEPARATEGROUPS, (int) $cm->effectivegroupmode);
        $this->assertFalse(has_capability('moodle/site:accessallgroups', context_module::instance($cm->id)));
        if ($withdraw) {
            $s->provider->onrequest = static function () use ($s, $group): prompt_response {
                groups_remove_member($group, $s->alpha);
                return request_provider::success('<p>Old group result</p>');
            };
        }
        $s->task->execute();

        $this->assertCount(1, $s->provider->requests);
        $this->assertStringContainsString('ALPHA_SOURCE', $s->provider->requests[0]['prompt']);
        $this->assertStringNotContainsString('BETA_SOURCE', $s->provider->requests[0]['prompt']);
        if ($withdraw) {
            $this->assert_failed($s, 'error_source_forbidden');
            $this->assert_logs($s, true);
        } else {
            $this->assertSame('completed', $this->report($s)->status);
            $this->assertSame([(int) $s->alpha->id], array_keys($this->subjects($s)));
        }
    }

    /**
     * Stable versus withdrawn actual group membership.
     *
     * @return array Test cases
     */
    public static function group_membership_provider(): array {
        return ['initial restriction' => [false], 'membership withdrawn' => [true]];
    }

    /**
     * An empty role intersection is no data, not an excuse to analyse every enrolled author.
     */
    private function check_role_filter_with_no_selected_authors_is_no_data(): void {
        $s = $this->scenario();
        $this->scope($s, (new scope_builder($s->course->id))->filter_by_roles([$s->teacherrole])
            ->filter_by_participants([$s->alpha->id, $s->beta->id])->with_sources(['cm_' . $s->forum->cmid]));
        $s->task->execute();
        $this->assert_failed($s, 'error_no_data');
        $this->assertSame([], $s->provider->requests);
    }

    /**
     * Invalid persisted record settings fail explicitly instead of removing the bound or multiplying a default.
     *
     * @param string $value Invalid setting
     */
    private function check_invalid_record_limit_is_not_unlimited(string $value): void {
        $s = $this->scenario();
        set_config('max_records_per_analysis', $value, 'report_ai_analysis');
        $s->task->execute();
        $this->assert_failed($s, 'error_source_failed');
        $this->assertSame([], $s->provider->requests);
    }

    /**
     * Invalid record counts, with no locale-dependent numeric interpretation.
     *
     * @return array Test cases
     */
    public static function invalid_limit_provider(): array {
        return ['zero' => ['0'], 'negative' => ['-1'], 'fractional' => ['1.5'], 'invalid' => ['unlimited']];
    }

    /**
     * A crashed worker's logged copy is also wiped when its actor can no longer resume.
     */
    private function check_crashed_attempt_log_is_cleaned_when_access_was_revoked(): void {
        global $DB;

        $s = $this->scenario();
        $DB->set_field('report_ai_analysis_reports', 'status', 'running', ['id' => $s->report->id]);
        $old = $this->analysis_log($s, 'CRASHED_ATTEMPT');
        $this->suspend_enrolment($s, (int) $s->teacher->id);
        $s->task->execute();

        $this->assert_failed($s, 'error_access_revoked');
        $this->assertSame([], $s->provider->requests);
        $this->assertSame(
            data_wiper::ANONYMIZE_STRING,
            $DB->get_field('local_ai_manager_request_log', 'prompttext', ['id' => $old->id], MUST_EXIST)
        );
    }

    /**
     * Cancellation after multiple successful responses wipes every known ID and never requests the next author.
     */
    private function check_cancellation_cleans_all_earlier_response_ids(): void {
        $s = $this->scenario('individual');
        $this->post($s, $s->silent, 'THIRD_AUTHOR_SOURCE');
        $expected = null;
        $s->provider->onrequest = function (array $request, int $number) use ($s, &$expected): prompt_response {
            if ($number === 2) {
                report_manager::cancel((int) $s->report->id);
                $expected = $this->snapshot($s);
            }
            return request_provider::success('<p>Late individual ' . $number . '</p>');
        };
        $s->task->execute();

        $this->assertSame('cancelled', $this->report($s)->status);
        $this->assertEquals($expected, $this->snapshot($s));
        $this->assertCount(2, $s->provider->requests);
        $this->assertCount(2, $s->provider->logids);
        $this->assert_logs($s, true);
    }

    /**
     * Absent response linkage must never fall back to wiping the replacement generation's whole report log.
     */
    private function check_late_response_without_log_id_preserves_new_generation_logs(): void {
        global $DB;

        $s = $this->scenario();
        $s->provider->createlogs = false;
        $newlogid = 0;
        $s->provider->onrequest = function () use ($s, &$newlogid): prompt_response {
            report_manager::cancel((int) $s->report->id);
            report_manager::rerun((int) $s->report->id);
            $newlogid = (int) $this->analysis_log($s, 'KEEP_NEW_GENERATION_LOG')->id;
            return prompt_response::create_from_result('unlinked-response', new usage(1), '<p>Old response without ID</p>');
        };
        $s->task->execute();

        $this->assertGreaterThan(0, $newlogid);
        $this->assertSame('pending', $this->report($s)->status);
        $this->assertSame(3, (int) $this->report($s)->runversion);
        $this->assertSame(
            'KEEP_NEW_GENERATION_LOG',
            $DB->get_field('local_ai_manager_request_log', 'prompttext', ['id' => $newlogid], MUST_EXIST)
        );
        $this->assertCount(1, $this->queued($s));
    }

    /**
     * Manager events/output formatting may throw after insertion but before returning the response ID.
     */
    private function check_exception_after_log_insert_cleans_unreturned_response(): void {
        $s = $this->scenario();
        $s->provider->onlogged = static function (): void {
            throw new \RuntimeException('Synthetic post-insert formatting failure');
        };
        $s->task->execute();

        $this->assert_failed($s, 'error_unknown');
        $this->assert_logs($s, true);
        $before = $this->snapshot($s);
        $s->task->execute();
        $this->assertEquals($before, $this->snapshot($s));
        $this->assert_logs($s, true);
        $this->assert_locks_released($s);
    }

    /**
     * Terminal and deleted tasks recover residual logs without rewriting report state.
     *
     * @param string $state State left after a crash
     */
    private function check_repeated_inactive_task_cleans_residual_logs(string $state): void {
        global $DB;

        $s = $this->scenario();
        $log = $this->analysis_log($s, 'RESIDUAL_PERSONAL_DATA');
        if ($state === 'deleted') {
            $DB->delete_records('report_ai_analysis_reports', ['id' => $s->report->id]);
        } else {
            $DB->set_field('report_ai_analysis_reports', 'status', $state, ['id' => $s->report->id]);
        }
        $before = $this->snapshot($s);
        $s->task->execute();
        $s->task->execute();

        $this->assertEquals($before, $this->snapshot($s));
        $this->assertSame([], $s->provider->requests);
        $this->assertSame(
            data_wiper::ANONYMIZE_STRING,
            $DB->get_field('local_ai_manager_request_log', 'prompttext', ['id' => $log->id], MUST_EXIST)
        );
        $this->assertNull($DB->get_field('local_ai_manager_request_log', 'userid', ['id' => $log->id], MUST_EXIST));
        $this->assert_locks_released($s);
    }

    /**
     * States which stop processing, not recovery of residual personal copies.
     *
     * @return array Test states
     */
    public static function cleanup_state_provider(): array {
        return [['completed'], ['failed'], ['cancelled'], ['deleted']];
    }

    /**
     * Superseded tasks must not wipe a replacement generation's outstanding log.
     */
    private function check_repeated_stale_task_preserves_new_generation_log(): void {
        global $DB;

        $s = $this->scenario();
        $DB->set_field('report_ai_analysis_reports', 'runversion', 2, ['id' => $s->report->id]);
        $log = $this->analysis_log($s, 'NEW_GENERATION_PERSONAL_COPY');
        $before = $this->snapshot($s);
        $s->task->execute();
        $s->task->execute();

        $this->assertEquals($before, $this->snapshot($s));
        $this->assertSame(
            'NEW_GENERATION_PERSONAL_COPY',
            $DB->get_field('local_ai_manager_request_log', 'prompttext', ['id' => $log->id], MUST_EXIST)
        );
        $this->assertSame([], $s->provider->requests);
    }

    /**
     * A new generation waits for the previous request's log capture, without failing its report.
     */
    private function check_replacement_worker_does_not_log_concurrently(): void {
        $s = $this->scenario();
        $lock = lock_config::get_lock_factory('report_ai_analysis')->get_lock('requests:' . $s->report->id, 0);
        $this->assertNotFalse($lock);
        $before = $this->snapshot($s);
        try {
            try {
                $s->task->execute();
                $this->fail('The core runner must receive a retriable lock failure');
            } catch (\moodle_exception $exception) {
                $this->assertSame('locktimeout', $exception->errorcode);
            }
            $this->assertEquals($before, $this->snapshot($s));
            $this->assertSame([], $s->provider->requests);
        } finally {
            $lock->release();
        }
        $this->assert_locks_released($s);
    }

    /**
     * Additional allowed posts must neither invalidate the captured IDs nor enter later requests.
     */
    private function check_new_forum_posts_do_not_invalidate_the_snapshot(): void {
        $s = $this->scenario('individual');
        set_config('max_records_per_analysis', 2, 'report_ai_analysis');
        $s->provider->onrequest = function (array $request, int $number) use ($s): prompt_response {
            if ($number === 1) {
                $this->post($s, $s->alpha, 'NEW_ALLOWED_POST');
                $this->post($s, $s->silent, 'NEW_ALLOWED_AUTHOR');
            }
            return request_provider::success('<p>Unchanged snapshot result</p>');
        };
        $s->task->execute();

        $this->assertSame('completed', $this->report($s)->status);
        $this->assertCount(2, $s->provider->requests);
        foreach ($s->provider->requests as $request) {
            $this->assertStringNotContainsString('NEW_ALLOWED_', $request['prompt']);
        }
        $this->assertSame([(int) $s->alpha->id, (int) $s->beta->id], array_keys($this->subjects($s)));
        $this->assert_logs($s, true);
    }

    /**
     * New chat entries may displace the collection window, but cannot revoke captured request access.
     */
    private function check_new_chat_entries_do_not_invalidate_the_snapshot(): void {
        $s = $this->scenario();
        $this->chat($s, $s->alpha, 'CAPTURED_CHAT');
        $this->scope($s, (new scope_builder($s->course->id))->with_sources(['block_' . $s->chatcontext->id])
            ->filter_by_participants([$s->alpha->id]));
        set_config('max_records_per_analysis', 1, 'report_ai_analysis');
        $s->provider->onrequest = function () use ($s): prompt_response {
            $this->chat($s, $s->alpha, 'NEW_CHAT_ONE');
            $this->chat($s, $s->alpha, 'NEW_CHAT_TWO');
            return request_provider::success('<p>Captured chat result</p>');
        };
        $s->task->execute();

        $this->assertSame('completed', $this->report($s)->status);
        $this->assertCount(1, $s->provider->requests);
        $this->assertStringContainsString('CAPTURED_CHAT', $s->provider->requests[0]['prompt']);
        $this->assertStringNotContainsString('NEW_CHAT_', $s->provider->requests[0]['prompt']);
        $this->assert_logs($s, true);
    }

    /**
     * A persisted manager quota produces rate-limit feedback without transient connector retries.
     */
    private function check_known_manager_quota_is_not_retried(): void {
        $s = $this->scenario();
        set_config('retry_on_failure', 3, 'report_ai_analysis');
        $s->provider->onavailable = static function (): void {
            throw new \moodle_exception('error_limitreached', 'local_ai_manager');
        };
        $s->task->execute();

        $this->assert_failed($s, 'error_rate_limit');
        $this->assertSame([], $s->provider->requests);
    }

    /**
     * Create an explicit per-test fixture; no common setUp creates data or grants an administrator to the worker.
     *
     * @param string $mode Analysis mode
     * @return stdClass Course, real actors/sources, queued-generation fixture and request boundary
     */
    private function scenario(string $mode = 'aggregated'): stdClass {
        global $CFG, $DB;

        // Isolate injected services between the sub-scenarios that share one test method.
        di::reset_container();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $CFG->forceclean = false;
        // PostgreSQL advisory locks are reentrant on one connection; record locks make this test deterministic.
        $CFG->lock_factory = db_record_lock_factory::class;
        foreach (
            ['system_prompt' => 'SYSTEM INSTRUCTIONS', 'store_raw_data' => 1, 'truncate_raw_data_length' => 500000,
            'max_records_per_analysis' => 1000, 'retry_on_failure' => 0] as $name => $value
        ) {
            set_config($name, $value, 'report_ai_analysis');
        }
        $generator = $this->getDataGenerator();
        $s = new stdClass();
        $s->course = $generator->create_course();
        $s->context = context_course::instance($s->course->id);
        $s->teacher = $generator->create_user(['firstname' => 'Worker', 'lastname' => 'Teacher']);
        $s->alpha = $generator->create_user(['firstname' => 'Alpha', 'lastname' => 'Author']);
        $s->beta = $generator->create_user(['firstname' => 'Beta', 'lastname' => 'Author']);
        $s->silent = $generator->create_user(['firstname' => 'Silent', 'lastname' => 'Participant']);
        $generator->enrol_user($s->teacher->id, $s->course->id, 'editingteacher');
        foreach ([$s->alpha, $s->beta, $s->silent] as $author) {
            $generator->enrol_user($author->id, $s->course->id, 'student');
        }
        $s->teacherrole = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $s->forum = $generator->create_module('forum', ['course' => $s->course->id]);
        /** @var \mod_forum_generator $forumgenerator */
        $forumgenerator = $generator->get_plugin_generator('mod_forum');
        $s->discussion = $forumgenerator->create_discussion([
            'course' => $s->course->id, 'forum' => $s->forum->id, 'userid' => $s->alpha->id,
            'name' => 'ALPHA_ROOT_TITLE', 'message' => 'ALPHA_SOURCE', 'messageformat' => FORMAT_PLAIN,
        ]);
        $s->reply = $this->post($s, $s->beta, 'BETA_SOURCE');
        $s->scope = (new scope_builder($s->course->id))->set_analysis_mode($mode)->with_sources(['cm_' . $s->forum->cmid])
            ->filter_by_participants([$s->alpha->id, $s->beta->id, $s->silent->id]);
        /** @var \report_ai_analysis_generator $reportgenerator */
        $reportgenerator = $generator->get_plugin_generator('report_ai_analysis');
        $s->report = $reportgenerator->create_report([
            'courseid' => $s->course->id, 'userid' => $s->teacher->id, 'status' => 'pending',
            'scope_details' => $s->scope->build(), 'runversion' => 1, 'action' => 'create',
            'subjects' => [$s->alpha->id, $s->beta->id, $s->silent->id],
        ]);
        $this->setUser($s->teacher);
        $s->task = $this->task($s->report, (int) $s->teacher->id);
        $s->provider = new request_provider();
        di::set(ai_request_provider::class, $s->provider);
        return $s;
    }

    /**
     * Create a generation- and attempt-aware task without an unrelated queue row to complete manually.
     *
     * @param stdClass $report Persisted report
     * @param int $userid Actual actor
     * @return process_analysis_task Executable task
     */
    private function task(stdClass $report, int $userid): process_analysis_task {
        $task = new process_analysis_task();
        $task->set_userid($userid);
        $task->set_custom_data(['reportid' => (int) $report->id, 'runversion' => (int) $report->runversion,
            'retry_count' => (int) $report->retry_count, 'contextid' => (int) $report->contextid]);
        return $task;
    }

    /**
     * Create a real reply with its own subject, not an artificial quote of another author's root.
     *
     * @param stdClass $s Scenario
     * @param stdClass $author Author
     * @param string $message Message text
     * @return stdClass Forum post
     */
    private function post(stdClass $s, stdClass $author, string $message): stdClass {
        /** @var \mod_forum_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        return $generator->create_post([
            'discussion' => $s->discussion->id, 'parent' => $s->discussion->firstpost,
            'userid' => $author->id, 'subject' => 'Own subject', 'message' => $message, 'messageformat' => FORMAT_PLAIN,
        ]);
    }

    /**
     * Update stored selection for a deliberate queued fixture, without mocking scope resolution.
     *
     * @param stdClass $s Scenario
     * @param scope_builder $scope Replacement scope
     */
    private function scope(stdClass $s, scope_builder $scope): void {
        global $DB;
        $s->scope = $scope;
        $DB->set_field('report_ai_analysis_reports', 'scope_details', $scope->build(), ['id' => $s->report->id]);
    }

    /**
     * Create real block/log data; only purpose presentation avoids dependence on an AI connector configuration.
     *
     * @param stdClass $s Scenario
     * @param stdClass $author Chat author
     * @param string $prompt Prompt marker
     * @param array $overrides Real log field overrides
     * @return stdClass Created log
     */
    private function chat(stdClass $s, stdClass $author, string $prompt, array $overrides = []): stdClass {
        if (!isset($s->chatcontext)) {
            $block = $this->getDataGenerator()->create_block('ai_chat', ['parentcontextid' => $s->context->id]);
            $s->chatcontext = context_block::instance($block->id);
            foreach (['local/ai_manager:viewprompts', 'local/ai_manager:viewpromptsdates'] as $capability) {
                assign_capability($capability, CAP_ALLOW, $s->teacherrole, $s->chatcontext->id, true);
            }
            $reader = $this->getMockBuilder(ai_log_provider::class)->onlyMethods(['format_purpose_output'])->getMock();
            $reader->method('format_purpose_output')->willReturnCallback(
                static fn(string $purpose, string $content): string => '<p>' . s($content) . '</p>'
            );
            di::set(ai_log_provider::class, $reader);
        }
        /** @var \local_ai_manager_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ai_manager');
        return $generator->create_request_log_entry(array_merge([
            'userid' => $author->id, 'component' => 'block_ai_chat', 'contextid' => $s->chatcontext->id,
            'coursecontextid' => $s->context->id, 'purpose' => 'chat', 'itemid' => 55, 'timecreated' => 2000,
            'prompttext' => $prompt, 'promptcompletion' => 'Chat response',
            'requestoptions' => '{"conversationcontext":[{"role":"user","content":"SECRET_HISTORY"}]}',
        ], $overrides));
    }

    /**
     * Create an actual manager log for exact-ID cleanup assertions.
     *
     * @param stdClass $s Scenario
     * @param string $prompt Marker
     * @param array $overrides Field overrides
     * @return stdClass Log record
     */
    private function analysis_log(stdClass $s, string $prompt, array $overrides = []): stdClass {
        /** @var \local_ai_manager_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ai_manager');
        return $generator->create_request_log_entry(array_merge([
            'userid' => $s->teacher->id, 'contextid' => $s->context->id, 'coursecontextid' => $s->context->id,
            'component' => 'report_ai_analysis', 'purpose' => 'singleprompt', 'itemid' => $s->report->id,
            'prompttext' => $prompt,
        ], $overrides));
    }

    /**
     * Make a real actor permission/account change, preserving the stale global user object deliberately.
     *
     * @param stdClass $s Scenario
     * @param string $change Mutation kind
     */
    private function revoke_actor(stdClass $s, string $change): void {
        global $DB;

        switch ($change) {
            case 'suspended':
            case 'deleted':
                $DB->set_field('user', $change, 1, ['id' => $s->teacher->id]);
                break;
            case 'unconfirmed':
                $DB->set_field('user', 'confirmed', 0, ['id' => $s->teacher->id]);
                break;
            case 'enrolment':
                $this->suspend_enrolment($s, (int) $s->teacher->id);
                break;
            case 'unenrolled':
                $instance = $DB->get_record('enrol', ['courseid' => $s->course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
                enrol_get_plugin('manual')->unenrol_user($instance, $s->teacher->id);
                break;
            case 'course':
                $DB->set_field('course', 'visible', 0, ['id' => $s->course->id]);
                assign_capability('moodle/course:viewhiddencourses', CAP_PROHIBIT, $s->teacherrole, $s->context->id, true);
                rebuild_course_cache($s->course->id, true);
                break;
            default:
                $DB->set_field('report_ai_analysis_reports', 'action', $change, ['id' => $s->report->id]);
                assign_capability('report/ai_analysis:' . $change, CAP_PROHIBIT, $s->teacherrole, $s->context->id, true);
        }
    }

    /**
     * Suspend an actual course enrolment through its plugin API.
     *
     * @param stdClass $s Scenario
     * @param int $userid Enrolled user
     */
    private function suspend_enrolment(stdClass $s, int $userid): void {
        global $DB;
        $instance = $DB->get_record('enrol', ['courseid' => $s->course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->update_user_enrol($instance, $userid, ENROL_USER_SUSPENDED);
    }

    /**
     * Reload the persisted report, without retaining a mutable success object from the worker.
     *
     * @param stdClass $s Scenario
     * @return stdClass Report
     */
    private function report(stdClass $s): stdClass {
        global $DB;
        return $DB->get_record('report_ai_analysis_reports', ['id' => $s->report->id], '*', MUST_EXIST);
    }

    /**
     * Return actual attribution keyed by user ID.
     *
     * @param stdClass $s Scenario
     * @return array<int, stdClass> Subject rows
     */
    private function subjects(stdClass $s): array {
        global $DB;
        $subjects = [];
        foreach ($DB->get_records('report_ai_analysis_users', ['reportid' => $s->report->id], 'userid') as $subject) {
            $subjects[(int) $subject->userid] = $subject;
        }
        return $subjects;
    }

    /**
     * Find only this report's actual queued tasks, regardless of their future due time.
     *
     * @param stdClass $s Scenario
     * @return process_analysis_task[] Queued tasks
     */
    private function queued(stdClass $s): array {
        return array_values(array_filter(
            task_manager::get_adhoc_tasks(process_analysis_task::class),
            static fn(process_analysis_task $task): bool => (int) $task->get_custom_data()->reportid === (int) $s->report->id
        ));
    }

    /**
     * Snapshot every report/subject/queue field for no-overwrite assertions, including deleted reports.
     *
     * @param stdClass $s Scenario
     * @return array Persisted state
     */
    private function snapshot(stdClass $s): array {
        global $DB;
        return [
            'report' => $DB->get_record('report_ai_analysis_reports', ['id' => $s->report->id]),
            'subjects' => $this->subjects($s),
            'tasks' => $DB->get_records('task_adhoc', ['classname' => '\\' . process_analysis_task::class], 'id'),
        ];
    }

    /**
     * Assert the public AI options/context/actor contract and absence of a transaction at transmission.
     *
     * @param stdClass $s Scenario
     * @param array $request Captured request
     */
    private function assert_request_contract(stdClass $s, array $request): void {
        $this->assertSame('singleprompt', $request['purpose']);
        $this->assertSame('report_ai_analysis', $request['component']);
        $this->assertSame((int) $s->context->id, $request['contextid']);
        $this->assertSame((int) $s->teacher->id, $request['userid']);
        $this->assertSame(['itemid' => (int) $s->report->id], $request['options']);
        $this->assertFalse($request['transaction']);
        $this->assertLessThanOrEqual(1000000, core_text::strlen($request['prompt']));
    }

    /**
     * Check terminal failure without any success/copy residue or surprise retries.
     *
     * @param stdClass $s Scenario
     * @param string $code Expected safe error key
     */
    private function assert_failed(stdClass $s, string $code): void {
        $report = $this->report($s);
        $this->assertSame('failed', $report->status, (string) $report->error_details);
        $this->assertSame($code, $report->error_code, (string) $report->error_details);
        $this->assertSame(error_info::get_description($code), $report->error_message);
        $this->assertGreaterThan(0, (int) $report->timecompleted);
        $this->assertSame([], $this->queued($s));
        $this->assert_copies_cleared($s);
    }

    /**
     * Check the shared null/zero contract for failed or retried output fields.
     *
     * @param stdClass $s Scenario
     */
    private function assert_copies_cleared(stdClass $s): void {
        $report = $this->report($s);
        foreach (['ai_result', 'raw_data', 'ai_model_name', 'token_usage'] as $field) {
            $this->assertNull($report->{$field}, $field);
        }
        $this->assertSame(0, (int) $report->execution_time);
        foreach ($this->subjects($s) as $subject) {
            $this->assertNull($subject->source_data);
            $this->assertNull($subject->ai_result);
        }
    }

    /**
     * Assert an actual atomic retry row with preserved actor/generation and the correct backoff.
     *
     * @param stdClass $s Scenario
     * @param int $count New attempt counter
     * @param int $before Time before execution
     * @param int $after Time after execution
     */
    private function assert_retry(stdClass $s, int $count, int $before, int $after): void {
        $report = $this->report($s);
        $this->assertSame('pending', $report->status, (string) $report->error_details);
        $this->assertSame($count, (int) $report->retry_count);
        $this->assertNull($report->timecompleted);
        $tasks = $this->queued($s);
        $this->assertCount(1, $tasks);
        $task = $tasks[0];
        $this->assertSame((int) $s->teacher->id, (int) $task->get_userid());
        $this->assertSame((int) $report->runversion, (int) $task->get_custom_data()->runversion);
        $this->assertSame((int) $report->id, (int) $task->get_custom_data()->reportid);
        $this->assertSame($count, (int) $task->get_custom_data()->retry_count);
        $delay = 60 * (2 ** ($count - 1));
        $this->assertGreaterThanOrEqual($before + $delay, (int) $task->get_next_run_time());
        $this->assertLessThanOrEqual($after + $delay, (int) $task->get_next_run_time());
        $this->assertLessThanOrEqual(3, $count);
        $this->assert_copies_cleared($s);
    }

    /**
     * Assert exact response log anonymisation while keeping statistical/context linkage intact.
     *
     * @param stdClass $s Scenario
     * @param bool $anonymized Expected privacy state
     */
    private function assert_logs(stdClass $s, bool $anonymized): void {
        global $DB;
        $this->assertNotEmpty($s->provider->logids, 'The fixture must create real logs to test cleanup');
        foreach ($s->provider->logids as $id) {
            $log = $DB->get_record('local_ai_manager_request_log', ['id' => $id], '*', MUST_EXIST);
            $this->assertSame('report_ai_analysis', $log->component);
            $this->assertSame((int) $s->context->id, (int) $log->contextid);
            $this->assertSame((int) $s->report->id, (int) $log->itemid);
            $this->assertSame(12, (int) $log->value);
            if ($anonymized) {
                $this->assertNull($log->userid);
                foreach (['prompttext', 'promptcompletion', 'requestoptions'] as $field) {
                    $this->assertSame(data_wiper::ANONYMIZE_STRING, $log->{$field});
                }
            } else {
                $this->assertSame((int) $s->teacher->id, (int) $log->userid);
                $this->assertNotSame(data_wiper::ANONYMIZE_STRING, $log->prompttext);
            }
        }
    }

    /**
     * Verify both lock resources have been released on success, failure and invalidation paths.
     *
     * @param stdClass $s Scenario
     */
    private function assert_locks_released(stdClass $s): void {
        foreach (
            ['report:' . $s->report->id, 'run:' . $s->report->id . ':' . $s->report->runversion,
                'requests:' . $s->report->id] as $resource
        ) {
            $lock = lock_config::get_lock_factory('report_ai_analysis')->get_lock($resource, 0);
            $this->assertNotFalse($lock, $resource);
            $lock->release();
        }
    }
}
