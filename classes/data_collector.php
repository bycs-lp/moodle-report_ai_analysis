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
 * Data collector for AI conversation and discussion data.
 *
 * @package    report_ai_analysis
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis;

use report_ai_analysis\collectors\conversation_collector;
use report_ai_analysis\collectors\forum_collector;

/**
 * Collects structured conversation and discussion data.
 *
 * This collector delegates to specific collectors based on
 * available data sources (block_ai_chat, mod_forum, etc.).
 *
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class data_collector {
    /** @var scope_builder The scope builder */
    private $scopebuilder;

    /** @var int Maximum records to collect */
    private $maxrecords;

    /**
     * Constructor.
     *
     * @param scope_builder $scopebuilder The scope builder
     * @param int $maxrecords Maximum records to collect (default: 1000)
     */
    public function __construct(scope_builder $scopebuilder, int $maxrecords = 1000) {
        $this->scopebuilder = $scopebuilder;
        $this->maxrecords = $maxrecords;
    }

    /**
     * Collect structured conversation and discussion data from all available sources.
     *
     * @return array Array with data from all available sources
     * @throws \moodle_exception If no data sources are available
     */
    public function collect(): array {
        $alldata = [
            'conversations' => [],
            'discussions' => [],
        ];

        // Get sources to collect from scope.
        $sources = $this->scopebuilder->get_sources_in_scope();

        // If no sources specified, collect from all available sources.
        $collectall = empty($sources);

        // Collect from block_ai_chat if available and requested.
        $collectconversations = $collectall || $this->has_block_sources($sources);
        if ($collectconversations && conversation_collector::is_available()) {
            try {
                $collector = new conversation_collector($this->scopebuilder, $this->maxrecords);
                $alldata['conversations'] = $collector->collect();
            } catch (\Exception $e) {
                debugging('Conversation collector failed: ' . s($e->getMessage()), DEBUG_DEVELOPER);
            }
        }

        // Collect from mod_forum if available and requested.
        $collectforums = $collectall || $this->has_forum_sources($sources);

        if ($collectforums && forum_collector::is_available()) {
            try {
                $collector = new forum_collector($this->scopebuilder, $this->maxrecords);
                $alldata['discussions'] = $collector->collect();
            } catch (\Exception $e) {
                debugging('Forum collector failed: ' . s($e->getMessage()), DEBUG_DEVELOPER);
            }
        }

        // Check if any data was collected.
        if (empty($alldata['conversations']) && empty($alldata['discussions'])) {
            throw new \moodle_exception('error_no_data', 'report_ai_analysis');
        }

        return $alldata;
    }

    /**
     * Get formatted data as string for AI analysis.
     *
     * @param array $data Array of collected data
     * @return string Formatted data
     */
    public static function format_for_ai(array $data): string {
        $output = [];

        // Format conversations if available.
        if (!empty($data['conversations'])) {
            $output[] = conversation_collector::format_for_ai($data['conversations']);
        }

        // Format discussions if available.
        if (!empty($data['discussions'])) {
            $output[] = forum_collector::format_for_ai($data['discussions']);
        }

        return implode("\n\n", $output);
    }

    /**
     * Get statistics about collected data.
     *
     * @param array $data Array of collected data
     * @return array Statistics
     */
    public static function get_statistics(array $data): array {
        $stats = [
            'total_sources' => 0,
            'conversations' => [],
            'discussions' => [],
        ];

        // Get conversation statistics.
        if (!empty($data['conversations'])) {
            $stats['conversations'] = conversation_collector::get_statistics($data['conversations']);
            $stats['total_sources']++;
        }

        // Get discussion statistics.
        if (!empty($data['discussions'])) {
            $stats['discussions'] = forum_collector::get_statistics($data['discussions']);
            $stats['total_sources']++;
        }

        return $stats;
    }

    /**
     * Check if sources contain any block sources (block_*).
     *
     * @param array $sources Array of source identifiers
     * @return bool True if block sources found
     */
    private function has_block_sources(array $sources): bool {
        foreach ($sources as $source) {
            if (strpos($source, 'block_') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if sources contain any forum course modules (cm_* with mod=forum).
     *
     * @param array $sources Array of source identifiers (e.g., ['cm_123', 'cm_456'])
     * @return bool True if forum sources found
     */
    private function has_forum_sources(array $sources): bool {
        global $DB;

        foreach ($sources as $source) {
            // Check if it's a course module source.
            if (strpos($source, 'cm_') === 0) {
                $cmid = (int)substr($source, 3);
                // Check if this cm is a forum.
                $modname = $DB->get_field('course_modules', 'module', ['id' => $cmid]);
                if ($modname) {
                    $modulename = $DB->get_field('modules', 'name', ['id' => $modname]);
                    if ($modulename === 'forum') {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}
