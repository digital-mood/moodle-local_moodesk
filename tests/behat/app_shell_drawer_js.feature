@local @local_moodesk @javascript
Feature: The page behind the mobile navigation drawer stays where it was
  In order to use the navigation on a phone without losing my place
  As a user of mooDesk
  I need the page under the drawer to hold still while the drawer is open

  # Run by the "Behat JavaScript" job in .github/workflows/behat.yml, against a
  # real Chrome in a pinned Selenium container: none of this exists without a
  # browser — the drawer only opens below the tablet step, and whether the
  # document scrolls is a question only a viewport can answer.
  #
  # The lock is one class on <body>, so every scenario asserts it twice: the
  # class, which is the contract app_shell.js implements, and the used overflow
  # it is supposed to produce, which is what actually stops a finger or a wheel.
  # Asserting only the class would stay green with the stylesheet gone or with a
  # theme rule beating it. Neither step tries to scroll: `overflow: hidden` only
  # refuses the user, and `window.scrollBy` moves a locked page exactly as far
  # as a free one. See the note on probe_scroll_lock in behat_local_moodesk.
  #
  # Enterprise, and the first scenario signs in as the admin: the drawer's own
  # internal scrolling is one of the things under test, and it can only be shown
  # by a navigation long enough to overflow its band. An agent's sidebar, on
  # Starter or with the administration group gated away, is not.
  #
  # The toggle closes the drawer from the keyboard rather than from a second
  # click, and that is not a workaround: the open drawer is 256px of fixed panel
  # over the left of the topbar, so at every width the drawer exists at, the
  # toggle is underneath it and no pointer reaches it. Worse than covered — the
  # drawer's brand mark lands on the toggle's exact rectangle, so raising the
  # toggle would draw a hamburger over the logo. The click that opened it left
  # the focus there, so Enter activates the same handler a click would.
  #
  # What a finger or a mouse gets instead is the drawer's own close control, in
  # its top-right corner, covered by its own scenario below. Backdrop and Escape
  # keep working as they did.
  #
  # Sizes are window sizes, not viewport sizes, and the two differ in both
  # directions. Chrome takes its own chrome out of the height, so the viewport is
  # the shorter of the two; and the CI container enforces a minimum window width
  # of about 500px, so a request narrower than that is widened. Neither changes
  # which side of the step a scenario is on — 420 and 768 are both inside the
  # drawer's, 1024 is outside it — but a scenario that clicks something whose
  # position depends on the viewport's width has to allow for it. See the
  # backdrop scenario, which is the one that does.

  Background:
    Given the mooDesk edition is "enterprise"
    And the following "users" exist:
      | username   | firstname | lastname | email                  |
      | requester1 | Rita      | Requena  | requester1@example.com |
      | agent1     | Alba      | Agente   | agent1@example.com     |
    And the following "role assigns" exist:
      | user   | role      | contextlevel | reference |
      | agent1 | mhd_agent | System       |           |
    And the following "local_moodesk > tickets" exist:
      | subject               | message              | user       | priority |
      | Printer is jammed     | Paper everywhere     | requester1 | high     |
      | VPN drops hourly      | Disconnects at :00   | requester1 | high     |
      | Password reset loop   | Link expires at once | requester1 | low      |
      | Laptop will not charge| Dead on the dock     | requester1 | high     |
      | Mail sync stalled     | Stuck since Monday   | requester1 | low      |
      | Projector flickers    | Room 12, every hour  | requester1 | low      |
      | Badge reader offline  | Side entrance        | requester1 | high     |
      | Wifi drops in the lab | Only near the window | requester1 | low      |

  Scenario: Opening the drawer holds the page still and closing it with the toggle lets go
    Given I change window size to "420x600"
    And I am on the "local_moodesk > Tickets" page logged in as "admin"
    And the mooDesk page should scroll freely
    When I click on "[data-region=\"moodesk-nav-toggle\"]" "css_element"
    Then "[data-region=\"moodesk-drawer-backdrop\"]" "css_element" should be visible
    And ".moodesk-app__brand" "css_element" should be visible
    And ".moodesk-app__sidebar-footer" "css_element" should be visible
    And the "class" attribute of "body" "css_element" should contain "mhd-drawer-locked"
    And the mooDesk page should be held still
    And the mooDesk element ".moodesk-app__nav" should scroll internally
    When I press the enter key
    Then the "class" attribute of "body" "css_element" should not contain "mhd-drawer-locked"
    And the "aria-expanded" attribute of "[data-region=\"moodesk-nav-toggle\"]" "css_element" should contain "false"
    And the mooDesk page should scroll freely

  # 768 rather than 420, and the width is load-bearing. Selenium clicks an
  # element's in-view centre, the backdrop is inset:0, so its centre is the
  # viewport's centre — and that has to land clear of the 256px drawer or the
  # click is intercepted by the panel it is meant to close. A 420px window does
  # not guarantee it: the CI container enforces a minimum window width of about
  # 500px, which puts the centre at x=250, six pixels inside the drawer. At 768
  # the centre is past x=376, and the width is still inside the drawer's step.
  Scenario: Closing the drawer from the backdrop lets the page go
    Given I change window size to "768x600"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I click on "[data-region=\"moodesk-nav-toggle\"]" "css_element"
    Then the "class" attribute of "body" "css_element" should contain "mhd-drawer-locked"
    When I click on "[data-region=\"moodesk-drawer-backdrop\"]" "css_element"
    Then the "class" attribute of "body" "css_element" should not contain "mhd-drawer-locked"
    And the mooDesk page should scroll freely

  Scenario: Closing the drawer with Escape lets the page go
    Given I change window size to "420x600"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I click on "[data-region=\"moodesk-nav-toggle\"]" "css_element"
    Then the "class" attribute of "body" "css_element" should contain "mhd-drawer-locked"
    When I press the escape key
    Then the "class" attribute of "body" "css_element" should not contain "mhd-drawer-locked"
    And the mooDesk page should scroll freely

  # Growing past the step turns the sidebar back into a rail, and nothing on
  # screen would explain a page that is still frozen. 768 is the last width that
  # is still the drawer's; the crossing is to 1024 rather than to 769 because a
  # classic scrollbar takes its width out of the viewport, and 769 minus a
  # scrollbar is back below the step.
  Scenario: Growing past the drawer's step releases the page
    Given I change window size to "768x600"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I click on "[data-region=\"moodesk-nav-toggle\"]" "css_element"
    Then the "class" attribute of "body" "css_element" should contain "mhd-drawer-locked"
    When I change window size to "1024x768"
    Then the "class" attribute of "body" "css_element" should not contain "mhd-drawer-locked"
    And "[data-region=\"moodesk-drawer-backdrop\"]" "css_element" should not be visible
    And the mooDesk page should scroll freely

  # The pointer's way out, and the one path the toggle cannot serve. Closing here
  # has to hand the focus back explicitly: the control is inside the drawer and
  # stops being displayed with it, so without that, focus falls to <body> and the
  # keyboard user starts again from the top of the document.
  Scenario: The drawer's own close control shuts it with a pointer
    Given I change window size to "420x600"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I click on "[data-region=\"moodesk-nav-toggle\"]" "css_element"
    Then "[data-region=\"moodesk-drawer-close\"]" "css_element" should be visible
    And the "class" attribute of "body" "css_element" should contain "mhd-drawer-locked"
    # A click inside the drawer is not a click outside it. On a group heading,
    # deliberately: it is the one thing in the panel that is neither a link nor
    # a control, so the assertion is about the backdrop and not about what the
    # click happened to land on.
    When I click on ".moodesk-app__nav-group-label" "css_element"
    Then the "class" attribute of "body" "css_element" should contain "mhd-drawer-locked"
    When I click on "[data-region=\"moodesk-drawer-close\"]" "css_element"
    Then "[data-region=\"moodesk-drawer-close\"]" "css_element" should not be visible
    And "[data-region=\"moodesk-drawer-backdrop\"]" "css_element" should not be visible
    And the "class" attribute of "body" "css_element" should not contain "mhd-drawer-locked"
    And the "aria-expanded" attribute of "[data-region=\"moodesk-nav-toggle\"]" "css_element" should contain "false"
    And the focused element is "[data-region=\"moodesk-nav-toggle\"]" "css_element"
    And the mooDesk page should scroll freely

  # Above the step the sidebar is a rail, the toggle collapses it, and there is
  # no drawer to close. The control must not merely be invisible there — it is
  # display:none, so a screen reader is offered one navigation control and not
  # two.
  Scenario: Above the drawer's step the close control does not exist
    Given I change window size to "1024x768"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    Then "[data-region=\"moodesk-drawer-close\"]" "css_element" should not be visible
    When I click on "[data-region=\"moodesk-nav-toggle\"]" "css_element"
    Then "[data-region=\"moodesk-drawer-close\"]" "css_element" should not be visible
    And "[data-region=\"moodesk-drawer-backdrop\"]" "css_element" should not be visible
    And the "class" attribute of "body" "css_element" should not contain "mhd-drawer-locked"

  # ── The closed drawer and the Tab key ──────────────────────────────────────
  #
  # The sidebar comes before the topbar in the document, so the way to walk into
  # it from the toggle is backwards. Shift+Tab is therefore the discriminator in
  # all four of the scenarios below, and the toggle is where they start from:
  # opening and closing the drawer parks the focus there by a path the suite
  # already covers, which beats tabbing forward from the top of the page and
  # depending on what Moodle renders above the shell.
  #
  # `inert` on the <aside> is the mechanism, but none of these assert only the
  # attribute — an attribute a browser ignored would still be there. They press
  # the key and ask where the focus went.

  Scenario: A closed drawer is not somewhere the Tab key can go
    Given I change window size to "420x600"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    Then the "inert" attribute of "#moodesk-app-sidebar" "css_element" should be set
    When I click on "[data-region=\"moodesk-nav-toggle\"]" "css_element"
    And I press the escape key
    Then the focused element is "[data-region=\"moodesk-nav-toggle\"]" "css_element"
    When I press the shift tab key
    Then the focus should not be inside the mooDesk sidebar
    # The element immediately before the sidebar in the document. Landing on it
    # is the whole sidebar being stepped over in one press.
    And the focused element is ".moodesk-app__skip-link" "css_element"

  Scenario: An open drawer is
    Given I change window size to "420x600"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I click on "[data-region=\"moodesk-nav-toggle\"]" "css_element"
    Then the "inert" attribute of "#moodesk-app-sidebar" "css_element" should not be set
    When I press the shift tab key
    Then the focus should be inside the mooDesk sidebar

  Scenario: Closing from inside the drawer hands the focus back and shuts it again
    Given I change window size to "420x600"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    When I click on "[data-region=\"moodesk-nav-toggle\"]" "css_element"
    And I press the shift tab key
    Then the focus should be inside the mooDesk sidebar
    When I click on "[data-region=\"moodesk-drawer-close\"]" "css_element"
    Then the focused element is "[data-region=\"moodesk-nav-toggle\"]" "css_element"
    And the focus should not be inside the mooDesk sidebar
    And the "inert" attribute of "#moodesk-app-sidebar" "css_element" should be set
    When I press the shift tab key
    Then the focused element is ".moodesk-app__skip-link" "css_element"

  # Residual state in both directions. A rail left inert by a stale drawer state
  # is a navigation nobody can reach, and it would look perfectly normal.
  Scenario: Crossing the drawer's step hands the sidebar back, and takes it away again
    Given I change window size to "768x600"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    Then the "inert" attribute of "#moodesk-app-sidebar" "css_element" should be set
    When I change window size to "1024x768"
    Then the "inert" attribute of "#moodesk-app-sidebar" "css_element" should not be set
    # Up here the toggle collapses the rail rather than opening a drawer, so this
    # also covers the collapsed rail: still every one of those links, still
    # reachable, only their labels hidden.
    When I click on "[data-region=\"moodesk-nav-toggle\"]" "css_element"
    And I press the shift tab key
    Then the focus should be inside the mooDesk sidebar
    And the "inert" attribute of "#moodesk-app-sidebar" "css_element" should not be set
    When I change window size to "768x600"
    Then the "inert" attribute of "#moodesk-app-sidebar" "css_element" should be set
