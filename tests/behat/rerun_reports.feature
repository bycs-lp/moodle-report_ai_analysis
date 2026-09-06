@report @report_ai_analysis @javascript
Feature: Re-run AI Analysis Reports
  In order to update analysis results
  As a teacher
  I need to be able to re-run completed or failed reports

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "report_ai_analysis > reports" exist:
      | title              | course | user     | status    | prompt                    | error_code   | error_message                  | error_details              |
      | Completed Report   | C1     | teacher1 | completed | Completed analysis        |              |                                |                            |
      | Failed Report      | C1     | teacher1 | failed    | Failed analysis           | error_unknown | Unknown error occurred         | Sensitive connector data   |
      | Cancelled Report   | C1     | teacher1 | cancelled | Cancelled analysis        |              |                                |                            |
      | Pending Report     | C1     | teacher1 | pending   | Pending analysis          |              |                                |                            |
      | Running Report     | C1     | teacher1 | running   | Currently running         |              |                                |                            |
    And the AI analysis backend is configured

  Scenario: Re-run resets a terminal report and deletion removes queued work
    Given the following "report_ai_analysis > reports" exist:
      | title            | course | user     | status  | prompt    | queue_task |
      | Report to Delete | C1     | teacher1 | pending | Remove me | 1          |
    And I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    Then I should see "Re-run" in the "Completed Report" "table_row"
    And I should see "Re-run" in the "Failed Report" "table_row"
    And I should see "Re-run" in the "Cancelled Report" "table_row"
    And I should not see "Re-run" in the "Pending Report" "table_row"
    And I should not see "Re-run" in the "Running Report" "table_row"
    And I remember AI analysis report "Failed Report"
    When I click on "Re-run" "link" in the "Failed Report" "table_row"
    Then I should see "Are you sure you want to re-run"
    And I should see "Failed Report"
    When I press "Continue"
    Then I should see "Pending"
    And the report "Failed Report" should have no stored error data
    And AI analysis report "Failed Report" should have a fresh generation
    And AI analysis report "Failed Report" should have "1" queued tasks
    And an adhoc task "report_ai_analysis\task\process_analysis_task" should exist for user "teacher1"
    And I remember AI analysis report "Report to Delete"
    And AI analysis report "Report to Delete" should have "1" queued tasks
    When I click on "Delete" "link" in the "Report to Delete" "table_row"
    And I press "Continue"
    Then I should see "Report deleted successfully"
    And I should not see "Report to Delete"
    And AI analysis report "Report to Delete" should have "0" queued tasks

  Scenario: Direct re-run enforces session key, report state and the rerun capability
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    Then a direct "rerun" request for AI analysis report "Completed Report" with an invalid sesskey should be denied
    And AI analysis report "Pending Report" should reject direct "rerun"
    And AI analysis report "Running Report" should reject direct "rerun"
    And AI analysis report "Running Report" should reject direct "edit"
    Given the following "permission overrides" exist:
      | capability               | permission | role           | contextlevel | reference |
      | report/ai_analysis:rerun | Prohibit   | editingteacher | Course       | C1        |
    When I navigate to "Reports > AI Conversation Analysis" in current page administration
    Then I should not see "Re-run" in the "Completed Report" "table_row"
    And a direct rerun request for AI analysis report "Completed Report" should be rejected
