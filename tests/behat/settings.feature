@report @report_ai_analysis @javascript
Feature: Bounded report settings
  Administrators must not be offered an ineffective timeout or silently accepted invalid limits.

  Background:
    Given the following config values are set as admin:
      | max_records_per_analysis | 1000  | report_ai_analysis |
      | truncate_raw_data_length | 50000 | report_ai_analysis |
    And I log in as "admin"
    And I navigate to "Plugins > Reports > AI Conversation Analysis" in site administration

  Scenario: Settings omit a timeout and store valid boundary limits
    Then "Request timeout" "field" should not exist
    And I should see "Manage prompt templates"
    When I set the following fields to these values:
      | Maximum records per analysis | 10000  |
      | Truncate raw data length     | 500000 |
    And I press "Save changes"
    Then the AI analysis setting "max_records_per_analysis" should be "10000"
    And the AI analysis setting "truncate_raw_data_length" should be "500000"