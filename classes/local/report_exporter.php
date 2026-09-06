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

namespace report_ai_analysis\local;

use report_ai_analysis\error_info;
use report_ai_analysis\scope_builder;

/**
 * Safe report presentation shared by the detail page and downloadable exports.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_exporter {
    /** @var \stdClass Report being presented. */
    private \stdClass $report;

    /** @var \context Course context used for permissions and formatting. */
    private \context $context;

    /**
     * Create an authorized presenter for a course report.
     *
     * @param \stdClass $report Report record
     * @param \context $context Report context
     */
    public function __construct(\stdClass $report, \context $context) {
        if ($context->contextlevel !== CONTEXT_COURSE || (int) $report->contextid !== (int) $context->id) {
            throw new \moodle_exception('error_contextmismatch', 'report_ai_analysis');
        }
        report_access::require_view($report);
        $this->report = $report;
        $this->context = $context;
    }

    /**
     * Whether a report has reached an exportable state, including safe failure exports.
     *
     * @param \stdClass $report Report record
     * @return bool
     */
    public static function can_export(\stdClass $report): bool {
        return in_array($report->status, ['completed', 'failed'], true);
    }

    /**
     * Format the user's untrusted prompt, never trusting embedded HTML.
     *
     * @return string Safe HTML
     */
    public function format_prompt(): string {
        $format = get_config('report_ai_analysis', 'enable_markdown_conversion') ? FORMAT_MARKDOWN : FORMAT_PLAIN;
        return format_text($this->report->prompt, $format, [
            'context' => $this->context,
            'clean' => true,
            'trusted' => false,
        ]);
    }

    /**
     * Format a stored result using its persisted format, with a real plain-text mode.
     *
     * @return string Safe HTML
     */
    public function format_result(): string {
        $text = (string) ($this->report->ai_result ?? '');
        // Moodle's FORMAT_* constants are strings; database drivers may return integer fields as ints.
        $format = (string) ($this->report->resultformat ?? FORMAT_MARKDOWN);
        if (!in_array($format, [FORMAT_HTML, FORMAT_MARKDOWN, FORMAT_PLAIN], true)) {
            $format = FORMAT_MARKDOWN;
        }
        $options = ['context' => $this->context, 'clean' => true, 'trusted' => false];

        if (!get_config('report_ai_analysis', 'enable_markdown_conversion')) {
            if ($format === FORMAT_HTML) {
                // Clean before converting so hidden active content cannot become visible text.
                $text = html_to_text(format_text($text, FORMAT_HTML, $options + ['filter' => false]), 0, false);
            }
            $format = FORMAT_PLAIN;
        }

        return format_text($text, $format, $options);
    }

    /**
     * Get export data. Prompt and result are safe HTML, not the original stored markup.
     *
     * @return array Export data without unrelated creator contact information
     */
    public function get_data(): array {
        global $DB;

        $namefields = \core_user\fields::for_name()->get_sql('', false, '', '', false)->selects;
        $creator = $DB->get_record('user', ['id' => $this->report->userid], 'id, ' . $namefields);
        $data = [
            'id' => (int) $this->report->id,
            'title' => $this->report->title,
            'context' => html_to_text($this->context->get_context_name(false), 0, false),
            'scope' => empty($this->report->scope_details) ? [] : json_decode($this->report->scope_details, true),
            'prompt' => $this->format_prompt(),
            'promptformat' => FORMAT_HTML,
            'ai_result' => $this->report->status === 'completed' ? $this->format_result() : null,
            'resultformat' => FORMAT_HTML,
            'status' => $this->report->status,
            'error_message' => null,
            'ai_model' => $this->report->ai_model_name ?? null,
            'token_usage' => $this->report->token_usage ?? null,
            'retry_count' => (int) ($this->report->retry_count ?? 0),
            'timecreated' => (int) $this->report->timecreated,
            'timecompleted' => $this->report->timecompleted ?? null,
            'truncated' => !empty($this->report->truncated),
            'created_by' => [
                'id' => (int) $this->report->userid,
                'name' => $creator ? fullname($creator) : get_string('unknown', 'report_ai_analysis'),
            ],
        ];

        if ($this->report->status === 'failed') {
            $data['error_message'] = error_info::get_description($this->report->error_code ?? null);
            $details = error_info::get_debug_details(
                $this->report->error_code ?? null,
                $this->report->error_message ?? null,
                $this->report->error_details ?? null
            );
            if ($details !== null) {
                $data['error_details'] = $details;
            }
        }
        if (has_capability('report/ai_analysis:viewrawdata', $this->context) && !empty($this->report->raw_data)) {
            $data['raw_data'] = $this->report->raw_data;
        }

        return $data;
    }

    /**
     * Get structured scope labels and values for escaped Mustache output.
     *
     * @return array List of label/value pairs, never prebuilt HTML
     */
    public function get_scope_items(): array {
        global $DB;

        if (empty($this->report->scope_details)) {
            return [];
        }
        $scope = scope_builder::parse($this->report->scope_details);
        $items = [];
        if (isset($scope->analysis_mode)) {
            $mode = in_array($scope->analysis_mode, [
                scope_builder::ANALYSIS_MODE_AGGREGATED,
                scope_builder::ANALYSIS_MODE_INDIVIDUAL,
            ], true) ? 'analysis_mode_' . $scope->analysis_mode : 'unknown';
            $items[] = [
                'label' => get_string('analysis_mode', 'report_ai_analysis'),
                'value' => get_string($mode, 'report_ai_analysis'),
            ];
        }
        $filters = $scope->filters ?? new \stdClass();
        foreach (['sources', 'groups'] as $filter) {
            if (isset($filters->{$filter})) {
                $items[] = ['label' => get_string($filter, 'report_ai_analysis'), 'value' => count($filters->{$filter})];
            }
        }
        $participants = $filters->participants ?? $filters->students ?? null;
        if ($participants !== null) {
            $value = count($participants);
            $label = 'participants';
            if ($value === 1) {
                $label = 'participant';
                $namefields = \core_user\fields::for_name()->get_sql('', false, '', '', false)->selects;
                $participant = $DB->get_record('user', ['id' => reset($participants)], 'id, ' . $namefields);
                $value = $participant ? fullname($participant) : get_string('unknown', 'report_ai_analysis');
            }
            $items[] = ['label' => get_string($label, 'report_ai_analysis'), 'value' => $value];
        }
        if (!empty($filters->roles)) {
            $items[] = [
                'label' => get_string('roles', 'report_ai_analysis'),
                'value' => implode(', ', scope_builder::get_role_names($filters->roles)),
            ];
        }
        foreach (['start' => 'timestart', 'end' => 'timeend'] as $boundary => $label) {
            if (!empty($filters->timerange->{$boundary})) {
                $items[] = [
                    'label' => get_string($label, 'report_ai_analysis'),
                    'value' => userdate($filters->timerange->{$boundary}, get_string('strftimedatetimeshort', 'langconfig')),
                ];
            }
        }

        return $items;
    }

    /**
     * Get the common template context for HTML export and the detail page.
     *
     * @return \stdClass Template data
     */
    public function get_template_data(): \stdClass {
        $export = $this->get_data();
        $dateformat = get_string('strftimedatetimeshort', 'langconfig');
        $classes = ['completed' => 'success', 'running' => 'info', 'failed' => 'danger', 'cancelled' => 'warning'];
        $duration = '-';
        if (isset($this->report->execution_time)) {
            $seconds = (int) $this->report->execution_time;
            $duration = $seconds > 0 ? format_time($seconds) : get_string('numseconds', 'moodle', 0);
        }

        return (object) [
            'title' => $export['title'],
            'status' => $export['status'],
            'statustext' => get_string('status_' . $export['status'], 'report_ai_analysis'),
            'statusbadgeclass' => 'badge badge-' . ($classes[$export['status']] ?? 'secondary'),
            'contextname' => $export['context'],
            'creator' => $export['created_by']['name'],
            'timecreated' => userdate($export['timecreated'], $dateformat),
            'timecompleted' => $export['timecompleted'] ? userdate($export['timecompleted'], $dateformat) : '-',
            'scopeitems' => $this->get_scope_items(),
            'prompt' => $export['prompt'],
            'haveresult' => $export['ai_result'] !== null && trim($export['ai_result']) !== '',
            'airesult' => $export['ai_result'],
            'aimodel' => $export['ai_model'] ?? '-',
            'duration' => $duration,
            'tokenusage' => $export['token_usage'] ?? '-',
            'retries' => $export['retry_count'],
            'truncated' => $export['truncated'],
            'haserror' => $export['status'] === 'failed',
            'errormessage' => $export['error_message'],
            'haserrordetails' => isset($export['error_details']),
            'errordetails' => $export['error_details'] ?? null,
            'canviewrawdata' => isset($export['raw_data']),
            'rawdata' => $export['raw_data'] ?? null,
            'canexport' => self::can_export($this->report),
        ];
    }

    /**
     * Produce JSON without changing headers or terminating execution.
     *
     * @return string JSON document
     */
    public function get_json(): string {
        if (!self::can_export($this->report)) {
            throw new \moodle_exception('cannotexportreport', 'report_ai_analysis');
        }
        return json_encode($this->get_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Produce a localised standalone HTML document.
     *
     * @param \renderer_base $output Renderer
     * @return string HTML document
     */
    public function get_html(\renderer_base $output): string {
        global $SITE;

        if (!self::can_export($this->report)) {
            throw new \moodle_exception('cannotexportreport', 'report_ai_analysis');
        }
        $data = $this->get_template_data();
        $data->language = current_language();
        $data->sitename = $SITE->fullname;
        $data->exportedat = userdate(time(), get_string('strftimedatetimeshort', 'langconfig'));
        return $output->render_from_template('report_ai_analysis/export', $data);
    }
}
