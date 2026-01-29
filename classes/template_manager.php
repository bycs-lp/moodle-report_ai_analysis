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

use moodle_database;

/**
 * Manager class for prompt templates.
 *
 * Refactored from static methods to instance methods for better testability
 * and Dependency Injection (DI) support.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_manager {
    /** @var moodle_database Database instance. */
    private moodle_database $db;

    /**
     * Constructor.
     *
     * @param moodle_database|null $db Optional database instance for testing.
     */
    public function __construct(?moodle_database $db = null) {
        global $DB;
        $this->db = $db ?? $DB;
    }

    /**
     * Get all enabled templates ordered by sortorder.
     *
     * @return array Array of template objects.
     */
    public function get_enabled_templates(): array {
        return $this->db->get_records(
            'report_ai_analysis_templates',
            ['enabled' => 1],
            'sortorder ASC'
        );
    }

    /**
     * Get all templates (enabled and disabled).
     *
     * @return array Array of template objects.
     */
    public function get_all_templates(): array {
        return $this->db->get_records('report_ai_analysis_templates', null, 'sortorder ASC');
    }

    /**
     * Get a specific template by ID.
     *
     * @param int $templateid Template ID.
     * @return \stdClass Template object.
     * @throws \dml_exception If template not found.
     */
    public function get_template(int $templateid): \stdClass {
        return $this->db->get_record(
            'report_ai_analysis_templates',
            ['id' => $templateid],
            '*',
            MUST_EXIST
        );
    }

    /**
     * Delete a template.
     *
     * @param int $templateid Template ID.
     * @return bool Success status.
     * @throws \dml_exception On database error.
     */
    public function delete_template(int $templateid): bool {
        $transaction = $this->db->start_delegated_transaction();

        try {
            // Get sortorder of deleted template.
            $template = $this->db->get_record(
                'report_ai_analysis_templates',
                ['id' => $templateid],
                'sortorder',
                MUST_EXIST
            );

            // Delete template.
            $this->db->delete_records('report_ai_analysis_templates', ['id' => $templateid]);

            // Reorder remaining templates.
            $this->db->execute(
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
     * @param int $templateid Template ID.
     * @param string $direction 'up' or 'down'.
     * @return bool Success status.
     * @throws \dml_exception On database error.
     */
    public function move_template(int $templateid, string $direction): bool {
        $template = $this->db->get_record(
            'report_ai_analysis_templates',
            ['id' => $templateid],
            '*',
            MUST_EXIST
        );

        $neworder = null;

        if ($direction === 'up' && $template->sortorder > 0) {
            $neworder = $template->sortorder - 1;
        } else if ($direction === 'down') {
            $maxorder = $this->db->get_field_sql(
                "SELECT MAX(sortorder) FROM {report_ai_analysis_templates}"
            );
            if ($template->sortorder < $maxorder) {
                $neworder = $template->sortorder + 1;
            }
        }

        if ($neworder === null) {
            return false;
        }

        $transaction = $this->db->start_delegated_transaction();

        try {
            // Swap with neighbor.
            $this->db->execute(
                "UPDATE {report_ai_analysis_templates}
                    SET sortorder = :oldsort
                  WHERE sortorder = :newsort",
                ['oldsort' => $template->sortorder, 'newsort' => $neworder]
            );

            $this->db->set_field(
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
     * @param \stdClass $data Template data.
     * @return int Template ID.
     * @throws \dml_exception On database error.
     */
    public function save_template(\stdClass $data): int {
        $data->timemodified = time();

        if (!empty($data->id)) {
            // Update existing template.
            $this->db->update_record('report_ai_analysis_templates', $data);
            return $data->id;
        } else {
            // Create new template.
            $data->timecreated = time();

            // Set sortorder to last position.
            $maxorder = $this->db->get_field_sql(
                "SELECT MAX(sortorder) FROM {report_ai_analysis_templates}"
            );
            $data->sortorder = $maxorder !== null ? $maxorder + 1 : 0;

            return $this->db->insert_record('report_ai_analysis_templates', $data);
        }
    }

    /**
     * Toggle template enabled status.
     *
     * @param int $templateid Template ID.
     * @return bool New enabled status.
     * @throws \dml_exception On database error.
     */
    public function toggle_enabled(int $templateid): bool {
        $template = $this->get_template($templateid);
        $newstatus = $template->enabled ? 0 : 1;

        $this->db->set_field(
            'report_ai_analysis_templates',
            'enabled',
            $newstatus,
            ['id' => $templateid]
        );

        return (bool) $newstatus;
    }
}
