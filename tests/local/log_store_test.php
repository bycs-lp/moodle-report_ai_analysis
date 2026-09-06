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
 * Regression tests for scoped AI request log anonymisation.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis\local;

use context;
use context_course;
use local_ai_manager\local\data_wiper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

/**
 * Check exact report, response, component and context boundaries with real log fixtures.
 *
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(log_store::class)]
#[CoversClass(ai_log_provider::class)]
#[Group('report_ai_analysis')]
#[Group('local_mbs')]
#[Group('mebis')]
final class log_store_test extends \advanced_testcase {
    /**
     * Report, response, legacy and context cleanup stay run-, component- and context-scoped, and the provider forwards its flags.
     */
    public function test_log_store_cleanup_is_scoped_and_provider_forwards_flags(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $otheruser = $this->getDataGenerator()->create_user();

        // Report cleanup wipes every executing user's log and its soft-deleted copy, idempotently, and no unrelated log.
        $context = context_course::instance($this->getDataGenerator()->create_course()->id);
        $othercontext = context_course::instance($this->getDataGenerator()->create_course()->id);
        $first = $this->create_log($context, 41);
        $deleted = $this->create_log($context, 41, ['userid' => $otheruser->id, 'deleted' => 1]);
        $otherreport = $this->create_log($context, 42);
        $othercourselog = $this->create_log($othercontext, 41);
        $othercomponent = $this->create_log($context, 41, ['component' => 'mod_aichat']);
        $legacy = $this->create_log($context, 0);
        log_store::anonymize_report($context->id, 41);
        log_store::anonymize_report($context->id, 41);
        // The upstream itemid = 0 sentinel must not turn cleanup context-wide.
        foreach ([0, -1] as $badreportid) {
            log_store::anonymize_report($context->id, $badreportid);
        }
        $this->assert_anonymized($first);
        $this->assert_anonymized($deleted);
        foreach ([$otherreport, $othercourselog, $othercomponent, $legacy] as $record) {
            $this->assert_unchanged($record);
        }

        // Response cleanup targets only the matching run, even when an older run logs last, and never widens on bad input.
        $context = context_course::instance($this->getDataGenerator()->create_course()->id);
        $othercontext = context_course::instance($this->getDataGenerator()->create_course()->id);
        $newrun = $this->create_log($context, 41, ['timecreated' => 1700000001]);
        // This response has the highest log ID and timestamp, despite belonging to an older cancelled run.
        $lateoldrun = $this->create_log($context, 41, ['timecreated' => 1700000002, 'deleted' => 1]);
        $foreigncomponent = $this->create_log($context, 41, ['component' => 'mod_aichat']);
        $legacy = $this->create_log($context, 0);
        log_store::anonymize_response($context->id, 41, (int) $lateoldrun->id);
        log_store::anonymize_response($context->id, 41, (int) $lateoldrun->id);
        // Missing, invalid, out-of-range, foreign-component, cross-context, mismatched or legacy IDs never widen cleanup.
        foreach ([null, 0, -1, (int) $foreigncomponent->id + 1000, (int) $foreigncomponent->id] as $logid) {
            log_store::anonymize_response($context->id, 41, $logid);
        }
        log_store::anonymize_response($othercontext->id, 41, (int) $newrun->id);
        log_store::anonymize_response($context->id, 42, (int) $newrun->id);
        foreach ([0, -1] as $reportid) {
            log_store::anonymize_response($context->id, $reportid, (int) $legacy->id);
        }
        $this->assert_anonymized($lateoldrun);
        foreach ([$newrun, $foreigncomponent, $legacy] as $record) {
            $this->assert_unchanged($record);
        }

        // Batched ID cleanup reads the report linkage once and retains every unrelated statistical row.
        $batchcontext = context_course::instance($this->getDataGenerator()->create_course()->id);
        $batchfirst = $this->create_log($batchcontext, 41);
        $batchsecond = $this->create_log($batchcontext, 41, ['deleted' => 1]);
        $batchother = $this->create_log($batchcontext, 42);
        // Warm the public wiper's configuration cache before measuring linkage reads.
        new data_wiper();
        $reads = $DB->perf_get_reads();
        log_store::anonymize_responses(
            $batchcontext->id,
            41,
            [(int) $batchfirst->id, (int) $batchsecond->id, (int) $batchother->id]
        );
        $this->assertSame(1, $DB->perf_get_reads() - $reads);
        $this->assert_anonymized($batchfirst);
        $this->assert_anonymized($batchsecond);
        $this->assert_unchanged($batchother);

        // Every cleanup entrypoint is a no-op without logs.
        $emptycontext = context_course::instance($this->getDataGenerator()->create_course()->id);
        log_store::anonymize_report($emptycontext->id, 41);
        log_store::anonymize_response($emptycontext->id, 41, null);
        log_store::anonymize_legacy($emptycontext->id);
        log_store::anonymize_context($emptycontext->id);
        $this->assertFalse($DB->record_exists(
            'local_ai_manager_request_log',
            ['component' => 'report_ai_analysis', 'contextid' => $emptycontext->id]
        ));

        // Legacy cleanup covers only null/zero item IDs, including soft-deleted entries, within its own component and context.
        $legacycontext = context_course::instance($this->getDataGenerator()->create_course()->id);
        $legacyothercontext = context_course::instance($this->getDataGenerator()->create_course()->id);
        $zeroitem = $this->create_log($legacycontext, 0);
        $nullitem = $this->create_log($legacycontext, null);
        $legacydeleted = $this->create_log($legacycontext, 0, ['deleted' => 1]);
        $linked = $this->create_log($legacycontext, 41);
        $legacyothercourselog = $this->create_log($legacyothercontext, 0);
        $legacyothercomponent = $this->create_log($legacycontext, 0, ['component' => 'mod_aichat']);
        log_store::anonymize_legacy($legacycontext->id);
        log_store::anonymize_legacy($legacycontext->id);
        foreach ([$zeroitem, $nullitem, $legacydeleted] as $record) {
            $this->assert_anonymized($record);
        }
        foreach ([$linked, $legacyothercourselog, $legacyothercomponent] as $record) {
            $this->assert_unchanged($record);
        }

        // Context cleanup covers all report logs but never another component or a neighbouring course.
        $context = context_course::instance($this->getDataGenerator()->create_course()->id);
        $othercontext = context_course::instance($this->getDataGenerator()->create_course()->id);
        $records = [
            $this->create_log($context, 41),
            $this->create_log($context, 42, ['deleted' => 1]),
            $this->create_log($context, null),
        ];
        $contextothercourselog = $this->create_log($othercontext, 41);
        $contextothercomponent = $this->create_log($context, 41, ['component' => 'mod_aichat']);
        log_store::anonymize_context($context->id);
        log_store::anonymize_context($context->id);
        foreach ($records as $record) {
            $this->assert_anonymized($record);
        }
        $this->assert_unchanged($contextothercourselog);
        $this->assert_unchanged($contextothercomponent);

        // The provider forwards the include-deleted flag (argument five) and record limit (argument eight).
        $user = $this->getDataGenerator()->create_user();
        $block = $this->getDataGenerator()->create_block('ai_chat', [
            'parentcontextid' => context_course::instance($this->getDataGenerator()->create_course()->id)->id,
        ]);
        $blockcontext = \context_block::instance($block->id);
        /** @var \local_ai_manager_generator $loggenerator */
        $loggenerator = $this->getDataGenerator()->get_plugin_generator('local_ai_manager');
        $logs = [];
        foreach ([1000, 2000, 3000] as $time) {
            $logs[] = $loggenerator->create_request_log_entry([
                'userid' => $user->id, 'contextid' => $blockcontext->id, 'timecreated' => $time,
                'deleted' => $time === 3000 ? 1 : 0,
            ]);
        }
        $provider = new ai_log_provider();
        $cid = $blockcontext->id;
        $visible = $provider->get_log_entries('block_ai_chat', $cid, $user->id, 0, false, 'id,prompttext', ['chat'], 1);
        $this->assertSame([(int) $logs[1]->id], array_map('intval', array_keys($visible)));
        $withdeleted = $provider->get_log_entries('block_ai_chat', $cid, $user->id, 0, true, '*', ['chat'], 1);
        $this->assertSame([(int) $logs[2]->id], array_map('intval', array_keys($withdeleted)));
        $both = $provider->get_log_entries('block_ai_chat', $cid, $user->id, 0, false, '*', ['chat'], 2);
        $this->assertSame([1000, 2000], array_map('intval', array_column($both, 'timecreated')));
    }

    /**
     * Create a complete log fixture without configuring a connector or contacting an AI service.
     *
     * @param context $context Request context
     * @param int|null $reportid Request item ID, including legacy zero/null values
     * @param array $overrides Field overrides
     * @return stdClass Persisted record with database-normalised field types
     */
    private function create_log(context $context, ?int $reportid, array $overrides = []): stdClass {
        global $DB;
        /** @var \local_ai_manager_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ai_manager');
        $record = $generator->create_request_log_entry(array_merge([
            'contextid' => $context->id,
            'coursecontextid' => $context->id,
            'component' => 'report_ai_analysis',
            'purpose' => 'singleprompt',
            'itemid' => $reportid,
            'timecreated' => 1700000000,
        ], $overrides));
        return $DB->get_record('local_ai_manager_request_log', ['id' => $record->id], '*', MUST_EXIST);
    }

    /**
     * Assert personal fields are wiped while every statistical and lifecycle field is unchanged.
     *
     * @param stdClass $original Original log record
     */
    private function assert_anonymized(stdClass $original): void {
        $expected = clone $original;
        $expected->userid = null;
        $expected->prompttext = data_wiper::ANONYMIZE_STRING;
        $expected->promptcompletion = data_wiper::ANONYMIZE_STRING;
        $expected->requestoptions = data_wiper::ANONYMIZE_STRING;
        $this->assert_unchanged($expected);
    }

    /**
     * Assert that no field in a retained log has changed or been deleted.
     *
     * @param stdClass $expected Expected complete log record
     */
    private function assert_unchanged(stdClass $expected): void {
        global $DB;
        $actual = $DB->get_record('local_ai_manager_request_log', ['id' => $expected->id], '*', MUST_EXIST);
        $this->assertSame((array) $expected, (array) $actual);
    }
}
