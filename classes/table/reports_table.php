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
 * Reports table for AI analysis.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis\table;

use table_sql;
use moodle_url;
use html_writer;
use report_ai_analysis\error_info;
use report_ai_analysis\local\report_access;
use report_ai_analysis\local\report_exporter;
use report_ai_analysis\output\ai_availability;
use report_ai_analysis\scope_builder;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/tablelib.php');

/**
 * Table displaying AI analysis reports.
 *
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reports_table extends table_sql {
    /** @var \context The context */
    private \context $context;

    /** @var array|null Availability shared by all action cells in this table. */
    private ?array $availability = null;

    /**
     * Constructor.
     *
     * @param \context $context The context for filtering reports
     * @param \moodle_url $baseurl Base URL for the table
     */
    public function __construct(\context $context, \moodle_url $baseurl) {
        if ($context->contextlevel !== CONTEXT_COURSE) {
            throw new \moodle_exception('error_contextmismatch', 'report_ai_analysis');
        }
        parent::__construct('report_ai_analysis_reports_table');

        $this->context = $context;

        // Define columns.
        $this->define_columns([
            'title',
            'scope',
            'timecreated',
            'userid',
            'status',
            'actions',
        ]);

        // Define headers.
        $this->define_headers([
            get_string('report_title', 'report_ai_analysis'),
            get_string('report_scope', 'report_ai_analysis'),
            get_string('report_created', 'report_ai_analysis'),
            get_string('report_creator', 'report_ai_analysis'),
            get_string('report_status', 'report_ai_analysis'),
            get_string('report_actions', 'report_ai_analysis'),
        ]);

        // Set base URL.
        $this->define_baseurl($baseurl);

        // Table configuration.
        $this->sortable(true, 'timecreated', SORT_DESC);
        $this->no_sorting('actions');
        $this->no_sorting('scope');
        $this->collapsible(false);
        $this->pageable(true);

        // Setup SQL query.
        $this->setup_sql();
    }

    /**
     * Setup SQL query for the table.
     */
    private function setup_sql(): void {
        global $USER;

        // Join with user table to avoid N+1 queries.
        $userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $fields = 'r.id, r.title, r.contextid, r.scope_details, r.timecreated, r.userid, r.status, ' .
            'r.error_message, r.error_details, r.error_code, ' . $userfields;
        $from = '{report_ai_analysis_reports} r LEFT JOIN {user} u ON r.userid = u.id';

        // Filter in SQL so counts and pagination cannot reveal foreign private reports.
        $where = 'r.contextid = :contextid';
        $params = ['contextid' => $this->context->id];
        if (!has_capability('report/ai_analysis:view', $this->context)) {
            $where .= ' AND 1 = 0';
        } else if (
            !has_capability('report/ai_analysis:viewall', $this->context) &&
            !get_config('report_ai_analysis', 'share_reports_in_course')
        ) {
            $where .= ' AND r.userid = :userid';
            $params['userid'] = $USER->id;
        }

        $this->set_sql($fields, $from, $where, $params);
        $this->set_count_sql("SELECT COUNT(r.id) FROM {$from} WHERE {$where}", $params);
    }

    /**
     * Format title column.
     *
     * @param \stdClass $row Table row
     * @return string Formatted title
     */
    protected function col_title(\stdClass $row): string {
        $viewurl = new moodle_url('/report/ai_analysis/view.php', ['id' => $row->id]);
        return html_writer::link($viewurl, s($row->title));
    }

    /**
     * Format scope column.
     *
     * @param \stdClass $row Table row
     * @return string Formatted scope
     */
    protected function col_scope(\stdClass $row): string {
        if (empty($row->scope_details)) {
            return '-';
        }

        $scope = scope_builder::parse($row->scope_details);
        $parts = [];

        if (isset($scope->filters->sources)) {
            $parts[] = get_string('sources', 'report_ai_analysis') . ': ' . count($scope->filters->sources);
        }

        // Handle participants (new field).
        if (isset($scope->filters->participants)) {
            $parts[] = get_string('participants', 'report_ai_analysis') . ': ' . count($scope->filters->participants);
        } else if (isset($scope->filters->students)) {
            // Legacy support for old "students" field.
            $parts[] = get_string('participants', 'report_ai_analysis') . ': ' . count($scope->filters->students);
        }
        if (!empty($scope->filters->roles)) {
            $parts[] = get_string('roles', 'report_ai_analysis') . ': ' . count($scope->filters->roles);
        }

        return !empty($parts) ? implode(', ', $parts) : '-';
    }

    /**
     * Format timecreated column.
     *
     * @param \stdClass $row Table row
     * @return string Formatted date
     */
    protected function col_timecreated(\stdClass $row): string {
        return userdate($row->timecreated, get_string('strftimedatetimeshort', 'langconfig'));
    }

    /**
     * Format userid column.
     *
     * @param \stdClass $row Table row
     * @return string User fullname
     */
    protected function col_userid(\stdClass $row): string {
        // User data is already joined in SQL query.
        return s(fullname($row));
    }

    /**
     * Format status column.
     *
     * @param \stdClass $row Table row
     * @return string Formatted status with badge
     */
    protected function col_status(\stdClass $row): string {
        $statuskey = 'status_' . $row->status;
        $statustext = get_string($statuskey, 'report_ai_analysis');

        $badgeclass = 'badge ';
        switch ($row->status) {
            case 'completed':
                $badgeclass .= 'badge-success';
                break;
            case 'running':
                $badgeclass .= 'badge-info';
                break;
            case 'failed':
                $badgeclass .= 'badge-danger';
                break;
            case 'cancelled':
                $badgeclass .= 'badge-warning';
                break;
            default:
                $badgeclass .= 'badge-secondary';
        }

        $status = html_writer::span($statustext, $badgeclass);
        if ($row->status === 'failed') {
            $status .= html_writer::div(
                s(error_info::get_description($row->error_code ?? null)),
                'small text-danger mt-1'
            );
            $debugdetails = error_info::get_debug_details(
                $row->error_code ?? null,
                $row->error_message ?? null,
                $row->error_details ?? null
            );
            if ($debugdetails !== null) {
                $status .= html_writer::tag(
                    'pre',
                    s(get_string('debuginfo', 'debug') . ': ' . $debugdetails),
                    ['class' => 'small mt-1 mb-0']
                );
            }
        }

        return $status;
    }

    /**
     * Format actions column.
     *
     * @param \stdClass $row Table row
     * @return string Action links
     */
    protected function col_actions(\stdClass $row): string {
        if (!report_access::can_view($row)) {
            return '';
        }
        $actions = [];

        // View action.
        $viewurl = new moodle_url('/report/ai_analysis/view.php', ['id' => $row->id]);
        $actions[] = html_writer::link($viewurl, get_string('view', 'report_ai_analysis'));

        // Failed reports deliberately support exports with safe error descriptions.
        if (report_exporter::can_export($row)) {
            $exporturl = new moodle_url('/report/ai_analysis/export.php', [
                'id' => $row->id,
                'format' => 'json',
            ]);
            $actions[] = html_writer::link($exporturl, get_string('export', 'report_ai_analysis'));
        }

        $canrerun = report_access::can_manage($row, 'report/ai_analysis:rerun') &&
            in_array($row->status, ['completed', 'failed', 'cancelled'], true);
        $canedit = report_access::can_manage($row, 'report/ai_analysis:create') && $row->status !== 'running';
        if (($canrerun || $canedit) && $this->availability === null) {
            $this->availability = \core\di::get(ai_availability::class)->get_availability($this->context);
        }
        $aistate = $this->availability['state'] ?? 'hidden';

        // Re-run action (if completed, failed, or cancelled and user has permission).
        $reportcontext = \context::instance_by_id($row->contextid);
        if ($canrerun && $aistate !== 'hidden') {
            $rerunurl = new moodle_url('/report/ai_analysis/rerun.php', [
                'id' => $row->id,
                'sesskey' => sesskey(),
            ]);
            $actions[] = $aistate === 'available'
                ? html_writer::link($rerunurl, get_string('rerun', 'report_ai_analysis'))
                : html_writer::tag('button', get_string('rerun', 'report_ai_analysis'), [
                    'type' => 'button',
                    'disabled' => 'disabled',
                    'title' => get_string('aiunavailable', 'report_ai_analysis'),
                ]);
        }

        // Edit action (if user has permission and report is not running).
        if ($canedit && $aistate !== 'hidden') {
            $editurl = new moodle_url('/report/ai_analysis/create.php', [
                'reportid' => $row->id,
                'courseid' => $reportcontext->instanceid,
            ]);
            $actions[] = $aistate === 'available'
                ? html_writer::link($editurl, get_string('edit', 'report_ai_analysis'))
                : html_writer::tag('button', get_string('edit', 'report_ai_analysis'), [
                    'type' => 'button',
                    'disabled' => 'disabled',
                    'title' => get_string('aiunavailable', 'report_ai_analysis'),
                ]);
        }

        // Delete action (if user has permission).
        $candelete = report_access::can_manage($row, 'report/ai_analysis:delete');
        if ($candelete) {
            $deleteurl = new moodle_url('/report/ai_analysis/index.php', [
                'action' => 'delete',
                'reportid' => $row->id,
                'courseid' => $reportcontext->instanceid,
            ]);
            $actions[] = html_writer::link($deleteurl, get_string('delete', 'report_ai_analysis'), [
                'class' => 'text-danger',
            ]);
        }

        // Cancel action (if status is pending or running).
        if (in_array($row->status, ['pending', 'running'], true) && $candelete) {
            $cancelurl = new moodle_url('/report/ai_analysis/index.php', [
                'action' => 'cancel',
                'reportid' => $row->id,
                'sesskey' => sesskey(),
                'courseid' => $reportcontext->instanceid,
            ]);
            $actions[] = html_writer::link($cancelurl, get_string('cancel', 'report_ai_analysis'), [
                'class' => 'text-warning',
                'title' => get_string('cancelwarning', 'report_ai_analysis'),
            ]);
        }

        return implode(' | ', $actions);
    }
}
