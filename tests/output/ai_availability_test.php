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

namespace report_ai_analysis\output;

/**
 * Tests the public availability presentation boundary.
 *
 * @package    report_ai_analysis
 * @category   test
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ai_availability::class)]
final class ai_availability_test extends \advanced_testcase {
    /**
     * Effective availability precedence and always-generic, sanitised user messages.
     */
    public function test_effective_state_and_message_sanitisation(): void {
        global $CFG;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        // General and purpose availability must both allow the action.
        foreach (self::availability_provider() as [$general, $purpose, $expected]) {
            $service = $this->getMockBuilder(ai_availability::class)->onlyMethods(['get_config'])->getMock();
            $purposes = $purpose === null ? [] : [['purpose' => 'singleprompt', 'available' => $purpose]];
            $service->expects($this->once())->method('get_config')->with($context)->willReturn([
                'availability' => ['available' => $general],
                'purposes' => $purposes,
            ]);
            $actual = $service->get_availability($context);
            $this->assertSame($expected, $actual['state']);
            $this->assertSame(
                $expected === 'disabled' ? s(get_string('aiunavailable', 'report_ai_analysis')) : '',
                $actual['message']
            );
        }

        // Backend failures and manager notices are always sanitised before reaching the user.
        $this->setAdminUser();
        $CFG->debugdeveloper = true;
        $CFG->debugdisplay = true;
        $CFG->forceclean = false;

        // A backend exception never becomes a user-facing description, even with debugging on.
        $throwing = $this->getMockBuilder(ai_availability::class)->onlyMethods(['get_config'])->getMock();
        $throwing->method('get_config')->willThrowException(new \RuntimeException('Private connector configuration'));
        $result = $throwing->get_availability($context);
        $this->assertSame('disabled', $result['state']);
        $this->assertSame(s(get_string('aiunavailable', 'report_ai_analysis')), $result['message']);
        $this->assertStringNotContainsString('Private connector', $result['message']);

        // A documented manager notice may include text, but active markup is stripped.
        $notice = $this->getMockBuilder(ai_availability::class)->onlyMethods(['get_config'])->getMock();
        $notice->method('get_config')->willReturn([
            'availability' => ['available' => 'available'],
            'purposes' => [[
                'purpose' => 'singleprompt',
                'available' => 'disabled',
                'errormessage' => '<p>Quota notice</p><img src="x" onerror="availabilityProbe()">',
            ]],
        ]);
        $cleaned = $notice->get_availability($context);
        $this->assertSame('disabled', $cleaned['state']);
        $this->assertStringContainsString('Quota notice', $cleaned['message']);
        $this->assertStringNotContainsString('availabilityProbe', $cleaned['message']);
    }

    /**
     * Availability precedence and fail-closed cases.
     *
     * @return array
     */
    public static function availability_provider(): array {
        return [
            'both available' => ['available', 'available', 'available'],
            'disabled generally' => ['disabled', 'available', 'disabled'],
            'disabled purpose' => ['available', 'disabled', 'disabled'],
            'hidden generally' => ['hidden', 'available', 'hidden'],
            'hidden purpose' => ['available', 'hidden', 'hidden'],
            'hidden beats disabled' => ['disabled', 'hidden', 'hidden'],
            'missing purpose' => ['available', null, 'disabled'],
            'unknown state' => ['unexpected', 'available', 'disabled'],
        ];
    }
}
