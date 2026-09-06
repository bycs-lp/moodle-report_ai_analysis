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

namespace report_ai_analysis\local\provider\fixtures;

use report_ai_analysis\local\provider\base_provider;

/**
 * Injectable provider fixture; discovery never enables this unsupported source in production.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_provider extends base_provider {
    /**
     * Get the fixture provider type.
     *
     * @return string Fixture type.
     */
    public static function get_type(): string {
        return 'test_provider';
    }

    /**
     * Get availability for tests.
     *
     * @return bool Always available.
     */
    public static function is_available(): bool {
        return true;
    }

    /**
     * Get fixture metadata.
     *
     * @return array Metadata.
     */
    public static function get_metadata(): array {
        return ['name' => 'Test provider', 'type' => static::get_type()];
    }

    /**
     * Collect no data unless mocked by a test.
     *
     * @return array Empty data.
     */
    public function collect(): array {
        return [];
    }

    /**
     * Format fixture content.
     *
     * @param array $data Fixture records.
     * @return string Concatenated content.
     */
    public static function format_for_ai(array $data): string {
        return implode("\n", array_column($data, 'content'));
    }

    /**
     * Get fixture statistics.
     *
     * @param array $data Fixture records.
     * @return array Statistics.
     */
    public static function get_statistics(array $data): array {
        return ['count' => count($data)];
    }

    /**
     * Accept a source for source-filter tests.
     *
     * @param string $sourceidentifier Source identifier.
     * @return bool Whether the source is handled.
     */
    public function handles_source(string $sourceidentifier): bool {
        return true;
    }
}
