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
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis;

use core\di;
use core_text;
use report_ai_analysis\local\provider\base_provider;
use report_ai_analysis\local\provider\provider_factory;

/**
 * Collects conversation data using provider pattern.
 *
 * Refactored to use Dependency Injection (DI) for better testability.
 * The provider_factory is now injected via constructor instead of being
 * called statically.
 */
class data_collector {
    /** @var int Maximum characters in formatted source data, excluding the caller's instructions. */
    public const MAX_FORMATTED_LENGTH = 1000000;

    /** @var scope_builder The scope builder. */
    private scope_builder $scopebuilder;

    /** @var provider_factory The provider factory. */
    private provider_factory $providerfactory;

    /** @var int Maximum records to collect. */
    private int $maxrecords;

    /** @var bool Whether record collection or formatting was limited. */
    private bool $truncated = false;

    /** @var array<string, class-string<base_provider>> Implementations used for the last collection. */
    private array $collectedproviders = [];

    /**
     * Constructor.
     *
     * @param scope_builder $scopebuilder The scope builder.
     * @param int $maxrecords Maximum records to collect (default: 1000).
     * @param provider_factory|null $providerfactory Optional provider factory (injected via DI).
     */
    public function __construct(
        scope_builder $scopebuilder,
        int $maxrecords = 1000,
        ?provider_factory $providerfactory = null
    ) {
        $this->scopebuilder = $scopebuilder;
        if ($maxrecords <= 0) {
            throw new \coding_exception('Maximum records must be positive');
        }
        $this->maxrecords = min($maxrecords, base_provider::HARD_RECORD_LIMIT);
        $this->providerfactory = $providerfactory ?? di::get(provider_factory::class);
    }

    /**
     * Collect structured conversation and discussion data from all available sources.
     *
     * Uses provider pattern to automatically discover and delegate to available data providers.
     *
     * @return array Array with data from all available sources.
     * @throws \moodle_exception If no data sources are available.
     */
    public function collect(): array {
        try {
            return $this->collect_sources();
        } catch (\Throwable $exception) {
            if (
                $exception instanceof \moodle_exception && in_array(
                    $exception->errorcode,
                    ['error_no_data', 'error_source_forbidden', 'error_source_failed'],
                    true
                )
            ) {
                throw $exception;
            }
            throw new \moodle_exception(
                'error_source_failed',
                'report_ai_analysis',
                '',
                null,
                'Source collection: ' . $exception->getMessage() . "\n" . ($exception->debuginfo ?? '')
            );
        }
    }

    /**
     * Resolve current source permissions and apply one shared budget during collection.
     *
     * @return array Collected provider data.
     */
    private function collect_sources(): array {
        $this->truncated = false;
        $this->collectedproviders = [];
        $alldata = [];
        $remaining = $this->maxrecords;

        // Get sources to collect from scope.
        $sources = $this->scopebuilder->get_sources_in_scope();
        $this->scopebuilder->validate_sources();

        // Get all available providers.
        $providers = $this->providerfactory->get_all_providers($this->scopebuilder, $this->maxrecords);

        if (empty($providers)) {
            $error = $sources ? 'error_source_failed' : 'error_no_data';
            throw new \moodle_exception($error, 'report_ai_analysis');
        }
        foreach ($providers as $provider) {
            $this->collectedproviders[$provider::get_type()] = get_class($provider);
        }
        foreach ($sources as $source) {
            $handled = false;
            foreach ($providers as $provider) {
                $handled = $provider->handles_source($source) || $handled;
            }
            if (!$handled) {
                throw new \moodle_exception(
                    'error_source_failed',
                    'report_ai_analysis',
                    '',
                    null,
                    'No available provider for selected source ' . $source
                );
            }
        }

        // Collect from each provider.
        foreach ($providers as $provider) {
            $providertype = $provider::get_type();

            // If specific sources are set, check if this provider should collect.
            if (!empty($sources) && !$this->should_collect_from_provider($provider, $sources)) {
                continue;
            }

            try {
                $provider->set_maxrecords($remaining);
                $data = $provider->collect();
                $count = $provider::count_records($data);
                if ($count > $remaining) {
                    throw new \coding_exception('Provider exceeded its remaining record budget');
                }
                $remaining -= $count;
                $this->truncated = $provider->is_truncated() || $this->truncated;
                if (!empty($data)) {
                    // Store data with provider type as key.
                    $alldata[$providertype] = $data;
                }
            } catch (\Throwable $exception) {
                if ($exception instanceof \moodle_exception && $exception->errorcode === 'error_source_forbidden') {
                    throw $exception;
                }
                throw new \moodle_exception(
                    'error_source_failed',
                    'report_ai_analysis',
                    '',
                    null,
                    get_class($provider) . ': ' . $exception->getMessage() . "\n" . ($exception->debuginfo ?? '')
                );
            }
        }

        // Check if any data was collected.
        if (empty($alldata)) {
            if ($this->truncated) {
                throw new \moodle_exception(
                    'error_source_failed',
                    'report_ai_analysis',
                    '',
                    null,
                    'The source scan safety limit was reached before any in-scope data could be included'
                );
            }
            throw new \moodle_exception('error_no_data', 'report_ai_analysis');
        }

        return $alldata;
    }

    /**
     * Determine whether the provider handles at least one explicitly selected source.
     *
     * @param base_provider $provider Source provider.
     * @param array $sources Selected source identifiers.
     * @return bool Whether to collect from the provider.
     */
    private function should_collect_from_provider(base_provider $provider, array $sources): bool {
        foreach ($sources as $source) {
            if ($provider->handles_source($source)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get formatted data as string for AI analysis.
     *
     * Delegates formatting to individual providers.
     *
     * @param array $data Array of collected data.
     * @param provider_factory|null $providerfactory Optional provider factory for testing.
     * @return string Formatted data.
     */
    public function format_for_ai(array $data, ?provider_factory $providerfactory = null): string {
        $output = [];
        foreach ($this->get_data_providers($data, $providerfactory) as $type => $providerclass) {
            try {
                $formatted = $providerclass::format_for_ai($data[$type]);
            } catch (\Throwable $exception) {
                throw new \moodle_exception(
                    'error_source_failed',
                    'report_ai_analysis',
                    '',
                    null,
                    $type . ' formatting: ' . $exception->getMessage() . "\n" . ($exception->debuginfo ?? '')
                );
            }
            if ($formatted !== '') {
                $output[] = $formatted;
            }
            $this->truncated = $this->truncated || in_array(true, array_column($data[$type], 'truncated'), true);
        }

        $formatted = implode("\n\n", $output);
        if (core_text::strlen($formatted) > self::MAX_FORMATTED_LENGTH) {
            $this->truncated = true;
            $marker = "\n" . get_string('export_truncated', 'report_ai_analysis');
            $formatted = core_text::substr($formatted, 0, self::MAX_FORMATTED_LENGTH - core_text::strlen($marker)) . $marker;
        }
        return $formatted;
    }

    /**
     * Return the last collection/formatting truncation state.
     *
     * @return bool Whether the extraction or formatted text is incomplete.
     */
    public function is_truncated(): bool {
        return $this->truncated;
    }

    /**
     * Filter already authorised data for one author, preserving provider keys and shapes.
     *
     * No names or identities are taken from scope JSON, and no additional source data is fetched.
     *
     * @param array $data Collected provider data.
     * @param int $userid Actual author identifier.
     * @return array The author's data only.
     */
    public function filter_by_user(array $data, int $userid): array {
        $filtered = [];
        foreach ($this->get_data_providers($data) as $type => $providerclass) {
            $userdata = $providerclass::filter_by_user($data[$type], $userid);
            if ($userdata) {
                $filtered[$type] = $userdata;
            }
        }
        return $filtered;
    }

    /**
     * Format sources separately for every actual author, including represented forum thread roots.
     *
     * These strings contain sources only, not the report prompt, other users' posts or other starters' metadata.
     *
     * @param array $data Collected provider data.
     * @return array<int, string> Formatted source data keyed by actual author identifier.
     */
    public function get_user_data(array $data): array {
        $userids = [];
        foreach ($this->get_data_providers($data) as $type => $providerclass) {
            foreach ($providerclass::get_user_ids($data[$type]) as $userid) {
                if ($userid > 0) {
                    $userids[(int)$userid] = (int)$userid;
                }
            }
        }
        ksort($userids);
        $result = [];
        foreach ($userids as $userid) {
            $filtered = $this->filter_by_user($data, $userid);
            $formatted = $this->format_for_ai($filtered);
            if ($formatted !== '') {
                $result[$userid] = $formatted;
            }
        }
        return $result;
    }

    /**
     * Get statistics about collected data.
     *
     * Delegates statistics calculation to individual providers.
     *
     * @param array $data Array of collected data.
     * @param provider_factory|null $providerfactory Optional provider factory for testing.
     * @return array Statistics.
     */
    public function get_statistics(array $data, ?provider_factory $providerfactory = null): array {
        $stats = [
            'total_sources' => 0,
        ];

        foreach ($this->get_data_providers($data, $providerfactory) as $type => $providerclass) {
            $providerstats = $providerclass::get_statistics($data[$type]);
            if ($providerstats) {
                $stats[$type] = $providerstats;
                $stats['total_sources']++;
            }
        }

        return $stats;
    }

    /**
     * Resolve every nonempty data source; missing formatters must not silently drop collected content.
     *
     * Reuse implementations from collection even if plugin availability subsequently changes. This performs
     * no further source reads and never treats stored scope metadata as an authority or a class name.
     *
     * @param array $data Collected provider data.
     * @param provider_factory|null $factory Optional alternative factory for compatibility/testing.
     * @return array<string, class-string<base_provider>> Implementations keyed by provider type.
     */
    private function get_data_providers(array $data, ?provider_factory $factory = null): array {
        if (!$data) {
            return [];
        }
        $providers = $factory ? [] : $this->collectedproviders;
        if (!$providers) {
            foreach (($factory ?? $this->providerfactory)->discover_providers() as $classname) {
                $providers[$classname::get_type()] = $classname;
            }
        }
        $result = [];
        foreach ($data as $type => $providerdata) {
            if (!$providerdata) {
                continue;
            }
            if (!isset($providers[$type])) {
                throw new \moodle_exception(
                    'error_source_failed',
                    'report_ai_analysis',
                    '',
                    null,
                    'No implementation for collected source ' . $type
                );
            }
            $result[$type] = $providers[$type];
        }
        return $result;
    }
}
