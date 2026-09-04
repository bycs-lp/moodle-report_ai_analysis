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
 * Privacy provider tests.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_mbs
 * @group      mebis
 */

namespace report_ai_analysis;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use report_ai_analysis\privacy\provider;

/**
 * Privacy provider tests.
 *
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_ai_analysis\privacy\provider
 */
final class privacy_test extends \core_privacy\tests\provider_testcase {
    /**
     * Set up test state.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        writer::reset();
    }

    /**
     * Privacy exports never contain technical error details.
     */
    public function test_export_omits_error_details(): void {
        global $CFG;

        $CFG->debugdeveloper = true;
        $CFG->debugdisplay = true;

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $generator = $this->getDataGenerator()->get_plugin_generator('report_ai_analysis');
        $report = $generator->create_report([
            'courseid' => $course->id,
            'userid' => $user->id,
            'status' => 'failed',
            'error_code' => 'error_ai_request',
            'error_message' => get_string('error_ai_request', 'report_ai_analysis'),
            'error_details' => 'Sensitive connector details',
        ]);

        $contextlist = new approved_contextlist($user, 'report_ai_analysis', [$context->id]);
        provider::export_user_data($contextlist);

        $data = writer::with_context($context)->get_data(['AI Analysis Reports', $report->id]);
        $this->assertSame(get_string('error_ai_request', 'report_ai_analysis'), $data->error_message);
        $this->assertSame('error_ai_request', $data->error_code);
        $this->assertFalse(property_exists($data, 'error_details'));
    }
}