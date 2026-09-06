@report @report_ai_analysis @javascript
Feature: Manage Prompt Templates (Admin only)
  In order to provide pre-defined analysis prompts
  As a site administrator
  I need to be able to manage prompt templates

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
    And the following "report_ai_analysis > templates" exist:
      | title           | content                              | sortorder | enabled |
      | Quoted template | Analyse "Ä & Ö" and describe ü.      | 0         | 1       |
      | Second template | Explain the second selected topic.   | 1         | 1       |
      | Third template  | Explain the third selected topic.    | 2         | 1       |
      | Fourth template | Explain the fourth selected topic.   | 3         | 1       |
      | Fifth template  | Explain the fifth selected topic.    | 4         | 1       |
      | Hidden template | This disabled prompt must not appear. | 5        | 0       |

  Scenario: Five enabled templates use buttons and preserve the exact prompt
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Reports > AI Conversation Analysis" in current page administration
    And I click on "New analysis" "link"
    When I press "Quoted template"
    Then the field "Analysis prompt" matches value "Analyse \"Ä & Ö\" and describe ü."
    And "Hidden template" "button" should not exist
    And "Use a template" "field" should not exist
    When I set the field "Title" to "Template roundtrip"
    And I press "Create new analysis"
    And I click on "Edit" "link" in the "Template roundtrip" "table_row"
    Then the field "Analysis prompt" matches value "Analyse \"Ä & Ö\" and describe ü."
    And AI analysis template administration should be denied

  Scenario: Administrators can create edit toggle reorder and delete a template
    Given I log in as "admin"
    And I visit the url "/report/ai_analysis/manage_templates.php"
    When I press "Add new template"
    And I set the following fields to these values:
      | Template title | Admin template                    |
      | Prompt text    | Analyse "A & B" and include ü.   |
    And I press "Save changes"
    Then I should see "Template created successfully"
    When I click on "Edit" "link" in the "Admin template" "table_row"
    And I set the field "Prompt text" to "Updated analysis instructions with umlauts äöü."
    And I press "Save changes"
    Then I should see "Template updated successfully"
    When I click on "Disable" "link" in the "Admin template" "table_row"
    Then I should see "No" in the "Admin template" "table_row"
    When I click on "Enable" "link" in the "Admin template" "table_row"
    Then I should see "Yes" in the "Admin template" "table_row"
    When I click on "Move up" "link" in the "Third template" "table_row"
    Then AI analysis template "Third template" should precede "Second template"
    When I click on "Delete" "link" in the "Admin template" "table_row"
    And I press "Continue"
    Then I should see "Template deleted successfully"
    And I should not see "Admin template"
