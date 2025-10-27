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

use report_ai_analysis\provider\provider_factory;

/**
 * Collects structured conversation and discussion data.
 *
 * This collector uses a provider pattern to delegate collection to specific
 * data source providers. Providers are automatically discovered and instantiated
 * based on available plugins and scope requirements.
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
     * Uses provider pattern to automatically discover and delegate to available data providers.
     *
     * @return array Array with data from all available sources
     * @throws \moodle_exception If no data sources are available
     */
    public function collect(): array {
        $alldata = [];

        // Get sources to collect from scope.
        $sources = $this->scopebuilder->get_sources_in_scope();

        // Get all available providers.
        $providers = provider_factory::get_all_providers($this->scopebuilder, $this->maxrecords);

        if (empty($providers)) {
            throw new \moodle_exception('error_no_data', 'report_ai_analysis');
        }

        // Collect from each provider.
        foreach ($providers as $provider) {
            $providertype = $provider::get_type();

            // If specific sources are set, check if this provider should collect.
            if (!empty($sources) && !$this->should_collect_from_provider($provider, $sources)) {
                continue;
            }

            try {
                $data = $provider->collect();
                if (!empty($data)) {
                    // Store data with provider type as key.
                    $alldata[$providertype] = $data;
                }
            } catch (\Exception $e) {
                debugging('Provider ' . get_class($provider) . ' failed: ' . s($e->getMessage()), DEBUG_DEVELOPER);
            }
        }

        // Check if any data was collected.
        if (empty($alldata)) {
            throw new \moodle_exception('error_no_data', 'report_ai_analysis');
        }

        return $alldata;
    }

    /**
     * Check if provider should collect based on sources.
     *
     * @param \report_ai_analysis\provider\base_provider $provider Provider instance
     * @param array $sources Array of source identifiers
     * @return bool True if provider should collect
     */
    private function should_collect_from_provider($provider, array $sources): bool {
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
     * @param array $data Array of collected data
     * @return string Formatted data
     */
    public static function format_for_ai(array $data): string {
        $output = [];

        // Get all available providers to access their format methods.
        $providers = provider_factory::discover_providers();

        foreach ($data as $providertype => $providerdata) {
            if (empty($providerdata)) {
                continue;
            }

            // Find matching provider class.
            foreach ($providers as $providerclass) {
                if ($providerclass::get_type() === $providertype) {
                    $formatted = $providerclass::format_for_ai($providerdata);
                    if (!empty($formatted)) {
                        $output[] = $formatted;
                    }
                    break;
                }
            }
        }

        return implode("\n\n", $output);
    }

    /**
     * Get statistics about collected data.
     *
     * Delegates statistics calculation to individual providers.
     *
     * @param array $data Array of collected data
     * @return array Statistics
     */
    public static function get_statistics(array $data): array {
        $stats = [
            'total_sources' => 0,
        ];

        // Get all available providers to access their statistics methods.
        $providers = provider_factory::discover_providers();

        foreach ($data as $providertype => $providerdata) {
            if (empty($providerdata)) {
                continue;
            }

            // Find matching provider class.
            foreach ($providers as $providerclass) {
                if ($providerclass::get_type() === $providertype) {
                    $providerstats = $providerclass::get_statistics($providerdata);
                    if (!empty($providerstats)) {
                        $stats[$providertype] = $providerstats;
                        $stats['total_sources']++;
                    }
                    break;
                }
            }
        }

        return $stats;
    }
}
