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
 * Settings for report_ai_analysis.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use report_ai_analysis\local\admin_setting_positive_int;

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // System prompt.
    $settings->add(new admin_setting_configtextarea(
        'report_ai_analysis/system_prompt',
        get_string('system_prompt', 'report_ai_analysis'),
        get_string('system_prompt_desc', 'report_ai_analysis'),
        get_string('system_prompt_default', 'report_ai_analysis'),
        PARAM_TEXT
    ));

    // Max records per analysis.
    $settings->add(new admin_setting_positive_int(
        'report_ai_analysis/max_records_per_analysis',
        get_string('max_records_per_analysis', 'report_ai_analysis'),
        get_string('max_records_per_analysis_desc', 'report_ai_analysis'),
        1000,
        10000
    ));

    // Store raw data.
    $settings->add(new admin_setting_configcheckbox(
        'report_ai_analysis/store_raw_data',
        get_string('store_raw_data', 'report_ai_analysis'),
        get_string('store_raw_data_desc', 'report_ai_analysis'),
        1
    ));

    // Truncate raw data length.
    $settings->add(new admin_setting_positive_int(
        'report_ai_analysis/truncate_raw_data_length',
        get_string('truncate_raw_data_length', 'report_ai_analysis'),
        get_string('truncate_raw_data_length_desc', 'report_ai_analysis'),
        50000,
        500000
    ));

    // Retry on failure.
    $settings->add(new admin_setting_configselect(
        'report_ai_analysis/retry_on_failure',
        get_string('retry_on_failure', 'report_ai_analysis'),
        get_string('retry_on_failure_desc', 'report_ai_analysis'),
        2,
        [
            0 => '0',
            1 => '1',
            2 => '2',
            3 => '3',
        ]
    ));

    // Share reports in course.
    $settings->add(new admin_setting_configcheckbox(
        'report_ai_analysis/share_reports_in_course',
        get_string('share_reports_in_course', 'report_ai_analysis'),
        get_string('share_reports_in_course_desc', 'report_ai_analysis'),
        0
    ));

    // Enable markdown conversion.
    $settings->add(new admin_setting_configcheckbox(
        'report_ai_analysis/enable_markdown_conversion',
        get_string('enable_markdown_conversion', 'report_ai_analysis'),
        get_string('enable_markdown_conversion_desc', 'report_ai_analysis'),
        1
    ));

    // Link to manage prompt templates.
    $templatesurl = new moodle_url('/report/ai_analysis/manage_templates.php');
    $templateslink = html_writer::link($templatesurl, get_string('manage_templates', 'report_ai_analysis'));
    $settings->add(new admin_setting_heading(
        'report_ai_analysis/prompt_templates_heading',
        get_string('prompt_templates', 'report_ai_analysis'),
        $templateslink
    ));
}
