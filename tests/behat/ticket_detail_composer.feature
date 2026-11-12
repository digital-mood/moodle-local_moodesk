@local @local_moodesk
Feature: One way out of a ticket, and one thing to do with a reply
  In order to work a ticket without second-guessing the controls
  As an agent
  I need the composer to send, and the panel to close, and neither to do both

  # The composer used to carry a Cancel beside its submit — moodleform's default
  # action pair. Inside the drawer that was a second way out of the ticket,
  # sitting in the conversation, next to the panel's own close control in the
  # corner. On the canonical page it did nothing but reload the ticket with an
  # empty message box.
  #
  # It is gone from both mounts, because there is one composer and it renders in
  # both. The hierarchy is now: the drawer's close control leaves the ticket,
  # and the composer's send button sends. The bar the two buttons shared went
  # with them, and the conversation got that height back.

  Background:
    Given the following "users" exist:
      | username   | firstname | lastname | email                  |
      | requester1 | Rita      | Requena  | requester1@example.com |
      | agent1     | Alba      | Agente   | agent1@example.com     |
    And the following "role assigns" exist:
      | user   | role      | contextlevel | reference |
      | agent1 | mhd_agent | System       |           |
    And the following "local_moodesk > tickets" exist:
      | subject           | message          | user       | status | priority |
      | Printer is jammed | Paper everywhere | requester1 | open   | low      |

  @javascript
  Scenario: The drawer's composer offers send and nothing else
    Given I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    Then ".mhd-detail-drawer .mhd-composer__send" "css_element" should be visible
    And "Cancel" "button" should not exist in the ".mhd-detail-drawer .mhd-composer" "css_element"
    # The way out is still there, in the corner where it always was.
    And ".mhd-detail-drawer .mhd-drawer__close" "css_element" should be visible

  @javascript
  Scenario: Closing is still the drawer's own control, and it still works
    Given I am on the "local_moodesk > Tickets" page logged in as "agent1"
    And I mark this mooDesk page
    When I open the mooDesk ticket "Printer is jammed"
    And I click on ".mhd-detail-drawer .mhd-drawer__close" "css_element"
    Then ".mhd-detail-drawer .mhd-detail" "css_element" should not be visible
    # It came down in place; the list behind it was never re-fetched.
    And the mooDesk page should not have been reloaded
    And I should see "Printer is jammed"

  Scenario: The canonical page's composer has no Cancel either
    Given I am on the "Printer is jammed" "local_moodesk > Ticket" page logged in as "agent1"
    Then ".mhd-composer__send" "css_element" should exist
    And "Cancel" "button" should not exist in the ".mhd-composer" "css_element"

  @javascript
  Scenario Outline: The composer stays one row at every width, in the drawer
    Given I change window size to "<width>x800"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    Then the mooDesk composer should keep its send button beside the message box
    And the mooDesk message box should have grown to fit what was typed
    And the mooDesk element ".mhd-detail-drawer .mhd-composer" should not scroll sideways
    And the mooDesk element ".mhd-detail-drawer .mhd-detail__composer" should not scroll sideways

    Examples:
      | width |
      | 360   |
      | 390   |
      | 576   |
      | 768   |
      | 820   |
      | 991   |
      | 1024  |
      | 1440  |

  @javascript
  Scenario Outline: And on the canonical page too
    Given I change window size to "<width>x900"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed" full page
    Then the mooDesk composer should keep its send button beside the message box
    And the mooDesk element ".mhd-composer" should not scroll sideways

    Examples:
      | width |
      | 360   |
      | 768   |
      | 1440  |

  @javascript
  Scenario: The send control names the thing it is about to do
    Given I change window size to "1280x900"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    # Icon-only, so the name is the whole of what it says. It has to change with
    # the mode, because one control sends two different things.
    Then the "aria-label" attribute of ".mhd-detail-drawer .mhd-composer__send" "css_element" should contain "Send reply"
    When I click on "Internal Note" "button"
    Then the "aria-label" attribute of ".mhd-detail-drawer .mhd-composer__send" "css_element" should contain "Add internal note"
    And the "title" attribute of ".mhd-detail-drawer .mhd-composer__send" "css_element" should contain "Add internal note"
    When I click on "Public Reply" "button"
    Then the "aria-label" attribute of ".mhd-detail-drawer .mhd-composer__send" "css_element" should contain "Send reply"

  @javascript
  Scenario Outline: A one-line composer is one row, box and button the same height
    Given I change window size to "<width>x800"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    Then the mooDesk composer should size its message box to its send button
    And the mooDesk composer should keep its send button beside the message box

    Examples:
      | width |
      | 360   |
      | 390   |
      | 576   |
      | 768   |
      | 1440  |

  @javascript
  Scenario Outline: A refused reply puts its message under the box, and moves nothing
    Given I change window size to "<width>x800"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    And I note how the mooDesk send button sits against the message box
    # An empty message is refused by the form's own client-side rule, which is
    # the state being measured: the composer stays up, and the message appears.
    And I press "Send reply"
    Then I should see "You must supply a value here."
    And I wait until the page is ready
    # moodleform packs a field and its error into one wrapper. While that
    # wrapper was the grid item, the error made the row taller and took the send
    # button down with it — the bug this guards.
    And the mooDesk validation message should sit below the message box
    And the mooDesk send button should still sit against the message box
    And the mooDesk element ".mhd-detail-drawer .mhd-composer" should not scroll sideways

    Examples:
      | width |
      | 360   |
      | 390   |
      | 576   |
      | 768   |
      | 1440  |

  @javascript
  Scenario: The box grows with the reply, and the button neither grows nor moves off it
    Given I change window size to "1280x900"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    Then the mooDesk composer should size its message box to its send button
    # Several lines. autogrow sizes the box from its content, and the button
    # stays 44x44 beside it rather than being stretched by the row.
    #
    # Wrapped text rather than typed newlines: Moodle's multiline step does not
    # carry a newline into a plain textarea, and growth is growth either way.
    When I set the field "Reply" to "The printer on the second floor has been jamming since Monday morning and every time we clear the middle tray it starts again after two or three pages, which makes it hard to get the intake forms printed before the clinic opens."
    Then the mooDesk composer should keep its send button beside the message box

  @javascript
  Scenario: The reply box is the shared control, ceiling and all
    Given I change window size to "1280x900"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    # The reply composer's own rule adds a frame on top of the shared control —
    # a floor, an inset, no border — and says in as many words that it restates
    # none of what components/_composer.scss owns. It is the more specific of
    # the two, so anything it did restate would silently become this form's
    # private copy of a shared decision, and only the New ticket form was ever
    # asked to prove the shared decision holds.
    Then the mooDesk message box should be the shared auto-growing control
    # Well past ten lines. The ceiling is what keeps a long reply from growing
    # the composer up over the conversation it belongs to.
    When I set the field "Reply" to "The printer on the second floor has been jamming since Monday morning and every time we clear the middle tray it starts again after two or three pages, which makes it hard to get the intake forms printed before the clinic opens. We have already tried the usual reset, powering it off overnight, and swapping the paper for a fresh ream from the store cupboard. The printer on the second floor has been jamming since Monday morning and every time we clear the middle tray it starts again after two or three pages, which makes it hard to get the intake forms printed before the clinic opens. We have already tried the usual reset, powering it off overnight, and swapping the paper for a fresh ream from the store cupboard. The printer on the second floor has been jamming since Monday morning and every time we clear the middle tray it starts again after two or three pages, which makes it hard to get the intake forms printed before the clinic opens. We have already tried the usual reset, powering it off overnight, and swapping the paper for a fresh ream from the store cupboard. The printer on the second floor has been jamming since Monday morning and every time we clear the middle tray it starts again after two or three pages, which makes it hard to get the intake forms printed before the clinic opens. We have already tried the usual reset, powering it off overnight, and swapping the paper for a fresh ream from the store cupboard. The printer on the second floor has been jamming since Monday morning and every time we clear the middle tray it starts again after two or three pages, which makes it hard to get the intake forms printed before the clinic opens. We have already tried the usual reset, powering it off overnight, and swapping the paper for a fresh ream from the store cupboard. "
    Then the mooDesk message box should have stopped at its ceiling
    And the mooDesk composer should keep its send button beside the message box

  @javascript
  Scenario Outline: The box and the send button meet with no seam, at rest and with the box focused
    Given I change window size to "<width>x800"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    # One control in two colours. The frame around both carries the outer
    # radius; the box is square and the button keeps only its right corners, so
    # where the box ends and the button begins there is nothing of the frame's
    # background to see — no white notch cut out of the blue, top or bottom.
    Then the mooDesk send button should be joined to the message box
    # Focusing the box draws its ring inside itself, and the join has to hold
    # under it: no second edge, and nothing opening between the two. Scoped to
    # the reply composer: the New ticket drawer holds a box with the same
    # data-region, earlier in the document and closed.
    When I click on "#mhd-reply-editor textarea[data-region=\"mhd-composer-input\"]" "css_element"
    Then the mooDesk send button should be joined to the message box while the box has focus

    Examples:
      | width |
      | 360   |
      | 390   |
      | 576   |
      | 768   |
      | 1440  |
