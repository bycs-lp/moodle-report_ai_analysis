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
 * Unit tests for conversation_collector.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_mbs
 * @group      mebis
 */

namespace report_ai_analysis\collectors;

use report_ai_analysis\scope_builder;

/**
 * Test class for conversation_collector.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \report_ai_analysis\collectors\conversation_collector
 */
final class conversation_collector_test extends \advanced_testcase {
    /**
     * Clean up after each test.
     */
    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    /**
     * Test is_available checks for block_ai_chat.
     *
     * @covers \report_ai_analysis\collectors\conversation_collector::is_available
     */
    public function test_is_available(): void {
        global $CFG;

        // Check if block_ai_chat exists in this installation.
        $blockpath = $CFG->dirroot . '/blocks/ai_chat';
        $blockexists = file_exists($blockpath);

        $this->assertEquals($blockexists, conversation_collector::is_available());
    }

    /**
     * Test collect with no AI chat conversations.
     *
     * @covers \report_ai_analysis\collectors\conversation_collector::collect
     */
    public function test_collect_no_conversations(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $scopebuilder = new scope_builder($course->id);

        $collector = new conversation_collector($scopebuilder);
        $data = $collector->collect();

        $this->assertIsArray($data);
        $this->assertEmpty($data);
    }

    /**
     * Test format_for_ai produces valid output.
     *
     * @covers \report_ai_analysis\collectors\conversation_collector::format_for_ai
     */
    public function test_format_for_ai(): void {
        $this->resetAfterTest();

        // Create mock conversation data structure.
        $mockconversations = [
            [
                'threadid' => 1,
                'userid' => 100,
                'username' => 'Test User',
                'contextid' => 1,
                'timecreated' => time(),
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => 'Hello AI',
                        'timestamp' => time(),
                    ],
                    [
                        'role' => 'assistant',
                        'content' => 'Hello User!',
                        'timestamp' => time(),
                    ],
                ],
            ],
        ];

        $formatted = conversation_collector::format_for_ai($mockconversations);

        $this->assertIsString($formatted);
        $this->assertStringContainsString('=== AI CHAT CONVERSATIONS ===', $formatted);
        $this->assertStringContainsString('Test User', $formatted);
        $this->assertStringContainsString('Hello AI', $formatted);
        $this->assertStringContainsString('Hello User!', $formatted);
    }

    /**
     * Test get_statistics returns valid statistics.
     *
     * @covers \report_ai_analysis\collectors\conversation_collector::get_statistics
     */
    public function test_get_statistics(): void {
        $this->resetAfterTest();

        // Create mock conversation data.
        $mockconversations = [
            [
                'threadid' => 1,
                'userid' => 100,
                'username' => 'User One',
                'messages' => [
                    ['role' => 'user', 'content' => 'Message 1'],
                    ['role' => 'assistant', 'content' => 'Response 1'],
                ],
            ],
            [
                'threadid' => 2,
                'userid' => 100,
                'username' => 'User One',
                'messages' => [
                    ['role' => 'user', 'content' => 'Message 2'],
                    ['role' => 'assistant', 'content' => 'Response 2'],
                    ['role' => 'user', 'content' => 'Message 3'],
                    ['role' => 'assistant', 'content' => 'Response 3'],
                ],
            ],
        ];

        $stats = conversation_collector::get_statistics($mockconversations);

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_threads', $stats);
        $this->assertArrayHasKey('total_messages', $stats);
        $this->assertArrayHasKey('total_users', $stats);
        $this->assertEquals(2, $stats['total_threads']);
        $this->assertEquals(6, $stats['total_messages']);
        $this->assertEquals(1, $stats['total_users']);
    }

    /**
     * Test collect with maxconversations limit.
     *
     * @covers \report_ai_analysis\collectors\conversation_collector::collect
     */
    public function test_collect_respects_max_limit(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $scopebuilder = new scope_builder($course->id);

        // Test that collector accepts maxconversations parameter.
        $collector = new conversation_collector($scopebuilder, 3);
        $data = $collector->collect();

        $this->assertIsArray($data);
        // Without actual conversation data, we just verify it doesn't error.
        $this->assertLessThanOrEqual(3, count($data));
    }

    /**
     * Test format_for_ai with empty conversations.
     *
     * @covers \report_ai_analysis\collectors\conversation_collector::format_for_ai
     */
    public function test_format_for_ai_with_empty_conversations(): void {
        $this->resetAfterTest();

        $formatted = conversation_collector::format_for_ai([]);

        $this->assertIsString($formatted);
        $this->assertEmpty($formatted);
    }

    /**
     * Test get_statistics with empty conversations.
     *
     * @covers \report_ai_analysis\collectors\conversation_collector::get_statistics
     */
    public function test_get_statistics_with_empty_conversations(): void {
        $this->resetAfterTest();

        $stats = conversation_collector::get_statistics([]);

        $this->assertIsArray($stats);
        $this->assertEquals(0, $stats['total_threads']);
        $this->assertEquals(0, $stats['total_messages']);
        $this->assertEquals(0, $stats['total_users']);
    }

    /**
     * Test get_statistics with multiple participants.
     *
     * @covers \report_ai_analysis\collectors\conversation_collector::get_statistics
     */
    public function test_get_statistics_with_multiple_participants(): void {
        $this->resetAfterTest();

        $mockconversations = [
            [
                'threadid' => 1,
                'userid' => 100,
                'username' => 'User One',
                'messages' => [
                    ['role' => 'user', 'content' => 'Message 1'],
                ],
            ],
            [
                'threadid' => 2,
                'userid' => 200,
                'username' => 'User Two',
                'messages' => [
                    ['role' => 'user', 'content' => 'Message 2'],
                ],
            ],
        ];

        $stats = conversation_collector::get_statistics($mockconversations);

        $this->assertEquals(2, $stats['total_threads']);
        $this->assertEquals(2, $stats['total_messages']);
        $this->assertEquals(2, $stats['total_users']);
    }
}
