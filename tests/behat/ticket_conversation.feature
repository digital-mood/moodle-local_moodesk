@local @local_moodesk
Feature: A ticket raised in the web form comes back answered
  In order to get support
  As a requester
  I need my ticket to reach an agent and the agent's answer to reach me

  Background:
    Given the following "users" exist:
      | username   | firstname | lastname | email                  |
      | requester1 | Rita      | Requena  | requester1@example.com |
      | agent1     | Alba      | Agente   | agent1@example.com     |
    And the following "role assigns" exist:
      | user   | role      | contextlevel | reference |
      | agent1 | mhd_agent | System       |           |

  Scenario: The requester submits, the agent answers, the requester reads the answer
    Given I am on the "local_moodesk > New ticket" page logged in as "requester1"
    When I set the following fields to these values:
      | Subject     | Enrolment key rejected              |
      | Description | The key from the email does not work |
    And I press "Submit ticket"
    Then I should see "Enrolment key rejected"

    When I am on the "local_moodesk > Tickets" page logged in as "agent1"
    Then I should see "Enrolment key rejected"
    When I am on the "Enrolment key rejected" "local_moodesk > Ticket" page
    And I set the field "Reply" to "The key was rotated this morning, here is the new one."
    And I press "Send reply"
    Then I should see "The key was rotated this morning, here is the new one."

    When I am on the "Enrolment key rejected" "local_moodesk > Ticket" page logged in as "requester1"
    Then I should see "The key was rotated this morning, here is the new one."

  Scenario: An internal note stays out of the requester's thread
    Given the following "local_moodesk > tickets" exist:
      | subject                | message                  | user       |
      | Enrolment key rejected | The key does not work    | requester1 |
    And the following "local_moodesk > replies" exist:
      | ticket                 | user   | message                             | type |
      | Enrolment key rejected | agent1 | Escalating to the enrolment team     | note |
      | Enrolment key rejected | agent1 | We are looking into it               | reply |
    When I am on the "Enrolment key rejected" "local_moodesk > Ticket" page logged in as "agent1"
    Then I should see "Escalating to the enrolment team"
    And I should see "We are looking into it"
    When I am on the "Enrolment key rejected" "local_moodesk > Ticket" page logged in as "requester1"
    Then I should see "We are looking into it"
    And I should not see "Escalating to the enrolment team"

  Scenario: A plain-text answer is shown as text, its address as a link, and typed markup as the text it is
    Given the following "local_moodesk > tickets" exist:
      | subject                | message                  | user       |
      | Enrolment key rejected | The key does not work    | requester1 |
    And the following "local_moodesk > replies" exist:
      | ticket                 | user   | message                                                                                                   | type  |
      | Enrolment key rejected | agent1 | Read <b>this</b> & <script>alert(1)</script> at https://docs.example.com/keys?course=1&step=2, then reply. | reply |
    When I am on the "Enrolment key rejected" "local_moodesk > Ticket" page logged in as "requester1"
    Then I should see "Read <b>this</b> & <script>alert(1)</script> at https://docs.example.com/keys?course=1&step=2, then reply."
    And "//*[contains(@class, 'mhd-thread')]//a[@href = 'https://docs.example.com/keys?course=1&step=2'][normalize-space(.) = 'https://docs.example.com/keys?course=1&step=2']" "xpath_element" should exist
    And "//*[contains(@class, 'mhd-thread')]//b" "xpath_element" should not exist
    And "//*[contains(@class, 'mhd-thread')]//script" "xpath_element" should not exist
