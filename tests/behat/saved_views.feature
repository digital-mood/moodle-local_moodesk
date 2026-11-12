@local @local_moodesk
Feature: Saved views show their names as the agent typed them
  In order to recognise my saved views in the ticket list
  As an agent
  I need each view's name escaped once, not twice

  # The view is seeded as the user preference saved_view_service::save() writes,
  # so the scenario runs without JavaScript. The name carries the three
  # characters an extra s() used to turn into visible entities: &, < and ".

  Background:
    Given the following "users" exist:
      | username   | firstname | lastname | email                  |
      | requester1 | Rita      | Requena  | requester1@example.com |
      | agent1     | Alba      | Agente   | agent1@example.com     |
    And the following "role assigns" exist:
      | user   | role      | contextlevel | reference |
      | agent1 | mhd_agent | System       |           |
    And the following "local_moodesk > tickets" exist:
      | subject           | message          | user       | priority |
      | Printer is jammed | Paper everywhere | requester1 | high     |
    And the following "user preferences" exist:
      | user   | preference                    | value                                                       |
      | agent1 | local_mhd_savedview_ops-queue | {"name":"Ops & \"Night\" <EU>","params":{"status":["open"]}} |

  Scenario: A saved view name with markup characters reads exactly as saved
    Given I am on the "local_moodesk > Tickets" page logged in as "agent1"
    Then I should see "Ops & \"Night\" <EU>" in the ".mhd-savedviews-list" "css_element"
    And I should not see "&amp;" in the ".mhd-savedviews-list" "css_element"
    And I should not see "&quot;" in the ".mhd-savedviews-list" "css_element"
