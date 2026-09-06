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
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis\output;

use renderable;
use renderer_base;
use templatable;
use report_ai_analysis\local\report_access;
use report_ai_analysis\local\report_exporter;

/**
 * View page output class.
 *
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view_page implements renderable, templatable {
    /** @var \stdClass The report record */
    private \stdClass $report;

    /** @var \context The context */
    private \context $context;

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
    public function export_for_template(renderer_base $output): \stdClass {
        $exporter = new report_exporter($this->report, $this->context);
        $data = $exporter->get_template_data();
        $data->canacceptaiterms = $data->haserror && ($this->report->error_code ?? '') === 'error_terms_not_accepted';
        if ($data->canacceptaiterms) {
            $data->acceptaitermsurl = (new \moodle_url('/local/ai_manager/confirm_ai_usage.php'))->out(false);
        }

        // Actions.
        $data->candelete = report_access::can_manage($this->report, 'report/ai_analysis:delete');
        $data->canrerun = report_access::can_manage($this->report, 'report/ai_analysis:rerun') &&
            in_array($this->report->status, ['failed', 'cancelled', 'completed'], true);
        $data->canedit = report_access::can_manage($this->report, 'report/ai_analysis:create') &&
            $this->report->status !== 'running';
        $data->isprocessing = in_array($this->report->status, ['pending', 'running'], true);
        $data->cancancel = $data->candelete && $data->isprocessing;
        $data->aiactionsdisabled = false;
        if ($data->canedit || $data->canrerun) {
            $availability = \core\di::get(ai_availability::class)->get_availability($this->context);
            if ($availability['state'] === 'hidden') {
                $data->canedit = false;
                $data->canrerun = false;
            }
            $data->aiactionsdisabled = $availability['state'] === 'disabled';
            $data->availabilitymessage = $availability['message'];
        }

        if ($data->candelete) {
            $data->deleteurl = (new \moodle_url('/report/ai_analysis/view.php', [
                'action' => 'delete',
                'id' => $this->report->id,
                'sesskey' => sesskey(),
            ]))->out(false);
        }

        if ($data->canrerun) {
            $data->rerunurl = (new \moodle_url('/report/ai_analysis/rerun.php', [
                'id' => $this->report->id,
                'sesskey' => sesskey(),
            ]))->out(false);
        }

        if ($data->canedit) {
            $data->editurl = (new \moodle_url('/report/ai_analysis/create.php', [
                'reportid' => $this->report->id,
                'courseid' => $this->context->instanceid,
            ]))->out(false);
        }
        if ($data->cancancel) {
            $data->cancelurl = (new \moodle_url('/report/ai_analysis/index.php', [
                'action' => 'cancel',
                'reportid' => $this->report->id,
                'courseid' => $this->context->instanceid,
                'sesskey' => sesskey(),
            ]))->out(false);
        }

        // Export URLs.
        $data->exportjsonurl = (new \moodle_url('/report/ai_analysis/export.php', [
            'id' => $this->report->id,
            'format' => 'json',
        ]))->out(false);
        $data->exporthtmlurl = (new \moodle_url('/report/ai_analysis/export.php', [
            'id' => $this->report->id,
            'format' => 'html',
        ]))->out(false);

        // Back to index link.
        $data->indexurl = (new \moodle_url('/report/ai_analysis/index.php', [
            'courseid' => $this->context->instanceid,
        ]))->out(false);

        return $data;
    }
}
