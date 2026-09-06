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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace report_ai_analysis\local;

use report_ai_analysis\error_info;

/**
 * Tests safe, authorized and format-aware export presentation.
 *
 * @package    report_ai_analysis
 * @category   test
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(report_exporter::class)]
final class report_exporter_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \context Course context. */
    private \context $context;

    /**
     * Explicitly prepare the report context needed by the test, without AI requests.
     */
    private function prepare_test(): void {
        global $CFG, $PAGE, $OUTPUT;

        // A fresh page lets a single scenario test render more than one export.
        $PAGE = new \moodle_page();
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->forceclean = false;
        $this->course = $this->getDataGenerator()->create_course(['fullname' => 'Kurs Ä & B']);
        $this->context = \context_course::instance($this->course->id);
        $PAGE->set_course($this->course);
        $PAGE->set_url('/report/ai_analysis/export.php');
        $OUTPUT = $PAGE->get_renderer('core');
        set_config('enable_markdown_conversion', 1, 'report_ai_analysis');
        set_config('share_reports_in_course', 0, 'report_ai_analysis');
    }

    /**
     * Release retained fixture records and contexts after each test.
     */
    protected function tearDown(): void {
        unset($this->course, $this->context);
        parent::tearDown();
    }

    /**
     * Create a report with the new persisted format fields.
     *
     * @param array $overrides Report fields
     * @return \stdClass
     */
    private function create_report(array $overrides = []): \stdClass {
        global $USER;

        /** @var \report_ai_analysis_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('report_ai_analysis');
        return $generator->create_report($overrides + [
            'contextid' => $this->context->id,
            'userid' => $USER->id,
            'title' => 'Analyse Ä & B',
            'prompt' => '**Analyze** this discussion.',
            'ai_result' => '<p>A safe result.</p>',
            'resultformat' => FORMAT_HTML,
            'truncated' => 0,
        ]);
    }

    /**
     * Safe localized presentation, stored-format fidelity, debug gating and access guards for exports.
     */
    public function test_export_presentation_formatting_gates_and_guards(): void {
        global $CFG, $DB, $OUTPUT;

        // Standalone export is cleaned of active markup and localized across the required languages.
        foreach (self::languages_provider() as [$language]) {
            $this->prepare_test();
            force_current_language($language);
            $report = $this->create_report([
                'prompt' => '**Safe prompt** <img src="x" onerror="promptProbe()"><script>promptScriptProbe()</script>',
                'ai_result' => '<p>Safe result</p><img src="x" onerror="resultProbe()"><script>resultScriptProbe()</script>',
                'raw_data' => '<img src="x" onerror="rawProbe()">',
                'truncated' => 1,
                'scope_details' => json_encode([
                    'analysis_mode' => 'individual',
                    'filters' => ['sources' => ['cm_1'], 'participants' => [], 'timerange' => ['start' => 1788678000, 'end' => 0]],
                ]),
            ]);
            $exporter = new report_exporter($report, $this->context);

            // JSON keeps safe formatting for prompt and result while stripping every active probe.
            $data = json_decode($exporter->get_json(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertStringContainsString('<strong>Safe prompt</strong>', $data['prompt']);
            $this->assertStringContainsString('<p>Safe result</p>', $data['ai_result']);
            foreach (['promptProbe', 'promptScriptProbe', 'resultProbe', 'resultScriptProbe'] as $probe) {
                $this->assertStringNotContainsString($probe, $data['prompt'] . $data['ai_result']);
            }
            $this->assertSame(FORMAT_HTML, $data['resultformat']);

            // Standalone HTML export escapes raw data, localizes metadata and keeps the AI warning.
            $template = $exporter->get_template_data();
            $this->assertSame('Kurs Ä & B', $template->contextname);
            $this->assertTrue($template->truncated);
            $html = $exporter->get_html($OUTPUT);
            $this->assertStringContainsString(s($report->raw_data), $html);
            $document = new \DOMDocument();
            $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $this->assertCount(0, (new \DOMXPath($document))->query('//*[@onerror] | //script'));
            foreach (['report_creator', 'coursename', 'analysis_result', 'analysis_truncated'] as $key) {
                $this->assertStringContainsString(get_string($key, 'report_ai_analysis'), $html);
            }
            $this->assertStringContainsString(get_string('aiwarning', 'local_ai_manager'), $html);
        }

        // HTML, plain and legacy Markdown results are formatted by their stored format, not re-parsed.
        foreach (self::stored_format_types_provider() as [$integerformat]) {
            $this->prepare_test();

            // Current purpose HTML is not parsed as Markdown again and keeps code and math markup.
            $htmlcontent = '<p>**Not Markdown**</p><pre><code>&lt;em&gt;code&lt;/em&gt;</code></pre><p>\(x + y\)</p>';
            $htmlreport = $this->create_report(['ai_result' => $htmlcontent, 'resultformat' => FORMAT_HTML]);
            $htmlreport->resultformat = $integerformat ? (int) FORMAT_HTML : FORMAT_HTML;
            $actual = (new report_exporter($htmlreport, $this->context))->format_result();
            $this->assertStringContainsString('**Not Markdown**', $actual);
            $this->assertStringContainsString('<code>&lt;em&gt;code&lt;/em&gt;</code>', $actual);
            $this->assertStringContainsString('\(x + y\)', $actual);

            // Plain display converts stored HTML to text before escaping it, with no literal wrappers.
            set_config('enable_markdown_conversion', 0, 'report_ai_analysis');
            $plaincontent = '<p>Ä &amp; B</p><pre><code>sample &lt;em&gt; &amp;lt;</code></pre>' .
                '<script>plainScriptProbe()</script><img src="x" onerror="plainImageProbe()">';
            $plainhtmlreport = $this->create_report(['ai_result' => $plaincontent, 'resultformat' => FORMAT_HTML]);
            $plainhtmlreport->resultformat = $integerformat ? (int) FORMAT_HTML : FORMAT_HTML;
            $plaindata = (new report_exporter($plainhtmlreport, $this->context))->get_data();
            $this->assertStringContainsString('Ä &amp; B', $plaindata['ai_result']);
            $this->assertStringNotContainsString('&lt;p&gt;', $plaindata['ai_result']);
            $this->assertStringNotContainsString('<code>', $plaindata['ai_result']);
            $this->assertStringContainsString('&lt;em&gt;', $plaindata['ai_result']);
            $this->assertStringNotContainsString('plainScriptProbe', $plaindata['ai_result']);
            $this->assertStringNotContainsString('plainImageProbe', $plaindata['ai_result']);
            $this->assertStringContainsString('**Analyze**', $plaindata['prompt']);
            $this->assertStringNotContainsString('<strong>', $plaindata['prompt']);

            // Persisted plain text stays literal, independently of the Markdown display setting.
            $plainreport = $this->create_report(
                ['ai_result' => '**Plain** <em>literal &amp;</em>', 'resultformat' => FORMAT_PLAIN]
            );
            $plainreport->resultformat = $integerformat ? (int) FORMAT_PLAIN : FORMAT_PLAIN;
            foreach ([1, 0] as $conversion) {
                set_config('enable_markdown_conversion', $conversion, 'report_ai_analysis');
                $literal = (new report_exporter($plainreport, $this->context))->format_result();
                $this->assertSame('**Plain** &lt;em&gt;literal &amp;amp;&lt;/em&gt;', $literal);
            }

            // Legacy Markdown remains supported with or without a supplied format field.
            set_config('enable_markdown_conversion', 1, 'report_ai_analysis');
            $markdownreport = $this->create_report(['ai_result' => '**Legacy**', 'resultformat' => FORMAT_MARKDOWN]);
            $explicit = (new report_exporter($markdownreport, $this->context))->format_result();
            unset($markdownreport->resultformat);
            $implicit = (new report_exporter($markdownreport, $this->context))->format_result();
            $this->assertSame($explicit, $implicit);
            $this->assertStringContainsString('<strong>Legacy</strong>', $explicit);
        }

        // Failed exports are allowed, but legacy and current diagnostic fields obey both flags.
        foreach (self::debug_flags_provider() as [$developer, $display, $legacy]) {
            $this->prepare_test();
            $CFG->debugdeveloper = $developer;
            $CFG->debugdisplay = $display;
            $details = 'Private connector diagnostic <probe>';
            $report = $this->create_report([
                'status' => 'failed',
                'error_code' => 'unclassified_internal_code',
                'error_message' => $legacy ? $details : 'Do not display this backend message',
                'error_details' => $legacy ? null : $details,
                'ai_result' => '<p>Stale result that must not be shown</p>',
            ]);
            $exporter = new report_exporter($report, $this->context);
            $data = json_decode($exporter->get_json(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(error_info::get_description(null), $data['error_message']);
            $this->assertNull($data['ai_result']);
            $html = $exporter->get_html($OUTPUT);
            if ($developer && $display) {
                $this->assertSame($details, $data['error_details']);
                $this->assertStringContainsString(s($details), $html);
            } else {
                $this->assertArrayNotHasKey('error_details', $data);
                $this->assertStringNotContainsString('Private connector diagnostic', $html);
                $this->assertStringNotContainsString('Do not display this backend message', $html);
            }
            $this->assertStringNotContainsString('Stale result', $html);
        }

        // Export guards refuse missing capabilities, unfinished states and mismatched or foreign contexts.
        $this->prepare_test();

        // Raw data needs its own capability, and the creator email is never exported.
        $teacher = $this->getDataGenerator()->create_user();
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, $roleid);
        assign_capability('report/ai_analysis:viewrawdata', CAP_PROHIBIT, $roleid, $this->context->id, true);
        $report = $this->create_report(['userid' => $teacher->id, 'raw_data' => 'Private raw data marker']);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($teacher);
        $exporter = new report_exporter($report, $this->context);
        $data = $exporter->get_data();
        $this->assertArrayNotHasKey('raw_data', $data);
        $this->assertArrayNotHasKey('email', $data['created_by']);
        $this->assertStringNotContainsString('Private raw data marker', $exporter->get_html($OUTPUT));
        $this->assertStringNotContainsString($teacher->email, $exporter->get_json());

        assign_capability('report/ai_analysis:viewrawdata', CAP_ALLOW, $roleid, $this->context->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($teacher);
        $this->assertSame('Private raw data marker', $exporter->get_data()['raw_data']);

        // Pending, running and cancelled reports cannot be downloaded as final exports.
        $this->setAdminUser();
        foreach (['pending', 'running', 'cancelled'] as $status) {
            $unfinished = $this->create_report(['status' => $status]);
            $this->assertFalse(report_exporter::can_export($unfinished));
            $unfinishedexporter = new report_exporter($unfinished, $this->context);
            try {
                $unfinishedexporter->get_json();
                $this->fail('Unfinished ' . $status . ' report must not export.');
            } catch (\moodle_exception $e) {
                $this->assertStringContainsString(get_string('cannotexportreport', 'report_ai_analysis'), $e->getMessage());
            }
        }

        // A foreign private report is refused even when a caller bypasses the endpoint.
        $owner = $this->getDataGenerator()->create_user();
        $reader = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($owner->id, $this->course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($reader->id, $this->course->id, 'editingteacher');
        $private = $this->create_report(['userid' => $owner->id]);
        $this->setUser($reader);
        try {
            new report_exporter($private, $this->context);
            $this->fail('A foreign private report must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertInstanceOf(\moodle_exception::class, $e);
        }

        // The supplied context must match the report and be a course context.
        $this->setAdminUser();
        $othercourse = $this->getDataGenerator()->create_course();
        $mismatch = $this->create_report();
        try {
            new report_exporter($mismatch, \context_course::instance($othercourse->id));
            $this->fail('A mismatched course context must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(get_string('error_contextmismatch', 'report_ai_analysis'), $e->getMessage());
        }
        $systemcontext = \context_system::instance();
        $noncourse = $this->create_report(['contextid' => $systemcontext->id]);
        try {
            new report_exporter($noncourse, $systemcontext);
            $this->fail('A non-course context must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(get_string('error_contextmismatch', 'report_ai_analysis'), $e->getMessage());
        }
    }

    /**
     * Required export languages.
     *
     * @return array
     */
    public static function languages_provider(): array {
        return ['English' => ['en'], 'German' => ['de']];
    }

    /**
     * Database drivers and in-memory report records can expose either scalar format type.
     *
     * @return array
     */
    public static function stored_format_types_provider(): array {
        return ['string format' => [false], 'integer format' => [true]];
    }

    /**
     * Debug display combinations for both storage generations.
     *
     * @return array
     */
    public static function debug_flags_provider(): array {
        return [
            'neither' => [false, false, false],
            'both' => [true, true, false],
            'legacy display only' => [false, true, true],
            'legacy both' => [true, true, true],
        ];
    }

    /**
     * NO_DEBUG_DISPLAY is authoritative even with developer output enabled.
     */
    public function test_no_debug_display_suppresses_export_details(): void {
        // A nested Moodle bootstrap would deadlock on the parent's PHPUnit testdata lock.
        // Load only the real helper: the exporter matrix above already verifies gate delegation.
        $script = <<<'PHP'
            define('MOODLE_INTERNAL', true);
            require $argv[1];
            $CFG = (object) ['debugdeveloper' => true, 'debugdisplay' => true];
            $enabled = \report_ai_analysis\error_info::can_show_debug_details();
            define('NO_DEBUG_DISPLAY', true);
            // Suppressed details must return before needing any Moodle language or database API.
            echo json_encode([
                'enabled_before_override' => $enabled,
                'enabled_after_override' => \report_ai_analysis\error_info::can_show_debug_details(),
                'current_details' => \report_ai_analysis\error_info::get_debug_details(
                    'error_ai_request',
                    'Backend message marker',
                    'NO_DEBUG_DISPLAY diagnostic marker'
                ),
                'legacy_details' => \report_ai_analysis\error_info::get_debug_details(
                    'unclassified_internal_code',
                    'NO_DEBUG_DISPLAY legacy diagnostic marker',
                    null
                ),
            ], JSON_THROW_ON_ERROR);
            PHP;
        $process = proc_open([
            PHP_BINARY,
            '-n',
            '-r',
            $script,
            '--',
            dirname(__DIR__, 2) . '/classes/error_info.php',
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $this->assertSame(0, proc_close($process), $output);
        $this->assertSame([
            'enabled_before_override' => true,
            'enabled_after_override' => false,
            'current_details' => null,
            'legacy_details' => null,
        ], json_decode($output, true, 512, JSON_THROW_ON_ERROR));
    }
}
