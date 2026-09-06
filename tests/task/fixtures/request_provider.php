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

namespace report_ai_analysis\task;

use Closure;
use local_ai_manager\local\prompt_response;
use local_ai_manager\local\usage;
use report_ai_analysis\local\ai_request_provider;

/**
 * Isolated request boundary: no real AI configuration, connector or HTTP transport.
 *
 * Source collection, access checks, attribution, transactions and log wiping remain real.
 * Callbacks may change actual database permissions/lifecycle before the late response is logged.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class request_provider extends ai_request_provider {
    /** @var array Captured requests, including the persisted state before transmission. */
    public array $requests = [];

    /** @var int[] Context IDs supplied to the availability check. */
    public array $availabilitychecks = [];

    /** @var int[] Exact generated response log IDs. */
    public array $logids = [];

    /** @var Closure|null Callback receiving the request array and one-based request number. */
    public ?Closure $onrequest = null;

    /** @var Closure|null Callback receiving the context ID and one-based availability check number. */
    public ?Closure $onavailable = null;

    /** @var Closure|null Callback after log insertion, before the response linkage is returned. */
    public ?Closure $onlogged = null;

    /** @var bool Whether successful responses create representative real manager logs. */
    public bool $createlogs = true;

    /**
     * Replace only the AI availability boundary, not report/source permissions.
     *
     * @param int $contextid Actual report context
     */
    public function require_available(int $contextid): void {
        if (!PHPUNIT_TEST) {
            throw new \coding_exception('This request fixture is for PHPUnit only');
        }
        $this->availabilitychecks[] = $contextid;
        if ($this->onavailable) {
            ($this->onavailable)($contextid, count($this->availabilitychecks));
        }
    }

    /**
     * Capture a worker request and optionally write a real, exactly linked synthetic manager log.
     *
     * @param string $purpose Purpose name
     * @param string $prompt Complete bounded prompt
     * @param string $component Consumer component
     * @param int $contextid Actual report context
     * @param array $options Public request options
     * @return prompt_response Response with an explicitly initialised log ID
     */
    public function perform_request(
        string $purpose,
        string $prompt,
        string $component,
        int $contextid,
        array $options = []
    ): prompt_response {
        global $DB, $USER;

        if (!PHPUNIT_TEST) {
            throw new \coding_exception('This request fixture is for PHPUnit only');
        }
        $reportid = (int) ($options['itemid'] ?? 0);
        $subjects = [];
        foreach ($DB->get_records('report_ai_analysis_users', ['reportid' => $reportid], 'userid') as $subject) {
            $subjects[(int) $subject->userid] = $subject;
        }
        $request = [
            'purpose' => $purpose,
            'prompt' => $prompt,
            'component' => $component,
            'contextid' => $contextid,
            'userid' => (int) $USER->id,
            'options' => $options,
            'subjects' => $subjects,
            'report' => $DB->get_record('report_ai_analysis_reports', ['id' => $reportid]),
            'transaction' => $DB->is_transaction_started(),
        ];
        $this->requests[] = $request;
        $number = count($this->requests);
        $response = $this->onrequest ? ($this->onrequest)($request, $number) : self::success('<p>Result ' . $number . '</p>');
        if ($this->createlogs && $response->get_code() === 200) {
            /** @var \local_ai_manager_generator $generator */
            $generator = \testing_util::get_data_generator()->get_plugin_generator('local_ai_manager');
            $log = $generator->create_request_log_entry([
                'userid' => $request['userid'],
                'component' => $component,
                'contextid' => $contextid,
                'coursecontextid' => $contextid,
                'purpose' => $purpose,
                'itemid' => $reportid,
                'prompttext' => $prompt,
                'promptcompletion' => $response->get_content(),
                'requestoptions' => json_encode($options, JSON_THROW_ON_ERROR),
                'value' => 12,
                'customvalue1' => 8,
                'customvalue2' => 4,
            ]);
            $this->logids[] = (int) $log->id;
            if ($this->onlogged) {
                ($this->onlogged)($log);
            }
            $response->set_logrecordid((int) $log->id);
        }
        return $response;
    }

    /**
     * Create a purpose-formatted successful response with all optional fixture fields initialised.
     *
     * @param string $html Already formatted HTML
     * @param string $model Response model name
     * @param float $tokens Deliberately float-typed as in the manager API
     * @return prompt_response Synthetic response
     */
    public static function success(string $html, string $model = 'fixture-model', float $tokens = 12.0): prompt_response {
        $response = prompt_response::create_from_result($model, new usage($tokens, 8, 4), $html);
        $response->set_logrecordid(0);
        $response->set_debuginfo('');
        return $response;
    }

    /**
     * Create a typed failure without leaving the manager's optional log ID uninitialised.
     *
     * @param int $code HTTP-like response code
     * @param string $message Exact manager error message or harmless synthetic diagnostic
     * @return prompt_response Synthetic failure
     */
    public static function failure(int $code, string $message = 'Synthetic connector failure'): prompt_response {
        $response = prompt_response::create_from_error($code, $message, 'Private connector diagnostics');
        $response->set_logrecordid(0);
        return $response;
    }
}
