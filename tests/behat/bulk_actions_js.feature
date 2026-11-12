@local @local_moodesk @javascript
Feature: An agent acts on many tickets at once
  In order to triage a queue without opening every ticket
  As an agent
  I need to select tickets from the list and apply one action to all of them

  # Run by the "Behat JavaScript" job in .github/workflows/behat.yml, against a
  # real Chrome in a pinned Selenium container. The non-JavaScript smoke job
  # still excludes @javascript, so these scenarios run in exactly one place.
  # docs/behat-javascript-suite.md has the local recipe.
  #
  # Two things about how the toolbar is addressed here, both of them because
  # this file was written before anything ran it:
  #
  #  - Its three selects are reached through mooDesk steps, not through
  #    `I set the field`. They carry an aria-label and no name and no
  #    <label for>, and Mink's "field" selector reads none of those. See note 5
  #    on behat_local_moodesk.
  #  - Apply is scoped to #mhd-bulk-toolbar. The filter bar has an Apply button
  #    of its own, it is visible at the same time, and it comes first in the
  #    document — an unscoped click submits the filter and no bulk action runs.
  #
  # The server-rendered half of the same feature — who gets the controls at all
  # — is covered by bulk_actions.feature, which the smoke job runs.

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
      | subject             | message              | user       | priority |
      | Printer is jammed   | Paper everywhere     | requester1 | high     |
      | VPN drops hourly    | Disconnects at :00   | requester1 | high     |
      | Password reset loop | Link expires at once | requester1 | low      |

  Scenario: Selecting tickets opens the toolbar and reports the count
    Given I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I select the mooDesk ticket "Printer is jammed"
    Then I should see "1 ticket selected"
    When I select the mooDesk ticket "VPN drops hourly"
    Then I should see "2 tickets selected"
    When I click on "Clear selection" "button" in the "#mhd-bulk-toolbar" "css_element"
    Then I should not see "2 tickets selected"

  Scenario: Select-all covers the rows on the page and tracks partial selections
    Given I am on the "local_moodesk > Tickets" page logged in as "agent1"
    And the mooDesk select-all checkbox should not be indeterminate
    When I click on "#mhd-select-all" "css_element"
    Then I should see "3 tickets selected"
    And the mooDesk select-all checkbox should not be indeterminate
    When I select the mooDesk ticket "Password reset loop"
    Then I should see "2 tickets selected"
    And the mooDesk select-all checkbox should be indeterminate

  Scenario: The rows a selection covers are highlighted, and only those
    Given I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I select the mooDesk ticket "Printer is jammed"
    Then the mooDesk ticket "Printer is jammed" should be highlighted as selected
    And the mooDesk ticket "VPN drops hourly" should not be highlighted as selected
    When I click on "#mhd-select-all" "css_element"
    Then the mooDesk ticket "VPN drops hourly" should be highlighted as selected
    And the mooDesk ticket "Password reset loop" should be highlighted as selected
    When I click on "Clear selection" "button" in the "#mhd-bulk-toolbar" "css_element"
    Then the mooDesk ticket "Printer is jammed" should not be highlighted as selected
    And the mooDesk ticket "VPN drops hourly" should not be highlighted as selected
    And the mooDesk ticket "Password reset loop" should not be highlighted as selected

  # The busy state lasts as long as the request does, so the POST is held to
  # look at it; the scenarios below this one send theirs for real.
  Scenario: Apply says it is working and locks the batch while it is sent
    Given I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I select the mooDesk ticket "Printer is jammed"
    And I choose the mooDesk bulk action "Change status"
    And I set the mooDesk bulk status to "Resolved"
    And the next mooDesk bulk action is held before it is sent
    And I click on "Apply" "button" in the "#mhd-bulk-toolbar" "css_element"
    Then the "Applying…" "button" should be disabled
    And the "Clear selection" "button" should be disabled
    And the "#mhd-bulk-action-select" "css_element" should be disabled
    And the "#mhd-select-all" "css_element" should be disabled
    And the "aria-busy" attribute of "#mhd-bulk-toolbar" "css_element" should contain "true"

  # From ticket_service::BULK_CONFIRM_FROM tickets (10) Apply asks first, since
  # every ticket may send its own email inside the request. The scenarios that
  # act on one or two tickets click Apply plainly: a dialog there would fail them.
  Scenario: A batch of ten asks before it is sent, and declining sends nothing
    Given the following "local_moodesk > tickets" exist:
      | subject           | message | user       |
      | Batch ticket four  | Body    | requester1 |
      | Batch ticket five  | Body    | requester1 |
      | Batch ticket six   | Body    | requester1 |
      | Batch ticket seven | Body    | requester1 |
      | Batch ticket eight | Body    | requester1 |
      | Batch ticket nine  | Body    | requester1 |
      | Batch ticket ten   | Body    | requester1 |
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I click on "#mhd-select-all" "css_element"
    Then I should see "10 tickets selected"
    When I choose the mooDesk bulk action "Change status"
    And I set the mooDesk bulk status to "Resolved"
    And I click on "#mhd-bulk-apply" "css_element" dismissing the dialogue
    Then the "#mhd-bulk-apply" "css_element" should be enabled
    And the mooDesk ticket "Printer is jammed" should show the status "Open"
    When I click on "#mhd-bulk-apply" "css_element" confirming the dialogue
    Then I should see "10 ticket(s) updated successfully."
    And the mooDesk ticket "Printer is jammed" should show the status "Resolved"

  Scenario: A bulk status change applies to every selected ticket
    Given I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I select the mooDesk ticket "Printer is jammed"
    And I select the mooDesk ticket "VPN drops hourly"
    And I choose the mooDesk bulk action "Change status"
    And I set the mooDesk bulk status to "Resolved"
    And I click on "Apply" "button" in the "#mhd-bulk-toolbar" "css_element"
    Then I should see "2 ticket(s) updated successfully."
    And the mooDesk ticket "Printer is jammed" should show the status "Resolved"
    And the mooDesk ticket "VPN drops hourly" should show the status "Resolved"
    And the mooDesk ticket "Password reset loop" should show the status "Open"

  Scenario: A bulk assignment applies to every selected ticket
    Given I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I select the mooDesk ticket "Printer is jammed"
    And I choose the mooDesk bulk action "Assign to agent"
    And I set the mooDesk bulk assignee to "Bruno Bianchi"
    And I click on "Apply" "button" in the "#mhd-bulk-toolbar" "css_element"
    Then I should see "1 ticket(s) updated successfully."
    And the mooDesk ticket "Printer is jammed" should be assigned to "Bruno Bianchi"
    And the mooDesk ticket "VPN drops hourly" should be unassigned

  Scenario: The filters survive a bulk action
    Given I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I set the field "priority" to "High"
    Then I should see "Printer is jammed"
    And I should not see "Password reset loop"
    When I select the mooDesk ticket "Printer is jammed"
    And I choose the mooDesk bulk action "Change status"
    And I set the mooDesk bulk status to "Resolved"
    And I click on "Apply" "button" in the "#mhd-bulk-toolbar" "css_element"
    Then I should see "1 ticket(s) updated successfully."
    And I should not see "Password reset loop"
    And the field "priority" matches value "High"

  Scenario: A ticket that cannot move is named, and the rest still move
    Given the following "local_moodesk > tickets" exist:
      | subject           | message              | user       | status |
      | Laptop was stolen | Reported to security | requester1 | closed |
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I select the mooDesk ticket "Laptop was stolen"
    And I select the mooDesk ticket "Printer is jammed"
    And I choose the mooDesk bulk action "Change status"
    And I set the mooDesk bulk status to "Resolved"
    And I click on "Apply" "button" in the "#mhd-bulk-toolbar" "css_element"
    Then I should see "1 ticket(s) updated."
    And I should see "Could not be changed:"
    And the mooDesk ticket "Printer is jammed" should show the status "Resolved"
    And the mooDesk ticket "Laptop was stolen" should show the status "Closed"
