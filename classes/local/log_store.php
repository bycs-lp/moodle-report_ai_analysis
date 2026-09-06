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
 * Privacy cleanup of analysis requests stored by the AI manager.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_ai_analysis\local;

use local_ai_manager\ai_manager_utils;
use local_ai_manager\local\data_wiper;
use stdClass;

/**
 * Encapsulates the AI manager's public log and anonymisation APIs.
 *
 * Prompts, completions, request options and the executing user are anonymised.
 * Usage statistics, timestamps and context/item associations are retained by
 * the AI manager. Soft-deleted logs must also be included in privacy cleanup.
 *
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class log_store {
    /**
     * Anonymise every request linked to a report, regardless of the executing user.
     *
     * @param int $contextid The report's context ID
     * @param int $reportid The report ID stored as the request item ID
     */
    public static function anonymize_report(int $contextid, int $reportid): void {
        // The upstream API interprets itemid = 0 as no filter, not as an unknown report.
        if ($reportid <= 0) {
            return;
        }

        self::anonymize_records(ai_manager_utils::get_log_entries(
            'report_ai_analysis',
            $contextid,
            0,
            $reportid,
            true,
            'id'
        ));
    }

    /**
     * Anonymise only a late response belonging to an invalidated run.
     *
     * Another run can already have logged a new response with the same item ID.
     * A missing log ID must therefore never fall back to report-wide cleanup.
     *
     * @param int $contextid The report's context ID
     * @param int $reportid The report ID stored as the request item ID
     * @param int|null $logid The specific response log ID, or null when no log was created
     */
    public static function anonymize_response(int $contextid, int $reportid, ?int $logid): void {
        self::anonymize_responses($contextid, $reportid, $logid ? [$logid] : []);
    }

    /**
     * Read report linkage once and wipe only the explicitly captured response IDs.
     *
     * @param int $contextid Original context
     * @param int $reportid Original report
     * @param int[] $logids Captured response identifiers
     */
    public static function anonymize_responses(int $contextid, int $reportid, array $logids): void {
        if ($reportid <= 0 || !$logids) {
            return;
        }
        $records = ai_manager_utils::get_log_entries('report_ai_analysis', $contextid, 0, $reportid, true, 'id');
        self::anonymize_records(array_intersect_key($records, array_fill_keys($logids, true)));
    }

    /**
     * Capture report log identifiers without loading any source, completion or request options.
     *
     * @param int $contextid Original context
     * @param int $reportid Positive report identifier
     * @return int[] Log identifiers keyed by themselves
     */
    public static function get_report_log_ids(int $contextid, int $reportid): array {
        if ($reportid <= 0) {
            return [];
        }
        $ids = array_keys(ai_manager_utils::get_log_entries('report_ai_analysis', $contextid, 0, $reportid, true, 'id'));
        return array_combine($ids, $ids);
    }

    /**
     * Anonymise legacy requests which cannot be linked to a particular report.
     *
     * Only use this for deletion of legacy data in the affected context. Modern
     * linked requests in that context must not be changed by this fallback.
     *
     * @param int $contextid The approved context ID
     */
    public static function anonymize_legacy(int $contextid): void {
        $records = ai_manager_utils::get_log_entries('report_ai_analysis', $contextid, 0, 0, true, 'id,itemid');
        $records = array_filter($records, static fn(stdClass $record): bool => empty($record->itemid));
        self::anonymize_records($records);
    }

    /**
     * Anonymise all analysis requests in a deleted or fully approved context.
     *
     * @param int $contextid The context ID, never its parent or another course's context
     */
    public static function anonymize_context(int $contextid): void {
        self::anonymize_records(ai_manager_utils::get_log_entries('report_ai_analysis', $contextid, 0, 0, true, 'id'));
    }

    /**
     * Remove personal content through the public data-wiping API.
     *
     * @param stdClass[] $records Identifiers returned by the public log API
     */
    private static function anonymize_records(array $records): void {
        $datawiper = new data_wiper();
        foreach ($records as $record) {
            $datawiper->anonymize_request_log_record((object) ['id' => $record->id]);
        }
    }
}
