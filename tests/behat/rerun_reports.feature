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
      | title              | course | userid   | status    | prompt                    |
      | Completed Report   | C1     | teacher1 | completed | Completed analysis        |
      | Failed Report      | C1     | teacher1 | failed    | Failed analysis           |
      | Cancelled Report   | C1     | teacher1 | cancelled | Cancelled analysis        |
      | Pending Report     | C1     | teacher1 | pending   | Pending analysis          |
      | Running Report     | C1     | teacher1 | running   | Currently running         |

  Scenario: Re-run link is visible for completed reports
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    When I navigate to "Reports > AI Conversation Analysis" in current page administration
    Then I should see "Re-run" in the "Completed Report" "table_row"

  Scenario: Re-run link is visible for failed reports
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    When I navigate to "Reports > AI Conversation Analysis" in current page administration
    Then I should see "Re-run" in the "Failed Report" "table_row"

  Scenario: Re-run link is visible for cancelled reports
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    When I navigate to "Reports > AI Conversation Analysis" in current page administration
    Then I should see "Re-run" in the "Cancelled Report" "table_row"

  Scenario: Re-run link is not visible for pending reports
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    When I navigate to "Reports > AI Conversation Analysis" in current page administration
    Then I should not see "Re-run" in the "Pending Report" "table_row"

  Scenario: Re-run link is not visible for running reports
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    When I navigate to "Reports > AI Conversation Analysis" in current page administration
    Then I should not see "Re-run" in the "Running Report" "table_row"

  Scenario: Re-run shows confirmation page
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    When I click on "Re-run" "link" in the "Completed Report" "table_row"
    Then I should see "Are you sure you want to re-run"
    And I should see "Completed Report"

  Scenario: Can confirm re-running a report
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    And I click on "Re-run" "link" in the "Completed Report" "table_row"
    When I press "Continue"
    Then I should see "queued"
    And I should see "Pending"

  Scenario: Re-running resets report status
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    And I click on "Re-run" "link" in the "Failed Report" "table_row"
    When I press "Continue"
    Then I should see "Pending"

  Scenario: Re-running queues adhoc task
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    And I click on "Re-run" "link" in the "Completed Report" "table_row"
    When I click on "Continue" "button"
    Then an adhoc task "report_ai_analysis\task\process_analysis_task" should exist

  Scenario: Teacher without rerun capability cannot re-run
    Given the following "permission overrides" exist:
      | capability                     | permission | role           | contextlevel | reference |
      | report/ai_analysis:rerun       | Prohibit   | editingteacher | Course       | C1        |
    And I am on the "Course 1" "Course" page logged in as "teacher1"
    When I navigate to "Reports > AI Conversation Analysis" in current page administration
    Then I should not see "Re-run" in the "Completed Report" "table_row"
