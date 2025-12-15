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

/**
 * Tests for template_manager class.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_ai_analysis\template_manager
 */
final class template_manager_test extends \advanced_testcase {
    /**
     * Test getting enabled templates.
     */
    public function test_get_enabled_templates(): void {
        global $DB;
        $this->resetAfterTest();

        // Create test templates.
        $time = time();

        $template1 = new \stdClass();
        $template1->title = 'Template 1';
        $template1->prompt = 'This is template 1';
        $template1->sortorder = 0;
        $template1->enabled = 1;
        $template1->timecreated = $time;
        $template1->timemodified = $time;
        $template1->id = $DB->insert_record('report_ai_analysis_templates', $template1);

        $template2 = new \stdClass();
        $template2->title = 'Template 2';
        $template2->prompt = 'This is template 2';
        $template2->sortorder = 1;
        $template2->enabled = 0; // Disabled.
        $template2->timecreated = $time;
        $template2->timemodified = $time;
        $template2->id = $DB->insert_record('report_ai_analysis_templates', $template2);

        $template3 = new \stdClass();
        $template3->title = 'Template 3';
        $template3->prompt = 'This is template 3';
        $template3->sortorder = 2;
        $template3->enabled = 1;
        $template3->timecreated = $time;
        $template3->timemodified = $time;
        $template3->id = $DB->insert_record('report_ai_analysis_templates', $template3);

        // Get enabled templates.
        $templates = template_manager::get_enabled_templates();

        // Should only return enabled templates.
        $this->assertCount(2, $templates);

        // Check they are in correct order.
        $templatearray = array_values($templates);
        $this->assertEquals('Template 1', $templatearray[0]->title);
        $this->assertEquals('Template 3', $templatearray[1]->title);
    }

    /**
     * Test getting all templates.
     */
    public function test_get_all_templates(): void {
        global $DB;
        $this->resetAfterTest();

        $time = time();

        // Create templates with different enabled status.
        for ($i = 0; $i < 3; $i++) {
            $template = new \stdClass();
            $template->title = "Template $i";
            $template->prompt = "Prompt $i";
            $template->sortorder = $i;
            $template->enabled = $i % 2; // Alternate enabled/disabled.
            $template->timecreated = $time;
            $template->timemodified = $time;
            $DB->insert_record('report_ai_analysis_templates', $template);
        }

        $templates = template_manager::get_all_templates();
        $this->assertCount(3, $templates);
    }

    /**
     * Test getting a specific template.
     */
    public function test_get_template(): void {
        global $DB;
        $this->resetAfterTest();

        $time = time();

        $template = new \stdClass();
        $template->title = 'Test Template';
        $template->prompt = 'Test Prompt';
        $template->sortorder = 0;
        $template->enabled = 1;
        $template->timecreated = $time;
        $template->timemodified = $time;
        $id = $DB->insert_record('report_ai_analysis_templates', $template);

        $retrieved = template_manager::get_template($id);

        $this->assertEquals('Test Template', $retrieved->title);
        $this->assertEquals('Test Prompt', $retrieved->prompt);
    }

    /**
     * Test getting non-existent template throws exception.
     */
    public function test_get_template_not_found(): void {
        $this->resetAfterTest();

        $this->expectException(\dml_exception::class);
        template_manager::get_template(99999);
    }

    /**
     * Test saving a new template.
     */
    public function test_save_template_new(): void {
        global $DB;
        $this->resetAfterTest();

        $data = new \stdClass();
        $data->title = 'New Template';
        $data->prompt = 'New Prompt';
        $data->enabled = 1;

        $id = template_manager::save_template($data);

        $this->assertGreaterThan(0, $id);

        $saved = $DB->get_record('report_ai_analysis_templates', ['id' => $id]);
        $this->assertEquals('New Template', $saved->title);
        $this->assertEquals('New Prompt', $saved->prompt);
        $this->assertEquals(1, $saved->enabled);
        $this->assertEquals(0, $saved->sortorder); // First template.
        $this->assertGreaterThan(0, $saved->timecreated);
        $this->assertGreaterThan(0, $saved->timemodified);
    }

    /**
     * Test saving updates existing template.
     */
    public function test_save_template_update(): void {
        global $DB;
        $this->resetAfterTest();

        $time = time() - 100;

        // Create initial template.
        $template = new \stdClass();
        $template->title = 'Original Title';
        $template->prompt = 'Original Prompt';
        $template->sortorder = 0;
        $template->enabled = 1;
        $template->timecreated = $time;
        $template->timemodified = $time;
        $id = $DB->insert_record('report_ai_analysis_templates', $template);

        // Update template.
        $data = new \stdClass();
        $data->id = $id;
        $data->title = 'Updated Title';
        $data->prompt = 'Updated Prompt';
        $data->enabled = 0;

        $returnedid = template_manager::save_template($data);

        $this->assertEquals($id, $returnedid);

        $updated = $DB->get_record('report_ai_analysis_templates', ['id' => $id]);
        $this->assertEquals('Updated Title', $updated->title);
        $this->assertEquals('Updated Prompt', $updated->prompt);
        $this->assertEquals(0, $updated->enabled);
        $this->assertEquals($time, $updated->timecreated); // Unchanged.
        $this->assertGreaterThan($time, $updated->timemodified); // Updated.
    }

    /**
     * Test sortorder assignment for new templates.
     */
    public function test_save_template_sortorder(): void {
        global $DB;
        $this->resetAfterTest();

        // Create first template.
        $data1 = new \stdClass();
        $data1->title = 'First';
        $data1->prompt = 'First prompt';
        $data1->enabled = 1;
        $id1 = template_manager::save_template($data1);

        $template1 = $DB->get_record('report_ai_analysis_templates', ['id' => $id1]);
        $this->assertEquals(0, $template1->sortorder);

        // Create second template.
        $data2 = new \stdClass();
        $data2->title = 'Second';
        $data2->prompt = 'Second prompt';
        $data2->enabled = 1;
        $id2 = template_manager::save_template($data2);

        $template2 = $DB->get_record('report_ai_analysis_templates', ['id' => $id2]);
        $this->assertEquals(1, $template2->sortorder);

        // Create third template.
        $data3 = new \stdClass();
        $data3->title = 'Third';
        $data3->prompt = 'Third prompt';
        $data3->enabled = 1;
        $id3 = template_manager::save_template($data3);

        $template3 = $DB->get_record('report_ai_analysis_templates', ['id' => $id3]);
        $this->assertEquals(2, $template3->sortorder);
    }

    /**
     * Test deleting a template.
     */
    public function test_delete_template(): void {
        global $DB;
        $this->resetAfterTest();

        $time = time();

        // Create three templates.
        for ($i = 0; $i < 3; $i++) {
            $template = new \stdClass();
            $template->title = "Template $i";
            $template->prompt = "Prompt $i";
            $template->sortorder = $i;
            $template->enabled = 1;
            $template->timecreated = $time;
            $template->timemodified = $time;
            $ids[$i] = $DB->insert_record('report_ai_analysis_templates', $template);
        }

        // Delete middle template.
        $result = template_manager::delete_template($ids[1]);
        $this->assertTrue($result);

        // Check it's deleted.
        $exists = $DB->record_exists('report_ai_analysis_templates', ['id' => $ids[1]]);
        $this->assertFalse($exists);

        // Check sortorder was adjusted.
        $template0 = $DB->get_record('report_ai_analysis_templates', ['id' => $ids[0]]);
        $template2 = $DB->get_record('report_ai_analysis_templates', ['id' => $ids[2]]);

        $this->assertEquals(0, $template0->sortorder);
        $this->assertEquals(1, $template2->sortorder); // Was 2, now 1.
    }

    /**
     * Test moving template up.
     */
    public function test_move_template_up(): void {
        global $DB;
        $this->resetAfterTest();

        $time = time();

        // Create three templates.
        for ($i = 0; $i < 3; $i++) {
            $template = new \stdClass();
            $template->title = "Template $i";
            $template->prompt = "Prompt $i";
            $template->sortorder = $i;
            $template->enabled = 1;
            $template->timecreated = $time;
            $template->timemodified = $time;
            $ids[$i] = $DB->insert_record('report_ai_analysis_templates', $template);
        }

        // Move template 2 up (from position 2 to position 1).
        $result = template_manager::move_template($ids[2], 'up');
        $this->assertTrue($result);

        // Check new positions.
        $template0 = $DB->get_record('report_ai_analysis_templates', ['id' => $ids[0]]);
        $template1 = $DB->get_record('report_ai_analysis_templates', ['id' => $ids[1]]);
        $template2 = $DB->get_record('report_ai_analysis_templates', ['id' => $ids[2]]);

        $this->assertEquals(0, $template0->sortorder);
        $this->assertEquals(2, $template1->sortorder); // Swapped with template2.
        $this->assertEquals(1, $template2->sortorder); // Moved up.
    }

    /**
     * Test moving template down.
     */
    public function test_move_template_down(): void {
        global $DB;
        $this->resetAfterTest();

        $time = time();

        // Create three templates.
        for ($i = 0; $i < 3; $i++) {
            $template = new \stdClass();
            $template->title = "Template $i";
            $template->prompt = "Prompt $i";
            $template->sortorder = $i;
            $template->enabled = 1;
            $template->timecreated = $time;
            $template->timemodified = $time;
            $ids[$i] = $DB->insert_record('report_ai_analysis_templates', $template);
        }

        // Move template 0 down (from position 0 to position 1).
        $result = template_manager::move_template($ids[0], 'down');
        $this->assertTrue($result);

        // Check new positions.
        $template0 = $DB->get_record('report_ai_analysis_templates', ['id' => $ids[0]]);
        $template1 = $DB->get_record('report_ai_analysis_templates', ['id' => $ids[1]]);
        $template2 = $DB->get_record('report_ai_analysis_templates', ['id' => $ids[2]]);

        $this->assertEquals(1, $template0->sortorder); // Moved down.
        $this->assertEquals(0, $template1->sortorder); // Swapped with template0.
        $this->assertEquals(2, $template2->sortorder);
    }

    /**
     * Test moving first template up returns false.
     */
    public function test_move_template_up_at_top(): void {
        global $DB;
        $this->resetAfterTest();

        $time = time();

        $template = new \stdClass();
        $template->title = 'First Template';
        $template->prompt = 'First Prompt';
        $template->sortorder = 0;
        $template->enabled = 1;
        $template->timecreated = $time;
        $template->timemodified = $time;
        $id = $DB->insert_record('report_ai_analysis_templates', $template);

        // Try to move up when already at top.
        $result = template_manager::move_template($id, 'up');
        $this->assertFalse($result);

        // Check position unchanged.
        $unchanged = $DB->get_record('report_ai_analysis_templates', ['id' => $id]);
        $this->assertEquals(0, $unchanged->sortorder);
    }

    /**
     * Test moving last template down returns false.
     */
    public function test_move_template_down_at_bottom(): void {
        global $DB;
        $this->resetAfterTest();

        $time = time();

        // Create two templates.
        for ($i = 0; $i < 2; $i++) {
            $template = new \stdClass();
            $template->title = "Template $i";
            $template->prompt = "Prompt $i";
            $template->sortorder = $i;
            $template->enabled = 1;
            $template->timecreated = $time;
            $template->timemodified = $time;
            $ids[$i] = $DB->insert_record('report_ai_analysis_templates', $template);
        }

        // Try to move last template down.
        $result = template_manager::move_template($ids[1], 'down');
        $this->assertFalse($result);

        // Check position unchanged.
        $unchanged = $DB->get_record('report_ai_analysis_templates', ['id' => $ids[1]]);
        $this->assertEquals(1, $unchanged->sortorder);
    }

    /**
     * Test toggling template enabled status.
     */
    public function test_toggle_enabled(): void {
        global $DB;
        $this->resetAfterTest();

        $time = time();

        $template = new \stdClass();
        $template->title = 'Test Template';
        $template->prompt = 'Test Prompt';
        $template->sortorder = 0;
        $template->enabled = 1;
        $template->timecreated = $time;
        $template->timemodified = $time;
        $id = $DB->insert_record('report_ai_analysis_templates', $template);

        // Toggle to disabled.
        $newstatus = template_manager::toggle_enabled($id);
        $this->assertFalse($newstatus);

        $updated = $DB->get_record('report_ai_analysis_templates', ['id' => $id]);
        $this->assertEquals(0, $updated->enabled);

        // Toggle back to enabled.
        $newstatus = template_manager::toggle_enabled($id);
        $this->assertTrue($newstatus);

        $updated = $DB->get_record('report_ai_analysis_templates', ['id' => $id]);
        $this->assertEquals(1, $updated->enabled);
    }

    /**
     * Test empty database returns empty array.
     */
    public function test_get_enabled_templates_empty(): void {
        $this->resetAfterTest();

        $templates = template_manager::get_enabled_templates();
        $this->assertIsArray($templates);
        $this->assertEmpty($templates);
    }

    /**
     * Test all templates disabled returns empty array.
     */
    public function test_get_enabled_templates_all_disabled(): void {
        global $DB;
        $this->resetAfterTest();

        $time = time();

        // Create disabled templates.
        for ($i = 0; $i < 3; $i++) {
            $template = new \stdClass();
            $template->title = "Template $i";
            $template->prompt = "Prompt $i";
            $template->sortorder = $i;
            $template->enabled = 0; // All disabled.
            $template->timecreated = $time;
            $template->timemodified = $time;
            $DB->insert_record('report_ai_analysis_templates', $template);
        }

        $templates = template_manager::get_enabled_templates();
        $this->assertIsArray($templates);
        $this->assertEmpty($templates);
    }
}
