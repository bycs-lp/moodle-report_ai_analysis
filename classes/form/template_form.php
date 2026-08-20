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

namespace report_ai_analysis\form;

use moodleform;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for creating/editing prompt templates.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_form extends moodleform {
    /**
     * Define the form.
     */
    protected function definition() {
        $mform = $this->_form;

        // Template ID (hidden field for edit mode).
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        // Title field.
        $mform->addElement(
            'text',
            'title',
            get_string('template_title', 'report_ai_analysis'),
            ['size' => 60]
        );
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('title', 'template_title', 'report_ai_analysis');

        // Prompt textarea.
        $mform->addElement(
            'textarea',
            'prompt',
            get_string('template_prompt', 'report_ai_analysis'),
            ['rows' => 10, 'cols' => 80]
        );
        $mform->setType('prompt', PARAM_TEXT);
        $mform->addRule('prompt', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('prompt', 'template_prompt', 'report_ai_analysis');

        // Enabled checkbox.
        $mform->addElement(
            'advcheckbox',
            'enabled',
            get_string('template_enabled', 'report_ai_analysis')
        );
        $mform->setDefault('enabled', 1);
        $mform->addHelpButton('enabled', 'template_enabled', 'report_ai_analysis');

        // Action buttons.
        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Validate the form data.
     *
     * @param array $data Form data
     * @param array $files Form files
     * @return array Errors
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // Validate title length.
        if (isset($data['title'])) {
            $titlelen = strlen(trim($data['title']));
            if ($titlelen < 3) {
                $errors['title'] = get_string('error_title_too_short', 'report_ai_analysis');
            } else if ($titlelen > 100) {
                $errors['title'] = get_string('error_title_too_long', 'report_ai_analysis');
            }
        }

        // Validate prompt length.
        if (isset($data['prompt'])) {
            $promptlen = strlen(trim($data['prompt']));
            if ($promptlen < 10) {
                $errors['prompt'] = get_string('error_prompt_too_short', 'report_ai_analysis');
            } else if ($promptlen > 10000) {
                $errors['prompt'] = get_string('error_prompt_too_long', 'report_ai_analysis');
            }
        }

        return $errors;
    }
}
