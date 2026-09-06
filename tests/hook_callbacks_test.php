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
 * Tests for report lifecycle and AI usage hook registration.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis;

use context_course;
use core\di;
use core\hook\manager as hook_manager;
use core\task\manager as task_manager;
use core_course\hook\before_course_deleted;
use local_ai_manager\hook\purpose_usage;
use local_ai_manager\local\data_wiper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use report_ai_analysis\local\ai_request_provider;
use report_ai_analysis\task\process_analysis_task;
use stdClass;

/**
 * Exercise the registered listeners, including Moodle's actual course-deletion entrypoint.
 *
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(hook_callbacks::class)]
#[Group('report_ai_analysis')]
#[Group('local_mbs')]
#[Group('mebis')]
final class hook_callbacks_test extends \advanced_testcase {
    /**
     * Both listeners register under the real hook classes and dispatch a localised usage description; course deletion then
     * removes every report, log copy and pending run.
     */
    public function test_hooks_register_dispatch_and_course_deletion_cleanup(): void {
        global $DB;
        $this->resetAfterTest();

        // Both listeners are registered with callable, enabled callbacks and a localised usage description.
        $manager = $this->get_hook_manager();
        $this->assertEqualsCanonicalizing(
            [before_course_deleted::class, purpose_usage::class],
            $manager->get_hooks_with_callbacks()
        );
        foreach ($this->callback_methods() as $hookclass => $method) {
            $callbacks = $manager->get_callbacks_for_hook($hookclass);
            $this->assertCount(1, $callbacks);
            $this->assertSame(hook_callbacks::class . '::' . $method, $callbacks[0]['callback']);
            $this->assertTrue(is_callable($callbacks[0]['callback']));
            $this->assertFalse($callbacks[0]['disabled']);
        }
        $hook = new purpose_usage();
        $manager->dispatch($hook);
        $this->assertSame([
            'singleprompt' => [
                'report_ai_analysis' => [get_string('purposeplacedescription', 'report_ai_analysis')],
            ],
        ], $hook->get_purposes_usage_info());
        $this->assertSame(get_string('pluginname', 'report_ai_analysis'), $hook->get_component_displayname('report_ai_analysis'));

        // Course deletion removes all its reports and log copies, and an old queued task cannot send data afterwards.
        $this->setAdminUser();
        $owner = $this->getDataGenerator()->create_user();
        $subject = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $context = context_course::instance($course->id);
        $othercontext = context_course::instance($othercourse->id);
        /** @var \report_ai_analysis_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('report_ai_analysis');
        $reports = [];
        $logs = [];
        foreach (['pending', 'running', 'completed'] as $status) {
            $report = $generator->create_report([
                'contextid' => $context->id, 'userid' => $owner->id, 'status' => $status, 'runversion' => 1,
            ]);
            $DB->insert_record('report_ai_analysis_users', (object) [
                'reportid' => $report->id, 'userid' => $subject->id,
                'source_data' => 'Included subject content', 'ai_result' => null,
            ]);
            $reports[] = $report;
            $logs[] = $this->create_log($report);
        }
        $logs[] = $this->create_log($reports[0], ['itemid' => 0, 'deleted' => 1]);
        $logs[] = $this->create_log($reports[0], ['itemid' => null]);
        $foreignlog = $this->create_log($reports[0], ['component' => 'mod_aichat']);
        $otherreport = $generator->create_report(['contextid' => $othercontext->id, 'userid' => $owner->id]);
        $DB->insert_record('report_ai_analysis_users', (object) ['reportid' => $otherreport->id, 'userid' => $subject->id]);
        $otherlog = $this->create_log($otherreport);

        $task = new process_analysis_task();
        $task->set_userid($owner->id);
        $task->set_custom_data(['reportid' => $reports[0]->id, 'runversion' => 1]);
        task_manager::queue_adhoc_task($task);
        $requestprovider = $this->createMock(ai_request_provider::class);
        $requestprovider->expects($this->never())->method('perform_request');
        di::set(ai_request_provider::class, $requestprovider);
        // Load the real registration while isolating unrelated plugins' course hooks.
        di::set(hook_manager::class, $this->get_hook_manager());

        $this->assertTrue(delete_course($course, false));

        $this->assertFalse($DB->record_exists('context', ['id' => $context->id]));
        foreach ($reports as $report) {
            $this->assertFalse($DB->record_exists('report_ai_analysis_reports', ['id' => $report->id]));
            $this->assertFalse($DB->record_exists('report_ai_analysis_users', ['reportid' => $report->id]));
        }
        foreach ($logs as $log) {
            $expected = clone $log;
            $expected->userid = null;
            $expected->prompttext = data_wiper::ANONYMIZE_STRING;
            $expected->promptcompletion = data_wiper::ANONYMIZE_STRING;
            $expected->requestoptions = data_wiper::ANONYMIZE_STRING;
            $this->assert_log_unchanged($expected);
        }
        $this->assert_log_unchanged($foreignlog);
        $this->assert_log_unchanged($otherlog);
        $this->assertTrue($DB->record_exists('report_ai_analysis_reports', ['id' => $otherreport->id]));
        $this->assertTrue($DB->record_exists('report_ai_analysis_users', ['reportid' => $otherreport->id]));

        // A worker can already hold this task in memory when the course is deleted.
        ob_start();
        try {
            $task->execute();
        } finally {
            ob_end_clean();
        }
        $this->assertFalse($DB->record_exists('report_ai_analysis_reports', ['id' => $reports[0]->id]));
        $this->assert_log_unchanged($otherlog);
    }

    /**
     * Build a hook dispatcher from this plugin's actual registration file.
     *
     * @return hook_manager Isolated dispatcher
     */
    private function get_hook_manager(): hook_manager {
        return hook_manager::phpunit_get_instance(['report_ai_analysis' => __DIR__ . '/../db/hooks.php']);
    }

    /**
     * Map each registered hook class to its expected callback method.
     *
     * @return array Hook class to callback method
     */
    private function callback_methods(): array {
        return [
            before_course_deleted::class => 'before_course_deleted',
            purpose_usage::class => 'purpose_usage',
        ];
    }

    /**
     * Create a log fixture without making a real AI request.
     *
     * @param stdClass $report Associated report
     * @param array $overrides Field overrides
     * @return stdClass Persisted log with database-normalised field types
     */
    private function create_log(stdClass $report, array $overrides = []): stdClass {
        global $DB;
        /** @var \local_ai_manager_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ai_manager');
        $record = $generator->create_request_log_entry(array_merge([
            'component' => 'report_ai_analysis',
            'contextid' => $report->contextid,
            'coursecontextid' => $report->contextid,
            'userid' => $report->userid,
            'itemid' => $report->id,
            'purpose' => 'singleprompt',
        ], $overrides));
        return $DB->get_record('local_ai_manager_request_log', ['id' => $record->id], '*', MUST_EXIST);
    }

    /**
     * Assert that no statistical or unrelated field has been changed or deleted.
     *
     * @param stdClass $expected Expected complete log record
     */
    private function assert_log_unchanged(stdClass $expected): void {
        global $DB;
        $actual = $DB->get_record('local_ai_manager_request_log', ['id' => $expected->id], '*', MUST_EXIST);
        $this->assertSame((array) $expected, (array) $actual);
    }
}
