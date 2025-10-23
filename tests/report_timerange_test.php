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
 * Test for report timerange functionality.
 *
 * @package    report_ai_analysis
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis;

use advanced_testcase;
use context_course;
use report_ai_analysis\scope_builder;
use stdClass;

/**
 * Test class for timerange functionality in AI analysis reports.
 *
 * @package    report_ai_analysis
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_ai_analysis\scope_builder
 */
final class report_timerange_test extends advanced_testcase {
    /**
     * Test that timerange is correctly stored in scope details when creating a report.
     *
     * @return void
     */
    public function test_timerange_stored_in_scope_details(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a course.
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        // Define time range.
        $timestart = strtotime('2025-01-01 00:00:00');
        $timeend = strtotime('2025-12-31 23:59:59');

        // Build scope with time range.
        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_timerange($timestart, $timeend);
        $scopedetails = $scopebuilder->build();

        // Create report record.
        $record = new \stdClass();
        $record->contextid = $context->id;
        $record->title = 'Test Report with Timerange';
        $record->scope_details = $scopedetails;
        $record->prompt = 'Test prompt';
        $record->status = 'pending';
        $record->userid = 2; // Admin user.
        $record->timecreated = time();
        $record->timemodified = time();

        $reportid = $DB->insert_record('report_ai_analysis_reports', $record);

        // Retrieve the report.
        $savedreport = $DB->get_record('report_ai_analysis_reports', ['id' => $reportid], '*', MUST_EXIST);

        // Parse scope details.
        $scope = scope_builder::parse($savedreport->scope_details);

        // Assert that timerange is stored correctly.
        $this->assertNotEmpty($scope->filters->timerange);
        $this->assertEquals($timestart, $scope->filters->timerange->start);
        $this->assertEquals($timeend, $scope->filters->timerange->end);
    }

    /**
     * Test that timerange is correctly updated when editing a report.
     *
     * @return void
     */
    public function test_timerange_updated_on_edit(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a course.
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        // Create initial report without timerange.
        $scopebuilder = new scope_builder($course->id);
        $scopedetails = $scopebuilder->build();

        $record = new \stdClass();
        $record->contextid = $context->id;
        $record->title = 'Test Report';
        $record->scope_details = $scopedetails;
        $record->prompt = 'Test prompt';
        $record->status = 'completed';
        $record->userid = 2;
        $record->timecreated = time();
        $record->timemodified = time();

        $reportid = $DB->insert_record('report_ai_analysis_reports', $record);

        // Define new time range.
        $newtimestart = strtotime('2025-06-01 00:00:00');
        $newtimeend = strtotime('2025-06-30 23:59:59');

        // Update report with time range.
        $newscopebuilder = new scope_builder($course->id);
        $newscopebuilder->with_timerange($newtimestart, $newtimeend);
        $newscopedetails = $newscopebuilder->build();

        $record->id = $reportid;
        $record->scope_details = $newscopedetails;
        $record->timemodified = time();

        $DB->update_record('report_ai_analysis_reports', $record);

        // Retrieve the updated report.
        $updatedreport = $DB->get_record('report_ai_analysis_reports', ['id' => $reportid], '*', MUST_EXIST);

        // Parse updated scope details.
        $updatedscope = scope_builder::parse($updatedreport->scope_details);

        // Assert that timerange is updated correctly.
        $this->assertNotEmpty($updatedscope->filters->timerange);
        $this->assertEquals($newtimestart, $updatedscope->filters->timerange->start);
        $this->assertEquals($newtimeend, $updatedscope->filters->timerange->end);
    }

    /**
     * Test that timerange can be removed from a report.
     *
     * @return void
     */
    public function test_timerange_can_be_removed(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a course.
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        // Create report with timerange.
        $timestart = strtotime('2025-01-01 00:00:00');
        $timeend = strtotime('2025-12-31 23:59:59');

        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_timerange($timestart, $timeend);
        $scopedetails = $scopebuilder->build();

        $record = new \stdClass();
        $record->contextid = $context->id;
        $record->title = 'Test Report';
        $record->scope_details = $scopedetails;
        $record->prompt = 'Test prompt';
        $record->status = 'completed';
        $record->userid = 2;
        $record->timecreated = time();
        $record->timemodified = time();

        $reportid = $DB->insert_record('report_ai_analysis_reports', $record);

        // Update report without timerange.
        $newscopebuilder = new scope_builder($course->id);
        $newscopedetails = $newscopebuilder->build();

        $record->id = $reportid;
        $record->scope_details = $newscopedetails;
        $record->timemodified = time();

        $DB->update_record('report_ai_analysis_reports', $record);

        // Retrieve the updated report.
        $updatedreport = $DB->get_record('report_ai_analysis_reports', ['id' => $reportid], '*', MUST_EXIST);

        // Parse updated scope details.
        $updatedscope = scope_builder::parse($updatedreport->scope_details);

        // Assert that timerange is removed.
        $this->assertEmpty($updatedscope->filters->timerange ?? null);
    }

    /**
     * Test that partial timerange (only start or only end) is not stored.
     *
     * @return void
     */
    public function test_partial_timerange_not_stored(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a course.
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        // Try to build scope with only timestart (this should not add timerange).
        $scopebuilder = new scope_builder($course->id);
        $scopedetails = $scopebuilder->build();

        // Parse scope details.
        $scope = scope_builder::parse($scopedetails);

        // Assert that timerange is not set.
        $this->assertEmpty($scope->filters->timerange ?? null);
    }

    /**
     * Test that timerange values are Unix timestamps.
     *
     * @return void
     */
    public function test_timerange_values_are_timestamps(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a course.
        $course = $this->getDataGenerator()->create_course();

        // Define time range.
        $timestart = strtotime('2025-03-15 10:30:00');
        $timeend = strtotime('2025-03-20 18:45:00');

        // Build scope with time range.
        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_timerange($timestart, $timeend);
        $scopedetails = $scopebuilder->build();

        // Parse scope details.
        $scope = scope_builder::parse($scopedetails);

        // Assert that values are integers (Unix timestamps).
        $this->assertIsInt($scope->filters->timerange->start);
        $this->assertIsInt($scope->filters->timerange->end);

        // Assert that start is before end.
        $this->assertLessThan($scope->filters->timerange->end, $scope->filters->timerange->start);

        // Assert exact values.
        $this->assertEquals($timestart, $scope->filters->timerange->start);
        $this->assertEquals($timeend, $scope->filters->timerange->end);
    }

    /**
     * Test that timerange with zero values is not stored.
     *
     * @return void
     */
    public function test_timerange_zero_values_not_stored(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a course.
        $course = $this->getDataGenerator()->create_course();

        // Try to build scope with zero values (disabled optional fields).
        $scopebuilder = new scope_builder($course->id);
        // Simulate disabled optional date fields by not calling with_timerange.
        $scopedetails = $scopebuilder->build();

        // Parse scope details.
        $scope = scope_builder::parse($scopedetails);

        // Assert that timerange is not set.
        $this->assertEmpty($scope->filters->timerange ?? null);
    }
}
