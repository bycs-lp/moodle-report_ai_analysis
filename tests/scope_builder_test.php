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

namespace report_ai_analysis;

/**
 * Tests for scope_builder class.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_ai_analysis\scope_builder
 */
final class scope_builder_test extends \advanced_testcase {
    /**
     * Test source validation accepts valid formats and rejects invalid ones.
     */
    public function test_source_validation(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        // Valid formats should work.
        $builder = new scope_builder($course->id);
        $builder->with_sources(['cm_123', 'block_456', 'forum_789']);
        $this->assertEquals(['cm_123', 'block_456', 'forum_789'], $builder->get_sources_in_scope());

        // Invalid format.
        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessage('Invalid source format');
        $builder2 = new scope_builder($course->id);
        $builder2->with_sources(['invalid_format']);
    }

    /**
     * Test source validation rejects invalid IDs and unsupported types.
     */
    public function test_source_validation_edge_cases(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        // Invalid ID (zero).
        try {
            $builder = new scope_builder($course->id);
            $builder->with_sources(['cm_0']);
            $this->fail('Expected coding_exception was not thrown');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('Invalid source ID', $e->getMessage());
        }

        // Unsupported type.
        try {
            $builder2 = new scope_builder($course->id);
            $builder2->with_sources(['unsupported_123']);
            $this->fail('Expected coding_exception was not thrown');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('Unsupported source type', $e->getMessage());
        }
    }

    /**
     * Test extracting activities and blocks from sources.
     */
    public function test_get_activities_and_blocks_from_sources(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $builder = new scope_builder($course->id);
        $builder->with_sources(['cm_1', 'block_2', 'forum_3', 'cm_4', 'block_5']);

        $this->assertEquals([1, 4], $builder->get_activities_in_scope());
        $this->assertEquals([2, 5], $builder->get_block_contexts_in_scope());
    }

    /**
     * Test getting all activities when no filter is set.
     */
    public function test_get_all_activities_without_filter(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);

        $builder = new scope_builder($course->id);
        $cmids = $builder->get_activities_in_scope();

        $expectedcount = $DB->count_records('course_modules', ['course' => $course->id]);
        $this->assertCount($expectedcount, $cmids);
    }

    /**
     * Test participant filter with various input types.
     */
    public function test_participant_filter(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $builder = new scope_builder($course->id);
        $builder->filter_by_participants([1, 2, 3]);
        $this->assertEquals([1, 2, 3], $builder->get_participants_in_scope());

        // String IDs should be converted to int.
        $builder2 = new scope_builder($course->id);
        $builder2->filter_by_participants(['5', '10', '15']);
        $participants = $builder2->get_participants_in_scope();
        $this->assertSame([5, 10, 15], $participants);
        $this->assertIsInt($participants[0]);
    }

    /**
     * Test group filter resolves to correct participants.
     */
    public function test_group_filter(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $group1 = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id);
        $this->getDataGenerator()->enrol_user($user2->id, $course->id);
        $this->getDataGenerator()->enrol_user($user3->id, $course->id);

        groups_add_member($group1, $user1);
        groups_add_member($group1, $user2);

        $builder = new scope_builder($course->id);
        $builder->with_groups([$group1->id]);
        $participants = $builder->get_participants_in_scope();

        $this->assertCount(2, $participants);
        $this->assertContains($user1->id, $participants);
        $this->assertContains($user2->id, $participants);
        $this->assertNotContains($user3->id, $participants);
    }

    /**
     * Test time range validation and storage.
     */
    public function test_timerange(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        // Valid range.
        $builder = new scope_builder($course->id);
        $builder->with_timerange(1000, 2000);
        $json = $builder->build();
        $scope = scope_builder::parse($json);
        $this->assertEquals(1000, $scope->filters->timerange->start);
        $this->assertEquals(2000, $scope->filters->timerange->end);

        // Invalid: from > to.
        $this->expectException(\coding_exception::class);
        $builder2 = new scope_builder($course->id);
        $builder2->with_timerange(2000, 1000);
    }

    /**
     * Test build and parse round-trip preserves all data.
     */
    public function test_build_and_parse_roundtrip(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $builder = new scope_builder($course->id);
        $builder->with_sources(['cm_123', 'block_456'])
            ->filter_by_participants([10, 20])
            ->with_timerange(1000, 2000);

        $json = $builder->build();
        $scope = scope_builder::parse($json);

        $this->assertEquals($course->id, $scope->courseid);
        $this->assertEquals(['cm_123', 'block_456'], $scope->filters->sources);
        $this->assertEquals([10, 20], $scope->filters->participants);
        $this->assertEquals(1000, $scope->filters->timerange->start);
        $this->assertEquals(2000, $scope->filters->timerange->end);
    }

    /**
     * Test parse throws on invalid JSON.
     */
    public function test_parse_invalid_json(): void {
        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessage('Invalid scope JSON');
        scope_builder::parse('invalid json{');
    }

    /**
     * Test get_course_in_scope returns correct course ID.
     */
    public function test_get_course_in_scope(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $builder = new scope_builder($course->id);
        $this->assertEquals($course->id, $builder->get_course_in_scope());
    }
}
