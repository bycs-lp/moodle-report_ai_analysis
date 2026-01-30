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
 * Testable provider class for unit testing.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis\tests\fixtures;

use report_ai_analysis\provider\base_provider;

/**
 * Testable provider class for unit testing.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_provider extends base_provider {
    /**
     * Get provider type.
     *
     * @return string Provider type.
     */
    public static function get_type(): string {
        return 'test_provider';
    }

    /**
     * Check if available.
     *
     * @return bool Always true.
     */
    public static function is_available(): bool {
        return true;
    }

    /**
     * Get metadata.
     *
     * @return array Metadata.
     */
    public static function get_metadata(): array {
        return ['name' => 'Test Provider', 'type' => 'test_provider'];
    }

    /**
     * Collect data.
     *
     * @return array Data.
     */
    public function collect(): array {
        return [];
    }

    /**
     * Format for AI.
     *
     * @param array $data Data to format.
     * @return string Formatted data.
     */
    public static function format_for_ai(array $data): string {
        return 'Test formatted';
    }

    /**
     * Get statistics.
     *
     * @param array $data Data to analyze.
     * @return array Statistics.
     */
    public static function get_statistics(array $data): array {
        return ['count' => count($data)];
    }

    /**
     * Check if this provider handles the given source.
     *
     * @param string $sourceidentifier Source identifier.
     * @return bool True if this provider handles this source.
     */
    public function handles_source(string $sourceidentifier): bool {
        return true;
    }
}
