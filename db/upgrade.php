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
 * Upgrade script for report_ai_analysis.
 *
 * @package    report_ai_analysis
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade function.
 *
 * @param int $oldversion The old version number
 * @return bool True on success
 */
function xmldb_report_ai_analysis_upgrade($oldversion) {
    global $DB;

    if ($oldversion < 2025102302) {
        // Reset role capabilities for updated capabilities.
        $capabilities = [
            'report/ai_analysis:viewcourse',
            'report/ai_analysis:viewactivity',
        ];

        foreach ($capabilities as $capability) {
            // Get the capability ID.
            $capid = $DB->get_field('capabilities', 'id', ['name' => $capability]);
            if ($capid) {
                // Reset to defaults by removing all overrides.
                $DB->delete_records('role_capabilities', ['capability' => $capability]);
            }
        }

        // Trigger update of default capabilities.
        update_capabilities('report_ai_analysis');

        upgrade_plugin_savepoint(true, 2025102302, 'report', 'ai_analysis');
    }

    return true;
}
