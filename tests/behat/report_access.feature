@report @report_ai_analysis @javascript
Feature: Report ownership and read-only sharing
  Report capabilities must not expose private reports or grant ownership of another user's work.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | teacher2 | Teacher   | Two      | teacher2@example.com |
      | manager1 | Manager   | One      | manager1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
      | Course 2 | C2        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher2 | C1     | editingteacher |
      | manager1 | C1     | manager        |
      | teacher1 | C2     | editingteacher |
    And the AI analysis backend is configured
    And the following config values are set as admin:
      | share_reports_in_course | 0 | report_ai_analysis |
      | debugdisplay            | 0 |                    |
    And the following "report_ai_analysis > reports" exist:
      | title         | course | user     | status    | prompt                      |
      | Own report    | C1     | teacher1 | completed | Analyse my permitted sources |
      | Other report  | C1     | teacher2 | completed | Analyse other allowed sources |
      | Other pending | C1     | teacher2 | pending   | Analyse sources when ready   |

  Scenario: Private reports are absent from lists and denied at every direct endpoint
    Given "30" AI analysis reports with prefix "Private batch" by "teacher2" exist in "C1"
    And I am on the "Course 1" "Course" page logged in as "teacher1"
    When I navigate to "Reports > AI Conversation Analysis" in current page administration
    Then I should see "Own report"
    And I should not see "Other report"
    And I should not see "Other pending"
    And ".pagination" "css_element" should not exist
    And a direct "view" request for AI analysis report "Other report" should be denied
    And a direct "export" request for AI analysis report "Other report" should be denied
    And a direct "edit" request for AI analysis report "Other report" should be denied
    And a direct "rerun" request for AI analysis report "Other report" should be denied
    And a direct "delete" request for AI analysis report "Other report" should be denied
    And a direct "cancel" request for AI analysis report "Other pending" should be denied

  Scenario: Shared and viewall access grant reading and exporting but never mutation
    Given the following config values are set as admin:
      | share_reports_in_course | 1 | report_ai_analysis |
    And the following "permission overrides" exist:
      | capability                 | permission | role           | contextlevel | reference |
      | report/ai_analysis:viewall | Allow      | editingteacher | Course       | C1        |
    And I am on the "Course 1" "Course" page logged in as "teacher1"
    When I navigate to "Reports > AI Conversation Analysis" in current page administration
    Then I should see "Other report"
    And I should not see "Edit" in the "Other report" "table_row"
    And I should not see "Re-run" in the "Other report" "table_row"
    And I should not see "Delete" in the "Other report" "table_row"
    And I should not see "Cancel" in the "Other pending" "table_row"
    And the "json" export of AI analysis report "Other report" should contain safe presentation data
    When I view the AI analysis report "Other report"
    Then I should see "Analyse other allowed sources"
    And a direct "edit" request for AI analysis report "Other report" should be denied
    And a direct "rerun" request for AI analysis report "Other report" should be denied
    And a direct "delete" request for AI analysis report "Other report" should be denied
    And a direct "cancel" request for AI analysis report "Other pending" should be denied

  Scenario: Grant-based access differs for non-editing teachers and students
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher3 | Teacher   | Three    | teacher3@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | teacher3 | C1     | teacher |
      | student1 | C1     | student |
    And the following "permission overrides" exist:
      | capability                | permission | role    | contextlevel | reference |
      | report/ai_analysis:view   | Allow      | teacher | Course       | C1        |
      | report/ai_analysis:view   | Allow      | student | Course       | C1        |
      | report/ai_analysis:create | Allow      | student | Course       | C1        |
    And I am on the "Course 1" "Course" page logged in as "teacher3"
    When I navigate to "Reports > AI Conversation Analysis" in current page administration
    Then I should see "AI Conversation Analysis"
    And I should not see "New analysis"
    Given I am on the "Course 1" "Course" page logged in as "student1"
    When I visit the url "/report/ai_analysis/index.php?courseid={{Course 1:id}}"
    Then I should see "New analysis"
    When I click on "New analysis" "link"
    Then I should see "Create new analysis"
    And I should see "Analysis prompt"