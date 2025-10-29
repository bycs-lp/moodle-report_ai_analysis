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
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();

// Get parameters.
$id = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

// Validate sesskey.
require_sesskey();

// Load report.
global $DB;
$report = $DB->get_record('report_ai_analysis_reports', ['id' => $id], '*', MUST_EXIST);

// Get context.
$context = context::instance_by_id($report->contextid);

// Check permissions.
require_capability('report/ai_analysis:create', $context);

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

// If not confirmed, show confirmation page.
if (!$confirm) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('rerunreport', 'report_ai_analysis'));

    $confirmurl = new moodle_url('/report/ai_analysis/rerun.php', [
        'id' => $id,
        'confirm' => 1,
        'sesskey' => sesskey(),
    ]);

    // Build cancel URL to index page based on context.
    $indexparams = [];
    if ($context->contextlevel === CONTEXT_COURSE) {
        $indexparams['id'] = $context->instanceid;
    } else if ($context->contextlevel === CONTEXT_MODULE) {
        $indexparams['cmid'] = $context->instanceid;
    } else if ($context->contextlevel === CONTEXT_COURSECAT) {
        $indexparams['categoryid'] = $context->instanceid;
    }
    $cancelurl = new moodle_url('/report/ai_analysis/index.php', $indexparams);

    echo $OUTPUT->confirm(
        get_string('rerunreportconfirm', 'report_ai_analysis', format_string($report->title)),
        $confirmurl,
        $cancelurl
    );

    echo $OUTPUT->footer();
    exit;
}

// Re-run the report by resetting status and scheduling task.
$transaction = $DB->start_delegated_transaction();

try {
    // Reset report status.
    $report->status = 'pending';
    $report->error_message = null;
    $report->ai_result = null;
    $report->raw_data = null;
    $report->timecompleted = null;
    $report->retry_count = 0;
    $report->timemodified = time();

    $DB->update_record('report_ai_analysis_reports', $report);

    // Schedule adhoc task to process the report.
    $task = new \report_ai_analysis\task\process_analysis_task();
    $task->set_custom_data([
        'reportid' => $report->id,
    ]);
    \core\task\manager::queue_adhoc_task($task);

    $transaction->allow_commit();

    // Build redirect URL to index page based on context.
    $indexparams = [];
    if ($context->contextlevel === CONTEXT_COURSE) {
        $indexparams['id'] = $context->instanceid;
    } else if ($context->contextlevel === CONTEXT_MODULE) {
        $indexparams['cmid'] = $context->instanceid;
    } else if ($context->contextlevel === CONTEXT_COURSECAT) {
        $indexparams['categoryid'] = $context->instanceid;
    }

    // Redirect to index page with success message.
    redirect(
        new moodle_url('/report/ai_analysis/index.php', $indexparams),
        get_string('reportrerunsuccess', 'report_ai_analysis'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
} catch (Exception $e) {
    $transaction->rollback($e);
    throw new moodle_exception('errorrerunningreport', 'report_ai_analysis', '', null, $e->getMessage());
}
