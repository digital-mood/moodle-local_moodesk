@local @local_moodesk @javascript
Feature: A ticket is worked on without leaving the queue
  In order to keep my place in the queue
  As an agent
  I need answering a ticket to happen where I opened it

  # Run by the "Behat JavaScript" job in .github/workflows/behat.yml. The
  # non-JavaScript smoke job excludes @javascript, so these run in one place.
  #
  # What is under test is that the panel survives the action. Sending a reply
  # used to POST to view.php and land on the ticket's own page, which took the
  # drawer down and the list behind it with it — for the single most common
  # thing an agent does. The composer now sends the same form to ajax.php and
  # the panel re-reads the ticket, so the assertions here are always three: the
  # new content is on screen, the drawer is still up, and the list behind it is
  # still there.
  #
  # The scenarios never reload, and that omission is the test.

  Background:
    Given the following "users" exist:
      | username   | firstname | lastname | email                  |
      | requester1 | Rita      | Requena  | requester1@example.com |
      | agent1     | Alba      | Agente   | agent1@example.com     |
    And the following "role assigns" exist:
      | user   | role      | contextlevel | reference |
      | agent1 | mhd_agent | System       |           |
    And the following "local_moodesk > tickets" exist:
      | subject           | message            | user       | status | priority |
      | Printer is jammed | Paper everywhere   | requester1 | open   | low      |
      | VPN drops hourly  | Disconnects at :00 | requester1 | open   | low      |

  @javascript
  Scenario: A public reply is sent from the drawer and appears in it
    Given I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    And I set the field "Reply" to "New toner is on its way to you."
    And I press "Send reply"
    Then I should see "New toner is on its way to you."
    And ".mhd-detail-drawer" "css_element" should be visible
    And the mooDesk ticket "VPN drops hourly" should be listed

  @javascript
  Scenario: An internal note is added from the drawer and appears in it
    Given I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    And I click on "Internal Note" "button"
    And I set the field "Reply" to "Toner budget already approved this quarter."
    # One composer, one send control — and it is named for what it is about to
    # do. In note mode that is not "Send reply".
    And I press "Add internal note"
    Then I should see "Toner budget already approved this quarter."
    And ".mhd-detail-drawer" "css_element" should be visible
    And the mooDesk ticket "VPN drops hourly" should be listed

  @javascript
  Scenario: The composer is empty again once the reply is sent
    Given I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    And I set the field "Reply" to "Replacement ordered."
    And I press "Send reply"
    Then I should see "Replacement ordered."
    # Not a nicety: a composer still holding the sent message is one accidental
    # second press away from sending it twice.
    And the field "Reply" matches value ""

  @javascript
  Scenario: A message the form refuses never leaves the browser, and the composer keeps sending by AJAX afterwards
    Given I change window size to "1280x900"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    And I mark this mooDesk page
    When I open the mooDesk ticket "Printer is jammed"
    # An empty message. moodleform's own rule refuses it in the browser, and that
    # is where the refusal has to end: one inline message, no request, and so
    # no toast reporting a failure the server never saw.
    And I press "Send reply"
    Then I should see "You must supply a value here."
    # No request was even started: the send control is disabled only for the
    # length of one, and the form wears is-sending only while one is in flight.
    # Neither is true here, because nothing was sent.
    And the "disabled" attribute of ".mhd-detail-drawer .mhd-composer__send" "css_element" should not be set
    And ".mhd-detail-drawer .mhd-composer form.is-sending" "css_element" should not exist
    And I wait "2" seconds
    And ".mhd-toast--error" "css_element" should not exist
    And ".mhd-detail-drawer" "css_element" should be visible
    # Corrected, the same composer sends the same form the same way: over
    # ajax.php, the panel staying up. A refusal in the browser must not leave
    # the form on a path where the next send is a plain POST and a redirect.
    When I set the field "Reply" to "First answer, after the refusal."
    And I press "Send reply"
    Then I should see "First answer, after the refusal."
    And ".mhd-detail-drawer" "css_element" should be visible
    And the mooDesk page should not have been reloaded
    And the mooDesk ticket "VPN drops hourly" should be listed
    # Second cycle, as an internal note: refused again, corrected again. One
    # composer, one listener, one request per send — a handler that had piled
    # up across the re-render would show as a second copy of the note. The
    # success toast from the send above sits over the composer's tab row until
    # it leaves; the click waits for it.
    And I wait until ".mhd-toast" "css_element" does not exist
    When I click on "Internal Note" "button"
    And I press "Add internal note"
    Then I should see "You must supply a value here."
    And I wait "2" seconds
    And ".mhd-toast--error" "css_element" should not exist
    When I set the field "Reply" to "Second answer, a note, after a second refusal."
    And I press "Add internal note"
    Then I should see "Second answer, a note, after a second refusal."
    And ".mhd-detail-drawer" "css_element" should be visible
    And the mooDesk page should not have been reloaded
    And the mooDesk document should hold 1 "#mhd-reply-editor" element

  @javascript
  Scenario: A refusal from the server is still reported, with the message kept
    Given I change window size to "1280x900"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    And I mark this mooDesk page
    When I open the mooDesk ticket "Printer is jammed"
    And I set the field "Reply" to "This one the server will refuse."
    And the mooDesk composer's session key is wrong
    And I press "Send reply"
    # The browser's rule passed, the request went out, the server said no. That
    # is the toast's job, and the reply is still in the box to be sent again.
    Then ".mhd-toast--error" "css_element" should exist
    And I should see "Your session has most likely timed out"
    And the field "Reply" matches value "This one the server will refuse."
    And ".mhd-detail-drawer" "css_element" should be visible
    And the mooDesk page should not have been reloaded
