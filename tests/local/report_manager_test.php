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

use context_course;
use core\di;
use core\lock\db_record_lock_factory;
use core\lock\lock_config;
use core\task\manager;
use moodle_database;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use report_ai_analysis\scope_builder;
use report_ai_analysis\task\database_fault;
use report_ai_analysis\task\process_analysis_task;
use stdClass;

/**
 * Mutation, availability and rollback regressions using the real database and lock API.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(report_manager::class)]
#[Group('report_ai_analysis')]
#[Group('local_mbs')]
#[Group('mebis')]
final class report_manager_test extends \advanced_testcase {
    /**
     * Release test-only request services.
     */
    protected function tearDown(): void {
        di::reset_container();
        parent::tearDown();
    }

    /**
     * Saving initialises fields and task linkage; cancellation, erasure and both queueing paths respect availability and rollback.
     */
    public function test_report_manager_lifecycle_availability_and_rollback(): void {
        global $DB;

        // Saving overrides caller-supplied identity and status, then links exactly one owner-scoped task.
        $s = $this->scenario();
        $before = time();
        $id = report_manager::save((object) [
            'title' => 'Saved report', 'prompt' => 'Analyse the selected contributions.',
            'userid' => 99999, 'status' => 'completed', 'runversion' => 99,
        ], $s->scope);
        $report = $DB->get_record('report_ai_analysis_reports', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame((int) $s->teacher->id, (int) $report->userid);
        $this->assertSame('pending', $report->status);
        $this->assertSame(1, (int) $report->runversion);
        $this->assertGreaterThanOrEqual($before, (int) $report->timecreated);
        $tasks = manager::get_adhoc_tasks(process_analysis_task::class);
        $this->assertCount(1, $tasks);
        $task = reset($tasks);
        $this->assertSame((int) $s->teacher->id, (int) $task->get_userid());
        $this->assertSame($id, $task->get_custom_data()->reportid);
        $this->assertSame((int) $s->context->id, $task->get_custom_data()->contextid);
        $this->assertFalse($DB->is_transaction_started());

        // Cancellation and erasure remain possible after consent withdrawal, without any availability check.
        $provider = $this->createMock(ai_request_provider::class);
        $provider->expects($this->never())->method('require_available');
        di::set(ai_request_provider::class, $provider);
        report_manager::cancel($id);
        $this->assertSame('cancelled', $DB->get_field('report_ai_analysis_reports', 'status', ['id' => $id]));
        report_manager::delete($id);
        $this->assertFalse($DB->record_exists('report_ai_analysis_reports', ['id' => $id]));
        $this->assertSame([], manager::get_adhoc_tasks(process_analysis_task::class));

        // A failed availability check on either interactive queueing path leaves old results, mappings and queue entries intact.
        foreach (self::queueing_operations() as [$operation]) {
            $s = $this->scenario();
            $report = $this->completed_report($s);
            $snapshot = $this->snapshot();
            $provider = $this->createMock(ai_request_provider::class);
            $provider->expects($this->once())->method('require_available')->with((int) $s->context->id)
                ->willThrowException(new \moodle_exception('error_terms_not_accepted', 'report_ai_analysis'));
            di::set(ai_request_provider::class, $provider);
            try {
                if ($operation === 'save') {
                    report_manager::save(
                        (object) ['title' => 'Replacement', 'prompt' => 'New replacement prompt'],
                        $s->scope,
                        (int) $report->id
                    );
                } else {
                    report_manager::rerun((int) $report->id);
                }
                $this->fail('Unavailable queueing must fail before resetting the report');
            } catch (\moodle_exception $exception) {
                $this->assertSame('error_terms_not_accepted', $exception->errorcode);
            }
            $this->assertEquals($snapshot, $this->snapshot());
            report_manager::get_lock((int) $report->id)->release();
        }

        // Deletion failure rolls back earlier log/mapping changes before returning and releasing its lock.
        $s = $this->scenario();
        $report = $this->completed_report($s);
        $snapshot = $this->snapshot();
        require_once(__DIR__ . '/../task/fixtures/database_fault.php');
        $realdb = $DB;
        $double = $this->createMock(moodle_database::class);
        $fault = new database_fault($realdb, $double, (int) $report->id, 'delete');
        $DB = $double;
        try {
            try {
                report_manager::delete((int) $report->id);
                $this->fail('Deletion failure must escape after rollback');
            } catch (\dml_write_exception $exception) {
                $this->assertTrue($fault->triggered);
                $this->assertFalse($realdb->is_transaction_started());
            }
        } finally {
            $DB = $realdb;
        }
        $this->assertEquals($snapshot, $this->snapshot());
        $lock = lock_config::get_lock_factory('report_ai_analysis')->get_lock('report:' . $report->id, 0);
        $this->assertNotFalse($lock);
        $lock->release();
    }

    /**
     * Both interactive queueing paths use the same availability boundary.
     *
     * @return array Queueing operations
     */
    public static function queueing_operations(): array {
        return [['save'], ['rerun']];
    }

    /**
     * Prepare real course access without configuring or contacting an AI connector.
     *
     * @return stdClass Course, context, teacher and scope
     */
    private function scenario(): stdClass {
        global $CFG;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $CFG->lock_factory = db_record_lock_factory::class;
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        di::set(ai_request_provider::class, $this->createMock(ai_request_provider::class));
        return (object) ['course' => $course, 'teacher' => $teacher,
            'context' => context_course::instance($course->id), 'scope' => new scope_builder($course->id)];
    }

    /**
     * Preserve representative inseparable content and a real manager log for rollback tests.
     *
     * @param stdClass $s Scenario
     * @return stdClass Persisted report
     */
    private function completed_report(stdClass $s): stdClass {
        $generator = $this->getDataGenerator()->get_plugin_generator('report_ai_analysis');
        $report = $generator->create_report(['courseid' => $s->course->id, 'userid' => $s->teacher->id,
            'status' => 'completed', 'scope_details' => $s->scope->build(), 'runversion' => 1,
            'raw_data' => 'Retained source', 'ai_result' => '<p>Retained result</p>', 'subjects' => [$s->teacher->id]]);
        $this->getDataGenerator()->get_plugin_generator('local_ai_manager')->create_request_log_entry([
            'component' => 'report_ai_analysis', 'contextid' => $s->context->id, 'coursecontextid' => $s->context->id,
            'userid' => $s->teacher->id, 'itemid' => $report->id, 'purpose' => 'singleprompt',
            'prompttext' => 'Retained log source', 'promptcompletion' => 'Retained log result',
        ]);
        return $report;
    }

    /**
     * Capture complete mutation-owned records, not a selected subset of security fields.
     *
     * @return array Complete database snapshots
     */
    private function snapshot(): array {
        global $DB;

        $snapshot = [];
        $tables = ['report_ai_analysis_reports', 'report_ai_analysis_users', 'task_adhoc', 'local_ai_manager_request_log'];
        foreach ($tables as $table) {
            $snapshot[$table] = $DB->get_records($table, null, 'id');
        }
        return $snapshot;
    }
}
