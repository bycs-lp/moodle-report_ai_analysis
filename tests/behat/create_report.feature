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
    And the AI analysis backend is configured
    And the following config values are set as admin:
      | timezone | UTC |

  Scenario: Creating a report queues background processing and keeps an open date boundary
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    And I click on "New analysis" "link"
    When I set the following fields to these values:
      | Title                | Start-only analysis          |
      | Analysis prompt      | Analyze recent conversations |
      | timestart[enabled]   | 1                            |
      | timestart[day]       | 1                            |
      | timestart[month]     | September                    |
      | timestart[year]      | 2026                         |
      | timestart[hour]      | 00                           |
      | timestart[minute]    | 00                           |
      | timeend[enabled]     | 0                            |
    And I press "Create new analysis"
    Then I should see "Analysis has been queued for background processing"
    And I should see "Start-only analysis"
    And AI analysis report "Start-only analysis" should have time boundaries "1788220800" and "0"
    And an adhoc task "report_ai_analysis\task\process_analysis_task" should exist for user "teacher1"

  Scenario: Prompt field is required
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    And I click on "New analysis" "link"
    When I set the field "Title" to "Test Analysis"
    And I press "Create new analysis"
    Then I should see "Required"

  Scenario: Selecting sources, participants and groups produces a Unicode-safe automatic title
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    And I click on "New analysis" "link"
    Then the "Data sources" select box should contain "Forum 1"
    And the "Data sources" select box should not contain "Quiz 1"
    When I set the field "Analysis mode" to "Aggregated (all participants)"
    And I set the field "Data sources" to "Forum 1"
    And I set the field "all_participants" to "0"
    And I set the field "Select participants" to "Student One,Student Two"
    And I set the field "Groups" to "Group 1"
    And I enter an AI analysis prompt at the Unicode title boundary
    And I press "Create new analysis"
    Then I should see "Analysis has been queued for background processing"
    And the automatically titled AI analysis should preserve its Unicode boundary
