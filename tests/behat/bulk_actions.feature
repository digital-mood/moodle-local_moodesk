@local @local_moodesk
Feature: Bulk controls appear for the agents allowed to use them
  In order to keep a read-only agent from reaching for an action the server will refuse
  As a product
  I need the bulk controls to follow the capability that gates the endpoint

  # Selecting tickets and applying an action is JavaScript throughout, so those
  # scenarios live in bulk_actions_js.feature, which the CI Behat job does not
  # run — see the note at the top of that file. What is left here is what the
  # server renders on its own, and it is the half that decides who can act at
  # all: the controls are gated on local/moodesk:manage, while reading the list
  # only needs local/moodesk:viewall.

  Background:
    Given the following "users" exist:
      | username   | firstname | lastname | email                  |
      | requester1 | Rita      | Requena  | requester1@example.com |
      | agent1     | Alba      | Agente   | agent1@example.com     |
      | viewer1    | Vera      | Vidal    | viewer1@example.com    |
    And the following "roles" exist:
      | shortname   | name            | archetype |
      | mhd_watcher | mooDesk watcher |           |
    And the following "permission overrides" exist:
      | capability            | permission | role        | contextlevel | reference |
      | local/moodesk:viewall | Allow      | mhd_watcher | System       |           |
    And the following "role assigns" exist:
      | user    | role        | contextlevel | reference |
      | agent1  | mhd_agent   | System       |           |
      | viewer1 | mhd_watcher | System       |           |
    And the following "local_moodesk > tickets" exist:
      | subject             | message              | user       | priority |
      | Printer is jammed   | Paper everywhere     | requester1 | high     |
      | VPN drops hourly    | Disconnects at :00   | requester1 | high     |

  Scenario: An agent who can manage tickets gets the bulk controls
    Given I am on the "local_moodesk > Tickets" page logged in as "agent1"
    Then I should see "Printer is jammed"
    And "#mhd-select-all" "css_element" should exist
    And "#mhd-bulk-toolbar" "css_element" should exist
    And ".mhd-bulk-check" "css_element" should exist
    And I should see "Select all"

  Scenario: An agent who can only read the queue gets none of them
    Given I am on the "local_moodesk > Tickets" page logged in as "viewer1"
    Then I should see "Printer is jammed"
    And "#mhd-select-all" "css_element" should not exist
    And "#mhd-bulk-toolbar" "css_element" should not exist
    And ".mhd-bulk-check" "css_element" should not exist
    And ".mhd-row-quick-actions" "css_element" should not exist
    And I should not see "Select all"

  Scenario: The requester's own list never carries bulk controls
    Given I am on the "local_moodesk > Tickets" page logged in as "requester1"
    Then I should see "Printer is jammed"
    And "#mhd-select-all" "css_element" should not exist
    And ".mhd-bulk-check" "css_element" should not exist
