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
 */

namespace report_ai_analysis;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests for error presentation.
 *
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(error_info::class)]
#[Group('report_ai_analysis')]
#[Group('local_mbs')]
#[Group('mebis')]
final class error_info_test extends \advanced_testcase {
    /**
     * Localised descriptions and both-flag gated debug details, for current and legacy storage.
     */
    public function test_descriptions_and_debug_detail_gating(): void {
        global $CFG;
        $this->resetAfterTest();

        // User descriptions are always localised and unknown codes stay neutral.
        $this->assertSame(
            get_string('error_terms_not_accepted', 'report_ai_analysis'),
            error_info::get_description('error_terms_not_accepted')
        );
        $this->assertSame(
            get_string('error_unknown', 'report_ai_analysis'),
            error_info::get_description('internal_connector_failure')
        );

        // Diagnostic details need both developer debugging and debug display across storage generations.
        foreach (self::debug_flag_matrix_provider() as [$developer, $display, $legacy]) {
            $CFG->debugdeveloper = $developer;
            $CFG->debugdisplay = $display;
            $details = 'Sensitive connector details';
            $expected = ($developer && $display) ? $details : null;
            $actual = $legacy
                ? error_info::get_debug_details('error_ai_request', $details)
                : error_info::get_debug_details('error_ai_request', null, $details);
            $this->assertSame($expected, $actual);

            // Even when shown, an unknown code never leaks the localized fallback string as debug details.
            if ($expected !== null) {
                $unknown = get_string('error_unknown', 'report_ai_analysis');
                $this->assertNull($legacy
                    ? error_info::get_debug_details('error_unknown', $unknown)
                    : error_info::get_debug_details('error_unknown', null, $unknown));
            }
        }
    }

    /**
     * Debug flag combinations for both storage generations.
     *
     * @return array
     */
    public static function debug_flag_matrix_provider(): array {
        return [
            'neither' => [false, false, false],
            'developer only' => [true, false, false],
            'display only' => [false, true, false],
            'both' => [true, true, false],
            'legacy neither' => [false, false, true],
            'legacy developer only' => [true, false, true],
            'legacy display only' => [false, true, true],
            'legacy both' => [true, true, true],
        ];
    }
}
