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
 * Integration tests for data_collector.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis;

use core_text;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use report_ai_analysis\local\provider\base_provider;
use report_ai_analysis\local\provider\block_ai_chat_provider;
use report_ai_analysis\local\provider\fixtures\non_handling_provider;
use report_ai_analysis\local\provider\fixtures\succeeding_provider;
use report_ai_analysis\local\provider\fixtures\testable_provider;
use report_ai_analysis\local\provider\mod_forum_provider;
use report_ai_analysis\local\provider\provider_factory;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/local/provider/fixtures/testable_provider.php');
require_once(__DIR__ . '/local/provider/fixtures/non_handling_provider.php');
require_once(__DIR__ . '/local/provider/fixtures/succeeding_provider.php');

/**
 * Test class for data_collector.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(data_collector::class)]
#[CoversClass(mod_forum_provider::class)]
#[Group('local_mbs')]
#[Group('mebis')]
final class data_collector_test extends \advanced_testcase {
    /** @var provider_factory&MockObject Mock provider factory for isolated collector tests. */
    private provider_factory&MockObject $factory;

    /** @var scope_builder&MockObject Mock scope whose source resolution is not under test in isolation. */
    private scope_builder&MockObject $scope;

    /**
     * Prepare isolated collector dependencies.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->factory = $this->createMock(provider_factory::class);
        $this->scope = $this->getMockBuilder(scope_builder::class)->disableOriginalConstructor()->getMock();
    }

    /**
     * Real forum collection produces structured, formatted, time-filtered and per-author data with no-data guards.
     *
     * A course without available sources is a no-data error, a selected forum returns structured discussions
     * with matching formatting, statistics and time filtering, individual mode keeps each author's own posts
     * without copying another author's metadata, an unselected provider is never collected, a selected source
     * with no handling provider fails, and a bounded scan with no records is a source error not an empty result.
     */
    public function test_real_collection_and_source_selection(): void {
        $this->setAdminUser();
        $generator = $this->getDataGenerator();

        // No available sources is a no-data error.
        $emptycourse = $generator->create_course();
        try {
            (new data_collector(new scope_builder($emptycourse->id)))->collect();
            $this->fail('Empty collection was accepted');
        } catch (\moodle_exception $exception) {
            $this->assertSame('error_no_data', $exception->errorcode);
        }

        // A selected forum returns structured, formatted data and a time range keeps only matching discussions.
        $course = $generator->create_course();
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id);
        /** @var \mod_forum_generator $forumgenerator */
        $forumgenerator = $generator->get_plugin_generator('mod_forum');
        $forum = $generator->create_module('forum', ['course' => $course->id]);
        $now = time();
        $forumgenerator->create_discussion([
            'course' => $course->id, 'forum' => $forum->id, 'userid' => $user->id,
            'name' => 'Today Discussion', 'message' => 'Test message content', 'timemodified' => $now,
        ]);
        $forumgenerator->create_discussion([
            'course' => $course->id, 'forum' => $forum->id, 'userid' => $user->id,
            'name' => 'Old Discussion', 'timemodified' => $now - 2 * DAYSECS,
        ]);
        $collector = new data_collector((new scope_builder($course->id))->with_sources(['cm_' . $forum->cmid]));
        $data = $collector->collect();
        $this->assertCount(2, $data['mod_forum']);
        $this->assertArrayHasKey('posts', $data['mod_forum'][0]);
        $this->assertStringContainsString('Test message content', $collector->format_for_ai($data));
        $stats = $collector->get_statistics($data);
        $this->assertSame(1, $stats['total_sources']);
        $this->assertArrayHasKey('mod_forum', $stats);
        $recent = new data_collector((new scope_builder($course->id))->with_sources(['cm_' . $forum->cmid])
            ->with_timerange($now - DAYSECS, $now + DAYSECS));
        $recentdata = $recent->collect();
        $this->assertCount(1, $recentdata['mod_forum']);
        $this->assertSame('Today Discussion', $recentdata['mod_forum'][0]['title']);

        // Individual mode yields per-author strings that never copy another author's posts or names.
        $alice = $generator->create_user(['firstname' => 'AliceRoot', 'lastname' => 'Author']);
        $bob = $generator->create_user(['firstname' => 'BobReply', 'lastname' => 'Author']);
        $generator->enrol_user($alice->id, $course->id, 'student');
        $generator->enrol_user($bob->id, $course->id, 'student');
        $indivforum = $generator->create_module('forum', ['course' => $course->id]);
        $discussion = $forumgenerator->create_discussion([
            'course' => $course->id, 'forum' => $indivforum->id, 'userid' => $alice->id,
            'name' => 'ALICE_ROOT_TITLE', 'message' => 'ALICE_ROOT_CONTENT',
        ]);
        $forumgenerator->create_post([
            'discussion' => $discussion->id, 'parent' => $discussion->firstpost, 'userid' => $bob->id,
            'subject' => 'BOB_SUBJECT', 'message' => 'BOB_CONTENT',
        ]);
        $indivscope = (new scope_builder($course->id))->with_sources(['cm_' . $indivforum->cmid])
            ->set_analysis_mode(scope_builder::ANALYSIS_MODE_INDIVIDUAL);
        $indivcollector = new data_collector($indivscope);
        $indivdata = $indivcollector->collect();
        $frozen = json_encode($indivdata);
        $userdata = $indivcollector->get_user_data($indivdata);
        $this->assertEqualsCanonicalizing([(int)$alice->id, (int)$bob->id], array_keys($userdata));
        $this->assertStringContainsString('ALICE_ROOT_CONTENT', $userdata[$alice->id]);
        $this->assertStringNotContainsString('BOB_', $userdata[$alice->id]);
        $this->assertStringNotContainsString('AliceRoot', $userdata[$bob->id]);
        $this->assertSame(1, mod_forum_provider::count_records($indivcollector->filter_by_user($indivdata, $bob->id)['mod_forum']));
        $this->assertSame([], $indivcollector->filter_by_user($indivdata, 999999));
        $this->assertSame($frozen, json_encode($indivdata), 'Filtering must not mutate shared input hierarchies');

        // A provider that returns nothing and a factory with no providers are both no-data errors.
        $empty = $this->getMockBuilder(testable_provider::class)
            ->disableOriginalConstructor()->onlyMethods(['collect'])->getMock();
        $empty->method('collect')->willReturn([]);
        foreach ([[$empty], []] as $providers) {
            $factory = $this->createMock(provider_factory::class);
            $factory->method('get_all_providers')->willReturn($providers);
            try {
                (new data_collector($this->scope, 1000, $factory))->collect();
                $this->fail('Empty collection was accepted');
            } catch (\moodle_exception $exception) {
                $this->assertSame('error_no_data', $exception->errorcode);
            }
        }

        // Selected provider keys are preserved and an unselected provider is never collected.
        $records = [['id' => 1, 'content' => 'Included content']];
        $selected = $this->getMockBuilder(testable_provider::class)
            ->disableOriginalConstructor()->onlyMethods(['collect'])->getMock();
        $selected->expects($this->once())->method('collect')->willReturn($records);
        $unselected = $this->getMockBuilder(non_handling_provider::class)
            ->disableOriginalConstructor()->onlyMethods(['collect'])->getMock();
        $unselected->expects($this->never())->method('collect');
        $selectionscope = $this->getMockBuilder(scope_builder::class)->disableOriginalConstructor()->getMock();
        $selectionscope->method('get_sources_in_scope')->willReturn(['cm_123']);
        $selectionfactory = $this->createMock(provider_factory::class);
        $selectionfactory->method('get_all_providers')->willReturn([$selected, $unselected]);
        $this->assertSame(
            ['test_provider' => $records],
            (new data_collector($selectionscope, 1000, $selectionfactory))->collect()
        );

        // A selected source with no handling provider fails instead of being silently skipped.
        $unhandledfactory = $this->createMock(provider_factory::class);
        $unhandledfactory->method('get_all_providers')->willReturn([new non_handling_provider($selectionscope)]);
        try {
            (new data_collector($selectionscope, 1000, $unhandledfactory))->collect();
            $this->fail('Selected unavailable source was ignored');
        } catch (\moodle_exception $exception) {
            $this->assertSame('error_source_failed', $exception->errorcode);
        }

        // A bounded scan with no matching records is a source error, not an ordinary empty result.
        $incomplete = $this->getMockBuilder(testable_provider::class)->disableOriginalConstructor()
            ->onlyMethods(['collect', 'is_truncated'])->getMock();
        $incomplete->method('collect')->willReturn([]);
        $incomplete->method('is_truncated')->willReturn(true);
        $incompletefactory = $this->createMock(provider_factory::class);
        $incompletefactory->method('get_all_providers')->willReturn([$incomplete]);
        try {
            (new data_collector($this->scope, 1, $incompletefactory))->collect();
            $this->fail('An incomplete scan was reported as an ordinary no-data result');
        } catch (\moodle_exception $exception) {
            $this->assertSame('error_source_failed', $exception->errorcode);
        }
    }

    /**
     * The shared analysis budget bounds requests and posts, failures stay localised and formatting is bounded.
     *
     * Requests and nested posts share one budget, an exhausted budget is passed as zero, an unbounded budget is
     * capped and a non-positive budget rejected, an over-budget provider fails, a source or discovery failure is
     * localised without leaking diagnostics, and the formatted text has a UTF-8-safe character bound while a
     * missing formatter is a source error.
     */
    public function test_budget_failures_and_formatting(): void {
        global $CFG;

        // One chat request plus two nested forum posts consume the same three-record budget.
        $chat = $this->getMockBuilder(block_ai_chat_provider::class)->disableOriginalConstructor()
            ->onlyMethods(['collect', 'set_maxrecords'])->getMock();
        $chat->expects($this->once())->method('set_maxrecords')->with(3);
        $chat->method('collect')->willReturn([['messages' => [['role' => 'user'], ['role' => 'assistant']]]]);
        $forum = $this->getMockBuilder(mod_forum_provider::class)->disableOriginalConstructor()
            ->onlyMethods(['collect', 'set_maxrecords', 'is_truncated'])->getMock();
        $forum->expects($this->once())->method('set_maxrecords')->with(2);
        $forum->method('collect')->willReturn([['posts' => [['replies' => [['replies' => []]]]]]]);
        $forum->method('is_truncated')->willReturn(true);
        $budgetfactory = $this->createMock(provider_factory::class);
        $budgetfactory->method('get_all_providers')->willReturn([$chat, $forum]);
        $budgetcollector = new data_collector($this->scope, 3, $budgetfactory);
        $budgetdata = $budgetcollector->collect();
        $this->assertSame(1, block_ai_chat_provider::count_records($budgetdata['block_ai_chat']));
        $this->assertSame(2, mod_forum_provider::count_records($budgetdata['mod_forum']));
        $this->assertTrue($budgetcollector->is_truncated());

        // An exhausted budget is passed as zero, not reset to a provider's default.
        $first = $this->getMockBuilder(testable_provider::class)->disableOriginalConstructor()
            ->onlyMethods(['collect'])->getMock();
        $first->method('collect')->willReturn([['content' => 'only record']]);
        $second = $this->getMockBuilder(succeeding_provider::class)->disableOriginalConstructor()
            ->onlyMethods(['collect', 'set_maxrecords', 'is_truncated'])->getMock();
        $second->expects($this->once())->method('set_maxrecords')->with(0);
        $second->method('collect')->willReturn([]);
        $second->method('is_truncated')->willReturn(true);
        $exhaustedfactory = $this->createMock(provider_factory::class);
        $exhaustedfactory->method('get_all_providers')->willReturn([$first, $second]);
        $exhaustedcollector = new data_collector($this->scope, 1, $exhaustedfactory);
        $this->assertCount(1, $exhaustedcollector->collect()['test_provider']);
        $this->assertTrue($exhaustedcollector->is_truncated());

        // An unbounded budget is capped to the hard record limit before providers receive it.
        $capfactory = $this->createMock(provider_factory::class);
        $capfactory->expects($this->once())->method('get_all_providers')
            ->with($this->scope, base_provider::HARD_RECORD_LIMIT)->willReturn([]);
        try {
            (new data_collector($this->scope, PHP_INT_MAX, $capfactory))->collect();
            $this->fail('An unbounded budget was accepted');
        } catch (\moodle_exception $exception) {
            $this->assertSame('error_no_data', $exception->errorcode);
        }

        // A non-positive budget is rejected in the constructor.
        try {
            new data_collector($this->scope, 0, $this->factory);
            $this->fail('A zero budget was accepted');
        } catch (\coding_exception $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        // A provider that exceeds its budget fails rather than silently slicing unbounded data.
        $overbudget = $this->getMockBuilder(testable_provider::class)
            ->disableOriginalConstructor()->onlyMethods(['collect'])->getMock();
        $overbudget->method('collect')->willReturn([['content' => 'one'], ['content' => 'two']]);
        $overbudgetfactory = $this->createMock(provider_factory::class);
        $overbudgetfactory->method('get_all_providers')->willReturn([$overbudget]);
        try {
            (new data_collector($this->scope, 1, $overbudgetfactory))->collect();
            $this->fail('An over-budget provider was accepted');
        } catch (\moodle_exception $exception) {
            $this->assertSame('error_source_failed', $exception->errorcode);
        }

        // A source failure and a discovery failure are localised without leaking private diagnostics.
        $this->expectOutputString('');
        $CFG->debug = DEBUG_DEVELOPER;
        $CFG->debugdeveloper = true;
        $CFG->debugdisplay = true;
        $failure = $this->getMockBuilder(testable_provider::class)
            ->disableOriginalConstructor()->onlyMethods(['collect'])->getMock();
        $failure->method('collect')->willThrowException(new \RuntimeException('PRIVATE_DIAGNOSTIC'));
        $failurefactory = $this->createMock(provider_factory::class);
        $failurefactory->method('get_all_providers')->willReturn([$failure]);
        $this->assert_localised_failure(new data_collector($this->scope, 1000, $failurefactory), 'PRIVATE_DIAGNOSTIC');
        $discoveryfactory = $this->createMock(provider_factory::class);
        $discoveryfactory->method('get_all_providers')
            ->willThrowException(new \RuntimeException('PRIVATE_DISCOVERY_FAILURE'));
        $this->assert_localised_failure(new data_collector($this->scope, 1000, $discoveryfactory), 'PRIVATE_DISCOVERY_FAILURE');

        // Empty data has empty statistics, and a missing formatter for an included source is a source error.
        $formatfactory = $this->createMock(provider_factory::class);
        $formatfactory->method('discover_providers')->willReturn([testable_provider::class]);
        $formatcollector = new data_collector($this->scope, 1000, $formatfactory);
        $this->assertSame(['total_sources' => 0], $formatcollector->get_statistics([]));
        $text = $formatcollector->format_for_ai(['test_provider' => [
            ['content' => str_repeat('ä', data_collector::MAX_FORMATTED_LENGTH + 1)],
        ]]);
        $this->assertSame(data_collector::MAX_FORMATTED_LENGTH, core_text::strlen($text));
        $this->assertStringContainsString(get_string('export_truncated', 'report_ai_analysis'), $text);
        $missingfactory = $this->createMock(provider_factory::class);
        $missingfactory->method('discover_providers')->willReturn([]);
        $missingcollector = new data_collector($this->scope, 1, $missingfactory);
        try {
            $missingcollector->format_for_ai(['missing_source' => [['content' => 'Not silently discarded']]]);
            $this->fail('Missing formatter was silently skipped');
        } catch (\moodle_exception $exception) {
            $this->assertSame('error_source_failed', $exception->errorcode);
        }
    }

    /**
     * Assert a collector failure is localised while retaining developer diagnostics.
     *
     * @param data_collector $collector Collector expected to fail on collection.
     * @param string $diagnostic Private diagnostic that must never leak into the safe description.
     */
    private function assert_localised_failure(data_collector $collector, string $diagnostic): void {
        try {
            $collector->collect();
            $this->fail('A source failure was suppressed');
        } catch (\moodle_exception $exception) {
            $this->assertSame('error_source_failed', $exception->errorcode);
            // PHPUnit always appends debuginfo to Moodle exception messages, even with debugging disabled.
            $this->assertStringContainsString($diagnostic, $exception->getMessage());
            $this->assertSame(
                get_string('error_source_failed', 'report_ai_analysis'),
                error_info::get_description($exception->errorcode)
            );
            $this->assertStringNotContainsString($diagnostic, error_info::get_description($exception->errorcode));
            $this->assertStringContainsString($diagnostic, $exception->debuginfo);
            $details = error_info::get_debug_details($exception->errorcode, $exception->getMessage(), $exception->debuginfo);
            $this->assertSame(error_info::can_show_debug_details() ? trim($exception->debuginfo) : null, $details);
        }
    }
}
