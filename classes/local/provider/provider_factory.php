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

use cache;
use core_component;
use report_ai_analysis\scope_builder;
use report_ai_analysis\source_registry;

/**
 * Discovers supported provider classes without caching user permissions or collected data.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider_factory {
    /** @var string Cache key changed with the move to the local namespace. */
    private const CACHE_KEY = 'local_provider_classes';

    /** @var cache Provider discovery cache. */
    private cache $cache;

    /** @var string[]|null Discovered class names, not their availability or source permissions. */
    private ?array $providerclasses = null;

    /** @var base_provider[] Instances keyed by class, scope identity and initial budget. */
    private array $providerinstances = [];

    /**
     * Constructor.
     *
     * @param cache|null $cache Optional cache for dependency injection.
     */
    public function __construct(?cache $cache = null) {
        $this->cache = $cache ?? cache::make('report_ai_analysis', 'providers');
    }

    /**
     * Discover available implementations under report_ai_analysis\local\provider.
     *
     * Only class names are cached. Availability is checked again to respect plugin enable/disable changes.
     *
     * @return string[] Available supported provider classes.
     */
    public function discover_providers(): array {
        if ($this->providerclasses === null) {
            $cached = $this->cache->get(self::CACHE_KEY);
            if (is_array($cached)) {
                $this->providerclasses = $cached;
            } else {
                $classes = core_component::get_component_classes_in_namespace('report_ai_analysis', 'local\\provider');
                $this->providerclasses = array_keys($classes);
                sort($this->providerclasses);
                $this->cache->set(self::CACHE_KEY, $this->providerclasses);
            }
        }

        $providers = [];
        foreach ($this->providerclasses as $classname) {
            if (!is_string($classname) || !is_subclass_of($classname, base_provider::class)) {
                continue;
            }
            if (source_registry::get_source_type_for_plugin($classname::get_type()) === null) {
                continue;
            }
            try {
                if ($classname::is_available()) {
                    $providers[] = $classname;
                }
            } catch (\Throwable $exception) {
                throw new \moodle_exception(
                    'error_source_failed',
                    'report_ai_analysis',
                    '',
                    null,
                    $classname::get_type() . ': ' . $exception->getMessage() . "\n" . ($exception->debuginfo ?? '')
                );
            }
        }
        return $providers;
    }

    /**
     * Get a provider for an identifier in the course scope.
     *
     * @param string $sourceidentifier Source identifier.
     * @param scope_builder $scopebuilder Scope builder.
     * @param int $maxrecords Initial record budget.
     * @return base_provider|null Matching provider.
     */
    public function get_provider_for_source(
        string $sourceidentifier,
        scope_builder $scopebuilder,
        int $maxrecords = 1000
    ): ?base_provider {
        foreach ($this->get_all_providers($scopebuilder, $maxrecords) as $provider) {
            if ($provider->handles_source($sourceidentifier)) {
                return $provider;
            }
        }
        return null;
    }

    /**
     * Get available provider instances for this scope and budget.
     *
     * @param scope_builder $scopebuilder Scope builder.
     * @param int $maxrecords Initial record budget.
     * @return base_provider[] Providers.
     */
    public function get_all_providers(scope_builder $scopebuilder, int $maxrecords = 1000): array {
        $providers = [];
        foreach ($this->discover_providers() as $classname) {
            $cachekey = $classname . '_' . spl_object_id($scopebuilder) . '_' . $maxrecords;
            if (!isset($this->providerinstances[$cachekey])) {
                $this->providerinstances[$cachekey] = new $classname($scopebuilder, $maxrecords);
            }
            $provider = $this->providerinstances[$cachekey];
            $provider->set_maxrecords($maxrecords);
            $providers[] = $provider;
        }
        return $providers;
    }

    /**
     * Clear both persistent class discovery and instance caches.
     */
    public function clear_cache(): void {
        $this->cache->purge();
        $this->reset_caches();
    }

    /**
     * Clear request-local caches.
     */
    public function reset_caches(): void {
        $this->providerclasses = null;
        $this->providerinstances = [];
    }
}
