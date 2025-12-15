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
 * Manager for prompt templates.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis;

/**
 * Manager class for prompt templates.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_manager {
    /**
     * Get all enabled templates ordered by sortorder.
     *
     * @return array Array of template objects
     */
    public static function get_enabled_templates(): array {
        global $DB;

        return $DB->get_records(
            'report_ai_analysis_templates',
            ['enabled' => 1],
            'sortorder ASC'
        );
    }

    /**
     * Get all templates (enabled and disabled).
     *
     * @return array Array of template objects
     */
    public static function get_all_templates(): array {
        global $DB;

        return $DB->get_records('report_ai_analysis_templates', null, 'sortorder ASC');
    }

    /**
     * Get a specific template by ID.
     *
     * @param int $templateid Template ID
     * @return \stdClass Template object
     * @throws \dml_exception If template not found
     */
    public static function get_template(int $templateid): \stdClass {
        global $DB;

        return $DB->get_record(
            'report_ai_analysis_templates',
            ['id' => $templateid],
            '*',
            MUST_EXIST
        );
    }

    /**
     * Delete a template.
     *
     * @param int $templateid Template ID
     * @return bool Success status
     * @throws \dml_exception
     */
    public static function delete_template(int $templateid): bool {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        try {
            // Get sortorder of deleted template.
            $template = $DB->get_record(
                'report_ai_analysis_templates',
                ['id' => $templateid],
                'sortorder',
                MUST_EXIST
            );

            // Delete template.
            $DB->delete_records('report_ai_analysis_templates', ['id' => $templateid]);

            // Reorder remaining templates.
            $DB->execute(
                "UPDATE {report_ai_analysis_templates}
                    SET sortorder = sortorder - 1
                  WHERE sortorder > :sortorder",
                ['sortorder' => $template->sortorder]
            );

            $transaction->allow_commit();
            return true;
        } catch (\Exception $e) {
            $transaction->rollback($e);
            return false;
        }
    }

    /**
     * Move template up or down in sort order.
     *
     * @param int $templateid Template ID
     * @param string $direction 'up' or 'down'
     * @return bool Success status
     * @throws \dml_exception
     */
    public static function move_template(int $templateid, string $direction): bool {
        global $DB;

        $template = $DB->get_record(
            'report_ai_analysis_templates',
            ['id' => $templateid],
            '*',
            MUST_EXIST
        );

        $neworder = null;

        if ($direction === 'up' && $template->sortorder > 0) {
            $neworder = $template->sortorder - 1;
        } else if ($direction === 'down') {
            $maxorder = $DB->get_field_sql(
                "SELECT MAX(sortorder) FROM {report_ai_analysis_templates}"
            );
            if ($template->sortorder < $maxorder) {
                $neworder = $template->sortorder + 1;
            }
        }

        if ($neworder === null) {
            return false;
        }

        $transaction = $DB->start_delegated_transaction();

        try {
            // Swap with neighbor.
            $DB->execute(
                "UPDATE {report_ai_analysis_templates}
                    SET sortorder = :oldsort
                  WHERE sortorder = :newsort",
                ['oldsort' => $template->sortorder, 'newsort' => $neworder]
            );

            $DB->set_field(
                'report_ai_analysis_templates',
                'sortorder',
                $neworder,
                ['id' => $templateid]
            );

            $transaction->allow_commit();
            return true;
        } catch (\Exception $e) {
            $transaction->rollback($e);
            return false;
        }
    }

    /**
     * Create or update a template.
     *
     * @param \stdClass $data Template data
     * @return int Template ID
     * @throws \dml_exception
     */
    public static function save_template(\stdClass $data): int {
        global $DB;

        $data->timemodified = time();

        if (!empty($data->id)) {
            // Update existing template.
            $DB->update_record('report_ai_analysis_templates', $data);
            return $data->id;
        } else {
            // Create new template.
            $data->timecreated = time();

            // Set sortorder to last position.
            $maxorder = $DB->get_field_sql(
                "SELECT MAX(sortorder) FROM {report_ai_analysis_templates}"
            );
            $data->sortorder = $maxorder !== null ? $maxorder + 1 : 0;

            return $DB->insert_record('report_ai_analysis_templates', $data);
        }
    }

    /**
     * Toggle template enabled status.
     *
     * @param int $templateid Template ID
     * @return bool New enabled status
     * @throws \dml_exception
     */
    public static function toggle_enabled(int $templateid): bool {
        global $DB;

        $template = self::get_template($templateid);
        $newstatus = $template->enabled ? 0 : 1;

        $DB->set_field(
            'report_ai_analysis_templates',
            'enabled',
            $newstatus,
            ['id' => $templateid]
        );

        return (bool) $newstatus;
    }
}
