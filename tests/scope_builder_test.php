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
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_ai_analysis\scope_builder
 */
final class scope_builder_test extends \advanced_testcase {
    /**
     * Test source validation with valid formats.
     */
    public function test_with_sources_valid_formats(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $builder = new scope_builder($course->id);

        // Valid formats should not throw exceptions.
        $builder->with_sources(['cm_123', 'block_456', 'forum_789']);
        $this->assertTrue(true); // If we reach here, validation passed.
    }

    /**
     * Test source validation with invalid formats.
     */
    public function test_with_sources_invalid_formats(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $builder = new scope_builder($course->id);

        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessage('Invalid source format');
        $builder->with_sources(['invalid_format']);
    }

    /**
     * Test source validation with invalid ID.
     */
    public function test_with_sources_invalid_id(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $builder = new scope_builder($course->id);

        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessage('Invalid source ID');
        $builder->with_sources(['cm_0']);
    }

    /**
     * Test source validation with invalid formats including negative numbers.
     */
    public function test_with_sources_invalid_formats_various(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();

        // Test with no underscore.
        try {
            $builder = new scope_builder($course->id);
            $builder->with_sources(['invalid_format_without_number']);
            $this->fail('Expected coding_exception was not thrown');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('Invalid source format', $e->getMessage());
        }

        // Test with negative number (won't match regex, so caught as invalid format).
        try {
            $builder = new scope_builder($course->id);
            $builder->with_sources(['cm_-5']);
            $this->fail('Expected coding_exception was not thrown');
        } catch (\coding_exception $e) {
            // Either "Invalid source format" or "Invalid source ID" is acceptable.
            $this->assertTrue(
                strpos($e->getMessage(), 'Invalid source') !== false,
                'Exception message should contain "Invalid source"'
            );
        }
    }

    /**
     * Test source validation with unsupported type.
     */
    public function test_with_sources_unsupported_type(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $builder = new scope_builder($course->id);

        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessage('Unsupported source type');
        $builder->with_sources(['unsupported_123']);
    }

    /**
     * Test extracting course module IDs from sources.
     */
    public function test_get_activities_with_sources(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $builder = new scope_builder($course->id);

        $builder->with_sources(['cm_10', 'cm_20', 'block_30']);
        $cmids = $builder->get_activities_in_scope();

        $this->assertEquals([10, 20], $cmids);
        $this->assertNotContains(30, $cmids); // Block should not be included.
    }

    /**
     * Test extracting block context IDs from sources.
     */
    public function test_get_block_contexts_with_sources(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $builder = new scope_builder($course->id);

        $builder->with_sources(['block_100', 'block_200', 'cm_300']);
        $blockids = $builder->get_block_contexts_in_scope();

        $this->assertEquals([100, 200], $blockids);
        $this->assertNotContains(300, $blockids); // CM should not be included.
    }

    /**
     * Test getting all activities when no filter is set.
     */
    public function test_get_activities_without_filter(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);

        $builder = new scope_builder($course->id);
        $cmids = $builder->get_activities_in_scope();

        // Should get all course modules in course.
        $expectedcount = $DB->count_records('course_modules', ['course' => $course->id]);
        $this->assertCount($expectedcount, $cmids);
    }

    /**
     * Test student filter validation.
     */
    public function test_with_students(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $builder = new scope_builder($course->id);

        $builder->with_students([1, 2, 3]);
        $students = $builder->get_students_in_scope();

        $this->assertEquals([1, 2, 3], $students);
    }

    /**
     * Test student filter with string IDs (should be converted to int).
     */
    public function test_with_students_string_conversion(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $builder = new scope_builder($course->id);

        $builder->with_students(['5', '10', '15']);
        $students = $builder->get_students_in_scope();

        $this->assertSame([5, 10, 15], $students);
        $this->assertIsInt($students[0]);
    }

    /**
     * Test group filter.
     */
    public function test_with_groups(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $group1 = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $group2 = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id);
        $this->getDataGenerator()->enrol_user($user2->id, $course->id);
        $this->getDataGenerator()->enrol_user($user3->id, $course->id);

        groups_add_member($group1, $user1);
        groups_add_member($group1, $user2);
        groups_add_member($group2, $user3);

        $builder = new scope_builder($course->id);
        $builder->with_groups([$group1->id]);
        $students = $builder->get_students_in_scope();

        $this->assertCount(2, $students);
        $this->assertContains($user1->id, $students);
        $this->assertContains($user2->id, $students);
        $this->assertNotContains($user3->id, $students);
    }

    /**
     * Test time range validation.
     */
    public function test_with_timerange_validation(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $builder = new scope_builder($course->id);

        // Valid range.
        $builder->with_timerange(1000, 2000);
        $this->assertTrue(true);

        // Invalid: from > to.
        $builder2 = new scope_builder($course->id);
        $this->expectException(\coding_exception::class);
        $builder2->with_timerange(2000, 1000);
    }

    /**
     * Test time range with negative values.
     */
    public function test_with_timerange_negative(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $builder = new scope_builder($course->id);

        $this->expectException(\coding_exception::class);
        $builder->with_timerange(-100, 1000);
    }

    /**
     * Test build and parse round-trip.
     */
    public function test_build_and_parse(): void {
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
     * Test parse with invalid JSON.
     */
    public function test_parse_invalid_json(): void {
        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessage('Invalid scope JSON');
        scope_builder::parse('invalid json{');
    }

    /**
     * Test get_allowed_ai_plugins delegates to source_registry.
     */
    public function test_get_allowed_ai_plugins(): void {
        $plugins = scope_builder::get_allowed_ai_plugins();

        $this->assertContains('aiplacement_courseassist', $plugins);
        $this->assertContains('block_ai_chat', $plugins);
        $this->assertCount(5, $plugins);
    }

    /**
     * Test is_allowed_ai_plugin delegates to source_registry.
     */
    public function test_is_allowed_ai_plugin(): void {
        $this->assertTrue(scope_builder::is_allowed_ai_plugin('aiplacement_courseassist'));
        $this->assertTrue(scope_builder::is_allowed_ai_plugin('block_ai_chat'));
        $this->assertFalse(scope_builder::is_allowed_ai_plugin('mod_forum'));
        $this->assertFalse(scope_builder::is_allowed_ai_plugin('invalid_plugin'));
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

    /**
     * Test empty sources array.
     */
    public function test_with_sources_empty(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $builder = new scope_builder($course->id);

        $builder->with_sources([]);
        $cmids = $builder->get_activities_in_scope();

        $this->assertEmpty($cmids);
    }

    /**
     * Test mixed source types.
     */
    public function test_mixed_source_types(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $builder = new scope_builder($course->id);

        $builder->with_sources(['cm_1', 'block_2', 'forum_3', 'cm_4', 'block_5']);

        $cmids = $builder->get_activities_in_scope();
        $this->assertEquals([1, 4], $cmids);

        $blockids = $builder->get_block_contexts_in_scope();
        $this->assertEquals([2, 5], $blockids);
    }
}
