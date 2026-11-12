@local @local_moodesk @javascript
Feature: The New ticket form writes into the same message box the reply composer does
  In order to describe a problem without fighting the form
  As a requester
  I need the description box to grow with what I write and then stop

  # Run by the "Behat JavaScript" job in .github/workflows/behat.yml.
  #
  # This box and the reply composer's are one control: both forms declare the
  # same textarea, both drive amd/src/composer_autogrow.js, and both take their
  # ceiling and line height from components/_composer.scss.
  #
  # They had not actually been sharing it. That partial addressed the box by the
  # class the forms put on it, and a moodleform element's `class` never reaches
  # the control — Moodle 5 renders it on the `.fitem` wrapper instead, leaving
  # the textarea with `form-control` and nothing else. So the rule matched no
  # element in either form and both boxes ran on stock Bootstrap: no ceiling, and
  # a drag handle that fights the auto-grow. The selector now uses the
  # data-region both forms declare, which Moodle passes through untouched.
  #
  # These scenarios are the regression check on that fix, on the form that was
  # NOT otherwise touched.

  Background:
    Given the following "users" exist:
      | username   | firstname | lastname | email                  |
      | requester1 | Rita      | Requena  | requester1@example.com |

  Scenario: The description box is the shared control, with a ceiling and no drag handle
    Given I change window size to "1280x900"
    And I am on the "local_moodesk > New ticket" page logged in as "requester1"
    Then the mooDesk message box should be the shared auto-growing control

  Scenario: It grows with what is written
    Given I change window size to "1280x900"
    And I am on the "local_moodesk > New ticket" page logged in as "requester1"
    # Long enough to wrap past the four rows the box opens at. Wrapped text
    # rather than typed newlines: Moodle's multiline step does not carry a
    # newline into a plain textarea, and what is being measured is the box
    # growing to fit its content — which wrapping produces just as well.
    When I set the field "Description" to "The printer on the second floor has been jamming since Monday morning and every time we clear the middle tray it starts again after two or three pages, which makes it hard to get the intake forms printed before the clinic opens."
    Then the mooDesk message box should have grown to fit what was typed
    And the mooDesk message box should be the shared auto-growing control

  Scenario: And stops at the ceiling, scrolling inside itself rather than pushing the form down
    Given I change window size to "1280x900"
    And I am on the "local_moodesk > New ticket" page logged in as "requester1"
    # Well past ten lines. Without the ceiling the box grows to the length of the
    # message and takes the Submit button off the bottom of the page with it.
    When I set the field "Description" to "The printer on the second floor has been jamming since Monday morning and every time we clear the middle tray it starts again after two or three pages, which makes it hard to get the intake forms printed before the clinic opens. The printer on the second floor has been jamming since Monday morning and every time we clear the middle tray it starts again after two or three pages, which makes it hard to get the intake forms printed before the clinic opens. The printer on the second floor has been jamming since Monday morning and every time we clear the middle tray it starts again after two or three pages, which makes it hard to get the intake forms printed before the clinic opens. The printer on the second floor has been jamming since Monday morning and every time we clear the middle tray it starts again after two or three pages, which makes it hard to get the intake forms printed before the clinic opens. We have already tried the usual reset, powering it off overnight, and swapping the paper for a fresh ream from the store cupboard. The printer on the second floor has been jamming since Monday morning and every time we clear the middle tray it starts again after two or three pages, which makes it hard to get the intake forms printed before the clinic opens. The printer on the second floor has been jamming since Monday morning and every time we clear the middle tray it starts again after two or three pages, which makes it hard to get the intake forms printed before the clinic opens. The printer on the second floor has been jamming since Monday morning and every time we clear the middle tray it starts again after two or three pages, which makes it hard to get the intake forms printed before the clinic opens. The printer on the second floor has been jamming since Monday morning and every time we clear the middle tray it starts again after two or three pages, which makes it hard to get the intake forms printed before the clinic opens. We have already tried the usual reset, powering it off overnight, and swapping the paper for a fresh ream from the store cupboard. The printer on the second floor has been jamming since Monday morning and every time we clear the middle tray it starts again after two or three pages, which makes it hard to get the intake forms printed before the clinic opens. The printer on the second floor has been jamming since Monday morning and every time we clear the middle tray it starts again after two or three pages, which makes it hard to get the intake forms printed before the clinic opens. The printer on the second floor has been jamming since Monday morning and every time we clear the middle tray it starts again after two or three pages, which makes it hard to get the intake forms printed before the clinic opens. The printer on the second floor has been jamming since Monday morning and every time we clear the middle tray it starts again after two or three pages, which makes it hard to get the intake forms printed before the clinic opens. We have already tried the usual reset, powering it off overnight, and swapping the paper for a fresh ream from the store cupboard."
    Then the mooDesk message box should have stopped at its ceiling
    # The form below it is still reachable, which is the whole point of stopping.
    And "Submit ticket" "button" should be visible

  Scenario Outline: Nothing about it scrolls sideways
    Given I change window size to "<width>x900"
    And I am on the "local_moodesk > New ticket" page logged in as "requester1"
    When I set the field "Description" to "A description long enough to need more than one line at any width at all"
    Then the mooDesk message box should be the shared auto-growing control
    And the mooDesk element ".mhd-new-ticket, form.mform" should not scroll sideways

    Examples:
      | width |
      | 360   |
      | 768   |
      | 1440  |

  Scenario: The form still submits, and the ticket still arrives
    Given I change window size to "1280x900"
    And I am on the "local_moodesk > New ticket" page logged in as "requester1"
    When I set the following fields to these values:
      | Subject     | Enrolment key rejected               |
      | Description | The key from the email does not work |
    And I press "Submit ticket"
    Then I should see "Enrolment key rejected"
    And I should see "The key from the email does not work"
