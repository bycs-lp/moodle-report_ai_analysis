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

use core\di;

/**
 * Tests the rendered report list, SQL visibility and row actions together.
 *
 * @package    report_ai_analysis
 * @category   test
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(index_page::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\report_ai_analysis\table\reports_table::class)]
final class index_page_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Current teacher. */
    private \stdClass $owner;

    /** @var \stdClass Other teacher. */
    private \stdClass $other;

    /**
     * Explicitly prepare a listing test without invoking an AI connector.
     */
    private function prepare_test(): void {
        global $PAGE, $OUTPUT;

        // A fresh page lets a single scenario test render more than one listing.
        $PAGE = new \moodle_page();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->owner = $this->getDataGenerator()->create_user();
        $this->other = $this->getDataGenerator()->create_user();
        foreach ([$this->owner, $this->other] as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'editingteacher');
        }
        $this->setUser($this->owner);
        $PAGE->set_course($this->course);
        $PAGE->set_url('/report/ai_analysis/index.php', ['courseid' => $this->course->id]);
        $OUTPUT = $PAGE->get_renderer('core');
        set_config('share_reports_in_course', 0, 'report_ai_analysis');
        $this->set_availability('available');
    }

    /**
     * Release fixture records after each test.
     */
    protected function tearDown(): void {
        unset($this->course, $this->owner, $this->other);
        parent::tearDown();
    }

    /**
     * Set predictable AI availability without bypassing report permissions.
     *
     * @param string $state State
     */
    private function set_availability(string $state): void {
        $service = $this->createMock(ai_availability::class);
        $service->method('get_availability')->willReturn([
            'state' => $state,
            'message' => $state === 'disabled' ? s(get_string('aiunavailable', 'report_ai_analysis')) : '',
        ]);
        di::set(ai_availability::class, $service);
    }

    /**
     * Create a report in the listed course.
     *
     * @param string $title Report title
     * @param int $userid Creator
     * @param string $status State
     * @return \stdClass
     */
    private function create_report(string $title, int $userid, string $status = 'completed'): \stdClass {
        /** @var \report_ai_analysis_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('report_ai_analysis');
        return $generator->create_report([
            'contextid' => \context_course::instance($this->course->id)->id,
            'title' => $title,
            'userid' => $userid,
            'status' => $status,
            'resultformat' => FORMAT_HTML,
        ]);
    }

    /**
     * Parse the table's rendered HTML.
     *
     * @param string $html HTML
     * @return \DOMXPath
     */
    private function get_xpath(string $html): \DOMXPath {
        $document = new \DOMDocument();
        $document->loadHTML('<meta charset="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        return new \DOMXPath($document);
    }

    /**
     * Visibility, pagination, owner actions, read-only non-owner rows and creation availability.
     */
    public function test_listing_visibility_actions_and_creation_controls(): void {
        global $DB, $OUTPUT;

        // Visibility, pagination and owner row actions all respect the course and sharing boundary.
        $this->prepare_test();
        $report = $this->create_report('Owned report marker', (int) $this->owner->id, 'pending');
        $this->create_report('Foreign report marker', (int) $this->other->id);
        $othercourse = $this->getDataGenerator()->create_course();
        /** @var \report_ai_analysis_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('report_ai_analysis');
        $generator->create_report([
            'courseid' => $othercourse->id,
            'userid' => $this->owner->id,
            'title' => 'Outside course marker',
        ]);
        for ($index = 0; $index < 26; $index++) {
            $this->create_report('Foreign page marker ' . $index, (int) $this->other->id);
        }

        // Private listing shows only the owner's report; foreign and out-of-course rows stay hidden.
        $data = (new index_page((int) $this->course->id))->export_for_template($OUTPUT);
        $this->assertStringContainsString('Owned report marker', $data->tablecontent);
        $this->assertStringNotContainsString('Foreign report marker', $data->tablecontent);
        $this->assertStringNotContainsString('Foreign page marker', $data->tablecontent);
        $this->assertStringNotContainsString('Outside course marker', $data->tablecontent);
        $this->assertEquals($this->course->id, (new \moodle_url($data->createurl))->get_param('courseid'));

        // Hidden foreign rows never consume page slots or leak through pagination.
        $xpath = $this->get_xpath($data->tablecontent);
        $this->assertCount(0, $xpath->query('//*[contains(@class, "pagination")]//a[contains(@href, "page=1")]'));

        // The pending owner row exposes only state-appropriate, course-scoped actions.
        $this->assertCount(0, $xpath->query('//a[contains(@href, "/export.php") or contains(@href, "/rerun.php")]'));
        foreach (['delete', 'cancel'] as $action) {
            $node = $xpath->query('//a[contains(@href, "action=' . $action . '")]')->item(0);
            $this->assertInstanceOf(\DOMElement::class, $node);
            $url = new \moodle_url($node->getAttribute('href'));
            $this->assertEquals($this->course->id, $url->get_param('courseid'));
            $this->assertEquals($report->id, $url->get_param('reportid'));
        }

        // Enabling sharing widens visibility to foreign rows without crossing the course boundary.
        set_config('share_reports_in_course', 1, 'report_ai_analysis');
        $shared = (new index_page((int) $this->course->id))->export_for_template($OUTPUT);
        $this->assertStringContainsString('Foreign report marker', $shared->tablecontent);
        $this->assertStringNotContainsString('Outside course marker', $shared->tablecontent);

        // Shared and manager-visible rows the current user does not own are strictly read-only.
        $this->prepare_test();
        $context = \context_course::instance($this->course->id);

        // Shared foreign rows offer export only, never a foreign mutation.
        $this->create_report('Shared failed report', (int) $this->other->id, 'failed');
        set_config('share_reports_in_course', 1, 'report_ai_analysis');
        $data = (new index_page((int) $this->course->id))->export_for_template($OUTPUT);
        $xpath = $this->get_xpath($data->tablecontent);
        $this->assertStringContainsString('Shared failed report', $data->tablecontent);
        $this->assertCount(1, $xpath->query('//a[contains(@href, "/export.php")]'));
        $this->assertCount(0, $xpath->query('//a[contains(@href, "action=delete") or contains(@href, "action=cancel")' .
            ' or contains(@href, "/create.php") or contains(@href, "/rerun.php")]'));

        // A manager with viewall but not manageall reads private reports without mutation controls.
        $manager = $this->getDataGenerator()->create_user();
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        $this->getDataGenerator()->enrol_user($manager->id, $this->course->id, $roleid);
        assign_capability('report/ai_analysis:manageall', CAP_PROHIBIT, $roleid, $context->id);
        $this->create_report('Manager visible private report', (int) $this->owner->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($manager);
        $this->assertTrue(has_capability('report/ai_analysis:viewall', $context));
        $this->assertFalse(has_capability('report/ai_analysis:manageall', $context));
        $data = (new index_page((int) $this->course->id))->export_for_template($OUTPUT);
        $this->assertStringContainsString('Manager visible private report', $data->tablecontent);
        $xpath = $this->get_xpath($data->tablecontent);
        $this->assertCount(0, $xpath->query('//a[contains(@href, "action=delete") or contains(@href, "/rerun.php")' .
            ' or contains(@href, "/create.php")]'));

        // Listing creation controls reflect all three AI availability states.
        $this->prepare_test();
        foreach (self::creation_availability_provider() as [$state, $links, $buttons]) {
            $this->set_availability($state);
            $data = (new index_page((int) $this->course->id))->export_for_template($OUTPUT);
            $xpath = $this->get_xpath($OUTPUT->render_from_template('report_ai_analysis/index', $data));
            $this->assertCount($links, $xpath->query('//a[contains(@href, "/create.php")]'));
            $this->assertCount($buttons, $xpath->query('//button[@disabled]'));
        }
    }

    /**
     * Available, disabled and hidden creation controls.
     *
     * @return array
     */
    public static function creation_availability_provider(): array {
        return [
            'available' => ['available', 1, 0],
            'disabled' => ['disabled', 0, 1],
            'hidden' => ['hidden', 0, 0],
        ];
    }
}
