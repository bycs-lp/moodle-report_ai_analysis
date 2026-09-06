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
     * @param array|stdClass|null $record Report data, with optional subjects and queue_task fixture controls
     * @return stdClass Created report
     */
    public function create_report(array|stdClass|null $record = null): stdClass {
        global $DB;

        $data = (array) $record;
        if (!isset($data['userid'])) {
            throw new coding_exception('userid must be provided');
        }
        if (isset($data['contextid'])) {
            // Explicit contexts are allowed for negative API fixtures, not for production saves.
            $context = context::instance_by_id((int) $data['contextid']);
            $courseid = $context->contextlevel === CONTEXT_COURSE ? (int) $context->instanceid : 0;
        } else {
            $courseid = $data['courseid'] ?? $DB->get_field('course', 'id', ['shortname' => $data['course'] ?? ''], MUST_EXIST);
            $data['contextid'] = context_course::instance((int) $courseid)->id;
        }

        $now = time();
        $data += ['title' => 'Test Report ' . ($DB->count_records('report_ai_analysis_reports') + 1), 'status' => 'completed'];
        $completed = $data['status'] === 'completed';
        $data += [
            'prompt' => 'Test analysis prompt',
            'scope_details' => json_encode([
                'courseid' => (int) $courseid,
                'analysis_mode' => 'aggregated',
                'filters' => new stdClass(),
            ], JSON_THROW_ON_ERROR),
            'ai_result' => $completed ? '<p>Test AI result for ' . s($data['title']) . '</p>' : null,
            'raw_data' => $completed ? 'Test source data' : null,
            'ai_model_name' => $completed ? 'behat-model' : null,
            'token_usage' => $completed ? 100 : null,
            'execution_time' => 0,
            'retry_count' => 0,
            'runversion' => 1,
            'action' => 'create',
            'resultformat' => FORMAT_HTML,
            'truncated' => 0,
            'legacydata' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'timecompleted' => $completed ? $now : null,
        ];
        $subjects = $data['subjects'] ?? [];
        $queue = !empty($data['queue_task']);
        $taskuserid = (int) ($data['taskuserid'] ?? $data['userid']);
        unset($data['course'], $data['courseid'], $data['subjects'], $data['queue_task'], $data['taskuserid']);
        $id = $DB->insert_record('report_ai_analysis_reports', (object) $data);

        // Never infer analysed subjects from today's enrolments or from a scope selection.
        foreach ($subjects as $userid) {
            $this->create_subject(['reportid' => $id, 'userid' => $userid]);
        }
        if ($queue) {
            $task = new \report_ai_analysis\task\process_analysis_task();
            $task->set_custom_data((object) ['reportid' => $id, 'runversion' => (int) $data['runversion']]);
            $task->set_userid($taskuserid);
            \core\task\manager::queue_adhoc_task($task);
        }

        return $DB->get_record('report_ai_analysis_reports', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Create an explicit report/data-subject association, preserving nullable data.
     *
     * @param array|stdClass $record Report ID, user ID and optional separately attributable data
     * @return stdClass Stored association
     */
    public function create_subject(array|stdClass $record): stdClass {
        global $DB;

        $data = (array) $record + ['source_data' => null, 'ai_result' => null];
        $DB->get_record('report_ai_analysis_reports', ['id' => $data['reportid']], 'id', MUST_EXIST);
        $DB->get_record('user', ['id' => $data['userid']], 'id', MUST_EXIST);
        $id = $DB->insert_record('report_ai_analysis_users', (object) $data);
        return $DB->get_record('report_ai_analysis_users', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Create a course-owned AI Chat source using the real AI Manager log generator.
     *
     * @param array|stdClass $record Course/user IDs, prompttext and optional log fields
     * @return stdClass Generated log entry
     */
    public function create_chat_entry(array|stdClass $record): stdClass {
        global $DB;

        $data = (array) $record;
        $context = context_course::instance((int) $data['courseid']);
        $block = $DB->get_record('block_instances', ['parentcontextid' => $context->id, 'blockname' => 'ai_chat']);
        if (!$block) {
            $block = $this->datagenerator->create_block('ai_chat', ['parentcontextid' => $context->id]);
        }
        unset($data['courseid']);
        $data += [
            'contextid' => context_block::instance($block->id)->id,
            'coursecontextid' => $context->id,
            'component' => 'block_ai_chat',
            'purpose' => 'chat',
            'itemid' => 0,
            'promptcompletion' => 'Synthetic chat reply',
            'requestoptions' => '{}',
        ];
        /** @var local_ai_manager_generator $generator */
        $generator = $this->datagenerator->get_plugin_generator('local_ai_manager');
        return $generator->create_request_log_entry($data);
    }

    /**
     * Create a template.
     *
     * @param array|stdClass|null $record Template data
     * @return stdClass Created template
     */
    public function create_template(array|stdClass|null $record = null): stdClass {
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
            $record->sortorder = $maxsortorder === null ? 0 : (int) $maxsortorder + 1;
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
