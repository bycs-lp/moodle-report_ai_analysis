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
 * Form for creating AI analysis reports.
 *
 * @package    report_ai_analysis
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for creating new AI analysis reports.
 *
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $courseid = $this->_customdata['id'];

        // Title field.
        $mform->addElement('text', 'title', get_string('title', 'report_ai_analysis'), ['size' => 60]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addHelpButton('title', 'title', 'report_ai_analysis');

        // Prompt field (required).
        $mform->addElement('textarea', 'prompt', get_string('prompt', 'report_ai_analysis'), [
            'rows' => 10,
            'cols' => 60,
        ]);
        $mform->setType('prompt', PARAM_RAW);
        $mform->addRule('prompt', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('prompt', 'prompt', 'report_ai_analysis');

        // Scope section.
        $mform->addElement('header', 'scopeheader', get_string('scope', 'report_ai_analysis'));

        // Analysis mode selection.
        $modeselect = $mform->addElement(
            'select',
            'analysis_mode',
            get_string('analysis_mode', 'report_ai_analysis')
        );
        $modeselect->addOption(
            get_string('analysis_mode_aggregated', 'report_ai_analysis'),
            \report_ai_analysis\scope_builder::ANALYSIS_MODE_AGGREGATED
        );
        $modeselect->addOption(
            get_string('analysis_mode_individual', 'report_ai_analysis'),
            \report_ai_analysis\scope_builder::ANALYSIS_MODE_INDIVIDUAL
        );
        $mform->setDefault('analysis_mode', \report_ai_analysis\scope_builder::ANALYSIS_MODE_AGGREGATED);
        $mform->addHelpButton('analysis_mode', 'analysis_mode', 'report_ai_analysis');

        // Activity filter.
        $this->add_activity_filter($mform, $courseid);

        // Participant filter (renamed from student).
        $this->add_participant_filter($mform, $courseid);

        // Group filter.
        $this->add_group_filter($mform, $courseid);

        // Time range filter.
        $mform->addElement('date_time_selector', 'timestart', get_string('timestart', 'report_ai_analysis'), [
            'optional' => true,
        ]);
        $mform->addElement('date_time_selector', 'timeend', get_string('timeend', 'report_ai_analysis'), [
            'optional' => true,
        ]);

        // Action buttons.
        $this->add_action_buttons(true, get_string('createanalysis', 'report_ai_analysis'));
    }

    /**
     * Add activity filter.
     *
     * @param \MoodleQuickForm $mform The form
     * @param int $courseid The course ID
     */
    private function add_activity_filter(\MoodleQuickForm $mform, int $courseid) {
        global $DB;

        $sources = [];

        // Get activities in course.
        $modinfo = get_fast_modinfo($courseid);
        foreach ($modinfo->get_cms() as $cm) {
            $sources['cm_' . $cm->id] = $cm->get_formatted_name();
        }

        // Get blocks in course (only AI-relevant blocks).
        $coursecontext = \context_course::instance($courseid);
        $sql = "SELECT bi.id, bi.blockname, ctx.id as contextid
                  FROM {block_instances} bi
                  JOIN {context} ctx ON ctx.instanceid = bi.id
                                    AND ctx.contextlevel = :contextblock
                 WHERE bi.parentcontextid = :parentcontextid
                   AND bi.blockname = :blockname";

        $params = [
            'contextblock' => CONTEXT_BLOCK,
            'parentcontextid' => $coursecontext->id,
            'blockname' => 'ai_chat',
        ];

        $blocks = $DB->get_records_sql($sql, $params);
        foreach ($blocks as $block) {
            $blockname = get_string('pluginname', 'block_' . $block->blockname);
            $sources['block_' . $block->contextid] = $blockname . ' (Block)';
        }

        if (!empty($sources)) {
            $select = $mform->addElement('autocomplete', 'sources', get_string('sources', 'report_ai_analysis'), $sources);
            $select->setMultiple(true);
        }
    }

    /**
     * Add participant filter with role selection.
     *
     * @param \MoodleQuickForm $mform The form
     * @param int $courseid The course ID
     */
    private function add_participant_filter(\MoodleQuickForm $mform, int $courseid) {
        $context = \context_course::instance($courseid);

        // Role filter.
        $roles = role_fix_names(get_all_roles($context));
        if (!empty($roles)) {
            $roleoptions = [];
            foreach ($roles as $role) {
                $roleoptions[$role->id] = $role->localname;
            }

            $roleselect = $mform->addElement(
                'autocomplete',
                'roleids',
                get_string('select_roles', 'report_ai_analysis'),
                $roleoptions
            );
            $roleselect->setMultiple(true);
            $mform->addHelpButton('roleids', 'select_roles', 'report_ai_analysis');
        }

        // Get all enrolled users.
        $enrolledusers = get_enrolled_users($context, '', 0, 'u.*', null, 0, 0, true);

        $participants = [];
        foreach ($enrolledusers as $user) {
            $participants[$user->id] = fullname($user);
        }

        if (!empty($participants)) {
            // Add "All users" checkbox.
            $mform->addElement(
                'advcheckbox',
                'all_participants',
                get_string('participants', 'report_ai_analysis'),
                get_string('allusers', 'report_ai_analysis')
            );

            // Add participant selection field.
            $select = $mform->addElement(
                'autocomplete',
                'participants',
                get_string('select_participants', 'report_ai_analysis'),
                $participants
            );
            $select->setMultiple(true);
            $mform->addHelpButton('participants', 'select_participants', 'report_ai_analysis');

            // Disable participants field when "all participants" is checked.
            $mform->disabledIf('participants', 'all_participants', 'checked');

            // Warning for individual mode with multiple participants.
            $mform->addElement(
                'html',
                \html_writer::div(
                    get_string('individual_mode_warning', 'report_ai_analysis'),
                    'alert alert-warning',
                    ['id' => 'individual_mode_warning', 'style' => 'display:none;']
                )
            );
        }
    }

    /**
     * Add student filter.
     *
     * @param \MoodleQuickForm $mform The form
     * @param int $courseid The course ID
     * @deprecated Use add_participant_filter() instead
     */
    private function add_student_filter(\MoodleQuickForm $mform, int $courseid) {
        $this->add_participant_filter($mform, $courseid);
    }

    /**
     * Add group filter.
     *
     * @param \MoodleQuickForm $mform The form
     * @param int $courseid The course ID
     */
    private function add_group_filter(\MoodleQuickForm $mform, int $courseid) {
        global $DB;

        $groups = $DB->get_records_menu('groups', ['courseid' => $courseid], 'name', 'id, name');

        if (!empty($groups)) {
            $select = $mform->addElement('autocomplete', 'groups', get_string('groups', 'report_ai_analysis'), $groups);
            $select->setMultiple(true);
        }
    }

    /**
     * Validation.
     *
     * @param array $data Form data
     * @param array $files Uploaded files
     * @return array Validation errors
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // Validate prompt length.
        if (strlen($data['prompt']) < 10) {
            $errors['prompt'] = get_string('error_prompt_too_short', 'report_ai_analysis');
        }

        if (strlen($data['prompt']) > 10000) {
            $errors['prompt'] = get_string('error_prompt_too_long', 'report_ai_analysis');
        }

        return $errors;
    }
}
