@local @local_moodesk
Feature: Operational health is readable by the people who run the site, and by nobody else
  In order to keep failure data away from staff who only handle tickets
  As a site owner
  I need the System health page open to managers and refused to agents

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email              |
      | agent1   | Alba      | Agente   | agent1@example.com |
    And the following "role assigns" exist:
      | user   | role      | contextlevel | reference |
      | agent1 | mhd_agent | System       |           |

  Scenario: A manager reads the health page
    When I am on the "local_moodesk > System health" page logged in as "admin"
    Then I should see "System health"
    And I should see "Nothing to report"

  Scenario: An agent holding the ticket queue is still refused the health page
    Given I am on the "local_moodesk > Tickets" page logged in as "agent1"
    Then mooDesk should refuse me the "System health" page

  # The base-only leg is the JavaScript one, hence the tag; the step skips the
  # scenario on the leg that installs the add-on.
  @javascript
  Scenario: Without the add-on, neither System health nor the notification preferences name its parts
    Given the mooDesk add-on is not installed
    When I am on the "local_moodesk > System health" page logged in as "admin"
    Then I should see "Nothing to report"
    And I should see "Scheduled tasks"
    And I should not see "Webhooks"
    And I should not see "Email ingestion"
    And I visit "/message/notificationpreferences.php"
    And I should see "mooDesk operational alerts"
    And I should not see "email ingestion alerts"
    And I should not see "[[messageprovider"
