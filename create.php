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
 * Create or edit AI analysis report.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use report_ai_analysis\form\create_form;
use report_ai_analysis\local\report_access;
use report_ai_analysis\local\report_manager;
use report_ai_analysis\output\ai_availability;

global $DB, $OUTPUT, $USER;

// Report ID is optional - if provided, we're editing.
$reportid = optional_param('reportid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);

$report = null;
$isedit = false;

if ($reportid) {
    // Edit mode.
    $isedit = true;
    $report = $DB->get_record('report_ai_analysis_reports', ['id' => $reportid], '*', MUST_EXIST);
    $context = context::instance_by_id($report->contextid, MUST_EXIST);
    if ($context->contextlevel !== CONTEXT_COURSE) {
        throw new moodle_exception('error_contextmismatch', 'report_ai_analysis');
    }
    $courseid = $context->instanceid;
} else {
    // Create mode - course ID is required.
    $courseid = required_param('courseid', PARAM_INT);
    $context = context_course::instance($courseid, MUST_EXIST);
}

require_login($courseid, false);
$PAGE->set_context($context);

if ($isedit) {
    report_access::require_manage($report, 'report/ai_analysis:create');
    if ($report->status === 'running') {
        throw new moodle_exception('cannoteditrunningreport', 'report_ai_analysis');
    }
} else {
    require_capability('report/ai_analysis:create', $context);
}

// Set up page.
$urlparams = ['courseid' => $courseid];
if ($isedit) {
    $urlparams['reportid'] = $reportid;
}
$url = new moodle_url('/report/ai_analysis/create.php', $urlparams);
$indexurl = new moodle_url('/report/ai_analysis/index.php', ['courseid' => $courseid]);

$PAGE->set_url($url);
$PAGE->set_pagelayout('report');

if ($isedit) {
    $PAGE->set_title(get_string('editreport', 'report_ai_analysis'));
    $PAGE->set_heading(get_string('editreport', 'report_ai_analysis'));
} else {
    $PAGE->set_title(get_string('createanalysis', 'report_ai_analysis'));
    $PAGE->set_heading(get_string('createanalysis', 'report_ai_analysis'));
}

// Check availability before asking for input; the worker must revalidate independently.
$availability = \core\di::get(ai_availability::class)->get_availability($context);
$mform = new create_form($url->out(false), [
    'id' => $courseid,
    'reportid' => $reportid,
    'availability' => $availability,
]);

// If editing, populate form with existing data.
if ($isedit && $report) {
    $mform->set_data(create_form::get_initial_data($report));
}

// Handle form submission.
if ($mform->is_cancelled()) {
    redirect($indexurl);
} else if ($data = $mform->get_data()) {
    $reportid = report_manager::save($data, $mform->get_scope($data), $reportid ?: null);
    $message = get_string($isedit ? 'reportupdatedandqueued' : 'analysisqueued', 'report_ai_analysis');
    redirect($indexurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
}

// Render page.
if ($availability['state'] !== 'hidden') {
    $PAGE->requires->js_call_amd('report_ai_analysis/ai_widgets', 'initForm', [
        '#report-ai-analysis-form-info',
        (int) $USER->id,
        get_string('aiunavailable', 'report_ai_analysis'),
    ]);
}
echo $OUTPUT->header();
if ($isedit && $report) {
    echo $OUTPUT->heading(get_string('editreport', 'report_ai_analysis') . ': ' . s($report->title));
} else {
    echo $OUTPUT->heading(get_string('createanalysis', 'report_ai_analysis'));
}
if ($availability['state'] !== 'hidden') {
    echo html_writer::start_div('mb-3', ['id' => 'report-ai-analysis-form-info']);
    echo html_writer::start_div('', ['data-region' => 'ai-info']);
    echo $OUTPUT->render_from_template('local_ai_manager/infobox', [
        'aiinfourl' => (new moodle_url('/local/ai_manager/ai_info.php', ['purposes' => ['singleprompt']]))->out(false),
    ]);
    echo html_writer::end_div();
    echo html_writer::div('', 'mt-2', ['data-region' => 'ai-quota']);
    echo html_writer::tag('p', get_string('dataprivacy_notice', 'report_ai_analysis'), ['class' => 'mt-2']);
    echo html_writer::end_div();
    if ($availability['state'] === 'disabled') {
        echo $OUTPUT->notification($availability['message'], \core\output\notification::NOTIFY_WARNING);
    }
}
$mform->display();
echo $OUTPUT->footer();
