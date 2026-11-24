@local @local_moodesk @javascript
Feature: The sidebar remembers whether it was collapsed
  In order to keep the layout I chose
  As a user of mooDesk
  I need the collapsed sidebar to stay collapsed when the page reloads

  # Run by the "Behat JavaScript" job in .github/workflows/behat.yml. The state is
  # saved from the browser, through core_user/repository, and the server renders
  # it on the next page load: only a real browser and a reload can show both
  # halves. M.util.set_user_preference(), which the shell used until 3.0.5, was
  # removed in Moodle 5.2, and the shell then saved nothing without any error.
  #
  # app_shell.js holds a core/pending marker while the preference is saved, so
  # the reload waits for the save rather than racing it.
  #
  # 1366 is above the tablet step: there the toggle collapses the sidebar to its
  # rail. Below it the same toggle opens the drawer, which is never persisted.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email              |
      | agent1   | Alba      | Agente   | agent1@example.com |
    And the following "role assigns" exist:
      | user   | role      | contextlevel | reference |
      | agent1 | mhd_agent | System       |           |

  Scenario: The collapsed sidebar stays collapsed after a reload, and so does the expanded one
    Given I change window size to "1366x768"
    And I am on the "local_moodesk > Tickets" page logged in as "agent1"
    And the "class" attribute of "[data-region=\"moodesk-app\"]" "css_element" should not contain "moodesk-app--collapsed"
    When I click on "[data-region=\"moodesk-nav-toggle\"]" "css_element"
    Then the "class" attribute of "[data-region=\"moodesk-app\"]" "css_element" should contain "moodesk-app--collapsed"
    And I reload the page
    And the "class" attribute of "[data-region=\"moodesk-app\"]" "css_element" should contain "moodesk-app--collapsed"
    And the "aria-expanded" attribute of "[data-region=\"moodesk-nav-toggle\"]" "css_element" should contain "false"
    When I click on "[data-region=\"moodesk-nav-toggle\"]" "css_element"
    Then the "class" attribute of "[data-region=\"moodesk-app\"]" "css_element" should not contain "moodesk-app--collapsed"
    And I reload the page
    And the "class" attribute of "[data-region=\"moodesk-app\"]" "css_element" should not contain "moodesk-app--collapsed"
