@report @report_ai_analysis @javascript
Feature: Authorised sources and intersected scope filters
  Selection and source permissions must constrain the complete prompt, not just the form or displayed metadata.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
      | student2 | Student   | Two      | student2@example.com |
      | student3 | Student   | Three    | student3@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
      | Course 2 | C2        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
      | student1 | C2     | student        |
    And the following "groups" exist:
      | name    | course | idnumber |
      | Group 1 | C1     | G1       |
      | Group 2 | C1     | G2       |
    And the following "group members" exist:
      | user     | group |
      | teacher1 | G1    |
      | student1 | G1    |
      | student2 | G2    |
    And the following "activities" exist:
      | activity | name    | course | idnumber |
      | forum    | Forum 1 | C1     | forum1   |
      | forum    | Forum 2 | C1     | forum2   |
    And the following "mod_forum > discussions" exist:
      | forum  | name            | subject     | user     | message              | timemodified |
      | forum1 | Matching thread | Alpha root  | student1 | ALPHA_OLD            | 1788220800   |
      | forum1 | Unmatched thread | Other root | student1 | ALPHA_UNMATCHED_OLD  | 1788220800   |
      | forum2 | Excluded forum  | Other forum | student2 | OTHER_FORUM_PRIVATE | 1788220800   |
    And the following "mod_forum > posts" exist:
      | discussion       | user     | message       | created    |
      | Matching thread  | student1 | ALPHA_RECENT  | 1788307200 |
      | Matching thread  | student2 | BETA_RECENT   | 1788307200 |
      | Unmatched thread | student2 | BETA_TRIGGER  | 1788307200 |
    And the following "report_ai_analysis > chat entries" exist:
      | course | user     | prompttext       | timecreated | deleted | purpose   | requestoptions                                                     |
      | C1     | student1 | CHAT_OLD         | 1788220800  | 0       | chat      | {}                                                                 |
      | C1     | student1 | CHAT_RECENT      | 1788307200  | 0       | chat      | {"conversationcontext":[{"message":"HISTORY_SHOULD_NOT_LEAK"}]}     |
      | C1     | student1 | CHAT_LATER       | 1788393600  | 0       | chat      | {}                                                                 |
      | C1     | student1 | DELETED_CHAT     | 1788307200  | 1       | chat      | {}                                                                 |
      | C1     | student1 | NOT_A_CHAT       | 1788307200  | 0       | translate | {}                                                                 |
      | C1     | student2 | OTHER_STUDENT_CHAT | 1788307200 | 0     | chat      | {}                                                                 |
      | C2     | student1 | OTHER_COURSE_CHAT | 1788307200 | 0      | chat      | {}                                                                 |
    And the AI analysis backend is configured
    And the following "permission overrides" exist:
      | capability                    | permission | role           | contextlevel | reference |
      | local/ai_manager:viewprompts   | Allow      | editingteacher | Course       | C1        |
    And the following config values are set as admin:
      | store_raw_data           | 1    | report_ai_analysis |
      | max_records_per_analysis | 1000 | report_ai_analysis |

  Scenario: A private reply for another participant never reaches the analysis
    Given a private AI analysis reply "PRIVATE_REPLY" by "student2" to "student3" exists in discussion "Matching thread"
    And the following "permission overrides" exist:
      | capability                    | permission | role           | contextlevel | reference |
      | mod/forum:readprivatereplies   | Prohibit   | editingteacher | Course       | C1        |
    And I queue AI analysis "Private replies" as "teacher1" in "C1" with:
      | prompt       | Analyse only visible permitted forum posts |
      | sources      | forum1                                    |
      | participants | student1,student2,student3                 |
    When I run the AI analysis task for "Private replies"
    Then there should have been "1" AI analysis requests
    And AI analysis request "1" should contain "BETA_RECENT"
    And AI analysis request "1" should not contain "PRIVATE_REPLY"

  Scenario: Losing foreign prompt access blocks a previously selected chat source
    Given I queue AI analysis "Forbidden chat" as "teacher1" in "C1" with:
      | prompt       | Analyse chat only while source permission remains |
      | sources      | chat                                             |
      | participants | student1                                         |
    And the following "permission overrides" exist:
      | capability                  | permission | role           | contextlevel | reference |
      | local/ai_manager:viewprompts | Prohibit   | editingteacher | Course       | C1        |
    When I run the AI analysis task for "Forbidden chat"
    Then there should have been "0" AI analysis requests
    And AI analysis report "Forbidden chat" should have "error_code" "error_source_forbidden"