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
 * Tests for source_registry class.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_ai_analysis\source_registry
 */
final class source_registry_test extends \advanced_testcase {
    /**
     * Test supported source types.
     */
    public function test_supported_types(): void {
        $types = source_registry::get_supported_types();

        $this->assertContains('aiplacement', $types);
        $this->assertContains('block', $types);
        $this->assertNotContains('mod_forum', $types); // Not yet enabled.
        $this->assertNotContains('mod_quiz', $types); // Not yet enabled.
    }

    /**
     * Test plugin whitelist validation for AI placements.
     */
    public function test_plugin_allowed_aiplacement(): void {
        $this->assertTrue(source_registry::is_plugin_allowed('aiplacement', 'aiplacement_courseassist'));
        $this->assertTrue(source_registry::is_plugin_allowed('aiplacement', 'aiplacement_debughelper'));
        $this->assertTrue(source_registry::is_plugin_allowed('aiplacement', 'tiny_aiplacement_polite'));

        $this->assertFalse(source_registry::is_plugin_allowed('aiplacement', 'block_ai_chat'));
        $this->assertFalse(source_registry::is_plugin_allowed('aiplacement', 'mod_forum'));
    }

    /**
     * Test plugin whitelist validation for blocks.
     */
    public function test_plugin_allowed_block(): void {
        $this->assertTrue(source_registry::is_plugin_allowed('block', 'block_ai_chat'));

        $this->assertFalse(source_registry::is_plugin_allowed('block', 'block_html'));
        $this->assertFalse(source_registry::is_plugin_allowed('block', 'aiplacement_courseassist'));
    }

    /**
     * Test disabled source types.
     */
    public function test_disabled_sources(): void {
        $this->assertFalse(source_registry::is_supported('mod_forum'));
        $this->assertFalse(source_registry::is_plugin_allowed('mod_forum', 'mod_forum'));
        $this->assertEmpty(source_registry::get_allowed_plugins('mod_forum'));
    }

    /**
     * Test getting all allowed plugins.
     */
    public function test_get_all_allowed_plugins(): void {
        $plugins = source_registry::get_all_allowed_plugins();

        $this->assertContains('aiplacement_courseassist', $plugins);
        $this->assertContains('block_ai_chat', $plugins);
        $this->assertCount(5, $plugins); // 4 aiplacements + 1 block.
    }

    /**
     * Test finding source type for plugin.
     */
    public function test_get_source_type_for_plugin(): void {
        $this->assertEquals('aiplacement', source_registry::get_source_type_for_plugin('aiplacement_courseassist'));
        $this->assertEquals('block', source_registry::get_source_type_for_plugin('block_ai_chat'));
        $this->assertNull(source_registry::get_source_type_for_plugin('mod_forum'));
        $this->assertNull(source_registry::get_source_type_for_plugin('invalid_plugin'));
    }

    /**
     * Test unsupported source type.
     */
    public function test_unsupported_source(): void {
        $this->assertFalse(source_registry::is_supported('invalid_type'));
        $this->assertEmpty(source_registry::get_allowed_plugins('invalid_type'));
    }

    /**
     * Test get allowed plugins for aiplacement.
     */
    public function test_get_allowed_plugins_aiplacement(): void {
        $plugins = source_registry::get_allowed_plugins('aiplacement');

        $this->assertCount(4, $plugins);
        $this->assertContains('aiplacement_courseassist', $plugins);
        $this->assertContains('aiplacement_debughelper', $plugins);
        $this->assertContains('aiplacement_htmlblock', $plugins);
        $this->assertContains('tiny_aiplacement_polite', $plugins);
    }

    /**
     * Test get allowed plugins for block.
     */
    public function test_get_allowed_plugins_block(): void {
        $plugins = source_registry::get_allowed_plugins('block');

        $this->assertCount(1, $plugins);
        $this->assertContains('block_ai_chat', $plugins);
    }
}
