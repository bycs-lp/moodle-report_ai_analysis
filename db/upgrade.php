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
        // Preserve administrator capability overrides. Core updates capability definitions during upgrade.
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

    if ($oldversion < 2026090600) {
        $table = new xmldb_table('report_ai_analysis_reports');
        $fields = [
            new xmldb_field('runversion', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'status'),
            new xmldb_field('action', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'create', 'runversion'),
            new xmldb_field('resultformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '4', 'action'),
            new xmldb_field('truncated', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'resultformat'),
            new xmldb_field('legacydata', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'truncated'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        $table = new xmldb_table('report_ai_analysis_users');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('reportid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('source_data', XMLDB_TYPE_TEXT);
        $table->add_field('ai_result', XMLDB_TYPE_TEXT);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('reportid', XMLDB_KEY_FOREIGN, ['reportid'], 'report_ai_analysis_reports', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('reportid-userid', XMLDB_KEY_UNIQUE, ['reportid', 'userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Keep legacy content and incomplete attribution explicit; do not guess a result format or reset permissions.
        $lastid = 0;
        do {
            upgrade_set_timeout(300);
            $lastid = \report_ai_analysis\local\upgrade::migrate_legacy_reports($lastid);
        } while ($lastid !== 0);

        $lastid = 0;
        do {
            upgrade_set_timeout(300);
            $lastid = \report_ai_analysis\local\upgrade::remove_legacy_tasks($lastid);
        } while ($lastid !== 0);

        upgrade_plugin_savepoint(true, 2026090600, 'report', 'ai_analysis');
    }

    return true;
}
