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
 * Manage prompt templates.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use core\di;
use report_ai_analysis\template_manager;
use report_ai_analysis\table\templates_table;

$action = optional_param('action', '', PARAM_ALPHA);
$templateid = optional_param('id', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

// Require login and system context.
require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_context($context);
$PAGE->set_url('/report/ai_analysis/manage_templates.php');
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('manage_templates', 'report_ai_analysis'));
$PAGE->set_heading(get_string('manage_templates', 'report_ai_analysis'));

$returnurl = new moodle_url('/report/ai_analysis/manage_templates.php');

// Get template manager instance via DI.
$templatemanager = di::get(template_manager::class);

// Handle actions.
if (!empty($action) && confirm_sesskey()) {
    switch ($action) {
        case 'delete':
            if ($templateid && $confirm) {
                if ($templatemanager->delete_template($templateid)) {
                    redirect(
                        $returnurl,
                        get_string('template_deleted', 'report_ai_analysis'),
                        null,
                        \core\output\notification::NOTIFY_SUCCESS
                    );
                } else {
                    redirect(
                        $returnurl,
                        get_string('error_deleting_template', 'report_ai_analysis'),
                        null,
                        \core\output\notification::NOTIFY_ERROR
                    );
                }
            } else if ($templateid) {
                // Show confirmation page.
                $template = $templatemanager->get_template($templateid);

                echo $OUTPUT->header();
                echo $OUTPUT->heading(get_string('confirm_delete_template', 'report_ai_analysis'));

                $confirmurl = new moodle_url('/report/ai_analysis/manage_templates.php', [
                    'action' => 'delete',
                    'id' => $templateid,
                    'confirm' => 1,
                    'sesskey' => sesskey(),
                ]);

                echo $OUTPUT->confirm(
                    get_string('confirm_delete_template_text', 'report_ai_analysis', s($template->title)),
                    $confirmurl,
                    $returnurl
                );

                echo $OUTPUT->footer();
                exit;
            }
            break;

        case 'move':
            $direction = required_param('direction', PARAM_ALPHA);
            if ($templateid && in_array($direction, ['up', 'down'])) {
                $templatemanager->move_template($templateid, $direction);
            }
            redirect($returnurl);
            break;

        case 'toggle':
            if ($templateid) {
                $templatemanager->toggle_enabled($templateid);
            }
            redirect($returnurl);
            break;
    }
}

// Display page.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manage_templates', 'report_ai_analysis'));

// Add new template button.
$addurl = new moodle_url('/report/ai_analysis/edit_template.php');
echo $OUTPUT->single_button(
    $addurl,
    get_string('add_template', 'report_ai_analysis'),
    'get',
    ['class' => 'mb-3']
);

// Display table.
$table = new templates_table('templates');
$table->define_baseurl($PAGE->url);
$table->out(50, false);

echo $OUTPUT->footer();
