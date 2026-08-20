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
use report_ai_analysis\scope_builder;

global $DB;

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
    $courseid = $context->instanceid;

    // Only allow editing if report is not running.
    if (in_array($report->status, ['running'])) {
        throw new moodle_exception('cannoteditrunningreport', 'report_ai_analysis');
    }
} else {
    // Create mode - course ID is required.
    $courseid = required_param('courseid', PARAM_INT);
    $context = context_course::instance($courseid, MUST_EXIST);
}

require_login($courseid, false);
$PAGE->set_context($context);

// Require create capability.
require_capability('report/ai_analysis:create', $context);

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

// Create form.
$mform = new create_form($url->out(false), ['id' => $courseid]);

// If editing, populate form with existing data.
if ($isedit && $report) {
    $scopedata = new stdClass();
    $scopedata->title = $report->title;
    $scopedata->prompt = $report->prompt;

    if (!empty($report->scope_details)) {
        $scope = scope_builder::parse($report->scope_details);

        // Analysis mode.
        if (isset($scope->analysis_mode)) {
            $scopedata->analysis_mode = $scope->analysis_mode;
        }

        // Sources.
        if (isset($scope->filters->sources)) {
            $scopedata->sources = $scope->filters->sources;
        }

        // Participants.
        if (isset($scope->filters->participants)) {
            $scopedata->participants = $scope->filters->participants;
            $scopedata->all_participants = 0;

            // Roles.
            if (isset($scope->filters->roleids)) {
                $scopedata->roleids = $scope->filters->roleids;
            }
        } else if (isset($scope->filters->students)) {
            // Legacy support.
            $scopedata->students = $scope->filters->students;
            $scopedata->all_students = 0;
        } else {
            $scopedata->all_participants = 1;
        }

        // Groups.
        if (isset($scope->filters->groups)) {
            $scopedata->groups = $scope->filters->groups;
        }

        // Time range.
        if (isset($scope->filters->timerange)) {
            // For optional date_time_selector fields with timestamp values.
            // We need to set the full array including hour, minute, and enable the field.
            $calendartype = \core_calendar\type_factory::get_calendar_instance();

            if (!empty($scope->filters->timerange->start)) {
                $startdate = $calendartype->timestamp_to_date_array($scope->filters->timerange->start, 99);
                $scopedata->timestart = [
                    'day' => $startdate['mday'],
                    'month' => $startdate['mon'],
                    'year' => $startdate['year'],
                    'hour' => $startdate['hours'],
                    'minute' => $startdate['minutes'],
                    'enabled' => 1,
                ];
            }

            if (!empty($scope->filters->timerange->end)) {
                $enddate = $calendartype->timestamp_to_date_array($scope->filters->timerange->end, 99);
                $scopedata->timeend = [
                    'day' => $enddate['mday'],
                    'month' => $enddate['mon'],
                    'year' => $enddate['year'],
                    'hour' => $enddate['hours'],
                    'minute' => $enddate['minutes'],
                    'enabled' => 1,
                ];
            }
        }
    }

    $mform->set_data($scopedata);
}

// Handle form submission.
if ($mform->is_cancelled()) {
    redirect($indexurl);
} else if ($data = $mform->get_data()) {
    global $USER;

    // Build scope.
    $scopebuilder = new scope_builder($courseid);

    // Set analysis mode.
    if (!empty($data->analysis_mode)) {
        $scopebuilder->set_analysis_mode($data->analysis_mode);
    }

    if (!empty($data->sources)) {
        $scopebuilder->with_sources($data->sources);
    }

    // Handle participant filter (with role support).
    if (empty($data->all_participants) && !empty($data->participants)) {
        $roleids = !empty($data->roleids) ? $data->roleids : [];
        $scopebuilder->filter_by_participants($data->participants, $roleids);
    } else if (!empty($data->roleids)) {
        // Only role filter - get all users with these roles.
        $participantids = [];
        foreach ($data->roleids as $roleid) {
            $roleusers = get_role_users($roleid, $context, false, 'u.id');
            foreach ($roleusers as $user) {
                $participantids[] = $user->id;
            }
        }
        if (!empty($participantids)) {
            $participantids = array_unique($participantids);
            $scopebuilder->filter_by_participants($participantids, $data->roleids);
        }
    }

    // Legacy support for old "students" field.
    if (empty($data->all_students) && !empty($data->students)) {
        $scopebuilder->filter_by_participants($data->students);
    }

    if (!empty($data->groups)) {
        $scopebuilder->with_groups($data->groups);
    }

    // Handle time range - check if enabled (for optional date_time_selector).
    $timestart = null;
    $timeend = null;

    if (!empty($data->timestart)) {
        if (is_array($data->timestart) && !empty($data->timestart['enabled'])) {
            $timestart = $data->timestart['timestamp'];
        } else if (is_numeric($data->timestart)) {
            $timestart = $data->timestart;
        }
    }

    if (!empty($data->timeend)) {
        if (is_array($data->timeend) && !empty($data->timeend['enabled'])) {
            $timeend = $data->timeend['timestamp'];
        } else if (is_numeric($data->timeend)) {
            $timeend = $data->timeend;
        }
    }

    if (!empty($timestart) && !empty($timeend)) {
        $scopebuilder->with_timerange($timestart, $timeend);
    }

    if ($isedit) {
        // Update existing report.
        $report->title = !empty($data->title) ? $data->title : substr($data->prompt, 0, 80);
        $report->scope_details = $scopebuilder->build();
        $report->prompt = $data->prompt;
        $report->timemodified = time();

        // If report was completed/failed/cancelled, reset to pending and requeue.
        if (in_array($report->status, ['completed', 'failed', 'cancelled'])) {
            $report->status = 'pending';
            $report->ai_result = null;
            $report->raw_data = null;
            $report->error_message = null;

            // Queue adhoc task for processing.
            $task = new \report_ai_analysis\task\process_analysis_task();
            $task->set_custom_data(['reportid' => $reportid]);
            $task->set_userid($USER->id);
            \core\task\manager::queue_adhoc_task($task);

            $message = get_string('reportupdatedandqueued', 'report_ai_analysis');
        } else {
            $message = get_string('reportupdated', 'report_ai_analysis');
        }

        $DB->update_record('report_ai_analysis_reports', $report);
    } else {
        // Create new report record.
        $record = new stdClass();
        $record->contextid = $context->id;
        $record->title = !empty($data->title) ? $data->title : substr($data->prompt, 0, 80);
        $record->scope_details = $scopebuilder->build();
        $record->prompt = $data->prompt;
        $record->status = 'pending';
        $record->userid = $USER->id;
        $record->timecreated = time();
        $record->timemodified = time();

        $reportid = $DB->insert_record('report_ai_analysis_reports', $record);

        // Queue adhoc task for processing.
        $task = new \report_ai_analysis\task\process_analysis_task();
        $task->set_custom_data(['reportid' => $reportid]);
        $task->set_userid($USER->id);
        \core\task\manager::queue_adhoc_task($task);

        $message = get_string('analysisqueued', 'report_ai_analysis');
    }

    redirect($indexurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
}

// Render page.
echo $OUTPUT->header();
if ($isedit && $report) {
    echo $OUTPUT->heading(get_string('editreport', 'report_ai_analysis') . ': ' . s($report->title));
} else {
    echo $OUTPUT->heading(get_string('createanalysis', 'report_ai_analysis'));
}
$mform->display();
echo $OUTPUT->footer();
