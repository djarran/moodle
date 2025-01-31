@mod @mod_assign
Feature: View submission status table as a student
  In order to understand my submission status
  As a student
  I need to see the submission status table information correctly

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
      | student1 | Sam       | Student | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activity" exists:
      | activity                            | assign      |
      | course                              | C1          |
      | name                                | Test assign |

  Scenario: Student visits the activity page with submission table for the first time
    When I am on the "Test assign" "assign activity" page logged in as student1
    Then "Submission status" "table_row" should exist
    And "Grading status" "table_row" should exist
    And "Last modified" "table_row" should exist
    And "Submission comments" "table_row" should exist
