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
 * Custom Behat steps for report_ai_analysis.
 *
 * @package    report_ai_analysis
 * @category   test
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;

/**
 * Custom Behat steps for AI Analysis Report.
 *
 * @package    report_ai_analysis
 * @copyright  2025 ISB Bayern
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_report_ai_analysis extends behat_base {
    /**
     * Verify that an element appears before another in the DOM.
     *
     * @Then /^"(?P<element1_string>(?:[^"]|\\")*)" should appear before "(?P<element2_string>(?:[^"]|\\")*)"$/
     * @param string $element1 The first element text
     * @param string $element2 The second element text
     * @throws ExpectationException
     */
    public function should_appear_before($element1, $element2) {
        $xpath1 = "(//*[contains(text(), '" . $element1 . "')])[1]";
        $xpath2 = "(//*[contains(text(), '" . $element2 . "')])[1]";

        $node1 = $this->find('xpath', $xpath1);
        $node2 = $this->find('xpath', $xpath2);

        // Get position in document.
        $script = "return arguments[0].compareDocumentPosition(arguments[1])";
        $result = $this->getSession()->evaluateScript($script, [$node1, $node2]);

        // DOCUMENT_POSITION_FOLLOWING = 4 means node2 follows node1.
        if (!($result & 4)) {
            throw new ExpectationException(
                "Element '$element1' does not appear before '$element2'",
                $this->getSession()
            );
        }
    }

    /**
     * Check if an adhoc task exists in the queue.
     *
     * @Then /^an adhoc task "(?P<taskclass_string>(?:[^"]|\\")*)" should exist$/
     * @param string $taskclass The full class name of the task
     * @throws ExpectationException
     */
    public function adhoc_task_should_exist($taskclass) {
        global $DB;

        $exists = $DB->record_exists('task_adhoc', ['classname' => '\\' . ltrim($taskclass, '\\')]);

        if (!$exists) {
            throw new ExpectationException(
                "Adhoc task '$taskclass' was not found in the queue",
                $this->getSession()
            );
        }
    }

    /**
     * Check if an adhoc task exists in the queue for a specific user.
     *
     * @Then /^an adhoc task "(?P<taskclass_string>(?:[^"]|\\")*)" should exist for user "(?P<username_string>(?:[^"]|\\")*)"$/
     * @param string $taskclass The full class name of the task
     * @param string $username The username assigned to the task
     * @throws ExpectationException
     */
    public function adhoc_task_should_exist_for_user($taskclass, $username) {
        global $DB;

        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $exists = $DB->record_exists('task_adhoc', [
            'classname' => '\\' . ltrim($taskclass, '\\'),
            'userid' => $userid,
        ]);

        if (!$exists) {
            throw new ExpectationException(
                "Adhoc task '$taskclass' was not found in the queue for user '$username'",
                $this->getSession()
            );
        }
    }

    /**
     * Open an AI analysis report by title.
     *
     * @When /^I view the AI analysis report "(?P<title_string>(?:[^"]|\\")*)"$/
     * @param string $title The report title
     */
    public function i_view_ai_analysis_report(string $title): void {
        $link = $this->get_selected_node('link', $title);
        $this->getSession()->executeScript(
            'const topbar = document.querySelector("bycs-topbar"); if (topbar) { topbar.style.display = "none"; }'
        );
        $link->click();
    }

    /**
     * Verify that a direct rerun request is rejected.
     *
     * @Then /^a direct rerun request for AI analysis report "(?P<title_string>(?:[^"]|\\")*)" should be rejected$/
     * @param string $title Report title
     * @throws ExpectationException
     */
    public function direct_rerun_request_should_be_rejected(string $title): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/filelib.php');

        $reportid = $DB->get_field('report_ai_analysis_reports', 'id', ['title' => $title], MUST_EXIST);
        $sesskey = $this->getSession()->evaluateScript('return M.cfg.sesskey;');
        $url = new moodle_url('/report/ai_analysis/rerun.php', [
            'id' => $reportid,
            'sesskey' => $sesskey,
        ]);

        $sessionname = 'MoodleSession' . ($CFG->sessioncookie ?? '');
        $sessionid = $this->getSession()->getCookie($sessionname);
        $curl = new curl(['ignoresecurity' => true]);
        $curl->setHeader("Cookie: {$sessionname}={$sessionid}");
        $response = $curl->get($url->out(false));

        if (!str_contains($response, 'nopermissions')) {
            throw new ExpectationException(
                "Direct rerun request for report '$title' was not rejected for missing capability",
                $this->getSession()
            );
        }
    }

    /**
     * Visit a URL with dynamic parameters (allows exceptions).
     *
     * @When /^I visit the url "(?P<url_string>(?:[^"]|\\")*)"$/
     * @param string $url The URL to visit (relative to Moodle root)
     */
    public function i_visit_the_url($url) {
        global $CFG, $DB;

        // Replace placeholders like {{Course 1:id}}.
        if (preg_match_all('/\{\{([^:]+):([^}]+)\}\}/', $url, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $entityname = trim($match[1]);
                $field = trim($match[2]);

                // Try to find the entity in the database.
                if ($field === 'id') {
                    // Look for course by fullname or shortname.
                    $record = $DB->get_record('course', ['fullname' => $entityname], 'id');
                    if (!$record) {
                        $record = $DB->get_record('course', ['shortname' => $entityname], 'id');
                    }
                    if ($record) {
                        $url = str_replace($match[0], $record->id, $url);
                    }
                }
            }
        }

        $fullurl = $CFG->wwwroot . $url;

        // Visit URL - Moodle will show error page if permissions fail, which we can then test.
        $this->getSession()->visit($fullurl);
    }

    /**
     * Visit a URL expecting an exception/error page.
     *
     * @When /^I visit the url "(?P<url_string>(?:[^"]|\\")*)" expecting an error$/
     * @param string $url The URL to visit (relative to Moodle root)
     */
    public function i_visit_the_url_expecting_error($url) {
        global $CFG, $DB;

        // Replace placeholders like {{Course 1:id}}.
        if (preg_match_all('/\{\{([^:]+):([^}]+)\}\}/', $url, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $entityname = trim($match[1]);
                $field = trim($match[2]);

                // Try to find the entity in the database.
                if ($field === 'id') {
                    // Look for course by fullname or shortname.
                    $record = $DB->get_record('course', ['fullname' => $entityname], 'id');
                    if (!$record) {
                        $record = $DB->get_record('course', ['shortname' => $entityname], 'id');
                    }
                    if ($record) {
                        $url = str_replace($match[0], $record->id, $url);
                    }
                }
            }
        }

        $fullurl = $CFG->wwwroot . $url;

        // Visit URL - we expect an error page which is ok.
        // We need to suppress any exceptions that Behat sees during page load.
        try {
            $this->getSession()->visit($fullurl);
        } catch (\Exception $e) {
            // Exception is expected when visiting a page that shows an error.
            // This happens when the user doesn't have permission or there's a validation error.
            // The error page is successfully loaded, so we can continue to verify its content.
            // Intentionally empty to allow error page verification in subsequent steps.
            unset($e); // Suppress unused variable warning.
        }
    }

    /**
     * Visit a node (URL path).
     *
     * @When /^I navigate to "(?P<nodetext_string>(?:[^"]|\\")*)" node$/
     * @param string $nodetext The node path/URL
     */
    public function i_navigate_to_node($nodetext) {
        $this->i_visit_the_url($nodetext);
    }

    /**
     * Store a value from the page for later comparison.
     *
     * @When /^I note the "(?P<field_string>(?:[^"]|\\")*)" value$/
     * @param string $field The field label or identifier
     */
    public function i_note_the_value($field) {
        // Store in Behat context for later retrieval.
        $xpath = "//dt[contains(text(), '$field')]/following-sibling::dd[1]";
        $node = $this->find('xpath', $xpath);
        $value = $node->getText();

        // Store in session for later comparison.
        $this->getSession()->setCookie('behat_noted_' . md5($field), $value);
    }

    /**
     * Verify that a noted value has not changed.
     *
     * @Then /^the "(?P<field_string>(?:[^"]|\\")*)" value should be unchanged$/
     * @param string $field The field label or identifier
     * @throws ExpectationException
     */
    public function the_value_should_be_unchanged($field) {
        $xpath = "//dt[contains(text(), '$field')]/following-sibling::dd[1]";
        $node = $this->find('xpath', $xpath);
        $currentvalue = $node->getText();

        $notedvalue = $this->getSession()->getCookie('behat_noted_' . md5($field));

        if ($currentvalue !== $notedvalue) {
            throw new ExpectationException(
                "The value for '$field' has changed from '$notedvalue' to '$currentvalue'",
                $this->getSession()
            );
        }
    }

    /**
     * Verify a report's retry count.
     *
     * @Then /^the report "(?P<title_string>(?:[^"]|\\")*)" should have retry count "(?P<count_number>\d+)"$/
     * @param string $title The report title
     * @param int $count Expected retry count
     * @throws ExpectationException
     */
    public function report_should_have_retry_count($title, $count) {
        global $DB;

        $report = $DB->get_record('report_ai_analysis_reports', ['title' => $title], 'retry_count');

        if (!$report) {
            throw new ExpectationException(
                "Report with title '$title' not found",
                $this->getSession()
            );
        }

        if ($report->retry_count != $count) {
            throw new ExpectationException(
                "Report retry count is {$report->retry_count}, expected $count",
                $this->getSession()
            );
        }
    }

    /**
     * Verify that a report has no stored error data.
     *
     * @Then /^the report "(?P<title_string>(?:[^"]|\\")*)" should have no stored error data$/
     * @param string $title Report title
     * @throws ExpectationException
     */
    public function report_should_have_no_stored_error_data(string $title): void {
        global $DB;

        $report = $DB->get_record(
            'report_ai_analysis_reports',
            ['title' => $title],
            'error_message, error_details, error_code',
            MUST_EXIST
        );
        if (!empty($report->error_message) || !empty($report->error_details) || !empty($report->error_code)) {
            throw new ExpectationException(
                "Report '$title' still contains stored error data",
                $this->getSession()
            );
        }
    }
}
