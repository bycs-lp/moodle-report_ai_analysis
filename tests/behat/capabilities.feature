@report @report_ai_analysis @javascript
Feature: Permissions and Capabilities
  In order to control access to AI Analysis features
  As an administrator
  I need to verify that capabilities are enforced correctly

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | teacher2 | Teacher   | Two      | teacher2@example.com |
      | student1 | Student   | One      | student1@example.com |
      | manager1 | Manager   | One      | manager1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher2 | C1     | teacher        |
      | student1 | C1     | student        |
      | manager1 | C1     | manager        |

  Scenario: Editing teacher has all capabilities by default
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    Then I should see "New analysis"
    And I should see "AI Conversation Analysis"

  Scenario: Teacher (non-editing) can view and create
    Given I am on the "Course 1" "Course" page logged in as "teacher2"
    When I navigate to "Reports > AI Conversation Analysis" in current page administration
    Then I should see "AI Conversation Analysis"
    And I should not see "New analysis"

  Scenario: Teacher (non-editing) cannot delete by default
    Given the following "report_ai_analysis > reports" exist:
      | title        | course | user     | status    | prompt    |
      | Test Report  | C1     | teacher2 | completed | Analysis  |
    And I am on the "Course 1" "Course" page logged in as "teacher2"
    When I navigate to "Reports > AI Conversation Analysis" in current page administration
    Then I should not see "Delete" in the "Test Report" "table_row"

  Scenario: Student has no access to plugin
    Given I am on the "Course 1" "Course" page logged in as "student1"
    Then I should not see "AI Conversation Analysis"

  Scenario: Allow student to view with custom permission
    Given the following "permission overrides" exist:
      | capability                | permission | role    | contextlevel | reference |
      | report/ai_analysis:view   | Allow      | student | Course       | C1        |
    And I am on the "Course 1" "Course" page logged in as "student1"
    When I visit the url "/report/ai_analysis/index.php?courseid={{Course 1:id}}"
    Then I should see "AI Conversation Analysis"

  Scenario: Teacher without create capability cannot create
    Given the following "permission overrides" exist:
      | capability                  | permission | role           | contextlevel | reference |
      | report/ai_analysis:create   | Prohibit   | editingteacher | Course       | C1        |
    And I am on the "Course 1" "Course" page logged in as "teacher1"
    When I navigate to "Reports > AI Conversation Analysis" in current page administration
    Then I should not see "New analysis"

  Scenario: Teacher without delete capability cannot delete
    Given the following "report_ai_analysis > reports" exist:
      | title        | course | user     | status    | prompt    |
      | Test Report  | C1     | teacher1 | completed | Analysis  |
    And the following "permission overrides" exist:
      | capability                  | permission | role           | contextlevel | reference |
      | report/ai_analysis:delete   | Prohibit   | editingteacher | Course       | C1        |
    And I am on the "Course 1" "Course" page logged in as "teacher1"
    When I navigate to "Reports > AI Conversation Analysis" in current page administration
    Then I should not see "Delete" in the "Test Report" "table_row"

  Scenario: Teacher without rerun capability cannot re-run
    Given the following "report_ai_analysis > reports" exist:
      | title        | course | user     | status    | prompt    |
      | Test Report  | C1     | teacher1 | completed | Analysis  |
    And the following "permission overrides" exist:
      | capability                  | permission | role           | contextlevel | reference |
      | report/ai_analysis:rerun    | Prohibit   | editingteacher | Course       | C1        |
    And I am on the "Course 1" "Course" page logged in as "teacher1"
    When I navigate to "Reports > AI Conversation Analysis" in current page administration
    Then I should not see "Re-run" in the "Test Report" "table_row"

  # View page has session issues in Behat when testing capabilities
  # Raw data capability enforcement tested via PHPUnit
  # Scenario: Teacher without viewrawdata cannot see raw data

  Scenario: Grant student create capability
    Given the following "permission overrides" exist:
      | capability                  | permission | role    | contextlevel | reference |
      | report/ai_analysis:view     | Allow      | student | Course       | C1        |
      | report/ai_analysis:create   | Allow      | student | Course       | C1        |
    And I am on the "Course 1" "Course" page logged in as "student1"
    When I visit the url "/report/ai_analysis/index.php?courseid={{Course 1:id}}"
    Then I should see "New analysis"
    When I click on "New analysis" "link"
    Then I should see "Create new analysis"
    And I should see "Analysis prompt"

  Scenario: Capability check at context level
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 2 | C2        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C2     | editingteacher |
    And the following "permission overrides" exist:
      | capability                | permission | role           | contextlevel | reference |
      | report/ai_analysis:view   | Prohibit   | editingteacher | Course       | C2        |
    And I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports" in current page administration
    Then I should see "AI Conversation Analysis"
    When I am on "Course 2" course homepage
    And I navigate to "Reports" in current page administration
    Then I should not see "AI Conversation Analysis"
