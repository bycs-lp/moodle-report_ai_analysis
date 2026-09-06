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

namespace report_ai_analysis\local;

defined('BEHAT_SITE_RUNNING') || die();

/**
 * A selected source failure must not be mistaken for missing data or partial success.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_failing_log_provider extends ai_log_provider {
    /**
     * Fail a real collector read; all other source and scope code remains unchanged.
     *
     * @param string $component Consumer
     * @param int $contextid Source context
     * @param int $userid Author
     * @param int $itemid Optional thread ID
     * @param bool $includedeleted Include deleted entries
     * @param string $fields Requested fields
     * @param array $purposes Requested purposes
     * @param int $limit Requested bound
     * @return array Never returns successfully
     */
    public function get_log_entries(
        string $component,
        int $contextid,
        int $userid,
        int $itemid = 0,
        bool $includedeleted = false,
        string $fields = '*',
        array $purposes = [],
        int $limit = 0
    ): array {
        throw new \RuntimeException('Synthetic selected-source failure');
    }
}
