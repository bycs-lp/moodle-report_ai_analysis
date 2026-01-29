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
 * Unit tests for data_collector with mocked dependencies.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_mbs
 * @group      mebis
 */

namespace report_ai_analysis\unit;

use PHPUnit\Framework\MockObject\MockObject;
use report_ai_analysis\data_collector;
use report_ai_analysis\provider\base_provider;
use report_ai_analysis\provider\provider_factory;
use report_ai_analysis\scope_builder;

/**
 * Unit test class for data_collector with DI.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_ai_analysis\data_collector
 */
final class data_collector_unit_test extends \advanced_testcase {
    /** @var MockObject|provider_factory Mock provider factory. */
    private MockObject $mockfactory;

    /** @var MockObject|scope_builder Mock scope builder. */
    private MockObject $mockscopebuilder;

    /**
     * Set up before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->mockfactory = $this->createMock(provider_factory::class);
        $this->mockscopebuilder = $this->getMockBuilder(scope_builder::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * Test collect throws exception when no providers available.
     *
     * @covers \report_ai_analysis\data_collector::collect
     */
    public function test_collect_throws_when_no_providers(): void {
        $this->mockscopebuilder->method('get_sources_in_scope')->willReturn([]);
        $this->mockfactory->method('get_all_providers')->willReturn([]);

        $collector = new data_collector($this->mockscopebuilder, 1000, $this->mockfactory);

        $this->expectException(\moodle_exception::class);
        $collector->collect();
    }

    /**
     * Test collect throws exception when all providers return empty.
     *
     * @covers \report_ai_analysis\data_collector::collect
     */
    public function test_collect_throws_when_all_providers_empty(): void {
        $this->mockscopebuilder->method('get_sources_in_scope')->willReturn([]);

        $mockprovider = $this->getMockBuilder(TestableProvider::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['collect'])
            ->getMock();
        $mockprovider->method('collect')->willReturn([]);

        $this->mockfactory->method('get_all_providers')->willReturn([$mockprovider]);

        $collector = new data_collector($this->mockscopebuilder, 1000, $this->mockfactory);

        $this->expectException(\moodle_exception::class);
        $collector->collect();
    }

    /**
     * Test collect returns data from providers keyed by provider type.
     *
     * @covers \report_ai_analysis\data_collector::collect
     */
    public function test_collect_returns_provider_data(): void {
        $this->mockscopebuilder->method('get_sources_in_scope')->willReturn([]);

        $testdata = [['id' => 1, 'content' => 'Test content']];
        $mockprovider = $this->getMockBuilder(TestableProvider::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['collect'])
            ->getMock();
        $mockprovider->method('collect')->willReturn($testdata);

        $this->mockfactory->method('get_all_providers')->willReturn([$mockprovider]);

        $collector = new data_collector($this->mockscopebuilder, 1000, $this->mockfactory);
        $data = $collector->collect();

        $this->assertArrayHasKey('test_provider', $data);
        $this->assertEquals($testdata, $data['test_provider']);
    }

    /**
     * Test collect respects source filters - only calls collect on matching providers.
     *
     * @covers \report_ai_analysis\data_collector::collect
     */
    public function test_collect_respects_source_filters(): void {
        $this->mockscopebuilder->method('get_sources_in_scope')->willReturn(['cm_123']);

        // Provider that handles the source.
        $handlingprovider = $this->getMockBuilder(TestableProvider::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['collect', 'handles_source'])
            ->getMock();
        $handlingprovider->method('handles_source')->willReturn(true);
        $handlingprovider->method('collect')->willReturn([['data' => 'handled']]);

        // Provider that doesn't handle the source - collect should NOT be called.
        $nonhandlingprovider = $this->getMockBuilder(NonHandlingProvider::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['collect', 'handles_source'])
            ->getMock();
        $nonhandlingprovider->method('handles_source')->willReturn(false);
        $nonhandlingprovider->expects($this->never())->method('collect');

        $this->mockfactory->method('get_all_providers')
            ->willReturn([$handlingprovider, $nonhandlingprovider]);

        $collector = new data_collector($this->mockscopebuilder, 1000, $this->mockfactory);
        $data = $collector->collect();

        $this->assertNotEmpty($data);
    }

    /**
     * Test provider exceptions are caught and other providers continue.
     *
     * @covers \report_ai_analysis\data_collector::collect
     */
    public function test_collect_catches_provider_exceptions(): void {
        $this->mockscopebuilder->method('get_sources_in_scope')->willReturn([]);

        // Provider that throws.
        $throwingprovider = $this->getMockBuilder(TestableProvider::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['collect'])
            ->getMock();
        $throwingprovider->method('collect')->willThrowException(new \Exception('Provider failed'));

        // Provider that succeeds.
        $succeedingprovider = $this->getMockBuilder(SucceedingProvider::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['collect'])
            ->getMock();
        $succeedingprovider->method('collect')->willReturn([['data' => 'success']]);

        $this->mockfactory->method('get_all_providers')
            ->willReturn([$throwingprovider, $succeedingprovider]);

        $collector = new data_collector($this->mockscopebuilder, 1000, $this->mockfactory);
        $data = $collector->collect();

        // Should still have data from succeeding provider.
        $this->assertArrayHasKey('succeeding_provider', $data);
        $this->resetDebugging();
    }

    /**
     * Test get_statistics returns proper structure.
     *
     * @covers \report_ai_analysis\data_collector::get_statistics
     */
    public function test_get_statistics(): void {
        $course = $this->getDataGenerator()->create_course();
        $scopebuilder = new scope_builder($course->id);
        $collector = new data_collector($scopebuilder);

        $stats = $collector->get_statistics([]);

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_sources', $stats);
        $this->assertEquals(0, $stats['total_sources']);
    }
}

/**
 * Testable provider class for unit testing.
 */
class TestableProvider extends base_provider {
    /**
     * Get provider type.
     * @return string
     */
    public static function get_type(): string {
        return 'test_provider';
    }

    /**
     * Check if available.
     * @return bool
     */
    public static function is_available(): bool {
        return true;
    }

    /**
     * Get metadata.
     * @return array
     */
    public static function get_metadata(): array {
        return ['name' => 'Test Provider', 'type' => 'test_provider'];
    }

    /**
     * Collect data.
     * @return array
     */
    public function collect(): array {
        return [];
    }

    /**
     * Format for AI.
     * @param array $data
     * @return string
     */
    public static function format_for_ai(array $data): string {
        return 'Test formatted';
    }

    /**
     * Get statistics.
     * @param array $data
     * @return array
     */
    public static function get_statistics(array $data): array {
        return ['count' => count($data)];
    }

    /**
     * Check if this provider handles the given source.
     * @param string $sourceidentifier
     * @return bool
     */
    public function handles_source(string $sourceidentifier): bool {
        return true;
    }
}

/**
 * Non-handling provider for testing source filtering.
 */
class NonHandlingProvider extends TestableProvider {
    /**
     * Get provider type.
     * @return string
     */
    public static function get_type(): string {
        return 'non_handling_provider';
    }
}

/**
 * Succeeding provider for testing exception handling.
 */
class SucceedingProvider extends TestableProvider {
    /**
     * Get provider type.
     * @return string
     */
    public static function get_type(): string {
        return 'succeeding_provider';
    }
}
