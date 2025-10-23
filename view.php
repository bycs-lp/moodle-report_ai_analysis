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
 * View AI analysis report.
 *
 * @package    report_ai_analysis
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use report_ai_analysis\output\view_page;

require_login();

// Get report ID.
$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$confirm = optional_param('confirm', 0, PARAM_INT);

// Load report.
global $DB;
$report = $DB->get_record('report_ai_analysis_reports', ['id' => $id], '*', MUST_EXIST);

// Get context (always course context).
$context = context::instance_by_id($report->contextid);
require_login($context->instanceid);

// Check permissions.
require_capability('report/ai_analysis:view', $context);

// Handle delete action.
if ($action === 'delete') {
    require_sesskey();

    // Check delete permission.
    require_capability('report/ai_analysis:delete', $context);

    if ($confirm) {
        // Delete the report.
        $DB->delete_records('report_ai_analysis_reports', ['id' => $id]);

        // Trigger event.
        $event = \report_ai_analysis\event\report_deleted::create([
            'context' => $context,
            'objectid' => $id,
            'other' => [
                'title' => $report->title,
            ],
        ]);
        $event->trigger();

        // Redirect back to index.
        $redirectparams = [];
        switch ($context->contextlevel) {
            case CONTEXT_COURSE:
                $redirectparams['id'] = $context->instanceid;
                break;
            case CONTEXT_MODULE:
                $redirectparams['cmid'] = $context->instanceid;
                break;
            case CONTEXT_COURSECAT:
                $redirectparams['categoryid'] = $context->instanceid;
                break;
        }

        redirect(
            new moodle_url('/report/ai_analysis/index.php', $redirectparams),
            get_string('reportdeleted', 'report_ai_analysis'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    // Show confirmation page.
    $url = new moodle_url('/report/ai_analysis/view.php', ['id' => $id]);
    $PAGE->set_url($url);
    $PAGE->set_context($context);
    $PAGE->set_pagelayout('report');
    $PAGE->set_title(get_string('deletereport', 'report_ai_analysis'));
    $PAGE->set_heading(get_string('deletereport', 'report_ai_analysis'));

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('deletereport', 'report_ai_analysis'));

    $confirmurl = new moodle_url('/report/ai_analysis/view.php', [
        'id' => $id,
        'action' => 'delete',
        'confirm' => 1,
        'sesskey' => sesskey(),
    ]);
    $cancelurl = new moodle_url('/report/ai_analysis/view.php', ['id' => $id]);

    echo $OUTPUT->confirm(
        get_string('confirmdelete', 'report_ai_analysis', $report->title),
        $confirmurl,
        $cancelurl
    );

    echo $OUTPUT->footer();
    exit;
}

// Set up page.
$url = new moodle_url('/report/ai_analysis/view.php', ['id' => $id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_title($report->title);
$PAGE->set_heading($report->title);

// Render page.
$output = $PAGE->get_renderer('report_ai_analysis');
$page = new view_page($report, $context);

echo $output->header();
echo $output->render($page);
echo $output->footer();
