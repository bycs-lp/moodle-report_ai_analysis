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
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis;

use advanced_testcase;

/**
 * Test class for timerange functionality in AI analysis reports.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_ai_analysis\scope_builder
 */
final class report_timerange_test extends advanced_testcase {
    /**
     * Test timerange persistence in database records.
     *
     * Tests storage, update, and removal of timerange in scope_details.
     */
    public function test_timerange_persistence(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $timestart = strtotime('2025-01-01 00:00:00');
        $timeend = strtotime('2025-12-31 23:59:59');

        // Create report with timerange.
        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_timerange($timestart, $timeend);

        $record = new \stdClass();
        $record->contextid = $context->id;
        $record->title = 'Test Report';
        $record->scope_details = $scopebuilder->build();
        $record->prompt = 'Test prompt';
        $record->status = 'pending';
        $record->userid = 2;
        $record->timecreated = time();
        $record->timemodified = time();
        $reportid = $DB->insert_record('report_ai_analysis_reports', $record);

        // Verify storage.
        $saved = $DB->get_record('report_ai_analysis_reports', ['id' => $reportid]);
        $scope = scope_builder::parse($saved->scope_details);
        $this->assertEquals($timestart, $scope->filters->timerange->start);
        $this->assertEquals($timeend, $scope->filters->timerange->end);
        $this->assertIsInt($scope->filters->timerange->start);

        // Update with new timerange.
        $newtimestart = strtotime('2025-06-01 00:00:00');
        $newtimeend = strtotime('2025-06-30 23:59:59');
        $newscopebuilder = new scope_builder($course->id);
        $newscopebuilder->with_timerange($newtimestart, $newtimeend);

        $record->id = $reportid;
        $record->scope_details = $newscopebuilder->build();
        $DB->update_record('report_ai_analysis_reports', $record);

        $updated = $DB->get_record('report_ai_analysis_reports', ['id' => $reportid]);
        $updatedscope = scope_builder::parse($updated->scope_details);
        $this->assertEquals($newtimestart, $updatedscope->filters->timerange->start);

        // Remove timerange.
        $emptyscopebuilder = new scope_builder($course->id);
        $record->scope_details = $emptyscopebuilder->build();
        $DB->update_record('report_ai_analysis_reports', $record);

        $removed = $DB->get_record('report_ai_analysis_reports', ['id' => $reportid]);
        $removedscope = scope_builder::parse($removed->scope_details);
        $this->assertEmpty($removedscope->filters->timerange ?? null);
    }

    /**
     * Test scope without timerange has empty timerange filter.
     */
    public function test_scope_without_timerange(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $scopebuilder = new scope_builder($course->id);
        $scope = scope_builder::parse($scopebuilder->build());

        $this->assertEmpty($scope->filters->timerange ?? null);
    }
}
