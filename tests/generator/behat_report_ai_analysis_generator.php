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
 * Behat data generator for report_ai_analysis.
 *
 * @package    report_ai_analysis
 * @category   test
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Behat data generator class for report_ai_analysis.
 *
 * @package    report_ai_analysis
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_report_ai_analysis_generator extends behat_generator_base {
    /**
     * Get a list of the entities that can be created.
     *
     * @return array entity name => information about how to generate.
     */
    protected function get_creatable_entities(): array {
        return [
            'reports' => [
                'singular' => 'report',
                'datagenerator' => 'report',
                'required' => ['title', 'course', 'userid', 'prompt'],
                'switchids' => ['course' => 'courseid', 'userid' => 'userid'],
            ],
            'templates' => [
                'singular' => 'template',
                'datagenerator' => 'template',
                'required' => ['title', 'content'],
            ],
        ];
    }

    /**
     * Look up the id of a user from its username.
     *
     * @param string $username
     * @return int corresponding id.
     */
    protected function get_userid_id(string $username): int {
        global $DB;
        return $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
    }
}
