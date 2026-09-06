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
 * Tests safe report detail rendering and object-specific action controls.
 *
 * @package    report_ai_analysis
 * @category   test
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(view_page::class)]
final class view_page_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \context Report context. */
    private \context $context;

    /** @var \stdClass Report owner. */
    private \stdClass $owner;

    /** @var \stdClass Other teacher. */
    private \stdClass $other;

    /**
     * Explicitly prepare real report capabilities and independent AI availability.
     */
    private function prepare_test(): void {
        global $CFG, $PAGE, $OUTPUT;

        // A fresh page lets a single scenario test render more than one report.
        $PAGE = new \moodle_page();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course(['fullname' => 'Kurs Ä & B']);
        $this->context = \context_course::instance($this->course->id);
        $this->owner = $this->getDataGenerator()->create_user();
        $this->other = $this->getDataGenerator()->create_user();
        foreach ([$this->owner, $this->other] as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'editingteacher');
        }
        $this->setUser($this->owner);
        $PAGE->set_course($this->course);
        $PAGE->set_url('/report/ai_analysis/view.php');
        $OUTPUT = $PAGE->get_renderer('core');
        $CFG->forceclean = false;
        set_config('enable_markdown_conversion', 1, 'report_ai_analysis');
        set_config('share_reports_in_course', 0, 'report_ai_analysis');
        $this->set_availability('available');
    }

    /**
     * Release fixture records after each test.
     */
    protected function tearDown(): void {
        unset($this->course, $this->context, $this->owner, $this->other);
        parent::tearDown();
    }

    /**
     * Replace only the availability service, never the access policy.
     *
     * @param string $state Availability state
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
     * Create a completed report for the owner.
     *
     * @param array $fields Optional fields
     * @return \stdClass
     */
    private function create_report(array $fields = []): \stdClass {
        /** @var \report_ai_analysis_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('report_ai_analysis');
        return $generator->create_report($fields + [
            'contextid' => $this->context->id,
            'userid' => $this->owner->id,
            'resultformat' => FORMAT_HTML,
            'truncated' => 0,
            'ai_result' => '<p>Result.</p>',
        ]);
    }

    /**
     * Parse rendered output without depending on a browser process.
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
     * Rendered content, action authorization and availability behaviour for owner, foreign and manager access.
     */
    public function test_report_view_rendering_and_action_authorization(): void {
        global $CFG, $DB, $OUTPUT;

        // Owner content, action states and failed-view diagnostics stay safe across every report state.
        foreach (self::status_actions_provider() as [$status, $edit, $rerun, $cancel, $export]) {
            $this->prepare_test();
            $fields = ['status' => $status];
            if ($status === 'completed') {
                $fields += [
                    'title' => 'Analyse Ä & B <img src="x" onerror="titleProbe()">',
                    'prompt' => '**Safe prompt** <img src="x" onerror="promptProbe()">',
                    'ai_result' => '<p>Safe result</p><img src="x" onerror="resultProbe()">',
                    'raw_data' => '<script>rawProbe()</script>',
                    'truncated' => 1,
                    'scope_details' => json_encode([
                        'analysis_mode' => 'individual',
                        'filters' => ['sources' => ['cm_1'], 'participants' => [$this->owner->id]],
                    ]),
                ];
            }
            if ($status === 'failed') {
                $CFG->debugdeveloper = true;
                $CFG->debugdisplay = false;
                $fields += [
                    'error_code' => 'error_terms_not_accepted',
                    'error_message' => 'Private legacy failure marker',
                    'error_details' => 'Private diagnostic marker',
                ];
            }
            $report = $this->create_report($fields);
            $data = (new view_page($report, $this->context))->export_for_template($OUTPUT);
            $html = $OUTPUT->render_from_template('report_ai_analysis/view', $data);
            $xpath = $this->get_xpath($html);

            // Owner action states are consistent, and deletion of stored reports is always allowed.
            $this->assertSame($edit, $data->canedit);
            $this->assertSame($rerun, $data->canrerun);
            $this->assertSame($cancel, $data->cancancel);
            $this->assertSame($export, $data->canexport);
            $this->assertTrue($data->candelete);
            $this->assertCount($export ? 2 : 0, $xpath->query('//a[contains(@href, "/export.php")]'));
            if ($cancel) {
                $this->assertEquals($this->course->id, (new \moodle_url($data->cancelurl))->get_param('courseid'));
            }

            if ($status === 'completed') {
                // The real Mustache template escapes metadata/raw data and renders safe formatted content.
                $this->assertCount(0, $xpath->query('//script | //*[@onerror]'));
                $this->assertSame($report->title, $xpath->query('//h3')->item(0)->textContent);
                $this->assertSame('Kurs Ä & B', $data->contextname);
                $this->assertCount(3, $xpath->query('//ul[contains(@class, "report-ai-analysis-scope")]/li/strong'));
                $this->assertStringContainsString('<strong>Safe prompt</strong>', $html);
                $this->assertStringContainsString('<p>Safe result</p>', $html);
                $this->assertStringContainsString(s($report->raw_data), $html);
                $this->assertStringContainsString(get_string('analysis_truncated', 'report_ai_analysis'), $html);
                $this->assertEquals($this->course->id, (new \moodle_url($data->indexurl))->get_param('courseid'));
            }

            if ($status === 'failed') {
                // A failed view offers the terms action but never leaks legacy or current technical details.
                $this->assertTrue($data->canacceptaiterms);
                $this->assertFalse($data->haserrordetails);
                $this->assertStringNotContainsString('Private diagnostic marker', $html);
                $this->assertStringNotContainsString('Private legacy failure marker', $html);
            }
        }

        // Foreign and manager access follows sharing and each individual action capability.
        $this->prepare_test();
        $report = $this->create_report();

        // A private foreign report cannot even be prepared for rendering.
        $this->setUser($this->other);
        try {
            (new view_page($report, $this->context))->export_for_template($OUTPUT);
            $this->fail('Foreign access to a private report must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertInstanceOf(\moodle_exception::class, $e);
        }

        // Sharing grants reading only: never foreign edit, rerun, cancel or deletion.
        set_config('share_reports_in_course', 1, 'report_ai_analysis');
        $this->setUser($this->other);
        $data = (new view_page($report, $this->context))->export_for_template($OUTPUT);
        $this->assertFalse($data->canedit);
        $this->assertFalse($data->canrerun);
        $this->assertFalse($data->candelete);
        $this->assertFalse($data->cancancel);
        $this->assertTrue($data->canexport);
        $xpath = $this->get_xpath($OUTPUT->render_from_template('report_ai_analysis/view', $data));
        $this->assertCount(0, $xpath->query('//a[contains(@href, "action=delete") or contains(@href, "/rerun.php")' .
            ' or contains(@href, "/create.php") or contains(@href, "action=cancel")]'));

        // A manager needs manageall plus every specific action capability, independently.
        $manager = $this->getDataGenerator()->create_user();
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        $this->getDataGenerator()->enrol_user($manager->id, $this->course->id, $roleid);
        foreach (['create', 'rerun', 'delete'] as $capability) {
            assign_capability('report/ai_analysis:' . $capability, CAP_PROHIBIT, $roleid, $this->context->id, true);
            accesslib_clear_all_caches_for_unit_testing();
            $this->setUser($manager);
            $this->assertTrue(has_capability('report/ai_analysis:manageall', $this->context));
            $data = (new view_page($report, $this->context))->export_for_template($OUTPUT);
            $this->assertSame($capability !== 'create', $data->canedit);
            $this->assertSame($capability !== 'rerun', $data->canrerun);
            $this->assertSame($capability !== 'delete', $data->candelete);
            assign_capability('report/ai_analysis:' . $capability, CAP_INHERIT, $roleid, $this->context->id, true);
            accesslib_clear_all_caches_for_unit_testing();
        }

        // AI actions follow availability without preventing deletion of stored reports.
        foreach (self::unavailable_states_provider() as [$state]) {
            $this->prepare_test();
            $this->set_availability($state);
            $data = (new view_page($this->create_report(), $this->context))->export_for_template($OUTPUT);
            $xpath = $this->get_xpath($OUTPUT->render_from_template('report_ai_analysis/view', $data));
            $this->assertTrue($data->candelete);
            $this->assertCount(0, $xpath->query('//a[contains(@href, "/create.php") or contains(@href, "/rerun.php")]'));
            $this->assertCount($state === 'disabled' ? 2 : 0, $xpath->query('//button[@disabled]'));
            $this->assertCount(1, $xpath->query('//a[contains(@href, "action=delete")]'));
        }
    }

    /**
     * Owner state/action combinations including failed exports.
     *
     * @return array
     */
    public static function status_actions_provider(): array {
        return [
            'pending' => ['pending', true, false, true, false],
            'running' => ['running', false, false, true, false],
            'completed' => ['completed', true, true, false, true],
            'failed' => ['failed', true, true, false, true],
            'cancelled' => ['cancelled', true, true, false, false],
        ];
    }

    /**
     * Unavailable UI states.
     *
     * @return array
     */
    public static function unavailable_states_provider(): array {
        return ['disabled' => ['disabled'], 'hidden' => ['hidden']];
    }
}
