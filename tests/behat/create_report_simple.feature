@report @report_ai_analysis @javascript
Feature: Create AI Analysis Reports - Simple Tests
  In order to analyze AI conversation data
  As a teacher
  I need to be able to create new analysis reports

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

  Scenario: Teacher can open the create report form
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    When I click on "New analysis" "link"
    Then I should see "Create new analysis"
    And I should see "Title"
    And I should see "Analysis prompt"
    And I should see "Scope"

  Scenario: Prompt field is required
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    And I click on "New analysis" "link"
    When I set the field "Title" to "Test Analysis"
    And I press "Create new analysis"
    Then I should see "Required"
