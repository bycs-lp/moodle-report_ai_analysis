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

use context;
use core\di;
use core_text;
use report_ai_analysis\local\ai_log_provider;
use report_ai_analysis\scope_builder;

/**
 * Collects authorised AI chat request/response pairs, not duplicated conversation history.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_ai_chat_provider extends base_provider {
    /** @var ai_log_provider Public AI Manager log API adapter. */
    private ai_log_provider $ailogprovider;

    /**
     * Constructor.
     *
     * @param scope_builder $scopebuilder Scope builder.
     * @param int $maxrecords Maximum request entries, not conversation containers or messages.
     * @param ai_log_provider|null $ailogprovider Injectable log API adapter.
     */
    public function __construct(scope_builder $scopebuilder, int $maxrecords = 1000, ?ai_log_provider $ailogprovider = null) {
        parent::__construct($scopebuilder, $maxrecords);
        $this->ailogprovider = $ailogprovider ?? di::get(ai_log_provider::class);
    }

    /**
     * Check whether AI Chat and its log provider are installed and enabled.
     *
     * @return bool Availability.
     */
    public static function is_available(): bool {
        $plugins = \core_plugin_manager::instance();
        $block = $plugins->get_plugin_info('block_ai_chat');
        $manager = $plugins->get_plugin_info('local_ai_manager');
        // Local plugins need not support configurable availability (is_enabled() then returns null).
        return $block && $block->is_enabled() && $manager && $manager->is_enabled() !== false;
    }

    /**
     * Get provider metadata.
     *
     * @return array Provider metadata.
     */
    public static function get_metadata(): array {
        return ['name' => get_string('pluginname', 'block_ai_chat'), 'type' => 'block_ai_chat'];
    }

    /**
     * Collect authorised request entries within the date range and remaining global budget.
     *
     * Only each included log entry's own prompt and completion are used. requestoptions and
     * conversationcontext are never fetched or copied. Dates are filtered internally but omitted from
     * foreign users' returned data unless viewpromptsdates is granted in that source context.
     *
     * The log API has neither a date filter nor pagination. Date-filtered reads inspect a bounded recent
     * window; exhausting that window is explicitly reported as truncation, never claimed to be complete.
     * Budget-truncated conversations are marked; a request/response pair is never cut in half by the record budget.
     *
     * @return array Conversations containing only included request pairs, plus truncation and semantics flags.
     */
    public function collect(): array {
        global $DB, $USER;

        $this->truncated = false;
        if (!self::is_available()) {
            throw new \moodle_exception('error_ai_chat_not_available', 'report_ai_analysis');
        }
        $this->scopebuilder->validate_sources();
        $contextids = $this->scopebuilder->get_block_contexts_in_scope();
        $timerange = $this->scopebuilder->get_timerange_in_scope();
        $remaining = $this->maxrecords;
        $examined = 0;
        $queries = 0;
        $threads = [];

        $authorisedsources = [];
        foreach ($contextids as $contextid) {
            $context = context::instance_by_id($contextid);
            $userids = $this->scopebuilder->get_participants_in_scope($context);
            if (!has_capability('local/ai_manager:viewprompts', $context)) {
                $foreignusers = array_diff($userids, [$USER->id]);
                if ($foreignusers && $this->scopebuilder->get_sources_in_scope()) {
                    throw new \moodle_exception('error_source_forbidden', 'report_ai_analysis');
                }
                $userids = array_values(array_intersect($userids, [$USER->id]));
            }
            $authorisedsources[$contextid] = $userids;
        }
        // Check every explicit source before a full budget can stop later source reads.
        foreach ($authorisedsources as $contextid => $userids) {
            $context = context::instance_by_id($contextid);
            foreach ($userids as $userid) {
                $scanremaining = self::HARD_RECORD_LIMIT - $examined;
                if ($scanremaining <= 0 || $queries >= self::HARD_RECORD_LIMIT) {
                    $this->truncated = true;
                    break 2;
                }
                // One extra entry detects budget/window exhaustion without an unlimited API call.
                $limit = $timerange && ($timerange->start || $timerange->end) ? $scanremaining + 1 : $remaining + 1;
                $limit = min($limit, $scanremaining + 1);
                $queries++;
                $entries = $this->get_entries($contextid, $userid, $limit);
                if (count($entries) > $scanremaining) {
                    // The API returns its recent window in chronological order. Drop the oldest sentinel.
                    array_shift($entries);
                    $this->truncated = true;
                }
                $examined += count($entries);
                $showdates = $userid === (int)$USER->id || has_capability('local/ai_manager:viewpromptsdates', $context);
                foreach ($entries as $entry) {
                    if (!$this->entry_is_in_scope($entry, $contextid, $userid, $timerange)) {
                        continue;
                    }
                    if ($remaining === 0) {
                        $this->truncated = true;
                        break 3;
                    }
                    $this->add_entry($threads, $entry, $showdates);
                    $remaining--;
                }
            }
        }

        if (!$threads) {
            return [];
        }
        $userids = array_unique(array_column($threads, 'userid'));
        $users = $DB->get_records_list(
            'user',
            'id',
            $userids,
            '',
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename'
        );
        foreach ($threads as &$thread) {
            $user = $users[$thread['userid']] ?? null;
            $thread['username'] = $user ? fullname($user) : get_string('unknown', 'report_ai_analysis');
            $thread['truncated'] = $this->truncated;
        }
        unset($thread);
        return array_values($threads);
    }

    /**
     * Call the public log API with an explicit positive bound and no conversation history fields.
     *
     * @param int $contextid Authorised block context.
     * @param int $userid Authorised author.
     * @param int $limit Positive request entry limit.
     * @return array Log entries.
     */
    private function get_entries(int $contextid, int $userid, int $limit): array {
        try {
            return $this->ailogprovider->get_log_entries(
                'block_ai_chat',
                $contextid,
                $userid,
                0,
                false,
                'id,userid,contextid,component,purpose,itemid,timecreated,prompttext,promptcompletion,deleted',
                ['chat'],
                $limit
            );
        } catch (\Throwable $exception) {
            throw new \moodle_exception(
                'error_source_failed',
                'report_ai_analysis',
                '',
                null,
                'block_ai_chat log retrieval: ' . $exception->getMessage() . "\n" . ($exception->debuginfo ?? '')
            );
        }
    }

    /**
     * Check row identity and inclusive time boundaries before any formatting or copying of content.
     *
     * @param \stdClass $entry Request entry.
     * @param int $contextid Authorised source context.
     * @param int $userid Authorised author.
     * @param object|null $timerange Optional open date range.
     * @return bool Whether the entry belongs to this extraction.
     */
    private function entry_is_in_scope(\stdClass $entry, int $contextid, int $userid, ?object $timerange): bool {
        if (
            (int)$entry->userid !== $userid || (int)$entry->contextid !== $contextid ||
            $entry->component !== 'block_ai_chat' || $entry->purpose !== 'chat' || !empty($entry->deleted)
        ) {
            return false;
        }
        return (empty($timerange->start) || $entry->timecreated >= $timerange->start) &&
            (empty($timerange->end) || $entry->timecreated <= $timerange->end);
    }

    /**
     * Add one complete request/response pair, without optional dates when they are not authorised.
     *
     * @param array $threads Conversation map being built.
     * @param \stdClass $entry Included request entry.
     * @param bool $showdates Whether its dates may be disclosed.
     */
    private function add_entry(array &$threads, \stdClass $entry, bool $showdates): void {
        $threadkey = $entry->contextid . '_' . $entry->userid . '_' .
            (empty($entry->itemid) ? 'request_' . $entry->id : 'thread_' . $entry->itemid);
        if (!isset($threads[$threadkey])) {
            $threads[$threadkey] = [
                'threadid' => (int)($entry->itemid ?: $entry->id),
                'userid' => (int)$entry->userid,
                'contextid' => (int)$entry->contextid,
                'canviewdates' => $showdates,
                'messages' => [],
                'requestcount' => 0,
                'scope_semantics' => 'included_request_pairs_only',
            ];
            if ($showdates) {
                $threads[$threadkey]['timecreated'] = (int)$entry->timecreated;
            }
        }
        try {
            $response = $this->ailogprovider->format_purpose_output('chat', $entry->promptcompletion);
        } catch (\Throwable $exception) {
            throw new \moodle_exception(
                'error_source_failed',
                'report_ai_analysis',
                '',
                null,
                'block_ai_chat output formatting: ' . $exception->getMessage() . "\n" . ($exception->debuginfo ?? '')
            );
        }
        foreach (['user' => $entry->prompttext, 'assistant' => $response] as $role => $content) {
            $format = $role === 'user' ? FORMAT_PLAIN : FORMAT_HTML;
            $message = ['role' => $role, 'content' => $content, 'format' => $format, 'requestid' => (int)$entry->id];
            if ($showdates) {
                $message['timestamp'] = (int)$entry->timecreated;
            }
            if (core_text::strlen(trim(format_text_email($content, $format))) > self::MAX_TEXT_LENGTH) {
                $this->truncated = true;
            }
            $threads[$threadkey]['messages'][] = $message;
        }
        $threads[$threadkey]['requestcount']++;
    }

    /**
     * Format included conversations, omitting unauthorised date labels and values altogether.
     *
     * @param array $data Structured conversations.
     * @return string Formatted source data.
     */
    public static function format_for_ai(array $data): string {
        if (!$data) {
            return '';
        }
        $output = ['=== ' . get_string('export_conversations_header', 'report_ai_analysis') . ' ==='];
        $output[] = get_string('export_total_conversations', 'report_ai_analysis') . ': ' . count($data);
        foreach ($data as $thread) {
            $output[] = '--- ' . get_string('export_conversation_thread', 'report_ai_analysis') .
                ' #' . $thread['threadid'] . ' ---';
            $output[] = get_string('export_user', 'report_ai_analysis') . ': ' . $thread['username'];
            if (($thread['canviewdates'] ?? true) && isset($thread['timecreated'])) {
                $output[] = get_string('export_created', 'report_ai_analysis') . ': ' .
                    userdate($thread['timecreated'], '%Y-%m-%d %H:%M:%S');
            }
            $output[] = get_string('export_context_id', 'report_ai_analysis') . ': ' . $thread['contextid'];
            $output[] = get_string('export_messages', 'report_ai_analysis') . ': ' . count($thread['messages']);
            foreach ($thread['messages'] as $message) {
                $output[] = '[' . strtoupper($message['role']) . ']';
                $output[] = self::format_message($message['content'], $message['format'] ?? FORMAT_HTML);
                $output[] = '';
            }
            if (!empty($thread['truncated'])) {
                $output[] = get_string('export_truncated', 'report_ai_analysis');
            }
        }
        return implode("\n", $output);
    }

    /**
     * Count actual request entries (a prompt and its completion consume one record).
     *
     * @param array $data Structured conversations.
     * @return int Number of requests.
     */
    public static function count_records(array $data): int {
        $count = 0;
        foreach ($data as $thread) {
            foreach ($thread['messages'] as $message) {
                if ($message['role'] === 'user') {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Get authors with actual included request entries.
     *
     * @param array $data Structured conversations.
     * @return int[] Author identifiers.
     */
    public static function get_user_ids(array $data): array {
        $users = [];
        foreach ($data as $thread) {
            if (self::count_records([$thread])) {
                $users[(int)$thread['userid']] = (int)$thread['userid'];
            }
        }
        return array_values($users);
    }

    /**
     * Keep only the requested user's conversations.
     *
     * @param array $data Structured conversations.
     * @param int $userid Author identifier.
     * @return array Same conversation shape filtered by user.
     */
    public static function filter_by_user(array $data, int $userid): array {
        return array_values(array_filter($data, static fn($thread) => (int)$thread['userid'] === $userid));
    }

    /**
     * Summarise included request entries and messages.
     *
     * @param array $data Structured conversations.
     * @return array Statistics.
     */
    public static function get_statistics(array $data): array {
        $messages = array_sum(array_map(static fn($thread) => count($thread['messages']), $data));
        $users = array_column($data, 'username', 'userid');
        return [
            'total_threads' => count($data),
            'total_requests' => self::count_records($data),
            'total_messages' => $messages,
            'total_users' => count($users),
            'users' => $users,
            'avg_messages_per_thread' => $data ? round($messages / count($data), 2) : 0,
        ];
    }

    /**
     * Check that a block identifier belongs to an AI Chat instance owned by this course.
     *
     * @param string $sourceidentifier Block context identifier.
     * @return bool Whether this provider handles the identifier.
     */
    public function handles_source(string $sourceidentifier): bool {
        global $DB;

        if (!preg_match('/^block_([1-9][0-9]*)$/', $sourceidentifier, $matches)) {
            return false;
        }
        $context = context::instance_by_id((int)$matches[1], IGNORE_MISSING);
        if (!$context || $context->contextlevel !== CONTEXT_BLOCK) {
            return false;
        }
        return $DB->record_exists('block_instances', [
            'id' => $context->instanceid,
            'blockname' => 'ai_chat',
            'parentcontextid' => \context_course::instance($this->scopebuilder->get_course_in_scope())->id,
        ]);
    }
}
