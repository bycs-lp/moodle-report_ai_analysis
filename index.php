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
 * Main page for AI analysis reports.
 *
 * @package    report_ai_analysis
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use report_ai_analysis\output\index_page;

// Course ID is required.
$courseid = required_param('courseid', PARAM_INT);

// Set up page context.
$context = context_course::instance($courseid, MUST_EXIST);
require_login($courseid, false);
$PAGE->set_context($context);

// Check capabilities.
require_capability('report/ai_analysis:view', $context);

// Set up page.
$url = new moodle_url('/report/ai_analysis/index.php', ['courseid' => $courseid]);

$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('pluginname', 'report_ai_analysis'));
$PAGE->set_heading(get_string('pluginname', 'report_ai_analysis'));

// Handle actions.
$action = optional_param('action', '', PARAM_ALPHA);
$reportid = optional_param('reportid', 0, PARAM_INT);

if ($action && $reportid && confirm_sesskey()) {
    global $DB;

    $report = $DB->get_record('report_ai_analysis_reports', ['id' => $reportid], '*', MUST_EXIST);

    // Verify report belongs to the course context.
    if ($report->contextid !== $context->id) {
        throw new moodle_exception('error_contextmismatch', 'report_ai_analysis');
    }

    // Verify user has permission to perform action on this report.
    $reportcontext = context::instance_by_id($report->contextid);

    switch ($action) {
        case 'delete':
            require_capability('report/ai_analysis:delete', $reportcontext);
            $DB->delete_records('report_ai_analysis_reports', ['id' => $reportid]);
            redirect($url, get_string('reportdeleted', 'report_ai_analysis'), null, \core\output\notification::NOTIFY_SUCCESS);
            break;

        case 'cancel':
            require_capability('report/ai_analysis:delete', $reportcontext);
            $DB->set_field('report_ai_analysis_reports', 'status', 'cancelled', ['id' => $reportid]);
            redirect($url, get_string('reportcancelled', 'report_ai_analysis'), null, \core\output\notification::NOTIFY_SUCCESS);
            break;
    }
}

// Render page.
$output = $PAGE->get_renderer('report_ai_analysis');
$page = new index_page($courseid);

echo $output->header();
echo $output->render($page);
echo $output->footer();
