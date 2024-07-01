@mod @mod_assign
Feature: Duplicate assign activity module with permissions
  In order to ensure that locally assigned roles and permissions are correctly duplicated
  As a teacher
  I need to add the roles and permissions and ensure they are correctly duplicated

  @javascript
  Scenario: Add a locally assigned role and duplicate activity
    Given the following "courses" exist:
      | fullname | shortname | category | groupmode |
      | Course 1 | C1 | 0 | 1 |
    And the following "users" exist:
      | username | firstname | lastname | email |
      | teacher1 | Teacher | 1 | teacher1@example.com |
      | student1 | Student | 1 | student10@example.com |
      | student2 | Student | 2 | student20@example.com |
    And the following "course enrolments" exist:
      | user | course | role |
      | teacher1 | C1 | editingteacher |
      | student1 | C1 | student |
      | student2 | C1 | student |
    And the following "activity" exists:
      | activity         | assign                      |
      | course           | C1                          |
      | name             | Test assignment name        |
      | intro            | Test assignment description |
      | markingworkflow  | 1                           |
      | submissiondrafts | 0                           |

    When I am on the "C1" Course page logged in as teacher1
    And I enable the edit mode
    And I open "Test assignment name" actions menu
    And I click on "Assign roles" "link" in the "Test assignment name" activity
    And I click on "Non-editing teacher" "link"
    And I click on "Student 2" "option"
    And I click on "Add" "button"

    When I am on the "C1" Course page logged in as teacher1
    And I enable the edit mode
    And I duplicate "Test assignment name" activity
    Then I should see "Test assignment name (copy)"

    When I open "Test assignment name (copy)" actions menu
    And I click on "Assign roles" "link" in the "Test assignment name (copy)" activity
    Then "Non-editing teacher" row "Users with role" column of "generaltable" table should contain "1"

  @javascript
  Scenario: Add a permission override to activity and duplicate
    Given the following "courses" exist:
      | fullname | shortname | category | groupmode |
      | Course 1 | C1 | 0 | 1 |
    And the following "users" exist:
      | username | firstname | lastname | email |
      | teacher1 | Teacher | 1 | teacher1@example.com |
      | student1 | Student | 1 | student10@example.com |
    And the following "course enrolments" exist:
      | user | course | role |
      | teacher1 | C1 | editingteacher |
      | student1 | C1 | student |
    And the following "activity" exists:
      | activity         | assign                      |
      | course           | C1                          |
      | name             | Test assignment name        |
      | intro            | Test assignment description |
      | markingworkflow  | 1                           |
      | submissiondrafts | 0                           |
    And the following "permission overrides" exist:
      | capability | permission | role | contextlevel | reference |
      | mod/assign:grade | Allow | student | 70 | Test assignment name |

    When I am on the "C1" Course page logged in as admin
    And I enable the edit mode
    And I duplicate "Test assignment name" activity
    Then I should see "Test assignment name (copy)"

    When I open "Test assignment name (copy)" actions menu
    And I click on "Edit settings" "link" in the "Test assignment name (copy)" activity
    And I navigate to "Permissions" in current page administration
    And I set the field "permissionscapabilitysearch" to "mod/assign:grade"
    Then "Grade assignmentmod/assign:grade" row "Roles with permission" column of "permissions" table should contain "Student"
