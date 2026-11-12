@local @local_moodesk
Feature: A ticket is only readable by the people it belongs to
  In order to keep support conversations private
  As a site owner
  I need requesters confined to their own tickets and agents given the whole queue

  Background:
    Given the following "users" exist:
      | username   | firstname | lastname | email                  |
      | requester1 | Rita      | Requena  | requester1@example.com |
      | requester2 | Raul      | Rivas    | requester2@example.com |
      | agent1     | Alba      | Agente   | agent1@example.com     |
    And the following "role assigns" exist:
      | user   | role      | contextlevel | reference |
      | agent1 | mhd_agent | System       |           |
    And the following "local_moodesk > tickets" exist:
      | subject                     | message                        | user       |
      | Payroll export is wrong     | My payslip shows the wrong tax | requester1 |
      | Cannot reset my password    | The reset email never arrives  | requester2 |

  Scenario: A requester sees their own ticket and is refused someone else's
    When I am on the "local_moodesk > Tickets" page logged in as "requester2"
    Then I should see "Cannot reset my password"
    And I should not see "Payroll export is wrong"
    And mooDesk should refuse me the ticket "Payroll export is wrong"

  Scenario: An agent gets the whole queue
    When I am on the "local_moodesk > Tickets" page logged in as "agent1"
    Then I should see "Payroll export is wrong"
    And I should see "Cannot reset my password"
    When I am on the "Payroll export is wrong" "local_moodesk > Ticket" page
    Then I should see "My payslip shows the wrong tax"
