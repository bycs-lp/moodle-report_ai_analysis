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

namespace report_ai_analysis\form;

use report_ai_analysis\scope_builder;
use report_ai_analysis\template_manager;

/**
 * Regression tests for validated and lossless analysis form data.
 *
 * @package    report_ai_analysis
 * @category   test
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(create_form::class)]
final class create_form_test extends \advanced_testcase {
    /** @var \stdClass Course used by the form. */
    private \stdClass $course;

    /**
     * Explicitly prepare the course needed by a form test, without an AI backend.
     */
    private function prepare_test(): void {
        global $PAGE;

        // A fresh page lets a single scenario test render more than one form.
        $PAGE = new \moodle_page();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $PAGE->set_course($this->course);
        $PAGE->set_url('/report/ai_analysis/create.php', ['courseid' => $this->course->id]);
    }

    /**
     * Release fixtures rather than retaining them for the lifetime of the suite.
     */
    protected function tearDown(): void {
        unset($this->course);
        parent::tearDown();
    }

    /**
     * Construct a form with explicitly supplied, server-side test availability.
     *
     * @param array $customdata Custom form data overrides
     * @return create_form
     */
    private function get_form(array $customdata = []): create_form {
        $templates = $this->createMock(template_manager::class);
        $templates->method('get_enabled_templates')->willReturn([]);
        return new create_form(null, $customdata + [
            'id' => (int) $this->course->id,
            'availability' => ['state' => 'available', 'message' => ''],
            'templatemanager' => $templates,
        ]);
    }

    /**
     * Parse UTF-8 output as a browser would, without fetching external resources.
     *
     * @param string $html Rendered form
     * @return \DOMXPath
     */
    private function get_xpath(string $html): \DOMXPath {
        $document = new \DOMDocument();
        $document->loadHTML('<meta charset="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        return new \DOMXPath($document);
    }

    /**
     * Title/prompt validation and optional, closed or reversed date boundaries round-trip or fail localized.
     */
    public function test_title_prompt_and_date_rules(): void {
        $this->prepare_test();

        // Length validation counts characters, not UTF-8 bytes, and treats "0" as a real title.
        $cases = [
            [['title' => '', 'prompt' => str_repeat('ä', 9)], ['prompt']],
            [['title' => '', 'prompt' => str_repeat('ä', 10)], []],
            [['title' => '', 'prompt' => str_repeat('😀', 10001)], ['prompt']],
            [['title' => str_repeat('ä', 255), 'prompt' => 'Analyze this discussion.'], []],
            [['title' => str_repeat('ä', 256), 'prompt' => 'Analyze this discussion.'], ['title']],
            [['title' => '0', 'prompt' => 'Analyze this discussion.'], []],
        ];
        foreach ($cases as [$input, $errorfields]) {
            $this->assertSame($errorfields, array_keys($this->get_form()->validation($input, [])));
        }

        // A generated title from the prompt never splits a multibyte character.
        $prompt = str_repeat('x', 79) . 'ä😀 and more text';
        create_form::mock_submit(['title' => '', 'prompt' => $prompt, 'all_participants' => 1]);
        $data = $this->get_form()->get_data();
        $this->assertNotNull($data);
        $this->assertSame(str_repeat('x', 79) . 'ä', $data->title);
        $this->assertSame(80, \core_text::strlen($data->title));

        // An explicitly entered zero is preserved instead of being regenerated.
        create_form::mock_submit(['title' => '0', 'prompt' => 'Analyze this discussion.', 'all_participants' => 1]);
        $zerodata = $this->get_form()->get_data();
        $this->assertNotNull($zerodata);
        $this->assertSame('0', $zerodata->title);

        // Client-side maxlength is not the only guard against an oversized submitted title.
        create_form::mock_submit(['title' => str_repeat('ä', 256), 'prompt' => 'Analyze this discussion.']);
        $this->assertNull($this->get_form()->get_data());

        // Clear the submitted state left by the mock_submit cases so set_data drives rendering.
        $_POST = [];
        // Optional, closed and reversed date boundaries round-trip or fail with a localized error.
        foreach (self::date_ranges_provider() as [$start, $end, $reversed]) {
            $form = $this->get_form();
            $input = [
                'title' => 'Date range',
                'prompt' => 'Analyze this discussion.',
                'all_participants' => 1,
                'timestart' => $start,
                'timeend' => $end,
            ];
            if ($reversed) {
                // Reversed dates produce a localized form error rather than a builder exception.
                $this->assertSame(
                    ['timeend' => get_string('error_invalid_timerange', 'report_ai_analysis')],
                    $form->validation($input, [])
                );
                continue;
            }
            $this->assertSame([], $form->validation($input, []));
            $scope = $form->get_scope((object) $input);
            $defaults = create_form::get_initial_data((object) [
                'title' => $input['title'],
                'prompt' => $input['prompt'],
                'scope_details' => $scope->build(),
            ]);
            $this->assertSame($start, $defaults->timestart);
            $this->assertSame($end, $defaults->timeend);
            $this->assertSame($scope->build(), $form->get_scope($defaults)->build());
            $form->set_data($defaults);
            $xpath = $this->get_xpath($form->render());
            foreach (['timestart' => $start, 'timeend' => $end] as $field => $value) {
                $checkbox = $xpath->query('//input[@name="' . $field . '[enabled]"]')->item(0);
                $this->assertInstanceOf(\DOMElement::class, $checkbox);
                $this->assertSame($value !== 0, $checkbox->hasAttribute('checked'));
            }
        }
    }

    /**
     * Optional, closed and reversed date ranges.
     *
     * @return array
     */
    public static function date_ranges_provider(): array {
        return [
            'no boundaries' => [0, 0, false],
            'start only' => [1788678000, 0, false],
            'closed interval' => [1788678000, 1788764400, false],
            'reversed interval' => [1788764400, 1788678000, true],
        ];
    }

    /**
     * Scope filters round-trip, and template, source and availability controls limit submission.
     */
    public function test_scope_template_and_availability_controls(): void {
        global $DB;

        // Role, participant, group, source, mode and legacy filters survive an unchanged edit.
        $this->prepare_test();
        $form = $this->get_form();

        // An unoccupied role stays a restriction rather than becoming all participants.
        $emptyrole = (int) $this->getDataGenerator()->create_role(
            ['shortname' => 'emptyanalysis', 'name' => 'Empty analysis role']
        );
        $scope = $form->get_scope((object) ['all_participants' => 1, 'roleids' => [$emptyrole]]);
        $defaults = create_form::get_initial_data((object) [
            'title' => 'Role filter',
            'prompt' => 'Analyze this discussion.',
            'scope_details' => $scope->build(),
        ]);
        $this->assertSame([$emptyrole], $defaults->roleids);
        $this->assertSame(1, $defaults->all_participants);
        $this->assertSame([], $form->get_scope($defaults)->get_participants_in_scope());

        // Participant, role, group, source and mode restrictions all survive together.
        $participant = $this->getDataGenerator()->create_user();
        $studentrole = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $this->getDataGenerator()->enrol_user($participant->id, $this->course->id, $studentrole);
        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $participant->id]);
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id]);
        $combined = (object) [
            'title' => 'Combined filters',
            'prompt' => 'Analyze this discussion.',
            'analysis_mode' => scope_builder::ANALYSIS_MODE_INDIVIDUAL,
            'all_participants' => 0,
            'participants' => [(int) $participant->id],
            'roleids' => [$studentrole],
            'sources' => ['cm_' . $forum->cmid],
            'groups' => [(int) $group->id],
        ];
        $scope = $form->get_scope($combined);
        $defaults = create_form::get_initial_data((object) [
            'title' => $combined->title,
            'prompt' => $combined->prompt,
            'scope_details' => $scope->build(),
        ]);
        foreach (['analysis_mode', 'all_participants', 'participants', 'roleids', 'sources', 'groups'] as $field) {
            $this->assertSame($combined->{$field}, $defaults->{$field});
        }
        $this->assertSame($scope->build(), $form->get_scope($defaults)->build());

        // Legacy participant/role fields load into the current form rather than disappearing.
        $legacy = create_form::get_initial_data((object) [
            'title' => 'Legacy report',
            'prompt' => 'Analyze this discussion.',
            'scope_details' => json_encode(['filters' => ['students' => [17], 'roles' => [5]]]),
        ]);
        $this->assertSame([17], $legacy->participants);
        $this->assertSame([5], $legacy->roleids);
        $this->assertSame(0, $legacy->all_participants);

        // Template rendering stays lossless, and source and availability controls limit submission.
        $this->prepare_test();

        // A template presentation preserves UTF-8 attributes exactly after DOM decoding.
        $prompt = "Analyse \"A & B\" – ä 😀\n<em>Text</em> &quot;";
        $title = 'Vorlage "Ä & B" <Example>';
        $templates = [];
        for ($id = 1; $id <= 6; $id++) {
            $templates[$id] = (object) ['id' => $id, 'title' => $title, 'prompt' => $prompt];
        }
        $manager = $this->createMock(template_manager::class);
        $manager->method('get_enabled_templates')->willReturn($templates);
        $xpath = $this->get_xpath($this->get_form(['templatemanager' => $manager])->render());
        $nodes = $xpath->query('//*[@data-prompt]');
        $this->assertCount(6, $nodes);
        foreach ($nodes as $node) {
            $this->assertInstanceOf(\DOMElement::class, $node);
            $this->assertSame($prompt, $node->getAttribute('data-prompt'));
            $this->assertSame($title, $node->textContent);
        }
        $this->assertCount(6, $xpath->query('//option[@data-prompt]'));

        // Only a supported, visible forum the user may read is selectable as a source.
        $teacher = $this->getDataGenerator()->create_user();
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, $roleid);
        $visible = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id]);
        $hidden = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id, 'visible' => 0]);
        $denied = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id]);
        $othercourse = $this->getDataGenerator()->create_course();
        $otherforum = $this->getDataGenerator()->create_module('forum', ['course' => $othercourse->id]);
        $context = \context_course::instance($this->course->id);
        assign_capability('moodle/course:viewhiddenactivities', CAP_PROHIBIT, $roleid, $context->id);
        assign_capability('mod/forum:viewdiscussion', CAP_PROHIBIT, $roleid, \context_module::instance($denied->cmid)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($teacher);
        $form = $this->get_form();
        $xpath = $this->get_xpath($form->render());
        $this->assertCount(1, $xpath->query('//select[@name="sources[]"]/option[@value="cm_' . $visible->cmid . '"]'));
        foreach ([$hidden, $denied, $otherforum] as $excluded) {
            $this->assertCount(0, $xpath->query('//select[@name="sources[]"]/option[@value="cm_' . $excluded->cmid . '"]'));
        }
        $errors = $form->validation(['prompt' => 'Analyze this discussion.', 'sources' => ['cm_' . $otherforum->cmid]], []);
        $this->assertArrayHasKey('sources', $errors);

        // A disabled form blocks native fields and server submission, but keeps Cancel usable.
        create_form::mock_submit(['title' => 'Forged submission', 'prompt' => 'Analyze this discussion.']);
        $disabled = $this->get_form(['availability' => ['state' => 'disabled', 'message' => 'Unavailable']]);
        $this->assertNull($disabled->get_data());
        $xpath = $this->get_xpath($disabled->render());
        foreach (['title', 'prompt', 'submitbutton', 'timestart[enabled]'] as $name) {
            $node = $xpath->query('//*[@name="' . $name . '"]')->item(0);
            $this->assertInstanceOf(\DOMElement::class, $node);
            $this->assertTrue($node->hasAttribute('disabled'), $name);
        }
        $cancel = $xpath->query('//*[@name="cancel"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $cancel);
        $this->assertFalse($cancel->hasAttribute('disabled'));

        // Hidden availability exposes neither editable fields nor an analysis submit action.
        create_form::mock_submit(['title' => 'Forged submission', 'prompt' => 'Analyze this discussion.']);
        $hiddenform = $this->get_form(['availability' => ['state' => 'hidden', 'message' => '']]);
        $this->assertNull($hiddenform->get_data());
        $xpath = $this->get_xpath($hiddenform->render());
        $this->assertCount(0, $xpath->query('//*[@name="title" or @name="prompt" or @name="submitbutton"]'));
    }
}
