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
    And the AI analysis backend is configured
    And the following config values are set as admin:
      | debugdisplay               | 0 |                    |
      | forceclean                 | 0 |                    |
      | enable_markdown_conversion | 1 | report_ai_analysis |

  Scenario: Completed and failed reports export in both formats while others are rejected
    Given the following "report_ai_analysis > reports" exist:
      | title          | course | user     | status  | prompt                    | error_code       | error_details         |
      | Failed export  | C1     | teacher1 | failed  | Explain the failed report | error_ai_request | PRIVATE_DEBUG_DETAILS |
      | Pending export | C1     | teacher1 | pending | Not yet ready             |                  |                       |
      | Running export | C1     | teacher1 | running | Still processing          |                  |                       |
    And I am on the "Course 1" "Course" page logged in as "teacher1"
    When I view the AI analysis report "Export Test Report"
    Then I should see "Export as JSON"
    And I should see "Export as HTML"
    And the "json" export of AI analysis report "Export Test Report" should contain safe presentation data
    And the "html" export of AI analysis report "Export Test Report" should contain safe presentation data
    When I view the AI analysis report "Failed export"
    Then I should see "Export as JSON"
    And I should not see "PRIVATE_DEBUG_DETAILS"
    And the "json" export of AI analysis report "Failed export" should contain safe presentation data
    And the "html" export of AI analysis report "Failed export" should contain safe presentation data
    And AI analysis report "Pending export" should reject direct export
    And AI analysis report "Running export" should reject direct export

  Scenario: Export enforces the separate raw-data capability
    Given the following "permission overrides" exist:
      | capability                     | permission | role           | contextlevel | reference |
      | report/ai_analysis:viewrawdata | Prohibit   | editingteacher | Course       | C1        |
    And I am on the "Course 1" "Course" page logged in as "teacher1"
    Then the JSON export of AI analysis report "Export Test Report" should omit raw data
