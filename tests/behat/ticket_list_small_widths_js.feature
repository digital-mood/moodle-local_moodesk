@local @local_moodesk @javascript
Feature: The ticket list holds together on phones and tablets
  In order to triage from a phone or a tablet
  As an agent
  I need the list, its filters and its bulk toolbar to fit the screen I am holding

  # The responsive work of 2.20-2.22 had no Behat coverage of
  # the list itself below desktop; these scenarios are that coverage. They do
  # not replace QA on real devices.
  #
  # Widths come from Chrome's device emulation (`I emulate a mooDesk viewport`),
  # not from `I change window size`: the CI's browser widens any window below
  # ~500px, so a window step asking for 360 would quietly test 500. Each
  # scenario reloads after emulating because filters.js reads the width on load.
  #
  # Below 992px every row is a card. Below 576px the secondary filters fold
  # behind a "More filters" summary; at 576 and above they are always open and
  # the summary is not rendered.

  Background:
    Given the following "users" exist:
      | username   | firstname | lastname | email                  |
      | requester1 | Rita      | Requena  | requester1@example.com |
      | agent1     | Alba      | Agente   | agent1@example.com     |
      | agent2     | Bruno     | Bianchi  | agent2@example.com     |
    And the following "role assigns" exist:
      | user   | role      | contextlevel | reference |
      | agent1 | mhd_agent | System       |           |
      | agent2 | mhd_agent | System       |           |
    And the following "local_moodesk > tickets" exist:
      | subject                                                    | message          | user       | priority |
      | Printer is jammed                                          | Paper everywhere | requester1 | high     |
      | VPN drops hourly whenever the laptop moves between floors | Disconnects      | requester1 | high     |
      | Password reset loop                                        | Link expires     | requester1 | low      |

  Scenario Outline: At <width>px the rows are cards and nothing scrolls sideways
    Given I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I emulate a mooDesk viewport <width> pixels wide
    And I reload the page
    Then the mooDesk ticket rows should be laid out as cards
    And the mooDesk element ".mhd-ticket-rows" should not scroll sideways

    Examples:
      | width |
      | 360   |
      | 390   |
      | 576   |
      | 768   |

  Scenario: On desktop the same rows are rows, so the step above can tell the two apart
    Given I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I emulate a mooDesk viewport 1280 pixels wide
    And I reload the page
    Then the mooDesk ticket rows should be laid out as rows

  Scenario Outline: At <width>px the secondary filters fold, and open and close on demand
    Given I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I emulate a mooDesk viewport <width> pixels wide
    And I reload the page
    Then the "open" attribute of "[data-region='mhd-filter-more']" "css_element" should not be set
    When I click on ".mhd-filter-more-summary" "css_element"
    Then the "open" attribute of "[data-region='mhd-filter-more']" "css_element" should be set
    And the mooDesk element ".mhd-filter-bar" should not scroll sideways
    When I click on ".mhd-filter-more-summary" "css_element"
    Then the "open" attribute of "[data-region='mhd-filter-more']" "css_element" should not be set

    Examples:
      | width |
      | 360   |
      | 390   |

  Scenario Outline: At <width>px the secondary filters stay open and need no summary
    Given I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I emulate a mooDesk viewport <width> pixels wide
    And I reload the page
    Then the "open" attribute of "[data-region='mhd-filter-more']" "css_element" should be set
    And ".mhd-filter-more-summary" "css_element" should not be visible
    And the mooDesk element ".mhd-filter-bar" should not scroll sideways

    Examples:
      | width |
      | 576   |
      | 768   |

  Scenario Outline: At <width>px an active filter shows as a chip that fits
    Given I log in as "agent1"
    And I visit "/local/moodesk/index.php?priority=1"
    When I emulate a mooDesk viewport <width> pixels wide
    And I reload the page
    Then ".mhd-filter-chips .mhd-pill--input" "css_element" should be visible
    And the mooDesk element ".mhd-filter-chips" should not scroll sideways

    Examples:
      | width |
      | 360   |
      | 390   |
      | 576   |
      | 768   |

  # The ticket's third point, to confirm or discard: the toolbar's two selects
  # carry a min-width, and with an action chosen both are on screen at once.
  Scenario Outline: At <width>px the bulk toolbar with an action chosen fits the screen
    Given I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I emulate a mooDesk viewport <width> pixels wide
    And I reload the page
    And I select the mooDesk ticket "Printer is jammed"
    And I choose the mooDesk bulk action "Assign to agent"
    Then "#mhd-bulk-assigneeid" "css_element" should be visible
    And the mooDesk element "#mhd-bulk-toolbar" should not scroll sideways

    Examples:
      | width |
      | 360   |
      | 390   |
      | 576   |
      | 768   |
