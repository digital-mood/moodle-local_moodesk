@local @local_moodesk @javascript
Feature: A ticket opens at the newest thing said on it
  In order to pick up a conversation where it was left
  As an agent
  I need the detail to open at the end of the thread, not at its beginning

  # Run by the "Behat JavaScript" job in .github/workflows/behat.yml.
  #
  # The thread runs oldest first, so the newest message is the one at the
  # bottom. A detail that opened at the top showed the agent the oldest thing on
  # the ticket — the single message they were least likely to want — and did it
  # on every open, every switch and every re-render.
  #
  # Where that scroll happens is not one element. In the drawer's two-column
  # layout the conversation is its own scrollport; once the columns collapse
  # into tabs the shared body is, and the conversation inside it scrolls
  # nothing at all. amd/src/conversation_scroll.js finds the one that really
  # scrolls and is the only thing that writes to it — which is why both widths
  # are exercised below.
  #
  # Every scenario asserts two things together: the newest message is on screen
  # AND the oldest one is not. The second is what makes the first mean
  # something — a thread short enough to fit shows its newest message with
  # nobody doing anything about it.

  Background:
    Given the following "users" exist:
      | username   | firstname | lastname | email                  |
      | requester1 | Rita      | Requena  | requester1@example.com |
      | agent1     | Alba      | Agente   | agent1@example.com     |
    And the following "role assigns" exist:
      | user   | role      | contextlevel | reference |
      | agent1 | mhd_agent | System       |           |
    And the following "local_moodesk > tickets" exist:
      | subject           | message                       | user       | status | priority |
      | Printer is jammed | The oldest thing on this file | requester1 | open   | low      |
      | VPN drops hourly  | It disconnects on the hour    | requester1 | open   | low      |
    And the following "local_moodesk > replies" exist:
      | ticket            | user       | message                                                   | type  |
      | Printer is jammed | agent1     | Message two, asking which tray the paper is stuck in      | reply |
      | Printer is jammed | requester1 | Message three, the middle tray on the second floor        | reply |
      | Printer is jammed | agent1     | Message four, asking whether the cover was opened first   | reply |
      | Printer is jammed | requester1 | Message five, the cover was opened and closed twice       | reply |
      | Printer is jammed | agent1     | Message six, asking for the code on the display           | reply |
      | Printer is jammed | requester1 | Message seven, the display reads E-0-4 in red             | reply |
      | Printer is jammed | agent1     | Message eight, that code means the fuser is jammed        | reply |
      | Printer is jammed | requester1 | Message nine, asking whether to open the back panel       | reply |
      | Printer is jammed | agent1     | Message ten, yes, and to pull the sheet downwards         | reply |
      | Printer is jammed | requester1 | Message eleven, the sheet came out in one piece           | reply |
      | Printer is jammed | agent1     | Message twelve, asking to run a test page now             | reply |
      | Printer is jammed | requester1 | Message thirteen, the test page came out clean            | reply |
      | Printer is jammed | agent1     | Message fourteen, closing the loop on the fuser jam       | reply |
      | Printer is jammed | requester1 | Message fifteen, the newest thing anyone has said here    | reply |
      | VPN drops hourly  | agent1     | A single answer on a second ticket                        | reply |

  Scenario: Opening a ticket in the drawer lands on the newest message
    Given I change window size to "1280x900"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    Then the mooDesk conversation should be showing its latest message
    And the mooDesk conversation should have left its oldest message behind

  Scenario: It lands there on a narrow viewport too, where a different element scrolls
    Given I change window size to "390x800"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    # Conversation is the tab you land on, and it opens at its end.
    Then the "aria-selected" attribute of ".mhd-detail-drawer [data-panel=\"conversation\"][data-action]" "css_element" should contain "true"
    And the mooDesk conversation should be showing its latest message
    And the mooDesk conversation should have left its oldest message behind

  Scenario: Closing one ticket and opening the next lands on that one's newest message
    Given I change window size to "1280x900"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    And I click on ".mhd-detail-drawer .mhd-drawer__close" "css_element"
    And I open the mooDesk ticket "VPN drops hourly"
    Then I should see "A single answer on a second ticket"
    # A short thread has no end to scroll to, and must not be scrolled anyway.
    And the mooDesk conversation should be showing its latest message
    When I click on ".mhd-detail-drawer .mhd-drawer__close" "css_element"
    And I open the mooDesk ticket "Printer is jammed"
    Then the mooDesk conversation should be showing its latest message
    And the mooDesk conversation should have left its oldest message behind

  Scenario: A reply that was just sent is the thing on screen
    Given I change window size to "1280x900"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    And I set the field "Reply" to "Sixteenth, and the last word on the matter."
    And I press "Send reply"
    Then I should see "Sixteenth, and the last word on the matter."
    # The panel re-renders through the fragment, and the reply the agent just
    # sent is the whole reason they pressed the button.
    And the mooDesk conversation should be showing its latest message
    And the mooDesk conversation should have left its oldest message behind

  Scenario: An internal note takes the same path
    Given I change window size to "390x800"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    And I click on "Internal Note" "button"
    And I set the field "Reply" to "Noting that the fuser is out of warranty."
    # One composer, one send control — and it is named for what it is about to
    # do. In note mode that is not "Send reply".
    And I press "Add internal note"
    Then I should see "Noting that the fuser is out of warranty."
    And the mooDesk conversation should be showing its latest message
    And the mooDesk conversation should have left its oldest message behind

  Scenario: A trip to Details and back does not throw the reader to the top
    Given I change window size to "390x800"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    And I click on "Details" "button" in the ".mhd-detail-drawer .mhd-detail__tabs" "css_element"
    And I click on "Conversation" "button" in the ".mhd-detail-drawer .mhd-detail__tabs" "css_element"
    # Both tabs share one scrollport at this width, and Details is far shorter
    # than the thread — so the port was clamped to near zero while it was on
    # screen. Coming back has to restore where the conversation was, not
    # whatever the other panel left behind.
    Then the mooDesk conversation should be showing its latest message
    And the mooDesk conversation should have left its oldest message behind
