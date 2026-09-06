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
use report_ai_analysis\local\forum_vault_provider;
use report_ai_analysis\scope_builder;

/**
 * Forum source authorisation, author isolation and bounded-read regression tests.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(mod_forum_provider::class)]
final class mod_forum_provider_test extends \advanced_testcase {
    /** @var \stdClass Test course. */
    private \stdClass $course;

    /** @var \stdClass Non-admin executing teacher. */
    private \stdClass $teacher;

    /** @var \stdClass First enrolled author. */
    private \stdClass $author1;

    /** @var \stdClass Second enrolled author. */
    private \stdClass $author2;

    /** @var int Editing teacher role ID. */
    private int $teacherrole;

    /**
     * Prepare real enrolments; collection is always performed as a non-admin teacher.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->teacher = $generator->create_user(['firstname' => 'Teacher', 'lastname' => 'Viewer']);
        $this->author1 = $generator->create_user(['firstname' => 'Rootowner', 'lastname' => 'Alpha']);
        $this->author2 = $generator->create_user(['firstname' => 'Replyauthor', 'lastname' => 'Beta']);
        $generator->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $generator->enrol_user($this->author1->id, $this->course->id, 'student');
        $generator->enrol_user($this->author2->id, $this->course->id, 'student');
        $this->teacherrole = (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
    }

    /**
     * Create a discussion with distinguishable subject and content.
     *
     * @param \stdClass $forum Forum record.
     * @param \stdClass $author Author record.
     * @param string $content Root content and title marker.
     * @param array $options Additional discussion fields.
     * @return \stdClass Discussion record.
     */
    private function discussion(\stdClass $forum, \stdClass $author, string $content, array $options = []): \stdClass {
        /** @var \mod_forum_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        return $generator->create_discussion(array_merge([
            'course' => $this->course->id,
            'forum' => $forum->id,
            'userid' => $author->id,
            'name' => 'Title ' . $content,
            'message' => $content,
            'messageformat' => FORMAT_PLAIN,
        ], $options));
    }

    /**
     * Create a post whose subject does not quote another author's root.
     *
     * @param \stdClass $discussion Discussion record.
     * @param \stdClass $author Author record.
     * @param string $content Post content.
     * @param array $options Additional post fields.
     * @return \stdClass Post record.
     */
    private function post(\stdClass $discussion, \stdClass $author, string $content, array $options = []): \stdClass {
        /** @var \mod_forum_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        return $generator->create_post(array_merge([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $author->id,
            'subject' => 'Own contribution',
            'message' => $content,
            'messageformat' => FORMAT_PLAIN,
        ], $options));
    }

    /**
     * Revoke a capability in the exact source module context.
     *
     * @param \stdClass $forum Forum record.
     * @param string $capability Capability to prohibit.
     */
    private function prohibit(\stdClass $forum, string $capability): void {
        assign_capability($capability, CAP_PROHIBIT, $this->teacherrole, \context_module::instance($forum->cmid)->id, true);
    }

    /**
     * A selected forum and author, separate groups and forum capabilities all constrain visible content.
     *
     * An explicit source and participant filter keeps only the chosen author's contributions, separate module
     * groups hide a readable author's other-group post, and private, deleted, timed and Q&A posts follow the
     * real forum capabilities.
     */
    public function test_author_source_isolation_and_visibility(): void {
        global $CFG;

        $generator = $this->getDataGenerator();

        // A selected forum and author exclude other forums, other authors' metadata and their roots.
        $forum1 = $generator->create_module('forum', ['course' => $this->course->id]);
        $forum2 = $generator->create_module('forum', ['course' => $this->course->id]);
        $discussion = $this->discussion($forum1, $this->author1, 'EXCLUDED_ROOT');
        $this->post($discussion, $this->author2, 'INCLUDED_REPLY');
        $this->post($discussion, $this->author1, 'EXCLUDED_OTHER_REPLY');
        $this->discussion($forum2, $this->author2, 'EXCLUDED_FORUM');
        $this->setUser($this->teacher);
        $scope = (new scope_builder($this->course->id))->with_sources(['cm_' . $forum1->cmid])
            ->filter_by_participants([$this->author2->id]);
        $data = (new mod_forum_provider($scope))->collect();
        $this->assertSame(1, mod_forum_provider::count_records($data));
        $this->assertSame([(int)$this->author2->id], mod_forum_provider::get_user_ids($data));
        $this->assertSame(0, $data[0]['starterid']);
        $this->assertStringContainsString('INCLUDED_REPLY', mod_forum_provider::format_for_ai($data));
        foreach (['EXCLUDED_ROOT', 'EXCLUDED_OTHER_REPLY', 'EXCLUDED_FORUM', 'Rootowner'] as $excluded) {
            $this->assertStringNotContainsString($excluded, json_encode($data));
        }

        // Separate module groups keep a readable author's other-group post inaccessible to the viewer.
        $groupforum = $generator->create_module('forum', ['course' => $this->course->id, 'groupmode' => SEPARATEGROUPS]);
        $group1 = $generator->create_group(['courseid' => $this->course->id]);
        $group2 = $generator->create_group(['courseid' => $this->course->id]);
        groups_add_member($group1, $this->teacher);
        groups_add_member($group1, $this->author1);
        groups_add_member($group2, $this->author2);
        $this->discussion($groupforum, $this->author1, 'OWN_GROUP_DISCUSSION', ['groupid' => $group1->id]);
        $this->discussion($groupforum, $this->author2, 'FOREIGN_GROUP_AUTHOR', ['groupid' => $group2->id]);
        $this->prohibit($groupforum, 'moodle/site:accessallgroups');
        $groupscope = (new scope_builder($this->course->id))->with_sources(['cm_' . $groupforum->cmid])
            ->filter_by_participants([$this->author1->id, $this->author2->id]);
        $groupdata = (new mod_forum_provider($groupscope))->collect();
        $this->assertStringContainsString('OWN_GROUP_DISCUSSION', mod_forum_provider::format_for_ai($groupdata));
        $this->assertStringNotContainsString('FOREIGN_GROUP', json_encode($groupdata));

        // Private and deleted replies obey the actual private-reply permission.
        $forum = $generator->create_module('forum', ['course' => $this->course->id]);
        $privatediscussion = $this->discussion($forum, $this->author1, 'PUBLIC_ROOT');
        $this->post($privatediscussion, $this->author1, 'PRIVATE_OTHER_USERS', ['privatereplyto' => $this->author2->id]);
        $this->post($privatediscussion, $this->author1, 'ADDRESSED_REPLY', ['privatereplyto' => $this->teacher->id]);
        $this->post($privatediscussion, $this->author2, 'DELETED_REPLY', ['deleted' => 1]);
        $this->prohibit($forum, 'mod/forum:readprivatereplies');
        $privatescope = (new scope_builder($this->course->id))->with_sources(['cm_' . $forum->cmid]);
        $privatetext = mod_forum_provider::format_for_ai((new mod_forum_provider($privatescope))->collect());
        $this->assertStringContainsString('PUBLIC_ROOT', $privatetext);
        $this->assertStringContainsString('ADDRESSED_REPLY', $privatetext);
        $this->assertStringNotContainsString('PRIVATE_OTHER_USERS', $privatetext);
        $this->assertStringNotContainsString('DELETED_REPLY', $privatetext);

        // Timed discussions require the hidden-timed capability, including the viewer's own future post.
        $CFG->forum_enabletimedposts = 1;
        $timedforum = $generator->create_module('forum', ['course' => $this->course->id]);
        $this->discussion($timedforum, $this->author1, 'VISIBLE_NOW');
        $this->discussion($timedforum, $this->author1, 'HIDDEN_FUTURE', ['timestart' => time() + DAYSECS]);
        $ownfuture = $this->discussion($timedforum, $this->teacher, 'OWN_FUTURE', ['timestart' => time() + DAYSECS]);
        $this->prohibit($timedforum, 'mod/forum:viewhiddentimedposts');
        $timedcontext = \context_module::instance($timedforum->cmid);
        $this->assertFalse(forum_user_can_see_discussion($timedforum, $ownfuture, $timedcontext, $this->teacher));
        $timedscope = (new scope_builder($this->course->id))->with_sources(['cm_' . $timedforum->cmid]);
        $timeddata = (new mod_forum_provider($timedscope))->collect();
        $timedtext = mod_forum_provider::format_for_ai($timeddata);
        $this->assertStringContainsString('VISIBLE_NOW', $timedtext);
        $this->assertStringNotContainsString('OWN_FUTURE', $timedtext);
        $this->assertStringNotContainsString('HIDDEN_FUTURE', json_encode($timeddata));

        // Q&A answers stay hidden until the viewer posts and the editing interval expires.
        $qandaforum = $generator->create_module('forum', ['course' => $this->course->id, 'type' => 'qanda']);
        $qandadiscussion = $this->discussion($qandaforum, $this->author1, 'VISIBLE_QUESTION');
        $this->post($qandadiscussion, $this->author2, 'QA_OTHER_ANSWER');
        $this->prohibit($qandaforum, 'mod/forum:viewqandawithoutposting');
        $qandascope = (new scope_builder($this->course->id))->with_sources(['cm_' . $qandaforum->cmid]);
        $this->assertStringNotContainsString('QA_OTHER_ANSWER', json_encode((new mod_forum_provider($qandascope))->collect()));
        $this->post($qandadiscussion, $this->teacher, 'OWN_ANSWER', ['created' => time() - $CFG->maxeditingtime - 60]);
        $this->assertStringContainsString(
            'QA_OTHER_ANSWER',
            mod_forum_provider::format_for_ai((new mod_forum_provider($qandascope))->collect())
        );
    }

    /**
     * A time range respects author and private visibility, the post budget is bounded to fetched IDs and
     * deep hierarchies and multibyte messages are preserved.
     *
     * Only visible selected-author posts qualify a thread for a time range, a large discussion fetches only
     * bounded IDs, an explicit empty participant selection stays empty, a deep reply chain keeps every post and
     * a long message is shortened on a character boundary.
     */
    public function test_timerange_budget_hierarchy_and_formatting(): void {
        $generator = $this->getDataGenerator();

        // Only visible selected-author posts qualify a thread for the time range.
        $forum = $generator->create_module('forum', ['course' => $this->course->id]);
        $oldtime = time() - 3 * DAYSECS;
        $discussion = $this->discussion($forum, $this->author1, 'OLD_EXCLUDED_ROOT', ['timemodified' => $oldtime]);
        $this->post($discussion, $this->author2, 'OLD_INCLUDED_AUTHOR', ['created' => $oldtime]);
        $this->post($discussion, $this->author1, 'RECENT_EXCLUDED_AUTHOR');
        $this->post($discussion, $this->author2, 'RECENT_PRIVATE', ['privatereplyto' => $this->author1->id]);
        $this->prohibit($forum, 'mod/forum:readprivatereplies');
        $this->setUser($this->teacher);
        $scope = (new scope_builder($this->course->id))->with_sources(['cm_' . $forum->cmid])
            ->filter_by_participants([$this->author2->id])->with_timerange(time() - DAYSECS, 0);
        $this->assertSame([], (new mod_forum_provider($scope))->collect());
        $this->post($discussion, $this->author2, 'RECENT_VISIBLE');
        $rangetext = mod_forum_provider::format_for_ai((new mod_forum_provider($scope))->collect());
        $this->assertStringContainsString('OLD_INCLUDED_AUTHOR', $rangetext);
        $this->assertStringContainsString('RECENT_VISIBLE', $rangetext);
        $this->assertStringNotContainsString('RECENT_PRIVATE', $rangetext);
        $this->assertStringNotContainsString('EXCLUDED', $rangetext);

        // A large discussion fetches only bounded IDs, never all posts followed by an array slice.
        $budgetforum = $generator->create_module('forum', ['course' => $this->course->id]);
        $budgetdiscussion = $this->discussion($budgetforum, $this->author1, 'FIRST');
        for ($index = 1; $index <= 12; $index++) {
            $this->post($budgetdiscussion, $this->author1, 'REPLY_' . $index);
        }
        $realvault = (new forum_vault_provider())->get_post_vault();
        $vault = $this->createMock(\mod_forum\local\vaults\post::class);
        $vault->expects($this->never())->method('get_from_discussion_id');
        $vault->expects($this->once())->method('get_from_ids')->willReturnCallback(function (array $ids) use ($realvault): array {
            $this->assertCount(3, $ids, 'Two budgeted posts and one lookahead are sufficient');
            return $realvault->get_from_ids($ids);
        });
        $adapter = $this->getMockBuilder(forum_vault_provider::class)->onlyMethods(['get_post_vault'])->getMock();
        $adapter->method('get_post_vault')->willReturn($vault);
        $budgetscope = (new scope_builder($this->course->id))->with_sources(['cm_' . $budgetforum->cmid]);
        $budgetprovider = new mod_forum_provider($budgetscope, 2, $adapter);
        $budgetdata = $budgetprovider->collect();
        $this->assertSame(2, mod_forum_provider::count_records($budgetdata));
        $this->assertTrue($budgetprovider->is_truncated());
        $this->assertStringNotContainsString('REPLY_2', json_encode($budgetdata));

        // An explicit empty participant selection stays empty.
        $emptyscope = (new scope_builder($this->course->id))->with_sources(['cm_' . $budgetforum->cmid])
            ->filter_by_participants([]);
        $this->assertSame([], (new mod_forum_provider($emptyscope, 2))->collect());

        // A deep reply chain stays JSON-serialisable without losing posts for an individual author.
        $posts = [];
        for ($id = 1; $id <= 300; $id++) {
            $posts[] = [
                'postid' => $id, 'parentid' => $id - 1, 'authorid' => (int)$this->author1->id,
                'timemodified' => 1000 + $id, 'message' => 'Post ' . $id, 'replies' => [],
            ];
        }
        $hierarchy = [['discussionid' => 1, 'firstpostid' => 1, 'posts' => $posts]];
        $filtered = mod_forum_provider::filter_by_user($hierarchy, $this->author1->id);
        $this->assertSame(300, mod_forum_provider::count_records($filtered));
        $this->assertStringContainsString('Post 300', json_encode($filtered));

        // Message shortening keeps a complete multibyte character and reports truncation.
        $unicodeforum = $generator->create_module('forum', ['course' => $this->course->id]);
        $this->discussion($unicodeforum, $this->author1, str_repeat('a', 1999) . 'äÖEND', ['name' => 'Unicode']);
        $unicodescope = (new scope_builder($this->course->id))->with_sources(['cm_' . $unicodeforum->cmid]);
        $unicodeprovider = new mod_forum_provider($unicodescope);
        $unicodetext = mod_forum_provider::format_for_ai($unicodeprovider->collect());
        $this->assertStringContainsString(str_repeat('a', 1999) . 'ä', $unicodetext);
        $this->assertStringNotContainsString('ÖEND', $unicodetext);
        $this->assertTrue($unicodeprovider->is_truncated());
    }
}
