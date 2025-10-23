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
 * Unit tests for forum_collector.
 *
 * @package    report_ai_analysis
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_mbs
 * @group      mebis
 */

namespace report_ai_analysis\collectors;

use report_ai_analysis\scope_builder;

/**
 * Test class for forum_collector.
 *
 * @package    report_ai_analysis
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \report_ai_analysis\collectors\forum_collector
 */
final class forum_collector_test extends \advanced_testcase {
    /**
     * Test is_available returns true when mod_forum exists.
     *
     * @covers \report_ai_analysis\collectors\forum_collector::is_available
     */
    public function test_is_available(): void {
        $this->assertTrue(forum_collector::is_available());
    }

    /**
     * Test collect with no forums returns empty array.
     *
     * @covers \report_ai_analysis\collectors\forum_collector::collect
     */
    public function test_collect_no_forums(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $scopebuilder = new scope_builder($course->id);

        $collector = new forum_collector($scopebuilder);
        $data = $collector->collect();

        $this->assertIsArray($data);
        $this->assertEmpty($data);
    }

    /**
     * Test collect with forum but no discussions.
     *
     * @covers \report_ai_analysis\collectors\forum_collector::collect
     */
    public function test_collect_forum_no_discussions(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);

        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_sources(['cm_' . $forum->cmid]);

        $collector = new forum_collector($scopebuilder);
        $data = $collector->collect();

        $this->assertIsArray($data);
        $this->assertEmpty($data);
    }

    /**
     * Test collect with single discussion.
     *
     * @covers \report_ai_analysis\collectors\forum_collector::collect
     */
    public function test_collect_single_discussion(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Test', 'lastname' => 'User']);
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'name' => 'Test Discussion',
            'message' => 'This is a test message',
        ]);

        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_sources(['cm_' . $forum->cmid]);

        $collector = new forum_collector($scopebuilder);
        $data = $collector->collect();

        $this->assertCount(1, $data);
        $this->assertEquals('Test Discussion', $data[0]['title']);
        $this->assertEquals($discussion->id, $data[0]['discussionid']);
        $this->assertArrayHasKey('posts', $data[0]);
        $this->assertNotEmpty($data[0]['posts']);

        // Verify first post structure.
        $firstpost = $data[0]['posts'][0];
        $this->assertEquals('This is a test message', $firstpost['message']);
        $this->assertEquals('Test User', $firstpost['author']);
        $this->assertArrayHasKey('timecreated', $firstpost);
    }

    /**
     * Test collect with discussion and replies.
     *
     * @covers \report_ai_analysis\collectors\forum_collector::collect
     * @covers \report_ai_analysis\collectors\forum_collector::build_post_hierarchy
     */
    public function test_collect_discussion_with_replies(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user1 = $this->getDataGenerator()->create_user(['firstname' => 'User', 'lastname' => 'One']);
        $user2 = $this->getDataGenerator()->create_user(['firstname' => 'User', 'lastname' => 'Two']);
        $this->getDataGenerator()->enrol_user($user1->id, $course->id);
        $this->getDataGenerator()->enrol_user($user2->id, $course->id);

        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user1->id,
            'name' => 'Discussion with Replies',
        ]);

        // Create reply.
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $reply = $generator->create_post([
            'discussion' => $discussion->id,
            'userid' => $user2->id,
            'parent' => $discussion->firstpost,
            'subject' => 'Re: Discussion with Replies',
            'message' => 'This is a reply',
        ]);

        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_sources(['cm_' . $forum->cmid]);

        $collector = new forum_collector($scopebuilder);
        $data = $collector->collect();

        $this->assertCount(1, $data);
        $this->assertCount(1, $data[0]['posts']); // First post (reply is nested).

        // Verify reply structure - should be in replies array.
        $posts = $data[0]['posts'];
        $firstpost = $posts[0];
        $this->assertArrayHasKey('replies', $firstpost);
        $this->assertCount(1, $firstpost['replies']);

        $replypost = $firstpost['replies'][0];
        $this->assertEquals('This is a reply', $replypost['message']);
        $this->assertEquals('User Two', $replypost['author']);
    }

    /**
     * Test collect with multiple discussions.
     *
     * @covers \report_ai_analysis\collectors\forum_collector::collect
     */
    public function test_collect_multiple_discussions(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);

        // Create multiple discussions.
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion1 = $generator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'name' => 'First Discussion',
        ]);
        $discussion2 = $generator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'name' => 'Second Discussion',
        ]);
        $discussion3 = $generator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'name' => 'Third Discussion',
        ]);

        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_sources(['cm_' . $forum->cmid]);

        $collector = new forum_collector($scopebuilder);
        $data = $collector->collect();

        $this->assertCount(3, $data);

        $titles = array_column($data, 'title');
        $this->assertContains('First Discussion', $titles);
        $this->assertContains('Second Discussion', $titles);
        $this->assertContains('Third Discussion', $titles);
    }

    /**
     * Test collect with maxrecords limit.
     *
     * @covers \report_ai_analysis\collectors\forum_collector::collect
     */
    public function test_collect_with_maxrecords(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_forum');

        // Create 5 discussions.
        for ($i = 1; $i <= 5; $i++) {
            $generator->create_discussion([
                'course' => $course->id,
                'forum' => $forum->id,
                'userid' => $user->id,
                'name' => "Discussion $i",
            ]);
        }

        // Limit to 3 discussions.
        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_sources(['cm_' . $forum->cmid]);

        $collector = new forum_collector($scopebuilder, 3);
        $data = $collector->collect();

        $this->assertCount(3, $data);
    }

    /**
     * Test format_for_ai produces valid output.
     *
     * @covers \report_ai_analysis\collectors\forum_collector::format_for_ai
     */
    public function test_format_for_ai(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Test', 'lastname' => 'User']);
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'name' => 'Test Discussion',
            'message' => 'Test message content',
        ]);

        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_sources(['cm_' . $forum->cmid]);

        $collector = new forum_collector($scopebuilder);
        $data = $collector->collect();

        $formatted = forum_collector::format_for_ai($data);

        $this->assertIsString($formatted);
        $this->assertStringContainsString('=== FORUM DISCUSSIONS ===', $formatted);
        $this->assertStringContainsString('Test Discussion', $formatted);
        $this->assertStringContainsString('Test message content', $formatted);
        $this->assertStringContainsString('Test User', $formatted);
    }

    /**
     * Test get_statistics returns valid statistics.
     *
     * @covers \report_ai_analysis\collectors\forum_collector::get_statistics
     */
    public function test_get_statistics(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_forum');

        // Create discussion with reply.
        $discussion = $generator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'name' => 'Test Discussion',
        ]);
        $reply = $generator->create_post([
            'discussion' => $discussion->id,
            'userid' => $user->id,
            'parent' => $discussion->firstpost,
            'message' => 'Reply',
        ]);

        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_sources(['cm_' . $forum->cmid]);

        $collector = new forum_collector($scopebuilder);
        $data = $collector->collect();

        $stats = forum_collector::get_statistics($data);

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_discussions', $stats);
        $this->assertArrayHasKey('total_posts', $stats);
        $this->assertArrayHasKey('participants', $stats);
        $this->assertEquals(1, $stats['total_discussions']);
        $this->assertEquals(2, $stats['total_posts']); // First post + reply.
        $this->assertEquals(1, $stats['participants']);
    }

    /**
     * Test collect handles nested replies correctly.
     *
     * @covers \report_ai_analysis\collectors\forum_collector::build_post_hierarchy
     */
    public function test_collect_nested_replies(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user1 = $this->getDataGenerator()->create_user(['firstname' => 'User', 'lastname' => 'One']);
        $user2 = $this->getDataGenerator()->create_user(['firstname' => 'User', 'lastname' => 'Two']);
        $user3 = $this->getDataGenerator()->create_user(['firstname' => 'User', 'lastname' => 'Three']);
        $this->getDataGenerator()->enrol_user($user1->id, $course->id);
        $this->getDataGenerator()->enrol_user($user2->id, $course->id);
        $this->getDataGenerator()->enrol_user($user3->id, $course->id);

        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_forum');

        // Create discussion.
        $discussion = $generator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user1->id,
            'name' => 'Discussion',
        ]);

        // Create first level reply.
        $reply1 = $generator->create_post([
            'discussion' => $discussion->id,
            'userid' => $user2->id,
            'parent' => $discussion->firstpost,
            'message' => 'First reply',
        ]);

        // Create second level reply (reply to reply).
        $reply2 = $generator->create_post([
            'discussion' => $discussion->id,
            'userid' => $user3->id,
            'parent' => $reply1->id,
            'message' => 'Reply to reply',
        ]);

        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_sources(['cm_' . $forum->cmid]);

        $collector = new forum_collector($scopebuilder);
        $data = $collector->collect();

        $this->assertCount(1, $data);
        $this->assertCount(1, $data[0]['posts']); // First post (replies are nested).

        // Verify nested reply structure.
        $firstpost = $data[0]['posts'][0];
        $this->assertArrayHasKey('replies', $firstpost);
        $this->assertCount(1, $firstpost['replies']);

        $reply1 = $firstpost['replies'][0];
        $this->assertEquals('First reply', $reply1['message']);
        $this->assertArrayHasKey('replies', $reply1);
        $this->assertCount(1, $reply1['replies']);

        $reply2 = $reply1['replies'][0];
        $this->assertEquals('Reply to reply', $reply2['message']);
    }

    /**
     * Test collect with multiple forums in scope.
     *
     * @covers \report_ai_analysis\collectors\forum_collector::collect
     */
    public function test_collect_multiple_forums(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        // Create two forums.
        $forum1 = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $forum2 = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_forum');

        // Create discussions in both forums.
        $discussion1 = $generator->create_discussion([
            'course' => $course->id,
            'forum' => $forum1->id,
            'userid' => $user->id,
            'name' => 'Forum 1 Discussion',
        ]);
        $discussion2 = $generator->create_discussion([
            'course' => $course->id,
            'forum' => $forum2->id,
            'userid' => $user->id,
            'name' => 'Forum 2 Discussion',
        ]);

        // Collect from both forums.
        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_sources(['cm_' . $forum1->cmid, 'cm_' . $forum2->cmid]);

        $collector = new forum_collector($scopebuilder);
        $data = $collector->collect();

        $this->assertCount(2, $data);

        $titles = array_column($data, 'title');
        $this->assertContains('Forum 1 Discussion', $titles);
        $this->assertContains('Forum 2 Discussion', $titles);
    }

    /**
     * Test format_for_ai with structured test data.
     *
     * @covers \report_ai_analysis\collectors\forum_collector::format_for_ai
     */
    public function test_format_for_ai_with_structured_data(): void {
        $this->resetAfterTest();

        // Create test discussion data structure.
        $testdata = [
            [
                'discussionid' => 1,
                'title' => 'Test Discussion',
                'discussionname' => 'Test Discussion',
                'forumid' => 100,
                'forumname' => 'Test Forum',
                'courseid' => 1,
                'coursename' => 'Test Course',
                'courseshortname' => 'TC1',
                'starterid' => 2,
                'startername' => 'John Doe',
                'timemodified' => time(),
                'postcount' => 2,
                'posts' => [
                    [
                        'postid' => 1,
                        'parentid' => 0,
                        'author' => 'John Doe',
                        'username' => 'John Doe',
                        'authorid' => 2,
                        'timecreated' => time(),
                        'created' => time(),
                        'timemodified' => time(),
                        'subject' => 'Initial Post',
                        'message' => 'This is the initial post content.',
                        'messageformat' => FORMAT_HTML,
                        'replies' => [
                            [
                                'postid' => 2,
                                'parentid' => 1,
                                'author' => 'Jane Smith',
                                'username' => 'Jane Smith',
                                'authorid' => 3,
                                'timecreated' => time(),
                                'created' => time(),
                                'timemodified' => time(),
                                'subject' => 'Re: Initial Post',
                                'message' => 'This is a reply to the initial post.',
                                'messageformat' => FORMAT_HTML,
                                'replies' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $formatted = forum_collector::format_for_ai($testdata);

        // Check basic structure.
        $this->assertIsString($formatted);
        $this->assertNotEmpty($formatted);

        // Check header.
        $this->assertStringContainsString('=== FORUM DISCUSSIONS ===', $formatted);
        $this->assertStringContainsString('Total discussions: 1', $formatted);

        // Check discussion metadata.
        $this->assertStringContainsString('--- Discussion: Test Discussion ---', $formatted);
        $this->assertStringContainsString('Forum: Test Forum', $formatted);
        $this->assertStringContainsString('Course: Test Course (TC1)', $formatted);
        $this->assertStringContainsString('Started by: John Doe (ID: 2)', $formatted);
        $this->assertStringContainsString('Total posts: 2', $formatted);

        // Check posts.
        $this->assertStringContainsString('[POST by John Doe at', $formatted);
        $this->assertStringContainsString('Subject: Initial Post', $formatted);
        $this->assertStringContainsString('This is the initial post content.', $formatted);

        // Check reply (should be indented).
        $this->assertStringContainsString('[POST by Jane Smith at', $formatted);
        $this->assertStringContainsString('Subject: Re: Initial Post', $formatted);
        $this->assertStringContainsString('This is a reply to the initial post.', $formatted);

        // Debug output.
        debugging("Formatted output:\n" . $formatted, DEBUG_DEVELOPER);
    }

    /**
     * Test format_for_ai with empty discussions returns empty string.
     *
     * @covers \report_ai_analysis\collectors\forum_collector::format_for_ai
     */
    public function test_format_for_ai_empty(): void {
        $this->resetAfterTest();

        $formatted = forum_collector::format_for_ai([]);

        $this->assertIsString($formatted);
        $this->assertEmpty($formatted);
    }

    /**
     * Test format_for_ai with HTML content gets cleaned.
     *
     * @covers \report_ai_analysis\collectors\forum_collector::format_for_ai
     */
    public function test_format_for_ai_html_cleaning(): void {
        $this->resetAfterTest();

        $testdata = [
            [
                'discussionid' => 1,
                'title' => 'HTML Test',
                'discussionname' => 'HTML Test',
                'forumid' => 100,
                'forumname' => 'Test Forum',
                'courseid' => 1,
                'coursename' => 'Test Course',
                'courseshortname' => 'TC1',
                'starterid' => 2,
                'startername' => 'John Doe',
                'timemodified' => time(),
                'postcount' => 1,
                'posts' => [
                    [
                        'postid' => 1,
                        'parentid' => 0,
                        'author' => 'John Doe',
                        'username' => 'John Doe',
                        'authorid' => 2,
                        'timecreated' => time(),
                        'created' => time(),
                        'timemodified' => time(),
                        'subject' => 'HTML Content',
                        'message' => '<p>This is <strong>HTML</strong> content with <a href="#">links</a></p>',
                        'messageformat' => FORMAT_HTML,
                        'replies' => [],
                    ],
                ],
            ],
        ];

        $formatted = forum_collector::format_for_ai($testdata);

        // HTML should be converted to plain text by format_text_email.
        $this->assertStringContainsString('This is', $formatted);
        $this->assertStringContainsString('HTML', $formatted);
        $this->assertStringContainsString('content', $formatted);
    }

    /**
     * Test collect with timerange filter only includes discussions with posts in range.
     *
     * @covers \report_ai_analysis\collectors\forum_collector::collect
     */
    public function test_collect_with_timerange_filter(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);

        // Create discussion with posts at different times.
        $now = time();
        $oldtime = $now - (30 * DAYSECS); // 30 days ago.
        $futuretime = $now + (30 * DAYSECS); // 30 days in future.

        // Discussion 1: Old post (should be excluded).
        $record = [
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'timemodified' => $oldtime,
        ];
        $discussion1 = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($record);

        // Update post timecreated to old time.
        $DB->set_field('forum_posts', 'created', $oldtime, ['discussion' => $discussion1->id]);

        // Discussion 2: Recent post (should be included).
        $record = [
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'timemodified' => $now,
        ];
        $discussion2 = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($record);

        // Update post timecreated to now.
        $DB->set_field('forum_posts', 'created', $now, ['discussion' => $discussion2->id]);

        // Discussion 3: Future post (should be excluded).
        $record = [
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'timemodified' => $futuretime,
        ];
        $discussion3 = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($record);

        // Update post timecreated to future.
        $DB->set_field('forum_posts', 'created', $futuretime, ['discussion' => $discussion3->id]);

        // Set timerange: last 7 days to next 7 days.
        $timestart = $now - (7 * DAYSECS);
        $timeend = $now + (7 * DAYSECS);

        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_timerange($timestart, $timeend);

        $collector = new forum_collector($scopebuilder);
        $data = $collector->collect();

        // Debug: Check what we got.
        $this->assertIsArray($data, 'Data should be an array');

        // Should only return discussion2 (recent post in range).
        $this->assertCount(1, $data, 'Should return exactly 1 discussion');
        $this->assertArrayHasKey('title', $data[0], 'Discussion should have title');
        $this->assertEquals($discussion2->name, $data[0]['title']);
    }

    /**
     * Test collect without timerange includes all discussions.
     *
     * @covers \report_ai_analysis\collectors\forum_collector::collect
     */
    public function test_collect_without_timerange_includes_all(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);

        $now = time();
        $oldtime = $now - (30 * DAYSECS); // 30 days ago.

        // Create two discussions at different times.
        $record1 = [
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'timemodified' => $oldtime,
        ];
        $discussion1 = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($record1);

        $record2 = [
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'timemodified' => $now,
        ];
        $discussion2 = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion($record2);

        // Collect WITHOUT timerange filter.
        $scopebuilder = new scope_builder($course->id);
        $collector = new forum_collector($scopebuilder);
        $data = $collector->collect();

        // Should return both discussions.
        $this->assertCount(2, $data);
    }

    /**
     * Test timerange filter with multiple discussions and multiple posts per discussion.
     *
     * Creates 3 discussions with 5 posts each at different time periods:
     * - Discussion 1: 1 year ago (5 posts)
     * - Discussion 2: 1 month ago (5 posts)
     * - Discussion 3: Today (5 posts)
     *
     * Tests three different timerange scenarios:
     * 1. No filter: Should return all 3 discussions with 15 posts total
     * 2. Last 2 months: Should return 2 discussions with 10 posts total
     * 3. Last 1 week: Should return 1 discussion with 5 posts total
     *
     * @covers \report_ai_analysis\collectors\forum_collector::collect
     * @covers \report_ai_analysis\collectors\forum_collector::has_post_in_timerange
     */
    public function test_timerange_filter_with_multiple_posts_per_discussion(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_forum');

        $now = time();
        $oneyearago = $now - YEARSECS; // 1 year ago.
        $onemonthago = $now - 30 * DAYSECS; // 1 month ago.

        // Discussion 1: 1 year ago with 5 posts.
        $record1 = [
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'timemodified' => $oneyearago,
        ];
        $discussion1 = $generator->create_discussion($record1);

        // Update first post to 1 year ago.
        $DB->set_field('forum_posts', 'created', $oneyearago, ['discussion' => $discussion1->id]);

        // Add 4 more posts to discussion 1.
        for ($i = 1; $i <= 4; $i++) {
            $post = $generator->create_post([
                'discussion' => $discussion1->id,
                'userid' => $user->id,
                'created' => $oneyearago + ($i * HOURSECS), // Each post 1 hour apart.
            ]);
            $DB->set_field('forum_posts', 'created', $oneyearago + ($i * HOURSECS), ['id' => $post->id]);
        }

        // Discussion 2: 1 month ago with 5 posts.
        $record2 = [
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'timemodified' => $onemonthago,
        ];
        $discussion2 = $generator->create_discussion($record2);

        // Update first post to 1 month ago.
        $DB->set_field('forum_posts', 'created', $onemonthago, ['discussion' => $discussion2->id]);

        // Add 4 more posts to discussion 2.
        for ($i = 1; $i <= 4; $i++) {
            $post = $generator->create_post([
                'discussion' => $discussion2->id,
                'userid' => $user->id,
                'created' => $onemonthago + ($i * HOURSECS),
            ]);
            $DB->set_field('forum_posts', 'created', $onemonthago + ($i * HOURSECS), ['id' => $post->id]);
        }

        // Discussion 3: Today with 5 posts.
        $record3 = [
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'timemodified' => $now,
        ];
        $discussion3 = $generator->create_discussion($record3);

        // Update first post to today.
        $DB->set_field('forum_posts', 'created', $now, ['discussion' => $discussion3->id]);

        // Add 4 more posts to discussion 3.
        for ($i = 1; $i <= 4; $i++) {
            $post = $generator->create_post([
                'discussion' => $discussion3->id,
                'userid' => $user->id,
                'created' => $now + ($i * HOURSECS),
            ]);
            $DB->set_field('forum_posts', 'created', $now + ($i * HOURSECS), ['id' => $post->id]);
        }

        // Verify we have 3 discussions total.
        $totaldiscussioncount = $DB->count_records('forum_discussions', ['forum' => $forum->id]);
        $this->assertEquals(3, $totaldiscussioncount, 'Should have 3 discussions total in forum');

        // Test 1: Without timerange filter - should return all 3 discussions with 15 posts.
        $scopebuilder = new scope_builder($course->id);
        $collector = new forum_collector($scopebuilder);
        $data = $collector->collect();

        $this->assertIsArray($data, 'Data should be an array');
        $this->assertCount(3, $data, 'Without filter should return 3 discussions');

        // Count total posts in collected data.
        $totalcollectedposts = 0;
        foreach ($data as $discussion) {
            $this->assertArrayHasKey('posts', $discussion, 'Discussion should have posts array');
            $totalcollectedposts += count($discussion['posts']);
        }
        $this->assertEquals(15, $totalcollectedposts, 'Without filter should return 15 posts total');

        // Test 2: Last 2 months (60 days) - should return 2 discussions with 10 posts.
        $timestart = $now - (60 * DAYSECS);
        $timeend = $now + (24 * HOURSECS); // Include posts from today.

        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_timerange($timestart, $timeend);
        $collector = new forum_collector($scopebuilder);
        $data = $collector->collect();

        $this->assertIsArray($data, 'Data should be an array');
        $this->assertCount(2, $data, 'With 2 month filter should return 2 discussions');

        // Count posts and verify discussion names.
        $totalcollectedposts = 0;
        $discussionnames = [];
        foreach ($data as $discussion) {
            $this->assertArrayHasKey('posts', $discussion, 'Discussion should have posts array');
            $this->assertArrayHasKey('title', $discussion, 'Discussion should have title');
            $totalcollectedposts += count($discussion['posts']);
            $discussionnames[] = $discussion['title'];
        }
        $this->assertEquals(10, $totalcollectedposts, 'With 2 month filter should return 10 posts total');
        $this->assertContains($discussion2->name, $discussionnames, 'Should include 1 month old discussion');
        $this->assertContains($discussion3->name, $discussionnames, 'Should include today discussion');
        $this->assertNotContains($discussion1->name, $discussionnames, 'Should not include 1 year old discussion');

        // Test 3: Last 1 week (7 days) - should return 1 discussion with 5 posts.
        $timestart = $now - (7 * DAYSECS);
        $timeend = $now + (24 * HOURSECS);

        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_timerange($timestart, $timeend);
        $collector = new forum_collector($scopebuilder);
        $data = $collector->collect();

        $this->assertIsArray($data, 'Data should be an array');
        $this->assertCount(1, $data, 'With 1 week filter should return 1 discussion');

        // Count posts and verify it's the today discussion.
        $totalcollectedposts = 0;
        foreach ($data as $discussion) {
            $this->assertArrayHasKey('posts', $discussion, 'Discussion should have posts array');
            $this->assertArrayHasKey('title', $discussion, 'Discussion should have title');
            $totalcollectedposts += count($discussion['posts']);
            $this->assertEquals($discussion3->name, $discussion['title'], 'Should be today discussion');
        }
        $this->assertEquals(5, $totalcollectedposts, 'With 1 week filter should return 5 posts total');
    }
}
