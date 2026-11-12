@local @local_moodesk @javascript
Feature: A narrow ticket detail offers Conversation and Details as two tabs
  In order to work a ticket on a screen too narrow for two columns
  As an agent
  I need each half of the detail one tap away instead of one long scroll away

  # Run by the "Behat JavaScript" job in .github/workflows/behat.yml.
  #
  # Above the collapse width the detail is a conversation with a management rail
  # beside it, and these tabs do not exist. Below it the rail used to stack under
  # the thread, which buried it under however long the conversation happened to
  # be and buried the composer under the rail in turn.
  #
  # The collapse width is not the same in both mounts, and that is deliberate:
  # the drawer is a fixed 880px panel, so it keeps two columns down to 769px,
  # while the page shares the window with the app shell's 256px sidebar and runs
  # out of room at 992px. Each mount's tabs appear exactly where that mount's
  # own layout collapses — see the note at the top of detail_tabs.js.
  #
  # What every scenario here is really testing is that switching tabs only
  # changes what is visible. Nothing is re-rendered, so nothing typed is lost.

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
    And the mooDesk edition is "pro"

  @javascript
  Scenario: The drawer opens on Conversation, with Details behind a tab
    Given I change window size to "390x800"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    # A ticket is opened to read it. Details is a place you go, not the place
    # you land.
    Then ".mhd-detail-drawer .mhd-detail__tabs" "css_element" should be visible
    And ".mhd-detail-drawer .mhd-detail__main" "css_element" should be visible
    And ".mhd-detail-drawer .mhd-detail__rail" "css_element" should not be visible
    And the "aria-selected" attribute of ".mhd-detail-drawer [data-panel=\"conversation\"][data-action]" "css_element" should contain "true"
    # The composer belongs to the conversation and follows it: a reply box under
    # the properties reads as belonging to them.
    And ".mhd-detail-drawer .mhd-detail__composer" "css_element" should be visible

  @javascript
  Scenario: Details shows the rail, and Conversation brings the thread back
    Given I change window size to "390x800"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    And I click on "Details" "button" in the ".mhd-detail-drawer .mhd-detail__tabs" "css_element"
    Then ".mhd-detail-drawer .mhd-detail__rail" "css_element" should be visible
    And ".mhd-detail-drawer .mhd-detail__main" "css_element" should not be visible
    # Everything the rail carries came with it.
    And ".mhd-detail-drawer #mhd-prop-priority" "css_element" should be visible
    # The composer went with the conversation.
    And ".mhd-detail-drawer .mhd-detail__composer" "css_element" should not be visible
    When I click on "Conversation" "button" in the ".mhd-detail-drawer .mhd-detail__tabs" "css_element"
    Then ".mhd-detail-drawer .mhd-detail__main" "css_element" should be visible
    And ".mhd-detail-drawer .mhd-detail__rail" "css_element" should not be visible
    And ".mhd-detail-drawer .mhd-detail__composer" "css_element" should be visible

  @javascript
  Scenario: A reply being written survives a trip to Details and back
    Given I change window size to "390x800"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    And I set the field "Reply" to "Half-written answer about the toner."
    And I click on "Details" "button" in the ".mhd-detail-drawer .mhd-detail__tabs" "css_element"
    And I click on "Conversation" "button" in the ".mhd-detail-drawer .mhd-detail__tabs" "css_element"
    # The whole reason switching hides rather than re-renders. Losing a
    # half-written reply to a tap on Details would make the tabs worse than the
    # scroll they replaced.
    Then the field "Reply" matches value "Half-written answer about the toner."

  @javascript
  Scenario: Sending a public reply re-renders the detail and the tabs come back
    Given I change window size to "390x800"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    And I set the field "Reply" to "New toner is on its way to you."
    And I press "Send reply"
    Then I should see "New toner is on its way to you."
    And ".mhd-detail-drawer" "css_element" should be visible
    # A reply replaces the whole detail through the fragment. The tabs are wired
    # against markup that no longer exists, so they have to be wired again — and
    # the panel that comes back has to open on the conversation the reply just
    # joined.
    And ".mhd-detail-drawer .mhd-detail__tabs" "css_element" should be visible
    And ".mhd-detail-drawer .mhd-detail__main" "css_element" should be visible
    And the field "Reply" matches value ""
    When I click on "Details" "button" in the ".mhd-detail-drawer .mhd-detail__tabs" "css_element"
    Then ".mhd-detail-drawer .mhd-detail__rail" "css_element" should be visible
    And ".mhd-detail-drawer .mhd-detail__main" "css_element" should not be visible

  @javascript
  Scenario: An internal note takes the same path
    Given I change window size to "390x800"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    And I click on "Internal Note" "button"
    And I set the field "Reply" to "Toner budget already approved this quarter."
    # One composer, one send control — and it is named for what it is about to
    # do. In note mode that is not "Send reply".
    And I press "Add internal note"
    Then I should see "Toner budget already approved this quarter."
    And ".mhd-detail-drawer .mhd-detail__tabs" "css_element" should be visible
    When I click on "Details" "button" in the ".mhd-detail-drawer .mhd-detail__tabs" "css_element"
    Then ".mhd-detail-drawer .mhd-detail__rail" "css_element" should be visible

  @javascript
  Scenario: Working several tickets in a row leaves one detail wired, not many
    Given I change window size to "390x800"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    # Three full cycles through the panel: open, switch, close, open the next.
    # The drawer replaces its content rather than updating it, so each ticket
    # arrives as a fresh detail and the one before it is discarded — and this is
    # the session an agent actually has, dozens of tickets without a reload.
    When I open the mooDesk ticket "Printer is jammed"
    And I click on "Details" "button" in the ".mhd-detail-drawer .mhd-detail__tabs" "css_element"
    And I click on ".mhd-detail-drawer .mhd-drawer__close" "css_element"
    And I open the mooDesk ticket "VPN drops hourly"
    And I click on "Details" "button" in the ".mhd-detail-drawer .mhd-detail__tabs" "css_element"
    And I click on ".mhd-detail-drawer .mhd-drawer__close" "css_element"
    And I open the mooDesk ticket "Printer is jammed"
    # Still exactly one detail in the document: no orphan tree left behind for a
    # stale listener to reformat.
    Then the mooDesk document should hold 1 "[data-region=\"mhd-detail\"]" element
    # And the tabs on the current one still answer, on the first click.
    And ".mhd-detail-drawer .mhd-detail__main" "css_element" should be visible
    When I click on "Details" "button" in the ".mhd-detail-drawer .mhd-detail__tabs" "css_element"
    Then ".mhd-detail-drawer .mhd-detail__rail" "css_element" should be visible
    And ".mhd-detail-drawer .mhd-detail__main" "css_element" should not be visible

  @javascript
  Scenario: The tab strip is a tab strip, and the keyboard can drive it
    Given I change window size to "390x800"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    Then the "role" attribute of ".mhd-detail-drawer .mhd-detail__tabs" "css_element" should contain "tablist"
    And the "role" attribute of ".mhd-detail-drawer [data-panel=\"conversation\"][data-action]" "css_element" should contain "tab"
    And the "role" attribute of ".mhd-detail-drawer .mhd-detail__main" "css_element" should contain "tabpanel"
    And the "role" attribute of ".mhd-detail-drawer .mhd-detail__rail" "css_element" should contain "tabpanel"
    # A tab strip is one Tab stop: the selected tab is reachable, the other is
    # not, and the arrows move between them.
    And the "tabindex" attribute of ".mhd-detail-drawer [data-panel=\"conversation\"][data-action]" "css_element" should contain "0"
    And the "tabindex" attribute of ".mhd-detail-drawer [data-panel=\"details\"][data-action]" "css_element" should contain "-1"
    When I click on "Conversation" "button" in the ".mhd-detail-drawer .mhd-detail__tabs" "css_element"
    And I press the right key
    Then ".mhd-detail-drawer .mhd-detail__rail" "css_element" should be visible
    And the focused element is ".mhd-detail-drawer [data-panel=\"details\"][data-action]" "css_element"
    When I press the left key
    Then ".mhd-detail-drawer .mhd-detail__main" "css_element" should be visible
    And the focused element is ".mhd-detail-drawer [data-panel=\"conversation\"][data-action]" "css_element"

  @javascript
  Scenario: Nothing inside the hidden panel can be reached
    Given I change window size to "390x800"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    # `hidden` takes the panel out of the accessibility tree and out of the tab
    # order with it, so the controls in the half that is not on screen are not
    # somewhere the keyboard can wander into.
    Then ".mhd-detail-drawer #mhd-prop-priority" "css_element" should not be visible
    When I click on "Details" "button" in the ".mhd-detail-drawer .mhd-detail__tabs" "css_element"
    Then ".mhd-detail-drawer #mhd-prop-priority" "css_element" should be visible
    And "Send reply" "button" should not be visible

  @javascript
  Scenario Outline: The canonical page collapses at its own width, and gets the same tabs
    Given I change window size to "<width>x900"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed" full page
    # One component, one behaviour. The page runs out of room earlier in window
    # pixels than the drawer does, because the app shell's sidebar is still
    # taking 256px of it.
    Then ".mhd-detail__tabs" "css_element" should be visible
    And ".mhd-detail__main" "css_element" should be visible
    And ".mhd-detail__rail" "css_element" should not be visible
    When I click on "Details" "button" in the ".mhd-detail__tabs" "css_element"
    Then ".mhd-detail__rail" "css_element" should be visible
    And "#mhd-prop-priority" "css_element" should be visible
    And ".mhd-detail__main" "css_element" should not be visible

    Examples:
      | width |
      | 360   |
      | 390   |
      | 576   |
      | 768   |
      | 820   |
      | 991   |

  @javascript
  Scenario Outline: A draft survives the tab switch on the page too
    Given I change window size to "<width>x900"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed" full page
    And I set the field "Reply" to "Written on the canonical page."
    And I click on "Details" "button" in the ".mhd-detail__tabs" "css_element"
    And I click on "Conversation" "button" in the ".mhd-detail__tabs" "css_element"
    Then the field "Reply" matches value "Written on the canonical page."

    Examples:
      | width |
      | 390   |
      | 991   |

  @javascript
  Scenario Outline: Where there is room for two columns there are no tabs
    Given I change window size to "<width>x900"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    # The drawer is a fixed 880px panel, so it keeps both columns well below the
    # width at which the page gives up. Both regions are on screen, the strip is
    # not, and no ARIA describes a control that is not there.
    Then ".mhd-detail-drawer .mhd-detail__tabs" "css_element" should not be visible
    And ".mhd-detail-drawer .mhd-detail__main" "css_element" should be visible
    And ".mhd-detail-drawer .mhd-detail__rail" "css_element" should be visible
    # The landmark the template gives it, not a tab panel role left behind.
    And the "role" attribute of ".mhd-detail-drawer .mhd-detail__main" "css_element" should contain "region"

    Examples:
      | width |
      | 820   |
      | 991   |
      | 1024  |
      | 1280  |

  @javascript
  Scenario: Growing past the collapse width hands both columns back
    Given I change window size to "390x800"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I open the mooDesk ticket "Printer is jammed"
    And I click on "Details" "button" in the ".mhd-detail-drawer .mhd-detail__tabs" "css_element"
    And I change window size to "1280x900"
    # Residual state here would look perfectly normal on screen: a conversation
    # still carrying `hidden` from a tab that no longer exists is simply a
    # missing thread.
    Then ".mhd-detail-drawer .mhd-detail__tabs" "css_element" should not be visible
    And ".mhd-detail-drawer .mhd-detail__main" "css_element" should be visible
    And ".mhd-detail-drawer .mhd-detail__rail" "css_element" should be visible
    And ".mhd-detail-drawer .mhd-detail__composer" "css_element" should be visible
    # The landmarks come back rather than disappearing: the conversation was a
    # `region` before it was a tab panel, and has to be one again.
    And the "role" attribute of ".mhd-detail-drawer .mhd-detail__main" "css_element" should contain "region"
    When I change window size to "390x800"
    # Back in tab mode, and on the tab that was open before the window grew.
    # Remembering it is the point — being dropped back on Conversation because
    # the window was briefly wide is a jump the user did not ask for.
    Then ".mhd-detail-drawer .mhd-detail__tabs" "css_element" should be visible
    And ".mhd-detail-drawer .mhd-detail__rail" "css_element" should be visible
    And ".mhd-detail-drawer .mhd-detail__main" "css_element" should not be visible
