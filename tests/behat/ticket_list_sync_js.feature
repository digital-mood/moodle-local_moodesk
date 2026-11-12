@local @local_moodesk @javascript
Feature: The ticket list keeps up with changes made without leaving it
  In order to trust what the queue is telling me
  As an agent
  I need the list to reflect a change the moment it is saved, without reloading

  # Run by the "Behat JavaScript" job in .github/workflows/behat.yml, against a
  # real Chrome in a pinned Selenium container. The non-JavaScript smoke job
  # excludes @javascript, so these scenarios run in exactly one place.
  #
  # What is under test is the loop, not any one field: a property saved in the
  # drawer raises moodesk:ticket-changed, local_moodesk/ticket_list re-runs the
  # query through the ticket_list_results fragment, and the region is replaced
  # with whatever the server says now. Nothing here asserts that the client
  # worked out a filter for itself, because it never does — a ticket leaves the
  # list only because the server stopped returning it.
  #
  # The scenarios do not reload between the change and the assertion, and that
  # omission is the test.

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
      | subject           | message              | user       | status | priority |
      | Printer is jammed | Paper everywhere     | requester1 | open   | low      |
      | VPN drops hourly  | Disconnects at :00   | requester1 | open   | low      |

  @javascript
  Scenario: A change made in the drawer shows up on the row behind it
    Given I am on the "local_moodesk > Tickets" page logged in as "agent1"
    And the mooDesk ticket "Printer is jammed" should show the priority "Low"
    When I open the mooDesk ticket "Printer is jammed"
    And I set the field "mhd-prop-priority" to "High"
    Then the mooDesk ticket "Printer is jammed" should show the priority "High"
    And the mooDesk ticket "Printer is jammed" should be listed
    And the mooDesk ticket "VPN drops hourly" should show the priority "Low"

  @javascript
  Scenario: A ticket that stops matching the filter leaves the list
    Given I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I click on ".mhd-status-trigger" "css_element"
    And I click on "Open" "checkbox" in the ".mhd-status-panel" "css_element"
    And I click on "Apply" "button" in the ".mhd-filter-apply" "css_element"
    Then the mooDesk ticket "Printer is jammed" should be listed
    When I open the mooDesk ticket "Printer is jammed"
    And I set the field "mhd-prop-status" to "Closed"
    Then the mooDesk ticket "Printer is jammed" should not be listed
    And the mooDesk ticket "VPN drops hourly" should be listed
    # The change is not silently swallowed: the ticket left the view, and the
    # agent is told so rather than being left wondering whether the save landed.
    And I should see "no longer appears in this view"

  @javascript
  Scenario: A ticket that still matches the filter stays, with its new data
    Given I am on the "local_moodesk > Tickets" page logged in as "agent1"
    And the mooDesk ticket "Printer is jammed" should be unassigned
    When I open the mooDesk ticket "Printer is jammed"
    And I set the field "mhd-prop-assignee" to "Bruno Bianchi"
    Then the mooDesk ticket "Printer is jammed" should be listed
    And the mooDesk ticket "Printer is jammed" should be assigned to "Bruno Bianchi"
    And the mooDesk ticket "VPN drops hourly" should be unassigned

  @javascript
  Scenario: A ticket raised from the drawer is there when the browser goes back
    Given I am on the "local_moodesk > Tickets" page logged in as "agent1"
    And the mooDesk ticket "Cannot print payslips" should not be listed
    When I click on "New ticket" "link"
    And I set the field "Subject" to "Cannot print payslips"
    And I set the field "Description" to "The tray jams on the second page."
    And I click on "Create ticket" "button"
    # Scoped to the ticket's heading on purpose. Unscoped, the step can collect its
    # matches while the new page has sent only its <head>, whose <title> carries the
    # subject and is never visible, and then wait on that node alone.
    Then I should see "Cannot print payslips" in the "#mhd-detail-title" "css_element"
    # The way back from the new ticket's page is the browser's own Back, which
    # is the path that used to hand over a frozen copy of the list: Moodle serves
    # these pages cacheable-on-back, so the DOM is restored and the PHP never
    # re-runs. pageshow/persisted is what closes that.
    When I press the "back" button in the browser
    Then the mooDesk ticket "Cannot print payslips" should be listed
