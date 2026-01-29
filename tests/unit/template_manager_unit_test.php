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
 * Unit tests for template_manager with mocked database.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_mbs
 * @group      mebis
 */

namespace report_ai_analysis\unit;

use moodle_database;
use PHPUnit\Framework\MockObject\MockObject;
use report_ai_analysis\template_manager;

/**
 * Unit test class for template_manager with DI.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_ai_analysis\template_manager
 */
final class template_manager_unit_test extends \advanced_testcase {
    /** @var MockObject|moodle_database Mock database instance. */
    private MockObject $mockdb;

    /**
     * Set up before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->mockdb = $this->createMock(moodle_database::class);
    }

    /**
     * Test get_enabled_templates returns only enabled templates in correct order.
     *
     * @covers \report_ai_analysis\template_manager::get_enabled_templates
     */
    public function test_get_enabled_templates(): void {
        $template1 = (object)['id' => 1, 'title' => 'Template 1', 'enabled' => 1, 'sortorder' => 0];
        $template2 = (object)['id' => 2, 'title' => 'Template 2', 'enabled' => 1, 'sortorder' => 1];

        $this->mockdb->expects($this->once())
            ->method('get_records')
            ->with('report_ai_analysis_templates', ['enabled' => 1], 'sortorder ASC')
            ->willReturn([1 => $template1, 2 => $template2]);

        $manager = new template_manager($this->mockdb);
        $templates = $manager->get_enabled_templates();

        $this->assertCount(2, $templates);
        $this->assertEquals('Template 1', $templates[1]->title);
    }

    /**
     * Test get_all_templates returns all templates regardless of status.
     *
     * @covers \report_ai_analysis\template_manager::get_all_templates
     */
    public function test_get_all_templates(): void {
        $template1 = (object)['id' => 1, 'title' => 'Enabled', 'enabled' => 1];
        $template2 = (object)['id' => 2, 'title' => 'Disabled', 'enabled' => 0];

        $this->mockdb->expects($this->once())
            ->method('get_records')
            ->with('report_ai_analysis_templates', null, 'sortorder ASC')
            ->willReturn([1 => $template1, 2 => $template2]);

        $manager = new template_manager($this->mockdb);
        $templates = $manager->get_all_templates();

        $this->assertCount(2, $templates);
    }

    /**
     * Test get_template returns specific template by ID.
     *
     * @covers \report_ai_analysis\template_manager::get_template
     */
    public function test_get_template(): void {
        $template = (object)['id' => 42, 'title' => 'Test', 'prompt' => 'Prompt'];

        $this->mockdb->expects($this->once())
            ->method('get_record')
            ->with('report_ai_analysis_templates', ['id' => 42], '*', MUST_EXIST)
            ->willReturn($template);

        $manager = new template_manager($this->mockdb);
        $result = $manager->get_template(42);

        $this->assertEquals('Test', $result->title);
    }

    /**
     * Test save_template creates new template with correct sortorder.
     *
     * @covers \report_ai_analysis\template_manager::save_template
     */
    public function test_save_template_creates_new(): void {
        $data = new \stdClass();
        $data->title = 'New Template';
        $data->prompt = 'New Prompt';
        $data->enabled = 1;

        $this->mockdb->expects($this->once())
            ->method('get_field_sql')
            ->willReturn(5);

        $this->mockdb->expects($this->once())
            ->method('insert_record')
            ->with(
                'report_ai_analysis_templates',
                $this->callback(fn($rec) => $rec->title === 'New Template' && $rec->sortorder === 6)
            )
            ->willReturn(99);

        $manager = new template_manager($this->mockdb);
        $id = $manager->save_template($data);

        $this->assertEquals(99, $id);
    }

    /**
     * Test save_template updates existing template.
     *
     * @covers \report_ai_analysis\template_manager::save_template
     */
    public function test_save_template_updates_existing(): void {
        $data = new \stdClass();
        $data->id = 42;
        $data->title = 'Updated';
        $data->prompt = 'Updated Prompt';

        $this->mockdb->expects($this->once())
            ->method('update_record')
            ->with(
                'report_ai_analysis_templates',
                $this->callback(fn($rec) => $rec->id === 42 && $rec->title === 'Updated')
            );

        $manager = new template_manager($this->mockdb);
        $id = $manager->save_template($data);

        $this->assertEquals(42, $id);
    }

    /**
     * Test toggle_enabled toggles between enabled and disabled states.
     *
     * @covers \report_ai_analysis\template_manager::toggle_enabled
     */
    public function test_toggle_enabled(): void {
        // Test disabling.
        $enabledtemplate = (object)['id' => 1, 'enabled' => 1];
        $this->mockdb->method('get_record')->willReturn($enabledtemplate);
        $this->mockdb->expects($this->once())
            ->method('set_field')
            ->with('report_ai_analysis_templates', 'enabled', 0, ['id' => 1]);

        $manager = new template_manager($this->mockdb);
        $newstatus = $manager->toggle_enabled(1);

        $this->assertFalse($newstatus);
    }
}
