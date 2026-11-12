@local @local_moodesk @javascript
Feature: The New ticket form suggests knowledge base articles as the subject is typed
  In order to find an answer before I send a ticket
  As a requester
  I need the form to offer the articles that match my subject, without touching what I have written

  # Run by the "Behat JavaScript" job in .github/workflows/behat.yml.
  #
  # The block is filled by amd/src/kb_suggestions.js from
  # local_moodesk_search_kb_articles, which asks kb_service::suggest(). What
  # these scenarios pin down is the contract a requester experiences: nothing
  # under three characters, the matching published articles and nothing else,
  # the chosen category first, never a stale list, and a form that is exactly
  # as they left it after reading an article.
  #
  # The page and the drawer are the same form; the outlines run both mounts.

  Background:
    Given the following "users" exist:
      | username   | firstname | lastname | email                  |
      | requester1 | Rita      | Requena  | requester1@example.com |
      | agent1     | Ana       | Agente   | agent1@example.com     |
      | editor1    | Elena     | Editora  | editor1@example.com    |
    And the following "role assigns" exist:
      | user    | role      | contextlevel | reference |
      | agent1  | mhd_agent | System       |           |
      | editor1 | mhd_agent | System       |           |
    And the following "local_moodesk > kb articles" exist:
      | title                        | body                                                              | status    | audience | user    | kbcategory | timepublished |
      | How to reset your password   | <p>Click <strong>Forgot password</strong> on the login page.</p>  | published | everyone | editor1 | Access     | 1000        |
      | Password policy explained    | <p>Eight characters, a digit and a symbol.</p>                    | published | everyone | editor1 | Access     | 3000        |
      | Password reset for graders   | <p>Graders use the staff portal.</p>                              | published | everyone | editor1 | Grades     | 2000        |
      | Password vault for agents    | <p>Where the shared passwords live.</p>                           | published | agents   | editor1 | Access     | 4000        |
      | Password draft not yet live  | <p>Not yet.</p>                                                   | draft     | everyone | editor1 | Access     | 5000        |
      | Enrolment key does not work  | <p>Ask your teacher for the current key.</p>                      | published | everyone | editor1 | Grades     | 6000        |

  Scenario Outline: Fewer than three characters asks nothing; three characters bring the matching articles, newest first
    Given I change window size to "1280x900"
    And I start a mooDesk ticket from the "<mount>" as "requester1"
    When I set the field "Subject" to "pa"
    Then the mooDesk suggested articles should be:
      | |
    When I set the field "Subject" to "password"
    Then the mooDesk suggested articles should be:
      | Password policy explained  |
      | Password reset for graders |
      | How to reset your password |
    And I should not see "Password vault for agents"
    And I should not see "Password draft not yet live"

    Examples:
      | mount  |
      | page   |
      | drawer |

  Scenario: An agent is offered the agents-only article a requester is not
    Given I change window size to "1280x900"
    And I start a mooDesk ticket from the "page" as "agent1"
    When I set the field "Subject" to "password"
    Then the mooDesk suggested articles should be:
      | Password vault for agents  |
      | Password policy explained  |
      | Password reset for graders |
      | How to reset your password |
    And I should not see "Password draft not yet live"

  Scenario Outline: Choosing a category brings its articles to the front
    Given I change window size to "1280x900"
    And I start a mooDesk ticket from the "<mount>" as "requester1"
    When I set the field "Subject" to "password"
    And I set the field "Category" to "Grades"
    Then the mooDesk suggested articles should be:
      | Password reset for graders |
      | Password policy explained  |
      | How to reset your password |
    When I set the field "Category" to "Access"
    Then the mooDesk suggested articles should be:
      | Password policy explained  |
      | How to reset your password |
      | Password reset for graders |

    Examples:
      | mount  |
      | page   |
      | drawer |

  Scenario: Clearing the subject empties the block
    Given I change window size to "1280x900"
    And I start a mooDesk ticket from the "page" as "requester1"
    When I set the field "Subject" to "password"
    Then the mooDesk suggested articles should be:
      | Password policy explained  |
      | Password reset for graders |
      | How to reset your password |
    When I set the field "Subject" to ""
    Then the mooDesk suggested articles should be:
      | |

  Scenario: A reply to an older subject can never replace the list for a newer one
    Given I change window size to "1280x900"
    And I start a mooDesk ticket from the "page" as "requester1"
    # The first request is held back until well after the second has answered,
    # so the reply for "password" arrives when "enrolment" is already on screen.
    And the mooDesk knowledge base answers the first request last
    When I set the field "Subject" to "password"
    And I wait "1" seconds
    And I set the field "Subject" to "enrolment"
    Then the mooDesk suggested articles should be:
      | Enrolment key does not work |
    And I wait "4" seconds
    And the mooDesk suggested articles should be:
      | Enrolment key does not work |
    And I should not see "How to reset your password"

  Scenario Outline: Reading an article opens it in a new window and leaves the ticket exactly as written
    Given I change window size to "1280x900"
    And I start a mooDesk ticket from the "<mount>" as "requester1"
    When I set the field "Subject" to "password reset"
    And I set the field "Category" to "Access"
    And I set the field "Department" to "General"
    And I set the field "Description" to "I used the link twice and it still says the token has expired."
    # Both words have to match: "Password policy explained" has no "reset".
    Then the mooDesk suggested articles should be:
      | How to reset your password |
      | Password reset for graders |
    And I mark this mooDesk page
    When I open the mooDesk suggested article "How to reset your password"
    And I switch to a second window
    Then I should see "Click Forgot password on the login page."
    And I should see "How to reset your password" in the ".mhd-page-header" "css_element"
    When I switch back to the mooDesk ticket window
    Then the mooDesk page should not have been reloaded
    And the field "Subject" matches value "password reset"
    And the field "Category" matches value "Access"
    And the field "Department" matches value "General"
    And the field "Description" matches value "I used the link twice and it still says the token has expired."
    And the mooDesk suggested articles should be:
      | How to reset your password |
      | Password reset for graders |

    Examples:
      | mount  |
      | page   |
      | drawer |

  Scenario: With the knowledge base switched off there is no block to fill
    Given the following config values are set as admin:
      | kb_enabled | 0 | local_moodesk |
    And I change window size to "1280x900"
    And I start a mooDesk ticket from the "drawer" as "requester1"
    When I set the field "Subject" to "password"
    And I wait "1" seconds
    Then "[data-region='mhd-kb-suggest']" "css_element" should not exist
    And I should not see "How to reset your password"

  Scenario: Nothing about the block scrolls sideways on a phone
    Given I change window size to "420x800"
    And I start a mooDesk ticket from the "page" as "requester1"
    When I set the field "Subject" to "password"
    Then the mooDesk suggested articles should be:
      | Password policy explained  |
      | Password reset for graders |
      | How to reset your password |
    And the mooDesk element "form.mform" should not scroll sideways

  Scenario: A requester reading in another language is offered their translation, once
    # KB-06. pt_br is a fake pack the plugin has no strings for, so the form
    # stays in English; the suggestion is what changes language.
    Given the following "local_moodesk > language packs" exist:
      | code  | name               | iso |
      | pt_br | Português - Brasil | pt  |
    And the following "local_moodesk > kb articles" exist:
      | title                     | body                                          | status    | audience | user    | lang  | translationof              |
      | Como redefinir sua senha  | <p>Clique em Esqueci a senha (password).</p>  | published | everyone | editor1 | pt_br | How to reset your password |
    And the following "users" exist:
      | username   | firstname | lastname | email                  | lang  |
      | requester2 | Paulo     | Pereira  | requester2@example.com | pt_br |
    And the mooDesk edition is "pro"
    And I change window size to "1280x900"
    And I start a mooDesk ticket from the "page" as "requester2"
    When I set the field "Subject" to "password"
    Then the mooDesk suggested articles should be:
      | Password policy explained  |
      | Password reset for graders |
      | Como redefinir sua senha   |
    And I should not see "How to reset your password"
    And I should see "English" in the ".mhd-kb-suggest" "css_element"
