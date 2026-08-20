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
 * Adhoc task for processing AI analysis reports.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis\task;

use core\di;
use core\task\adhoc_task;
use report_ai_analysis\scope_builder;
use report_ai_analysis\data_collector;
use report_ai_analysis\local\ai_request_provider;

/**
 * Process AI analysis report task.
 *
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_analysis_task extends adhoc_task {
    /**
     * AI Manager purpose for singleprompt.
     */
    private const AI_PURPOSE = 'singleprompt';

    /**
     * Maximum raw data length in bytes (hard cap to prevent memory issues).
     *
     * This hard limit protects against excessive memory usage and database storage
     * issues. 500KB is sufficient for debugging while staying well under typical
     * database TEXT field limits (64KB-16MB depending on configuration).
     */
    private const MAX_RAW_DATA_LENGTH = 500000;

    /**
     * Get task name.
     *
     * @return string Task name
     */
    public function get_name() {
        return get_string('task_process_analysis', 'report_ai_analysis');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $reportid = $data->reportid;

        // Load report.
        $report = $DB->get_record('report_ai_analysis_reports', ['id' => $reportid]);
        if (!$report) {
            mtrace("Report {$reportid} not found, skipping.");
            return;
        }

        // Check if already processed or cancelled.
        if (in_array($report->status, ['completed', 'cancelled'])) {
            mtrace("Report {$reportid} already {$report->status}, skipping.");
            return;
        }

        // Update status to running.
        $report->status = 'running';
        $report->timemodified = time();
        $DB->update_record('report_ai_analysis_reports', $report);

        try {
            global $CFG;

            // Get context from report.
            $context = \context::instance_by_id($report->contextid);

            // Parse scope and create builder.
            $scope = scope_builder::parse($report->scope_details);
            $scopebuilder = new scope_builder($scope->courseid);

            // Restore analysis mode if set.
            if (isset($scope->analysis_mode)) {
                $scopebuilder->set_analysis_mode($scope->analysis_mode);
            }

            // Restore filters from scope.
            if (isset($scope->filters->sources)) {
                $scopebuilder->with_sources($scope->filters->sources);
            }

            // Handle participants (with role support).
            if (isset($scope->filters->participants)) {
                $roleids = isset($scope->filters->roles) ? $scope->filters->roles : [];
                $scopebuilder->filter_by_participants($scope->filters->participants, $roleids);
            } else if (isset($scope->filters->students)) {
                // Legacy support for old "students" field.
                $scopebuilder->filter_by_participants($scope->filters->students);
            }

            if (isset($scope->filters->groups)) {
                $scopebuilder->with_groups($scope->filters->groups);
            }

            // Handle timerange filter - check both old and new format.
            if (
                isset($scope->filters->timerange) &&
                isset($scope->filters->timerange->start) &&
                isset($scope->filters->timerange->end)
            ) {
                // New format: timerange as object with start/end properties.
                $scopebuilder->with_timerange($scope->filters->timerange->start, $scope->filters->timerange->end);
            } else if (isset($scope->filters->timestart) && isset($scope->filters->timeend)) {
                // Legacy format: separate timestart/timeend properties.
                $scopebuilder->with_timerange($scope->filters->timestart, $scope->filters->timeend);
            }

            // Collect conversation data.
            $maxrecords = get_config('report_ai_analysis', 'max_records_per_analysis') ?: 1000;
            $collector = new data_collector($scopebuilder, $maxrecords);
            $collecteddata = $collector->collect();

            // Check if any data was collected.
            // Data is keyed by provider type (e.g., 'mod_forum', 'block_ai_chat').
            $hasdata = false;
            foreach ($collecteddata as $providerdata) {
                if (!empty($providerdata)) {
                    $hasdata = true;
                    break;
                }
            }
            if (!$hasdata) {
                throw new \moodle_exception('error_no_data', 'report_ai_analysis');
            }

            // Format data for AI.
            $conversationdata = $collector->format_for_ai($collecteddata);

            // Store raw data if enabled.
            $storerawdata = get_config('report_ai_analysis', 'store_raw_data');
            if ($storerawdata) {
                $maxlength = (int) get_config('report_ai_analysis', 'truncate_raw_data_length');
                // Apply hard cap to prevent memory issues.
                if ($maxlength <= 0 || $maxlength > self::MAX_RAW_DATA_LENGTH) {
                    $maxlength = self::MAX_RAW_DATA_LENGTH;
                }
                $report->raw_data = \core_text::substr($conversationdata, 0, $maxlength);
            }

            // Build system prompt.
            $systemprompt = get_config('report_ai_analysis', 'system_prompt');
            if (empty($systemprompt)) {
                $systemprompt = get_string('system_prompt_default', 'report_ai_analysis');
            }

            // Prepare AI request.
            $fullprompt = $systemprompt . "\n\n" . $report->prompt . "\n\n" . $conversationdata;

            // Call AI Manager using the DI-injectable provider.
            $starttime = microtime(true);
            $airequestprovider = di::get(ai_request_provider::class);

            $result = $airequestprovider->perform_request(self::AI_PURPOSE, $fullprompt, 'report_ai_analysis', $context->id);
            $duration = microtime(true) - $starttime;

            // Check for errors.
            if ($result->get_code() !== 200) {
                // Log debug info for troubleshooting.
                $debuginfo = method_exists($result, 'get_debuginfo') ? $result->get_debuginfo() : '';
                mtrace("AI request failed with code {$result->get_code()}: {$result->get_errormessage()}");
                if (!empty($debuginfo)) {
                    mtrace("Debug info: " . $debuginfo);
                }
                throw new \moodle_exception(
                    'error_ai_request',
                    'report_ai_analysis',
                    '',
                    $result->get_errormessage(),
                    $debuginfo
                );
            }

            // Update report with success.
            $report->status = 'completed';
            $report->ai_result = $result->get_content();
            $report->ai_model_name = $result->get_modelinfo();
            $report->execution_time = $duration;
            $report->token_usage = $result->get_usage()->value;
            $report->timecompleted = time();
            $report->timemodified = time();

            $DB->update_record('report_ai_analysis_reports', $report);

            mtrace("Report {$reportid} completed successfully.");
        } catch (\Exception $e) {
            // Handle errors with retry logic.
            $maxretries = get_config('report_ai_analysis', 'retry_on_failure') ?: 0;

            if ($report->retry_count < $maxretries && $this->is_retryable_error($e)) {
                // Increment retry count and requeue.
                $report->retry_count++;
                $report->status = 'pending';
                $report->timemodified = time();
                $DB->update_record('report_ai_analysis_reports', $report);

                // Requeue task with delay, preserving the original user context.
                $newtask = new self();
                $newtask->set_custom_data($data);
                $newtask->set_userid($this->get_userid());
                $newtask->set_next_run_time(time() + 60); // 1 minute delay.
                \core\task\manager::queue_adhoc_task($newtask);

                mtrace("Report {$reportid} failed, retry {$report->retry_count}/{$maxretries}.");
            } else {
                // Mark as failed.
                $report->status = 'failed';
                $report->error_message = $e->getMessage();
                $report->error_code = $this->get_error_code($e);
                $report->timemodified = time();
                $DB->update_record('report_ai_analysis_reports', $report);

                mtrace("Report {$reportid} failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Check if error is retryable.
     *
     * @param \Exception $e The exception
     * @return bool True if retryable
     */
    private function is_retryable_error(\Exception $e): bool {
        $message = $e->getMessage();

        // Retry on timeout, connection errors, rate limits.
        $retryable = [
            'timeout',
            'connection',
            'rate limit',
            'temporarily unavailable',
        ];

        foreach ($retryable as $pattern) {
            if (stripos($message, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get error code from exception.
     *
     * @param \Exception $e The exception
     * @return string Error code
     */
    private function get_error_code(\Exception $e): string {
        $message = strtolower($e->getMessage());

        if (stripos($message, 'timeout') !== false) {
            return 'error_api_timeout';
        }
        if (stripos($message, 'connection') !== false) {
            return 'error_api_connection_error';
        }
        if (stripos($message, 'rate limit') !== false) {
            return 'error_rate_limit';
        }
        if (stripos($message, 'too long') !== false) {
            return 'error_prompt_too_long';
        }

        return 'error_unknown';
    }
}
