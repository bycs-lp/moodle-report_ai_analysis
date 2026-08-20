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
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_mbs
 * @group      mebis
 */

namespace report_ai_analysis;

/**
 * Test class for data_collector.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \report_ai_analysis\data_collector
 */
final class data_collector_test extends \advanced_testcase {
    /**
     * Test collect throws exception when no data sources are available.
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
            $this->assertEquals('error_no_data', $e->errorcode);
            throw $e;
        }
    }

    /**
     * Test collect with forum source returns structured data.
     *
     * @covers \report_ai_analysis\provider\mod_forum_provider::collect
     */
    public function test_collect_forum_source(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'name' => 'Test Discussion',
            'message' => 'Test message content',
        ]);

        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_sources(['cm_' . $forum->cmid]);

        $collector = new data_collector($scopebuilder);
        $data = $collector->collect();

        $this->assertArrayHasKey('mod_forum', $data);
        $this->assertCount(1, $data['mod_forum']);
        $this->assertEquals('Test Discussion', $data['mod_forum'][0]['title']);
        $this->assertArrayHasKey('posts', $data['mod_forum'][0]);
    }

    /**
     * Test collect with multiple forum sources returns all discussions.
     */
    public function test_collect_multiple_forum_sources(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $forum1 = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $forum2 = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);

        $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id, 'forum' => $forum1->id, 'userid' => $user->id,
            'name' => 'Discussion in Forum 1',
        ]);
        $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id, 'forum' => $forum2->id, 'userid' => $user->id,
            'name' => 'Discussion in Forum 2',
        ]);

        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_sources(['cm_' . $forum1->cmid, 'cm_' . $forum2->cmid]);

        $collector = new data_collector($scopebuilder);
        $data = $collector->collect();

        $this->assertCount(2, $data['mod_forum']);
        $titles = array_column($data['mod_forum'], 'title');
        $this->assertContains('Discussion in Forum 1', $titles);
        $this->assertContains('Discussion in Forum 2', $titles);
    }

    /**
     * Test format_for_ai and get_statistics return proper output.
     */
    public function test_format_and_statistics(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id, 'forum' => $forum->id, 'userid' => $user->id,
            'name' => 'Test Discussion', 'message' => 'Test message',
        ]);

        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_sources(['cm_' . $forum->cmid]);
        $collector = new data_collector($scopebuilder);
        $data = $collector->collect();

        // Test format_for_ai.
        $formatted = $collector->format_for_ai($data);
        $this->assertIsString($formatted);
        $this->assertStringContainsString('Test Discussion', $formatted);
        $this->assertStringContainsString('Test message', $formatted);

        // Test get_statistics.
        $stats = $collector->get_statistics($data);
        $this->assertArrayHasKey('total_sources', $stats);
        $this->assertEquals(1, $stats['total_sources']);
        $this->assertArrayHasKey('mod_forum', $stats);
    }

    /**
     * Test collect with timerange filter returns only matching discussions.
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

        $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id, 'forum' => $forum->id, 'userid' => $user->id,
            'name' => 'Today Discussion', 'timemodified' => $now,
        ]);
        $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id, 'forum' => $forum->id, 'userid' => $user->id,
            'name' => 'Old Discussion', 'timemodified' => $twodaysago,
        ]);

        // Filter to include only today.
        $scopebuilder = new scope_builder($course->id);
        $scopebuilder->with_sources(['cm_' . $forum->cmid]);
        $scopebuilder->with_timerange($yesterday, $tomorrow);

        $collector = new data_collector($scopebuilder);
        $data = $collector->collect();

        $this->assertCount(1, $data['mod_forum']);
        $this->assertEquals('Today Discussion', $data['mod_forum'][0]['title']);

        // Filter to include only old.
        $scopebuilder2 = new scope_builder($course->id);
        $scopebuilder2->with_sources(['cm_' . $forum->cmid]);
        $scopebuilder2->with_timerange($twodaysago - DAYSECS, $yesterday);

        $collector2 = new data_collector($scopebuilder2);
        $data2 = $collector2->collect();

        $this->assertCount(1, $data2['mod_forum']);
        $this->assertEquals('Old Discussion', $data2['mod_forum'][0]['title']);
    }
}
