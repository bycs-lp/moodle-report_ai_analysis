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

namespace report_ai_analysis\local\provider;

use PHPUnit\Framework\Attributes\CoversClass;
use report_ai_analysis\data_collector;
use report_ai_analysis\error_info;
use report_ai_analysis\local\ai_log_provider;
use report_ai_analysis\scope_builder;

/**
 * Non-admin AI log authorisation, date privacy, scope and bounded-request tests.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(block_ai_chat_provider::class)]
final class block_ai_chat_provider_test extends \advanced_testcase {
    /** @var \stdClass Test course. */
    private \stdClass $course;

    /** @var \stdClass Non-admin executing teacher. */
    private \stdClass $teacher;

    /** @var \stdClass First enrolled author. */
    private \stdClass $author1;

    /** @var \stdClass Second enrolled author. */
    private \stdClass $author2;

    /** @var \context Source block context. */
    private \context $context;

    /** @var int Editing teacher role ID. */
    private int $teacherrole;

    /**
     * Create users and a course-owned AI Chat source.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->teacher = $generator->create_user(['firstname' => 'Chatviewer', 'lastname' => 'Teacher']);
        $this->author1 = $generator->create_user(['firstname' => 'Chatfirst', 'lastname' => 'Author']);
        $this->author2 = $generator->create_user(['firstname' => 'Chatsecond', 'lastname' => 'Author']);
        $generator->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $generator->enrol_user($this->author1->id, $this->course->id, 'student');
        $generator->enrol_user($this->author2->id, $this->course->id, 'student');
        $coursecontext = \context_course::instance($this->course->id);
        $block = $generator->create_block('ai_chat', ['parentcontextid' => $coursecontext->id]);
        $this->context = \context_block::instance($block->id);
        $this->teacherrole = (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
    }

    /**
     * Create real AI Manager logs through its public test generator.
     *
     * @param \stdClass $user Author record.
     * @param string $content Prompt marker.
     * @param int $time Timestamp.
     * @param array $options Additional log fields.
     * @return \stdClass Created log record.
     */
    private function log(\stdClass $user, string $content, int $time = 2000, array $options = []): \stdClass {
        /** @var \local_ai_manager_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ai_manager');
        return $generator->create_request_log_entry(array_merge([
            'userid' => $user->id,
            'contextid' => $this->context->id,
            'coursecontextid' => \context_course::instance($this->course->id)->id,
            'component' => 'block_ai_chat',
            'purpose' => 'chat',
            'itemid' => 123,
            'timecreated' => $time,
            'prompttext' => $content,
            'promptcompletion' => 'Response for ' . $content,
            'requestoptions' => json_encode(['conversationcontext' => [
                ['role' => 'user', 'content' => 'OUT_OF_SCOPE_CONTEXT_HISTORY'],
                ['role' => 'assistant', 'content' => 'DUPLICATED_CONTEXT_HISTORY'],
            ]]),
        ], $options));
    }

    /**
     * Use the real log reader while avoiding dependence on configured external AI purposes for formatting.
     *
     * @return ai_log_provider Partially mocked adapter.
     */
    private function adapter(): ai_log_provider {
        $adapter = $this->getMockBuilder(ai_log_provider::class)->onlyMethods(['format_purpose_output'])->getMock();
        $adapter->method('format_purpose_output')->willReturnCallback(static fn($purpose, $content) => $content);
        return $adapter;
    }

    /**
     * Build an explicit block selection for one or more enrolled users.
     *
     * @param array $userids Selected author identifiers.
     * @return scope_builder Scope builder.
     */
    private function scope(array $userids): scope_builder {
        return (new scope_builder($this->course->id))->with_sources(['block_' . $this->context->id])
            ->filter_by_participants($userids);
    }

    /**
     * Prohibit a capability at the precise block source context.
     *
     * @param string $capability Capability to prohibit.
     */
    private function prohibit(string $capability): void {
        assign_capability($capability, CAP_PROHIBIT, $this->teacherrole, $this->context->id, true);
    }

    /**
     * Chat authorisation, date privacy and group/hidden-source boundaries are all enforced fail-closed.
     *
     * Separate groups limit which authors a teacher may select, a hidden block position is neither discovered
     * nor selectable, foreign author content is readable without dates while only in-window chat records are
     * returned, own dates stay visible, and once viewprompts is prohibited foreign prompts fail closed.
     */
    public function test_authorisation_privacy_and_group_boundaries(): void {
        global $DB;

        // Separate groups limit which chat authors a teacher without accessallgroups may select.
        $DB->set_field('course', 'groupmode', SEPARATEGROUPS, ['id' => $this->course->id]);
        rebuild_course_cache($this->course->id, true);
        $generator = $this->getDataGenerator();
        $group1 = $generator->create_group(['courseid' => $this->course->id]);
        $group2 = $generator->create_group(['courseid' => $this->course->id]);
        groups_add_member($group1, $this->teacher);
        groups_add_member($group1, $this->author1);
        groups_add_member($group2, $this->author2);
        $this->log($this->author1, 'OWN_GROUP_CHAT');
        $this->log($this->author2, 'FOREIGN_GROUP_CHAT');
        $this->prohibit('moodle/site:accessallgroups');
        $this->setUser($this->teacher);
        $groupscope = $this->scope([$this->author1->id, $this->author2->id]);
        $groupdata = (new block_ai_chat_provider($groupscope, 100, $this->adapter()))->collect();
        $this->assertSame([(int)$this->author1->id], block_ai_chat_provider::get_user_ids($groupdata));
        $this->assertStringNotContainsString('FOREIGN_GROUP_CHAT', json_encode($groupdata));

        // A block hidden by a course position override is neither discovered nor readable when selected.
        $guard = $this->createMock(ai_log_provider::class);
        $guard->expects($this->never())->method('get_log_entries');
        $positionid = $DB->insert_record('block_positions', [
            'blockinstanceid' => $this->context->instanceid,
            'contextid' => \context_course::instance($this->course->id)->id,
            'pagetype' => 'course-view-topics', 'subpage' => '',
            'visible' => 0, 'region' => 'side-pre', 'weight' => 0,
        ]);
        $this->assertSame([], (new block_ai_chat_provider(new scope_builder($this->course->id), 100, $guard))->collect());
        try {
            (new block_ai_chat_provider($this->scope([$this->author1->id]), 100, $guard))->collect();
            $this->fail('A hidden block position was accepted as a source');
        } catch (\moodle_exception $exception) {
            $this->assertNotEmpty($exception->errorcode);
        }
        $DB->delete_records('block_positions', ['id' => $positionid]);

        // Foreign author content is readable without dates while only in-window chat records are returned.
        $this->log($this->author1, 'IN_WINDOW', 2000);
        $this->log($this->author1, 'OUT_OF_WINDOW', 4000);
        $this->log($this->author1, 'WRONG_PURPOSE', 2500, ['purpose' => 'singleprompt']);
        $this->log($this->teacher, 'OWN_DATED', 1234567890);
        $this->prohibit('local/ai_manager:viewpromptsdates');
        $foreign = (new block_ai_chat_provider($this->scope([$this->author1->id])->with_timerange(0, 3000), 100, $this->adapter()))
            ->collect();
        $this->assertFalse($foreign[0]['canviewdates']);
        $this->assertArrayNotHasKey('timecreated', $foreign[0]);
        $foreigntext = block_ai_chat_provider::format_for_ai($foreign);
        $this->assertStringContainsString('IN_WINDOW', $foreigntext);
        foreach (['OUT_OF_WINDOW', 'WRONG_PURPOSE', get_string('export_created', 'report_ai_analysis') . ':'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $foreigntext);
        }

        // Own dates stay visible and, once viewprompts is prohibited, foreign prompts fail closed.
        $this->prohibit('local/ai_manager:viewprompts');
        $own = (new block_ai_chat_provider($this->scope([$this->teacher->id]), 100, $this->adapter()))->collect();
        $this->assertTrue($own[0]['canviewdates']);
        $this->assertSame(1234567890, $own[0]['timecreated']);
        $auto = (new scope_builder($this->course->id))->filter_by_participants([$this->teacher->id, $this->author1->id]);
        $autodata = (new block_ai_chat_provider($auto, 100, $this->adapter()))->collect();
        $this->assertSame([(int)$this->teacher->id], block_ai_chat_provider::get_user_ids($autodata));
        $this->assertStringNotContainsString('IN_WINDOW', json_encode($autodata));
        $forbidden = $this->createMock(ai_log_provider::class);
        $forbidden->expects($this->never())->method('get_log_entries');
        try {
            (new block_ai_chat_provider($this->scope([$this->author1->id]), 100, $forbidden))->collect();
            $this->fail('Foreign prompts were accepted without the source permission');
        } catch (\moodle_exception $exception) {
            $this->assertSame('error_source_forbidden', $exception->errorcode);
        }
    }

    /**
     * Bounded reads, a shared request budget, localised failures and mixed sources are all enforced.
     *
     * A shared budget bounds every read before retrieval and a source selected after it is spent is still
     * permission-checked, long messages are shortened UTF-8-safely, an out-of-range date-window scan stops at
     * the hard limit, a log failure stays localised without leaking diagnostics, and a mixed forum/chat
     * collection shares one budget while keeping each source shape separate.
     */
    public function test_bounded_reads_budget_and_source_failures(): void {
        global $CFG;

        $this->expectOutputString('');
        $this->setUser($this->teacher);
        $generator = $this->getDataGenerator();

        // A shared budget bounds every read across users before retrieval.
        $this->log($this->author1, 'FIRST_REQUEST', 1000);
        $this->log($this->author1, 'SECOND_REQUEST', 2000);
        $this->log($this->author2, 'OTHER_AUTHOR_REQUEST', 3000);
        $scope = (new scope_builder($this->course->id))->filter_by_participants([$this->author1->id, $this->author2->id]);
        $realadapter = new ai_log_provider();
        $adapter = $this->getMockBuilder(ai_log_provider::class)
            ->onlyMethods(['get_log_entries', 'format_purpose_output'])->getMock();
        $adapter->method('format_purpose_output')->willReturnCallback(static fn($purpose, $content) => $content);
        $adapter->expects($this->exactly(2))->method('get_log_entries')->willReturnCallback(
            function (...$args) use ($realadapter): array {
                $this->assertGreaterThan(0, $args[7]);
                $this->assertFalse($args[4]);
                $this->assertStringNotContainsString('requestoptions', $args[5]);
                return $realadapter->get_log_entries(...$args);
            }
        );
        $budgetprovider = new block_ai_chat_provider($scope, 2, $adapter);
        $budgetdata = $budgetprovider->collect();
        $this->assertSame(2, block_ai_chat_provider::count_records($budgetdata));
        $this->assertSame([(int)$this->author1->id], block_ai_chat_provider::get_user_ids($budgetdata));
        $this->assertTrue($budgetprovider->is_truncated());

        // A source selected after the budget is spent is still permission-checked before any read.
        $forbiddenblock = $generator->create_block('ai_chat', [
            'parentcontextid' => \context_course::instance($this->course->id)->id,
        ]);
        $forbiddencontext = \context_block::instance($forbiddenblock->id);
        assign_capability('local/ai_manager:viewprompts', CAP_PROHIBIT, $this->teacherrole, $forbiddencontext->id, true);
        $selectionscope = $this->scope([$this->author1->id])
            ->with_sources(['block_' . $this->context->id, 'block_' . $forbiddencontext->id]);
        $guard = $this->createMock(ai_log_provider::class);
        $guard->expects($this->never())->method('get_log_entries');
        try {
            (new block_ai_chat_provider($selectionscope, 1, $guard))->collect();
            $this->fail('A selected forbidden source was skipped because of its collection order');
        } catch (\moodle_exception $exception) {
            $this->assertSame('error_source_forbidden', $exception->errorcode);
        }

        // A long chat message is shortened on a character boundary and reported as truncated.
        $this->log($this->author1, str_repeat('a', 1999) . 'äÖEND', 5000, ['promptcompletion' => 'Short response']);
        $utf8provider = new block_ai_chat_provider(
            $this->scope([$this->author1->id])->with_timerange(5000, 5000),
            100,
            $this->adapter()
        );
        $utf8text = block_ai_chat_provider::format_for_ai($utf8provider->collect());
        $this->assertStringContainsString(str_repeat('a', 1999) . 'ä', $utf8text);
        $this->assertStringNotContainsString('ÖEND', $utf8text);
        $this->assertTrue($utf8provider->is_truncated());

        // A date-window scan that only sees out-of-range records still stops at the hard limit and reports exhaustion.
        $entry = (object)[
            'id' => 1, 'userid' => $this->author1->id, 'contextid' => $this->context->id,
            'component' => 'block_ai_chat', 'purpose' => 'chat', 'timecreated' => 3000, 'deleted' => 0,
        ];
        $scanadapter = $this->createMock(ai_log_provider::class);
        $scanadapter->expects($this->once())->method('get_log_entries')->with(
            'block_ai_chat',
            $this->context->id,
            $this->author1->id,
            0,
            false,
            $this->isType('string'),
            ['chat'],
            base_provider::HARD_RECORD_LIMIT + 1
        )->willReturn(array_fill(0, base_provider::HARD_RECORD_LIMIT + 1, $entry));
        $scanprovider = new block_ai_chat_provider(
            $this->scope([$this->author1->id])->with_timerange(0, 2000),
            1,
            $scanadapter
        );
        $this->assertSame([], $scanprovider->collect());
        $this->assertTrue($scanprovider->is_truncated());

        // A log source failure stays localised and never leaks its private diagnostic into the safe description.
        $CFG->debug = DEBUG_DEVELOPER;
        $CFG->debugdeveloper = true;
        $CFG->debugdisplay = true;
        $failadapter = $this->createMock(ai_log_provider::class);
        $failadapter->method('get_log_entries')->willThrowException(new \RuntimeException('PRIVATE_LOG_FAILURE'));
        try {
            (new block_ai_chat_provider($this->scope([$this->author1->id]), 100, $failadapter))->collect();
            $this->fail('Log source failure was suppressed');
        } catch (\moodle_exception $exception) {
            $this->assertSame('error_source_failed', $exception->errorcode);
            // Moodle deliberately retains diagnostics in the exception message during PHPUnit execution.
            $this->assertStringContainsString('PRIVATE_LOG_FAILURE', $exception->debuginfo);
            $this->assertStringNotContainsString('PRIVATE_LOG_FAILURE', error_info::get_description($exception->errorcode));
            $details = error_info::get_debug_details($exception->errorcode, $exception->getMessage(), $exception->debuginfo);
            $this->assertSame(error_info::can_show_debug_details() ? trim($exception->debuginfo) : null, $details);
        }

        // A mixed forum/chat collection shares one budget and keeps each source shape separate.
        $forum = $generator->create_module('forum', ['course' => $this->course->id]);
        /** @var \mod_forum_generator $forumgenerator */
        $forumgenerator = $generator->get_plugin_generator('mod_forum');
        $forumgenerator->create_discussion([
            'course' => $this->course->id, 'forum' => $forum->id, 'userid' => $this->author1->id,
            'name' => 'FORUM_TITLE', 'message' => 'FIRST_FORUM_POST',
        ]);
        $mixedscope = (new scope_builder($this->course->id))->filter_by_participants([$this->author1->id, $this->author2->id]);
        $chat = new block_ai_chat_provider($mixedscope, 10, $this->adapter());
        $forumprovider = new mod_forum_provider($mixedscope, 10);
        $factory = $this->createMock(provider_factory::class);
        $factory->method('get_all_providers')->willReturn([$chat, $forumprovider]);
        $factory->method('discover_providers')->willReturn([block_ai_chat_provider::class, mod_forum_provider::class]);
        $collector = new data_collector($mixedscope, 10, $factory);
        $mixed = $collector->collect();
        $this->assertArrayHasKey('block_ai_chat', $mixed);
        $this->assertArrayHasKey('mod_forum', $mixed);
        $userdata = $collector->get_user_data($mixed);
        $this->assertStringContainsString('FIRST_FORUM_POST', $userdata[$this->author1->id]);
        $this->assertStringNotContainsString('Chatsecond', $userdata[$this->author1->id]);
    }
}
