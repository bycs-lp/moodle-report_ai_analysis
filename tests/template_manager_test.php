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
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_ai_analysis\template_manager
 */
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
     * Test get_enabled_templates returns only enabled templates in order.
     */
    public function test_get_enabled_templates(): void {
        global $DB;

        $time = time();
        $DB->insert_record('report_ai_analysis_templates', (object)[
            'title' => 'First', 'prompt' => 'P1', 'sortorder' => 0, 'enabled' => 1,
            'timecreated' => $time, 'timemodified' => $time,
        ]);
        $DB->insert_record('report_ai_analysis_templates', (object)[
            'title' => 'Disabled', 'prompt' => 'P2', 'sortorder' => 1, 'enabled' => 0,
            'timecreated' => $time, 'timemodified' => $time,
        ]);
        $DB->insert_record('report_ai_analysis_templates', (object)[
            'title' => 'Second', 'prompt' => 'P3', 'sortorder' => 2, 'enabled' => 1,
            'timecreated' => $time, 'timemodified' => $time,
        ]);

        $templates = $this->manager->get_enabled_templates();

        $this->assertCount(2, $templates);
        $arr = array_values($templates);
        $this->assertEquals('First', $arr[0]->title);
        $this->assertEquals('Second', $arr[1]->title);
    }

    /**
     * Test get_all_templates returns all templates regardless of status.
     */
    public function test_get_all_templates(): void {
        global $DB;

        $time = time();
        for ($i = 0; $i < 3; $i++) {
            $DB->insert_record('report_ai_analysis_templates', (object)[
                'title' => "Template $i", 'prompt' => "Prompt $i", 'sortorder' => $i,
                'enabled' => $i % 2, 'timecreated' => $time, 'timemodified' => $time,
            ]);
        }

        $templates = $this->manager->get_all_templates();
        $this->assertCount(3, $templates);
    }

    /**
     * Test save_template creates new and updates existing templates.
     */
    public function test_save_template(): void {
        global $DB;

        // Create new template.
        $data = new \stdClass();
        $data->title = 'New Template';
        $data->prompt = 'New Prompt';
        $data->enabled = 1;
        $id = $this->manager->save_template($data);

        $this->assertGreaterThan(0, $id);
        $saved = $DB->get_record('report_ai_analysis_templates', ['id' => $id]);
        $this->assertEquals('New Template', $saved->title);
        $this->assertEquals(0, $saved->sortorder);

        // Create second template - should have sortorder 1.
        $data2 = new \stdClass();
        $data2->title = 'Second';
        $data2->prompt = 'Second Prompt';
        $data2->enabled = 1;
        $id2 = $this->manager->save_template($data2);
        $saved2 = $DB->get_record('report_ai_analysis_templates', ['id' => $id2]);
        $this->assertEquals(1, $saved2->sortorder);

        // Update existing template.
        $data->id = $id;
        $data->title = 'Updated Title';
        $originaltime = $saved->timecreated;
        $returnedid = $this->manager->save_template($data);

        $this->assertEquals($id, $returnedid);
        $updated = $DB->get_record('report_ai_analysis_templates', ['id' => $id]);
        $this->assertEquals('Updated Title', $updated->title);
        $this->assertEquals($originaltime, $updated->timecreated);
        $this->assertGreaterThanOrEqual($originaltime, $updated->timemodified);
    }

    /**
     * Test delete_template removes template and reorders remaining.
     */
    public function test_delete_template(): void {
        global $DB;

        $time = time();
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[$i] = $DB->insert_record('report_ai_analysis_templates', (object)[
                'title' => "Template $i", 'prompt' => "Prompt $i", 'sortorder' => $i,
                'enabled' => 1, 'timecreated' => $time, 'timemodified' => $time,
            ]);
        }

        // Delete middle template.
        $result = $this->manager->delete_template($ids[1]);

        $this->assertTrue($result);
        $this->assertFalse($DB->record_exists('report_ai_analysis_templates', ['id' => $ids[1]]));

        // Check sortorder was adjusted.
        $template2 = $DB->get_record('report_ai_analysis_templates', ['id' => $ids[2]]);
        $this->assertEquals(1, $template2->sortorder);
    }

    /**
     * Test move_template up and down.
     */
    public function test_move_template(): void {
        global $DB;

        $time = time();
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[$i] = $DB->insert_record('report_ai_analysis_templates', (object)[
                'title' => "Template $i", 'prompt' => "Prompt $i", 'sortorder' => $i,
                'enabled' => 1, 'timecreated' => $time, 'timemodified' => $time,
            ]);
        }

        // Move template 2 up.
        $result = $this->manager->move_template($ids[2], 'up');
        $this->assertTrue($result);

        $t1 = $DB->get_record('report_ai_analysis_templates', ['id' => $ids[1]]);
        $t2 = $DB->get_record('report_ai_analysis_templates', ['id' => $ids[2]]);
        $this->assertEquals(2, $t1->sortorder);
        $this->assertEquals(1, $t2->sortorder);

        // Move first template up should fail.
        $result = $this->manager->move_template($ids[0], 'up');
        $this->assertFalse($result);

        // Move last template down should fail.
        $result = $this->manager->move_template($ids[1], 'down');
        $this->assertFalse($result);
    }

    /**
     * Test toggle_enabled switches template status.
     */
    public function test_toggle_enabled(): void {
        global $DB;

        $time = time();
        $id = $DB->insert_record('report_ai_analysis_templates', (object)[
            'title' => 'Test', 'prompt' => 'Prompt', 'sortorder' => 0,
            'enabled' => 1, 'timecreated' => $time, 'timemodified' => $time,
        ]);

        // Toggle to disabled.
        $newstatus = $this->manager->toggle_enabled($id);
        $this->assertFalse($newstatus);
        $this->assertEquals(0, $DB->get_field('report_ai_analysis_templates', 'enabled', ['id' => $id]));

        // Toggle back to enabled.
        $newstatus = $this->manager->toggle_enabled($id);
        $this->assertTrue($newstatus);
        $this->assertEquals(1, $DB->get_field('report_ai_analysis_templates', 'enabled', ['id' => $id]));
    }

    /**
     * Test get_template throws on non-existent ID.
     */
    public function test_get_template_not_found(): void {
        $this->expectException(\dml_exception::class);
        $this->manager->get_template(99999);
    }
}
