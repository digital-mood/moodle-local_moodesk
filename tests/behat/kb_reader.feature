@local @local_moodesk
Feature: Reading and searching the knowledge base
  In order to find an answer before opening a ticket
  As a requester
  I need to browse the knowledge base by category, search it, read an article and open a ticket from it

  Background:
    Given the following "users" exist:
      | username   | firstname | lastname | email                  |
      | requester1 | Rita      | Requena  | requester1@example.com |
      | editor1    | Elena     | Editora  | editor1@example.com    |
      | manager1   | Marta     | Manager  | manager1@example.com   |
    And the following "role assigns" exist:
      | user     | role      | contextlevel | reference |
      | editor1  | mhd_agent | System       |           |
      | manager1 | manager   | System       |           |
    And the following "local_moodesk > kb articles" exist:
      | title                        | body                                                   | status    | audience | user    |
      | How to reset your password   | <p>Click <strong>Forgot password</strong> on the login page.</p> | published | everyone | editor1 |
      | Password policy explained    | <p>Eight characters, a digit and a symbol.</p>          | published | everyone | editor1 |
      | Escalating payroll incidents | <p>Call the on-call number first.</p>                   | published | agents   | editor1 |
      | Half-written draft           | <p>Not yet.</p>                                         | draft     | everyone | editor1 |

  Scenario: A requester browses the knowledge base and reads an article
    When I am on the "local_moodesk > Knowledge" page logged in as "requester1"
    Then I should see "Knowledge base"
    And I should see "All articles"
    And I should see "How to reset your password"
    And I should see "Password policy explained"
    And I should not see "Escalating payroll incidents"
    And I should not see "Half-written draft"
    And I should see "Open a ticket"
    When I follow "How to reset your password"
    Then I should see "Click Forgot password on the login page."
    And I should see "Knowledge base" in the ".mhd-breadcrumb" "css_element"
    And I should see "Updated"
    And I should not see "Elena Editora"
    And I should not see "Published" in the ".mhd-page-header" "css_element"
    And I should not see "Edit article"
    And I should not see "Manage status"
    And I should see "Open a ticket"

  Scenario: A requester searches, sees the terms marked, and clears the search
    Given I am on the "local_moodesk > Knowledge" page logged in as "requester1"
    When I set the field with xpath "//input[@name='q']" to "password"
    And I press "Search"
    Then I should see "2 results for “password”"
    And I should see "How to reset your password"
    And I should see "Password policy explained"
    And "mark" "css_element" should exist
    And I should see "Clear search"
    When I follow "Clear search"
    Then I should see "All articles"
    And I should not see "Clear search"

  Scenario: Too short a query is not a search, and no match says so
    Given I am on the "local_moodesk > Knowledge" page logged in as "requester1"
    When I set the field with xpath "//input[@name='q']" to "ab"
    And I press "Search"
    Then I should see "Type at least 3 characters to search."
    And I should see "How to reset your password"
    When I set the field with xpath "//input[@name='q']" to "xylophonic"
    And I press "Search"
    Then I should see "No articles match “xylophonic”"
    And I should see "Try other words"
    And I should see "Open a ticket"

  Scenario: Opening a ticket from an article starts in the article's category
    Given the following "local_moodesk > kb articles" exist:
      | title                | body                       | status    | user    | kbcategory |
      | Grades are hidden    | <p>Ask your teacher.</p>   | published | editor1 | Grades     |
    When I am on the "Grades are hidden" "local_moodesk > Article" page logged in as "requester1"
    Then I should see "Grades" in the ".mhd-breadcrumb" "css_element"
    When I follow "Open a ticket"
    Then the field "Category" matches value "Grades"

  Scenario: An article stays readable under a category that was archived afterwards
    Given the following "local_moodesk > kb articles" exist:
      | title                 | body                     | status    | user    | kbcategory |
      | Legacy VPN client     | <p>Still supported.</p>  | published | editor1 | Legacy     |
      | Grades are hidden     | <p>Ask your teacher.</p> | published | editor1 | Grades     |
    And the mooDesk category "Legacy" is archived
    When I am on the "local_moodesk > Knowledge" page logged in as "requester1"
    Then I should see "Legacy VPN client"
    And I should see "Legacy" in the ".mhd-category-nav" "css_element"
    And I should not see "Archived"
    When I follow "Legacy VPN client"
    Then I should see "Still supported."
    And I should see "Legacy" in the ".mhd-breadcrumb" "css_element"
    When I follow "Open a ticket"
    Then the field "Category" does not match value "Legacy"

  Scenario: An agent reads what a requester cannot; an editor still gets the editorial layer
    When I am on the "local_moodesk > Knowledge" page logged in as "editor1"
    Then I should see "Escalating payroll incidents"
    And I should see "Agents only"
    And I should not see "Half-written draft"
    When I follow "How to reset your password"
    Then I should see "Published" in the ".mhd-page-header" "css_element"
    And I should see "Edit article"
    And I should see "Elena Editora"
    And I should see "Open a ticket"

  Scenario: Knowledge sits in Operation and goes with the kb_enabled switch
    When I am on the "local_moodesk > Tickets" page logged in as "requester1"
    Then I should see "Knowledge" in the ".moodesk-app__sidebar" "css_element"
    And I should not see "Articles" in the ".moodesk-app__sidebar" "css_element"
    Given the following config values are set as admin:
      | kb_enabled | 0 | local_moodesk |
    When I am on the "local_moodesk > Tickets" page
    Then I should not see "Knowledge" in the ".moodesk-app__sidebar" "css_element"
    When I am on the "local_moodesk > Knowledge" page
    Then I should see "The knowledge base is turned off for this site"
