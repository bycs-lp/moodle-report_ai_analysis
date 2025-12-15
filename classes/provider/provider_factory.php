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
 * Provider factory for auto-discovery and instantiation of data providers.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis\provider;

use report_ai_analysis\scope_builder;

/**
 * Factory for discovering and managing data source providers.
 *
 * This factory automatically discovers all available providers by scanning
 * the provider directory for files matching the pattern *_provider.php.
 * Results are cached using Moodle's cache system for performance.
 *
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider_factory {
    /** @var array Cached provider class names */
    private static $providerclasses = null;

    /** @var base_provider[] Cached provider instances */
    private static $providerinstances = [];

    /**
     * Discover all available provider classes.
     *
     * Scans the provider directory for *_provider.php files and returns
     * fully qualified class names of available providers.
     * Results are cached in static variable and MUC.
     *
     * @return string[] Array of fully qualified provider class names
     */
    public static function discover_providers(): array {
        // Return from static cache if available.
        if (self::$providerclasses !== null) {
            return self::$providerclasses;
        }

        // Try to get from MUC cache.
        $cache = \cache::make('report_ai_analysis', 'providers');
        $cached = $cache->get('provider_classes');

        if ($cached !== false && is_array($cached)) {
            self::$providerclasses = $cached;
            return self::$providerclasses;
        }

        // Discover providers by scanning directory.
        $providers = self::scan_for_providers();

        // Cache the result.
        $cache->set('provider_classes', $providers);
        self::$providerclasses = $providers;

        return $providers;
    }

    /**
     * Scan provider directory for provider classes.
     *
     * @return string[] Array of fully qualified class names
     */
    private static function scan_for_providers(): array {
        $providers = [];
        $providerdir = __DIR__;

        // Scan for *_provider.php files.
        $files = glob($providerdir . '/*_provider.php');

        if ($files === false) {
            debugging('Failed to scan provider directory', DEBUG_DEVELOPER);
            return [];
        }

        foreach ($files as $file) {
            $basename = basename($file, '.php');
            $classname = "\\report_ai_analysis\\provider\\{$basename}";

            // Verify class exists and is a valid provider.
            if (!class_exists($classname)) {
                continue;
            }

            // Skip base_provider itself (it's abstract).
            if ($classname === '\\report_ai_analysis\\provider\\base_provider') {
                continue;
            }

            if (!is_subclass_of($classname, base_provider::class)) {
                debugging("Class {$classname} is not a subclass of base_provider", DEBUG_DEVELOPER);
                continue;
            }

            // Check if provider is available.
            try {
                if (!$classname::is_available()) {
                    continue;
                }
            } catch (\Exception $e) {
                debugging("Provider {$classname} availability check failed: " . $e->getMessage(), DEBUG_DEVELOPER);
                continue;
            }

            $providers[] = $classname;
        }

        return $providers;
    }

    /**
     * Get provider instance for a specific source identifier.
     *
     * @param string $sourceidentifier Source identifier (e.g., 'block_123', 'cm_456')
     * @param scope_builder $scopebuilder Scope builder
     * @param int $maxrecords Maximum records to collect
     * @return base_provider|null Provider instance or null if not found
     */
    public static function get_provider_for_source(
        string $sourceidentifier,
        scope_builder $scopebuilder,
        int $maxrecords = 1000
    ): ?base_provider {
        $providers = self::discover_providers();

        foreach ($providers as $classname) {
            $cachekey = $classname . '_' . spl_object_hash($scopebuilder);

            // Reuse instance if already created for this scope.
            if (isset(self::$providerinstances[$cachekey])) {
                $provider = self::$providerinstances[$cachekey];
            } else {
                $provider = new $classname($scopebuilder, $maxrecords);
                self::$providerinstances[$cachekey] = $provider;
            }

            if ($provider->handles_source($sourceidentifier)) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Get all available provider instances.
     *
     * @param scope_builder $scopebuilder Scope builder
     * @param int $maxrecords Maximum records to collect
     * @return base_provider[] Array of provider instances
     */
    public static function get_all_providers(scope_builder $scopebuilder, int $maxrecords = 1000): array {
        $providers = [];
        $classnames = self::discover_providers();

        foreach ($classnames as $classname) {
            $cachekey = $classname . '_' . spl_object_hash($scopebuilder);

            // Reuse instance if already created for this scope.
            if (isset(self::$providerinstances[$cachekey])) {
                $providers[] = self::$providerinstances[$cachekey];
            } else {
                $provider = new $classname($scopebuilder, $maxrecords);
                self::$providerinstances[$cachekey] = $provider;
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    /**
     * Get metadata for all available providers.
     *
     * @return array Array of metadata arrays keyed by provider class
     */
    public static function get_all_metadata(): array {
        $metadata = [];
        $providers = self::discover_providers();

        foreach ($providers as $classname) {
            try {
                $metadata[$classname] = $classname::get_metadata();
            } catch (\Exception $e) {
                debugging("Failed to get metadata for {$classname}: " . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        return $metadata;
    }

    /**
     * Clear provider cache.
     *
     * Should be called after installing/uninstalling plugins or when
     * purging caches.
     */
    public static function clear_cache(): void {
        $cache = \cache::make('report_ai_analysis', 'providers');
        $cache->purge();
        self::$providerclasses = null;
        self::$providerinstances = [];
    }

    /**
     * Reset static caches (for testing).
     *
     * @return void
     */
    public static function reset_caches(): void {
        self::$providerclasses = null;
        self::$providerinstances = [];
    }
}
