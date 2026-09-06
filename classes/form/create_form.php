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
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis\form;

use core\di;
use report_ai_analysis\output\ai_availability;
use report_ai_analysis\scope_builder;
use report_ai_analysis\template_manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for creating new AI analysis reports.
 *
 * Refactored to receive template_manager via customdata for better testability
 * and Dependency Injection support.
 *
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_form extends \moodleform {
    /** @var array Supported sources accessible to the current user. */
    private array $sources = [];

    /** @var array Effective AI availability, provided by the server only. */
    private array $availability;

    /**
     * Form definition.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $courseid = (int) $this->_customdata['id'];
        $context = \context_course::instance($courseid);
        $this->availability = $this->_customdata['availability']
            ?? di::get(ai_availability::class)->get_availability($context);

        if ($this->availability['state'] === 'hidden') {
            $mform->addElement('cancel');
            return;
        }

        // Title field.
        $mform->addElement('text', 'title', get_string('title', 'report_ai_analysis'), ['size' => 60, 'maxlength' => 255]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addHelpButton('title', 'title', 'report_ai_analysis');

        // Load and display prompt templates.
        $this->add_prompt_templates($mform);

        // Prompt field (required).
        $mform->addElement('textarea', 'prompt', get_string('prompt', 'report_ai_analysis'), [
            'rows' => 10,
            'cols' => 60,
            'id' => 'id_prompt',
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
            scope_builder::ANALYSIS_MODE_AGGREGATED
        );
        $modeselect->addOption(
            get_string('analysis_mode_individual', 'report_ai_analysis'),
            scope_builder::ANALYSIS_MODE_INDIVIDUAL
        );
        $mform->setDefault('analysis_mode', scope_builder::ANALYSIS_MODE_AGGREGATED);
        $mform->setType('analysis_mode', PARAM_ALPHA);
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
        $mform->addElement('static', 'timerangenote', '', get_string('timerange_help', 'report_ai_analysis'));

        // Action buttons.
        $submitlabel = empty($this->_customdata['reportid']) ? 'createanalysis' : 'editreport';
        $this->add_action_buttons(true, get_string($submitlabel, 'report_ai_analysis'));
        if ($this->availability['state'] !== 'available') {
            foreach ($mform->_elements as $element) {
                $this->disable_element($element);
            }
        }
    }

    /**
     * Add activity filter.
     *
     * @param \MoodleQuickForm $mform The form
     * @param int $courseid The course ID
     */
    private function add_activity_filter(\MoodleQuickForm $mform, int $courseid): void {
        $scope = new scope_builder($courseid);
        $modinfo = get_fast_modinfo($courseid);
        $cms = $modinfo->get_cms();
        foreach ($scope->get_activities_in_scope() as $cmid) {
            $cm = $cms[$cmid] ?? null;
            if ($cm && $cm->modname === 'forum' && $cm->uservisible && has_capability('mod/forum:viewdiscussion', $cm->context)) {
                $this->sources['cm_' . $cm->id] = $cm->get_formatted_name();
            }
        }

        // The scope resolves supported, authorized AI chat block contexts.
        foreach ($scope->get_block_contexts_in_scope() as $contextid) {
            $blockcontext = \context::instance_by_id($contextid);
            if (has_capability('moodle/block:view', $blockcontext)) {
                $this->sources['block_' . $contextid] = get_string('pluginname', 'block_ai_chat');
            }
        }

        if ($this->sources) {
            $select = $mform->addElement('autocomplete', 'sources', get_string('sources', 'report_ai_analysis'), $this->sources);
            $select->setMultiple(true);
            $mform->setType('sources', PARAM_ALPHANUMEXT);
        }
    }

    /**
     * Add participant filter with role selection.
     *
     * @param \MoodleQuickForm $mform The form
     * @param int $courseid The course ID
     */
    private function add_participant_filter(\MoodleQuickForm $mform, int $courseid): void {
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
            $mform->setType('roleids', PARAM_INT);
            $mform->addHelpButton('roleids', 'select_roles', 'report_ai_analysis');
        }

        // Keep the selector within the same participant boundary used by the collector.
        $scope = new scope_builder($courseid);
        $allowedids = array_fill_keys($scope->get_participants_in_scope(), true);
        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $enrolledusers = get_enrolled_users($context, '', 0, 'u.id, ' . $namefields, null, 0, 0, true);

        $participants = [];
        foreach ($enrolledusers as $user) {
            if (isset($allowedids[$user->id])) {
                $participants[$user->id] = s(fullname($user));
            }
        }

        $mform->addElement(
            'advcheckbox',
            'all_participants',
            get_string('participants', 'report_ai_analysis'),
            get_string('allusers', 'report_ai_analysis')
        );
        $mform->setType('all_participants', PARAM_BOOL);
        $mform->setDefault('all_participants', 1);

        if (!empty($participants)) {
            // Add participant selection field.
            $select = $mform->addElement(
                'autocomplete',
                'participants',
                get_string('select_participants', 'report_ai_analysis'),
                $participants
            );
            $select->setMultiple(true);
            $mform->setType('participants', PARAM_INT);
            $mform->addHelpButton('participants', 'select_participants', 'report_ai_analysis');

            // Disable participants field when "all participants" is checked.
            $mform->disabledIf('participants', 'all_participants', 'checked');
        }
        $mform->addElement('html', \html_writer::div(
            get_string('individual_mode_warning', 'report_ai_analysis'),
            'alert alert-warning',
            ['id' => 'individual_mode_warning']
        ));
    }

    /**
     * Add group filter.
     *
     * @param \MoodleQuickForm $mform The form
     * @param int $courseid The course ID
     */
    private function add_group_filter(\MoodleQuickForm $mform, int $courseid): void {
        global $DB;

        $groups = $DB->get_records_menu('groups', ['courseid' => $courseid], 'name', 'id, name');

        if (!empty($groups)) {
            $select = $mform->addElement('autocomplete', 'groups', get_string('groups', 'report_ai_analysis'), $groups);
            $select->setMultiple(true);
            $mform->setType('groups', PARAM_INT);
        }
    }

    /**
     * Add prompt templates section.
     *
     * Uses template_manager from customdata for DI support, or creates one via DI.
     *
     * @param \MoodleQuickForm $mform The form.
     */
    private function add_prompt_templates(\MoodleQuickForm $mform): void {
        global $PAGE;

        // Get template manager from customdata or create via DI.
        $templatemanager = $this->_customdata['templatemanager'] ?? di::get(template_manager::class);

        // Load enabled templates from database.
        $templates = $templatemanager->get_enabled_templates();

        if (empty($templates)) {
            return;
        }

        $mform->addElement('header', 'templateheader', get_string('prompt_templates', 'report_ai_analysis'));

        $templatecount = count($templates);

        // Decide UI based on number of templates.
        if ($templatecount <= 5) {
            // Button variant for up to 5 templates.
            $buttons = [];
            foreach ($templates as $template) {
                $buttons[] = \html_writer::tag(
                    'button',
                    s($template->title),
                    [
                        'type' => 'button',
                        'class' => 'btn btn-secondary btn-sm m-1 prompt-template-btn',
                        'data-prompt' => $template->prompt,
                        'disabled' => $this->availability['state'] !== 'available' ? 'disabled' : null,
                    ]
                );
            }

            $mform->addElement(
                'html',
                \html_writer::div(
                    implode('', $buttons),
                    'prompt-templates-buttons mb-3'
                )
            );
        } else {
            // Select dropdown for more than 5 templates.
            $options = ['' => get_string('select_template', 'report_ai_analysis')];
            $select = $mform->addElement(
                'select',
                'template_selector',
                get_string('use_template', 'report_ai_analysis'),
                $options,
                ['id' => 'id_template_selector', 'class' => 'mb-3']
            );
            foreach ($templates as $template) {
                $select->addOption(s($template->title), $template->id, ['data-prompt' => $template->prompt]);
            }
            $mform->setType('template_selector', PARAM_INT);

            $PAGE->requires->js_call_amd('report_ai_analysis/prompt_templates', 'init');
        }

        // Initialize JavaScript for button variant.
        if ($templatecount <= 5) {
            $PAGE->requires->js_call_amd('report_ai_analysis/prompt_templates', 'initButtons');
        }
    }

    /**
     * Validation.
     *
     * @param array $data Form data
     * @param array $files Uploaded files
     * @return array Validation errors
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if ($this->availability['state'] === 'hidden') {
            return $errors;
        }

        if (\core_text::strlen($data['title'] ?? '') > 255) {
            $errors['title'] = get_string('error_title_too_long', 'report_ai_analysis');
        }
        $promptlength = \core_text::strlen($data['prompt'] ?? '');
        if ($promptlength < 10) {
            $errors['prompt'] = get_string('error_prompt_too_short', 'report_ai_analysis');
        }

        if ($promptlength > 10000) {
            $errors['prompt'] = get_string('error_prompt_too_long', 'report_ai_analysis');
        }
        if (!empty($data['timestart']) && !empty($data['timeend']) && $data['timeend'] < $data['timestart']) {
            $errors['timeend'] = get_string('error_invalid_timerange', 'report_ai_analysis');
        }
        if (array_diff($data['sources'] ?? [], array_keys($this->sources))) {
            $errors['sources'] = get_string('nopermission', 'report_ai_analysis');
        }
        if ($this->availability['state'] !== 'available') {
            $errors['prompt'] = get_string('aiunavailable', 'report_ai_analysis');
        }

        return $errors;
    }

    /**
     * Return validated data with a Unicode-safe automatic title.
     *
     * @return \stdClass|null Validated data, or null when unavailable or invalid
     */
    public function get_data(): ?\stdClass {
        if ($this->availability['state'] !== 'available') {
            return null;
        }
        $data = parent::get_data();
        if ($data !== null && trim($data->title) === '') {
            $data->title = \core_text::substr($data->prompt, 0, 80);
        }
        return $data;
    }

    /**
     * Build the scope from validated form data without resolving away role restrictions.
     *
     * @param \stdClass $data Validated form data
     * @return scope_builder Scope for the report manager
     */
    public function get_scope(\stdClass $data): scope_builder {
        $scope = new scope_builder((int) $this->_customdata['id']);
        $scope->set_analysis_mode($data->analysis_mode ?? scope_builder::ANALYSIS_MODE_AGGREGATED);
        if (!empty($data->sources)) {
            $scope->with_sources($data->sources);
        }
        $roles = $data->roleids ?? [];
        if (empty($data->all_participants)) {
            // An explicitly empty selection must remain empty, never become "all".
            $scope->filter_by_participants($data->participants ?? [], $roles);
        } else if ($roles) {
            $scope->filter_by_roles($roles);
        }
        if (!empty($data->groups)) {
            $scope->with_groups($data->groups);
        }
        $start = (int) ($data->timestart ?? 0);
        $end = (int) ($data->timeend ?? 0);
        if ($start || $end) {
            $scope->with_timerange($start, $end);
        }
        return $scope;
    }

    /**
     * Restore edit defaults, preserving roles and independently optional dates.
     *
     * @param \stdClass $report Stored report
     * @return \stdClass Form defaults
     */
    public static function get_initial_data(\stdClass $report): \stdClass {
        $data = (object) [
            'title' => $report->title,
            'prompt' => $report->prompt,
            'analysis_mode' => scope_builder::ANALYSIS_MODE_AGGREGATED,
            'all_participants' => 1,
            'timestart' => 0,
            'timeend' => 0,
        ];
        if (empty($report->scope_details)) {
            return $data;
        }
        $scope = scope_builder::parse($report->scope_details);
        $filters = $scope->filters ?? new \stdClass();
        $data->analysis_mode = $scope->analysis_mode ?? scope_builder::ANALYSIS_MODE_AGGREGATED;
        $data->sources = $filters->sources ?? [];
        $data->roleids = $filters->roles ?? [];
        $data->groups = $filters->groups ?? [];
        if (isset($filters->participants) || isset($filters->students)) {
            $data->participants = $filters->participants ?? $filters->students;
            $data->all_participants = 0;
        }
        $data->timestart = (int) ($filters->timerange->start ?? 0);
        $data->timeend = (int) ($filters->timerange->end ?? 0);
        return $data;
    }

    /**
     * Disable native controls, including date and action groups, but retain Cancel.
     *
     * @param \HTML_QuickForm_element $element Form element
     */
    private function disable_element(\HTML_QuickForm_element $element): void {
        if ($element instanceof \MoodleQuickForm_cancel) {
            return;
        }
        if ($element instanceof \HTML_QuickForm_group) {
            foreach ($element->getElements() as $child) {
                $this->disable_element($child);
            }
        } else if (!in_array($element->getType(), ['cancel', 'hidden', 'html', 'header', 'static'], true)) {
            $element->updateAttributes(['disabled' => 'disabled']);
        }
    }
}
