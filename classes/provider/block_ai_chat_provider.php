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
 * Block AI Chat provider for block_ai_chat conversations.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis\provider;

use core\di;
use report_ai_analysis\scope_builder;
use report_ai_analysis\local\ai_log_provider;

/**
 * Provider for block_ai_chat conversation data.
 *
 * Collects structured conversation data from block_ai_chat using the
 * ai_manager's log system. Provides conversations as complete threads
 * with user prompts and AI responses.
 *
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_ai_chat_provider extends base_provider {
    /**
     * Check if block_ai_chat is available.
     *
     * @return bool True if block_ai_chat is installed and enabled
     */
    public static function is_available(): bool {
        global $CFG;

        // Check if block exists.
        $blockpath = $CFG->dirroot . '/blocks/ai_chat';
        if (!file_exists($blockpath)) {
            return false;
        }

        // Check if block is enabled.
        $pluginmanager = \core_plugin_manager::instance();
        $blockinfo = $pluginmanager->get_plugin_info('block_ai_chat');

        return $blockinfo && $blockinfo->is_enabled();
    }

    /**
     * Get provider metadata.
     *
     * @return array Metadata array
     */
    public static function get_metadata(): array {
        return [
            'name' => get_string('pluginname', 'block_ai_chat'),
            'type' => 'block_ai_chat',
            'description' => 'AI Chat conversation threads',
        ];
    }

    /**
     * Collect structured conversations based on scope.
     *
     * @return array Array of structured conversations
     * @throws \moodle_exception If block_ai_chat is not available
     */
    public function collect(): array {
        global $DB;

        if (!self::is_available()) {
            throw new \moodle_exception('error_ai_chat_not_available', 'report_ai_analysis');
        }

        $allconversations = [];

        // Get scope parameters.
        $blockcontextids = $this->scopebuilder->get_block_contexts_in_scope();
        $userids = $this->scopebuilder->get_participants_in_scope();

        if (empty($blockcontextids) || empty($userids)) {
            return [];
        }

        // Batch load all users to avoid N+1 queries.
        $userfields = 'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename';
        $users = $DB->get_records_list('user', 'id', $userids, '', $userfields);

        // Collect conversations for each user and context.
        foreach ($userids as $userid) {
            if (!isset($users[$userid])) {
                continue;
            }
            foreach ($blockcontextids as $contextid) {
                $conversations = $this->get_user_conversations($userid, $contextid, $users[$userid]);
                $allconversations = array_merge($allconversations, $conversations);
            }
        }

        // Limit total number of conversations.
        $maxconversations = $this->maxrecords;
        if (count($allconversations) > ($maxconversations * count($userids))) {
            $allconversations = array_slice($allconversations, 0, $maxconversations * count($userids));
        }

        return $allconversations;
    }

    /**
     * Get conversations for a specific user and context.
     *
     * Uses the ai_chat block's method to retrieve structured conversation data.
     *
     * @param int $userid User ID
     * @param int $contextid Block context ID
     * @param \stdClass $user User object with name fields
     * @return array Array of conversations
     */
    private function get_user_conversations(int $userid, int $contextid, \stdClass $user): array {
        // Use DI-injectable provider for testability.
        $ailogprovider = di::get(ai_log_provider::class);

        try {
            $conversations = $ailogprovider->get_log_entries(
                'block_ai_chat',
                $contextid,
                $userid,
                0,
                false,
                '*',
                ['chat']
            );
        } catch (\Exception $e) {
            debugging('Conversation fetch failed: ' . s($e->getMessage()), DEBUG_DEVELOPER);
            return [];
        }

        // Group by itemid (thread ID) and structure properly.
        $threads = [];
        foreach ($conversations as $entry) {
            if (empty($entry->itemid)) {
                continue;
            }

            $threadid = $entry->itemid;

            if (!isset($threads[$threadid])) {
                // Initialize thread with system prompt.
                $requestoptions = json_decode($entry->requestoptions, true);
                $conversationcontext = $requestoptions['conversationcontext'] ?? [];

                $threads[$threadid] = [
                    'threadid' => $threadid,
                    'userid' => $userid,
                    'username' => fullname($user),
                    'contextid' => $contextid,
                    'timecreated' => $entry->timecreated,
                    'messages' => $conversationcontext,
                ];
            }

            // Add user prompt.
            $threads[$threadid]['messages'][] = [
                'role' => 'user',
                'content' => $entry->prompttext,
                'timestamp' => $entry->timecreated,
            ];

            // Add AI response using DI-injectable provider.
            $formattedcontent = $ailogprovider->format_purpose_output('chat', $entry->promptcompletion);

            $threads[$threadid]['messages'][] = [
                'role' => 'assistant',
                'content' => $formattedcontent,
                'timestamp' => $entry->timecreated,
            ];
        }

        return array_values($threads);
    }

    /**
     * Format conversations for AI analysis.
     *
     * Converts structured conversation data into a readable format for AI.
     *
     * @param array $conversations Array of conversation threads
     * @return string Formatted conversation data
     */
    public static function format_for_ai(array $conversations): string {
        if (empty($conversations)) {
            return '';
        }

        $output = [];
        $output[] = "=== " . get_string('export_conversations_header', 'report_ai_analysis') . " ===\n";
        $output[] = get_string('export_total_conversations', 'report_ai_analysis') . ": " . count($conversations) . "\n\n";

        foreach ($conversations as $thread) {
            $output[] = "--- " . get_string('export_conversation_thread', 'report_ai_analysis') .
                        " #" . $thread['threadid'] . " ---";
            $output[] = get_string('export_user', 'report_ai_analysis') . ": " .
                        $thread['username'] . " (ID: " . $thread['userid'] . ")";
            $output[] = get_string('export_created', 'report_ai_analysis') . ": " .
                        userdate($thread['timecreated'], '%Y-%m-%d %H:%M:%S');
            $output[] = get_string('export_context_id', 'report_ai_analysis') . ": " . $thread['contextid'];
            $output[] = get_string('export_messages', 'report_ai_analysis') . ": " . count($thread['messages']);
            $output[] = "";

            foreach ($thread['messages'] as $index => $message) {
                $role = strtoupper($message['role'] ?? $message['sender'] ?? 'UNKNOWN');
                $content = $message['content'] ?? $message['message'] ?? '';

                // Strip HTML tags for cleaner output.
                $content = strip_tags($content);

                // Limit very long messages.
                if (strlen($content) > 2000) {
                    $content = substr($content, 0, 2000) . '... ' . get_string('export_truncated', 'report_ai_analysis');
                }

                $output[] = "[$role]";
                $output[] = $content;
                $output[] = "";
            }

            $output[] = str_repeat('-', 60);
            $output[] = "";
        }

        return implode("\n", $output);
    }

    /**
     * Get conversation statistics.
     *
     * @param array $conversations Array of conversation threads
     * @return array Statistics
     */
    public static function get_statistics(array $conversations): array {
        $stats = [
            'total_threads' => count($conversations),
            'total_messages' => 0,
            'total_users' => 0,
            'users' => [],
            'avg_messages_per_thread' => 0,
        ];

        foreach ($conversations as $thread) {
            $stats['total_messages'] += count($thread['messages']);
            $stats['users'][$thread['userid']] = $thread['username'];
        }

        $stats['total_users'] = count($stats['users']);

        if ($stats['total_threads'] > 0) {
            $stats['avg_messages_per_thread'] = round($stats['total_messages'] / $stats['total_threads'], 2);
        }

        return $stats;
    }

    /**
     * Check if this provider handles the given source identifier.
     *
     * Handles source identifiers starting with 'block_' that are block_ai_chat instances.
     *
     * @param string $sourceidentifier Source identifier (e.g., 'block_123')
     * @return bool True if this provider handles this source
     */
    public function handles_source(string $sourceidentifier): bool {
        // Check if it's a block source.
        if (strpos($sourceidentifier, 'block_') !== 0) {
            return false;
        }

        // Extract context ID and verify it's an ai_chat block.
        $contextid = (int)substr($sourceidentifier, 6);
        if ($contextid <= 0) {
            return false;
        }

        global $DB;
        try {
            $context = \context::instance_by_id($contextid, IGNORE_MISSING);
            if (!$context || $context->contextlevel !== CONTEXT_BLOCK) {
                return false;
            }

            $blockinstance = $DB->get_record('block_instances', ['id' => $context->instanceid]);
            return $blockinstance && $blockinstance->blockname === 'ai_chat';
        } catch (\Exception $e) {
            return false;
        }
    }
}
