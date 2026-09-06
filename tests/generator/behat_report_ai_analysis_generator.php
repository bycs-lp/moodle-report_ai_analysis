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
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Behat data generator class for report_ai_analysis.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
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
                'required' => ['title', 'course', 'user', 'prompt'],
                'switchids' => ['course' => 'courseid', 'user' => 'userid', 'taskuser' => 'taskuserid'],
            ],
            'subjects' => [
                'singular' => 'subject',
                'datagenerator' => 'subject',
                'required' => ['report', 'user'],
                'switchids' => ['report' => 'reportid', 'user' => 'userid'],
            ],
            'chat entries' => [
                'singular' => 'chat entry',
                'datagenerator' => 'chat_entry',
                'required' => ['course', 'user', 'prompttext'],
                'switchids' => ['course' => 'courseid', 'user' => 'userid'],
            ],
            'templates' => [
                'singular' => 'template',
                'datagenerator' => 'template',
                'required' => ['title', 'content'],
            ],
        ];
    }

    /**
     * Resolve a report title, rejecting ambiguous fixtures rather than guessing an ID.
     *
     * @param string $title Report title
     * @return int Report ID
     */
    protected function get_report_id(string $title): int {
        global $DB;
        return (int) $DB->get_field('report_ai_analysis_reports', 'id', ['title' => $title], MUST_EXIST);
    }

    /**
     * Resolve the requested task actor independently of report ownership.
     *
     * @param string $username Username
     * @return int User ID
     */
    protected function get_taskuser_id(string $username): int {
        return (int) $this->get_user_id($username);
    }

    /**
     * Translate readable scope fixtures without hardcoded course, user, group or module IDs.
     *
     * @param array $data Fixture fields after the standard ID switches
     * @return array Database fixture fields
     */
    protected function preprocess_report(array $data): array {
        global $DB;

        if (!isset($data['scope_details'])) {
            $scope = new \report_ai_analysis\scope_builder((int) $data['courseid']);
            $scope->set_analysis_mode($data['analysis_mode'] ?? 'aggregated');
            if (array_key_exists('participants', $data)) {
                $scope->filter_by_participants(array_map(
                    fn($name) => (int) $this->get_user_id($name),
                    $this->split_names($data['participants'])
                ));
            }
            if (!empty($data['roles'])) {
                $scope->filter_by_roles(array_map(fn($name) => (int) $DB->get_field(
                    'role',
                    'id',
                    ['shortname' => $name],
                    MUST_EXIST
                ), $this->split_names($data['roles'])));
            }
            if (!empty($data['groups'])) {
                $scope->with_groups(array_map(fn($name) => (int) $this->get_group_id($name), $this->split_names($data['groups'])));
            }
            if (!empty($data['sources'])) {
                $sources = [];
                foreach ($this->split_names($data['sources']) as $source) {
                    if ($source === 'chat') {
                        $blockid = $DB->get_field('block_instances', 'id', [
                            'parentcontextid' => context_course::instance((int) $data['courseid'])->id,
                            'blockname' => 'ai_chat',
                        ], MUST_EXIST);
                        $sources[] = 'block_' . context_block::instance($blockid)->id;
                    } else {
                        $sources[] = 'cm_' . $DB->get_field('course_modules', 'id', [
                            'course' => $data['courseid'], 'idnumber' => $source,
                        ], MUST_EXIST);
                    }
                }
                $scope->with_sources($sources);
            }
            if (isset($data['timestart']) || isset($data['timeend'])) {
                $scope->with_timerange((int) ($data['timestart'] ?? 0), (int) ($data['timeend'] ?? 0));
            }
            $data['scope_details'] = $scope->build();
        }
        unset(
            $data['analysis_mode'],
            $data['participants'],
            $data['roles'],
            $data['groups'],
            $data['sources'],
            $data['timestart'],
            $data['timeend']
        );
        return $data;
    }

    /**
     * Split an optional comma-separated fixture selection, keeping an empty selection empty.
     *
     * @param string $names Human-readable names
     * @return string[] Nonempty names
     */
    private function split_names(string $names): array {
        return array_values(array_filter(array_map('trim', explode(',', $names)), static fn($name) => $name !== ''));
    }
}
