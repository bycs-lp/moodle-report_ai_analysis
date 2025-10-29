@report @report_ai_analysis @javascript
Feature: Basic navigation and access to AI Analysis Report
  In order to use the AI Analysis Report plugin
  As a teacher or manager
  I need to be able to access the plugin from the course navigation

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
      | manager1 | Manager   | One      | manager1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | manager1 | C1     | manager        |

  Scenario: Teacher can see AI Analysis Report link in course navigation
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    When I navigate to "Reports" in current page administration
    Then I should see "AI Conversation Analysis"

  Scenario: Manager can see AI Analysis Report link in course navigation
    Given I am on the "Course 1" "Course" page logged in as "manager1"
    When I navigate to "Reports" in current page administration
    Then I should see "AI Conversation Analysis"

  Scenario: Student cannot see AI Analysis Report link in course navigation
    Given I am on the "Course 1" "Course" page logged in as "student1"
    Then "Reports" "link" should not exist in current page administration

  Scenario: Teacher can access the AI Analysis Report index page
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    When I navigate to "Reports > AI Conversation Analysis" in current page administration
    Then I should see "AI Conversation Analysis"
    And I should see "New analysis"
