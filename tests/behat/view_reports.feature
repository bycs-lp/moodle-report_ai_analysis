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
      | title              | course | userid   | status    | prompt                    |
      | Completed Analysis | C1     | teacher1 | completed | Analyze conversations     |
      | Pending Analysis   | C1     | teacher1 | pending   | Waiting for processing    |
      | Failed Analysis    | C1     | teacher1 | failed    | This analysis failed      |
      | Running Analysis   | C1     | teacher1 | running   | Currently processing      |

  Scenario: Teacher can view list of reports
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    When I navigate to "Reports > AI Conversation Analysis" in current page administration
    Then I should see "Completed Analysis"
    And I should see "Pending Analysis"
    And I should see "Failed Analysis"
    And I should see "Running Analysis"

  Scenario: Reports show correct status badges
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    When I navigate to "Reports > AI Conversation Analysis" in current page administration
    Then I should see "Completed" in the "Completed Analysis" "table_row"
    And I should see "Pending" in the "Pending Analysis" "table_row"
    And I should see "Failed" in the "Failed Analysis" "table_row"
    And I should see "Running" in the "Running Analysis" "table_row"

  Scenario: View actions are available based on status
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    Then I should see "Delete" in the "Completed Analysis" "table_row"
    And I should see "Export" in the "Completed Analysis" "table_row"
    And I should see "Re-run" in the "Completed Analysis" "table_row"

  Scenario: Reports table supports pagination
    Given the following "report_ai_analysis > reports" exist:
      | title      | course | userid   | status    | prompt    |
      | Report 01  | C1     | teacher1 | completed | Analysis  |
      | Report 02  | C1     | teacher1 | completed | Analysis  |
      | Report 03  | C1     | teacher1 | completed | Analysis  |
      | Report 04  | C1     | teacher1 | completed | Analysis  |
      | Report 05  | C1     | teacher1 | completed | Analysis  |
      | Report 06  | C1     | teacher1 | completed | Analysis  |
      | Report 07  | C1     | teacher1 | completed | Analysis  |
      | Report 08  | C1     | teacher1 | completed | Analysis  |
      | Report 09  | C1     | teacher1 | completed | Analysis  |
      | Report 10  | C1     | teacher1 | completed | Analysis  |
      | Report 11  | C1     | teacher1 | completed | Analysis  |
      | Report 12  | C1     | teacher1 | completed | Analysis  |
    When I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    Then I should see "Report 01"
    And I should see "Report 12"
