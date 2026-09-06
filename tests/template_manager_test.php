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

namespace report_ai_analysis;

use core\di;

/**
 * Integration tests for template_manager class.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(template_manager::class)]
final class template_manager_test extends \advanced_testcase {
    /** @var template_manager */
    private template_manager $manager;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->manager = di::get(template_manager::class);
    }

    /**
     * Template lifecycle: listing, creating, updating, retrieving, deleting, moving, toggling and not-found.
     *
     * Enabled templates are listed in sort order while all-templates ignore the flag, saving creates then
     * updates a record with a stable creation time, deleting reorders the remainder, moving respects the
     * boundaries, toggling flips the enabled flag and an unknown ID raises an exception.
     */
    public function test_template_lifecycle(): void {
        global $DB;

        // Saving creates a template with sortorder 0, retrievable by ID.
        $data = (object)['title' => 'First', 'prompt' => 'P1', 'enabled' => 1];
        $id0 = $this->manager->save_template($data);
        $this->assertGreaterThan(0, $id0);
        $created = $this->manager->get_template($id0);
        $this->assertSame('First', $created->title);
        $this->assertEquals(0, $created->sortorder);

        // A disabled second and enabled third template extend the sort order.
        $disabledid = $this->manager->save_template((object)['title' => 'Disabled', 'prompt' => 'P2', 'enabled' => 0]);
        $id2 = $this->manager->save_template((object)['title' => 'Second', 'prompt' => 'P3', 'enabled' => 1]);
        $this->assertEquals(2, $DB->get_field('report_ai_analysis_templates', 'sortorder', ['id' => $id2]));

        // Enabled listing keeps order and hides the disabled template; all-templates ignores the flag.
        $enabled = array_values($this->manager->get_enabled_templates());
        $this->assertSame(['First', 'Second'], array_map(static fn($template) => $template->title, $enabled));
        $this->assertCount(3, $this->manager->get_all_templates());

        // Updating an existing template keeps its original creation time.
        $data->id = $id0;
        $data->title = 'Updated Title';
        $this->assertSame($id0, $this->manager->save_template($data));
        $updated = $this->manager->get_template($id0);
        $this->assertSame('Updated Title', $updated->title);
        $this->assertEquals($created->timecreated, $updated->timecreated);

        // Deleting reorders the remaining templates.
        $this->assertTrue($this->manager->delete_template($disabledid));
        $this->assertFalse($DB->record_exists('report_ai_analysis_templates', ['id' => $disabledid]));
        $this->assertEquals(1, $DB->get_field('report_ai_analysis_templates', 'sortorder', ['id' => $id2]));

        // Moving respects the sort-order boundaries.
        $this->assertTrue($this->manager->move_template($id2, 'up'));
        $this->assertEquals(0, $DB->get_field('report_ai_analysis_templates', 'sortorder', ['id' => $id2]));
        $this->assertFalse($this->manager->move_template($id2, 'up'));
        $this->assertFalse($this->manager->move_template($id0, 'down'));

        // Toggling flips the enabled flag.
        $this->assertFalse($this->manager->toggle_enabled($id0));
        $this->assertEquals(0, $DB->get_field('report_ai_analysis_templates', 'enabled', ['id' => $id0]));

        // An unknown template ID raises an exception.
        $this->expectException(\dml_exception::class);
        $this->manager->get_template(99999);
    }
}
