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
 * Unit tests for data_collector.
 *
 * @package    report_ai_analysis
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_mbs
 * @group      mebis
 */

namespace report_ai_analysis;

/**
 * Test class for data_collector.
 *
 * @package    report_ai_analysis
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \report_ai_analysis\data_collector
 */
final class data_collector_test extends \advanced_testcase {
    /**
     * Test data_collector with no sources throws exception.
     *
     * @covers \report_ai_analysis\data_collector::collect
     */
    public function test_collect_no_sources_throws_exception(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $scopebuilder = new scope_builder($course->id);

        $collector = new data_collector($scopebuilder);

        $this->expectException(\moodle_exception::class);
        try {
            $collector->collect();
        } catch (\moodle_exception $e) {
            // Check error code instead of translated message.
            $this->assertEquals('error_no_data', $e->errorcode);
            throw $e;
        }
    }

    /**
     * Test has_forum_sources detects forum course modules.
     *
     * @covers \report_ai_analysis\data_collector::has_forum_sources
     */
    public function test_has_forum_sources(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        // Create forum and quiz.
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);

        $scopebuilder = new scope_builder($course->id);

        // Test with forum source.
        $scopebuilder->with_sources(['cm_' . $forum->cmid]);
        $collector = new data_collector($scopebuilder);

        // Use reflection to access private method.
        $reflection = new \ReflectionClass($collector);
        $method = $reflection->getMethod('has_forum_sources');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($collector, ['cm_' . $forum->cmid]));
        $this->assertFalse($method->invoke($collector, ['cm_' . $quiz->cmid]));
        $this->assertFalse($method->invoke($collector, []));
    }

    /**
     * Test has_block_sources detects block sources.
     *
     * @covers \report_ai_analysis\data_collector::has_block_sources
     */
    public function test_has_block_sources(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $scopebuilder = new scope_builder($course->id);
        $collector = new data_collector($scopebuilder);

        // Use reflection to access private method.
        $reflection = new \ReflectionClass($collector);
        $method = $reflection->getMethod('has_block_sources');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($collector, ['block_123']));
        $this->assertFalse($method->invoke($collector, ['cm_456']));
        $this->assertFalse($method->invoke($collector, []));
    }

    /**
     * Test collect with forum source.
     *
     * @covers \report_ai_analysis\data_collector::collect
     * @covers \report_ai_analysis\collectors\forum_collector::collect
     */
    public function test_collect_forum_source(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        // Create forum with discussions.
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'name' => 'Test Discussion',
            'message' => 'Test message content',
        ]);

        // Create scope with forum source.
        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_sources(['cm_' . $forum->cmid]);

        $collector = new data_collector($scopebuilder);
        $data = $collector->collect();

        $this->assertIsArray($data);
        $this->assertArrayHasKey('conversations', $data);
        $this->assertArrayHasKey('discussions', $data);
        $this->assertEmpty($data['conversations']);
        $this->assertNotEmpty($data['discussions']);
        $this->assertCount(1, $data['discussions']);

        // Verify discussion structure.
        $discussiondata = $data['discussions'][0];
        $this->assertEquals('Test Discussion', $discussiondata['title']);
        $this->assertArrayHasKey('posts', $discussiondata);
        $this->assertNotEmpty($discussiondata['posts']);
    }

    /**
     * Test collect with multiple forum sources.
     *
     * @covers \report_ai_analysis\data_collector::collect
     * @covers \report_ai_analysis\collectors\forum_collector::collect
     */
    public function test_collect_multiple_forum_sources(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        // Create two forums.
        $forum1 = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'name' => 'Forum 1']);
        $forum2 = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'name' => 'Forum 2']);

        // Create discussions in each forum.
        $discussion1 = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id,
            'forum' => $forum1->id,
            'userid' => $user->id,
            'name' => 'Discussion in Forum 1',
        ]);
        $discussion2 = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id,
            'forum' => $forum2->id,
            'userid' => $user->id,
            'name' => 'Discussion in Forum 2',
        ]);

        // Create scope with both forums.
        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_sources(['cm_' . $forum1->cmid, 'cm_' . $forum2->cmid]);

        $collector = new data_collector($scopebuilder);
        $data = $collector->collect();

        $this->assertNotEmpty($data['discussions']);
        $this->assertCount(2, $data['discussions']);

        // Verify both discussions are present.
        $titles = array_column($data['discussions'], 'title');
        $this->assertContains('Discussion in Forum 1', $titles);
        $this->assertContains('Discussion in Forum 2', $titles);
    }



    /**
     * Test format_for_ai produces valid output.
     *
     * @covers \report_ai_analysis\data_collector::format_for_ai
     */
    public function test_format_for_ai(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        // Create forum with discussion.
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'name' => 'Test Discussion',
            'message' => 'Test message',
        ]);

        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_sources(['cm_' . $forum->cmid]);

        $collector = new data_collector($scopebuilder);
        $data = $collector->collect();

        $formatted = data_collector::format_for_ai($data);

        $this->assertIsString($formatted);
        $this->assertStringContainsString('Test Discussion', $formatted);
        $this->assertStringContainsString('Test message', $formatted);
    }

    /**
     * Test get_statistics returns valid statistics.
     *
     * @covers \report_ai_analysis\data_collector::get_statistics
     */
    public function test_get_statistics(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        // Create forum with discussion.
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'name' => 'Test Discussion',
        ]);

        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_sources(['cm_' . $forum->cmid]);

        $collector = new data_collector($scopebuilder);
        $data = $collector->collect();

        $stats = data_collector::get_statistics($data);

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_sources', $stats);
        $this->assertEquals(1, $stats['total_sources']);
        $this->assertArrayHasKey('discussions', $stats);
        $this->assertNotEmpty($stats['discussions']);
    }

    /**
     * Test collect with mix of forum and block sources (when available).
     *
     * @covers \report_ai_analysis\data_collector::collect
     */
    public function test_collect_mixed_sources(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        // Create forum.
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'name' => 'Forum Discussion',
        ]);

        // Create fake block context for testing.
        $coursecontext = \context_course::instance($course->id);
        $blockcontext = \context::instance_by_id($coursecontext->id);

        // Create scope with forum and block sources.
        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_sources(['cm_' . $forum->cmid, 'block_' . $blockcontext->id]);

        $collector = new data_collector($scopebuilder);
        $data = $collector->collect();

        // Should collect forum discussions.
        $this->assertNotEmpty($data['discussions']);
        // Conversations might be empty if block_ai_chat is not available.
        $this->assertArrayHasKey('conversations', $data);
    }

    /**
     * Test collect with timerange filter.
     *
     * @covers \report_ai_analysis\data_collector::collect
     * @covers \report_ai_analysis\scope_builder::with_timerange
     */
    public function test_collect_with_timerange_filter(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);

        $now = time();
        $yesterday = $now - DAYSECS;
        $tomorrow = $now + DAYSECS;
        $twodaysago = $now - (2 * DAYSECS);

        // Create discussion today.
        $discussiontoday = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'name' => 'Today Discussion',
            'timemodified' => $now,
        ]);

        // Create discussion two days ago.
        $discussionold = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'name' => 'Old Discussion',
            'timemodified' => $twodaysago,
        ]);

        // Test with timerange that includes today.
        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_sources(['cm_' . $forum->cmid]);
        $scopebuilder->with_timerange($yesterday, $tomorrow);

        $collector = new data_collector($scopebuilder);
        $data = $collector->collect();

        $this->assertNotEmpty($data['discussions']);
        $this->assertCount(1, $data['discussions'], 'Should only get discussion from today');
        $this->assertEquals('Today Discussion', $data['discussions'][0]['title']);

        // Test with timerange that excludes today (only old discussions).
        $scopebuilder2 = new scope_builder($course->id);
        $scopebuilder2->with_sources(['cm_' . $forum->cmid]);
        $scopebuilder2->with_timerange($twodaysago - DAYSECS, $yesterday);

        $collector2 = new data_collector($scopebuilder2);
        $data2 = $collector2->collect();

        $this->assertNotEmpty($data2['discussions']);
        $this->assertCount(1, $data2['discussions'], 'Should only get old discussion');
        $this->assertEquals('Old Discussion', $data2['discussions'][0]['title']);
    }
}
