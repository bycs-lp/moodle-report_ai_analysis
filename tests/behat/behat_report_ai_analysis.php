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

// Behat discovers this context before the Moodle bootstrap; guard fixture execution, not discovery.
require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Exception\ExpectationException;
use local_ai_manager\base_purpose;
use local_ai_manager\local\config_manager;
use local_ai_manager\local\connector_factory;
use local_ai_manager\local\tenant;
use local_ai_manager\local\userinfo;
use local_ai_manager\local\userusage;
use report_ai_analysis\local\ai_request_provider;
use report_ai_analysis\local\report_manager;
use report_ai_analysis\scope_builder;

/**
 * Real backend configuration, authenticated endpoints and synchronous worker assertions.
 *
 * @package    report_ai_analysis
 * @category   test
 * @copyright  2026 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_report_ai_analysis extends behat_base {
    /** @var string Canonical class name stored in the task queue. */
    private const TASK_CLASS = '\\report_ai_analysis\\task\\process_analysis_task';

    /** @var array Requests captured during the last synchronous worker execution. */
    private array $requests = [];

    /** @var array Report identifiers remembered before deletion. */
    private array $reportids = [];

    /** @var array Snapshots used to verify atomic transitions. */
    private array $snapshots = [];

    /** @var int Earliest possible retry scheduling time in the last execution. */
    private int $taskstarted = 0;

    /** @var int Latest possible retry scheduling time in the last execution. */
    private int $taskfinished = 0;

    /** @var string Expected Unicode-safe automatic title in the form scenario. */
    private string $automatictitle = '';

    /**
     * Configure actual tenant, connector, purpose, usage permission and consent records.
     *
     * @Given /^the AI analysis backend is configured$/
     */
    public function backend_is_configured(): void {
        global $DB;

        $this->require_behat_site();
        $this->requests = [];
        $this->reportids = [];
        $this->snapshots = [];
        $generator = testing_util::get_data_generator();
        $roleid = $generator->create_role(['shortname' => 'aianalysisbackend', 'name' => 'AI analysis fixture usage']);
        assign_capability('local/ai_manager:use', CAP_ALLOW, $roleid, context_system::instance()->id);
        set_config('requireconfirmtou', 1, 'local_ai_manager');
        \local_ai_manager\plugininfo\aitool::enable_plugin('chatgpt', true);
        \local_ai_manager\plugininfo\aipurpose::enable_plugin('singleprompt', true);

        $users = $DB->get_records_select('user', 'deleted = 0 AND username <> :guest', ['guest' => 'guest']);
        $tenantfield = get_config('local_ai_manager', 'tenantcolumn') ?: 'institution';
        $tenants = [];
        foreach ($users as $user) {
            $identifier = $user->{$tenantfield} ?: tenant::DEFAULT_IDENTIFIER;
            $tenants[$identifier] = true;
            role_assign($roleid, $user->id, context_system::instance()->id);
            $info = new userinfo((int) $user->id);
            $info->set_role(userinfo::ROLE_BASIC);
            $info->set_scope(userinfo::SCOPE_EVERYWHERE);
            $info->set_locked(false);
            $info->set_confirmed(true);
            $info->store();
        }
        // Configure the allowlist, rather than bypassing tenant checks.
        set_config('restricttenants', 1, 'local_ai_manager');
        set_config('allowedtenants', implode("\n", array_keys($tenants)), 'local_ai_manager');
        foreach (array_keys($tenants) as $identifier) {
            $config = new config_manager(new tenant($identifier));
            $factory = new connector_factory($config);
            $instance = $factory->get_new_instance('chatgpt');
            $instance->set_name('AI analysis Behat fixture');
            $instance->set_tenant($identifier);
            $instance->set_model('gpt-4o');
            $instance->set_endpoint('https://ai-analysis-behat.invalid/v1/chat/completions');
            $instance->set_apikey('behat-placeholder-not-a-secret');
            $instance->set_useglobalapikey(false);
            $instance->store();
            $config->set_config('tenantenabled', '1');
            $config->set_config('singleprompt_max_requests_basic', '50');
            $config->set_config(
                base_purpose::get_purpose_tool_config_key('singleprompt', userinfo::ROLE_BASIC),
                (string) $instance->get_id()
            );
        }
    }

    /**
     * Change genuine AI Manager state for negative availability scenarios.
     *
     * @Given /^AI analysis for "([^"]*)" is unavailable because of "(consent|quota|purpose|tenant)"$/
     * @param string $username Fixture user
     * @param string $reason Unavailability reason
     */
    public function backend_is_unavailable(string $username, string $reason): void {
        $this->with_user($this->user_id($username), function () use ($reason): void {
            global $USER;

            $config = \core\di::get(config_manager::class);
            if ($reason === 'consent') {
                $info = new userinfo((int) $USER->id);
                $info->set_confirmed(false);
                $info->store();
            } else if ($reason === 'quota') {
                $purpose = \core\di::get(connector_factory::class)->get_purpose_by_purpose_string('singleprompt');
                $usage = new userusage($purpose, (int) $USER->id);
                $usage->set_currentusage(50);
                $usage->store();
            } else if ($reason === 'purpose') {
                $config->unset_config(base_purpose::get_purpose_tool_config_key('singleprompt', userinfo::ROLE_BASIC));
            } else {
                $config->set_config('tenantenabled', '0');
            }
        });
    }

    /**
     * Queue through the real manager, using readable scope references rather than IDs.
     *
     * @Given /^I queue AI analysis "([^"]*)" as "([^"]*)" in "([^"]*)" with:$/
     * @param string $title Report title
     * @param string $username Requesting actor
     * @param string $course Course shortname
     * @param TableNode $fields Prompt and optional mode/source/participant/role/group/date fields
     */
    public function queue_analysis(string $title, string $username, string $course, TableNode $fields): void {
        global $DB;

        $data = $fields->getRowsHash();
        $courseid = (int) $DB->get_field('course', 'id', ['shortname' => $course], MUST_EXIST);
        $this->with_user($this->user_id($username), function () use ($data, $courseid, $title): void {
            $scope = $this->make_scope($courseid, $data);
            $id = report_manager::save((object) ['title' => $title, 'prompt' => $data['prompt']], $scope);
            $this->reportids[$title] = $id;
        }, $courseid);
    }

    /**
     * Create deterministic rows for real pagination and private-count checks.
     *
     * @Given /^"(\d+)" AI analysis reports with prefix "([^"]*)" by "([^"]*)" exist in "([^"]*)"$/
     * @param int $count Number of reports
     * @param string $prefix Unique title prefix
     * @param string $username Owner
     * @param string $course Course shortname
     */
    public function create_report_batch(int $count, string $prefix, string $username, string $course): void {
        $this->require_behat_site();
        /** @var report_ai_analysis_generator $generator */
        $generator = testing_util::get_data_generator()->get_plugin_generator('report_ai_analysis');
        for ($i = 1; $i <= $count; $i++) {
            $generator->create_report([
                'course' => $course,
                'userid' => $this->user_id($username),
                'title' => $prefix . sprintf(' %02d', $i),
                'timecreated' => 1000 + $i,
            ]);
        }
    }

    /**
     * Put a multibyte character exactly at the automatic-title cut boundary.
     *
     * @When /^I enter an AI analysis prompt at the Unicode title boundary$/
     */
    public function enter_unicode_prompt(): void {
        $prompt = str_repeat('a', 79) . 'ä remains valid after truncation';
        $this->automatictitle = core_text::substr($prompt, 0, 80);
        $this->execute('behat_forms::i_set_the_field_to', ['Analysis prompt', $prompt]);
    }

    /**
     * Verify the stored automatic title is exactly 80 Unicode characters and JSON-safe.
     *
     * @Then /^the automatically titled AI analysis should preserve its Unicode boundary$/
     */
    public function automatic_title_is_valid(): void {
        $this->check($this->automatictitle !== '', 'The Unicode boundary fixture was not entered');
        $report = $this->get_report($this->automatictitle);
        $this->check(core_text::strlen($report->title) === 80, 'Incorrect automatic title length');
        json_encode($report->title, JSON_THROW_ON_ERROR);
    }

    /**
     * Execute the actual queued worker, replacing only its AI request adapter.
     *
     * @When /^I run the AI analysis task for "([^"]*)"$/
     * @param string $title Report title
     */
    public function run_analysis(string $title): void {
        $this->run_task($title);
    }

    /**
     * Execute with a typed transient/non-transient failure or an empty successful response.
     *
     * @When /^I run the AI analysis task for "([^"]*)" returning HTTP "(\d+)"$/
     * @param string $title Report title
     * @param int $code Synthetic response code; 200 deliberately returns empty content here
     */
    public function run_analysis_with_response(string $title, int $code): void {
        $this->run_task($title, $code, '');
    }

    /**
     * An exception mentioning timeout must not itself become a retryable response.
     *
     * @When /^I run the AI analysis task for "([^"]*)" throwing "([^"]*)"$/
     * @param string $title Report title
     * @param string $message Synthetic exception message
     */
    public function run_analysis_with_exception(string $title, string $message): void {
        $this->run_task($title, 0, $message);
    }

    /**
     * Keep the real collector but make its selected chat source fail explicitly.
     *
     * @When /^I run the AI analysis task for "([^"]*)" with a failing chat source$/
     * @param string $title Report title
     */
    public function run_analysis_with_source_failure(string $title): void {
        $this->require_behat_site();
        require_once(__DIR__ . '/fixtures/failing_log_provider.php');
        $original = \core\di::get(\report_ai_analysis\local\ai_log_provider::class);
        try {
            \core\di::set(
                \report_ai_analysis\local\ai_log_provider::class,
                new \report_ai_analysis\local\behat_failing_log_provider()
            );
            $this->run_task($title);
        } finally {
            \core\di::set(\report_ai_analysis\local\ai_log_provider::class, $original);
        }
    }

    /**
     * Generate a private reply without hardcoding the recipient ID.
     *
     * @Given /^a private AI analysis reply "([^"]*)" by "([^"]*)" to "([^"]*)" exists in discussion "([^"]*)"$/
     * @param string $message Distinctive source marker
     * @param string $author Author username
     * @param string $recipient Recipient username
     * @param string $discussion Discussion name
     */
    public function create_private_reply(string $message, string $author, string $recipient, string $discussion): void {
        global $DB;

        $this->require_behat_site();
        $thread = $DB->get_record('forum_discussions', ['name' => $discussion], '*', MUST_EXIST);
        /** @var mod_forum_generator $generator */
        $generator = testing_util::get_data_generator()->get_plugin_generator('mod_forum');
        $generator->create_post([
            'discussion' => $thread->id,
            'parent' => $thread->firstpost,
            'userid' => $this->user_id($author),
            'privatereplyto' => $this->user_id($recipient),
            'message' => $message,
        ]);
    }

    /**
     * Generate enough real posts to exercise the final prompt bound, including multibyte data.
     *
     * @Given /^discussion "([^"]*)" has "(\d+)" AI analysis posts of "(\d+)" characters by "([^"]*)"$/
     * @param string $discussion Discussion name
     * @param int $count Number of posts
     * @param int $length Characters per post
     * @param string $username Author username
     */
    public function create_large_source(string $discussion, int $count, int $length, string $username): void {
        global $DB;

        $this->require_behat_site();
        $thread = $DB->get_record('forum_discussions', ['name' => $discussion], '*', MUST_EXIST);
        /** @var mod_forum_generator $generator */
        $generator = testing_util::get_data_generator()->get_plugin_generator('mod_forum');
        for ($i = 0; $i < $count; $i++) {
            $generator->create_post([
                'discussion' => $thread->id,
                'parent' => $thread->firstpost,
                'userid' => $this->user_id($username),
                'message' => str_repeat('ä', $length),
                'messageformat' => FORMAT_PLAIN,
            ]);
        }
    }

    /**
     * Mutate through the public manager while a response is in flight.
     *
     * @When /^I run the AI analysis task for "([^"]*)" while I "(cancel|cancel and rerun|delete)" it$/
     * @param string $title Report title
     * @param string $action Concurrent lifecycle action
     */
    public function run_analysis_during_mutation(string $title, string $action): void {
        $reportid = (int) $this->get_report($title)->id;
        $this->run_task($title, 200, '<p>Stale result must not survive</p>', static function () use ($reportid, $action): void {
            if ($action === 'delete') {
                report_manager::delete($reportid);
            } else {
                report_manager::cancel($reportid);
                if ($action === 'cancel and rerun') {
                    report_manager::rerun($reportid);
                }
            }
        });
    }

    /**
     * Suspend the actual manual enrolment after queueing.
     *
     * @Given /^the AI analysis enrolment of "([^"]*)" in "([^"]*)" is suspended$/
     * @param string $username Enrolled actor
     * @param string $course Course shortname
     */
    public function suspend_enrolment(string $username, string $course): void {
        global $DB;

        $courseid = $DB->get_field('course', 'id', ['shortname' => $course], MUST_EXIST);
        $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->update_user_enrol($instance, $this->user_id($username), ENROL_USER_SUSPENDED);
    }

    /**
     * Open a detail endpoint without theme-specific DOM manipulation.
     *
     * @When /^I view the AI analysis report "([^"]*)"$/
     * @param string $title Report title
     */
    public function i_view_ai_analysis_report(string $title): void {
        $this->getSession()->visit(
            (new moodle_url('/report/ai_analysis/view.php', ['id' => $this->get_report($title)->id]))->out(false)
        );
    }

    /**
     * Resolve the existing course placeholder syntax, failing for unknown references.
     *
     * @When /^I visit the url "([^"]*)"$/
     * @param string $url Relative plugin URL
     */
    public function i_visit_the_url(string $url): void {
        global $DB;

        $this->check(str_starts_with($url, '/report/ai_analysis/'), 'Only AI analysis URLs are supported by this step');
        $url = preg_replace_callback('/\{\{([^:]+):id\}\}/', static function (array $match) use ($DB): string {
            $course = $DB->get_record('course', ['shortname' => $match[1]]);
            $course = $course ?: $DB->get_record('course', ['fullname' => $match[1]], '*', MUST_EXIST);
            return (string) $course->id;
        }, $url);
        $this->check(!str_contains($url, '{{'), 'Unresolved fixture placeholder');
        $this->getSession()->visit((new moodle_url($url))->out(false));
    }

    /**
     * Assert the complete object-level denial, including an unchanged report and task queue.
     *
     * @Then /^a direct "(view|edit|export|rerun|cancel|delete)" request for AI analysis report "([^"]*)" should be denied$/
     * @param string $action Requested endpoint action
     * @param string $title Report title
     */
    public function direct_request_is_denied(string $action, string $title): void {
        $report = $this->get_report($title);
        $before = $this->snapshot((int) $report->id);
        $response = $this->http_request($this->report_url($report, $action));
        $capability = match ($action) {
            'edit' => 'create', 'cancel', 'delete' => 'delete', 'rerun' => 'rerun', default => 'view',
        };
        $messages = [get_string('nopermission', 'report_ai_analysis'),
            get_string('nopermissions', 'error', get_capability_string('report/ai_analysis:' . $capability))];
        $text = html_entity_decode(strip_tags($response['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->check(
            (bool) array_filter($messages, static fn($message) => str_contains($text, $message)),
            "The $action endpoint did not return its permission error (HTTP {$response['status']})"
        );
        $this->check($before === $this->snapshot((int) $report->id), 'A denied request changed report data or queued work');
    }

    /**
     * Preserve the earlier feature's rerun assertion with the full shared denial check.
     *
     * @Then /^a direct rerun request for AI analysis report "([^"]*)" should be rejected$/
     * @param string $title Report title
     */
    public function direct_rerun_request_should_be_rejected(string $title): void {
        $this->direct_request_is_denied('rerun', $title);
    }

    /**
     * Ensure public manager calls cannot bypass the endpoint's ownership policy.
     *
     * @Then /^an AI analysis manager "(edit|rerun|cancel|delete)" call for "([^"]*)" as "([^"]*)" should be denied$/
     * @param string $action Mutation
     * @param string $title Report title
     * @param string $username Actor
     */
    public function manager_call_is_denied(string $action, string $title, string $username): void {
        $report = $this->get_report($title);
        $before = $this->snapshot((int) $report->id);
        $denied = false;
        try {
            $this->with_user($this->user_id($username), static function () use ($action, $report): void {
                if ($action === 'edit') {
                    report_manager::save(
                        (object) ['title' => $report->title, 'prompt' => $report->prompt],
                        scope_builder::from_json($report->scope_details),
                        (int) $report->id
                    );
                } else {
                    report_manager::{$action}((int) $report->id);
                }
            });
        } catch (moodle_exception $exception) {
            if (!in_array($exception->errorcode, ['nopermission', 'nopermissions'], true)) {
                throw $exception;
            }
            $denied = true;
        }
        $this->check($denied, 'The public manager did not reject the forbidden mutation');
        $this->check($before === $this->snapshot((int) $report->id), 'A denied manager call changed report data or tasks');
    }

    /**
     * Verify CSRF protection independently of ownership checks.
     *
     * @Then /^a direct "(rerun|cancel|delete)" request for AI analysis report "([^"]*)" with an invalid sesskey should be denied$/
     * @param string $action Action
     * @param string $title Report title
     */
    public function invalid_sesskey_is_denied(string $action, string $title): void {
        $report = $this->get_report($title);
        $before = $this->snapshot((int) $report->id);
        $url = $this->report_url($report, $action);
        $url->param('sesskey', 'invalid-behat-sesskey');
        $response = $this->http_request($url);
        $this->check(str_contains($response['body'], get_string('invalidsesskey', 'error')), 'Missing invalid-sesskey error');
        $this->check($before === $this->snapshot((int) $report->id), 'A CSRF-rejected action changed report data or queued work');
    }

    /**
     * Check the index action course parameter against the report's actual context.
     *
     * @Then /^a direct "(cancel|delete)" request for AI analysis report "([^"]*)" in course "([^"]*)" should be denied$/
     * @param string $action Action
     * @param string $title Report title
     * @param string $course Wrong course shortname
     */
    public function wrong_course_is_denied(string $action, string $title, string $course): void {
        global $DB;

        $report = $this->get_report($title);
        $before = $this->snapshot((int) $report->id);
        $url = $this->report_url($report, $action);
        $url->param('courseid', $DB->get_field('course', 'id', ['shortname' => $course], MUST_EXIST));
        $response = $this->http_request($url);
        $this->check(
            str_contains($response['body'], get_string('error_contextmismatch', 'report_ai_analysis')),
            'The endpoint did not reject the wrong course context'
        );
        $this->check($before === $this->snapshot((int) $report->id), 'Wrong-course request changed the report');
    }

    /**
     * Download through the authenticated endpoint and check the presentation contract.
     *
     * @Then /^the "(json|html)" export of AI analysis report "([^"]*)" should contain safe presentation data$/
     * @param string $format Export format
     * @param string $title Report title
     */
    public function export_is_safe(string $format, string $title): void {
        $report = $this->get_report($title);
        $url = $this->report_url($report, 'export');
        $url->param('format', $format);
        $response = $this->http_request($url);
        $this->check($response['status'] === 200, 'The export endpoint did not return HTTP 200');
        $this->check(!str_contains($response['body'], '@example.com'), 'Export exposed a creator email');
        if ($format === 'json') {
            $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            $this->check(($data['id'] ?? null) === (int) $report->id, 'JSON did not contain the requested report');
            $this->check(
                ($data['promptformat'] ?? null) === FORMAT_HTML && ($data['resultformat'] ?? null) === FORMAT_HTML,
                'JSON prompt/result must be marked as presentation HTML'
            );
            $this->check(!array_key_exists('email', $data['created_by']), 'JSON creator includes email');
            $this->assert_safe_html($data['prompt']);
            if ($report->status === 'failed') {
                $this->check($data['ai_result'] === null && !empty($data['error_message']), 'Invalid failed-report export');
            } else {
                $this->check(!empty($data['ai_result']), 'Completed export contains no result');
                $this->assert_safe_html($data['ai_result']);
            }
            $this->check(!isset($data['error_details']), 'Diagnostics leaked while debug display was disabled');
        } else {
            $this->check(
                str_contains($response['body'], '<!DOCTYPE html>') || str_contains($response['body'], '<!doctype html>'),
                'HTML export did not contain a standalone document'
            );
            $this->check(
                str_contains($response['body'], get_string('exportedfrom', 'report_ai_analysis')),
                'The HTML export footer is missing'
            );
            $this->assert_safe_html($response['body']);
        }
    }

    /**
     * Check direct export rejection for non-terminal/non-exportable reports.
     *
     * @Then /^AI analysis report "([^"]*)" should reject direct export$/
     * @param string $title Report title
     */
    public function export_is_rejected(string $title): void {
        $response = $this->http_request($this->report_url($this->get_report($title), 'export'));
        $this->check(
            str_contains($response['body'], get_string('cannotexportreport', 'report_ai_analysis')),
            'A non-exportable report did not return the export-state error'
        );
    }

    /**
     * State eligibility must also be checked at direct edit and re-run endpoints.
     *
     * @Then /^AI analysis report "([^"]*)" should reject direct "(edit|rerun)"$/
     * @param string $title Report title
     * @param string $action Requested action
     */
    public function mutation_state_is_rejected(string $title, string $action): void {
        $report = $this->get_report($title);
        $before = $this->snapshot((int) $report->id);
        $response = $this->http_request($this->report_url($report, $action));
        $key = $action === 'edit' ? 'cannoteditrunningreport' : 'cannotrerunreport';
        $this->check(str_contains($response['body'], get_string($key, 'report_ai_analysis')), 'Missing invalid-state error');
        $this->check($before === $this->snapshot((int) $report->id), 'An invalid-state request changed report data or tasks');
    }

    /**
     * Test raw-data capability on the actual JSON endpoint, not only a hidden UI region.
     *
     * @Then /^the JSON export of AI analysis report "([^"]*)" should omit raw data$/
     * @param string $title Report title
     */
    public function export_omits_raw_data(string $title): void {
        $response = $this->http_request($this->report_url($this->get_report($title), 'export'));
        $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->check(!array_key_exists('raw_data', $data), 'Raw data was exported without the capability');
    }

    /**
     * Seed harmless active-markup probes into stored prompt/result fields.
     *
     * @Given /^AI analysis report "([^"]*)" contains unsafe presentation fixtures$/
     * @param string $title Report title
     */
    public function seed_presentation_fixture(string $title): void {
        global $DB;

        $report = $this->get_report($title);
        $probe = '<script>window.aiAnalysisUnsafe = true;</script>' .
            '<img src="missing" onerror="window.aiAnalysisUnsafe = true;">';
        $DB->update_record('report_ai_analysis_reports', (object) [
            'id' => $report->id,
            'prompt' => '**Safe prompt** ' . $probe,
            'ai_result' => '<p><strong>Safe result</strong></p>' . $probe,
            'resultformat' => FORMAT_HTML,
            'raw_data' => null,
        ]);
    }

    /**
     * Inspect only rendered prompt/result regions, not Moodle's legitimate page scripts.
     *
     * @Then /^the AI analysis presentation should be safe$/
     */
    public function presentation_is_safe(): void {
        foreach (['.prompt-text', '.ai-result'] as $selector) {
            $node = $this->find('css', $selector);
            $this->assert_safe_html($node->getHtml());
        }
        $this->check(
            !$this->getSession()->evaluateScript('return window.aiAnalysisUnsafe === true;'),
            'Untrusted report markup executed in the browser'
        );
    }

    /**
     * Assert the persisted lifecycle state without relying only on a status badge.
     *
     * phpcs:ignore moodle.Files.LineLength.TooLong
     * @Then /^AI analysis report "([^"]*)" should have "(status|action|runversion|resultformat|truncated|retry_count|error_code|ai_result)" "([^"]*)"$/
     * @param string $title Report title
     * @param string $field Allowed record field
     * @param string $expected Expected value
     */
    public function report_field_is(string $title, string $field, string $expected): void {
        $report = $this->get_report($title);
        $this->check((string) $report->{$field} === $expected, "Report $title: unexpected $field value");
    }

    /**
     * Keep the existing retry-count assertion.
     *
     * @Then /^the report "([^"]*)" should have retry count "(\d+)"$/
     * @param string $title Report title
     * @param string $count Retry count
     */
    public function report_should_have_retry_count(string $title, string $count): void {
        $this->report_field_is($title, 'retry_count', $count);
    }

    /**
     * Verify that all stored failure fields were reset.
     *
     * @Then /^the report "([^"]*)" should have no stored error data$/
     * @param string $title Report title
     */
    public function report_should_have_no_stored_error_data(string $title): void {
        $report = $this->get_report($title);
        foreach (['error_message', 'error_details', 'error_code'] as $field) {
            $this->check(empty($report->{$field}), "Report still contains $field");
        }
    }

    /**
     * Assert queue identity and generation for a particular report, not an unrelated task.
     *
     * @Then /^AI analysis report "([^"]*)" should have "(\d+)" queued tasks$/
     * @param string $title Report title
     * @param int $count Expected count
     */
    public function report_task_count(string $title, int $count): void {
        $reportid = $this->reportids[$title] ?? (int) $this->get_report($title)->id;
        $tasks = $this->report_tasks($reportid);
        $this->check(count($tasks) === $count, "Expected $count tasks for $title, found " . count($tasks));
        if ($count > 0) {
            $report = $this->get_report($title);
            foreach ($tasks as $task) {
                $data = json_decode($task->customdata, false, 512, JSON_THROW_ON_ERROR);
                $this->check((int) ($data->runversion ?? 0) === (int) $report->runversion, 'Queued stale generation');
            }
        }
    }

    /**
     * Maintain existing queue assertions with the actor verified against the real user ID.
     *
     * @Then /^an adhoc task "([^"]*)" should exist for user "([^"]*)"$/
     * @param string $taskclass Task class
     * @param string $username Queued actor
     */
    public function adhoc_task_should_exist_for_user(string $taskclass, string $username): void {
        global $DB;

        $this->check($DB->record_exists('task_adhoc', [
            'classname' => '\\' . ltrim($taskclass, '\\'), 'userid' => $this->user_id($username),
        ]), 'No matching task for the expected actor');
    }

    /**
     * Verify exponential retry scheduling without sleeping or executing future retries implicitly.
     *
     * @Then /^AI analysis report "([^"]*)" should retry in "(\d+)" seconds as "([^"]*)"$/
     * @param string $title Report title
     * @param int $delay Expected delay
     * @param string $username Original requesting actor
     */
    public function retry_is_scheduled(string $title, int $delay, string $username): void {
        $this->report_task_count($title, 1);
        $tasks = $this->report_tasks((int) $this->get_report($title)->id);
        $task = reset($tasks);
        $this->check((int) $task->userid === $this->user_id($username), 'Retry changed the executing user');
        $this->check(
            $task->nextruntime >= $this->taskstarted + $delay && $task->nextruntime <= $this->taskfinished + $delay,
            "Retry was not scheduled with a $delay second delay"
        );
    }

    /**
     * Assert actual adapter invocations, including zero calls after revoked access.
     *
     * @Then /^there should have been "(\d+)" AI analysis requests$/
     * @param int $count Expected request count
     */
    public function request_count_is(int $count): void {
        $this->check(count($this->requests) === $count, "Expected $count requests, found " . count($this->requests));
    }

    /**
     * Inspect complete final request contents.
     *
     * @Then /^AI analysis request "(\d+)" should (contain|not contain) "([^"]*)"$/
     * @param int $number One-based request number
     * @param string $comparison Positive or negative check
     * @param string $text Expected or forbidden marker
     */
    public function request_contains(int $number, string $comparison, string $text): void {
        $this->check(isset($this->requests[$number - 1]), "Request $number does not exist");
        $contains = str_contains($this->requests[$number - 1]['prompt'], $text);
        $this->check($contains === ($comparison === 'contain'), "Request $number should $comparison $text");
    }

    /**
     * Check a global record budget using markers occurring once per source record.
     *
     * @Then /^AI analysis request "(\d+)" should contain "(\d+)" occurrences of "([^"]*)"$/
     * @param int $number Request number
     * @param int $count Expected records
     * @param string $marker Unique record marker prefix
     */
    public function request_occurrences(int $number, int $count, string $marker): void {
        $this->check(isset($this->requests[$number - 1]), 'No request to inspect');
        $this->check(substr_count($this->requests[$number - 1]['prompt'], $marker) === $count, 'Incorrect shared record budget');
    }

    /**
     * Check persisted setting values after the actual admin form validation.
     *
     * @Then /^the AI analysis setting "(max_records_per_analysis|truncate_raw_data_length)" should be "(\d+)"$/
     * @param string $setting Bounded setting
     * @param string $value Expected stored value
     */
    public function setting_is(string $setting, string $value): void {
        $this->check((string) get_config('report_ai_analysis', $setting) === $value, 'Unexpected stored setting value');
    }

    /**
     * Verify actor, component, course, itemid and the final prompt hard limit.
     *
     * @Then /^all AI analysis requests should belong to "([^"]*)" and report "([^"]*)"$/
     * @param string $username Actual queued user
     * @param string $title Report title
     */
    public function request_identity_is(string $username, string $title): void {
        $report = $this->get_report($title);
        $this->check(count($this->requests) > 0, 'No requests to check');
        foreach ($this->requests as $request) {
            $this->check($request['userid'] === $this->user_id($username), 'Request ran as the wrong user');
            $this->check($request['contextid'] === (int) $report->contextid, 'Request used the wrong course context');
            $this->check(
                $request['purpose'] === 'singleprompt' && $request['component'] === 'report_ai_analysis',
                'Request used the wrong purpose or component'
            );
            $this->check((int) ($request['options']['itemid'] ?? 0) === (int) $report->id, 'Missing report/log linkage');
            $this->check(core_text::strlen($request['prompt']) <= 1000000, 'Final prompt exceeded its hard limit');
        }
        $this->check(ctype_digit((string) $report->execution_time), 'Execution time is not a whole-number duration');
    }

    /**
     * Assert exact relational attribution, not the requested but uncollected participant selection.
     *
     * @Then /^AI analysis report "([^"]*)" should contain only subjects "([^"]*)"$/
     * @param string $title Report title
     * @param string $usernames Comma-separated usernames
     */
    public function subjects_are(string $title, string $usernames): void {
        global $DB;

        $actual = array_map('intval', $DB->get_fieldset(
            'report_ai_analysis_users',
            'userid',
            ['reportid' => $this->get_report($title)->id]
        ));
        $expected = array_map(fn($name) => $this->user_id($name), $this->names($usernames));
        sort($actual);
        sort($expected);
        $this->check($actual === $expected, 'Report attribution does not match the included authors');
    }

    /**
     * Verify individual result/source separation in persisted participant rows.
     *
     * @Then /^AI analysis report "([^"]*)" should store individual results:$/
     * @param string $title Report title
     * @param TableNode $rows User/source/result/absent markers
     */
    public function individual_results_are(string $title, TableNode $rows): void {
        global $DB;

        $report = $this->get_report($title);
        foreach ($rows->getHash() as $row) {
            $subject = $DB->get_record('report_ai_analysis_users', [
                'reportid' => $report->id, 'userid' => $this->user_id($row['user']),
            ], '*', MUST_EXIST);
            $this->check(str_contains((string) $subject->source_data, $row['source']), 'Missing attributable source');
            $this->check(str_contains((string) $subject->ai_result, $row['result']), 'Missing individual result');
            $this->check(
                !str_contains((string) $subject->source_data . (string) $subject->ai_result, $row['absent']),
                'A participant row contains another participant\'s data'
            );
            $this->check(
                str_contains((string) $report->ai_result, $row['result']),
                'Parent does not combine the individual results'
            );
        }
        $this->check((int) $report->resultformat === FORMAT_HTML, 'Combined result is not marked as HTML');
    }

    /**
     * Raw storage off leaves attribution/results, not source copies.
     *
     * @Then /^AI analysis report "([^"]*)" should have no stored source data$/
     * @param string $title Report title
     */
    public function source_data_is_absent(string $title): void {
        global $DB;

        $report = $this->get_report($title);
        $this->check(empty($report->raw_data), 'Parent source data was stored');
        foreach ($DB->get_records('report_ai_analysis_users', ['reportid' => $report->id]) as $subject) {
            $this->check($subject->source_data === null, 'Participant source data was not null');
        }
    }

    /**
     * Remember lifecycle fields before a re-run, edit or cancel.
     *
     * @Given /^I remember AI analysis report "([^"]*)"$/
     * @param string $title Report title
     */
    public function remember_report(string $title): void {
        $this->snapshots[$title] = $this->get_report($title);
    }

    /**
     * Ensure stale response data and old metadata cannot survive a new generation.
     *
     * @Then /^AI analysis report "([^"]*)" should have a fresh generation$/
     * @param string $title Report title
     */
    public function generation_is_fresh(string $title): void {
        $report = $this->get_report($title);
        $this->check(isset($this->snapshots[$title]), 'No remembered report generation');
        $this->check((int) $report->runversion > (int) $this->snapshots[$title]->runversion, 'Generation was not increased');
        $this->check((int) $report->userid === (int) $this->snapshots[$title]->userid, 'Mutation changed the report owner');
        $fields = ['ai_result', 'raw_data', 'ai_model_name', 'token_usage', 'execution_time', 'timecompleted', 'retry_count'];
        foreach ($fields as $field) {
            $this->check(empty($report->{$field}), "New generation retained $field");
        }
        $this->report_should_have_no_stored_error_data($title);
    }

    /**
     * Compare canonical filter values, ignoring JSON object member ordering.
     *
     * @Then /^AI analysis report "([^"]*)" should retain its remembered scope$/
     * @param string $title Report title
     */
    public function scope_is_unchanged(string $title): void {
        $this->check(isset($this->snapshots[$title]), 'No remembered scope');
        $actual = json_decode($this->get_report($title)->scope_details, true, 512, JSON_THROW_ON_ERROR);
        $expected = json_decode($this->snapshots[$title]->scope_details, true, 512, JSON_THROW_ON_ERROR);
        $this->check($actual == $expected, 'Edit changed the participant, role, group, source or date filters');
    }

    /**
     * Check template order in the rendered table rather than executing JavaScript on Mink node objects.
     *
     * @Then /^AI analysis template "([^"]*)" should precede "([^"]*)"$/
     * @param string $first First title
     * @param string $second Second title
     */
    public function template_precedes(string $first, string $second): void {
        $positions = [];
        foreach ($this->getSession()->getPage()->findAll('css', 'table tbody tr') as $index => $row) {
            foreach ([$first, $second] as $title) {
                if (str_contains($row->getText(), $title)) {
                    $positions[$title] = $index;
                }
            }
        }
        $this->check(
            isset($positions[$first], $positions[$second]) && $positions[$first] < $positions[$second],
            'Templates are not rendered in the expected order'
        );
    }

    /**
     * Check both administrative template endpoints for a non-administrator.
     *
     * @Then /^AI analysis template administration should be denied$/
     */
    public function template_administration_is_denied(): void {
        $message = get_string('nopermissions', 'error', get_capability_string('moodle/site:config'));
        foreach (['manage_templates.php', 'edit_template.php'] as $endpoint) {
            $response = $this->http_request(new moodle_url('/report/ai_analysis/' . $endpoint));
            $text = html_entity_decode(strip_tags($response['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->check(str_contains($text, $message), 'Template administration did not enforce site configuration permission');
        }
    }

    /**
     * Assert an independently optional date range persisted unchanged through the form.
     *
     * @Then /^AI analysis report "([^"]*)" should have time boundaries "(\d+)" and "(\d+)"$/
     * @param string $title Report title
     * @param int $start Inclusive start, zero means open
     * @param int $end Inclusive end, zero means open
     */
    public function timerange_is(string $title, int $start, int $end): void {
        $scope = scope_builder::parse($this->get_report($title)->scope_details);
        $this->check(($scope->filters->timerange->start ?? 0) === $start, 'Start boundary changed');
        $this->check(($scope->filters->timerange->end ?? 0) === $end, 'End boundary changed');
    }

    /**
     * Delete approved privacy data through the actual provider and locked lifecycle.
     *
     * @When /^approved AI analysis data for "([^"]*)" in "([^"]*)" is deleted$/
     * @param string $username Data subject
     * @param string $course Approved course
     */
    public function delete_privacy_data(string $username, string $course): void {
        global $DB;

        $user = $DB->get_record('user', ['id' => $this->user_id($username)], '*', MUST_EXIST);
        $context = context_course::instance($DB->get_field('course', 'id', ['shortname' => $course], MUST_EXIST));
        $list = new \core_privacy\local\request\approved_contextlist($user, 'report_ai_analysis', [$context->id]);
        \report_ai_analysis\privacy\provider::delete_data_for_user($list);
    }

    /**
     * Exercise the normal course lifecycle, not the Privacy API's context deletion shortcut.
     *
     * @When /^AI analysis course "([^"]*)" is deleted through the normal course API$/
     * @param string $shortname Course shortname
     */
    public function delete_analysis_course(string $shortname): void {
        global $DB;

        $course = $DB->get_record('course', ['shortname' => $shortname], '*', MUST_EXIST);
        $this->with_user($this->user_id('admin'), static function () use ($course): void {
            delete_course($course, false);
        });
    }

    /**
     * Check cleanup for a queued report which has not made any request yet.
     *
     * @Then /^AI analysis report "([^"]*)" should no longer exist$/
     * @param string $title Report title remembered before deletion
     */
    public function report_no_longer_exists(string $title): void {
        global $DB;

        $this->check(isset($this->reportids[$title]), 'Report was not remembered before deletion');
        $id = $this->reportids[$title];
        $this->check(!$DB->record_exists('report_ai_analysis_reports', ['id' => $id]), 'Report still exists');
        $this->check(!$DB->record_exists('report_ai_analysis_users', ['reportid' => $id]), 'Subject rows still exist');
        $this->check(!$this->report_tasks($id), 'Deleted report still has queued work');
    }

    /**
     * Both a creator and an analysed author must be discovered by the real provider.
     *
     * @Then /^AI analysis privacy data for "([^"]*)" should include course "([^"]*)"$/
     * @param string $username Data subject
     * @param string $course Course shortname
     */
    public function privacy_context_exists(string $username, string $course): void {
        global $DB;

        $context = context_course::instance($DB->get_field('course', 'id', ['shortname' => $course], MUST_EXIST));
        $contexts = \report_ai_analysis\privacy\provider::get_contexts_for_userid($this->user_id($username));
        $this->check(
            in_array((int) $context->id, array_map('intval', $contexts->get_contextids()), true),
            'Subject not discovered'
        );
    }

    /**
     * Check report/map/task cleanup and linked log anonymisation, including retained statistics.
     *
     * @Then /^AI analysis report "([^"]*)" should be deleted with anonymised logs$/
     * @param string $title Report title remembered before deletion
     */
    public function report_is_deleted(string $title): void {
        global $DB;

        $this->report_no_longer_exists($title);
        $id = $this->reportids[$title];
        $logs = $DB->get_records('local_ai_manager_request_log', ['component' => 'report_ai_analysis', 'itemid' => $id]);
        $this->check(count($logs) > 0, 'No generated request logs to check');
        foreach ($logs as $log) {
            $this->check($log->userid === null, 'Log actor was not anonymised');
            foreach (['prompttext', 'promptcompletion', 'requestoptions'] as $field) {
                $this->check($log->{$field} === \local_ai_manager\local\data_wiper::ANONYMIZE_STRING, "Log retained $field");
            }
            $this->check((float) $log->value === 12.0, 'Usage statistics were not retained');
        }
    }

    /**
     * Assert without catching and suppressing driver, PHP or Moodle errors.
     *
     * @param bool $condition Required condition
     * @param string $message Failure explanation
     */
    private function check(bool $condition, string $message): void {
        if (!$condition) {
            throw new ExpectationException($message, $this->getSession());
        }
    }

    /**
     * Guard all fixture execution against a production bootstrap.
     */
    private function require_behat_site(): void {
        if (!defined('BEHAT_SITE_RUNNING') || !BEHAT_SITE_RUNNING) {
            throw new coding_exception('AI analysis fixtures require the Behat site');
        }
    }

    /**
     * Resolve a unique report and remember its ID for post-deletion assertions.
     *
     * @param string $title Report title
     * @return stdClass Report
     */
    private function get_report(string $title): stdClass {
        global $DB;

        $report = $DB->get_record('report_ai_analysis_reports', ['title' => $title], '*', MUST_EXIST);
        $this->reportids[$title] = (int) $report->id;
        return $report;
    }

    /**
     * Resolve a user fixture.
     *
     * @param string $username Username
     * @return int User ID
     */
    private function user_id(string $username): int {
        global $DB;
        return (int) $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
    }

    /**
     * Split human-readable selections without converting an empty selection to all users.
     *
     * @param string $names Comma-separated names
     * @return string[] Names
     */
    private function names(string $names): array {
        return array_values(array_filter(array_map('trim', explode(',', $names)), static fn($name) => $name !== ''));
    }

    /**
     * Build scopes for real manager submissions; authorisation is left to production code.
     *
     * @param int $courseid Course ID
     * @param array $data Readable selections
     * @return scope_builder Scope
     */
    private function make_scope(int $courseid, array $data): scope_builder {
        global $DB;

        $scope = new scope_builder($courseid);
        $scope->set_analysis_mode($data['analysis_mode'] ?? 'aggregated');
        if (array_key_exists('participants', $data)) {
            $scope->filter_by_participants(array_map(fn($name) => $this->user_id($name), $this->names($data['participants'])));
        }
        if (!empty($data['roles'])) {
            $scope->filter_by_roles(array_map(
                fn($name) => (int) $DB->get_field('role', 'id', ['shortname' => $name], MUST_EXIST),
                $this->names($data['roles'])
            ));
        }
        if (!empty($data['groups'])) {
            $scope->with_groups(array_map(fn($name) => (int) $DB->get_field(
                'groups',
                'id',
                ['courseid' => $courseid, 'idnumber' => $name],
                MUST_EXIST
            ), $this->names($data['groups'])));
        }
        if (!empty($data['sources'])) {
            $sources = [];
            foreach ($this->names($data['sources']) as $source) {
                if ($source === 'chat') {
                    $blockid = $DB->get_field('block_instances', 'id', [
                        'parentcontextid' => context_course::instance($courseid)->id, 'blockname' => 'ai_chat',
                    ], MUST_EXIST);
                    $sources[] = 'block_' . context_block::instance($blockid)->id;
                } else {
                    $sources[] = 'cm_' . $DB->get_field(
                        'course_modules',
                        'id',
                        ['course' => $courseid, 'idnumber' => $source],
                        MUST_EXIST
                    );
                }
            }
            $scope->with_sources($sources);
        }
        if (isset($data['timestart']) || isset($data['timeend'])) {
            $scope->with_timerange((int) ($data['timestart'] ?? 0), (int) ($data['timeend'] ?? 0));
        }
        return $scope;
    }

    /**
     * Execute as a real actor and restore user/session/course and tenant-aware DI services.
     *
     * @param int $userid Actor
     * @param Closure $callback Operation
     * @param int $courseid Optional course context
     * @return mixed Callback result
     */
    private function with_user(int $userid, Closure $callback, int $courseid = 0): mixed {
        global $USER, $SESSION, $COURSE;

        $this->require_behat_site();
        $originaluser = clone $USER;
        $originalsession = clone $SESSION;
        $originalcourse = $COURSE;
        $services = [];
        foreach ([tenant::class, config_manager::class, connector_factory::class] as $service) {
            $services[$service] = \core\di::get($service);
        }
        try {
            self::set_user($userid);
            if ($courseid) {
                $COURSE = get_course($courseid);
            }
            $tenant = new tenant();
            $config = new config_manager($tenant);
            \core\di::set(tenant::class, $tenant);
            \core\di::set(config_manager::class, $config);
            \core\di::set(connector_factory::class, new connector_factory($config));
            return $callback();
        } finally {
            self::set_user($originaluser);
            $SESSION = $originalsession;
            $COURSE = $originalcourse;
            foreach ($services as $service => $instance) {
                \core\di::set($service, $instance);
            }
        }
    }

    /**
     * Fetch only this report's tasks; unrelated queue entries cannot satisfy assertions.
     *
     * @param int $reportid Report ID
     * @return stdClass[] Matching records
     */
    private function report_tasks(int $reportid): array {
        global $DB;

        $tasks = $DB->get_records('task_adhoc', ['classname' => self::TASK_CLASS], 'id');
        return array_filter($tasks, static function (stdClass $task) use ($reportid): bool {
            $data = json_decode($task->customdata, false, 512, JSON_THROW_ON_ERROR);
            return (int) ($data->reportid ?? 0) === $reportid;
        });
    }

    /**
     * Run exactly one actual locked task; leave newly scheduled retries/new generations queued.
     *
     * @param string $title Report title
     * @param int $code Response code
     * @param string $content Synthetic HTML
     * @param Closure|null $duringrequest Mutation callback
     */
    private function run_task(
        string $title,
        int $code = 200,
        string $content = '<p>AI fixture result {request}</p>',
        ?Closure $duringrequest = null
    ): void {
        $this->require_behat_site();
        require_once(__DIR__ . '/fixtures/request_provider.php');
        $report = $this->get_report($title);
        $tasks = $this->report_tasks((int) $report->id);
        $this->check(count($tasks) === 1, 'Expected exactly one queued report task, not a synthetic unqueued worker');
        $record = reset($tasks);
        $this->check((int) $record->userid > 0, 'Queued task has no executing user');
        $provider = new \report_ai_analysis\local\behat_request_provider((int) $report->id, $code, $content, $duringrequest);
        $originalprovider = \core\di::get(ai_request_provider::class);
        $this->taskstarted = time();
        try {
            \core\di::set(ai_request_provider::class, $provider);
            $this->with_user((int) $record->userid, function () use ($record): void {
                $task = \core\task\manager::get_adhoc_task((int) $record->id);
                $this->check($task !== null, 'Queued task is locked by another runner');
                try {
                    \core\task\manager::adhoc_task_starting($task);
                    $task->execute();
                } catch (Throwable $exception) {
                    \core\task\manager::adhoc_task_failed($task);
                    throw $exception;
                }
                \core\task\manager::adhoc_task_complete($task);
            }, (int) context::instance_by_id($report->contextid)->instanceid);
        } finally {
            $this->requests = $provider->requests;
            $this->taskfinished = time();
            \core\di::set(ai_request_provider::class, $originalprovider);
        }
    }

    /**
     * Snapshot all report-owned data touched by a denied action.
     *
     * @param int $reportid Report ID
     * @return string Stable snapshot
     */
    private function snapshot(int $reportid): string {
        global $DB;

        return json_encode([
            $DB->get_record('report_ai_analysis_reports', ['id' => $reportid]),
            $DB->get_records('report_ai_analysis_users', ['reportid' => $reportid], 'id'),
            $this->report_tasks($reportid),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Build real endpoint URLs with the browser's actual session key.
     *
     * @param stdClass $report Report
     * @param string $action Endpoint action
     * @return moodle_url URL
     */
    private function report_url(stdClass $report, string $action): moodle_url {
        $courseid = context::instance_by_id($report->contextid)->instanceid;
        $sesskey = $this->getSession()->evaluateScript('return M.cfg.sesskey;');
        return match ($action) {
            'view' => new moodle_url('/report/ai_analysis/view.php', ['id' => $report->id]),
            'edit' => new moodle_url('/report/ai_analysis/create.php', ['reportid' => $report->id, 'courseid' => $courseid]),
            'export' => new moodle_url(
                '/report/ai_analysis/export.php',
                ['id' => $report->id, 'format' => 'json', 'sesskey' => $sesskey]
            ),
            'rerun' => new moodle_url(
                '/report/ai_analysis/rerun.php',
                ['id' => $report->id, 'confirm' => 1, 'sesskey' => $sesskey]
            ),
            default => new moodle_url('/report/ai_analysis/index.php', [
                'courseid' => $courseid, 'reportid' => $report->id, 'action' => $action, 'confirm' => 1, 'sesskey' => $sesskey,
            ]),
        };
    }

    /**
     * Make an authenticated local request without navigating the browser onto an expected error page.
     *
     * Redirects, network errors and PHP failures are not accepted as permission denials.
     *
     * @param moodle_url $url Local plugin endpoint
     * @return array Response body/status
     */
    private function http_request(moodle_url $url): array {
        global $CFG;

        $this->require_behat_site();
        require_once($CFG->libdir . '/filelib.php');
        $sessionname = 'MoodleSession' . ($CFG->sessioncookie ?? '');
        $sessionid = $this->getSession()->getCookie($sessionname);
        $this->check(!empty($sessionid), 'No authenticated browser session for direct request');
        $curl = new curl(['ignoresecurity' => true]);
        $curl->setHeader('Cookie: ' . $sessionname . '=' . $sessionid);
        $body = $curl->get($url->out(false), [], ['CURLOPT_FOLLOWLOCATION' => false]);
        $this->check($curl->get_errno() === 0, 'Local endpoint request failed: ' . $curl->error);
        $info = $curl->get_info();
        $status = (int) $info['http_code'];
        $this->check(
            $status >= 200 && $status < 500 && ($status < 300 || $status >= 400),
            "Unexpected redirect or server failure: HTTP $status"
        );
        return ['body' => $body, 'status' => $status];
    }

    /**
     * Reject active elements and event-handler/JavaScript URL attributes in presentation HTML.
     *
     * @param string $html Exported or rendered HTML
     */
    private function assert_safe_html(string $html): void {
        $document = new DOMDocument();
        $this->check(
            $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING),
            'Could not parse presentation HTML'
        );
        $xpath = new DOMXPath($document);
        $this->check($xpath->query('//script|//iframe|//object|//embed')->length === 0, 'Presentation contains active elements');
        foreach ($xpath->query('//@*') as $attribute) {
            $this->check(!str_starts_with(strtolower($attribute->nodeName), 'on'), 'Presentation contains an event handler');
            if (in_array(strtolower($attribute->nodeName), ['href', 'src', 'xlink:href'], true)) {
                $value = preg_replace('/\s+/', '', $attribute->nodeValue);
                $this->check(!str_starts_with(strtolower($value), 'javascript:'), 'Presentation contains a JavaScript URL');
            }
        }
    }
}
