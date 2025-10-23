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
 * Event for report deletion.
 *
 * @package    report_ai_analysis
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis\event;

/**
 * Event triggered when a report is deleted.
 *
 * @package    report_ai_analysis
 * @copyright  2025 PeMaSoft, Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_deleted extends \core\event\base {
    /**
     * Initialize event data.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'report_ai_analysis_reports';
    }

    /**
     * Get event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventreportdeleted', 'report_ai_analysis');
    }

    /**
     * Get event description.
     *
     * @return string
     */
    public function get_description() {
        $title = isset($this->other['title']) ? $this->other['title'] : 'Unknown';
        return "The user with id '{$this->userid}' deleted the AI analysis report with id '{$this->objectid}' " .
               "and title '{$title}'.";
    }

    /**
     * Get URL related to the event.
     *
     * @return \moodle_url
     */
    public function get_url() {
        $params = [];

        switch ($this->contextlevel) {
            case CONTEXT_COURSE:
                $params['id'] = $this->contextinstanceid;
                break;
            case CONTEXT_MODULE:
                $params['cmid'] = $this->contextinstanceid;
                break;
            case CONTEXT_COURSECAT:
                $params['categoryid'] = $this->contextinstanceid;
                break;
        }

        return new \moodle_url('/report/ai_analysis/index.php', $params);
    }

    /**
     * Validate event data.
     *
     * @return void
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->objectid)) {
            throw new \coding_exception('The \'objectid\' must be set.');
        }

        if (!isset($this->other['title'])) {
            throw new \coding_exception('The \'title\' value must be set in other.');
        }
    }
}
