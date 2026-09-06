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
 * Re-run a failed or completed AI analysis report.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use report_ai_analysis\local\report_access;
use report_ai_analysis\local\report_manager;
use report_ai_analysis\output\ai_availability;

require_login();

// Get parameters.
$id = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

// Validate sesskey.
require_sesskey();

// Load report.
global $DB, $OUTPUT;
$report = $DB->get_record('report_ai_analysis_reports', ['id' => $id], '*', MUST_EXIST);

// Get context.
$context = context::instance_by_id($report->contextid);
if ($context->contextlevel !== CONTEXT_COURSE) {
    throw new moodle_exception('error_contextmismatch', 'report_ai_analysis');
}
require_login($context->instanceid, false);

// Check permissions.
report_access::require_manage($report, 'report/ai_analysis:rerun');

// Set up page.
$PAGE->set_url('/report/ai_analysis/rerun.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('rerunreport', 'report_ai_analysis'));
$PAGE->set_heading($context->get_context_name());

// Check if report can be re-run.
if ($report->status !== 'completed' && $report->status !== 'failed' && $report->status !== 'cancelled') {
    throw new moodle_exception('cannotrerunreport', 'report_ai_analysis');
}

$indexurl = new moodle_url('/report/ai_analysis/index.php', ['courseid' => $context->instanceid]);
$availability = \core\di::get(ai_availability::class)->get_availability($context);
if ($availability['state'] !== 'available') {
    echo $OUTPUT->header();
    if ($availability['state'] === 'disabled') {
        echo $OUTPUT->notification($availability['message'], \core\output\notification::NOTIFY_WARNING);
    }
    echo $OUTPUT->single_button($indexurl, get_string('back'), 'get');
    echo $OUTPUT->footer();
    exit;
}

// If not confirmed, show confirmation page.
if (!$confirm) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('rerunreport', 'report_ai_analysis'));

    $confirmurl = new moodle_url('/report/ai_analysis/rerun.php', [
        'id' => $id,
        'confirm' => 1,
        'sesskey' => sesskey(),
    ]);

    echo $OUTPUT->confirm(
        get_string('rerunreportconfirm', 'report_ai_analysis', s($report->title)),
        $confirmurl,
        $indexurl
    );

    echo $OUTPUT->footer();
    exit;
}

report_manager::rerun($id);
redirect(
    $indexurl,
    get_string('reportrerunsuccess', 'report_ai_analysis'),
    null,
    \core\output\notification::NOTIFY_SUCCESS
);
