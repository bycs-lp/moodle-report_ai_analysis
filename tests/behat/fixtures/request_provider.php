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

use local_ai_manager\local\prompt_response;
use local_ai_manager\local\usage;

defined('BEHAT_SITE_RUNNING') || die();

/**
 * In-process Behat request fixture; never registered in production or an HTTP request.
 *
 * @package    report_ai_analysis
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_request_provider extends ai_request_provider {
    /** @var array Captured requests from this one synchronous task execution. */
    public array $requests = [];

    /**
     * Configure the response and an optional mutation during the simulated request.
     *
     * @param int $reportid Expected report linkage
     * @param int $responsecode HTTP-like code, or zero to throw an untyped exception
     * @param string $content Response HTML or exception text
     * @param \Closure|null $duringrequest Callback after the synthetic log write, before returning
     */
    public function __construct(
        /** @var int Expected report linkage */
        private readonly int $reportid,
        /** @var int HTTP-like code, or zero to throw an untyped exception */
        private readonly int $responsecode,
        /** @var string Response HTML or exception text */
        private readonly string $content,
        /** @var \Closure|null Callback after the synthetic log write, before returning */
        private readonly ?\Closure $duringrequest = null
    ) {
    }

    /**
     * Capture a real worker call and return a typed response without any HTTP connection.
     *
     * Successful calls create representative logs through the actual AI Manager generator.
     * This tests report cleanup, not the AI Manager's own request/logging implementation.
     *
     * @param string $purpose Purpose name
     * @param string $prompt Complete final prompt
     * @param string $component Consumer component
     * @param int $contextid Request context
     * @param array $options Supported manager options, including itemid
     * @return prompt_response Synthetic response
     */
    public function perform_request(
        string $purpose,
        string $prompt,
        string $component,
        int $contextid,
        array $options = []
    ): prompt_response {
        global $USER;

        $this->requests[] = [
            'purpose' => $purpose,
            'prompt' => $prompt,
            'component' => $component,
            'contextid' => $contextid,
            'userid' => (int) $USER->id,
            'options' => $options,
        ];
        if (
            $purpose !== 'singleprompt' || $component !== 'report_ai_analysis' ||
                (int) ($options['itemid'] ?? 0) !== $this->reportid || array_diff(array_keys($options), ['itemid'])
        ) {
            throw new \coding_exception('The worker must use singleprompt and the report itemid, without unsupported options');
        }
        if ($this->responsecode === 0) {
            throw new \RuntimeException($this->content);
        }
        if ($this->responsecode !== 200) {
            $message = $this->responsecode === 403
                ? get_string('error_http403notconfirmed', 'local_ai_manager') : 'Synthetic service failure';
            return prompt_response::create_from_error($this->responsecode, $message, 'Synthetic connector diagnostics');
        }

        $content = str_replace('{request}', (string) count($this->requests), $this->content);
        $response = prompt_response::create_from_result('behat-model', new usage(12, 8, 4), $content);
        /** @var \local_ai_manager_generator $generator */
        $generator = \testing_util::get_data_generator()->get_plugin_generator('local_ai_manager');
        $log = $generator->create_request_log_entry([
            'userid' => $USER->id,
            'component' => $component,
            'contextid' => $contextid,
            'coursecontextid' => $contextid,
            'purpose' => $purpose,
            'itemid' => $this->reportid,
            'prompttext' => $prompt,
            'promptcompletion' => $content,
            'requestoptions' => json_encode($options, JSON_THROW_ON_ERROR),
            'value' => 12,
            'customvalue1' => 8,
            'customvalue2' => 4,
        ]);
        $response->set_logrecordid((int) $log->id);
        if ($this->duringrequest !== null) {
            ($this->duringrequest)();
        }
        return $response;
    }
}
