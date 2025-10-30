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
 * Mod Forum provider for mod_forum discussions.
 *
 * @package    report_ai_analysis
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis\provider;

use report_ai_analysis\scope_builder;
use mod_forum\local\container as forum_container;

/**
 * Provider for mod_forum discussion data.
 *
 * Collects structured forum discussion data using Moodle's forum vault system.
 * Each discussion is treated as a complete conversation unit with hierarchical
 * post structure for AI analysis.
 *
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_forum_provider extends base_provider {
    /**
     * Check if mod_forum is available.
     *
     * @return bool True if mod_forum is installed and enabled
     */
    public static function is_available(): bool {
        global $CFG;

        // Check if forum module exists.
        $forumpath = $CFG->dirroot . '/mod/forum';
        if (!file_exists($forumpath)) {
            return false;
        }

        // Check if module is enabled.
        $pluginmanager = \core_plugin_manager::instance();
        $foruminfo = $pluginmanager->get_plugin_info('mod_forum');

        return $foruminfo && $foruminfo->is_enabled();
    }

    /**
     * Get provider metadata.
     *
     * @return array Metadata array
     */
    public static function get_metadata(): array {
        return [
            'name' => get_string('pluginname', 'mod_forum'),
            'type' => 'mod_forum',
            'description' => 'Forum discussion threads',
        ];
    }

    /**
     * Collect forum discussions based on scope.
     *
     * @return array Array of structured discussions with posts
     * @throws \moodle_exception If mod_forum is not available
     */
    public function collect(): array {
        if (!self::is_available()) {
            throw new \moodle_exception('error_forum_not_available', 'report_ai_analysis');
        }

        global $DB, $USER;

        $alldiscussions = [];

        // Get scope parameters.
        $courseid = $this->scopebuilder->get_course_in_scope();

        if (empty($courseid)) {
            return [];
        }

        // Get all forums in the course.
        $forums = $DB->get_records('forum', ['course' => $courseid]);

        if (empty($forums)) {
            return [];
        }

        // Get vault factory from forum container.
        $vaultfactory = forum_container::get_vault_factory();
        $discussionvault = $vaultfactory->get_discussions_in_forum_vault();
        $postvault = $vaultfactory->get_post_vault();

        // Pre-collect all discussions to batch load users.
        $discussionstoprocess = [];
        foreach ($forums as $forum) {
            // Get discussions using forum vault.
            $discussions = $discussionvault->get_from_forum_id(
                $forum->id,
                true, // Include hidden discussions.
                null, // Don't restrict to specific user.
                null, // Use default sort order.
                $this->maxrecords,
                0
            );

            // Convert to array_values to ensure numeric indexing for iteration.
            $discussions = array_values($discussions);

            foreach ($discussions as $discussionsummary) {
                $discussion = $discussionsummary->get_discussion();

                // Get all posts for this discussion using vault.
                $posts = $postvault->get_from_discussion_id(
                    $USER,
                    $discussion->get_id(),
                    true  // Can see private replies.
                );

                // Convert posts to array_values for proper iteration.
                $posts = array_values($posts);

                // Filter: Check timerange if specified - check if any post is in timerange.
                $timerange = $this->scopebuilder->get_timerange_in_scope();

                if ($timerange !== null) {
                    // Check if at least one post is in timerange, but keep ALL posts.
                    if (!$this->has_post_in_timerange($posts, $timerange)) {
                        continue; // No posts in timerange, skip this discussion.
                    }
                    // Keep all posts for analysis, even those outside timerange.
                }

                $discussionstoprocess[] = [
                    'discussion' => $discussion,
                    'posts' => $posts,
                    'forum' => $forum,
                ];

                // Limit total discussions.
                if (count($discussionstoprocess) >= $this->maxrecords) {
                    break 2;
                }
            }
        }

        // Batch load all discussion starter users.
        $starteruserids = array_unique(array_map(function ($item) {
            return $item['discussion']->get_user_id();
        }, $discussionstoprocess));

        $userfields = 'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename';
        $starterusers = $DB->get_records_list('user', 'id', $starteruserids, '', $userfields);

        // Now process discussions with pre-loaded users.
        foreach ($discussionstoprocess as $item) {
            $structured = $this->structure_discussion(
                $item['discussion'],
                $item['posts'],
                $item['forum'],
                $courseid,
                $starterusers
            );
            if (!empty($structured['posts'])) {
                $alldiscussions[] = $structured;
            }
        }

        return $alldiscussions;
    }

    /**
     * Check if any post is within timerange.
     *
     * Checks if at least one post has timecreated within the specified timerange.
     *
     * @param array $posts Array of post entities
     * @param object $timerange Object with 'start' and 'end' properties (Unix timestamps)
     * @return bool True if at least one post is in timerange
     */
    private function has_post_in_timerange(array $posts, object $timerange): bool {
        foreach ($posts as $post) {
            $timecreated = $post->get_time_created();
            if ($timecreated >= $timerange->start && $timecreated <= $timerange->end) {
                return true;
            }
        }
        return false;
    }

    /**
     * Structure a discussion with all its posts.
     *
     * @param \mod_forum\local\entities\discussion $discussion Discussion entity
     * @param array $posts Array of post entities
     * @param \stdClass $forum Forum record
     * @param int $courseid Course ID
     * @param array $starterusers Pre-loaded user records indexed by user ID
     * @return array Structured discussion data
     */
    private function structure_discussion($discussion, array $posts, $forum, int $courseid, array $starterusers): array {
        global $DB;

        // Get course info.
        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname');

        // Get discussion starter info from pre-loaded users.
        $starterid = $discussion->get_user_id();
        $starter = $starterusers[$starterid] ?? null;

        if (!$starter) {
            // Fallback if user not found in cache.
            $starter = $DB->get_record(
                'user',
                ['id' => $starterid],
                'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename'
            );
        }

        // Build hierarchical post structure.
        $structuredposts = $this->build_post_hierarchy($posts);

        return [
            'discussionid' => $discussion->get_id(),
            'title' => $discussion->get_name(),
            'discussionname' => $discussion->get_name(),
            'forumid' => $forum->id,
            'forumname' => $forum->name,
            'courseid' => $course->id,
            'coursename' => $course->fullname,
            'courseshortname' => $course->shortname,
            'starterid' => $starter->id,
            'startername' => fullname($starter),
            'timemodified' => $discussion->get_time_modified(),
            'postcount' => count($posts),
            'posts' => $structuredposts,
        ];
    }

    /**
     * Build hierarchical post structure from forum post entities.
     *
     * @param array $postentities Array of post entities
     * @return array Hierarchical array of posts
     */
    private function build_post_hierarchy(array $postentities): array {
        global $DB;

        $hierarchy = [];
        $indexed = [];

        // Pre-load all users for efficiency.
        $userids = array_unique(array_map(function ($post) {
            return $post->get_author_id();
        }, $postentities));

        $users = $DB->get_records_list(
            'user',
            'id',
            $userids,
            '',
            'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename'
        );

        // Convert entities to structured arrays and index by ID.
        foreach ($postentities as $postentity) {
            $authorid = $postentity->get_author_id();
            $user = $users[$authorid] ?? null;
            $authorname = $user ? fullname($user) : 'Unknown User';

            $indexed[$postentity->get_id()] = [
                'postid' => $postentity->get_id(),
                'parentid' => $postentity->get_parent_id(),
                'author' => $authorname,
                'username' => $authorname,
                'authorid' => $authorid,
                'timecreated' => $postentity->get_time_created(),
                'created' => $postentity->get_time_created(),
                'timemodified' => $postentity->get_time_modified(),
                'subject' => $postentity->get_subject(),
                'message' => $postentity->get_message(),
                'messageformat' => FORMAT_HTML,
                'replies' => [],
            ];
        }

        // Build hierarchy.
        foreach ($indexed as $id => $post) {
            if ($post['parentid'] == 0) {
                // Root post.
                $hierarchy[] = &$indexed[$id];
            } else if (isset($indexed[$post['parentid']])) {
                // Add as reply to parent.
                $indexed[$post['parentid']]['replies'][] = &$indexed[$id];
            } else {
                // Parent not found, treat as root.
                $hierarchy[] = &$indexed[$id];
            }
        }

        return $hierarchy;
    }

    /**
     * Format discussions for AI analysis.
     *
     * @param array $discussions Array of discussion threads
     * @return string Formatted discussion data
     */
    public static function format_for_ai(array $discussions): string {
        if (empty($discussions)) {
            return '';
        }

        $output = [];
        $output[] = "=== " . get_string('export_discussions_header', 'report_ai_analysis') . " ===\n";
        $output[] = get_string('export_total_discussions', 'report_ai_analysis') . ": " . count($discussions) . "\n\n";

        foreach ($discussions as $discussion) {
            $output[] = "--- " . get_string('export_discussion', 'report_ai_analysis') .
                        ": " . $discussion['discussionname'] . " ---";
            $output[] = get_string('export_forum', 'report_ai_analysis') . ": " . $discussion['forumname'];
            $output[] = get_string('export_course', 'report_ai_analysis') . ": " .
                        $discussion['coursename'] . " (" . $discussion['courseshortname'] . ")";
            $output[] = get_string('export_started_by', 'report_ai_analysis') . ": " .
                        $discussion['startername'] . " (ID: " . $discussion['starterid'] . ")";
            $output[] = get_string('export_modified', 'report_ai_analysis') . ": " .
                        userdate($discussion['timemodified'], '%Y-%m-%d %H:%M:%S');
            $output[] = get_string('export_posts', 'report_ai_analysis') . ": " . $discussion['postcount'];
            $output[] = "";

            // Format posts hierarchically.
            $output[] = self::format_posts_recursive($discussion['posts'], 0);

            $output[] = str_repeat('-', 60);
            $output[] = "";
        }

        return implode("\n", $output);
    }

    /**
     * Format posts recursively with indentation.
     *
     * @param array $posts Array of posts
     * @param int $level Indentation level
     * @return string Formatted posts
     */
    private static function format_posts_recursive(array $posts, int $level): string {
        $output = [];
        $indent = str_repeat('  ', $level);

        foreach ($posts as $post) {
            $output[] = $indent . "[POST by " . $post['username'] . " at " .
                        userdate($post['created'], '%Y-%m-%d %H:%M:%S') . "]";
            $output[] = $indent . "Subject: " . $post['subject'];

            // Clean and format message.
            $message = format_text_email($post['message'], $post['messageformat']);
            $message = trim($message);

            // Limit very long messages.
            if (\core_text::strlen($message) > 2000) {
                $message = \core_text::substr($message, 0, 2000) . '... ' . get_string('export_truncated', 'report_ai_analysis');
            }

            $output[] = $indent . $message;
            $output[] = "";

            // Format replies recursively.
            if (!empty($post['replies'])) {
                $output[] = self::format_posts_recursive($post['replies'], $level + 1);
            }
        }

        return implode("\n", $output);
    }

    /**
     * Get discussion statistics.
     *
     * @param array $discussions Array of discussion threads
     * @return array Statistics
     */
    public static function get_statistics(array $discussions): array {
        $stats = [
            'total_discussions' => count($discussions),
            'total_posts' => 0,
            'total_forums' => 0,
            'total_courses' => 0,
            'participants' => 0,
            'forums' => [],
            'courses' => [],
            'avg_posts_per_discussion' => 0,
        ];

        $participants = [];

        foreach ($discussions as $discussion) {
            $stats['total_posts'] += $discussion['postcount'];
            $stats['forums'][$discussion['forumid']] = $discussion['forumname'];
            $stats['courses'][$discussion['courseid']] = $discussion['coursename'];

            // Count unique participants.
            $participants[$discussion['starterid']] = true;

            // Count participants from posts.
            self::count_participants_from_posts($discussion['posts'], $participants);
        }

        $stats['total_forums'] = count($stats['forums']);
        $stats['total_courses'] = count($stats['courses']);
        $stats['participants'] = count($participants);

        if ($stats['total_discussions'] > 0) {
            $stats['avg_posts_per_discussion'] = round($stats['total_posts'] / $stats['total_discussions'], 2);
        }

        return $stats;
    }

    /**
     * Count unique participants from posts recursively.
     *
     * @param array $posts Array of posts
     * @param array $participants Reference to participants array (passed by reference)
     * @return void
     */
    private static function count_participants_from_posts(array $posts, array &$participants): void {
        foreach ($posts as $post) {
            $participants[$post['authorid']] = true;

            if (!empty($post['replies'])) {
                self::count_participants_from_posts($post['replies'], $participants);
            }
        }
    }

    /**
     * Check if this provider handles the given source identifier.
     *
     * Handles source identifiers starting with 'cm_' that are forum course modules.
     *
     * @param string $sourceidentifier Source identifier (e.g., 'cm_123')
     * @return bool True if this provider handles this source
     */
    public function handles_source(string $sourceidentifier): bool {
        // Check if it's a course module source.
        if (strpos($sourceidentifier, 'cm_') !== 0) {
            return false;
        }

        // Extract CM ID and verify it's a forum.
        $cmid = (int)substr($sourceidentifier, 3);
        if ($cmid <= 0) {
            return false;
        }

        global $DB;
        try {
            // Get module type.
            $moduleid = $DB->get_field('course_modules', 'module', ['id' => $cmid], IGNORE_MISSING);
            if (!$moduleid) {
                return false;
            }

            $modulename = $DB->get_field('modules', 'name', ['id' => $moduleid], IGNORE_MISSING);
            return $modulename === 'forum';
        } catch (\Exception $e) {
            return false;
        }
    }
}
