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
require_once($CFG->libdir . '/filelib.php');

use report_ai_analysis\local\report_access;
use report_ai_analysis\local\report_exporter;

require_login();

// Get parameters.
$id = required_param('id', PARAM_INT);
$format = required_param('format', PARAM_ALPHA);
if (!in_array($format, ['json', 'html'], true)) {
    throw new moodle_exception('invalidformat', 'report_ai_analysis');
}

// Load report.
global $DB;
$report = $DB->get_record('report_ai_analysis_reports', ['id' => $id], '*', MUST_EXIST);

// Get context (always course context).
$context = context::instance_by_id($report->contextid);
if ($context->contextlevel !== CONTEXT_COURSE) {
    throw new moodle_exception('error_contextmismatch', 'report_ai_analysis');
}
require_login($context->instanceid, false);

// Check permissions.
report_access::require_view($report);
$PAGE->set_context($context);
$PAGE->set_url('/report/ai_analysis/export.php', ['id' => $id, 'format' => $format]);

$exporter = new report_exporter($report, $context);
$content = $format === 'json' ? $exporter->get_json() : $exporter->get_html($PAGE->get_renderer('report_ai_analysis'));
$filename = clean_filename($report->title . '_' . date('Y-m-d_H-i-s') . '.' . $format);
$mimetype = $format === 'json' ? 'application/json' : 'text/html';
send_file($content, $filename, 0, 0, true, true, $mimetype);
