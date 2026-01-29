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
 * Unit tests for provider_factory with DI.
 *
 * Tests the provider_factory class focusing on discovery mechanism
 * and caching behavior.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_mbs
 * @group      mebis
 */

namespace report_ai_analysis\unit;

use cache;
use PHPUnit\Framework\MockObject\MockObject;
use report_ai_analysis\provider\provider_factory;
use report_ai_analysis\scope_builder;

/**
 * Unit test class for provider_factory.
 *
 * Tests the provider_factory discovery, caching, and instance management.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_ai_analysis\provider\provider_factory
 */
final class provider_factory_unit_test extends \advanced_testcase {
    /**
     * Set up before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Test that provider_factory discovers available providers.
     *
     * @covers \report_ai_analysis\provider\provider_factory::discover_providers
     */
    public function test_discover_providers_finds_available_providers(): void {
        $factory = new provider_factory();
        $providers = $factory->discover_providers();

        // Should return an array.
        $this->assertIsArray($providers);

        // All discovered classes should be subclasses of base_provider.
        foreach ($providers as $classname) {
            $this->assertTrue(
                is_subclass_of($classname, \report_ai_analysis\provider\base_provider::class),
                "Class {$classname} should be subclass of base_provider"
            );
        }
    }

    /**
     * Test that provider_factory uses cache for subsequent calls.
     *
     * @covers \report_ai_analysis\provider\provider_factory::discover_providers
     */
    public function test_discover_providers_uses_request_cache(): void {
        $factory = new provider_factory();

        // First call.
        $providers1 = $factory->discover_providers();

        // Second call should use request-level cache.
        $providers2 = $factory->discover_providers();

        // Results should be identical.
        $this->assertEquals($providers1, $providers2);
    }

    /**
     * Test that provider_factory caches to MUC.
     *
     * @covers \report_ai_analysis\provider\provider_factory::discover_providers
     */
    public function test_discover_providers_caches_to_muc(): void {
        // Create mock cache.
        $mockcache = $this->createMock(cache::class);

        // Cache should be checked first.
        $mockcache->expects($this->once())
            ->method('get')
            ->with('provider_classes')
            ->willReturn(false);

        // Cache should be set after discovery.
        $mockcache->expects($this->once())
            ->method('set')
            ->with('provider_classes', $this->isType('array'));

        $factory = new provider_factory($mockcache);
        $factory->discover_providers();
    }

    /**
     * Test that provider_factory returns cached MUC value.
     *
     * @covers \report_ai_analysis\provider\provider_factory::discover_providers
     */
    public function test_discover_providers_returns_cached_muc_value(): void {
        $cachedproviders = ['\\TestProvider1', '\\TestProvider2'];

        // Create mock cache that returns cached value.
        $mockcache = $this->createMock(cache::class);
        $mockcache->expects($this->once())
            ->method('get')
            ->with('provider_classes')
            ->willReturn($cachedproviders);

        // Cache::set should NOT be called.
        $mockcache->expects($this->never())
            ->method('set');

        $factory = new provider_factory($mockcache);
        $providers = $factory->discover_providers();

        $this->assertEquals($cachedproviders, $providers);
    }

    /**
     * Test get_all_providers returns provider instances.
     *
     * @covers \report_ai_analysis\provider\provider_factory::get_all_providers
     */
    public function test_get_all_providers_returns_instances(): void {
        $course = $this->getDataGenerator()->create_course();
        $scopebuilder = new scope_builder($course->id);

        $factory = new provider_factory();
        $providers = $factory->get_all_providers($scopebuilder);

        $this->assertIsArray($providers);

        // All should be base_provider instances.
        foreach ($providers as $provider) {
            $this->assertInstanceOf(
                \report_ai_analysis\provider\base_provider::class,
                $provider
            );
        }
    }

    /**
     * Test get_all_providers reuses instances for same scope.
     *
     * @covers \report_ai_analysis\provider\provider_factory::get_all_providers
     */
    public function test_get_all_providers_reuses_instances(): void {
        $course = $this->getDataGenerator()->create_course();
        $scopebuilder = new scope_builder($course->id);

        $factory = new provider_factory();

        // First call.
        $providers1 = $factory->get_all_providers($scopebuilder);

        // Second call with same scope.
        $providers2 = $factory->get_all_providers($scopebuilder);

        // Instances should be identical (same object references).
        $this->assertEquals(count($providers1), count($providers2));

        for ($i = 0; $i < count($providers1); $i++) {
            $this->assertSame($providers1[$i], $providers2[$i]);
        }
    }

    /**
     * Test get_provider_for_source returns null for unknown source.
     *
     * @covers \report_ai_analysis\provider\provider_factory::get_provider_for_source
     */
    public function test_get_provider_for_source_returns_null_for_unknown(): void {
        $course = $this->getDataGenerator()->create_course();
        $scopebuilder = new scope_builder($course->id);

        $factory = new provider_factory();
        $provider = $factory->get_provider_for_source('unknown_999', $scopebuilder);

        $this->assertNull($provider);
    }

    /**
     * Test clear_cache purges MUC and resets internal caches.
     *
     * @covers \report_ai_analysis\provider\provider_factory::clear_cache
     */
    public function test_clear_cache_purges_all_caches(): void {
        // Create mock cache.
        $mockcache = $this->createMock(cache::class);
        $mockcache->expects($this->once())
            ->method('purge');

        $factory = new provider_factory($mockcache);
        $factory->clear_cache();

        // After clear, next discover should re-scan.
        // This is implicitly tested by the purge expectation.
    }

    /**
     * Test reset_caches clears internal caches only.
     *
     * @covers \report_ai_analysis\provider\provider_factory::reset_caches
     */
    public function test_reset_caches_clears_internal_only(): void {
        $course = $this->getDataGenerator()->create_course();
        $scopebuilder = new scope_builder($course->id);

        $factory = new provider_factory();

        // Populate caches.
        $factory->discover_providers();
        $factory->get_all_providers($scopebuilder);

        // Reset.
        $factory->reset_caches();

        // After reset, next call should work (no errors).
        $providers = $factory->discover_providers();
        $this->assertIsArray($providers);
    }

    /**
     * Test constructor uses default cache when none provided.
     *
     * @covers \report_ai_analysis\provider\provider_factory::__construct
     */
    public function test_constructor_creates_default_cache(): void {
        $factory = new provider_factory();

        // Should not throw exceptions.
        $this->assertInstanceOf(provider_factory::class, $factory);
    }

    /**
     * Test that provider_factory does not include base_provider or itself.
     *
     * @covers \report_ai_analysis\provider\provider_factory::discover_providers
     */
    public function test_discover_providers_excludes_base_and_factory(): void {
        $factory = new provider_factory();
        $providers = $factory->discover_providers();

        // Should not contain base_provider.
        $this->assertNotContains(
            \report_ai_analysis\provider\base_provider::class,
            $providers
        );

        // Should not contain provider_factory itself.
        $this->assertNotContains(
            provider_factory::class,
            $providers
        );
    }
}
