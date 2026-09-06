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

use core_text;
use mod_forum\local\entities\discussion;
use mod_forum\local\entities\forum;
use mod_forum\local\entities\post;
use mod_forum\local\managers\capability;
use moodle_database;
use report_ai_analysis\local\forum_vault_provider;
use report_ai_analysis\scope_builder;

/**
 * Collects authorised forum posts, with author filtering rather than whole other-user threads.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_forum_provider extends base_provider {
    /** @var int Maximum entities fetched in one page. */
    private const PAGE_SIZE = 50;

    /** @var int Maximum nesting depth; deeper replies become roots without dropping content. */
    private const MAX_POST_DEPTH = 64;

    /** @var forum_vault_provider Forum entity and capability APIs. */
    private forum_vault_provider $forumvaultprovider;

    /** @var moodle_database Database used only for bounded candidate-ID reads and author names. */
    private moodle_database $db;

    /** @var int Posts examined in the last collection, including visibility and timerange probes. */
    private int $examinedposts = 0;

    /**
     * Constructor.
     *
     * @param scope_builder $scopebuilder Scope builder.
     * @param int $maxrecords Maximum individual posts.
     * @param forum_vault_provider|null $forumvaultprovider Injectable forum API adapter.
     * @param moodle_database|null $db Injectable database.
     */
    public function __construct(
        scope_builder $scopebuilder,
        int $maxrecords = 1000,
        ?forum_vault_provider $forumvaultprovider = null,
        ?moodle_database $db = null
    ) {
        global $DB;
        parent::__construct($scopebuilder, $maxrecords);
        $this->forumvaultprovider = $forumvaultprovider ?? new forum_vault_provider();
        $this->db = $db ?? $DB;
    }

    /**
     * Check whether the forum module is enabled.
     *
     * @return bool Availability.
     */
    public static function is_available(): bool {
        $plugin = \core_plugin_manager::instance()->get_plugin_info('mod_forum');
        return $plugin && $plugin->is_enabled();
    }

    /**
     * Get provider metadata.
     *
     * @return array Provider metadata.
     */
    public static function get_metadata(): array {
        return ['name' => get_string('pluginname', 'mod_forum'), 'type' => 'mod_forum'];
    }

    /**
     * Collect visible posts by selected, actively enrolled authors in selected course modules.
     *
     * A time range selects threads having at least one VISIBLE post by a SELECTED author in the range.
     * Older posts are then retained only for selected authors and only if the viewer may read them.
     * Removed parents become roots; an excluded starter's identity and discussion title are not retained.
     * The budget counts posts, not discussions. Both scan exhaustion and content shortening are explicit.
     *
     * @return array Discussions with posts, scope_semantics, timerange_semantics and truncated indicators.
     */
    public function collect(): array {
        global $CFG, $USER;

        $this->truncated = false;
        $this->examinedposts = 0;
        if (!self::is_available()) {
            throw new \moodle_exception('error_forum_not_available', 'report_ai_analysis');
        }
        $this->scopebuilder->validate_sources();
        $cmids = $this->scopebuilder->get_activities_in_scope();
        if (!$cmids) {
            return [];
        }

        $forums = $this->forumvaultprovider->get_forum_vault()->get_from_course_module_ids($cmids);
        $vault = $this->forumvaultprovider->get_discussions_vault();
        $timerange = $this->scopebuilder->get_timerange_in_scope();
        $items = [];
        $remaining = $this->maxrecords;
        $examineddiscussions = 0;

        foreach ($forums as $forum) {
            $manager = $this->forumvaultprovider->get_capability_manager($forum);
            if (!$manager->can_view_discussions($USER)) {
                if ($this->scopebuilder->get_sources_in_scope()) {
                    throw new \moodle_exception('error_source_forbidden', 'report_ai_analysis');
                }
                continue;
            }
            $participants = $this->scopebuilder->get_participants_in_scope($forum->get_context());
            if (!$participants) {
                continue;
            }
            $groups = $this->get_discussion_groups($forum, $manager);
            $includehidden = empty($CFG->forum_enabletimedposts) || $manager->can_view_hidden_posts($USER);
            $offset = 0;

            do {
                $limit = min(self::PAGE_SIZE, $remaining + 1, self::HARD_RECORD_LIMIT - $examineddiscussions);
                if ($limit <= 0 || $this->examinedposts >= self::HARD_RECORD_LIMIT) {
                    $this->truncated = true;
                    break 2;
                }
                if ($groups === null) {
                    $summaries = $vault->get_from_forum_id($forum->get_id(), $includehidden, $USER->id, null, $limit, $offset);
                } else {
                    $summaries = $vault->get_from_forum_id_and_group_id(
                        $forum->get_id(),
                        $groups,
                        $includehidden,
                        $USER->id,
                        null,
                        $limit,
                        $offset
                    );
                }
                $offset += count($summaries);
                $examineddiscussions += count($summaries);
                foreach ($summaries as $summary) {
                    $discussion = $summary->get_discussion();
                    if (!$manager->can_view_discussion($USER, $discussion)) {
                        continue;
                    }
                    if ($timerange && ($timerange->start || $timerange->end)) {
                        if (!$this->get_visible_posts($discussion, $manager, $participants, 1, $timerange)) {
                            continue;
                        }
                    }
                    $posts = $this->get_visible_posts($discussion, $manager, $participants, $remaining + 1);
                    $limited = count($posts) > $remaining;
                    if ($limited) {
                        array_pop($posts);
                        $this->truncated = true;
                    }
                    if ($posts) {
                        $items[] = ['forum' => $forum, 'discussion' => $discussion, 'posts' => $posts, 'truncated' => $limited];
                        $remaining -= count($posts);
                    }
                    if ($limited) {
                        break 3;
                    }
                }
            } while (count($summaries) === $limit);
        }

        // Load names only for authors actually included, not every enrolled user or excluded thread starters.
        $authorids = [];
        foreach ($items as $item) {
            foreach ($item['posts'] as $post) {
                $authorids[$post->get_author_id()] = $post->get_author_id();
            }
        }
        if (!$authorids) {
            return [];
        }
        $users = $this->db->get_records_list(
            'user',
            'id',
            $authorids,
            '',
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename'
        );
        $result = [];
        foreach ($items as $item) {
            $result[] = $this->structure_discussion($item, $users);
        }
        return $result;
    }

    /**
     * Intersect an optional discussion group selection with the viewer's source-level permissions.
     *
     * @param forum $forum Forum entity.
     * @param capability $manager Forum capability manager.
     * @return array|null Allowed group IDs, or null for no group restriction. Public discussions remain visible.
     */
    private function get_discussion_groups(forum $forum, capability $manager): ?array {
        global $USER;

        $groups = null;
        if ($forum->get_effective_group_mode() == SEPARATEGROUPS && !$manager->can_access_all_groups($USER)) {
            $groups = array_keys(groups_get_activity_allowed_groups($forum->get_course_module_record(), $USER->id));
        }
        $selected = $this->scopebuilder->get_groups_in_scope();
        if ($selected) {
            $coursegroups = array_keys(groups_get_all_groups($forum->get_course_id(), 0, 0, 'g.id'));
            $selected = array_values(array_intersect($selected, $coursegroups));
            $groups = $groups === null ? $selected : array_values(array_intersect($groups, $selected));
        }
        return $groups;
    }

    /**
     * Read bounded candidate IDs, retrieve entities through the vault, and apply the actual forum rules.
     *
     * The post vault's discussion/filter methods are unbounded. Never use them and slice afterwards.
     * Deleted/private posts are prefiltered; the capability manager additionally checks timed, Q&A,
     * private-reply, availability and group rules for every returned entity.
     *
     * @param discussion $discussion Visible discussion.
     * @param capability $manager Forum capability manager.
     * @param array $participants Author IDs already restricted by enrolment and scope.
     * @param int $limit Maximum visible posts needed, including at most one budget lookahead.
     * @param object|null $timerange Optional inclusive date boundaries used for thread qualification only.
     * @return post[] Authorised post entities.
     */
    private function get_visible_posts(
        discussion $discussion,
        capability $manager,
        array $participants,
        int $limit,
        ?object $timerange = null
    ): array {
        global $USER;

        [$authorsql, $params] = $this->db->get_in_or_equal($participants, SQL_PARAMS_NAMED, 'author');
        $where = "p.discussion = :discussionid AND p.userid $authorsql AND p.deleted = 0 AND p.id > :afterid";
        $params['discussionid'] = $discussion->get_id();
        if (!$manager->can_view_any_private_reply($USER)) {
            $where .= ' AND (p.privatereplyto = 0 OR p.privatereplyto = :recipient OR p.userid = :sender)';
            $params['recipient'] = $USER->id;
            $params['sender'] = $USER->id;
        }
        if (!empty($timerange->start)) {
            $where .= ' AND p.created >= :starttime';
            $params['starttime'] = $timerange->start;
        }
        if (!empty($timerange->end)) {
            $where .= ' AND p.created <= :endtime';
            $params['endtime'] = $timerange->end;
        }
        $posts = [];
        $afterid = 0;
        $vault = $this->forumvaultprovider->get_post_vault();
        while (count($posts) < $limit) {
            $pagesize = min(self::PAGE_SIZE, $limit - count($posts), self::HARD_RECORD_LIMIT - $this->examinedposts);
            if ($pagesize <= 0) {
                $this->truncated = true;
                break;
            }
            $params['afterid'] = $afterid;
            $records = $this->db->get_records_sql(
                "SELECT p.id FROM {forum_posts} p WHERE $where ORDER BY p.id",
                $params,
                0,
                $pagesize
            );
            if (!$records) {
                break;
            }
            $ids = array_keys($records);
            $afterid = (int)end($ids);
            $this->examinedposts += count($ids);
            $entities = $vault->get_from_ids($ids);
            foreach ($ids as $id) {
                $post = $entities[$id] ?? null;
                if ($post && !$post->is_deleted() && $manager->can_view_post($USER, $discussion, $post)) {
                    $posts[] = $post;
                }
            }
            if (count($records) < $pagesize) {
                break;
            }
        }
        return $posts;
    }

    /**
     * Structure one included discussion without adding excluded authors' names or root content.
     *
     * @param array $item Forum, discussion, included post entities and truncation state.
     * @param array $users Included authors' name records.
     * @return array Structured discussion.
     */
    private function structure_discussion(array $item, array $users): array {
        $discussion = $item['discussion'];
        $forum = $item['forum'];
        $course = $forum->get_course_record();
        $posts = [];
        $root = null;
        $modified = 0;
        foreach ($item['posts'] as $post) {
            $author = $users[$post->get_author_id()] ?? null;
            $name = $author ? fullname($author) : get_string('unknown', 'report_ai_analysis');
            $row = [
                'postid' => $post->get_id(),
                'parentid' => $post->get_parent_id(),
                'author' => $name,
                'username' => $name,
                'authorid' => $post->get_author_id(),
                'timecreated' => $post->get_time_created(),
                'created' => $post->get_time_created(),
                'timemodified' => $post->get_time_modified(),
                'subject' => $post->get_subject(),
                'message' => $post->get_message(),
                'messageformat' => $post->get_message_format(),
                'replies' => [],
            ];
            if ($post->get_id() === $discussion->get_first_post_id()) {
                $root = $row;
            }
            $modified = max($modified, $post->get_time_modified());
            if (core_text::strlen(trim(format_text_email($row['message'], $row['messageformat']))) > self::MAX_TEXT_LENGTH) {
                $item['truncated'] = true;
                $this->truncated = true;
            }
            $posts[] = $row;
        }
        $title = $root ? $root['subject'] : self::anonymous_discussion_title($discussion->get_id());
        return [
            'discussionid' => $discussion->get_id(),
            'title' => $title,
            'discussionname' => $title,
            'forumid' => $forum->get_id(),
            'forumname' => $forum->get_name(),
            'courseid' => (int)$course->id,
            'coursename' => $course->fullname,
            'courseshortname' => $course->shortname,
            'firstpostid' => $root['postid'] ?? 0,
            'starterid' => $root['authorid'] ?? 0,
            'startername' => $root['username'] ?? '',
            'timemodified' => $modified,
            'postcount' => count($posts),
            'posts' => self::build_hierarchy($posts),
            'scope_semantics' => 'visible_posts_by_selected_authors',
            'timerange_semantics' => 'threads_with_visible_selected_author_post_in_range',
            'truncated' => $item['truncated'],
        ];
    }

    /**
     * Build the existing hierarchical shape; excluded parents are not retained as metadata.
     *
     * @param array $posts Flat included posts.
     * @return array Hierarchical posts.
     */
    private static function build_hierarchy(array $posts): array {
        $indexed = [];
        foreach ($posts as $post) {
            $post['replies'] = [];
            $indexed[$post['postid']] = $post;
        }
        $roots = [];
        $depths = [];
        foreach ($indexed as $id => $post) {
            $depth = ($depths[$post['parentid']] ?? 0) + 1;
            if (
                $post['parentid'] && $post['parentid'] < $id && isset($indexed[$post['parentid']]) &&
                $depth < self::MAX_POST_DEPTH
            ) {
                $depths[$id] = $depth;
                $indexed[$post['parentid']]['replies'][] = &$indexed[$id];
            } else {
                $depths[$id] = 0;
                $indexed[$id]['parentid'] = 0;
                $roots[] = &$indexed[$id];
            }
        }
        return $roots;
    }

    /**
     * Flatten included posts for attribution and filtering, including represented thread roots.
     *
     * @param array $posts Hierarchical posts.
     * @return array Flat posts without nested replies.
     */
    private static function flatten_posts(array $posts): array {
        $result = [];
        foreach ($posts as $post) {
            $replies = $post['replies'] ?? [];
            $post['replies'] = [];
            $result[] = $post;
            array_push($result, ...self::flatten_posts($replies));
        }
        return $result;
    }

    /**
     * Get a neutral title when a discussion's root is excluded.
     *
     * @param int $discussionid Discussion identifier.
     * @return string Neutral title.
     */
    private static function anonymous_discussion_title(int $discussionid): string {
        return get_string('export_discussion', 'report_ai_analysis') . ' #' . $discussionid;
    }

    /**
     * Format only included discussions and posts.
     *
     * @param array $data Structured discussions.
     * @return string Formatted source data.
     */
    public static function format_for_ai(array $data): string {
        if (!$data) {
            return '';
        }
        $output = ['=== ' . get_string('export_discussions_header', 'report_ai_analysis') . ' ==='];
        $output[] = get_string('export_total_discussions', 'report_ai_analysis') . ': ' . count($data);
        foreach ($data as $discussion) {
            $output[] = "\n--- " . $discussion['discussionname'] . ' ---';
            $output[] = get_string('export_forum', 'report_ai_analysis') . ': ' . $discussion['forumname'];
            $output[] = get_string('export_course', 'report_ai_analysis') . ': ' . $discussion['coursename'] .
                ' (' . $discussion['courseshortname'] . ')';
            if (!empty($discussion['starterid'])) {
                $output[] = get_string('export_started_by', 'report_ai_analysis') . ': ' . $discussion['startername'];
            }
            $output[] = get_string('export_posts', 'report_ai_analysis') . ': ' . self::count_records([$discussion]);
            foreach (self::flatten_posts($discussion['posts']) as $post) {
                $output[] = '[' . get_string('bynameondate', 'forum', [
                    'name' => $post['username'],
                    'date' => userdate($post['created'], '%Y-%m-%d %H:%M:%S'),
                ]) . ']';
                $output[] = get_string('subject', 'forum') . ': ' . $post['subject'];
                $output[] = self::format_message($post['message'], $post['messageformat']);
                $output[] = '';
            }
            if (!empty($discussion['truncated'])) {
                $output[] = get_string('export_truncated', 'report_ai_analysis');
            }
        }
        return implode("\n", $output);
    }

    /**
     * Count included posts, never use a possibly stale discussion postcount.
     *
     * @param array $data Structured discussions.
     * @return int Included post count.
     */
    public static function count_records(array $data): int {
        $count = 0;
        foreach ($data as $discussion) {
            $count += count(self::flatten_posts($discussion['posts']));
        }
        return $count;
    }

    /**
     * Identify actual authors from all represented posts, including roots and nested replies.
     *
     * @param array $data Structured discussions.
     * @return int[] Author identifiers.
     */
    public static function get_user_ids(array $data): array {
        $users = [];
        foreach ($data as $discussion) {
            foreach (self::flatten_posts($discussion['posts']) as $post) {
                $users[(int)$post['authorid']] = (int)$post['authorid'];
            }
        }
        return array_values($users);
    }

    /**
     * Filter posts by author, removing other authors' starter identity and root title.
     *
     * Authored text is not rewritten: an author may have quoted somebody else inside their own post.
     *
     * @param array $data Structured discussions.
     * @param int $userid Author identifier.
     * @return array Same discussion shape with this author's posts only.
     */
    public static function filter_by_user(array $data, int $userid): array {
        $result = [];
        foreach ($data as $discussion) {
            $posts = array_values(array_filter(
                self::flatten_posts($discussion['posts']),
                static fn($post) => (int)$post['authorid'] === $userid
            ));
            if (!$posts) {
                continue;
            }
            $rootincluded = !empty($discussion['firstpostid']) && in_array(
                $discussion['firstpostid'],
                array_column($posts, 'postid')
            );
            if (!$rootincluded) {
                $discussion['starterid'] = 0;
                $discussion['startername'] = '';
                $discussion['firstpostid'] = 0;
                $discussion['title'] = self::anonymous_discussion_title($discussion['discussionid']);
                $discussion['discussionname'] = $discussion['title'];
            }
            $discussion['timemodified'] = max(array_column($posts, 'timemodified'));
            $discussion['posts'] = self::build_hierarchy($posts);
            $discussion['postcount'] = count($posts);
            $result[] = $discussion;
        }
        return $result;
    }

    /**
     * Summarise included data only.
     *
     * @param array $data Structured discussions.
     * @return array Statistics, including exact post and participant counts.
     */
    public static function get_statistics(array $data): array {
        $forums = array_column($data, 'forumname', 'forumid');
        $courses = array_column($data, 'coursename', 'courseid');
        $posts = self::count_records($data);
        return [
            'total_discussions' => count($data),
            'total_posts' => $posts,
            'total_forums' => count($forums),
            'total_courses' => count($courses),
            'participants' => count(self::get_user_ids($data)),
            'forums' => $forums,
            'courses' => $courses,
            'avg_posts_per_discussion' => $data ? round($posts / count($data), 2) : 0,
        ];
    }

    /**
     * Resolve only supported forum identifiers belonging to this course.
     *
     * @param string $sourceidentifier Course module or legacy forum identifier.
     * @return bool Whether this is a forum source in the scope course.
     */
    public function handles_source(string $sourceidentifier): bool {
        if (!preg_match('/^(cm|forum)_([1-9][0-9]*)$/', $sourceidentifier, $matches)) {
            return false;
        }
        foreach (get_fast_modinfo($this->scopebuilder->get_course_in_scope())->get_cms() as $cm) {
            $id = $matches[1] === 'cm' ? $cm->id : $cm->instance;
            if ($cm->modname === 'forum' && (int)$id === (int)$matches[2]) {
                return true;
            }
        }
        return false;
    }
}
