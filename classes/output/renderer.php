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
 * Renderer for report_ai_analysis.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis\output;

use plugin_renderer_base;

/**
 * Renderer class for report_ai_analysis.
 *
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends plugin_renderer_base {
    /**
     * Render the index page.
     *
     * @param index_page $page The index page
     * @return string HTML output
     */
    protected function render_index_page(index_page $page): string {
        $data = $page->export_for_template($this);
        return $this->render_from_template('report_ai_analysis/index', $data);
    }

    /**
     * Render the view page.
     *
     * @param view_page $page The view page
     * @return string HTML output
     */
    protected function render_view_page(view_page $page): string {
        $data = $page->export_for_template($this);
        return $this->render_from_template('report_ai_analysis/view', $data);
    }
}
