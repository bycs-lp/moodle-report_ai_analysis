@report @report_ai_analysis @javascript
Feature: Real queued analysis processing with a local request fixture
  The worker must preserve attribution, actor identity and generation validity without contacting external AI.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
      | student2 | Student   | Two      | student2@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity | name    | course | idnumber |
      | forum    | Forum 1 | C1     | forum1   |
      | forum    | Forum 2 | C1     | forum2   |
    And the following "mod_forum > discussions" exist:
      | forum  | name         | subject       | user     | message             |
      | forum1 | First thread | Alpha subject | student1 | ALPHA_ONLY          |
      | forum2 | Other thread | Other subject | student2 | OTHER_FORUM_PRIVATE |
    And the following "mod_forum > posts" exist:
      | discussion   | parentsubject | user     | subject      | message   |
      | First thread | Alpha subject | student2 | Beta subject | BETA_ONLY |
    And the AI analysis backend is configured
    And the following config values are set as admin:
      | store_raw_data           | 1    | report_ai_analysis |
      | max_records_per_analysis | 1000 | report_ai_analysis |
      | retry_on_failure         | 3    | report_ai_analysis |

  Scenario: Aggregated analysis completes one real task and one request
    Given I queue AI analysis "Aggregated report" as "teacher1" in "C1" with:
      | prompt       | Analyse the selected forum contributions |
      | sources      | forum1                                   |
      | participants | student1,student2                        |
    When I run the AI analysis task for "Aggregated report"
    Then there should have been "1" AI analysis requests
    And all AI analysis requests should belong to "teacher1" and report "Aggregated report"
    And AI analysis request "1" should contain "ALPHA_ONLY"
    And AI analysis request "1" should contain "BETA_ONLY"
    And AI analysis request "1" should not contain "OTHER_FORUM_PRIVATE"
    And AI analysis report "Aggregated report" should have "status" "completed"
    And AI analysis report "Aggregated report" should contain only subjects "student1,student2"
    And AI analysis report "Aggregated report" should have "0" queued tasks
    And AI analysis privacy data for "teacher1" should include course "C1"
    And AI analysis privacy data for "student2" should include course "C1"

  Scenario: Cancelling in-flight work prevents a late result from completing the report
    Given I queue AI analysis "Cancel race" as "teacher1" in "C1" with:
      | prompt  | Analyse before cancellation |
      | sources | forum1                      |
    When I run the AI analysis task for "Cancel race" while I "cancel" it
    Then there should have been "1" AI analysis requests
    And AI analysis report "Cancel race" should have "status" "cancelled"
    And AI analysis report "Cancel race" should have "ai_result" ""
    And AI analysis report "Cancel race" should have "0" queued tasks