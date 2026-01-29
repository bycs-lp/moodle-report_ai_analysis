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
 * Edit or create a prompt template.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use core\di;
use report_ai_analysis\form\template_form;
use report_ai_analysis\template_manager;

$templateid = optional_param('id', 0, PARAM_INT);

// Require login and system context.
require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');

$returnurl = new moodle_url('/report/ai_analysis/manage_templates.php');

// Get template manager instance via DI.
$templatemanager = di::get(template_manager::class);

$template = null;
$pagetitle = get_string('add_template', 'report_ai_analysis');

if ($templateid) {
    $template = $templatemanager->get_template($templateid);
    $pagetitle = get_string('edit_template', 'report_ai_analysis');
}

$PAGE->set_url('/report/ai_analysis/edit_template.php', ['id' => $templateid]);
$PAGE->set_title($pagetitle);
$PAGE->set_heading($pagetitle);

$mform = new template_form(null, ['id' => $templateid]);

if ($mform->is_cancelled()) {
    redirect($returnurl);
} else if ($data = $mform->get_data()) {
    try {
        $templateid = $templatemanager->save_template($data);

        $message = empty($data->id) ?
            get_string('template_created', 'report_ai_analysis') :
            get_string('template_updated', 'report_ai_analysis');

        redirect($returnurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (\Exception $e) {
        redirect(
            $returnurl,
            get_string('error_saving_template', 'report_ai_analysis'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

if ($template) {
    $mform->set_data($template);
}

echo $OUTPUT->header();
echo $OUTPUT->heading($pagetitle);
$mform->display();
echo $OUTPUT->footer();
