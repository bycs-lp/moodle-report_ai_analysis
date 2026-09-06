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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for scope_builder class.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(scope_builder::class)]
final class scope_builder_test extends \advanced_testcase {
    /**
     * Source identifiers, activity availability and participant/group/role filters all resolve fail-closed.
     *
     * Valid identifiers resolve to their activity and block contexts while malformed, unsupported or
     * cross-course identifiers are rejected, a non-admin sees only supported visible modules and a selected
     * hidden module fails closed, and participant/role/group filters intersect with active enrolment without
     * ever expanding the course scope.
     */
    public function test_sources_activities_and_participant_filters(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();

        // Valid formats resolve to their activity and block contexts.
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);
        $forum = $generator->create_module('forum', ['course' => $course->id]);
        $block = $generator->create_block('ai_chat', ['parentcontextid' => $context->id]);
        $blockcontext = \context_block::instance($block->id);
        $resolved = (new scope_builder($course->id))
            ->with_sources(['cm_' . $forum->cmid, 'block_' . $blockcontext->id, 'forum_' . $forum->id]);
        $this->assertSame([(int)$forum->cmid], $resolved->get_activities_in_scope());
        $this->assertSame([(int)$blockcontext->id], $resolved->get_block_contexts_in_scope());

        // Malformed and unsupported identifiers are rejected with a descriptive coding exception.
        $invalidsources = [
            ['invalid_format', 'Invalid source format'],
            ['cm_0', 'Invalid source ID'],
            ['unsupported_123', 'Unsupported source type'],
        ];
        foreach ($invalidsources as [$source, $message]) {
            try {
                (new scope_builder($course->id))->with_sources([$source]);
                $this->fail('Expected coding_exception was not thrown for ' . $source);
            } catch (\coding_exception $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }

        // An identifier from another course fails closed even for an administrator.
        $othercourse = $generator->create_course();
        $foreignforum = $generator->create_module('forum', ['course' => $othercourse->id]);
        try {
            (new scope_builder($course->id))->with_sources(['cm_' . $foreignforum->cmid])->validate_sources();
            $this->fail('A cross-course source was accepted');
        } catch (\moodle_exception $exception) {
            $this->assertSame('error_source_forbidden', $exception->errorcode);
        }

        // A non-admin sees only supported visible modules; a selected hidden module fails closed.
        $CFG->enableavailability = true;
        $restrictedcourse = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $restrictedcourse->id, 'editingteacher');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $restrictedcontext = \context_course::instance($restrictedcourse->id);
        assign_capability('moodle/course:viewhiddenactivities', CAP_PROHIBIT, $roleid, $restrictedcontext->id, true);
        $visible = $generator->create_module('forum', ['course' => $restrictedcourse->id]);
        $hidden = $generator->create_module('forum', ['course' => $restrictedcourse->id, 'visible' => 0]);
        $generator->create_module('quiz', ['course' => $restrictedcourse->id]);
        $this->setUser($teacher);
        $this->assertSame([(int)$visible->cmid], (new scope_builder($restrictedcourse->id))->get_activities_in_scope());
        try {
            (new scope_builder($restrictedcourse->id))->with_sources(['cm_' . $hidden->cmid])->get_activities_in_scope();
            $this->fail('Selected inaccessible activity was accepted');
        } catch (\moodle_exception $exception) {
            $this->assertSame('error_source_forbidden', $exception->errorcode);
        }

        // Participant, role and group filters intersect with active enrolment and never expand the scope.
        $this->setAdminUser();
        $intersectcourse = $generator->create_course();
        $studentrole = (int)$DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $group = $generator->create_group(['courseid' => $intersectcourse->id]);
        $included = $generator->create_user();
        $suspended = $generator->create_user();
        $unenrolled = $generator->create_user();
        $outsider = $generator->create_user();
        $generator->enrol_user($included->id, $intersectcourse->id, 'student');
        $generator->enrol_user($suspended->id, $intersectcourse->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $generator->role_assign('student', $unenrolled->id, \context_course::instance($intersectcourse->id)->id);
        foreach ([$included, $suspended] as $member) {
            $this->assertTrue($generator->create_group_member(['groupid' => $group->id, 'userid' => $member->id]));
        }

        // An outsider is dropped and a string ID becomes an integer.
        $normalised = (new scope_builder($intersectcourse->id))
            ->filter_by_participants([(string)$included->id, $outsider->id]);
        $participants = $normalised->get_participants_in_scope();
        $this->assertSame([(int)$included->id], $participants);
        $this->assertIsInt($participants[0]);

        // User, role and group filters are independent intersections with active enrolment and round-trip.
        $intersect = (new scope_builder($intersectcourse->id))
            ->filter_by_participants([$included->id, $suspended->id, $unenrolled->id], [$studentrole])
            ->with_groups([$group->id]);
        $this->assertSame([(int)$included->id], $intersect->get_participants_in_scope());
        $this->assertSame(
            $intersect->get_participants_in_scope(),
            scope_builder::from_json($intersect->build())->get_participants_in_scope()
        );

        // An explicit empty selection survives rebuilding and never expands to the whole course.
        $empty = (new scope_builder($intersectcourse->id))->filter_by_participants([]);
        $this->assertSame([], scope_builder::from_json($empty->build())->get_participants_in_scope());
        $this->assertNotEmpty((new scope_builder($intersectcourse->id))->get_participants_in_scope());

        // A group from another course cannot expand the selection.
        $foreigngroup = $generator->create_group(['courseid' => $course->id]);
        $this->assertSame(
            [],
            (new scope_builder($intersectcourse->id))->with_groups([$foreigngroup->id])->get_participants_in_scope()
        );
    }

    /**
     * Group-mode authorisation, time-range persistence and malformed/legacy scope JSON all fail closed.
     *
     * In separate groups a teacher without accessallgroups may only select their own group's members while a
     * NOGROUPS module override follows the effective group mode, time ranges (including open boundaries) survive
     * build, parse and database persistence, and malformed or legacy scope JSON never becomes an unfiltered
     * scope or restores forged identity metadata.
     */
    public function test_group_authorisation_timerange_and_json(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();

        // Separate groups are an authorisation boundary and a module override follows the effective group mode.
        $course = $generator->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 0]);
        $context = \context_course::instance($course->id);
        $teacher = $generator->create_user();
        $user1 = $generator->create_user();
        $user2 = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($user1->id, $course->id, 'student');
        $generator->enrol_user($user2->id, $course->id, 'student');
        $group1 = $generator->create_group(['courseid' => $course->id]);
        $group2 = $generator->create_group(['courseid' => $course->id]);
        $this->assertTrue($generator->create_group_member(['groupid' => $group1->id, 'userid' => $teacher->id]));
        $this->assertTrue($generator->create_group_member(['groupid' => $group1->id, 'userid' => $user1->id]));
        $this->assertTrue($generator->create_group_member(['groupid' => $group2->id, 'userid' => $user2->id]));
        $forum = $generator->create_module('forum', ['course' => $course->id, 'groupmode' => NOGROUPS]);
        $forumcontext = \context_module::instance($forum->cmid);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('moodle/site:accessallgroups', CAP_PROHIBIT, $roleid, $context->id, true);
        $this->setUser($teacher);
        $builder = (new scope_builder($course->id))->filter_by_participants([$user1->id, $user2->id]);
        $this->assertSame([(int)$user1->id], $builder->get_participants_in_scope());
        $this->assertSame([], $builder->with_groups([$group2->id])->get_participants_in_scope());
        $override = (new scope_builder($course->id))->filter_by_participants([$user2->id]);
        $this->assertSame([(int)$user2->id], $override->get_participants_in_scope($forumcontext));
        $this->assertSame([], $override->get_participants_in_scope());

        // A valid range survives build/parse and an inverted range is rejected.
        $this->setAdminUser();
        $stored = scope_builder::parse((new scope_builder($course->id))->with_sources(['cm_123'])
            ->filter_by_participants([10])->with_timerange(1000, 2000)->build());
        $this->assertSame(1000, (int)$stored->filters->timerange->start);
        $this->assertSame(2000, (int)$stored->filters->timerange->end);
        $this->assertEquals(['cm_123'], $stored->filters->sources);
        try {
            (new scope_builder($course->id))->with_timerange(2000, 1000);
            $this->fail('An inverted time range was accepted');
        } catch (\coding_exception $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        // Open boundaries and the analysis mode survive reconstruction, and a stored range round-trips via the DB.
        $open = scope_builder::from_json((new scope_builder($course->id))->with_timerange(0, 2000)->build());
        $this->assertSame(0, $open->get_timerange_in_scope()->start);
        $this->assertSame(2000, $open->get_timerange_in_scope()->end);
        $this->assertSame(scope_builder::ANALYSIS_MODE_AGGREGATED, $open->get_analysis_mode());
        $record = (object)[
            'contextid' => $context->id, 'title' => 'Test Report',
            'scope_details' => (new scope_builder($course->id))->with_timerange(1500, 2500)->build(),
            'prompt' => 'Test prompt', 'status' => 'pending', 'userid' => 2,
            'timecreated' => time(), 'timemodified' => time(),
        ];
        $reportid = $DB->insert_record('report_ai_analysis_reports', $record);
        $persisted = scope_builder::parse($DB->get_record('report_ai_analysis_reports', ['id' => $reportid])->scope_details);
        $this->assertSame(1500, (int)$persisted->filters->timerange->start);
        $this->assertIsInt($persisted->filters->timerange->start);

        // Malformed JSON is rejected and structurally invalid shapes never become an unfiltered scope.
        try {
            scope_builder::parse('invalid json{');
            $this->fail('Invalid scope JSON was accepted');
        } catch (\coding_exception $exception) {
            $this->assertStringContainsString('Invalid scope JSON', $exception->getMessage());
        }
        $invalidscopes = [
            'null',
            '[]',
            json_encode(['courseid' => $course->id, 'filters' => ['participants' => ['12invalid']]]),
            json_encode(['courseid' => $course->id, 'filters' => ['timerange' => ['start' => -1, 'end' => 0]]]),
        ];
        foreach ($invalidscopes as $json) {
            try {
                scope_builder::from_json($json);
                $this->fail('Invalid scope JSON was accepted');
            } catch (\coding_exception $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }

        // Legacy filters are normalised and forged identity/permission metadata is never restored.
        $legacy = json_encode([
            'courseid' => $course->id, 'username' => 'Forged name', 'canviewall' => true,
            'filters' => ['students' => [], 'roleids' => [12], 'timerange' => ['start' => 1000]],
        ]);
        $scope = scope_builder::parse(scope_builder::from_json($legacy)->build());
        $this->assertSame([], $scope->filters->participants);
        $this->assertSame([12], $scope->filters->roles);
        $this->assertObjectNotHasProperty('username', $scope);
        $this->assertObjectNotHasProperty('canviewall', $scope);
        $this->assertSame(0, (int)$scope->filters->timerange->end);
    }
}
