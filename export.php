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
 * Export page for AI analysis reports.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();

// Get parameters.
$id = required_param('id', PARAM_INT);
$format = required_param('format', PARAM_ALPHA);

// Load report.
global $DB;
$report = $DB->get_record('report_ai_analysis_reports', ['id' => $id], '*', MUST_EXIST);

// Get context (always course context).
$context = context::instance_by_id($report->contextid);
require_login($context->instanceid);

// Check permissions.
require_capability('report/ai_analysis:view', $context);

// Export based on format.
switch ($format) {
    case 'json':
        export_json($report, $context);
        break;
    case 'html':
        export_html($report, $context);
        break;
    default:
        throw new moodle_exception('invalidformat', 'report_ai_analysis');
}

/**
 * Export report as JSON.
 *
 * @param stdClass $report The report record
 * @param context $context The context
 * @return void
 */
function export_json($report, $context) {
    global $DB, $USER;

    // Get user info with all required fields.
    $userfields = 'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename, email';
    $user = $DB->get_record('user', ['id' => $report->userid], $userfields);

    // Build export data.
    $exportdata = [
        'id' => $report->id,
        'title' => $report->title,
        'context' => $context->get_context_name(),
        'scope' => json_decode($report->scope_details, true),
        'prompt' => $report->prompt,
        'ai_result' => $report->ai_result,
        'status' => $report->status,
        'error_message' => $report->error_message,
        'ai_model' => $report->ai_model_name,
        'token_usage' => $report->token_usage,
        'retry_count' => $report->retry_count,
        'timecreated' => $report->timecreated,
        'timecompleted' => $report->timecompleted,
        'created_by' => [
            'id' => $user->id,
            'name' => fullname($user),
            'email' => $user->email,
        ],
    ];

    // Include raw data if user has permission.
    if (has_capability('report/ai_analysis:viewrawdata', $context) && !empty($report->raw_data)) {
        $exportdata['raw_data'] = $report->raw_data;
    }

    // Set headers.
    $filename = clean_filename($report->title . '_' . date('Y-m-d_H-i-s') . '.json');
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');

    // Output JSON.
    echo json_encode($exportdata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Export report as HTML.
 *
 * @param stdClass $report The report record
 * @param context $context The context
 * @return void
 */
function export_html($report, $context) {
    global $DB, $SITE;

    // Get user info with all required fields.
    $userfields = 'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename, email';
    $user = $DB->get_record('user', ['id' => $report->userid], $userfields);

    // Parse scope.
    $scope = json_decode($report->scope_details, true);
    $scopeitems = [];
    if (!empty($scope) && is_array($scope)) {
        foreach ($scope as $key => $value) {
            if (is_array($value)) {
                // Handle nested arrays by converting to strings first.
                $items = [];
                foreach ($value as $item) {
                    if (is_array($item)) {
                        // For deeply nested arrays, use JSON representation.
                        $items[] = s(json_encode($item, JSON_UNESCAPED_UNICODE));
                    } else {
                        $items[] = s($item);
                    }
                }
                // Construct the content using html_writer consistently.
                $content = html_writer::tag('strong', s($key) . ':') . ' ' .
                           html_writer::tag('span', implode(', ', $items));
                $scopeitems[] = html_writer::tag('li', $content);
            } else {
                // Construct the content using html_writer consistently.
                $content = html_writer::tag('strong', s($key) . ':') . ' ' .
                           html_writer::tag('span', s($value));
                $scopeitems[] = html_writer::tag('li', $content);
            }
        }
    }
    $scopehtml = html_writer::tag('ul', implode('', $scopeitems));

    // Build HTML.
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>' . s($report->title) . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
        h1 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        h2 { color: #555; margin-top: 30px; border-bottom: 1px solid #ddd; padding-bottom: 5px; }
        .meta { background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .meta dt { font-weight: bold; float: left; clear: left; width: 150px; }
        .meta dd { margin-left: 160px; margin-bottom: 10px; }
        .content { background-color: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
        .badge { padding: 5px 10px; border-radius: 3px; font-size: 0.9em; }
        .badge-success { background-color: #28a745; color: white; }
        .badge-danger { background-color: #dc3545; color: white; }
        .badge-warning { background-color: #ffc107; color: black; }
        .badge-info { background-color: #17a2b8; color: white; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 0.9em; color: #666; }
    </style>
</head>
<body>
    <h1>' . s($report->title) . '</h1>

    <div class="meta">
        <dl>
            <dt>Status:</dt>
            <dd><span class="badge badge-' . get_status_class($report->status) . '">' .
                s(get_string('status_' . $report->status, 'report_ai_analysis')) . '</span></dd>
            <dt>Context:</dt>
            <dd>' . s($context->get_context_name()) . '</dd>
            <dt>Created by:</dt>
            <dd>' . s(fullname($user)) . ' (' . s($user->email) . ')</dd>
            <dt>Created:</dt>
            <dd>' . userdate($report->timecreated, get_string('strftimedatetimeshort')) . '</dd>';

    if ($report->timecompleted) {
        $html .= '<dt>Completed:</dt>
            <dd>' . userdate($report->timecompleted, get_string('strftimedatetimeshort')) . '</dd>';
    }

    if ($report->ai_model_name) {
        $html .= '<dt>AI Model:</dt>
            <dd>' . s($report->ai_model_name) . '</dd>';
    }

    if ($report->token_usage) {
        $html .= '<dt>Token Usage:</dt>
            <dd>' . s($report->token_usage) . '</dd>';
    }

    $html .= '</dl>
    </div>

    <h2>Scope</h2>
    <div class="content">' . $scopehtml . '</div>

    <h2>Prompt</h2>
    <div class="content">' . nl2br(s($report->prompt)) . '</div>';

    if (!empty($report->ai_result)) {
        $html .= '<h2>AI Analysis Result</h2>
        <div class="content">' . format_text($report->ai_result, FORMAT_MARKDOWN) . '</div>';
    }

    if (!empty($report->error_message)) {
        $html .= '<h2>Error</h2>
        <div class="content" style="color: #dc3545;">' . s($report->error_message) . '</div>';
    }

    $html .= '<div class="footer">
        <p>Exported from: ' . s($SITE->fullname) . ' on ' .
            userdate(time(), get_string('strftimedatetimeshort')) . '</p>
    </div>

</body>
</html>';

    // Set headers.
    $filename = clean_filename($report->title . '_' . date('Y-m-d_H-i-s') . '.html');
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');

    // Output HTML.
    echo $html;
    exit;
}

/**
 * Get CSS class for status badge.
 *
 * @param string $status The status
 * @return string The CSS class
 */
function get_status_class($status) {
    switch ($status) {
        case 'completed':
            return 'success';
        case 'failed':
        case 'cancelled':
            return 'danger';
        case 'running':
            return 'warning';
        case 'pending':
        default:
            return 'info';
    }
}
