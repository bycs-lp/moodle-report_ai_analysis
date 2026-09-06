@report @report_ai_analysis @javascript
Feature: Genuine AI availability and consent
  Reports must use configured backend availability rather than production test bypasses.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the AI analysis backend is configured
    And the following "report_ai_analysis > reports" exist:
      | title             | course | user     | status    | prompt                      |
      | Existing analysis | C1     | teacher1 | completed | Analyse permitted course data |

  Scenario: Available backend exposes information, quota and data protection notice
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    When I click on "New analysis" "link"
    Then the "Create new analysis" "button" should be enabled
    And "#report-ai-analysis-form-info [data-region='ai-info']" "css_element" should exist
    And "#report-ai-analysis-form-info [data-region='ai-quota']" "css_element" should exist
    And I should see "Disabling raw data storage only removes this report's source copy"