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

namespace report_ai_analysis\local;

use local_ai_manager\ai_manager_utils;
use local_ai_manager\local\connector_factory;

/**
 * Wrapper class for ai_manager_utils log functions to enable dependency injection and mocking in tests.
 *
 * This provider abstracts the AI log retrieval functionality, allowing for easy mocking
 * in unit tests without requiring actual database log entries.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_log_provider {
    /**
     * Get AI conversation log entries.
     *
     * Retrieves log entries from the local_ai_manager log system for
     * specified component, context, and user.
     *
     * @param string $component The component identifier (e.g., 'block_ai_chat').
     * @param int $contextid The context ID.
     * @param int $userid The user ID.
     * @param int $itemid Optional item ID filter.
     * @param bool $desc Whether to sort descending.
     * @param string $fields Fields to retrieve.
     * @param array $purposes Array of purpose strings to filter by.
     * @return array Array of log entry records.
     */
    public function get_log_entries(
        string $component,
        int $contextid,
        int $userid,
        int $itemid = 0,
        bool $desc = false,
        string $fields = '*',
        array $purposes = []
    ): array {
        return ai_manager_utils::get_log_entries(
            $component,
            $contextid,
            $userid,
            $itemid,
            $desc,
            $fields,
            $purposes
        );
    }

    /**
     * Get the connector factory for accessing purpose configurations.
     *
     * @return connector_factory The connector factory instance.
     */
    public function get_connector_factory(): connector_factory {
        return \core\di::get(connector_factory::class);
    }

    /**
     * Format output for a specific purpose.
     *
     * @param string $purposestring The purpose string (e.g., 'chat').
     * @param string $content The content to format.
     * @return string The formatted content.
     */
    public function format_purpose_output(string $purposestring, string $content): string {
        $connectorfactory = $this->get_connector_factory();
        $purpose = $connectorfactory->get_purpose_by_purpose_string($purposestring);
        return $purpose->format_output($content);
    }
}
