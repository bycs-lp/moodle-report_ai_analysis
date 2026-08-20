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
 * Data generator for report_ai_analysis plugin.
 *
 * @package    report_ai_analysis
 * @category   test
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_ai_analysis_generator extends component_generator_base {
    /**
     * Create a report.
     *
     * @param array|stdClass $record Report data
     * @return stdClass Created report
     */
    public function create_report($record = null): stdClass {
        global $DB;

        $record = (object) (array) $record;

        // Set defaults.
        if (!isset($record->title)) {
            $record->title = 'Test Report ' . time();
        }
        if (!isset($record->prompt)) {
            $record->prompt = 'Test analysis prompt';
        }
        if (!isset($record->status)) {
            $record->status = 'completed';
        }
        if (!isset($record->contextid)) {
            $courseid = null;
            if (isset($record->courseid)) {
                $courseid = $record->courseid;
            } else if (isset($record->course)) {
                // Handle Behat-style course reference (shortname).
                $course = $DB->get_record('course', ['shortname' => $record->course], '*', MUST_EXIST);
                $courseid = $course->id;
            } else {
                throw new coding_exception('Either contextid, courseid, or course must be provided');
            }
            $context = context_course::instance($courseid);
            $record->contextid = $context->id;
        }
        if (!isset($record->userid)) {
            throw new coding_exception('userid must be provided');
        }
        if (!isset($record->timecreated)) {
            $record->timecreated = time();
        }
        if (!isset($record->timemodified)) {
            $record->timemodified = time();
        }

        // Set completed time if status is completed.
        if ($record->status === 'completed' && !isset($record->timecompleted)) {
            $record->timecompleted = time();
        }

        // Set default AI result if completed and not set.
        if ($record->status === 'completed' && !isset($record->ai_result)) {
            $record->ai_result = 'This is a test AI analysis result for: ' . $record->title;
        }

        // Set default raw_data if not set.
        if (!isset($record->raw_data)) {
            $record->raw_data = json_encode(['test' => 'data', 'conversations' => []]);
        }

        // Set default scope details if not set.
        if (!isset($record->scope_details)) {
            $record->scope_details = json_encode([
                'analysis_mode' => 'aggregated',
                'filters' => [
                    'sources' => [],
                    'participants' => [],
                ],
            ]);
        }

        // Set default AI model name if not set.
        if (!isset($record->ai_model_name)) {
            $record->ai_model_name = 'gpt-4-test';
        }

        // Set default token usage if not set.
        if (!isset($record->token_usage)) {
            $record->token_usage = 1000;
        }

        // Set default retry count if not set.
        if (!isset($record->retry_count)) {
            $record->retry_count = 0;
        }

        // Insert record.
        $record->id = $DB->insert_record('report_ai_analysis_reports', $record);

        return $record;
    }

    /**
     * Create a template.
     *
     * @param array|stdClass $record Template data
     * @return stdClass Created template
     */
    public function create_template($record = null): stdClass {
        global $DB;

        $record = (object) (array) $record;

        // Set defaults.
        if (!isset($record->title)) {
            $record->title = 'Test Template ' . time();
        }
        // Handle both 'content' (Behat) and 'prompt' (DB) fields.
        if (!isset($record->prompt)) {
            if (isset($record->content)) {
                $record->prompt = $record->content;
                unset($record->content);
            } else {
                $record->prompt = 'Test template content';
            }
        }
        if (!isset($record->sortorder)) {
            // Get highest sortorder and add 1.
            $maxsortorder = $DB->get_field_sql('SELECT MAX(sortorder) FROM {report_ai_analysis_templates}');
            $record->sortorder = $maxsortorder ? $maxsortorder + 1 : 0;
        }
        if (!isset($record->enabled)) {
            $record->enabled = 1;
        }
        if (!isset($record->timecreated)) {
            $record->timecreated = time();
        }
        if (!isset($record->timemodified)) {
            $record->timemodified = time();
        }

        // Insert record.
        $record->id = $DB->insert_record('report_ai_analysis_templates', $record);

        return $record;
    }
}
