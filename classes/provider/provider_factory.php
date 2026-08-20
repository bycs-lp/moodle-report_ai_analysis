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

use cache;
use core_component;
use report_ai_analysis\scope_builder;

/**
 * Factory for discovering and managing data source providers.
 *
 * This factory automatically discovers all available providers using Moodle's
 * core_component API. Results are cached using Moodle's cache system (MUC)
 * for performance.
 *
 * Refactored from static methods to instance methods for better testability
 * and Dependency Injection (DI) support.
 *
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider_factory {
    /** @var cache MUC cache instance for provider classes. */
    private cache $cache;

    /** @var array|null Cached provider class names (request-level). */
    private ?array $providerclasses = null;

    /** @var base_provider[] Cached provider instances. */
    private array $providerinstances = [];

    /**
     * Constructor.
     *
     * @param cache|null $cache Optional cache instance for testing.
     */
    public function __construct(?cache $cache = null) {
        $this->cache = $cache ?? cache::make('report_ai_analysis', 'providers');
    }

    /**
     * Discover all available provider classes.
     *
     * Uses Moodle's core_component API to find provider classes in the
     * report_ai_analysis\provider namespace.
     * Results are cached in MUC and request-level cache.
     *
     * @return string[] Array of fully qualified provider class names.
     */
    public function discover_providers(): array {
        // Return from request-level cache if available.
        if ($this->providerclasses !== null) {
            return $this->providerclasses;
        }

        // Try to get from MUC cache.
        $cached = $this->cache->get('provider_classes');

        if ($cached !== false && is_array($cached)) {
            $this->providerclasses = $cached;
            return $this->providerclasses;
        }

        // Discover providers using core_component API.
        $providers = $this->scan_for_providers();

        // Cache the result.
        $this->cache->set('provider_classes', $providers);
        $this->providerclasses = $providers;

        return $providers;
    }

    /**
     * Scan for provider classes using Moodle's core_component API.
     *
     * Uses core_component::get_component_classes_in_namespace() instead of
     * glob() for better integration with Moodle's autoloading system.
     *
     * @return string[] Array of fully qualified class names.
     */
    private function scan_for_providers(): array {
        $providers = [];

        // Get all classes in the provider namespace using Moodle's API.
        $classes = core_component::get_component_classes_in_namespace(
            'report_ai_analysis',
            'provider'
        );

        foreach ($classes as $classname => $path) {
            // Skip base_provider itself (it's abstract).
            if ($classname === base_provider::class) {
                continue;
            }

            // Skip provider_factory itself.
            if ($classname === self::class) {
                continue;
            }

            // Verify class is a valid provider.
            if (!class_exists($classname)) {
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
     * @param string $sourceidentifier Source identifier (e.g., 'block_123', 'cm_456').
     * @param scope_builder $scopebuilder Scope builder.
     * @param int $maxrecords Maximum records to collect.
     * @return base_provider|null Provider instance or null if not found.
     */
    public function get_provider_for_source(
        string $sourceidentifier,
        scope_builder $scopebuilder,
        int $maxrecords = 1000
    ): ?base_provider {
        $providers = $this->discover_providers();

        foreach ($providers as $classname) {
            $cachekey = $classname . '_' . spl_object_hash($scopebuilder);

            // Reuse instance if already created for this scope.
            if (isset($this->providerinstances[$cachekey])) {
                $provider = $this->providerinstances[$cachekey];
            } else {
                $provider = new $classname($scopebuilder, $maxrecords);
                $this->providerinstances[$cachekey] = $provider;
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
     * @param scope_builder $scopebuilder Scope builder.
     * @param int $maxrecords Maximum records to collect.
     * @return base_provider[] Array of provider instances.
     */
    public function get_all_providers(scope_builder $scopebuilder, int $maxrecords = 1000): array {
        $providers = [];
        $classnames = $this->discover_providers();

        foreach ($classnames as $classname) {
            $cachekey = $classname . '_' . spl_object_hash($scopebuilder);

            // Reuse instance if already created for this scope.
            if (isset($this->providerinstances[$cachekey])) {
                $providers[] = $this->providerinstances[$cachekey];
            } else {
                $provider = new $classname($scopebuilder, $maxrecords);
                $this->providerinstances[$cachekey] = $provider;
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    /**
     * Clear provider cache.
     *
     * Should be called after installing/uninstalling plugins or when
     * purging caches.
     */
    public function clear_cache(): void {
        $this->cache->purge();
        $this->providerclasses = null;
        $this->providerinstances = [];
    }

    /**
     * Reset instance caches (for testing).
     *
     * @return void
     */
    public function reset_caches(): void {
        $this->providerclasses = null;
        $this->providerinstances = [];
    }
}
