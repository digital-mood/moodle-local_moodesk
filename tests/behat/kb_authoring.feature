@local @local_moodesk
Feature: Writing and publishing knowledge base articles
  In order to answer a recurring question once
  As a support team
  I need editors to write articles and managers to decide which ones are live

  Background:
    Given the following "users" exist:
      | username   | firstname | lastname | email                  |
      | editor1    | Elena     | Editora  | editor1@example.com    |
      | manager1   | Marta     | Manager  | manager1@example.com   |
      | requester1 | Rita      | Requena  | requester1@example.com |
    And the following "role assigns" exist:
      | user     | role      | contextlevel | reference |
      | editor1  | mhd_agent | System       |           |
      | manager1 | manager   | System       |           |

  Scenario: An editor writes a draft and finds it in the list
    Given I am on the "local_moodesk > Articles" page logged in as "editor1"
    Then I should see "No articles yet"
    When I follow "New article"
    And I set the field "Title" to "How to reset your password"
    And I set the field "Body" to "<p>Click <strong>Forgot password</strong> on the login page.</p>"
    And I press "Save draft"
    Then I should see "The draft has been created"
    And I should see "How to reset your password"
    And I should see "Draft"
    And I should see "Click Forgot password on the login page."
    When I am on the "local_moodesk > Articles" page
    Then I should see "How to reset your password"
    And I should see "Showing 1 of 1 articles"

  Scenario: An editor without the manage capability edits but never changes status
    Given the following "local_moodesk > kb articles" exist:
      | title                  | body                       | status | user    |
      | Payroll export is slow | <p>Run it after 18:00.</p> | draft  | editor1 |
    When I am on the "Payroll export is slow" "local_moodesk > Article" page logged in as "editor1"
    Then I should see "Run it after 18:00."
    And I should see "Edit article"
    And I should not see "Publish"
    And I should not see "Manage status"
    When I follow "Edit article"
    And I set the field "Title" to "Payroll export is slow at month end"
    And I press "Save changes"
    Then I should see "The article has been saved"
    And I should see "Payroll export is slow at month end"
    And I should see "Draft"

  Scenario: A manager walks an article through its whole lifecycle
    Given the following "local_moodesk > kb articles" exist:
      | title                | body                          | status | user    |
      | Requesting a licence | <p>Ask your line manager.</p> | draft  | editor1 |
    When I am on the "Requesting a licence" "local_moodesk > Article" page logged in as "manager1"
    And I press "Publish"
    Then I should see "The article is now published"
    And I should see "Published"
    And I should see "First published"
    When I press "Unpublish"
    Then I should see "The article is back in draft"
    When I press "Publish"
    And I press "Archive"
    Then I should see "The article has been archived"
    And I should see "Archived"
    When I press "Restore"
    Then I should see "The article is back as a draft"
    And I should see "Draft"

  Scenario: A draft with no content cannot be published
    Given the following "local_moodesk > kb articles" exist:
      | title            | body | status | user    |
      | Untitled thought |      | draft  | editor1 |
    When I am on the "Untitled thought" "local_moodesk > Article" page logged in as "manager1"
    Then I should see "This article has no content yet"
    When I press "Publish"
    Then I should see "An article needs some content before it can be published"
    And I should see "Draft"

  Scenario: A manager deletes an article
    Given the following "local_moodesk > kb articles" exist:
      | title                | body           | status    | user    |
      | Obsolete VPN guide   | <p>Old.</p>    | archived  | editor1 |
      | Current VPN guide    | <p>New.</p>    | published | editor1 |
    When I am on the "Obsolete VPN guide" "local_moodesk > Article" page logged in as "manager1"
    And I press "Delete article"
    Then I should see "The article has been deleted"
    And I should see "Current VPN guide"
    And I should not see "Obsolete VPN guide"

  Scenario: The list filters by status and tells apart "nothing here" from "nothing matches"
    Given the following "local_moodesk > kb articles" exist:
      | title                     | body        | status    | audience | user    |
      | Published guide           | <p>P.</p>   | published | everyone | editor1 |
      | Draft for agents          | <p>D.</p>   | draft     | agents   | editor1 |
    When I am on the "local_moodesk > Articles" page logged in as "manager1"
    Then I should see "Showing 2 of 2 articles"
    And I should see "Agents only"
    When I set the field with xpath "//select[@name='status']" to "Published"
    And I press "Apply"
    Then I should see "Published guide"
    And I should not see "Draft for agents"
    And I should see "Clear filters"
    When I set the field with xpath "//select[@name='status']" to "Archived"
    And I press "Apply"
    Then I should see "Nothing matches these filters"
    And I should not see "No articles yet"
    When I follow "Clear filters"
    Then I should see "Showing 2 of 2 articles"

  Scenario: A requester is refused the authoring screens
    Given the following "local_moodesk > kb articles" exist:
      | title            | body        | status    | user    |
      | Published guide  | <p>P.</p>   | published | editor1 |
      | Secret draft     | <p>S.</p>   | draft     | editor1 |
    When I log in as "requester1"
    Then mooDesk should refuse me the "Articles" page
    And mooDesk should refuse me the "New article" page
    When I am on the "Published guide" "local_moodesk > Article" page
    Then I should see "P."
    And I should not see "Edit article"
    And I should not see "Manage status"

  Scenario: Switching the knowledge base off takes the screens out of the shell
    Given the following config values are set as admin:
      | kb_enabled | 0 | local_moodesk |
    When I am on the "local_moodesk > Articles" page logged in as "manager1"
    Then I should see "The knowledge base is turned off for this site"
    And I should not see "No articles yet"
