@report @report_ai_analysis @javascript
Feature: Export AI Analysis Reports
  In order to share or archive analysis results
  As a teacher
  I need to be able to export reports in different formats

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
      | title              | course | user     | status    | prompt            | ai_result          |
      | Export Test Report | C1     | teacher1 | completed | Test for export   | This is the result |

  Scenario: Pending reports cannot be exported
    Given the following "report_ai_analysis > reports" exist:
      | title           | course | user     | status  | prompt          |
      | Pending Report  | C1     | teacher1 | pending | Not yet ready   |
    And I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    Then I should not see "Export" in the "Pending Report" "table_row"

  # Note: Export functionality from view page cannot be tested in Behat due to session conflicts
  # This functionality is covered by unit tests and manual testing
