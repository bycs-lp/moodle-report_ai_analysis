@report @report_ai_analysis @javascript
Feature: Create AI Analysis Reports
  In order to analyze AI conversation data
  As a teacher
  I need to be able to create new analysis reports with various filters

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
      | student2 | Student   | Two      | student2@example.com |
      | student3 | Student   | Three    | student3@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
    And the following "groups" exist:
      | name    | course | idnumber |
      | Group 1 | C1     | G1       |
      | Group 2 | C1     | G2       |
    And the following "group members" exist:
      | user     | group |
      | student1 | G1    |
      | student2 | G2    |
    And the following "activities" exist:
      | activity | name        | course | idnumber |
      | forum    | Forum 1     | C1     | forum1   |
      | quiz     | Quiz 1      | C1     | quiz1    |

  Scenario: Teacher can open the create report form
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    When I click on "New analysis" "link"
    Then I should see "Create new analysis"
    And I should see "Analysis prompt"
    And I should see "Scope"

  Scenario: Create a simple report with only prompt
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    And I click on "New analysis" "link"
    When I set the field "Analysis prompt" to "Analyze all conversations and identify common themes"
    And I press "Create new analysis"
    Then I should see "Analysis has been queued for background processing"
    And I should see "Pending"

  Scenario: Create a report with title and prompt
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    And I click on "New analysis" "link"
    When I set the field "Title" to "Theme Analysis"
    And I set the field "Analysis prompt" to "What are the main topics discussed?"
    And I press "Create new analysis"
    Then I should see "Analysis has been queued for background processing"
    And I should see "Theme Analysis"

  Scenario: Prompt field is required
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    And I click on "New analysis" "link"
    When I set the field "Title" to "Test Analysis"
    And I press "Create new analysis"
    Then I should see "Required"

  Scenario: Create report with participant filter
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    And I click on "New analysis" "link"
    When I set the field "Analysis prompt" to "Analyze student conversations"
    And I set the field "Select participants" to "Student One"
    And I press "Create new analysis"
    Then I should see "Analysis has been queued for background processing"

  Scenario: Create report with group filter
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    And I click on "New analysis" "link"
    When I set the field "Analysis prompt" to "Analyze group conversations"
    And I set the field "Groups" to "Group 1"
    And I press "Create new analysis"
    Then I should see "Analysis has been queued for background processing"

  Scenario: Create report with activity filter
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    And I click on "New analysis" "link"
    When I set the field "Analysis prompt" to "Analyze forum conversations"
    And I set the field "Data sources" to "Forum 1"
    And I press "Create new analysis"
    Then I should see "Analysis has been queued for background processing"

  Scenario: Create report with aggregated analysis mode
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    And I click on "New analysis" "link"
    When I set the field "Analysis prompt" to "Aggregate analysis"
    And I set the field "Analysis mode" to "Aggregated (all participants)"
    And I press "Create new analysis"
    Then I should see "Analysis has been queued for background processing"

  Scenario: Create report with individual analysis mode
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    And I click on "New analysis" "link"
    When I set the field "Analysis prompt" to "Individual analysis"
    And I set the field "Analysis mode" to "Individual (per participant)"
    And I press "Create new analysis"
    Then I should see "Analysis has been queued for background processing"

  Scenario: Cancel creating a report
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    And I click on "New analysis" "link"
    When I set the field "Title" to "Test Analysis"
    And I set the field "Analysis prompt" to "Test prompt"
    And I press "Cancel"
    Then I should see "AI Conversation Analysis"
    And I should not see "Test Analysis"

  Scenario: Create report with time range filter
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    And I click on "New analysis" "link"
    When I set the field "Analysis prompt" to "Analyze recent conversations"
    And I press "Create new analysis"
    Then I should see "Analysis has been queued for background processing"

  Scenario: Create comprehensive report with all filters
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    And I click on "New analysis" "link"
    When I set the field "Title" to "Comprehensive Analysis"
    And I set the field "Analysis prompt" to "Comprehensive analysis of all data"
    And I set the field "Analysis mode" to "Aggregated (all participants)"
    And I set the field "Data sources" to "Forum 1"
    And I set the field "Select participants" to "Student One,Student Two"
    And I set the field "Groups" to "Group 1"
    And I press "Create new analysis"
    Then I should see "Analysis has been queued for background processing"
    And I should see "Comprehensive Analysis"
