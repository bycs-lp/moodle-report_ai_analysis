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
 * Tests for Moodle-aware error presentation.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_mbs
 * @group      mebis
 */

namespace report_ai_analysis\unit;

use report_ai_analysis\error_info;

/**
 * Tests for error presentation.
 *
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_ai_analysis\error_info
 */
final class error_info_test extends \advanced_testcase {
    /**
     * Set up test state.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * User descriptions are always localised and unknown codes are neutral.
     */
    public function test_get_description(): void {
        $this->assertSame(
            get_string('error_terms_not_accepted', 'report_ai_analysis'),
            error_info::get_description('error_terms_not_accepted')
        );
        $this->assertSame(
            get_string('error_unknown', 'report_ai_analysis'),
            error_info::get_description('internal_connector_failure')
        );
    }

    /**
     * Technical details stay hidden when Moodle debug display is disabled.
     */
    public function test_debug_details_require_debug_display(): void {
        global $CFG;

        $CFG->debugdeveloper = true;
        $CFG->debugdisplay = false;

        $this->assertNull(error_info::get_debug_details('error_ai_request', null, 'Sensitive connector details'));
    }

    /**
     * Technical details stay hidden below developer debugging level.
     */
    public function test_debug_details_require_developer_debugging(): void {
        global $CFG;

        $CFG->debugdeveloper = false;
        $CFG->debugdisplay = true;

        $this->assertNull(error_info::get_debug_details('error_ai_request', null, 'Sensitive connector details'));
    }

    /**
     * Technical details are available only when both Moodle settings allow them.
     */
    public function test_debug_details_are_shown_when_enabled(): void {
        global $CFG;

        $CFG->debugdeveloper = true;
        $CFG->debugdisplay = true;

        $this->assertSame(
            'Sensitive connector details',
            error_info::get_debug_details('error_ai_request', null, 'Sensitive connector details')
        );
        $this->assertNull(error_info::get_debug_details(
            'error_unknown',
            null,
            get_string('error_unknown', 'report_ai_analysis')
        ));
    }

    /**
     * Legacy technical messages follow the same debug display rules.
     */
    public function test_legacy_error_message_is_treated_as_debug_details(): void {
        global $CFG;

        $CFG->debugdeveloper = true;
        $CFG->debugdisplay = true;

        $this->assertSame(
            'Legacy connector details',
            error_info::get_debug_details('error_ai_request', 'Legacy connector details')
        );
        $this->assertNull(error_info::get_debug_details(
            'error_unknown',
            get_string('error_unknown', 'report_ai_analysis')
        ));
    }
}
