@local @local_moodesk
Feature: The New ticket form carries a "Suggested articles" block when the knowledge base is readable
  In order to be offered an answer before I send a ticket
  As a requester
  I need the block to be in the form exactly when the knowledge base is there for me to read

  # The block is filled by JavaScript (see kb_deflection_js.feature). What is
  # checked here without it is the contract that decides whether the block
  # exists at all: it comes through the shared form, so submit.php and the
  # drawer agree by construction, and it goes with the knowledge base as the
  # reader sees it — off with kb_enabled, absent without :viewkb.

  Background:
    Given the following "users" exist:
      | username   | firstname | lastname | email                  |
      | requester1 | Rita      | Requena  | requester1@example.com |

  Scenario: The block sits between the category row and the description, on the page and in the drawer
    When I am on the "local_moodesk > New ticket" page logged in as "requester1"
    Then I should see "Suggested articles" in the "form.mform" "css_element"
    And "//form[contains(@class, 'mform')]//*[@name='categoryid']/following::*[@data-region='mhd-kb-suggest']" "xpath_element" should exist
    And "//form[contains(@class, 'mform')]//*[@data-region='mhd-kb-suggest']/following::*[@data-region='mhd-composer-input']" "xpath_element" should exist
    When I am on the "local_moodesk > Tickets" page
    Then "[data-region='mhd-ticket-drawer'] [data-region='mhd-kb-suggest']" "css_element" should exist

  Scenario: Switching the knowledge base off takes the block out of the form
    Given the following config values are set as admin:
      | kb_enabled | 0 | local_moodesk |
    When I am on the "local_moodesk > New ticket" page logged in as "requester1"
    Then I should not see "Suggested articles"
    And "[data-region='mhd-kb-suggest']" "css_element" should not exist
    And "Subject" "field" should exist
    When I am on the "local_moodesk > Tickets" page
    Then "[data-region='mhd-ticket-drawer'] [data-region='mhd-kb-suggest']" "css_element" should not exist

  Scenario: A requester who may not read the knowledge base still gets the form, without the block
    Given the following "permission overrides" exist:
      | capability           | permission | role | contextlevel | reference |
      | local/moodesk:viewkb | Prohibit   | user | System       |           |
    When I am on the "local_moodesk > New ticket" page logged in as "requester1"
    Then "Subject" "field" should exist
    And I should not see "Suggested articles"
    And "[data-region='mhd-kb-suggest']" "css_element" should not exist
