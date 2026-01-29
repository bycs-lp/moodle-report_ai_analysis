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
 * These are true unit tests that mock the database dependency,
 * allowing testing without a real database connection.
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
 * Tests the template_manager class in isolation by mocking
 * the database dependency.
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

        // Create mock database.
        $this->mockdb = $this->createMock(moodle_database::class);
    }

    /**
     * Test get_enabled_templates returns only enabled templates.
     *
     * @covers \report_ai_analysis\template_manager::get_enabled_templates
     */
    public function test_get_enabled_templates_returns_enabled_only(): void {
        // Prepare mock data.
        $template1 = (object)[
            'id' => 1,
            'title' => 'Template 1',
            'prompt' => 'Prompt 1',
            'enabled' => 1,
            'sortorder' => 0,
        ];
        $template2 = (object)[
            'id' => 2,
            'title' => 'Template 2',
            'prompt' => 'Prompt 2',
            'enabled' => 1,
            'sortorder' => 1,
        ];

        // Configure mock to return templates.
        $this->mockdb->expects($this->once())
            ->method('get_records')
            ->with(
                'report_ai_analysis_templates',
                ['enabled' => 1],
                'sortorder ASC'
            )
            ->willReturn([1 => $template1, 2 => $template2]);

        // Create template manager with mock DB.
        $manager = new template_manager($this->mockdb);

        // Execute.
        $templates = $manager->get_enabled_templates();

        // Assert.
        $this->assertCount(2, $templates);
        $this->assertEquals('Template 1', $templates[1]->title);
        $this->assertEquals('Template 2', $templates[2]->title);
    }

    /**
     * Test get_enabled_templates returns empty array when no templates.
     *
     * @covers \report_ai_analysis\template_manager::get_enabled_templates
     */
    public function test_get_enabled_templates_returns_empty_when_none(): void {
        // Configure mock to return empty array.
        $this->mockdb->expects($this->once())
            ->method('get_records')
            ->with(
                'report_ai_analysis_templates',
                ['enabled' => 1],
                'sortorder ASC'
            )
            ->willReturn([]);

        $manager = new template_manager($this->mockdb);
        $templates = $manager->get_enabled_templates();

        $this->assertEmpty($templates);
    }

    /**
     * Test get_all_templates returns all templates regardless of enabled status.
     *
     * @covers \report_ai_analysis\template_manager::get_all_templates
     */
    public function test_get_all_templates_returns_all(): void {
        $template1 = (object)['id' => 1, 'title' => 'Enabled', 'enabled' => 1, 'sortorder' => 0];
        $template2 = (object)['id' => 2, 'title' => 'Disabled', 'enabled' => 0, 'sortorder' => 1];

        $this->mockdb->expects($this->once())
            ->method('get_records')
            ->with('report_ai_analysis_templates', null, 'sortorder ASC')
            ->willReturn([1 => $template1, 2 => $template2]);

        $manager = new template_manager($this->mockdb);
        $templates = $manager->get_all_templates();

        $this->assertCount(2, $templates);
    }

    /**
     * Test get_template returns specific template.
     *
     * @covers \report_ai_analysis\template_manager::get_template
     */
    public function test_get_template_returns_template(): void {
        $template = (object)[
            'id' => 42,
            'title' => 'Test Template',
            'prompt' => 'Test Prompt',
            'enabled' => 1,
            'sortorder' => 0,
        ];

        $this->mockdb->expects($this->once())
            ->method('get_record')
            ->with('report_ai_analysis_templates', ['id' => 42], '*', MUST_EXIST)
            ->willReturn($template);

        $manager = new template_manager($this->mockdb);
        $result = $manager->get_template(42);

        $this->assertEquals('Test Template', $result->title);
        $this->assertEquals('Test Prompt', $result->prompt);
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

        // Mock get_field_sql for max sortorder.
        $this->mockdb->expects($this->once())
            ->method('get_field_sql')
            ->with("SELECT MAX(sortorder) FROM {report_ai_analysis_templates}")
            ->willReturn(5);

        // Mock insert_record.
        $this->mockdb->expects($this->once())
            ->method('insert_record')
            ->with(
                'report_ai_analysis_templates',
                $this->callback(function ($record) {
                    return $record->title === 'New Template'
                        && $record->sortorder === 6
                        && isset($record->timecreated)
                        && isset($record->timemodified);
                })
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
        $data->title = 'Updated Title';
        $data->prompt = 'Updated Prompt';
        $data->enabled = 0;

        $this->mockdb->expects($this->once())
            ->method('update_record')
            ->with(
                'report_ai_analysis_templates',
                $this->callback(function ($record) {
                    return $record->id === 42
                        && $record->title === 'Updated Title'
                        && isset($record->timemodified);
                })
            );

        $manager = new template_manager($this->mockdb);
        $id = $manager->save_template($data);

        $this->assertEquals(42, $id);
    }

    /**
     * Test toggle_enabled toggles from enabled to disabled.
     *
     * @covers \report_ai_analysis\template_manager::toggle_enabled
     */
    public function test_toggle_enabled_disables(): void {
        $template = (object)[
            'id' => 1,
            'title' => 'Template',
            'enabled' => 1,
        ];

        $this->mockdb->expects($this->once())
            ->method('get_record')
            ->with('report_ai_analysis_templates', ['id' => 1], '*', MUST_EXIST)
            ->willReturn($template);

        $this->mockdb->expects($this->once())
            ->method('set_field')
            ->with('report_ai_analysis_templates', 'enabled', 0, ['id' => 1]);

        $manager = new template_manager($this->mockdb);
        $newstatus = $manager->toggle_enabled(1);

        $this->assertFalse($newstatus);
    }

    /**
     * Test toggle_enabled toggles from disabled to enabled.
     *
     * @covers \report_ai_analysis\template_manager::toggle_enabled
     */
    public function test_toggle_enabled_enables(): void {
        $template = (object)[
            'id' => 1,
            'title' => 'Template',
            'enabled' => 0,
        ];

        $this->mockdb->expects($this->once())
            ->method('get_record')
            ->with('report_ai_analysis_templates', ['id' => 1], '*', MUST_EXIST)
            ->willReturn($template);

        $this->mockdb->expects($this->once())
            ->method('set_field')
            ->with('report_ai_analysis_templates', 'enabled', 1, ['id' => 1]);

        $manager = new template_manager($this->mockdb);
        $newstatus = $manager->toggle_enabled(1);

        $this->assertTrue($newstatus);
    }

    /**
     * Test constructor uses global $DB when no database provided.
     *
     * @covers \report_ai_analysis\template_manager::__construct
     */
    public function test_constructor_uses_global_db_when_not_provided(): void {
        global $DB;

        // This test verifies the default constructor behavior works.
        $manager = new template_manager();

        // Simply verify no exceptions are thrown.
        $this->assertInstanceOf(template_manager::class, $manager);
    }
}
