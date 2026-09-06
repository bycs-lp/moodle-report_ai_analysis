@report @report_ai_analysis @javascript
Feature: View AI Analysis Reports
  In order to review analysis results
  As a teacher or manager
  I need to be able to view report details

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | manager1 | Manager   | One      | manager1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | manager1 | C1     | manager        |
    And the following "report_ai_analysis > reports" exist:
      | title              | course | user     | status    | prompt                    | error_code               | error_message                                                                            | error_details                |
      | Completed Analysis | C1     | teacher1 | completed | Analyze conversations     |                          |                                                                                          |                              |
      | Pending Analysis   | C1     | teacher1 | pending   | Waiting for processing    |                          |                                                                                          |                              |
      | Failed Analysis    | C1     | teacher1 | failed    | This analysis failed      | error_terms_not_accepted | You have not yet accepted the AI terms of use. Accept them before re-running the report. | Sensitive connector details  |
      | Running Analysis   | C1     | teacher1 | running   | Currently processing      |                          |                                                                                          |                              |
    And the AI analysis backend is configured

  Scenario: Reports show status, localized errors, gate technical detail and render markup safely
    Given the following config values are set as admin:
      | debug        | 32767 |
      | debugdisplay | 0     |
    And AI analysis report "Completed Analysis" contains unsafe presentation fixtures
    And the following config values are set as admin:
      | enable_markdown_conversion | 1 | report_ai_analysis |
    And I am on the "Course 1" "Course" page logged in as "teacher1"
    When I navigate to "Reports > AI Conversation Analysis" in current page administration
    Then I should see "Completed" in the "Completed Analysis" "table_row"
    And I should see "Pending" in the "Pending Analysis" "table_row"
    And I should see "Failed" in the "Failed Analysis" "table_row"
    And I should see "Running" in the "Running Analysis" "table_row"
    And I should see "You have not yet accepted the AI terms of use" in the "Failed Analysis" "table_row"
    And I should not see "Sensitive connector details"
    When I view the AI analysis report "Failed Analysis"
    Then I should see "You have not yet accepted the AI terms of use" in the ".alert-danger" "css_element"
    And I should see "Review and accept AI terms of use" in the ".alert-danger" "css_element"
    And I should not see "Sensitive connector details"
    When I view the AI analysis report "Completed Analysis"
    Then I should see "Safe prompt"
    And I should see "Safe result"
    And the AI analysis presentation should be safe

  Scenario: Reports table supports pagination
    Given "30" AI analysis reports with prefix "Page report" by "teacher1" exist in "C1"
    When I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    Then I should see "Page report 30"
    And I should not see "Page report 01"
    When I visit the url "/report/ai_analysis/index.php?courseid={{C1:id}}&page=1"
    Then I should see "Page report 01"
    And I should not see "Page report 30"
