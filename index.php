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
$courseid = optional_param('courseid', 0, PARAM_INT);
if (!$courseid) {
    $courseid = required_param('id', PARAM_INT);
}

// Set up page context.
$context = context_course::instance($courseid, MUST_EXIST);
require_login($courseid, false);
$PAGE->set_context($context);

// Check capabilities.
require_capability('report/ai_analysis:view', $context);

// Set up page.
$url = new moodle_url('/report/ai_analysis/index.php', ['id' => $courseid]);

$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('pluginname', 'report_ai_analysis'));
$PAGE->set_heading(get_string('pluginname', 'report_ai_analysis'));

// Handle actions.
$action = optional_param('action', '', PARAM_ALPHA);
$reportid = optional_param('reportid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

if ($action && $reportid) {
    global $DB;

    $report = $DB->get_record('report_ai_analysis_reports', ['id' => $reportid], '*', MUST_EXIST);

    // Get report context.
    $reportcontext = context::instance_by_id($report->contextid);

    // Verify report belongs to accessible context - course context or parent.
    // Reports are always in course context, check if it matches current course.
    if ($reportcontext->contextlevel == CONTEXT_COURSE && $reportcontext->instanceid != $courseid) {
        throw new moodle_exception('error_contextmismatch', 'report_ai_analysis');
    }

    // Verify user has permission to perform action on this report.

    if ($action === 'delete') {
        require_capability('report/ai_analysis:delete', $reportcontext);

        if ($confirm && confirm_sesskey()) {
            // Actually delete the report.
            $DB->delete_records('report_ai_analysis_reports', ['id' => $reportid]);

            // Trigger event.
            $event = \report_ai_analysis\event\report_deleted::create([
                'context' => $reportcontext,
                'objectid' => $reportid,
                'other' => [
                    'title' => $report->title,
                ],
            ]);
            $event->trigger();

            $redirecturl = new moodle_url('/report/ai_analysis/index.php', ['id' => $courseid]);
            $message = get_string('reportdeleted', 'report_ai_analysis');
            redirect($redirecturl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
        } else {
            // Show confirmation page.
            $PAGE->set_title(get_string('deletereport', 'report_ai_analysis'));
            $PAGE->set_heading(get_string('deletereport', 'report_ai_analysis'));

            echo $OUTPUT->header();
            echo $OUTPUT->heading(get_string('deletereport', 'report_ai_analysis'));

            $confirmurl = new moodle_url('/report/ai_analysis/index.php', [
                'id' => $courseid,
                'action' => 'delete',
                'reportid' => $reportid,
                'confirm' => 1,
                'sesskey' => sesskey(),
            ]);
            $cancelurl = new moodle_url('/report/ai_analysis/index.php', ['id' => $courseid]);

            echo $OUTPUT->confirm(
                get_string('confirmdelete', 'report_ai_analysis', s($report->title)),
                $confirmurl,
                $cancelurl
            );

            echo $OUTPUT->footer();
            exit;
        }
    } else if ($action === 'cancel' && confirm_sesskey()) {
        require_capability('report/ai_analysis:delete', $reportcontext);
        $DB->set_field('report_ai_analysis_reports', 'status', 'cancelled', ['id' => $reportid]);
        redirect($url, get_string('reportcancelled', 'report_ai_analysis'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// Render page.
$output = $PAGE->get_renderer('report_ai_analysis');
$page = new index_page($courseid);

echo $output->header();
echo $output->render($page);
echo $output->footer();
