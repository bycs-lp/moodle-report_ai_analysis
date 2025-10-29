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
 * Output class for index page.
 *
 * @package    report_ai_analysis
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis\output;

use renderable;
use renderer_base;
use templatable;
use report_ai_analysis\table\reports_table;

/**
 * Index page output class.
 *
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class index_page implements renderable, templatable {
    /** @var int The course ID */
    private $courseid;

    /**
     * Constructor.
     *
     * @param int $courseid The course ID
     */
    public function __construct(int $courseid) {
        $this->courseid = $courseid;
    }

    /**
     * Export data for template.
     *
     * @param renderer_base $output Renderer
     * @return \stdClass Template data
     */
    public function export_for_template(renderer_base $output) {
        global $DB;

        $data = new \stdClass();
        $context = \context_course::instance($this->courseid);

        // Course information.
        $course = $DB->get_record('course', ['id' => $this->courseid], 'fullname', MUST_EXIST);
        $data->coursename = format_string($course->fullname);
        $data->courseid = $this->courseid;

        // Check if user can create new analyses.
        $data->cancreate = has_capability('report/ai_analysis:create', $context);

        // Create URL for new analysis.
        if ($data->cancreate) {
            $createurl = new \moodle_url('/report/ai_analysis/create.php', ['courseid' => $this->courseid]);
            $data->createurl = $createurl->out(false);
        }

        // Render reports table.
        $baseurl = new \moodle_url('/report/ai_analysis/index.php', ['id' => $this->courseid]);

        $table = new reports_table($context, $baseurl);
        ob_start();
        $table->out(25, false);
        $data->tablecontent = ob_get_clean();

        return $data;
    }
}
