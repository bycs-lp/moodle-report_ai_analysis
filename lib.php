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
 * Library functions for report_ai_analysis.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add report link to course navigation.
 *
 * @param navigation_node $navigation The navigation node to extend
 * @param stdClass $course The course object
 * @param context $context The course context
 */
function report_ai_analysis_extend_navigation_course($navigation, $course, $context) {
    if (has_capability('report/ai_analysis:view', $context)) {
        $url = new moodle_url('/report/ai_analysis/index.php', ['courseid' => $course->id]);
        $navigation->add(
            get_string('pluginname', 'report_ai_analysis'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            null,
            new pix_icon('i/report', '')
        );
    }
}

/**
 * Get all enabled templates.
 *
 * @return array Array of template objects
 */
function report_ai_analysis_get_enabled_templates(): array {
    global $DB;
    return $DB->get_records('report_ai_analysis_templates', ['enabled' => 1], 'sortorder ASC');
}

/**
 * Get a specific template by ID.
 *
 * @param int $templateid Template ID
 * @return stdClass Template object
 * @throws dml_exception If template not found
 */
function report_ai_analysis_get_template(int $templateid): stdClass {
    global $DB;
    return $DB->get_record('report_ai_analysis_templates', ['id' => $templateid], '*', MUST_EXIST);
}

/**
 * Delete a template and reorder remaining ones.
 *
 * @param int $templateid Template ID
 * @return bool Success status
 */
function report_ai_analysis_delete_template(int $templateid): bool {
    global $DB;
    $transaction = $DB->start_delegated_transaction();

    try {
        $template = $DB->get_record('report_ai_analysis_templates', ['id' => $templateid], 'sortorder', MUST_EXIST);
        $DB->delete_records('report_ai_analysis_templates', ['id' => $templateid]);
        $DB->execute(
            "UPDATE {report_ai_analysis_templates} SET sortorder = sortorder - 1 WHERE sortorder > :sortorder",
            ['sortorder' => $template->sortorder]
        );
        $transaction->allow_commit();
        return true;
    } catch (Exception $e) {
        $transaction->rollback($e);
        return false;
    }
}

/**
 * Save (create or update) a template.
 *
 * @param stdClass $data Template data
 * @return int Template ID
 */
function report_ai_analysis_save_template(stdClass $data): int {
    global $DB;
    $data->timemodified = time();

    if (!empty($data->id)) {
        $DB->update_record('report_ai_analysis_templates', $data);
        return $data->id;
    }

    $data->timecreated = time();
    $maxorder = $DB->get_field_sql("SELECT MAX(sortorder) FROM {report_ai_analysis_templates}");
    $data->sortorder = $maxorder !== null ? $maxorder + 1 : 0;
    return $DB->insert_record('report_ai_analysis_templates', $data);
}
