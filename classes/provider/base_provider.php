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
 * Base provider interface and abstract class for data sources.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis\provider;

use report_ai_analysis\scope_builder;

/**
 * Base provider for AI analysis data sources.
 *
 * This abstract class defines the contract for all data source providers.
 * Each provider is responsible for:
 * - Checking if its data source is available
 * - Collecting data based on scope
 * - Formatting data for AI analysis
 * - Providing statistics about collected data
 * - Determining if it handles specific source identifiers
 *
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_provider {
    /** @var scope_builder The scope builder */
    protected $scopebuilder;

    /** @var int Maximum records to collect */
    protected $maxrecords;

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
     * Check if this data source is available (plugin installed and enabled).
     *
     * @return bool True if data source is available
     */
    abstract public static function is_available(): bool;

    /**
     * Get provider metadata.
     *
     * @return array Metadata array with keys:
     *               - name: Human-readable name
     *               - type: Provider type identifier
     *               - version: Provider version (optional)
     */
    abstract public static function get_metadata(): array;

    /**
     * Collect data based on scope.
     *
     * @return array Array of collected data items
     * @throws \moodle_exception If data collection fails
     */
    abstract public function collect(): array;

    /**
     * Format collected data as string for AI analysis.
     *
     * @param array $data Array of collected data
     * @return string Formatted data ready for AI processing
     */
    abstract public static function format_for_ai(array $data): string;

    /**
     * Get statistics about collected data.
     *
     * @param array $data Array of collected data
     * @return array Statistics array
     */
    abstract public static function get_statistics(array $data): array;

    /**
     * Check if this provider handles the given source identifier.
     *
     * Source identifiers follow patterns like:
     * - 'block_123' for block instances
     * - 'cm_456' for course modules
     *
     * @param string $sourceidentifier Source identifier
     * @return bool True if this provider handles this source
     */
    abstract public function handles_source(string $sourceidentifier): bool;

    /**
     * Get display name for this provider.
     *
     * @return string Localized display name
     */
    public static function get_display_name(): string {
        $metadata = static::get_metadata();
        return $metadata['name'] ?? static::class;
    }

    /**
     * Get provider type identifier.
     *
     * @return string Type identifier (e.g., 'block_ai_chat', 'mod_forum')
     */
    public static function get_type(): string {
        $metadata = static::get_metadata();
        return $metadata['type'] ?? '';
    }
}
