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
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
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

    $dbman = $DB->get_manager();

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

    if ($oldversion < 2025102700) {
        // Define table report_ai_analysis_templates to be created.
        $table = new xmldb_table('report_ai_analysis_templates');

        // Adding fields to table report_ai_analysis_templates.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('prompt', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table report_ai_analysis_templates.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Adding indexes to table report_ai_analysis_templates.
        $table->add_index('sortorder', XMLDB_INDEX_NOTUNIQUE, ['sortorder']);
        $table->add_index('enabled', XMLDB_INDEX_NOTUNIQUE, ['enabled']);

        // Conditionally launch create table for report_ai_analysis_templates.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Ai_analysis savepoint reached.
        upgrade_plugin_savepoint(true, 2025102700, 'report', 'ai_analysis');
    }

    if ($oldversion < 2026090400) {
        $table = new xmldb_table('report_ai_analysis_reports');
        $field = new xmldb_field('error_details', XMLDB_TYPE_TEXT, null, null, null, null, null, 'error_message');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Existing error messages may contain technical details and must no longer be treated as user-facing descriptions.
        $DB->execute(
            'UPDATE {report_ai_analysis_reports}
                SET error_details = error_message,
                    error_message = NULL
              WHERE error_message IS NOT NULL'
        );

        upgrade_plugin_savepoint(true, 2026090400, 'report', 'ai_analysis');
    }

    return true;
}
