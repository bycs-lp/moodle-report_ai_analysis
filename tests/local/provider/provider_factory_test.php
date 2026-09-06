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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use report_ai_analysis\scope_builder;
use report_ai_analysis\source_registry;

/**
 * Provider discovery, namespace and instance-budget tests.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(provider_factory::class)]
#[Group('local_mbs')]
#[Group('mebis')]
final class provider_factory_test extends \advanced_testcase {
    /**
     * Reset discovery state between tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Discovery, instance reuse, budget isolation, course-bound source matching and availability recheck.
     *
     * Discovery finds only supported concrete local providers and caches the class list, identical scopes and
     * budgets reuse instances while a changed budget cannot inherit stale limits, source matching is course-bound
     * and clearing releases instances, and a cached class list is never frozen: an unavailable module disappears.
     */
    public function test_discovery_instances_budgets_and_sources(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $scope = new scope_builder($course->id);

        // Discovery finds only supported concrete providers registered in the source registry.
        $factory = new provider_factory();
        $factory->clear_cache();
        $providers = $factory->discover_providers();
        $this->assertContains(mod_forum_provider::class, $providers);
        $this->assertNotContains(base_provider::class, $providers);
        $this->assertNotContains(provider_factory::class, $providers);
        foreach ($providers as $classname) {
            $this->assertTrue(is_subclass_of($classname, base_provider::class));
            $this->assertNotNull(source_registry::get_source_type_for_plugin($classname::get_type()));
        }

        // The MUC key stores the class list on a miss.
        $miss = $this->createMock(cache::class);
        $miss->expects($this->once())->method('get')->with('local_provider_classes')->willReturn(false);
        $miss->expects($this->once())->method('set')->with('local_provider_classes', $this->isType('array'));
        $this->assertContains(mod_forum_provider::class, (new provider_factory($miss))->discover_providers());

        // Identical scopes and budgets reuse instances, but a changed budget cannot inherit stale limits.
        $budgetcache = $this->createMock(cache::class);
        $budgetcache->method('get')->willReturn([mod_forum_provider::class]);
        $budgetfactory = new provider_factory($budgetcache);
        $first = $budgetfactory->get_all_providers($scope, 1);
        $this->assertInstanceOf(base_provider::class, $first[0]);
        $this->assertSame($first[0], $budgetfactory->get_all_providers($scope, 1)[0]);
        $this->assertNotSame($first[0], $budgetfactory->get_all_providers($scope, 2)[0]);

        // Source matching is course-bound, not merely prefix-based, and clearing releases instances.
        $othercourse = $generator->create_course();
        $forum = $generator->create_module('forum', ['course' => $course->id]);
        $foreign = $generator->create_module('forum', ['course' => $othercourse->id]);
        $sourcefactory = new provider_factory();
        $this->assertInstanceOf(
            mod_forum_provider::class,
            $sourcefactory->get_provider_for_source('cm_' . $forum->cmid, $scope)
        );
        $this->assertNull($sourcefactory->get_provider_for_source('cm_' . $foreign->cmid, $scope));
        $this->assertNull($sourcefactory->get_provider_for_source('unknown_999', $scope));
        $managementcache = $this->createMock(cache::class);
        $managementcache->expects($this->once())->method('purge');
        $managementcache->method('get')->willReturn([mod_forum_provider::class]);
        $managementfactory = new provider_factory($managementcache);
        $instance = $managementfactory->get_all_providers($scope)[0];
        $managementfactory->clear_cache();
        $this->assertNotSame($instance, $managementfactory->get_all_providers($scope)[0]);

        // Availability is rechecked and not frozen: an unavailable module disappears from a cached result.
        $recheckcache = $this->createMock(cache::class);
        $recheckcache->method('get')->willReturn([mod_forum_provider::class]);
        $recheckfactory = new provider_factory($recheckcache);
        $this->assertSame([mod_forum_provider::class], $recheckfactory->discover_providers());
        $DB->set_field('modules', 'visible', 0, ['name' => 'forum']);
        \core_plugin_manager::reset_caches();
        $this->assertSame([], $recheckfactory->discover_providers());
    }
}
