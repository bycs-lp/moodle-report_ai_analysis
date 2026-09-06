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

use core_text;
use report_ai_analysis\scope_builder;

/**
 * Contract for authorised, bounded analysis data providers.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_provider {
    /** @var int Absolute record and candidate scan safety limit. */
    public const HARD_RECORD_LIMIT = 10000;

    /** @var int Maximum characters of one message in formatted analysis data. */
    public const MAX_TEXT_LENGTH = 2000;

    /** @var scope_builder The scope, not a substitute for current source permissions. */
    protected scope_builder $scopebuilder;

    /** @var int Remaining analysis budget, measured in posts or request log entries. */
    protected int $maxrecords;

    /** @var bool Whether data or formatted message content has been limited. */
    protected bool $truncated = false;

    /**
     * Constructor.
     *
     * @param scope_builder $scopebuilder Scope builder.
     * @param int $maxrecords Maximum individual posts or request entries to include.
     */
    public function __construct(scope_builder $scopebuilder, int $maxrecords = 1000) {
        $this->scopebuilder = $scopebuilder;
        if ($maxrecords <= 0) {
            throw new \coding_exception('Maximum records must be positive');
        }
        $this->set_maxrecords($maxrecords);
    }

    /**
     * Set the remaining shared budget before collection.
     *
     * Zero permits a bounded lookahead only, so exhaustion can be reported without including another record.
     *
     * @param int $maxrecords Remaining record budget.
     */
    public function set_maxrecords(int $maxrecords): void {
        if ($maxrecords < 0) {
            throw new \coding_exception('Remaining record budget must not be negative');
        }
        $this->maxrecords = min($maxrecords, self::HARD_RECORD_LIMIT);
    }

    /**
     * Report whether the last collection was incomplete or message text needed shortening.
     *
     * @return bool Truncation indicator.
     */
    public function is_truncated(): bool {
        return $this->truncated;
    }

    /**
     * Check whether this source's plugin is installed and enabled.
     *
     * @return bool Whether the provider is available.
     */
    abstract public static function is_available(): bool;

    /**
     * Get provider metadata.
     *
     * @return array Metadata including name and type.
     */
    abstract public static function get_metadata(): array;

    /**
     * Collect only authorised data within the current budget.
     *
     * @return array Structured provider data.
     */
    abstract public function collect(): array;

    /**
     * Format already collected data for analysis.
     *
     * @param array $data Collected data.
     * @return string Formatted data.
     */
    abstract public static function format_for_ai(array $data): string;

    /**
     * Get statistics about included data only.
     *
     * @param array $data Collected data.
     * @return array Statistics.
     */
    abstract public static function get_statistics(array $data): array;

    /**
     * Check whether this provider handles a course-bound source identifier.
     *
     * @param string $sourceidentifier Source identifier.
     * @return bool Whether the source is handled.
     */
    abstract public function handles_source(string $sourceidentifier): bool;

    /**
     * Count individual records, rather than discussion or conversation containers.
     *
     * @param array $data Collected data.
     * @return int Number of records.
     */
    public static function count_records(array $data): int {
        return count($data);
    }

    /**
     * Get actual authors represented in collected data, never users from scope metadata.
     *
     * Providers must opt in to person-level processing; an unknown shape cannot safely be attributed.
     *
     * @param array $data Collected data.
     * @return int[] Actual author identifiers.
     */
    public static function get_user_ids(array $data): array {
        return [];
    }

    /**
     * Keep a single author's data, removing other authors' thread metadata as well as their posts.
     *
     * @param array $data Collected data.
     * @param int $userid Author identifier.
     * @return array Data of the same provider shape, or empty when attribution is not supported.
     */
    public static function filter_by_user(array $data, int $userid): array {
        return [];
    }

    /**
     * Get a localised display name.
     *
     * @return string Display name.
     */
    public static function get_display_name(): string {
        return static::get_metadata()['name'] ?? static::class;
    }

    /**
     * Get the provider's component identifier.
     *
     * @return string Component identifier.
     */
    public static function get_type(): string {
        return static::get_metadata()['type'] ?? '';
    }

    /**
     * Format and shorten human text without splitting UTF-8 characters.
     *
     * @param string $text Message text.
     * @param int $format Moodle text format.
     * @return string Plain text with an explicit marker when shortened.
     */
    protected static function format_message(string $text, int $format): string {
        $text = trim(format_text_email($text, $format));
        if (core_text::strlen($text) > self::MAX_TEXT_LENGTH) {
            $text = core_text::substr($text, 0, self::MAX_TEXT_LENGTH) . ' ' . get_string('export_truncated', 'report_ai_analysis');
        }
        return $text;
    }
}
