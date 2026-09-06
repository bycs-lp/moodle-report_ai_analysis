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
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use report_ai_analysis\local\report_access;
use report_ai_analysis\local\report_manager;
use report_ai_analysis\output\view_page;

require_login();

// Get report ID.
$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$confirm = optional_param('confirm', 0, PARAM_INT);

// Load report.
global $DB, $OUTPUT;
$report = $DB->get_record('report_ai_analysis_reports', ['id' => $id], '*', MUST_EXIST);

// Get context (always course context).
$context = context::instance_by_id($report->contextid);
if ($context->contextlevel !== CONTEXT_COURSE) {
    throw new moodle_exception('error_contextmismatch', 'report_ai_analysis');
}
require_login($context->instanceid, false);

// Check permissions.
report_access::require_view($report);

// Handle delete action.
if ($action === 'delete') {
    require_sesskey();

    // Check delete permission.
    report_access::require_manage($report, 'report/ai_analysis:delete');

    if ($confirm) {
        report_manager::delete($id);
        redirect(
            new moodle_url('/report/ai_analysis/index.php', ['courseid' => $context->instanceid]),
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
        get_string('confirmdelete', 'report_ai_analysis', s($report->title)),
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

if ($report->status === 'completed' && !empty($report->ai_result)) {
    $PAGE->requires->js_call_amd('report_ai_analysis/ai_widgets', 'initWarning', [
        '[data-region="ai-result-warning"]',
        get_string('aiunavailable', 'report_ai_analysis'),
    ]);
}

echo $OUTPUT->header();
echo $output->render($page);
echo $OUTPUT->footer();
