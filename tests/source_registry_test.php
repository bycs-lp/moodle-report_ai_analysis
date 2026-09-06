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
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(source_registry::class)]
final class source_registry_test extends \advanced_testcase {
    /**
     * The registry exposes exactly the enabled source types, their whitelisted plugins and the reverse mapping.
     *
     * Blocks and the forum module are supported while AI placements and other activities are not, plugin
     * whitelisting accepts only the AI chat block and the forum, and each supported plugin maps back to its
     * source type while unknown types and plugins fail closed.
     */
    public function test_supported_types_plugins_and_mapping(): void {
        // Supported types are exactly the enabled block and forum sources.
        $this->assertEqualsCanonicalizing(['block', 'mod_forum'], source_registry::get_supported_types());
        $this->assertTrue(source_registry::is_supported('mod_forum'));
        $this->assertFalse(source_registry::is_supported('invalid_type'));

        // Only the whitelisted plugins are allowed and AI placements never are.
        $this->assertEqualsCanonicalizing(['block_ai_chat', 'mod_forum'], source_registry::get_all_allowed_plugins());
        $this->assertSame(['block_ai_chat'], source_registry::get_allowed_plugins('block'));
        $this->assertTrue(source_registry::is_plugin_allowed('mod_forum', 'mod_forum'));
        $this->assertFalse(source_registry::is_plugin_allowed('block', 'block_html'));
        $this->assertFalse(source_registry::is_plugin_allowed('aiplacement', 'aiplacement_courseassist'));
        $this->assertSame([], source_registry::get_allowed_plugins('invalid_type'));

        // Plugins map back to their source type; unknown plugins map to null.
        $this->assertSame('block', source_registry::get_source_type_for_plugin('block_ai_chat'));
        $this->assertSame('mod_forum', source_registry::get_source_type_for_plugin('mod_forum'));
        $this->assertNull(source_registry::get_source_type_for_plugin('aiplacement_courseassist'));
        $this->assertNull(source_registry::get_source_type_for_plugin('invalid_plugin'));
    }
}
