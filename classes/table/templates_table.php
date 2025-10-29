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

namespace report_ai_analysis\table;

use html_writer;
use moodle_url;
use pix_icon;
use table_sql;

/**
 * Table for displaying prompt templates.
 *
 * @package    report_ai_analysis
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class templates_table extends table_sql {
    /**
     * Constructor
     *
     * @param string $uniqueid Unique ID for the table
     */
    public function __construct($uniqueid) {
        parent::__construct($uniqueid);

        // Define columns.
        $columns = ['sortorder', 'title', 'prompt_preview', 'enabled', 'actions'];
        $headers = [
            get_string('sortorder', 'report_ai_analysis'),
            get_string('template_title', 'report_ai_analysis'),
            get_string('template_prompt', 'report_ai_analysis'),
            get_string('template_enabled', 'report_ai_analysis'),
            get_string('actions', 'core'),
        ];

        $this->define_columns($columns);
        $this->define_headers($headers);

        // Table settings.
        $this->collapsible(false);
        $this->sortable(false);
        $this->pageable(false);

        // Define SQL.
        $this->set_sql('*', '{report_ai_analysis_templates}', '1=1', []);
    }

    /**
     * Column for sort order
     *
     * @param object $row Table row
     * @return string
     */
    public function col_sortorder($row) {
        return $row->sortorder;
    }

    /**
     * Column for title
     *
     * @param object $row Table row
     * @return string
     */
    public function col_title($row) {
        return s($row->title);
    }

    /**
     * Column for prompt preview
     *
     * @param object $row Table row
     * @return string
     */
    public function col_prompt_preview($row) {
        $preview = s(substr($row->prompt, 0, 80));
        if (strlen($row->prompt) > 80) {
            $preview .= '...';
        }
        return $preview;
    }

    /**
     * Column for enabled status
     *
     * @param object $row Table row
     * @return string
     */
    public function col_enabled($row) {
        if ($row->enabled) {
            return html_writer::tag('span', get_string('yes'), ['class' => 'badge badge-success']);
        } else {
            return html_writer::tag('span', get_string('no'), ['class' => 'badge badge-secondary']);
        }
    }

    /**
     * Column for actions
     *
     * @param object $row Table row
     * @return string
     */
    public function col_actions($row) {
        global $OUTPUT;

        $actions = [];

        // Edit action.
        $editurl = new moodle_url('/report/ai_analysis/edit_template.php', ['id' => $row->id]);
        $editicon = new pix_icon('t/edit', get_string('edit'));
        $actions[] = html_writer::link($editurl, $OUTPUT->render($editicon));

        // Move up action.
        $moveupurl = new moodle_url('/report/ai_analysis/manage_templates.php', [
            'action' => 'move',
            'direction' => 'up',
            'id' => $row->id,
            'sesskey' => sesskey(),
        ]);
        $moveupicon = new pix_icon('t/up', get_string('moveup', 'core'));
        $actions[] = html_writer::link($moveupurl, $OUTPUT->render($moveupicon));

        // Move down action.
        $movedownurl = new moodle_url('/report/ai_analysis/manage_templates.php', [
            'action' => 'move',
            'direction' => 'down',
            'id' => $row->id,
            'sesskey' => sesskey(),
        ]);
        $movedownicon = new pix_icon('t/down', get_string('movedown', 'core'));
        $actions[] = html_writer::link($movedownurl, $OUTPUT->render($movedownicon));

        // Toggle enabled action.
        if ($row->enabled) {
            $toggleurl = new moodle_url('/report/ai_analysis/manage_templates.php', [
                'action' => 'toggle',
                'id' => $row->id,
                'sesskey' => sesskey(),
            ]);
            $toggleicon = new pix_icon('t/hide', get_string('disable'));
            $actions[] = html_writer::link($toggleurl, $OUTPUT->render($toggleicon));
        } else {
            $toggleurl = new moodle_url('/report/ai_analysis/manage_templates.php', [
                'action' => 'toggle',
                'id' => $row->id,
                'sesskey' => sesskey(),
            ]);
            $toggleicon = new pix_icon('t/show', get_string('enable'));
            $actions[] = html_writer::link($toggleurl, $OUTPUT->render($toggleicon));
        }

        // Delete action.
        $deleteurl = new moodle_url('/report/ai_analysis/manage_templates.php', [
            'action' => 'delete',
            'id' => $row->id,
            'sesskey' => sesskey(),
        ]);
        $deleteicon = new pix_icon('t/delete', get_string('delete'));
        $actions[] = html_writer::link($deleteurl, $OUTPUT->render($deleteicon));

        return implode(' ', $actions);
    }
}
