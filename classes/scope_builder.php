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
 * Scope builder for AI analysis reports.
 *
 * @package    report_ai_analysis
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis;

/**
 * Builds scope definitions for AI analysis in course context.
 *
 * Defines which data sources (activities, blocks, forums, etc.) to include
 * based on course and filters. Supports flexible source types through
 * the source_registry whitelist system.
 *
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scope_builder {
    /**
     * Analysis mode: Individual analysis per participant.
     */
    const ANALYSIS_MODE_INDIVIDUAL = 'individual';

    /**
     * Analysis mode: Aggregated analysis across all participants.
     */
    const ANALYSIS_MODE_AGGREGATED = 'aggregated';

    /** @var int The course ID */
    private $courseid;

    /** @var array Filters for the scope */
    private $filters = [];

    /** @var object The scope object */
    private $scope;

    /** @var array Static cache for parsed scope JSON (Request-level cache) */
    private static $parsecache = [];

    /** @var array Static cache for role names (Request-level cache) */
    private static $rolenamecache = [];

    /**
     * Constructor.
     *
     * @param int $courseid The course ID for analysis
     */
    public function __construct(int $courseid) {
        $this->courseid = $courseid;
        $this->scope = new \stdClass();
        $this->scope->filters = new \stdClass();
    }

    /**
     * Set analysis mode.
     *
     * @param string $mode Analysis mode (individual or aggregated)
     * @return self
     * @throws \coding_exception If invalid mode
     */
    public function set_analysis_mode(string $mode): self {
        if (!in_array($mode, [self::ANALYSIS_MODE_INDIVIDUAL, self::ANALYSIS_MODE_AGGREGATED], true)) {
            throw new \coding_exception('Invalid analysis mode: ' . $mode);
        }
        $this->scope->analysis_mode = $mode;
        return $this;
    }

    /**
     * Add source filter for analysis.
     *
     * Supported formats:
     * - 'cm_123' → Course Module (activity)
     * - 'block_456' → Block Context
     * - 'forum_789' → Forum (future)
     * - 'quiz_101' → Quiz (future)
     *
     * @param array $sources Array of source identifiers
     * @return self
     * @throws \coding_exception If invalid source format
     */
    public function with_sources(array $sources): self {
        // Validate all sources before accepting.
        foreach ($sources as $source) {
            $this->validate_source_format($source);
        }

        $this->scope->filters->sources = $sources;
        return $this;
    }

    /**
     * Add student filter.
     *
     * @param array $userids Array of user IDs
     * @return self
     * @deprecated Use filter_by_participants() instead
     */
    public function with_students(array $userids): self {
        return $this->filter_by_participants($userids);
    }

    /**
     * Filter by course participants with optional role restrictions.
     *
     * @param array $participantids Array of user IDs
     * @param array $roleids Optional array of role IDs to filter by
     * @return self
     * @throws \coding_exception If participant IDs are empty
     */
    public function filter_by_participants(array $participantids, array $roleids = []): self {
        if (empty($participantids)) {
            throw new \coding_exception('Participant IDs cannot be empty');
        }

        $this->scope->filters->participants = array_map('intval', $participantids);

        if (!empty($roleids)) {
            $this->scope->filters->roles = array_map('intval', $roleids);
        }

        return $this;
    }

    /**
     * Add group filter.
     *
     * @param array $groupids Array of group IDs
     * @return self
     */
    public function with_groups(array $groupids): self {
        $this->scope->filters->groups = array_map('intval', $groupids);
        return $this;
    }

    /**
     * Add time range filter.
     *
     * @param int $start Unix timestamp start
     * @param int $end Unix timestamp end
     * @return self
     * @throws \coding_exception If invalid time range
     */
    public function with_timerange(int $start, int $end): self {
        if ($start < 0 || $end < 0 || $start > $end) {
            throw new \coding_exception('Invalid time range: start must be <= end and both >= 0');
        }

        $this->scope->filters->timerange = (object)['start' => $start, 'end' => $end];
        return $this;
    }

    /**
     * Get sources in scope.
     *
     * @return array Array of source identifiers (e.g., ['mod_forum'])
     */
    public function get_sources_in_scope(): array {
        return $this->scope->filters->sources ?? [];
    }

    /**
     * Build the scope as JSON string.
     *
     * @return string JSON-encoded scope definition
     */
    public function build(): string {
        $this->scope->courseid = $this->courseid;

        return json_encode($this->scope, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Parse scope JSON back to object.
     *
     * Uses request-level caching to avoid repeated JSON parsing of identical scope details.
     *
     * @param string $json JSON-encoded scope
     * @return object Scope object
     * @throws \coding_exception If JSON is invalid
     */
    public static function parse(string $json): object {
        // Check if already cached.
        $cachekey = md5($json);
        if (isset(self::$parsecache[$cachekey])) {
            return clone self::$parsecache[$cachekey];
        }

        $scope = json_decode($json);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \coding_exception('Invalid scope JSON: ' . json_last_error_msg());
        }

        // Cache the parsed result.
        self::$parsecache[$cachekey] = $scope;

        return clone $scope;
    }

    /**
     * Get role names for display.
     *
     * Uses request-level caching to avoid repeated database queries for the same role IDs.
     *
     * @param array $roleids Array of role IDs
     * @return array Array of role names
     */
    public static function get_role_names(array $roleids): array {
        global $DB;

        if (empty($roleids)) {
            return [];
        }

        // Check if all role names are already cached.
        $cachekey = implode(',', $roleids);
        if (isset(self::$rolenamecache[$cachekey])) {
            return self::$rolenamecache[$cachekey];
        }

        [$insql, $params] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED);
        $roles = $DB->get_records_select('role', "id $insql", $params, 'sortorder', 'id, shortname, name');

        $names = [];
        foreach ($roles as $role) {
            $names[] = role_get_name($role);
        }

        // Cache the result.
        self::$rolenamecache[$cachekey] = $names;

        return $names;
    }

    /**
     * Get allowed AI plugins for conversation data collection.
     *
     * @return array Array of allowed plugin names
     */
    public static function get_allowed_ai_plugins(): array {
        return source_registry::get_all_allowed_plugins();
    }

    /**
     * Validate if AI plugin is allowed.
     *
     * @param string $pluginname The plugin name to validate
     * @return bool True if plugin is allowed
     */
    public static function is_allowed_ai_plugin(string $pluginname): bool {
        return source_registry::get_source_type_for_plugin($pluginname) !== null;
    }

    /**
     * Get course ID in scope.
     *
     * @return int Course ID
     */
    public function get_course_in_scope(): int {
        return $this->courseid;
    }

    /**
     * Get course modules (activities) in scope.
     *
     * @return array Array of course module IDs
     */
    public function get_activities_in_scope(): array {
        global $DB;

        // Handle explicit source filter.
        if (isset($this->scope->filters->sources)) {
            return $this->extract_ids_by_prefix('cm_');
        }

        // Get all activities in course.
        return $DB->get_fieldset_select('course_modules', 'id', 'course = :courseid', ['courseid' => $this->courseid]);
    }

    /**
     * Get block context IDs in scope.
     *
     * Returns context IDs, not block instance IDs, as these are used
     * for AI chat conversations linked to block contexts.
     *
     * @return array Array of block context IDs
     */
    public function get_block_contexts_in_scope(): array {
        global $DB;

        // Handle explicit source filter.
        if (isset($this->scope->filters->sources)) {
            return $this->extract_ids_by_prefix('block_');
        }

        // Get all AI chat block contexts in course.
        $coursecontext = \context_course::instance($this->courseid);
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {block_instances} bi ON bi.id = ctx.instanceid
                 WHERE ctx.contextlevel = :contextblock
                   AND bi.parentcontextid = :parentcontextid
                   AND bi.blockname = :blockname";

        $params = [
            'contextblock' => CONTEXT_BLOCK,
            'parentcontextid' => $coursecontext->id,
            'blockname' => 'ai_chat',
        ];

        return $DB->get_fieldset_sql($sql, $params);
    }

    /**
     * Get students (users) in scope.
     *
     * @return array Array of user IDs
     * @deprecated Use get_participants_in_scope() instead
     */
    public function get_students_in_scope(): array {
        return $this->get_participants_in_scope();
    }

    /**
     * Get participants (users) in scope.
     *
     * Considers participant filter, role filter, and group filter.
     *
     * @return array Array of user IDs
     */
    public function get_participants_in_scope(): array {
        global $DB;

        // Handle explicit participant filter.
        if (isset($this->scope->filters->participants)) {
            return $this->scope->filters->participants;
        }

        // Handle group filter.
        if (isset($this->scope->filters->groups)) {
            $userids = [];
            foreach ($this->scope->filters->groups as $groupid) {
                $members = $DB->get_fieldset_select(
                    'groups_members',
                    'userid',
                    'groupid = :groupid',
                    ['groupid' => $groupid]
                );
                $userids = array_merge($userids, $members);
            }
            return array_values(array_unique($userids));
        }

        // Get all enrolled users in course.
        $context = \context_course::instance($this->courseid);

        // Apply role filter if specified.
        $enrolledusers = [];
        if (isset($this->scope->filters->roles) && !empty($this->scope->filters->roles)) {
            foreach ($this->scope->filters->roles as $roleid) {
                $roleusers = get_role_users($roleid, $context, false, 'u.id');
                foreach ($roleusers as $user) {
                    $enrolledusers[$user->id] = $user;
                }
            }
        } else {
            // No role filter - get all enrolled users.
            $enrolledusers = get_enrolled_users($context, '', 0, 'u.id', null, 0, 0, true);
        }

        $userids = [];
        foreach ($enrolledusers as $user) {
            $userids[] = $user->id;
        }

        return array_values(array_unique($userids));
    }

    /**
     * Get timerange filter in scope.
     *
     * @return object|null Object with 'start' and 'end' properties (Unix timestamps), or null if not set
     */
    public function get_timerange_in_scope(): ?object {
        return $this->scope->filters->timerange ?? null;
    }

    /**
     * Validate source identifier format.
     *
     * @param string $source Source identifier (e.g., 'cm_123', 'block_456')
     * @throws \coding_exception If format is invalid
     */
    private function validate_source_format(string $source): void {
        // Format: 'prefix_id'.
        if (!preg_match('/^([a-z_]+)_(\d+)$/', $source, $matches)) {
            throw new \coding_exception("Invalid source format: {$source}. Expected format: 'prefix_id'");
        }

        $prefix = $matches[1];
        $id = (int)$matches[2];

        if ($id <= 0) {
            throw new \coding_exception("Invalid source ID in: {$source}");
        }

        // Validate prefix against supported types.
        $validprefixes = ['cm', 'block', 'forum', 'quiz', 'assign'];
        if (!in_array($prefix, $validprefixes, true)) {
            throw new \coding_exception("Unsupported source type: {$prefix}. Valid: " . implode(', ', $validprefixes));
        }
    }

    /**
     * Extract IDs from sources array by prefix.
     *
     * @param string $prefix Prefix to match (e.g., 'cm_', 'block_')
     * @return array Array of extracted IDs
     */
    private function extract_ids_by_prefix(string $prefix): array {
        if (!isset($this->scope->filters->sources)) {
            return [];
        }

        $ids = [];
        $prefixlen = strlen($prefix);

        foreach ($this->scope->filters->sources as $source) {
            if (strpos($source, $prefix) === 0) {
                $ids[] = (int) substr($source, $prefixlen);
            }
        }

        return $ids;
    }
}
