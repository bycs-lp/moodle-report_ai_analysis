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
 * Output class for view page.
 *
 * @package    report_ai_analysis
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis\output;

use renderable;
use renderer_base;
use templatable;
use report_ai_analysis\scope_builder;

/**
 * View page output class.
 *
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view_page implements renderable, templatable {
    /** @var \stdClass The report record */
    private $report;

    /** @var \context The context */
    private $context;

    /**
     * Constructor.
     *
     * @param \stdClass $report The report record
     * @param \context $context The context
     */
    public function __construct(\stdClass $report, \context $context) {
        $this->report = $report;
        $this->context = $context;
    }

    /**
     * Export data for template.
     *
     * @param renderer_base $output Renderer
     * @return \stdClass Template data
     */
    public function export_for_template(renderer_base $output) {
        global $DB, $OUTPUT;

        $data = new \stdClass();

        // Basic report info.
        $data->title = format_string($this->report->title);
        $data->status = $this->report->status;
        $data->statustext = get_string('status_' . $this->report->status, 'report_ai_analysis');

        // Status badge.
        $badgeclass = 'badge ';
        switch ($this->report->status) {
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
        $data->statusbadgeclass = $badgeclass;

        // Metadata.
        $data->timecreated = userdate($this->report->timecreated, get_string('strftimedatetimeshort', 'langconfig'));

        $userfields = 'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename';
        $creator = $DB->get_record('user', ['id' => $this->report->userid], $userfields);
        $data->creator = \fullname($creator);

        // Scope details.
        if (!empty($this->report->scope_details)) {
            $scope = scope_builder::parse($this->report->scope_details);
            $scopeparts = [];

            // Analysis mode.
            if (isset($scope->analysis_mode)) {
                $modekey = 'analysis_mode_' . $scope->analysis_mode;
                $modestring = get_string($modekey, 'report_ai_analysis');
                $scopeparts[] = \html_writer::tag('strong', get_string('analysis_mode', 'report_ai_analysis')) .
                    ': ' . \html_writer::span(s($modestring));
            }

            // Courses.
            if (isset($scope->filters->courses)) {
                $coursecount = count($scope->filters->courses);
                $scopeparts[] = s(get_string('courses', 'report_ai_analysis') . ': ' . $coursecount);
            }

            // Sources.
            if (isset($scope->filters->sources)) {
                $sourcecount = count($scope->filters->sources);
                $scopeparts[] = s(get_string('sources', 'report_ai_analysis') . ': ' . $sourcecount);
            }

            // Participants (renamed from students).
            if (isset($scope->filters->participants)) {
                $participantcount = count($scope->filters->participants);

                if ($participantcount === 1) {
                    // Single participant - show full name.
                    $participantid = reset($scope->filters->participants);
                    $participant = $DB->get_record(
                        'user',
                        ['id' => $participantid],
                        'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename'
                    );

                    if ($participant) {
                        $participantname = \fullname($participant);
                        $scopeparts[] = s(get_string('participant', 'report_ai_analysis') . ': ' . $participantname);
                    } else {
                        $scopeparts[] = s(get_string('participant', 'report_ai_analysis') . ': ' .
                            get_string('unknown', 'report_ai_analysis'));
                    }
                } else {
                    // Multiple participants.
                    $scopeparts[] = s(get_string('participants', 'report_ai_analysis') . ': ' . $participantcount);
                }

                // Show role filter if set.
                if (isset($scope->filters->roles) && !empty($scope->filters->roles)) {
                    $rolenames = scope_builder::get_role_names($scope->filters->roles);
                    if (!empty($rolenames)) {
                        $scopeparts[] = s(get_string('roles', 'report_ai_analysis') . ': ' .
                            implode(', ', $rolenames));
                    }
                }
            } else if (isset($scope->filters->students)) {
                // Legacy support for old "students" field.
                $studentcount = count($scope->filters->students);
                $scopeparts[] = s(get_string('participants', 'report_ai_analysis') . ': ' . $studentcount);
            }

            // Groups.
            if (isset($scope->filters->groups)) {
                $groupcount = count($scope->filters->groups);
                $scopeparts[] = s(get_string('groups', 'report_ai_analysis') . ': ' . $groupcount);
            }

            $data->scopedetails = !empty($scopeparts) ? implode('<br>', $scopeparts) : '-';
        } else {
            $data->scopedetails = '-';
        }

        // Prompt - check if markdown conversion is enabled.
        $enablemarkdown = get_config('report_ai_analysis', 'enable_markdown_conversion');
        if ($enablemarkdown) {
            // Use FORMAT_MARKDOWN with newlines option to preserve line breaks.
            $data->prompt = format_text($this->report->prompt, FORMAT_MARKDOWN, ['noclean' => true, 'para' => true]);
        } else {
            $data->prompt = format_text($this->report->prompt, FORMAT_PLAIN);
        }

        // AI result (if completed).
        $data->haveresult = $this->report->status === 'completed' && !empty($this->report->ai_result);
        if ($data->haveresult) {
            // Check if markdown conversion is enabled.
            if ($enablemarkdown) {
                // Use FORMAT_MARKDOWN with newlines option to preserve line breaks.
                $data->airesult = format_text($this->report->ai_result, FORMAT_MARKDOWN, ['noclean' => true, 'para' => true]);
            } else {
                $data->airesult = format_text($this->report->ai_result, FORMAT_PLAIN);
            }

            // Metadata about execution.
            $data->aimodel = $this->report->ai_model_name ?? '-';
            $data->duration = !empty($this->report->execution_time) ?
                round($this->report->execution_time, 2) . ' s' : '-';
            $data->tokenusage = $this->report->token_usage ?? '-';
            $data->retries = $this->report->retry_count ?? 0;
            $data->timecompleted = !empty($this->report->timecompleted) ?
                userdate($this->report->timecompleted, get_string('strftimedatetimeshort', 'langconfig')) : '-';
        } else {
            // Still show AI model even if not completed.
            $data->aimodel = $this->report->ai_model_name ?? '-';
        }

        // Error info (if failed).
        $data->haserror = $this->report->status === 'failed' && !empty($this->report->error_message);
        if ($data->haserror) {
            $data->errormessage = format_string($this->report->error_message);
            $data->errorcode = $this->report->error_code ?? '';
        }

        // Raw data (if user has permission).
        $data->canviewrawdata = has_capability('report/ai_analysis:viewrawdata', $this->context);
        if ($data->canviewrawdata && !empty($this->report->raw_data)) {
            $data->rawdata = format_text($this->report->raw_data, FORMAT_PLAIN);
        }

        // Actions.
        $data->candelete = has_capability('report/ai_analysis:delete', $this->context);

        $data->canrerun = has_capability('report/ai_analysis:rerun', $this->context) &&
            in_array($this->report->status, ['failed', 'cancelled', 'completed']);
        $data->canedit = has_capability('report/ai_analysis:create', $this->context) &&
            !in_array($this->report->status, ['running']);

        if ($data->candelete) {
            $data->deleteurl = new \moodle_url('/report/ai_analysis/view.php', [
                'action' => 'delete',
                'id' => $this->report->id,
                'sesskey' => sesskey(),
            ]);
        }

        if ($data->canrerun) {
            $data->rerunurl = new \moodle_url('/report/ai_analysis/rerun.php', [
                'id' => $this->report->id,
                'sesskey' => sesskey(),
            ]);
        }

        if ($data->canedit) {
            $data->editurl = new \moodle_url('/report/ai_analysis/create.php', [
                'reportid' => $this->report->id,
            ]);
        }

        // Export URLs.
        $data->exportjsonurl = new \moodle_url('/report/ai_analysis/export.php', [
            'id' => $this->report->id,
            'format' => 'json',
        ]);
        $data->exporthtmlurl = new \moodle_url('/report/ai_analysis/export.php', [
            'id' => $this->report->id,
            'format' => 'html',
        ]);

        // Back to index link.
        $indexurl = new \moodle_url('/report/ai_analysis/index.php');
        if ($this->context->contextlevel !== CONTEXT_SYSTEM) {
            switch ($this->context->contextlevel) {
                case CONTEXT_MODULE:
                    $indexurl->param('cmid', $this->context->instanceid);
                    break;
                case CONTEXT_COURSE:
                    $indexurl->param('id', $this->context->instanceid);
                    break;
                case CONTEXT_COURSECAT:
                    $indexurl->param('categoryid', $this->context->instanceid);
                    break;
            }
        }
        $data->indexurl = $indexurl->out(false);

        return $data;
    }
}
