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
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_mbs
 * @group      mebis
 */

namespace report_ai_analysis\unit;

use cache;
use report_ai_analysis\provider\provider_factory;
use report_ai_analysis\scope_builder;

/**
 * Unit test class for provider_factory.
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
     * Test discovery finds valid providers and excludes base classes.
     *
     * @covers \report_ai_analysis\provider\provider_factory::discover_providers
     */
    public function test_discover_providers_finds_valid_providers(): void {
        $factory = new provider_factory();
        $providers = $factory->discover_providers();

        $this->assertIsArray($providers);

        // All discovered classes should be subclasses of base_provider.
        foreach ($providers as $classname) {
            $this->assertTrue(
                is_subclass_of($classname, \report_ai_analysis\provider\base_provider::class),
                "Class {$classname} should be subclass of base_provider"
            );
        }

        // Should not contain base_provider or factory itself.
        $this->assertNotContains(\report_ai_analysis\provider\base_provider::class, $providers);
        $this->assertNotContains(provider_factory::class, $providers);
    }

    /**
     * Test MUC caching behavior - cache hit returns cached value, cache miss triggers discovery.
     *
     * @covers \report_ai_analysis\provider\provider_factory::discover_providers
     */
    public function test_discover_providers_muc_caching(): void {
        $cachedproviders = ['\\TestProvider1', '\\TestProvider2'];

        // Test cache hit - should return cached value without calling set.
        $mockcache = $this->createMock(cache::class);
        $mockcache->expects($this->once())
            ->method('get')
            ->with('provider_classes')
            ->willReturn($cachedproviders);
        $mockcache->expects($this->never())->method('set');

        $factory = new provider_factory($mockcache);
        $providers = $factory->discover_providers();
        $this->assertEquals($cachedproviders, $providers);

        // Test cache miss - should call set after discovery.
        $mockcache2 = $this->createMock(cache::class);
        $mockcache2->expects($this->once())
            ->method('get')
            ->with('provider_classes')
            ->willReturn(false);
        $mockcache2->expects($this->once())
            ->method('set')
            ->with('provider_classes', $this->isType('array'));

        $factory2 = new provider_factory($mockcache2);
        $factory2->discover_providers();
    }

    /**
     * Test get_all_providers returns valid base_provider instances and reuses them.
     *
     * @covers \report_ai_analysis\provider\provider_factory::get_all_providers
     */
    public function test_get_all_providers_returns_and_reuses_instances(): void {
        $course = $this->getDataGenerator()->create_course();
        $scopebuilder = new scope_builder($course->id);

        $factory = new provider_factory();
        $providers1 = $factory->get_all_providers($scopebuilder);

        $this->assertIsArray($providers1);
        foreach ($providers1 as $provider) {
            $this->assertInstanceOf(\report_ai_analysis\provider\base_provider::class, $provider);
        }

        // Second call should return same instances.
        $providers2 = $factory->get_all_providers($scopebuilder);
        $this->assertEquals(count($providers1), count($providers2));
        for ($i = 0; $i < count($providers1); $i++) {
            $this->assertSame($providers1[$i], $providers2[$i]);
        }
    }

    /**
     * Test get_provider_for_source returns null for unknown sources.
     *
     * @covers \report_ai_analysis\provider\provider_factory::get_provider_for_source
     */
    public function test_get_provider_for_source_unknown_returns_null(): void {
        $course = $this->getDataGenerator()->create_course();
        $scopebuilder = new scope_builder($course->id);

        $factory = new provider_factory();
        $provider = $factory->get_provider_for_source('unknown_999', $scopebuilder);

        $this->assertNull($provider);
    }

    /**
     * Test cache clearing and reset functionality.
     *
     * @covers \report_ai_analysis\provider\provider_factory::clear_cache
     * @covers \report_ai_analysis\provider\provider_factory::reset_caches
     */
    public function test_cache_management(): void {
        $course = $this->getDataGenerator()->create_course();
        $scopebuilder = new scope_builder($course->id);

        // Test clear_cache purges MUC.
        $mockcache = $this->createMock(cache::class);
        $mockcache->expects($this->once())->method('purge');
        $mockcache->method('get')->willReturn(false);
        $mockcache->method('set');

        $factory = new provider_factory($mockcache);
        $factory->clear_cache();

        // Test reset_caches allows continued operation.
        $factory2 = new provider_factory();
        $factory2->discover_providers();
        $factory2->get_all_providers($scopebuilder);
        $factory2->reset_caches();

        // Should work after reset.
        $providers = $factory2->discover_providers();
        $this->assertIsArray($providers);
    }
}
