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
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis;

use cache;
use context;
use context_course;
use moodle_database;

/**
 * Builds scope definitions for AI analysis.
 *
 * Refactored to use MUC (Moodle Universal Cache) instead of static array caches
 * for better testability and proper cache lifecycle management.
 */
class scope_builder {
    /**
     * Analysis mode: Individual analysis per participant.
     */
    public const ANALYSIS_MODE_INDIVIDUAL = 'individual';

    /**
     * Analysis mode: Aggregated analysis across all participants.
     */
    public const ANALYSIS_MODE_AGGREGATED = 'aggregated';

    /** @var int The course ID. */
    private int $courseid;

    /** @var object The scope object. */
    private object $scope;

    /** @var moodle_database Database instance. */
    private moodle_database $db;

    /**
     * Constructor.
     *
     * @param int $courseid The course ID for analysis.
     * @param moodle_database|null $db Optional database instance for testing.
     */
    public function __construct(int $courseid, ?moodle_database $db = null) {
        global $DB;
        if ($courseid <= 0) {
            throw new \coding_exception('A positive course ID is required');
        }
        $this->courseid = $courseid;
        $this->db = $db ?? $DB;
        $this->scope = new \stdClass();
        $this->scope->filters = new \stdClass();
    }

    /**
     * Set analysis mode.
     *
     * @param string $mode Analysis mode (individual or aggregated).
     * @return self
     * @throws \coding_exception If invalid mode.
     */
    public function set_analysis_mode(string $mode): self {
        if (!in_array($mode, [self::ANALYSIS_MODE_INDIVIDUAL, self::ANALYSIS_MODE_AGGREGATED], true)) {
            throw new \coding_exception('Invalid analysis mode: ' . $mode);
        }
        $this->scope->analysis_mode = $mode;
        return $this;
    }

    /**
     * Get the requested analysis mode, including the default for legacy scopes.
     *
     * @return string Analysis mode.
     */
    public function get_analysis_mode(): string {
        return $this->scope->analysis_mode ?? self::ANALYSIS_MODE_AGGREGATED;
    }

    /**
     * Add source filter for analysis.
     *
     * Supported formats:
     * - 'cm_123' → Course Module (activity)
     * - 'block_456' → Block Context
     * - 'forum_789' → Legacy forum instance identifier
     *
     * An empty selection means all supported, authorised sources in this course.
     * Identifiers are not authorisation: they are checked against current source permissions when resolved.
     *
     * @param array $sources Array of source identifiers.
     * @return self
     * @throws \coding_exception If invalid source format.
     */
    public function with_sources(array $sources): self {
        // Validate all sources before accepting.
        foreach ($sources as $source) {
            $this->validate_source_format($source);
        }

        $this->scope->filters->sources = array_values(array_unique($sources));
        return $this;
    }

    /**
     * Filter by course participants with optional role restrictions.
     *
     * An explicit empty array selects nobody; it must never mean all enrolled users.
     *
     * @param array $participantids Array of user IDs.
     * @param array $roleids Optional array of role IDs to filter by.
     * @return self
     * @throws \coding_exception If an identifier is invalid.
     */
    public function filter_by_participants(array $participantids, array $roleids = []): self {
        $this->scope->filters->participants = self::normalise_ids($participantids);

        if (!empty($roleids)) {
            $this->filter_by_roles($roleids);
        }

        return $this;
    }

    /**
     * Restrict participants to users assigned any of these roles in the course or its parents.
     *
     * Roles are an additional selection filter, never an alternative to enrolment or authorisation.
     * An empty role selection removes only the role filter.
     *
     * @param array $roleids Role identifiers.
     * @return self
     */
    public function filter_by_roles(array $roleids): self {
        $this->scope->filters->roles = self::normalise_ids($roleids);
        return $this;
    }

    /**
     * Add group filter.
     *
     * @param array $groupids Array of group IDs.
     * @return self
     */
    public function with_groups(array $groupids): self {
        $this->scope->filters->groups = self::normalise_ids($groupids);
        return $this;
    }

    /**
     * Get the optional group selection, independently of the viewer's group permissions.
     *
     * @return int[] Selected group identifiers, or an empty array for no selection.
     */
    public function get_groups_in_scope(): array {
        return $this->scope->filters->groups ?? [];
    }

    /**
     * Add time range filter.
     *
     * @param int $start Inclusive Unix timestamp start, or zero for no lower boundary.
     * @param int $end Inclusive Unix timestamp end, or zero for no upper boundary.
     * @return self
     * @throws \coding_exception If invalid time range.
     */
    public function with_timerange(int $start, int $end): self {
        if ($start < 0 || $end < 0 || ($start && $end && $start > $end)) {
            throw new \coding_exception('Invalid time range: nonzero start must not exceed nonzero end');
        }

        $this->scope->filters->timerange = (object)['start' => $start, 'end' => $end];
        return $this;
    }

    /**
     * Get sources in scope.
     *
     * @return array Array of source identifiers (e.g., ['cm_123']).
     */
    public function get_sources_in_scope(): array {
        return $this->scope->filters->sources ?? [];
    }

    /**
     * Build the scope as JSON string.
     *
     * @return string JSON-encoded scope definition.
     */
    public function build(): string {
        $this->scope->courseid = $this->courseid;

        return json_encode($this->scope, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Parse scope JSON back to object.
     *
     * Returns an independent object on every call; callers must not mutate a shared cached scope.
     *
     * @param string $json JSON-encoded scope.
     * @return object Scope object.
     * @throws \coding_exception If JSON is invalid.
     */
    public static function parse(string $json): object {
        $scope = json_decode($json);

        if (json_last_error() !== JSON_ERROR_NONE || !($scope instanceof \stdClass)) {
            throw new \coding_exception('Invalid scope JSON: ' . json_last_error_msg());
        }

        return $scope;
    }

    /**
     * Rebuild a scope using only recognised filters, not stored names or purported permissions.
     *
     * Legacy students and roleids keys are accepted, but canonical participants and roles take precedence.
     * Authorisation is deliberately resolved later for the executing user, not restored from JSON.
     *
     * @param string $json Persisted scope definition.
     * @return self Rebuilt scope.
     * @throws \coding_exception If the persisted definition is invalid.
     */
    public static function from_json(string $json): self {
        $scope = self::parse($json);
        $courseids = self::normalise_ids([$scope->courseid ?? 0]);
        $builder = new self($courseids[0]);
        $builder->set_analysis_mode($scope->analysis_mode ?? self::ANALYSIS_MODE_AGGREGATED);
        $filters = property_exists($scope, 'filters') ? $scope->filters : new \stdClass();
        if (!($filters instanceof \stdClass)) {
            throw new \coding_exception('Invalid scope filters');
        }

        foreach (['sources', 'participants', 'students', 'roles', 'roleids', 'groups'] as $key) {
            if (property_exists($filters, $key) && !is_array($filters->{$key})) {
                throw new \coding_exception('Invalid scope filter: ' . $key);
            }
        }
        if (isset($filters->sources)) {
            $builder->with_sources($filters->sources);
        }
        if (isset($filters->participants) || isset($filters->students)) {
            $builder->filter_by_participants($filters->participants ?? $filters->students);
        }
        if (isset($filters->roles) || isset($filters->roleids)) {
            $builder->filter_by_roles($filters->roles ?? $filters->roleids);
        }
        if (isset($filters->groups)) {
            $builder->with_groups($filters->groups);
        }
        if (isset($filters->timerange)) {
            if (!($filters->timerange instanceof \stdClass)) {
                throw new \coding_exception('Invalid scope time range');
            }
            $start = $filters->timerange->start ?? 0;
            $end = $filters->timerange->end ?? 0;
            if (!is_int($start) || !is_int($end)) {
                throw new \coding_exception('Invalid scope time range boundaries');
            }
            $builder->with_timerange($start, $end);
        }
        return $builder;
    }

    /**
     * Get role names for display.
     *
     * Uses MUC (MODE_REQUEST) to avoid repeated database queries for the same role IDs.
     *
     * @param array $roleids Array of role IDs.
     * @param moodle_database|null $db Optional database instance for testing.
     * @return array Array of role names.
     */
    public static function get_role_names(array $roleids, ?moodle_database $db = null): array {
        global $DB;
        $database = $db ?? $DB;

        if (empty($roleids)) {
            return [];
        }

        // Use MUC request-level cache.
        $cache = cache::make('report_ai_analysis', 'role_names');
        $cachekey = implode(',', $roleids);

        $cached = $cache->get($cachekey);
        if ($cached !== false) {
            return $cached;
        }

        [$insql, $params] = $database->get_in_or_equal($roleids, SQL_PARAMS_NAMED);
        $roles = $database->get_records_select('role', "id $insql", $params, 'sortorder', 'id, shortname, name');

        $names = [];
        foreach ($roles as $role) {
            $names[] = role_get_name($role);
        }

        // Cache the result.
        $cache->set($cachekey, $names);

        return $names;
    }

    /**
     * Get allowed AI plugins for conversation data collection.
     *
     * @return array Array of allowed plugin names.
     */
    public static function get_allowed_ai_plugins(): array {
        return source_registry::get_all_allowed_plugins();
    }

    /**
     * Validate if AI plugin is allowed.
     *
     * @param string $pluginname The plugin name to validate.
     * @return bool True if plugin is allowed.
     */
    public static function is_allowed_ai_plugin(string $pluginname): bool {
        return source_registry::get_source_type_for_plugin($pluginname) !== null;
    }

    /**
     * Get course ID in scope.
     *
     * @return int Course ID.
     */
    public function get_course_in_scope(): int {
        return $this->courseid;
    }

    /**
     * Get course modules (activities) in scope.
     *
     * @return array Array of course module IDs.
     */
    public function get_activities_in_scope(): array {
        $modinfo = get_fast_modinfo($this->courseid);
        $allowed = [];
        $legacy = [];
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->modname === 'forum' && $cm->uservisible && has_capability('mod/forum:viewdiscussion', $cm->context)) {
                $allowed[] = (int)$cm->id;
                $legacy['forum_' . $cm->instance] = (int)$cm->id;
            }
        }

        if (!$this->get_sources_in_scope()) {
            return $allowed;
        }

        $selected = $this->extract_ids_by_prefix('cm_');
        foreach ($this->get_sources_in_scope() as $source) {
            if (str_starts_with($source, 'forum_')) {
                if (!isset($legacy[$source])) {
                    throw new \moodle_exception('error_source_forbidden', 'report_ai_analysis');
                }
                $selected[] = $legacy[$source];
            }
        }
        if (array_diff($selected, $allowed)) {
            throw new \moodle_exception('error_source_forbidden', 'report_ai_analysis');
        }
        return array_values(array_unique($selected));
    }

    /**
     * Get block context IDs in scope.
     *
     * Returns context IDs, not block instance IDs, as these are used
     * for AI chat conversations linked to block contexts.
     *
     * @return array Array of block context IDs.
     */
    public function get_block_contexts_in_scope(): array {
        // Only course-owned blocks are supported; system, dashboard and other-course logs are not course sources.
        $coursecontext = context_course::instance($this->courseid);
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {block_instances} bi ON bi.id = ctx.instanceid
                  JOIN {block} b ON b.name = bi.blockname
                 WHERE ctx.contextlevel = :contextblock
                   AND bi.parentcontextid = :parentcontextid
                   AND bi.blockname = :blockname
                   AND b.visible = 1
                   AND NOT EXISTS (
                       SELECT 1 FROM {block_positions} bp
                        WHERE bp.blockinstanceid = bi.id AND bp.contextid = :positioncontext AND bp.visible = 0
                   )
              ORDER BY ctx.id";

        $params = [
            'contextblock' => CONTEXT_BLOCK,
            'parentcontextid' => $coursecontext->id,
            'positioncontext' => $coursecontext->id,
            'blockname' => 'ai_chat',
        ];

        $allowed = [];
        foreach ($this->db->get_fieldset_sql($sql, $params) as $contextid) {
            $blockcontext = context::instance_by_id($contextid);
            if (has_capability('moodle/block:view', $blockcontext) && has_capability('block/ai_chat:view', $blockcontext)) {
                $allowed[] = (int)$contextid;
            }
        }
        if (!$this->get_sources_in_scope()) {
            return $allowed;
        }
        $selected = $this->extract_ids_by_prefix('block_');
        if (array_diff($selected, $allowed)) {
            throw new \moodle_exception('error_source_forbidden', 'report_ai_analysis');
        }
        return array_values(array_unique($selected));
    }

    /**
     * Validate every selected source, including sources whose provider would otherwise not be called.
     */
    public function validate_sources(): void {
        $this->get_activities_in_scope();
        $this->get_block_contexts_in_scope();
    }

    /**
     * Get selectable, supported sources for this course and the current viewer.
     *
     * @return array Source identifiers mapped to display names.
     */
    public function get_available_sources(): array {
        $builder = new self($this->courseid, $this->db);
        $modinfo = get_fast_modinfo($this->courseid);
        $sources = [];
        foreach ($builder->get_activities_in_scope() as $cmid) {
            $sources['cm_' . $cmid] = $modinfo->get_cm($cmid)->get_formatted_name();
        }
        foreach ($builder->get_block_contexts_in_scope() as $contextid) {
            $sources['block_' . $contextid] = get_string('pluginname', 'block_ai_chat') . ' (' . $contextid . ')';
        }
        return $sources;
    }

    /**
     * Get participants (users) in scope.
     *
     * Active enrolment, explicit participants, roles and selected groups are intersected.
     * The viewer's separate-group permissions are applied independently of those selections.
     *
     * @param context|null $sourcecontext Optional source context for activity/block group permissions.
     * @return array Array of user IDs.
     */
    public function get_participants_in_scope(?context $sourcecontext = null): array {
        global $USER;

        $context = context_course::instance($this->courseid);
        if ($sourcecontext && $sourcecontext->get_course_context()->id !== $context->id) {
            throw new \coding_exception('Source context must belong to the scope course');
        }
        if (isset($this->scope->filters->participants) && !$this->scope->filters->participants) {
            return [];
        }

        $users = get_enrolled_users($context, '', 0, 'u.id, u.suspended', 'u.id', 0, 0, true);
        $userids = array_keys(array_filter($users, static fn($user) => !$user->suspended));
        if (isset($this->scope->filters->participants)) {
            $userids = array_intersect($userids, $this->scope->filters->participants);
        }

        if (!empty($this->scope->filters->roles)) {
            [$rolesql, $roleparams] = $this->db->get_in_or_equal($this->scope->filters->roles, SQL_PARAMS_NAMED, 'role');
            [$contextsql, $contextparams] = $this->db->get_in_or_equal(
                $context->get_parent_context_ids(true),
                SQL_PARAMS_NAMED,
                'rolecontext'
            );
            $roleusers = $this->db->get_fieldset_select(
                'role_assignments',
                'userid',
                "roleid $rolesql AND contextid $contextsql",
                $roleparams + $contextparams
            );
            $userids = array_intersect($userids, $roleusers);
        }

        if ($this->get_groups_in_scope()) {
            $coursegroups = groups_get_all_groups($this->courseid, 0, 0, 'g.id');
            $selectedgroups = array_intersect($this->get_groups_in_scope(), array_keys($coursegroups));
            $userids = array_intersect($userids, $this->get_enrolled_group_members($selectedgroups));
        }

        $course = get_course($this->courseid);
        $permissioncontext = $sourcecontext ?? $context;
        $ismodule = $sourcecontext && $sourcecontext->contextlevel === CONTEXT_MODULE;
        // Activities use their effective mode (including a forced course mode), not the course default twice.
        if (
            !$ismodule && $course->groupmode == SEPARATEGROUPS &&
            !has_capability('moodle/site:accessallgroups', $permissioncontext)
        ) {
            $groups = groups_get_all_groups($this->courseid, $USER->id, $course->defaultgroupingid, 'g.id', false, true);
            $userids = array_intersect($userids, $this->get_enrolled_group_members(array_keys($groups)));
        }
        if ($ismodule) {
            $cm = get_fast_modinfo($this->courseid)->get_cm($sourcecontext->instanceid);
            if ($cm->effectivegroupmode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $sourcecontext)) {
                $groups = groups_get_activity_allowed_groups($cm, $USER->id);
                $userids = array_intersect($userids, $this->get_enrolled_group_members(array_keys($groups)));
            }
        }

        return array_values(array_map('intval', $userids));
    }

    /**
     * Resolve group membership without allowing an empty group set to mean all users.
     *
     * @param array $groupids Course group identifiers already checked using the Groups API.
     * @return int[] Active enrolled members.
     */
    private function get_enrolled_group_members(array $groupids): array {
        if (!$groupids) {
            return [];
        }
        return array_keys(get_enrolled_users(context_course::instance($this->courseid), '', $groupids, 'u.id', 'u.id', 0, 0, true));
    }

    /**
     * Get timerange filter in scope.
     *
     * @return object|null Object with 'start' and 'end' properties (Unix timestamps), or null if not set.
     */
    public function get_timerange_in_scope(): ?object {
        return $this->scope->filters->timerange ?? null;
    }

    /**
     * Validate source identifier format.
     *
     * @param string $source Source identifier (e.g., 'cm_123', 'block_456').
     * @throws \coding_exception If format is invalid.
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
        $validprefixes = ['cm', 'block', 'forum'];
        if (!in_array($prefix, $validprefixes, true)) {
            throw new \coding_exception("Unsupported source type: {$prefix}. Valid: " . implode(', ', $validprefixes));
        }
    }

    /**
     * Extract IDs from sources array by prefix.
     *
     * @param string $prefix Prefix to match (e.g., 'cm_', 'block_').
     * @return array Array of extracted IDs.
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

    /**
     * Validate identifiers without accepting strings that merely start with an integer.
     *
     * @param array $ids Identifiers supplied by a caller or persisted scope.
     * @return int[] Unique positive identifiers.
     */
    private static function normalise_ids(array $ids): array {
        foreach ($ids as $id) {
            if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int)$id <= 0) {
                throw new \coding_exception('Invalid scope identifier');
            }
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }
}
