<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Step definitions for the mooDesk smoke suite.
 *
 * Three things the core steps cannot do:
 *
 *  1. Deliver an inbound email. The scenario feeds a message straight into
 *     email_ingestion_service::process_raw_email(), which is where the poller
 *     hands off — everything downstream of IMAP is covered, the transport is
 *     not. See docs/behat-smoke-suite.md.
 *  2. Choose the active edition. In production that is a signed JWT bound to a
 *     wwwroot, unmintable from a test, so the suite writes the config row that
 *     edition_service reads only while serving the Behat site.
 *  3. Read a response without navigating to it. Loading a permission-denied
 *     page would fail the scenario in Behat's after-step exception hook, so the
 *     denial steps fetch the URL with the browser's session cookie and assert
 *     on the response body instead.
 *  4. Address one ticket row's checkbox, and read a checkbox's indeterminate
 *     state. Rows are addressed by subject because their ids are not knowable
 *     from a feature file, and indeterminate is a DOM property with no HTML
 *     attribute behind it, so no core step can see it.
 *  5. Drive the bulk toolbar. Its three selects are labelled with aria-label
 *     and carry no name and no <label for>, and Mink's "field" named selector
 *     reads none of those: it matches on id, name, placeholder or a label's
 *     "for". Moodle adds aria-label to the "button" selector but not to
 *     "field", so `I set the field "Choose action..."` finds nothing. These
 *     steps hand the core step the control's id instead, which is the handle
 *     the component actually publishes.
 *  6. Assert a bulk action's outcome on the row it changed. Every value the
 *     toolbar can write also appears in the toolbar's own dropdowns, so a bare
 *     `I should see "Bruno Bianchi"` can pass without a single ticket moving.
 *     These steps scope the assertion to one row.
 *  7. Find out whether something scrolls. The mobile navigation drawer holds
 *     the document still while it is open, and no core step asks the browser
 *     to try to scroll and report back — which is the only way to tell a page
 *     that is locked from a page that merely fits on screen.
 *  8. Ask where the focus is without naming the element. A closed drawer must
 *     not take the focus at all, and the assertion worth making is about the
 *     region rather than about which of its dozen links the Tab key reached —
 *     core's "the focused element is" needs a selector that matches exactly
 *     one node, and the answer here legitimately varies with the user's
 *     capabilities and the edition.
 *  9. Say which end of the conversation is on screen. Mink calls an element
 *     visible when CSS has not hidden it, which is true of every message in a
 *     thread whether or not it has been scrolled to. Opening a ticket at its
 *     newest message is a statement about the scrollport, so these steps
 *     measure the entry against the box that clips it.
 * 10. Notice that the page did not reload. Closing the detail drawer must take
 *     the panel down without going anywhere, and a reload would leave exactly
 *     the same list on screen. The only way to tell the two apart is to leave
 *     something in the window beforehand and look for it afterwards.
 * 11. Say whether the composer is still one row. The send button sits beside
 *     the message box, and the failure worth catching is it wrapping underneath
 *     on a narrow viewport — which no assertion about visibility can see,
 *     because a button below the box is just as visible as one beside it.
 * 12. Measure the composer's row against itself. A one-line box has to be
 *     exactly as tall as the button next to it, and the button must not move
 *     when a validation message appears under the box. Both are statements
 *     about pixels, and nothing in the DOM records either.
 * 13. Say whether the message box has stopped growing. Both forms share one
 *     auto-growing textarea with a ceiling, and "it stops there and scrolls
 *     inside itself" is invisible to every assertion about content: the text
 *     is in the box either way.
 * * 14. Call the REST API over HTTP. The chain worth exercising once end to end
 *     — Moodle's REST server, the external API, the api_endpoint gates, the
 *     endpoint — only exists on the wire; the PHPUnit suite drives the
 *     classes directly and cannot see it (KB-07 D13).
 *
 * @package   local_moodesk
 * @category  test
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Exception\ExpectationException;
use local_moodeskpro\service\email_ingestion_service;

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.
require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Step definitions for the mooDesk smoke suite.
 *
 * @package   local_moodesk
 * @category  test
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_moodesk extends behat_base {
    /** @var string|null Result of the last email handed to the ingestion service. */
    protected ?string $lastingestionresult = null;

    /**
     * Resolve a mooDesk page name to its URL.
     *
     * Recognised pages:
     *   Tickets    — the ticket list (index.php)
     *   New ticket — the submission form (submit.php)
     *   Dashboard  — the dashboard (dashboard.php)
     *   System health — operational incidents (health.php)
     *   Add agent  — onboarding an agent (add_agent.php)
     *   Agents     — the agents directory (agents.php)
     *   Articles   — the knowledge base management list (kb_articles.php)
     *   New article — the article form (kb_article_edit.php)
     *   Knowledge  — the knowledge base reader's landing (kb.php)
     *
     * @param string $page The page name from the feature file.
     * @return moodle_url
     */
    protected function resolve_page_url(string $page): moodle_url {
        switch (strtolower($page)) {
            case 'tickets':
                return new moodle_url('/local/moodesk/index.php');

            case 'new ticket':
                return new moodle_url('/local/moodesk/submit.php');

            case 'dashboard':
                return new moodle_url('/local/moodesk/dashboard.php');

            case 'system health':
                return new moodle_url('/local/moodesk/health.php');

            case 'add agent':
                return new moodle_url('/local/moodesk/add_agent.php');

            case 'agents':
                return new moodle_url('/local/moodesk/agents.php');

            case 'articles':
                return new moodle_url('/local/moodesk/kb_articles.php');

            case 'new article':
                return new moodle_url('/local/moodesk/kb_article_edit.php');

            case 'knowledge':
                return new moodle_url('/local/moodesk/kb.php');

            default:
                throw new Exception('Unrecognised mooDesk page type "' . $page . '".');
        }
    }

    /**
     * Resolve a page that belongs to one ticket.
     *
     * Recognised page types: Ticket, identified by its exact subject; Article
     * and Article edit, identified by the article's exact title.
     *
     * @param string $type The page type from the feature file.
     * @param string $identifier The ticket subject or article title.
     * @return moodle_url
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        global $DB;

        switch (strtolower($type)) {
            case 'ticket':
                $id = $DB->get_field('local_moodesk_ticket', 'id', ['subject' => $identifier], IGNORE_MULTIPLE);
                if (!$id) {
                    throw new Exception('There is no mooDesk ticket with subject "' . $identifier . '".');
                }
                return new moodle_url('/local/moodesk/view.php', ['id' => $id]);

            case 'article':
                return new moodle_url('/local/moodesk/kb_article.php', ['id' => $this->article_id($identifier)]);

            case 'article edit':
                return new moodle_url('/local/moodesk/kb_article_edit.php', ['id' => $this->article_id($identifier)]);

            default:
                throw new Exception('Unrecognised mooDesk page type "' . $type . '".');
        }
    }

    /**
     * The id of the knowledge base article with this exact title.
     *
     * @param string $title
     * @return int
     */
    protected function article_id(string $title): int {
        global $DB;
        $id = $DB->get_field('local_moodesk_kb_article', 'id', ['title' => $title], IGNORE_MULTIPLE);
        if (!$id) {
            throw new Exception('There is no mooDesk article titled "' . $title . '".');
        }
        return (int) $id;
    }

    /**
     * Archive a mooDesk category by name, as the Categories screen would.
     *
     * @Given /^the mooDesk category "(?P<name_string>(?:[^"]|\\")*)" is archived$/
     * @param string $name The category's exact name.
     */
    public function the_moodesk_category_is_archived(string $name): void {
        global $DB;
        $id = $DB->get_field('local_moodesk_category', 'id', ['name' => $name], IGNORE_MULTIPLE);
        if (!$id) {
            throw new Exception('There is no mooDesk category named "' . $name . '".');
        }
        $DB->set_field('local_moodesk_category', 'visible', 0, ['id' => $id]);
    }

    /**
     * Set the edition the site runs as, for the rest of the scenario.
     *
     * @Given /^the mooDesk edition is "(?P<edition_string>starter|pro|enterprise)"$/
     * @param string $edition One of starter, pro, enterprise.
     */
    public function the_moodesk_edition_is(string $edition): void {
        set_config('behat_edition', $edition, 'local_moodesk');
    }

    /**
     * Deliver one email to the ingestion pipeline.
     *
     * Table keys: from, to, subject, body, messageid, inreplyto, attachment,
     * attachmentcontent, attachmenttype. Everything but "from" has a default.
     *
     * @Given /^the following email arrives at the mooDesk mailbox:$/
     * @param TableNode $data Key/value pairs describing the message.
     */
    public function the_following_email_arrives(TableNode $data): void {
        $fields = [];
        foreach ($data->getRowsHash() as $key => $value) {
            $fields[strtolower(trim($key))] = $value;
        }

        if (empty($fields['from'])) {
            throw new coding_exception('An inbound email needs a "from" address.');
        }

        $raw = $this->build_raw_email($fields);

        $this->lastingestionresult = email_ingestion_service::create()->process_raw_email($raw);
    }

    /**
     * Assert what the ingestion pipeline made of the last delivered email.
     *
     * @Then /^the mooDesk mailbox should report "(?P<result_string>(?:[^"]|\\")*)"$/
     * @param string $expected An email_message::RESULT_* value, e.g. ticket_created.
     */
    public function the_moodesk_mailbox_should_report(string $expected): void {
        if ($this->lastingestionresult === null) {
            throw new ExpectationException('No email has been delivered to the mailbox yet.', $this->getSession());
        }

        if ($this->lastingestionresult !== $expected) {
            throw new ExpectationException(
                'The mailbox reported "' . $this->lastingestionresult . '", expected "' . $expected . '".',
                $this->getSession()
            );
        }
    }

    /**
     * Download the file behind a link on the page and assert what is inside it.
     *
     * Fails when the link is not on the page at all, which is the point: an
     * attachment written to a file area no screen reads is invisible to every
     * assertion made against the rendered page.
     *
     * @Then /^the attachment "(?P<link_string>(?:[^"]|\\")*)" should download the text "(?P<text_string>(?:[^"]|\\")*)"$/
     * @param string $linktext Text of the download link (the file name).
     * @param string $expected Text the downloaded bytes must contain.
     */
    public function the_attachment_should_download_the_text(string $linktext, string $expected): void {
        $content = $this->download_from_link($linktext);

        if (!str_contains($content, $expected)) {
            throw new ExpectationException(
                'The file downloaded from "' . $linktext . '" does not contain "' . $expected . '".',
                $this->getSession()
            );
        }
    }

    /**
     * Assert how an attachment is handed to the browser.
     *
     * The security property this covers is not in the bytes and not on the
     * page: it is the Content-Disposition the file is served with. An .html
     * attachment served inline executes on this site's origin, in the session
     * of whoever opened it — which for a help desk is an agent or an admin,
     * reading a file that a requester or an anonymous email sender chose.
     *
     * @Then /^the attachment "(?P<link_string>(?:[^"]|\\")*)" should be served as "(?P<disposition_string>attachment|inline)"$/
     * @param string $linktext    Text of the download link (the file name).
     * @param string $disposition Disposition the response must carry.
     */
    public function the_attachment_should_be_served_as(string $linktext, string $disposition): void {
        [, $headers] = $this->fetch_from_link($linktext);

        $actual = $this->response_header($headers, 'Content-Disposition');

        if ($actual === '') {
            throw new ExpectationException(
                'The response for "' . $linktext . '" carried no Content-Disposition header at all.',
                $this->getSession()
            );
        }

        if (stripos($actual, $disposition) !== 0) {
            throw new ExpectationException(
                'The attachment "' . $linktext . '" was served as "' . $actual
                    . '", expected "' . $disposition . '".',
                $this->getSession()
            );
        }
    }

    /**
     * Assert that the current user cannot read a ticket.
     *
     * Requests the ticket page out of band — with the browser's session cookie
     * but without navigating to it — because a rendered error page would fail
     * the scenario in Behat's after-step exception hook before anything could
     * be asserted about it.
     *
     * Two assertions, neither of them tied to a translated string: the response
     * has to be an error page, and it must not carry the ticket's subject.
     *
     * @Then /^mooDesk should refuse me the ticket "(?P<subject_string>(?:[^"]|\\")*)"$/
     * @param string $subject Subject of the ticket to request.
     */
    public function moodesk_should_refuse_me_the_ticket(string $subject): void {
        global $DB;

        $ticketid = $DB->get_field('local_moodesk_ticket', 'id', ['subject' => $subject], MUST_EXIST);
        $url      = (new moodle_url('/local/moodesk/view.php', ['id' => $ticketid]))->out(false);
        [$body, $httpcode] = $this->fetch_with_session($url);

        $iserror = $httpcode >= 400 || str_contains($body, 'data-rel="fatalerror"');

        if (!$iserror) {
            throw new ExpectationException(
                'Requesting ticket "' . $subject . '" returned HTTP ' . $httpcode . ' and no error page.',
                $this->getSession()
            );
        }

        if (str_contains($body, s($subject))) {
            throw new ExpectationException(
                'The refusal page for ticket "' . $subject . '" leaked its subject.',
                $this->getSession()
            );
        }
    }

    /**
     * Assert that a named mooDesk page refuses the current user.
     *
     * Fetched with curl rather than navigated to, for the same reason the
     * ticket refusal above is: require_capability() throws, and Behat's
     * after-step hook would fail the scenario on that exception before
     * anything could be asserted about it.
     *
     * The status code alone is the assertion. Matching on the wording of
     * Moodle's permission error would tie the suite to a core string in one
     * language, and the contract under test is "the page did not open", not
     * "the page said this".
     *
     * @Then /^mooDesk should refuse me the "(?P<page_string>(?:[^"]|\\")*)" page$/
     * @param string $page Page name, as understood by resolve_page_url(), or a path.
     */
    public function moodesk_should_refuse_me_the_page(string $page): void {
        $url = (str_starts_with($page, '/') ? new moodle_url($page) : $this->resolve_page_url($page))->out(false);
        [$body, $httpcode] = $this->fetch_with_session($url);

        $iserror = $httpcode >= 400 || str_contains($body, 'data-rel="fatalerror"');

        if (!$iserror) {
            throw new ExpectationException(
                'The "' . $page . '" page returned HTTP ' . $httpcode . ' and no error page.',
                $this->getSession()
            );
        }
    }

    /**
     * Assert that the merge page of a ticket refuses the current user, and opens no merge form.
     *
     * Fetched with curl, as the refusals above are. A merge needs the merge
     * capability and sight of both tickets; either missing ends on an error page.
     *
     * @Then /^mooDesk should refuse me the merge of "(?P<subject_string>[^"]+)"$/
     * @param string $subject The exact subject of the source ticket.
     * @return string The body of the refusal page.
     */
    public function moodesk_should_refuse_me_the_merge_of(string $subject): string {
        global $DB;

        $ticketid = $DB->get_field('local_moodesk_ticket', 'id', ['subject' => $subject], MUST_EXIST);
        $url      = (new moodle_url('/local/moodeskpro/merge.php', ['id' => $ticketid]))->out(false);
        [$body, $httpcode] = $this->fetch_with_session($url);

        $iserror = $httpcode >= 400 || str_contains($body, 'data-rel="fatalerror"');
        if (!$iserror || str_contains($body, s(get_string('merge_preview_button', 'local_moodeskpro')))) {
            throw new ExpectationException(
                'The merge page of "' . $subject . '" returned HTTP ' . $httpcode . ' and no error page.',
                $this->getSession()
            );
        }
        return $body;
    }

    /**
     * Assert that the merge page of a ticket refuses the current user with one of mooDesk's own errors.
     *
     * The plugin's string, not core's: it tells the two refusals apart (no
     * merge capability, or a ticket the user cannot see) without tying the
     * suite to a core string.
     *
     * @Then /^mooDesk should refuse me the merge of "(?P<subject_string>[^"]+)" with "(?P<identifier_string>[a-z_]+)"$/
     * @param string $subject The exact subject of the source ticket.
     * @param string $identifier A local_moodeskpro string identifier, e.g. merge_target_invalid.
     */
    public function moodesk_should_refuse_me_the_merge_with(string $subject, string $identifier): void {
        $body = $this->moodesk_should_refuse_me_the_merge_of($subject);
        if (!str_contains($body, s(get_string($identifier, 'local_moodeskpro')))) {
            throw new ExpectationException(
                'The merge page of "' . $subject . '" was refused, but not with "' . $identifier . '".',
                $this->getSession()
            );
        }
    }

    /**
     * Assert that starting a translation of an article is refused (KB-06):
     * the page answers with an error page or, below Pro, the neutral message.
     *
     * @Then /^mooDesk should refuse me the "(?P<lang_string>[a-z_]+)" translation of "(?P<title_string>(?:[^"]|\\")*)"$/
     * @param string $lang  The language code to start.
     * @param string $title The exact title of the source article.
     */
    public function moodesk_should_refuse_me_the_translation_of(string $lang, string $title): void {
        $url = (new moodle_url('/local/moodeskpro/kb_translate.php', [
            'translate' => $this->article_id($title),
            'to'        => $lang,
        ]))->out(false);
        [$body, $httpcode] = $this->fetch_with_session($url);

        $iserror = $httpcode >= 400 || str_contains($body, 'data-rel="fatalerror"')
            || str_contains($body, get_string('feature_not_licensed_title', 'local_moodeskpro'));

        if (!$iserror) {
            throw new ExpectationException(
                'Starting the "' . $lang . '" translation returned HTTP ' . $httpcode . ' and no error page.',
                $this->getSession()
            );
        }
    }

    /**
     * Download the target of a link on the current page.
     *
     * @param string $linktext Text of the link.
     * @return string The response body.
     */
    protected function download_from_link(string $linktext): string {
        [$body] = $this->fetch_from_link($linktext);

        return $body;
    }

    /**
     * Download the target of a link and keep the response headers.
     *
     * The headers are half of what an attachment assertion is about: the same
     * bytes are safe or dangerous depending on the disposition they are served
     * with, and that is only visible here, not in the rendered page.
     *
     * @param string $linktext Text of the link.
     * @return array [response body, response headers]
     */
    protected function fetch_from_link(string $linktext): array {
        $link = $this->find_link($linktext);
        $url  = $link->getAttribute('href');

        if (!$url) {
            throw new ExpectationException('The link "' . $linktext . '" has no href.', $this->getSession());
        }

        [$body, $httpcode, $headers] = $this->fetch_with_session($url);

        if ($httpcode !== 200) {
            throw new ExpectationException(
                'Downloading "' . $linktext . '" returned HTTP ' . $httpcode . '.',
                $this->getSession()
            );
        }

        return [$body, $headers];
    }

    /**
     * Fetch a URL as the logged-in user, without navigating the browser to it.
     *
     * Returns the status code alongside the body because half of what these
     * steps assert is a refusal, and a refusal is a status code as much as it
     * is a page.
     *
     * @param string $url Absolute URL.
     * @return array [body, http status code, response headers keyed by name]
     */
    protected function fetch_with_session(string $url): array {
        $session = $this->getSession()->getCookie('MoodleSession');

        $curl = new \curl();
        $curl->setHeader('Cookie: MoodleSession=' . $session);
        $body = $curl->get($url);
        $info = $curl->get_info();

        if ($body === false) {
            throw new ExpectationException(
                'Nothing came back from ' . $url . ': ' . $curl->error,
                $this->getSession()
            );
        }

        return [(string) $body, (int) ($info['http_code'] ?? 0), (array) $curl->getResponse()];
    }

    /**
     * Read one response header, whatever case the server sent its name in.
     *
     * @param array  $headers Response headers as curl collected them.
     * @param string $name    Header name to read.
     * @return string The value, or '' when the header is absent.
     */
    protected function response_header(array $headers, string $name): string {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return is_array($value) ? (string) end($value) : (string) $value;
            }
        }

        return '';
    }

    /**
     * Assemble an RFC 5322 message from the fields a scenario supplied.
     *
     * Single-part when there is no attachment, multipart/mixed when there is.
     * The date is fixed rather than time(): nothing downstream reads it, and a
     * moving value in a fixture is a flake waiting to happen.
     *
     * @param array $fields Lowercased table keys.
     * @return string The raw message.
     */
    protected function build_raw_email(array $fields): string {
        $from      = $fields['from'];
        $to        = $fields['to'] ?? 'support@example.com';
        $subject   = $fields['subject'] ?? 'Behat message';
        $body      = $fields['body'] ?? 'Sent by the mooDesk smoke suite.';
        $messageid = $fields['messageid'] ?? '<' . md5($from . $subject) . '@behat.example.com>';

        $headers = [];

        // Prepended, above everything the sender wrote, because that is where a
        // receiving MTA puts its verdict and the trust boundary reads position
        // as well as identity. A scenario that appended this at the bottom
        // would be testing an arrangement no provider produces.
        if (!empty($fields['authresults'])) {
            $headers[] = 'Authentication-Results: ' . $fields['authresults'];
        }

        $headers = array_merge($headers, [
            'From: ' . $from,
            'To: ' . $to,
            'Subject: ' . $subject,
            'Message-ID: ' . $messageid,
            'Date: Wed, 19 Aug 2026 09:00:00 +0000',
            'MIME-Version: 1.0',
        ]);

        if (!empty($fields['inreplyto'])) {
            $headers[] = 'In-Reply-To: ' . $fields['inreplyto'];
            $headers[] = 'References: ' . $fields['inreplyto'];
        }

        if (empty($fields['attachment'])) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';

            return implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n";
        }

        $boundary   = 'moodesk-behat-boundary';
        $filename   = $fields['attachment'];
        $filebody   = $fields['attachmentcontent'] ?? 'attachment body';
        $filetype   = $fields['attachmenttype'] ?? 'text/plain';
        $headers[]  = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

        $parts = [
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            '',
            $body,
            '--' . $boundary,
            'Content-Type: ' . $filetype . '; name="' . $filename . '"',
            'Content-Transfer-Encoding: base64',
            'Content-Disposition: attachment; filename="' . $filename . '"',
            '',
            chunk_split(base64_encode($filebody), 76, "\r\n"),
            '--' . $boundary . '--',
            '',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts);
    }

    /**
     * Tick the bulk checkbox on the row for one ticket.
     *
     * Rows carry the ticket id, which a feature file cannot know; the subject is
     * what the scenario wrote and what a reader recognises.
     *
     * @Given /^I select the mooDesk ticket "(?P<subject_string>(?:[^"]|\\")*)"$/
     * @param string $subject The exact ticket subject.
     */
    public function i_select_the_moodesk_ticket(string $subject): void {
        $this->execute('behat_general::i_click_on', [
            $this->bulk_checkbox_xpath($subject),
            'xpath_element',
        ]);
    }

    /**
     * Assert whether a ticket's row wears the bulk selection highlight.
     *
     * @Then /^the mooDesk ticket "(?P<subject_string>(?:[^"]|\\")*)" should (?P<not_string>not )?be highlighted as selected$/
     * @param string $subject The exact ticket subject.
     * @param string $not     "not " to assert the row is plain.
     */
    public function the_moodesk_ticket_should_be_highlighted(string $subject, string $not = ''): void {
        $row = $this->find('xpath', $this->ticket_row_xpath($subject));
        $selected = in_array('mhd-bulk-selected', explode(' ', (string) $row->getAttribute('class')), true);
        if ($selected === ($not !== '')) {
            throw new ExpectationException(
                'The row for "' . $subject . '" is ' . ($selected ? '' : 'not ') . 'highlighted as selected.',
                $this->getSession()
            );
        }
    }

    /**
     * Keep the next bulk POST from leaving the page.
     *
     * Apply's busy state lasts exactly as long as the request, and on a fast
     * site that is less than a step: by the time Behat looks, the result page
     * has replaced it. Holding the submit leaves the page as it is at the
     * moment the batch would have gone, which is the moment worth asserting.
     * Test-only; it lasts until the page is replaced.
     *
     * @Given /^the next mooDesk bulk action is held before it is sent$/
     */
    public function the_next_moodesk_bulk_action_is_held(): void {
        $this->execute_script('HTMLFormElement.prototype.submit = function() {};');
    }

    /**
     * XPath for one ticket's row, addressed by its subject.
     *
     * The class test matches a whole token, not a substring. contains(@class,
     * 'mhd-ticket-row') also matches the list container, which is
     * class="mhd-ticket-rows" — and the container holds every subject on the
     * page, so the subject predicate is true for it whichever ticket is asked
     * for. The step then clicked the first checkbox under the container, which
     * is row one's, every time: selecting a second ticket unticked the first,
     * and the one scenario that appeared to pass did so for the wrong reason.
     * Nothing caught it because nothing ran these scenarios until now.
     *
     * @param string $subject The exact ticket subject.
     * @return string
     */
    protected function ticket_row_xpath(string $subject): string {
        $literal = behat_context_helper::escape($subject);
        return "//div[contains(concat(' ', normalize-space(@class), ' '), ' mhd-ticket-row ')]"
            . "[.//span[contains(concat(' ', normalize-space(@class), ' '), ' mhd-ticket-subject ')]"
            . "[normalize-space(.)=" . $literal . "]]";
    }

    /**
     * XPath for the bulk checkbox belonging to one ticket row.
     *
     * @param string $subject The exact ticket subject.
     * @return string
     */
    protected function bulk_checkbox_xpath(string $subject): string {
        return $this->ticket_row_xpath($subject) . "//input[contains(@class,'mhd-bulk-check')]";
    }

    /**
     * Choose what the bulk toolbar will do.
     *
     * @When /^I choose the mooDesk bulk action "(?P<action_string>(?:[^"]|\\")*)"$/
     * @param string $action The option to select, e.g. "Change status".
     */
    public function i_choose_the_moodesk_bulk_action(string $action): void {
        $this->set_bulk_control('mhd-bulk-action-select', $action);
    }

    /**
     * Choose the status a bulk status change will apply.
     *
     * @When /^I set the mooDesk bulk status to "(?P<status_string>(?:[^"]|\\")*)"$/
     * @param string $status The option to select, e.g. "Resolved".
     */
    public function i_set_the_moodesk_bulk_status(string $status): void {
        $this->set_bulk_control('mhd-bulk-newstatus', $status);
    }

    /**
     * Choose the agent a bulk assignment will apply.
     *
     * @When /^I set the mooDesk bulk assignee to "(?P<assignee_string>(?:[^"]|\\")*)"$/
     * @param string $assignee The option to select, e.g. "Bruno Bianchi".
     */
    public function i_set_the_moodesk_bulk_assignee(string $assignee): void {
        $this->set_bulk_control('mhd-bulk-assigneeid', $assignee);
    }

    /**
     * Set one of the bulk toolbar's selects, addressed by the id it publishes.
     *
     * See note 5 on the class: the toolbar's controls are reachable by id and
     * by nothing else the field selector reads.
     *
     * @param string $id The control's DOM id.
     * @param string $value The option to select, by visible text or by value.
     */
    protected function set_bulk_control(string $id, string $value): void {
        $this->execute('behat_forms::i_set_the_field_to', [$id, $value]);
    }

    /**
     * Assert the status shown on one ticket's row.
     *
     * Scoped to the row, so it reads what the server wrote and not what the
     * toolbar happens to be offering: "Resolved" is an option in the bulk
     * status dropdown as much as it is a state a ticket can be in.
     *
     * @Then /^the mooDesk ticket "(?P<subject_string>(?:[^"]|\\")*)" should show the status "(?P<status_string>(?:[^"]|\\")*)"$/
     * @param string $subject The exact ticket subject.
     * @param string $status The status label expected on the row, e.g. "Resolved".
     */
    public function the_moodesk_ticket_should_show_the_status(string $subject, string $status): void {
        $this->execute('behat_general::assert_element_contains_text', [
            $status,
            $this->ticket_row_xpath($subject) . "//span[contains(@class,'mhd-pill--status-')]",
            'xpath_element',
        ]);
    }

    /**
     * Assert how many history rows of one action a ticket has.
     *
     * @Then /^mooDesk should log (?P<count_number>\d+) "(?P<action_string>[a-z_]+)" on "(?P<subject_string>[^"]+)"$/
     * @param int $count Expected number of rows.
     * @param string $action The history action, e.g. "merge_reverted".
     * @param string $subject The exact ticket subject.
     */
    public function moodesk_should_log_history_rows(int $count, string $action, string $subject): void {
        global $DB;

        $id = $DB->get_field('local_moodesk_ticket', 'id', ['subject' => $subject], MUST_EXIST);
        $actual = $DB->count_records('local_moodesk_ticket_history', ['ticketid' => $id, 'action' => $action]);

        if ($actual !== $count) {
            throw new ExpectationException(
                'Ticket "' . $subject . '" has ' . $actual . ' "' . $action . '" history rows, expected ' . $count . '.',
                $this->getSession()
            );
        }
    }

    /**
     * Assert who one ticket's row says it is assigned to.
     *
     * @Then /^the mooDesk ticket "(?P<subject_string>(?:[^"]|\\")*)" should be assigned to "(?P<assignee_string>(?:[^"]|\\")*)"$/
     * @param string $subject The exact ticket subject.
     * @param string $assignee The agent's full name.
     */
    public function the_moodesk_ticket_should_be_assigned_to(string $subject, string $assignee): void {
        $this->execute('behat_general::assert_element_contains_text', [
            $assignee,
            $this->ticket_row_xpath($subject) . "//span[contains(@class,'mhd-meta-assignee')]",
            'xpath_element',
        ]);
    }

    /**
     * Assert one ticket's row carries no assignee.
     *
     * The row marks this with a class rather than a word, which is what this
     * reads: an unassigned ticket a bulk action did not touch has to stay
     * unassigned, and that is the assertion, not the wording of the label.
     *
     * @Then /^the mooDesk ticket "(?P<subject_string>(?:[^"]|\\")*)" should be unassigned$/
     * @param string $subject The exact ticket subject.
     */
    public function the_moodesk_ticket_should_be_unassigned(string $subject): void {
        $this->execute('behat_general::should_exist', [
            $this->ticket_row_xpath($subject) . "//span[contains(@class,'mhd-assignee-unassigned')]",
            'xpath_element',
        ]);
    }

    /**
     * Open one ticket's detail drawer from its row on the list.
     *
     * The row's overlay link is what a person clicks, and clicking it is what
     * the drawer intercepts — going straight to view.php would test the page
     * instead, which is a different screen with a different refresh story.
     *
     * @When /^I open the mooDesk ticket "(?P<subject_string>(?:[^"]|\\")*)"$/
     * @param string $subject The exact ticket subject.
     */
    public function i_open_the_moodesk_ticket(string $subject): void {
        $this->execute('behat_general::i_click_on', [
            $this->ticket_row_xpath($subject) . "//a[@data-action='mhd-open-detail']",
            'xpath_element',
        ]);
        $this->execute('behat_general::wait_until_the_page_is_ready');
    }

    /**
     * Open one ticket on its canonical page, bypassing the drawer.
     *
     * The row's link is a real href to view.php, and this follows it rather
     * than clicking it: a plain click is what the drawer intercepts, so
     * clicking would land on the panel and never reach the page. The page is
     * the other mount of the same detail component, and some contracts — the
     * arrangement of the rail, above all — have to hold on both.
     *
     * @When /^I open the mooDesk ticket "(?P<subject_string>(?:[^"]|\\")*)" full page$/
     * @param string $subject The exact ticket subject.
     */
    public function i_open_the_moodesk_ticket_full_page(string $subject): void {
        $link = $this->find(
            'xpath',
            $this->ticket_row_xpath($subject) . "//a[@data-action='mhd-open-detail']"
        );

        $href = $link->getAttribute('href');
        if (empty($href)) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'The row for "' . $subject . '" carries no link to the ticket page.',
                $this->getSession()
            );
        }

        $this->execute('behat_general::i_visit', [$href]);
        $this->execute('behat_general::wait_until_the_page_is_ready');
    }

    /**
     * Assert whether a ticket has a row in the results list.
     *
     * Scoped to the results region rather than the page, and that scope is the
     * whole point: the detail drawer shows the subject too, so an unscoped
     * "should not see" would fail on a ticket that correctly left the list while
     * its panel is still open — which is exactly the case worth testing.
     *
     * @Then /^the mooDesk ticket "(?P<subject_string>(?:[^"]|\\")*)" should( not)? be listed$/
     * @param string $subject The exact ticket subject.
     * @param string $not Empty when the ticket is expected to be listed.
     */
    public function the_moodesk_ticket_should_be_listed(string $subject, string $not = ''): void {
        $xpath = "//div[@data-region='mhd-ticket-list-results']" . $this->ticket_row_xpath($subject);

        if ($not === '') {
            $this->execute('behat_general::should_exist', [$xpath, 'xpath_element']);
            return;
        }

        // The waiting variant, not should_not_exist. The latter looks the
        // element up with a reduced timeout and returns the moment it finds one,
        // so it reports "still there" instantly rather than waiting for it to
        // go — and what this step is usually asserting is the *result of a
        // refresh*: the save posts, the list re-queries, and only then does the
        // row leave. Asserting that without waiting tests the race, not the
        // behaviour, and fails on a product that works.
        $this->execute('behat_general::wait_until_does_not_exists', [$xpath, 'xpath_element']);
    }

    /**
     * Assert the priority shown on one ticket's row.
     *
     * @Then /^the mooDesk ticket "(?P<subject_string>(?:[^"]|\\")*)" should show the priority "(?P<label_string>(?:[^"]|\\")*)"$/
     * @param string $subject The exact ticket subject.
     * @param string $priority The priority label expected on the row, e.g. "High".
     */
    public function the_moodesk_ticket_should_show_the_priority(string $subject, string $priority): void {
        $this->execute('behat_general::assert_element_contains_text', [
            $priority,
            $this->ticket_row_xpath($subject) . "//span[contains(@class,'mhd-pill--priority-')]",
            'xpath_element',
        ]);
    }

    /**
     * Assert the select-all checkbox's indeterminate state.
     *
     * Indeterminate is a property, never an attribute: it cannot be read from
     * the markup, only from the live DOM, so this asks the browser directly.
     *
     * @Then /^the mooDesk select-all checkbox should( not)? be indeterminate$/
     * @param string $not Empty when the checkbox is expected to be indeterminate.
     * @throws ExpectationException
     */
    public function the_select_all_checkbox_should_be_indeterminate(string $not = ''): void {
        $expected = ($not === '');
        $actual   = (bool) $this->getSession()->evaluateScript(
            "(function() {
                var box = document.getElementById('mhd-select-all');
                return box ? box.indeterminate : false;
            })()"
        );

        if ($actual !== $expected) {
            throw new ExpectationException(
                'The select-all checkbox is ' . ($actual ? '' : 'not ') . 'indeterminate, expected the opposite.',
                $this->getSession()
            );
        }
    }

    /**
     * Read whether the document is currently locked against scrolling.
     *
     * It asks for the used overflow rather than trying to scroll, because
     * `overflow: hidden` is not what the name suggests: it takes the scrollbar
     * away and refuses the wheel, the touch drag and the keyboard, but the box
     * stays scrollable from script. `window.scrollBy` moves a locked page just
     * as far as an unlocked one, so a scroll probe cannot tell them apart.
     *
     * What can be observed is the mechanism itself, and it is two facts, not
     * one. A viewport takes its overflow from <html>; only when <html> is
     * `visible` does <body>'s overflow propagate up and take the viewport with
     * it. Assert just the body and a theme rule putting an overflow on <html>
     * would break the lock with every test still green.
     *
     * `room` is how far the document could scroll if it were free, and it is
     * what keeps either assertion from passing on a page that simply fits.
     *
     * @return array{body: string, root: string, room: int}
     */
    protected function probe_scroll_lock(): array {
        $result = $this->evaluate_script(
            "(function() {
                var el = document.scrollingElement || document.documentElement;
                return {
                    body: getComputedStyle(document.body).overflowY,
                    root: getComputedStyle(document.documentElement).overflowY,
                    room: el.scrollHeight - el.clientHeight
                };
            })()"
        );

        return [
            'body' => (string) $result['body'],
            'root' => (string) $result['root'],
            'room' => (int) $result['room'],
        ];
    }

    /**
     * Fail unless the page is long enough for scrolling to be a question.
     *
     * @param array $probe Result of probe_scroll_lock().
     * @throws ExpectationException
     */
    protected function require_scrollable_page(array $probe): void {
        if ($probe['room'] > 0) {
            return;
        }

        throw new ExpectationException(
            'The page fits on screen at this size, so nothing it does about scrolling means anything. ' .
                'Give the scenario more content or a shorter window.',
            $this->getSession()
        );
    }

    /**
     * Assert the document behind the drawer cannot be scrolled by the user.
     *
     * @Then /^the mooDesk page should be held still$/
     * @throws ExpectationException
     */
    public function the_moodesk_page_should_be_held_still(): void {
        $probe = $this->probe_scroll_lock();
        $this->require_scrollable_page($probe);

        if ($probe['body'] !== 'hidden') {
            throw new ExpectationException(
                'The document should be locked, but body\'s used overflow-y is "' . $probe['body'] . '".',
                $this->getSession()
            );
        }

        if ($probe['root'] !== 'visible') {
            throw new ExpectationException(
                'html\'s used overflow-y is "' . $probe['root'] . '", so the viewport takes its overflow from ' .
                    'html and body\'s lock never reaches it. The page still scrolls.',
                $this->getSession()
            );
        }
    }

    /**
     * Assert the document scrolls the way it normally does.
     *
     * @Then /^the mooDesk page should scroll freely$/
     * @throws ExpectationException
     */
    public function the_moodesk_page_should_scroll_freely(): void {
        $probe = $this->probe_scroll_lock();
        $this->require_scrollable_page($probe);

        if ($probe['body'] === 'hidden' || $probe['root'] === 'hidden') {
            throw new ExpectationException(
                'The document is still locked: body\'s used overflow-y is "' . $probe['body'] .
                    '" and html\'s is "' . $probe['root'] . '".',
                $this->getSession()
            );
        }
    }

    /**
     * Assert how many elements match a selector in the whole document.
     *
     * Counting, rather than asserting presence, is the point: what this catches
     * is a second copy of something that should be unique. The drawer replaces
     * its content ticket after ticket, and a detail left behind in the document
     * would keep its listeners and answer to the same selectors as the live one
     * — invisible on screen, and indistinguishable from correct behaviour to
     * every assertion that only asks whether an element exists.
     *
     * @Then /^the mooDesk document should hold (?P<count_number>\d+) "(?P<selector_string>(?:[^"]|\\")*)" element(?:s)?$/
     * @param int    $expected How many should match.
     * @param string $selector A CSS selector.
     * @throws ExpectationException
     */
    public function the_moodesk_document_should_hold(int $expected, string $selector): void {
        $actual = (int) $this->evaluate_script(
            "document.querySelectorAll('" . addslashes($selector) . "').length"
        );

        if ($actual !== $expected) {
            throw new ExpectationException(
                'Expected ' . $expected . ' element(s) matching "' . $selector .
                    '", found ' . $actual . '.',
                $this->getSession()
            );
        }
    }

    /**
     * Assert nothing on the screen is wider than the screen.
     *
     * A layout question a browser has to answer, and one no assertion about a
     * class or a style can stand in for: what makes a page scroll sideways is
     * usually a single control whose intrinsic width beats its container —
     * a select with a long option, a value that will not wrap — and it only
     * shows at the widths where the container is narrow enough to lose.
     *
     * The named element is checked as well as the document, because in the
     * drawer the panel is its own viewport: a card too wide for the rail makes
     * the rail scroll, not the page, and the document would report nothing.
     *
     * One pixel of tolerance, because a fractional layout width rounds up and
     * `scrollWidth` is an integer.
     *
     * @Then /^the mooDesk element "(?P<selector_string>(?:[^"]|\\")*)" should not scroll sideways$/
     * @param string $selector A CSS selector for the region under test.
     * @throws ExpectationException
     */
    public function the_moodesk_element_should_not_scroll_sideways(string $selector): void {
        $result = $this->evaluate_script(
            "(function() {
                var out = {doc: document.documentElement.scrollWidth - document.documentElement.clientWidth};
                var el = document.querySelector('" . addslashes($selector) . "');
                out.found = !!el;
                if (el) {
                    out.el = el.scrollWidth - el.clientWidth;
                    out.width = el.clientWidth;
                }
                return out;
            })()"
        );

        if (empty($result['found'])) {
            throw new ExpectationException(
                'No element matches "' . $selector . '", so nothing was measured.',
                $this->getSession()
            );
        }

        if ((int) $result['doc'] > 1) {
            throw new ExpectationException(
                'The document scrolls sideways by ' . $result['doc'] . 'px.',
                $this->getSession()
            );
        }

        if ((int) $result['el'] > 1) {
            throw new ExpectationException(
                '"' . $selector . '" is ' . $result['el'] . 'px wider than the ' .
                    $result['width'] . 'px it has to fit in.',
                $this->getSession()
            );
        }
    }

    /**
     * Give the page a viewport exactly this wide, as a phone or tablet would.
     *
     * `I change window size` cannot reach a phone: the CI's browser enforces a
     * minimum window width of about 500px and widens anything narrower, so a
     * scenario asking for 360 silently measures 500 and passes on the wrong
     * layout. Chrome's own device emulation sets the viewport itself, at any
     * width, with the mobile flag a real phone reports. It holds for the rest of
     * the scenario, across navigation, and the next scenario starts a new
     * browser session without it. Reload after it when a script reads the width
     * on load.
     *
     * @Given /^I emulate a mooDesk viewport (?P<width_number>\d+) pixels wide$/
     * @param int $width CSS pixels.
     */
    public function i_emulate_a_moodesk_viewport(int $width): void {
        $this->getSession()->getDriver()->getWebDriver()->executeCustomCommand(
            '/session/:sessionId/goog/cdp/execute',
            'POST',
            [
                'cmd' => 'Emulation.setDeviceMetricsOverride',
                'params' => ['width' => $width, 'height' => 800, 'deviceScaleFactor' => 1, 'mobile' => true],
            ]
        );
        $actual = (int) $this->evaluate_script('document.documentElement.clientWidth');
        // A scrollbar may take its width out of the layout viewport; more than
        // that means the emulation did not take.
        if (abs($actual - $width) > 20) {
            throw new ExpectationException(
                "Asked for a {$width}px viewport and the page reports {$actual}px.",
                $this->getSession()
            );
        }
    }

    /**
     * Whether the ticket list renders its rows as cards or as table-like rows.
     *
     * Read from the computed style of every row, not from the window width: the
     * point is the layout the stylesheet produced. Below desktop a row is a
     * flex card with a border all round; on desktop it is a grid row.
     *
     * @Then /^the mooDesk ticket rows should be laid out as (?P<layout_string>cards|rows)$/
     * @param string $layout 'cards' or 'rows'.
     */
    public function the_moodesk_ticket_rows_should_be_laid_out_as(string $layout): void {
        $rows = $this->evaluate_script(
            "Array.prototype.map.call(document.querySelectorAll('.mhd-ticket-row'), function(row) {
                var s = getComputedStyle(row);
                return s.display === 'flex' && parseFloat(s.borderTopWidth) > 0 && parseFloat(s.borderTopLeftRadius) > 0
                    ? 'cards' : (s.display === 'grid' ? 'rows' : s.display);
            })"
        );
        if (!$rows) {
            throw new ExpectationException('No ticket rows on the page.', $this->getSession());
        }
        $other = array_values(array_diff(array_unique($rows), [$layout]));
        if ($other) {
            throw new ExpectationException(
                'Expected every row laid out as ' . $layout . '; found ' . implode(', ', $other) . '.',
                $this->getSession()
            );
        }
    }

    /**
     * Wait until the New ticket drawer has finished sliding in.
     *
     * The panel slides in with a 0.18s transform transition that Moodle's
     * pending-JS tracking does not know about, so Behat can click inside it while
     * it is still moving. The click goes to where the target was when its
     * position was read and misses it: a section toggle clicked that way never
     * toggles. This waits until the panel is open, runs no animation and sits at
     * its final position.
     *
     * @Given /^the mooDesk new ticket drawer has finished opening$/
     * @throws ExpectationException
     */
    public function the_moodesk_new_ticket_drawer_has_finished_opening(): void {
        $this->spin(
            function ($context) {
                $result = $context->evaluate_script(
                    "(function() {
                        var el = document.querySelector('.mhd-ticket-drawer-mount.is-open .mhd-ticket-drawer');
                        if (!el) { return {open: false}; }
                        var moving = el.getAnimations ? el.getAnimations().length > 0 : false;
                        var t = getComputedStyle(el).transform;
                        return {open: true, moving: moving,
                                settled: t === 'none' || t === 'matrix(1, 0, 0, 1, 0, 0)'};
                    })()"
                );
                if (empty($result['open'])) {
                    throw new ExpectationException('The New ticket drawer is not open.', $context->getSession());
                }
                if (!empty($result['moving']) || empty($result['settled'])) {
                    throw new ExpectationException('The New ticket drawer is still sliding in.', $context->getSession());
                }
                return true;
            },
            [],
            self::get_extended_timeout()
        );
    }

    /**
     * The collapsible section inside the New ticket drawer is really hidden, or
     * really showing — by computed style, not by the state Bootstrap says it is
     * in.
     *
     * Those two came apart. Bootstrap collapses a section with
     * `.collapse:not(.show) { display: none }`, at (0,2,0); the drawer laid the
     * same element out with `.moodesk-app .mhd-ticket-drawer .fcontainer
     * { display: grid }`, at (0,3,0). Three classes beat two whatever the
     * source order — and the plugin's stylesheet is concatenated BEFORE the
     * theme's, so order was never on the drawer's side either. The toggle
     * flipped `aria-expanded`, the chevron turned, `show` came and went, and
     * the fields stayed exactly where they were.
     *
     * Hence a step that reads `display`. "should not be visible" on a field
     * would do for the hidden half, but the showing half has to say that the
     * layout came back, not merely that something is on screen: a section that
     * re-opens as a plain block has lost the drawer's grid.
     *
     * The wait is for Bootstrap's 350ms height transition, which Moodle's
     * pending-JS tracking does not know about.
     *
     * @Then /^the mooDesk drawer section should be (?P<state_string>hidden|showing)$/
     * @param string $state "hidden" or "showing".
     * @throws ExpectationException
     */
    public function the_moodesk_drawer_section_should_be(string $state): void {
        $expected = $state === 'hidden' ? 'none' : 'grid';

        $this->spin(
            function ($context) use ($expected, $state) {
                $result = $context->evaluate_script(
                    "(function() {
                        var el = document.querySelector('.mhd-ticket-drawer .fcontainer');
                        if (!el) { return {found: false}; }
                        return {found: true, display: getComputedStyle(el).display,
                                height: el.offsetHeight, collapsing: el.classList.contains('collapsing')};
                    })()"
                );

                if (empty($result['found'])) {
                    throw new ExpectationException(
                        'The drawer has no collapsible section to measure.',
                        $context->getSession()
                    );
                }

                // Still animating: not a verdict yet, let spin() come back.
                if (!empty($result['collapsing'])) {
                    return false;
                }

                if ($result['display'] !== $expected) {
                    throw new ExpectationException(
                        'The drawer section should be ' . $state . ' (display: ' . $expected . '), ' .
                            'but its computed display is "' . $result['display'] . '".',
                        $context->getSession()
                    );
                }

                if ($state === 'showing' && (int) $result['height'] === 0) {
                    throw new ExpectationException(
                        'The drawer section is display: grid but has no height.',
                        $context->getSession()
                    );
                }

                return true;
            },
            [],
            self::get_extended_timeout(),
            null,
            true
        );
    }

    /**
     * Scroll one element inside the page, and report whether it moved.
     *
     * The navigation band inside the drawer has to keep scrolling while the
     * document behind it is held still, and that is a different element with a
     * different overflow — nothing about the body's lock predicts it.
     *
     * @Then /^the mooDesk element "(?P<selector_string>(?:[^"]|\\")*)" should scroll internally$/
     * @param string $selector A CSS selector for the scrolling element.
     * @throws ExpectationException
     */
    public function the_moodesk_element_should_scroll_internally(string $selector): void {
        $result = $this->evaluate_script(
            "(function() {
                var el = document.querySelector('" . addslashes($selector) . "');
                if (!el) {
                    return null;
                }
                var before = el.scrollTop;
                el.scrollTop = before + 200;
                var after = el.scrollTop;
                el.scrollTop = before;
                return {before: before, after: after, room: el.scrollHeight - el.clientHeight};
            })()"
        );

        if ($result === null) {
            throw new ExpectationException(
                'No element matches "' . $selector . '".',
                $this->getSession()
            );
        }

        if ((int) $result['room'] <= 0) {
            throw new ExpectationException(
                'The element "' . $selector . '" has nothing to scroll, so it cannot show that it scrolls. ' .
                    'The scenario needs more navigation items or a shorter window.',
                $this->getSession()
            );
        }

        if ((int) $result['after'] === (int) $result['before']) {
            throw new ExpectationException(
                'The element "' . $selector . '" did not scroll, and it has ' . $result['room'] . 'px of room.',
                $this->getSession()
            );
        }
    }

    /**
     * Assert whether the focus is anywhere inside the app shell's sidebar.
     *
     * The point of the closed drawer's `inert` is that Tab does not go in, and
     * "does not go in" is a statement about the whole region: which control the
     * focus would otherwise have landed on depends on the user's capabilities
     * and the edition, so naming one would be asserting the wrong thing.
     *
     * @Then /^the focus should (?P<state>be inside|not be inside) the mooDesk sidebar$/
     * @param string $state Either "be inside" or "not be inside".
     * @throws ExpectationException
     */
    public function the_focus_should_be_inside_the_moodesk_sidebar(string $state): void {
        $expected = ($state === 'be inside');

        $result = $this->evaluate_script(
            "(function() {
                var side = document.getElementById('moodesk-app-sidebar');
                var el = document.activeElement;
                if (!side) {
                    return null;
                }
                return {
                    inside: side.contains(el),
                    where: el ? (el.tagName.toLowerCase() +
                        (el.className ? '.' + String(el.className).split(' ')[0] : '')) : 'nothing'
                };
            })()"
        );

        if ($result === null) {
            throw new ExpectationException(
                'There is no app-shell sidebar on this page.',
                $this->getSession()
            );
        }

        if ((bool) $result['inside'] !== $expected) {
            throw new ExpectationException(
                'The focus is on ' . $result['where'] . ', which is ' .
                    ($result['inside'] ? 'inside' : 'outside') . ' the sidebar; expected the opposite.',
                $this->getSession()
            );
        }
    }

    /**
     * Measure the thread's ends against the box that clips them.
     *
     * Returns the outermost rectangle the conversation is visible through — its
     * own scrollport in the drawer's two-column layout, the shared one once the
     * columns collapse, the window on the canonical page — together with the
     * first and last message in the thread. Which element scrolls is worked out
     * here from the computed overflow rather than taken from the plugin, so a
     * change of mind in the stylesheet cannot make these assertions agree with
     * a broken screen.
     *
     * @return array|null Null when there is no conversation on screen.
     */
    protected function measure_conversation(): ?array {
        return $this->evaluate_script(
            "(function() {
                var detail = document.querySelector('[data-region=\"mhd-detail\"]');
                var entries = detail ? detail.querySelectorAll('.mhd-thread-entry') : [];
                if (!detail || !entries.length) {
                    return null;
                }

                var port = null;
                for (var el = detail.querySelector('.mhd-detail__main'); el; el = el.parentElement) {
                    var overflow = window.getComputedStyle(el).overflowY;
                    if (overflow === 'auto' || overflow === 'scroll') {
                        port = el;
                        break;
                    }
                    if (el === detail) {
                        break;
                    }
                }

                var box = port
                    ? port.getBoundingClientRect()
                    : {top: 0, bottom: window.innerHeight};
                var rect = function(el) {
                    var r = el.getBoundingClientRect();
                    return {top: Math.round(r.top), bottom: Math.round(r.bottom),
                            text: (el.textContent || '').replace(/\\s+/g, ' ').trim().slice(0, 60)};
                };

                return {
                    port: {top: Math.round(box.top), bottom: Math.round(box.bottom),
                           scrolled: port ? !!port.scrollTop : window.scrollY > 0},
                    first: rect(entries[0]),
                    last: rect(entries[entries.length - 1]),
                    count: entries.length
                };
            })()"
        );
    }

    /**
     * Assert that the newest message in the thread is on screen.
     *
     * A ticket is opened to read what has just happened on it, and the thread
     * runs oldest first — so the newest message is the one at the bottom, and a
     * detail that opened at the top showed the agent the one message they were
     * least likely to want.
     *
     * @Then /^the mooDesk conversation should be showing its latest message$/
     * @throws ExpectationException
     */
    public function the_conversation_should_be_showing_its_latest_message(): void {
        $seen = $this->measure_conversation();

        if ($seen === null) {
            throw new ExpectationException(
                'There is no conversation on screen to measure.',
                $this->getSession()
            );
        }

        // A few pixels of tolerance: sub-pixel layout and a scrollbar's own
        // rounding move the last row by less than a line either way.
        $last = $seen['last'];
        $port = $seen['port'];

        if ((int) $last['bottom'] > (int) $port['bottom'] + 4 || (int) $last['top'] >= (int) $port['bottom']) {
            throw new ExpectationException(
                'The latest message ("' . $last['text'] . '") ends at ' . $last['bottom'] .
                    'px, below the ' . $port['bottom'] . 'px bottom of the conversation. ' .
                    'The thread was left showing its top, not its end.',
                $this->getSession()
            );
        }
    }

    /**
     * Assert that the thread has actually been scrolled off its first message.
     *
     * The companion to the step above, and the one that makes it mean
     * something: a thread short enough to fit shows its latest message without
     * anyone doing anything about it.
     *
     * @Then /^the mooDesk conversation should have left its oldest message behind$/
     * @throws ExpectationException
     */
    public function the_conversation_should_have_left_its_oldest_message_behind(): void {
        $seen = $this->measure_conversation();

        if ($seen === null) {
            throw new ExpectationException(
                'There is no conversation on screen to measure.',
                $this->getSession()
            );
        }

        if ((int) $seen['first']['bottom'] > (int) $seen['port']['top']) {
            throw new ExpectationException(
                'The oldest message ("' . $seen['first']['text'] . '") is still on screen, so the ' .
                    'conversation was never scrolled. The scenario needs a longer thread, or a ' .
                    'shorter window, for the assertion above it to prove anything.',
                $this->getSession()
            );
        }
    }

    /**
     * Assert how many rows an element's children lay out on.
     *
     * A responsive layout is a matter of geometry, not visibility: four KPI
     * cards that were meant to be one row on a desktop and one column on a
     * phone are all "visible" either way. Rows are counted as distinct top
     * offsets of the element's children (to the pixel), which is what a
     * grid or a wrapping flex row actually produces; children with no box
     * (hidden inputs, display:none) are not on any row.
     *
     * @Then /^the mooDesk element "(?P<selector_string>(?:[^"]|\\")*)" should lay its children out on (?P<count_number>\d+) rows?$/
     * @param string $selector A CSS selector for the parent.
     * @param int    $count    The rows expected.
     * @throws ExpectationException
     */
    public function the_moodesk_element_should_lay_its_children_out_on_rows(string $selector, int $count): void {
        $result = $this->evaluate_script(
            "(function() {
                var el = document.querySelector('" . addslashes($selector) . "');
                if (!el) { return {found: false}; }
                var tops = {}, counted = 0;
                Array.prototype.forEach.call(el.children, function(c) {
                    var r = c.getBoundingClientRect();
                    // A hidden input or a display:none child has no box and no row.
                    if (r.width === 0 && r.height === 0) { return; }
                    counted++;
                    tops[Math.round(r.top)] = true;
                });
                return {found: true, rows: Object.keys(tops).length, children: counted};
            })()"
        );
        if (empty($result['found'])) {
            throw new ExpectationException('No element matches "' . $selector . '".', $this->getSession());
        }
        if ((int) $result['rows'] !== $count) {
            throw new ExpectationException(
                'The ' . $result['children'] . ' children of "' . $selector . '" lie on ' . $result['rows']
                    . ' row(s), not ' . $count . '.',
                $this->getSession()
            );
        }
    }

    /**
     * Leave a mark in the window that only a page load can remove.
     *
     * @Given /^I mark this mooDesk page$/
     */
    public function i_mark_this_moodesk_page(): void {
        $this->execute_script("window.mhdBehatPageMark = 'marked';");
    }

    /**
     * Assert that the window is still the one that was marked.
     *
     * @Then /^the mooDesk page should not have been reloaded$/
     * @throws ExpectationException
     */
    public function the_moodesk_page_should_not_have_been_reloaded(): void {
        if ($this->evaluate_script("window.mhdBehatPageMark || ''") !== 'marked') {
            throw new ExpectationException(
                'The mark left on the window is gone, so the browser navigated or reloaded ' .
                    'instead of staying on the page.',
                $this->getSession()
            );
        }
    }

    /**
     * Assert that the send button is still beside the message box, not under it.
     *
     * The composer is one row — attach, message, send — and it has to stay one
     * row at every width the product supports. Wrapping is not something
     * visibility can report: a send button that has dropped below the box is
     * perfectly visible, it is just no longer the layout that was designed.
     *
     * @Then /^the mooDesk composer should keep its send button beside the message box$/
     * @throws ExpectationException
     */
    public function the_composer_should_keep_its_send_button_beside_the_box(): void {
        $seen = $this->evaluate_script(
            "(function() {
                var composer = document.querySelector('#mhd-reply-editor');
                var box = composer ? composer.querySelector('[data-region=\"mhd-composer-input\"]') : null;
                var send = composer ? composer.querySelector('[data-region=\"mhd-composer-send\"]') : null;
                if (!box || !send) {
                    return null;
                }
                var b = box.getBoundingClientRect();
                var s = send.getBoundingClientRect();
                return {
                    boxleft: Math.round(b.left), boxright: Math.round(b.right),
                    boxtop: Math.round(b.top), boxbottom: Math.round(b.bottom),
                    sendleft: Math.round(s.left), sendright: Math.round(s.right),
                    sendtop: Math.round(s.top), sendbottom: Math.round(s.bottom),
                    sendwidth: Math.round(s.width), sendheight: Math.round(s.height)
                };
            })()"
        );

        if ($seen === null) {
            throw new ExpectationException(
                'The composer, its message box or its send button is not on screen.',
                $this->getSession()
            );
        }

        // Same row: the two overlap vertically.
        if ((int) $seen['sendtop'] >= (int) $seen['boxbottom'] || (int) $seen['sendbottom'] <= (int) $seen['boxtop']) {
            throw new ExpectationException(
                'The send button spans ' . $seen['sendtop'] . '-' . $seen['sendbottom'] .
                    'px and the message box spans ' . $seen['boxtop'] . '-' . $seen['boxbottom'] .
                    'px, so they share no line: the button has wrapped.',
                $this->getSession()
            );
        }

        // To the right of it, not on top of it.
        if ((int) $seen['sendleft'] < (int) $seen['boxright']) {
            throw new ExpectationException(
                'The send button starts at ' . $seen['sendleft'] . 'px, inside the message box, ' .
                    'which ends at ' . $seen['boxright'] . 'px. The two must sit side by side.',
                $this->getSession()
            );
        }

        // Big enough to hit.
        if ((int) $seen['sendwidth'] < 44 || (int) $seen['sendheight'] < 44) {
            throw new ExpectationException(
                'The send button is ' . $seen['sendwidth'] . 'x' . $seen['sendheight'] .
                    'px, under the 44x44px a touch target needs.',
                $this->getSession()
            );
        }
    }

    /**
     * Read the composer's geometry: the box, the button and the error under them.
     *
     * @return array|null Null when there is no composer on screen.
     */
    protected function measure_composer(): ?array {
        // Handed back as a JSON string rather than as an object. Returning a
        // nested object through executeScript is what made this measurement
        // fail — once the validation message existed to be measured, the driver
        // came back with an error carrying no message at all, which says
        // nothing about the page and sent the whole thing chasing its own tail.
        // A string crosses the wire as a string.
        $json = $this->evaluate_script(
            "(function() {
                var composer = document.querySelector('#mhd-reply-editor');
                var box = composer ? composer.querySelector('[data-region=\"mhd-composer-input\"]') : null;
                var send = composer ? composer.querySelector('[data-region=\"mhd-composer-send\"]') : null;
                if (!box || !send) {
                    return null;
                }
                var rect = function(el) {
                    if (!el || (!el.offsetParent && el.offsetWidth === 0 && el.offsetHeight === 0)) {
                        return null;
                    }
                    var r = el.getBoundingClientRect();
                    return {
                        top: Math.round(r.top), bottom: Math.round(r.bottom),
                        left: Math.round(r.left), right: Math.round(r.right),
                        width: Math.round(r.width), height: Math.round(r.height)
                    };
                };
                var error = composer.querySelector('.invalid-feedback, .form-control-feedback');
                return JSON.stringify({
                    box: rect(box),
                    send: rect(send),
                    error: rect(error),
                    errortext: error ? (error.textContent || '').trim() : ''
                });
            })()"
        );

        return $json === null ? null : json_decode($json, true);
    }

    /**
     * Assert that a one-line message box is exactly as tall as the send button.
     *
     * They are the two halves of one row, and a box even a few pixels off the
     * button beside it shows the seam at every width.
     *
     * @Then /^the mooDesk composer should size its message box to its send button$/
     * @throws ExpectationException
     */
    public function the_composer_should_size_its_box_to_its_send_button(): void {
        $seen = $this->measure_composer();

        if ($seen === null) {
            throw new ExpectationException('There is no composer on screen.', $this->getSession());
        }

        $box  = (int) $seen['box']['height'];
        $send = (int) $seen['send']['height'];

        // A pixel of tolerance, and no more: sub-pixel line-height rounds, a
        // whole line does not.
        if (abs($box - $send) > 1) {
            throw new ExpectationException(
                'A one-line message box is ' . $box . 'px tall beside a ' . $send .
                    'px send button. They are one row and have to match.',
                $this->getSession()
            );
        }

        if ($send < 44) {
            throw new ExpectationException(
                'The send button is ' . $send . 'px tall, under the 44px a touch target needs.',
                $this->getSession()
            );
        }
    }

    /** @var array|null How the send button sat against the box, last time it was noted. */
    protected ?array $sendoffset = null;

    /**
     * Note how the send button sits against the message box.
     *
     * Recorded as the offset between the two rather than as a position on
     * screen, because the composer is pinned to the bottom of the panel: when it
     * grows a row, the whole block moves up and every absolute coordinate in it
     * changes. What must not change is where the button sits relative to the box
     * it belongs to.
     *
     * @Given /^I note how the mooDesk send button sits against the message box$/
     * @throws ExpectationException
     */
    public function i_note_how_the_send_button_sits(): void {
        $seen = $this->measure_composer();

        if ($seen === null) {
            throw new ExpectationException('There is no composer on screen.', $this->getSession());
        }

        $this->sendoffset = $this->send_offset($seen);
    }

    /**
     * The send button's placement relative to the message box.
     *
     * @param array $seen A measure_composer() reading.
     * @return array
     */
    protected function send_offset(array $seen): array {
        return [
            'top'    => (int) $seen['send']['top'] - (int) $seen['box']['top'],
            'bottom' => (int) $seen['send']['bottom'] - (int) $seen['box']['bottom'],
            'left'   => (int) $seen['send']['left'] - (int) $seen['box']['right'],
            'height' => (int) $seen['send']['height'],
            'width'  => (int) $seen['send']['width'],
        ];
    }

    /**
     * Assert the send button still sits against the box exactly as it did.
     *
     * The case this exists for: moodleform renders a field and its validation
     * message inside one wrapper, so a refused reply made that wrapper taller
     * and took the button down with it. The button belongs beside the BOX, not
     * beside the box and whatever the server said about it — and that is a
     * statement about the gap between the two, which is why the whole composer
     * being pushed up the panel by the new row does not count as moving.
     *
     * @Then /^the mooDesk send button should still sit against the message box$/
     * @throws ExpectationException
     */
    public function the_send_button_should_still_sit_against_the_box(): void {
        if ($this->sendoffset === null) {
            throw new ExpectationException(
                'Nothing noted how the send button sat, so there is nothing to compare against.',
                $this->getSession()
            );
        }

        $seen = $this->measure_composer();
        if ($seen === null) {
            throw new ExpectationException('There is no composer on screen.', $this->getSession());
        }

        $now   = $this->send_offset($seen);
        $moved = [];

        foreach ($this->sendoffset as $edge => $was) {
            if ($now[$edge] !== $was) {
                $moved[] = $edge . ' ' . $was . 'px to ' . $now[$edge] . 'px';
            }
        }

        if ($moved !== []) {
            throw new ExpectationException(
                'The send button shifted against the message box: ' . implode(', ', $moved) .
                    '. The validation message must not be part of what positions it.',
                $this->getSession()
            );
        }
    }

    /**
     * Assert the validation message is under the box and clear of the button.
     *
     * @Then /^the mooDesk validation message should sit below the message box$/
     * @throws ExpectationException
     */
    public function the_validation_message_should_sit_below_the_box(): void {
        $seen = $this->measure_composer();

        if ($seen === null) {
            throw new ExpectationException('There is no composer on screen.', $this->getSession());
        }

        if (empty($seen['error']) || $seen['errortext'] === '') {
            throw new ExpectationException(
                'No validation message is showing, so there is nothing to place.',
                $this->getSession()
            );
        }

        if ((int) $seen['error']['top'] < (int) $seen['box']['bottom']) {
            throw new ExpectationException(
                'The validation message starts at ' . $seen['error']['top'] . 'px, above the bottom of ' .
                    'the message box at ' . $seen['box']['bottom'] . 'px.',
                $this->getSession()
            );
        }

        // Clear of the button: its own column, not underneath it.
        if ((int) $seen['error']['right'] > (int) $seen['send']['left']) {
            throw new ExpectationException(
                'The validation message reaches ' . $seen['error']['right'] . 'px, past the send ' .
                    'button\'s left edge at ' . $seen['send']['left'] . 'px. They overlap.',
                $this->getSession()
            );
        }
    }

    /**
     * Read the message box wherever it is: the reply composer, or New ticket.
     *
     * One control, two forms. What is measured here is the control itself
     * rather than the frame around it, so the same step serves both.
     *
     * The reply composer is asked for first, and that ordering is load-bearing.
     * index.php renders the New ticket drawer before the ticket detail drawer,
     * so on the ticket list there are two of these controls in the document and
     * the New ticket one comes first. Taking whichever came first would measure
     * a box inside a closed drawer — zero-sized, so every proportion it is
     * asked about holds trivially and the reply composer goes unmeasured.
     *
     * @return array|null Null when no message box is on screen.
     */
    protected function measure_message_box(): ?array {
        $json = $this->evaluate_script(
            "(function() {
                var box = document.querySelector('#mhd-reply-editor textarea[data-region=\"mhd-composer-input\"]')
                    || document.querySelector('textarea[data-region=\"mhd-composer-input\"]');
                if (!box) {
                    return null;
                }
                var r = box.getBoundingClientRect();
                var cs = window.getComputedStyle(box);
                return JSON.stringify({
                    height: Math.round(r.height),
                    width: Math.round(r.width),
                    lineheight: Math.round(parseFloat(cs.lineHeight)),
                    maxheight: Math.round(parseFloat(cs.maxHeight) || 0),
                    resize: cs.resize,
                    overflowy: cs.overflowY,
                    scrollheight: box.scrollHeight,
                    clientheight: box.clientHeight,
                    padding: Math.round(parseFloat(cs.paddingTop) + parseFloat(cs.paddingBottom)),
                    scrollswide: box.scrollWidth > box.clientWidth + 1,
                    lines: box.value === '' ? 0 : box.value.split('\\n').length
                });
            })()"
        );

        return $json === null ? null : json_decode($json, true);
    }

    /**
     * Assert the message box behaves as the growing box both forms expect.
     *
     * @Then /^the mooDesk message box should be the shared auto-growing control$/
     * @throws ExpectationException
     */
    public function the_message_box_should_be_the_shared_control(): void {
        $box = $this->measure_message_box();

        if ($box === null) {
            throw new ExpectationException('There is no message box on screen.', $this->getSession());
        }

        $faults = [];

        // The ceiling both forms share. Without it an auto-growing box has no
        // limit but the length of the message.
        if ((int) $box['maxheight'] <= 0) {
            $faults[] = 'it has no max-height at all';
        }

        // A drag handle would fight the auto-grow.
        if ($box['resize'] !== 'none') {
            $faults[] = 'resize is ' . $box['resize'] . ' rather than none';
        }

        if ($box['overflowy'] !== 'auto' && $box['overflowy'] !== 'scroll') {
            $faults[] = 'overflow-y is ' . $box['overflowy'] . ', so it cannot scroll inside itself';
        }

        // 1.5, which is what the ten-line ceiling is calculated from.
        $expected = (int) round(15 * 1.5);
        if (abs((int) $box['lineheight'] - $expected) > 1) {
            $faults[] = 'its line height is ' . $box['lineheight'] . 'px, not the ' . $expected .
                'px the ceiling is calculated from';
        }

        if ($box['scrollswide']) {
            $faults[] = 'it scrolls sideways';
        }

        if ($faults !== []) {
            throw new ExpectationException(
                'The message box is not the shared control: ' . implode('; ', $faults) . '.',
                $this->getSession()
            );
        }
    }

    /**
     * Assert the box has stopped at its ceiling and is scrolling inside itself.
     *
     * @Then /^the mooDesk message box should have stopped at its ceiling$/
     * @throws ExpectationException
     */
    public function the_message_box_should_have_stopped_at_its_ceiling(): void {
        $box = $this->measure_message_box();

        if ($box === null) {
            throw new ExpectationException('There is no message box on screen.', $this->getSession());
        }

        $height = (int) $box['height'];
        $ceiling = (int) $box['maxheight'];

        if ($ceiling <= 0) {
            throw new ExpectationException(
                'The message box has no ceiling, so it cannot have stopped at one.',
                $this->getSession()
            );
        }

        if (abs($height - $ceiling) > 2) {
            throw new ExpectationException(
                'The message box is ' . $height . 'px tall against a ' . $ceiling .
                    'px ceiling, holding ' . $box['lines'] . ' lines in ' . $box['scrollheight'] .
                    'px of content. A message long enough to pass the ceiling has to stop there.',
                $this->getSession()
            );
        }

        // Stopped, not truncated: the rest of the message is still reachable.
        if ((int) $box['scrollheight'] <= (int) $box['clientheight']) {
            throw new ExpectationException(
                'The message box is at its ceiling but does not scroll (content ' .
                    $box['scrollheight'] . 'px in a ' . $box['clientheight'] .
                    'px box), so the end of a long message cannot be reached.',
                $this->getSession()
            );
        }
    }

    /**
     * Assert the box grew to fit what was typed, short of its ceiling.
     *
     * @Then /^the mooDesk message box should have grown to fit what was typed$/
     * @throws ExpectationException
     */
    public function the_message_box_should_have_grown(): void {
        $box = $this->measure_message_box();

        if ($box === null) {
            throw new ExpectationException('There is no message box on screen.', $this->getSession());
        }

        // Every line it holds, plus its own padding, and nothing left over.
        $content = (int) $box['scrollheight'];
        $height  = (int) $box['height'];

        if (abs($height - $content) > 2) {
            throw new ExpectationException(
                'The message box is ' . $height . 'px tall around ' . $content .
                    'px of content. Auto-grow has to leave the box the height of what is in it.',
                $this->getSession()
            );
        }
    }

    /**
     * Assert the send button and the message box meet with nothing between them.
     *
     * The two are one control drawn in two colours: the box is square and ends
     * where the button begins, and the button's left corners are square too, so
     * none of the composer's own background shows at the join. The frame around
     * both is what carries the outer radius; the button keeps only its right one.
     *
     * With the suffix, the box has to be the focused element as well. It draws
     * its ring inside itself, and the join has to hold under it — the ring must
     * not spill over the button, no second ring may be painted outside the box,
     * and nothing may open between the two.
     *
     * @Then /^the mooDesk send button should be joined to the message box( while the box has focus)?$/
     * @param string|null $focused The suffix, when the focused state is the one being asserted.
     * @throws ExpectationException
     */
    public function the_send_button_should_be_joined_to_the_box(?string $focused = null): void {
        $seen = $this->evaluate_script(
            "(function() {
                var composer = document.querySelector('#mhd-reply-editor');
                var box = composer ? composer.querySelector('[data-region=\"mhd-composer-input\"]') : null;
                var send = composer ? composer.querySelector('[data-region=\"mhd-composer-send\"]') : null;
                if (!box || !send) {
                    return null;
                }
                var b = box.getBoundingClientRect();
                var s = send.getBoundingClientRect();
                var bs = window.getComputedStyle(box);
                var ss = window.getComputedStyle(send);
                return {
                    gap: Math.round(s.left - b.right),
                    drift: Math.round(s.bottom - b.bottom),
                    sendleft: ss.borderTopLeftRadius + ' / ' + ss.borderBottomLeftRadius,
                    boxright: bs.borderTopRightRadius + ' / ' + bs.borderBottomRightRadius,
                    focused: document.activeElement === box,
                    ringwidth: bs.outlineStyle === 'none' ? 0 : (parseFloat(bs.outlineWidth) || 0),
                    ringoffset: parseFloat(bs.outlineOffset) || 0,
                    halo: bs.boxShadow
                };
            })()"
        );

        if ($seen === null) {
            throw new ExpectationException(
                'The composer, its message box or its send button is not on screen.',
                $this->getSession()
            );
        }

        $faults = [];

        if (abs((int) $seen['gap']) > 1) {
            $faults[] = 'the button starts ' . $seen['gap'] . 'px from the box\'s edge';
        }

        if (abs((int) $seen['drift']) > 1) {
            $faults[] = 'the button\'s bottom edge is ' . $seen['drift'] . 'px off the box\'s';
        }

        if ($seen['sendleft'] !== '0px / 0px') {
            $faults[] = 'the button\'s left corners are rounded (' . $seen['sendleft'] . ')';
        }

        if ($seen['boxright'] !== '0px / 0px') {
            $faults[] = 'the box\'s right corners are rounded (' . $seen['boxright'] . ')';
        }

        if ($focused !== null) {
            if (!$seen['focused']) {
                $faults[] = 'the box does not have focus';
            } else if ((float) $seen['ringwidth'] <= 0) {
                $faults[] = 'the focused box draws no ring';
            } else if ((float) $seen['ringoffset'] + (float) $seen['ringwidth'] > 0) {
                $faults[] = 'the box\'s ring reaches ' . ((float) $seen['ringoffset'] + (float) $seen['ringwidth']) .
                    'px outside it, over the join';
            }

            // Bootstrap's focus glow is a second ring, outside the box, and it
            // ends where the button begins. The inset outline is the only ring.
            if ($seen['halo'] !== 'none') {
                $faults[] = 'the focused box also paints a halo outside itself (box-shadow: ' . $seen['halo'] . ')';
            }
        }

        if ($faults !== []) {
            throw new ExpectationException(
                'The send button is not joined to the message box: ' . implode('; ', $faults) . '.',
                $this->getSession()
            );
        }
    }

    /**
     * Make the composer's next send fail on the server, whatever it contains.
     *
     * The one refusal a test can provoke without a second actor: ajax.php checks
     * the session key before it reads the action, and answers a bad one with
     * the same JSON refusal every other server-side "no" comes back as. What is
     * under test downstream is the composer's handling of a refusal that
     * arrived — it stays up, keeps the text, says so — not the key itself.
     *
     * @Given /^the mooDesk composer's session key is wrong$/
     */
    public function the_composer_session_key_is_wrong(): void {
        $this->execute_script(
            "(function() {
                var keys = document.querySelectorAll('#mhd-reply-editor form input[name=sesskey]');
                keys.forEach(function(k) {
                    k.value = 'not-the-session-key';
                });
            })()"
        );
    }

    /* ----------------------------------------------------------------------
     * Suggested articles in the New Ticket form (KB-04)
     * -------------------------------------------------------------------- */

    /**
     * The suggestion block that is on screen: the one inside an open drawer
     * when there is one, else the page's.
     *
     * The ticket list renders the New ticket drawer closed on every load, and
     * a closed drawer's block is in the document with no size. Reading the
     * first match would measure that one on the page mount and the right one
     * on the drawer mount, so the block is taken by where it is visible.
     *
     * @return string JavaScript expression evaluating to the block element or null.
     */
    private function suggest_block_js(): string {
        return "Array.prototype.find.call(document.querySelectorAll('[data-region=\"mhd-kb-suggest\"]'),"
            . " function(el) { return el.getBoundingClientRect().height > 0 || el.closest('.is-open'); })";
    }

    /**
     * Start a ticket on one of the two mounts of the same form.
     *
     * "page" is submit.php; "drawer" is the New ticket panel over the ticket
     * list. Both come from new_ticket_drawer::create(), and the scenarios that
     * use this step exist to show they behave the same.
     *
     * @Given /^I start a mooDesk ticket from the "(?P<mount_string>page|drawer)" as "(?P<user_string>(?:[^"]|\\")*)"$/
     * @param string $mount "page" or "drawer".
     * @param string $user Username to log in as.
     */
    public function i_start_a_moodesk_ticket_from(string $mount, string $user): void {
        if ($mount === 'page') {
            $this->execute('behat_navigation::i_am_on_page_logged_in_as', ['local_moodesk > New ticket', $user]);
            return;
        }
        $this->execute('behat_navigation::i_am_on_page_logged_in_as', ['local_moodesk > Tickets', $user]);
        $this->execute('behat_general::i_click_on', ['New ticket', 'link']);
        $this->execute('behat_general::wait_until_the_page_is_ready');
    }

    /**
     * Assert what the suggestion block lists, in order, once it has settled.
     *
     * Waits for the block to leave its loading and updating states, then
     * compares the titles of the rows to the table. An empty table asserts
     * that the block is quiet: no rows and the idle state.
     *
     * @Then /^the mooDesk suggested articles should be:$/
     * @param TableNode $table One column of article titles, or no rows.
     * @throws ExpectationException
     */
    public function the_moodesk_suggested_articles_should_be(TableNode $table): void {
        // A table with one empty cell is how Gherkin says "no rows".
        $expected = array_values(array_filter(
            array_map(static fn(array $row): string => trim((string) reset($row)), $table->getRows()),
            static fn(string $title): bool => $title !== ''
        ));
        $block    = $this->suggest_block_js();

        $this->spin(
            function ($context) use ($expected, $block) {
                $result = $context->evaluate_script(
                    "(function() {
                        var el = $block;
                        if (!el) { return {found: false}; }
                        return {found: true, state: el.dataset.state || 'idle',
                                titles: Array.prototype.map.call(el.querySelectorAll('.mhd-kb-suggest__link'),
                                    function(a) { return a.childNodes[0].textContent.trim(); })};
                    })()"
                );
                if (empty($result['found'])) {
                    throw new ExpectationException('There is no suggestion block on screen.', $context->getSession());
                }
                if (in_array($result['state'], ['loading', 'updating'], true)) {
                    return false;
                }
                if ($result['titles'] !== $expected) {
                    throw new ExpectationException(
                        'The suggested articles are [' . implode(' | ', $result['titles']) . '], expected ['
                            . implode(' | ', $expected) . '].',
                        $context->getSession()
                    );
                }
                if (empty($expected) && $result['state'] !== 'idle') {
                    throw new ExpectationException(
                        'Nothing is suggested, but the block is in the "' . $result['state'] . '" state rather than idle.',
                        $context->getSession()
                    );
                }
                return true;
            },
            [],
            self::get_extended_timeout(),
            null,
            true
        );
    }

    /**
     * Open one suggested article, which opens in a new window.
     *
     * @When /^I open the mooDesk suggested article "(?P<title_string>(?:[^"]|\\")*)"$/
     * @param string $title The article's exact title.
     */
    public function i_open_the_moodesk_suggested_article(string $title): void {
        $this->execute('behat_general::i_click_on', [
            "//*[@data-region='mhd-kb-suggest']//a[contains(@class, 'mhd-kb-suggest__link')]"
                . "[normalize-space(text()[1]) = " . behat_context_helper::escape($title) . "]",
            'xpath_element',
        ]);
    }

    /**
     * Put a ticket in a status, on the row: a fixture that wants a closed
     * ticket with replies has to add the replies first, since the service
     * refuses a reply on a closed one.
     *
     * @Given /^the mooDesk ticket "(?P<subject_string>(?:[^"]|\\")*)" is (?P<status_string>open|pending|resolved|closed)$/
     * @param string $subject The ticket's exact subject.
     * @param string $status  open, pending, resolved or closed.
     */
    public function the_moodesk_ticket_is(string $subject, string $status): void {
        global $DB;
        $id = $DB->get_field('local_moodesk_ticket', 'id', ['subject' => $subject], IGNORE_MULTIPLE);
        if (!$id) {
            throw new Exception('There is no mooDesk ticket with subject "' . $subject . '".');
        }
        $map = [
            'open'     => \local_moodesk\domain\ticket::STATUS_OPEN,
            'pending'  => \local_moodesk\domain\ticket::STATUS_PENDING,
            'resolved' => \local_moodesk\domain\ticket::STATUS_RESOLVED,
            'closed'   => \local_moodesk\domain\ticket::STATUS_CLOSED,
        ];
        $DB->set_field('local_moodesk_ticket', 'status', $map[$status], ['id' => $id]);
    }

    /**
     * Assert that a hand-made "create from ticket" URL is refused for the
     * current user, and that the refusal page carries nothing of the ticket.
     *
     * Out of band, like the ticket refusal above: a rendered error page would
     * fail the scenario in Behat's after-step hook before it could be read.
     *
     * @Then /^mooDesk should refuse me a new article from the ticket "(?P<subject_string>(?:[^"]|\\")*)"$/
     * @param string $subject The ticket's exact subject.
     */
    public function moodesk_should_refuse_me_a_new_article_from_the_ticket(string $subject): void {
        global $DB;

        $ticketid = $DB->get_field('local_moodesk_ticket', 'id', ['subject' => $subject], MUST_EXIST);
        $url      = (new moodle_url('/local/moodesk/kb_article_edit.php', ['fromticket' => $ticketid]))->out(false);
        [$body, $httpcode] = $this->fetch_with_session($url);

        $iserror = $httpcode >= 400 || str_contains($body, 'data-rel="fatalerror"');
        if (!$iserror) {
            throw new ExpectationException(
                'Requesting a new article from ticket "' . $subject . '" returned HTTP ' . $httpcode . ' and no error page.',
                $this->getSession()
            );
        }

        // Neither the subject nor the form: nothing was prefilled.
        if (str_contains($body, s($subject)) || str_contains($body, 'name="title"')) {
            throw new ExpectationException(
                'The refusal page for a new article from ticket "' . $subject . '" leaked the ticket or the form.',
                $this->getSession()
            );
        }
    }

    /**
     * Return to the window the ticket is being written in, after an article
     * opened in a second one.
     *
     * Core's "I switch to the main window" normalises the name to null, which
     * the WebDriver driver sends as an empty handle; chromedriver accepts that
     * and stays where it is. The first handle the driver reports is the window
     * the scenario started in — the same fact core's "I switch to a second
     * window" relies on for the second.
     *
     * @When /^I switch back to the mooDesk ticket window$/
     */
    public function i_switch_back_to_the_moodesk_ticket_window(): void {
        $names = $this->getSession()->getWindowNames();
        $this->getSession()->switchToWindow($names[0]);
    }

    /**
     * Make the suggestion endpoint answer its first request after every later
     * one, so a stale reply is guaranteed to arrive late.
     *
     * Wraps XMLHttpRequest for the rest of the page: the first request is held
     * for three seconds, later ones for a fraction of that. Only a scenario
     * about stale replies has any use for this, which is why it is a step and
     * not a fixture.
     *
     * @Given /^the mooDesk knowledge base answers the first request last$/
     */
    public function the_moodesk_knowledge_base_answers_the_first_request_last(): void {
        $this->execute_script(
            "(function() {
                var send = XMLHttpRequest.prototype.send, n = 0;
                XMLHttpRequest.prototype.send = function() {
                    var xhr = this, args = arguments, delay = (n++ === 0) ? 3000 : 300;
                    window.setTimeout(function() { send.apply(xhr, args); }, delay);
                };
            })()"
        );
    }

    /*
     * ----------------------------------------------------------------------
     * KB-05 — the composer's "Insert article" tool.
     * ----------------------------------------------------------------------
     */

    /**
     * The composer on screen: the one inside an open drawer when there is
     * one, the page's otherwise.
     *
     * @return string JavaScript expression evaluating to the composer element or null.
     */
    private function composer_js(): string {
        return "(document.querySelector('.mhd-detail-drawer.is-open #mhd-reply-editor')"
            . " || document.querySelector('#mhd-reply-editor'))";
    }

    /**
     * Open (or close) the composer's Insert article tool.
     *
     * @When /^I (?P<action_string>open|close) the mooDesk insert article tool$/
     * @param string $action
     */
    public function i_open_the_moodesk_insert_article_tool(string $action): void {
        $selector = $action === 'close'
            ? "[data-action='mhd-kb-insert-close']"
            : "[data-action='mhd-kb-insert-toggle']";
        $this->execute('behat_general::i_click_on', [$selector, 'css_element']);
    }

    /**
     * Type into the tool's search field.
     *
     * @When /^I search the mooDesk insert article tool for "(?P<query_string>(?:[^"]|\\")*)"$/
     * @param string $query
     */
    public function i_search_the_moodesk_insert_article_tool_for(string $query): void {
        $this->execute('behat_forms::i_set_the_field_with_xpath_to', [
            "//input[@data-region='mhd-kb-insert-search']",
            $query,
        ]);
    }

    /**
     * Pick one row of the tool, which inserts the article.
     *
     * @When /^I insert the mooDesk article "(?P<title_string>(?:[^"]|\\")*)"$/
     * @param string $title The article's exact title.
     */
    public function i_insert_the_moodesk_article(string $title): void {
        $this->execute('behat_general::i_click_on', [
            "//button[@data-action='mhd-kb-insert-pick'][@data-title=" . behat_context_helper::escape($title) . "]",
            'xpath_element',
        ]);
    }

    /**
     * The rows the tool offers right now, once it has stopped loading.
     *
     * One column of titles; a second column, when given, says "agents" for a
     * row that must carry the "Agents only" pill and "" for one that must
     * not. An empty table asserts an empty list.
     *
     * @Then /^the mooDesk insert article tool should offer:$/
     * @param TableNode $table
     * @throws ExpectationException
     */
    public function the_moodesk_insert_article_tool_should_offer(TableNode $table): void {
        $expected = [];
        foreach ($table->getRows() as $row) {
            $title = trim((string) ($row[0] ?? ''));
            if ($title === '') {
                continue;
            }
            $expected[] = [$title, trim((string) ($row[1] ?? '')) === 'agents'];
        }
        $composer = $this->composer_js();

        $this->spin(
            function ($context) use ($expected, $composer) {
                $result = $context->evaluate_script(
                    "(function() {
                        var c = $composer;
                        var panel = c && c.querySelector('[data-region=\"mhd-kb-insert-panel\"]');
                        if (!panel || panel.hidden) { return {found: false}; }
                        return {found: true, state: panel.dataset.state || 'idle',
                                rows: Array.prototype.map.call(panel.querySelectorAll('[data-action=\"mhd-kb-insert-pick\"]'),
                                    function(b) { return [b.dataset.title, !!b.querySelector('.mhd-kb-insert__pill')]; })};
                    })()"
                );
                if (empty($result['found'])) {
                    throw new ExpectationException('The insert article tool is not open.', $context->getSession());
                }
                if (in_array($result['state'], ['loading', 'updating', 'idle'], true)) {
                    return false;
                }
                if ($result['rows'] !== $expected) {
                    $show = static fn(array $rows): string => implode(' | ', array_map(
                        static fn(array $r): string => $r[0] . ($r[1] ? ' (agents)' : ''),
                        $rows
                    ));
                    throw new ExpectationException(
                        'The tool offers [' . $show($result['rows']) . '], expected [' . $show($expected) . '].',
                        $context->getSession()
                    );
                }
                return true;
            },
            [],
            self::get_extended_timeout(),
            null,
            true
        );
    }

    /**
     * What the message box holds, exactly, with "\n" standing for a newline.
     *
     * @Then /^the mooDesk message box should read "(?P<text_string>(?:[^"]|\\")*)"$/
     * @param string $text
     * @throws ExpectationException
     */
    public function the_moodesk_message_box_should_read(string $text): void {
        $expected = str_replace(['\\n', '\\"'], ["\n", '"'], $text);
        $actual   = $this->evaluate_script(
            "(function() { var c = " . $this->composer_js() . ";"
            . " return c.querySelector('[data-region=\"mhd-composer-input\"]').value; })()"
        );
        if ($actual !== $expected) {
            throw new ExpectationException(
                'The message box reads ' . json_encode($actual) . ', expected ' . json_encode($expected) . '.',
                $this->getSession()
            );
        }
    }

    /**
     * The message box holds a link to the article, in the inserted form.
     *
     * @Then /^the mooDesk message box should link the article "(?P<title_string>(?:[^"]|\\")*)"$/
     * @param string $title
     * @throws ExpectationException
     */
    public function the_moodesk_message_box_should_link_the_article(string $title): void {
        $url    = (new moodle_url('/local/moodesk/kb_article.php', ['id' => $this->article_id($title)]))->out(false);
        $actual = $this->evaluate_script(
            "(function() { var c = " . $this->composer_js() . ";"
            . " return c.querySelector('[data-region=\"mhd-composer-input\"]').value; })()"
        );
        if (strpos($actual, $title . ': ' . $url) === false) {
            throw new ExpectationException(
                'The message box reads ' . json_encode($actual) . ' and does not link "' . $title . '" as ' . $url . '.',
                $this->getSession()
            );
        }
    }

    /**
     * Where the caret and the focus are after an insert: in the message box,
     * at its end.
     *
     * @Then /^the mooDesk message box should have the focus with the caret at its end$/
     * @throws ExpectationException
     */
    public function the_moodesk_message_box_should_have_the_focus_with_the_caret_at_its_end(): void {
        $result = $this->evaluate_script(
            "(function() { var c = " . $this->composer_js() . ";"
            . " var i = c.querySelector('[data-region=\"mhd-composer-input\"]');"
            . " return {focused: document.activeElement === i, start: i.selectionStart, end: i.selectionEnd,"
            . " length: i.value.length}; })()"
        );
        if (empty($result['focused'])) {
            throw new ExpectationException('The message box does not have the focus.', $this->getSession());
        }
        if ($result['start'] !== $result['length'] || $result['end'] !== $result['length']) {
            throw new ExpectationException(
                'The caret is at ' . $result['start'] . '-' . $result['end'] . ' of ' . $result['length'] . '.',
                $this->getSession()
            );
        }
    }

    /**
     * Which control has the focus, by its data-action or data-region.
     *
     * @Then /^the mooDesk focus should be on "(?P<hook_string>[a-z0-9-]+)"$/
     * @param string $hook A data-action or data-region value.
     * @throws ExpectationException
     */
    public function the_moodesk_focus_should_be_on(string $hook): void {
        $result = $this->evaluate_script(
            "(function() { var a = document.activeElement; if (!a) { return ''; }"
            . " return (a.dataset.action || '') + ' ' + (a.dataset.region || ''); })()"
        );
        if (strpos(' ' . $result . ' ', ' ' . $hook . ' ') === false) {
            throw new ExpectationException('The focus is on "' . trim($result) . '", not "' . $hook . '".', $this->getSession());
        }
    }

    /**
     * Whether the R1 review warning is on screen.
     *
     * @Then /^the mooDesk review warning should be (?P<state_string>shown|hidden)$/
     * @param string $state
     * @throws ExpectationException
     */
    public function the_moodesk_review_warning_should_be(string $state): void {
        // The tool updates the warning from a setTimeout(0) after the mode tab's
        // own click handler, which Behat's pending-JS wait does not track: read
        // once, the step could look before the update and see the old state.
        $script = "(function() { var c = " . $this->composer_js() . ";"
            . " var w = c && c.querySelector('[data-region=\"mhd-kb-insert-warning\"]');"
            . " return !w || w.hidden; })()";
        $this->spin(
            function ($context) use ($script, $state) {
                $hidden = $context->evaluate_script($script);
                if ($hidden !== ($state === 'hidden')) {
                    throw new ExpectationException(
                        'The review warning is ' . ($hidden ? 'hidden' : 'shown') . '.',
                        $context->getSession()
                    );
                }
                return true;
            },
            [],
            self::get_extended_timeout(),
            null,
            true
        );
    }

    /**
     * The composer, with the tool open, keeps its send button on screen and
     * nothing scrolls sideways.
     *
     * @Then /^the mooDesk composer should keep its send button on screen with the tool open$/
     * @throws ExpectationException
     */
    public function the_moodesk_composer_should_keep_its_send_button_on_screen_with_the_tool_open(): void {
        $result = $this->evaluate_script(
            "(function() { var c = " . $this->composer_js() . ";"
            . " var panel = c.querySelector('[data-region=\"mhd-kb-insert-panel\"]');"
            . " var send = c.querySelector('[data-region=\"mhd-composer-send\"]').getBoundingClientRect();"
            . " return {open: !!panel && !panel.hidden, sendOn: send.top >= 0 && send.bottom <= window.innerHeight,"
            . " overflow: document.documentElement.scrollWidth > window.innerWidth}; })()"
        );
        if (empty($result['open'])) {
            throw new ExpectationException('The tool is not open.', $this->getSession());
        }
        if (empty($result['sendOn'])) {
            throw new ExpectationException('The send button is off screen with the tool open.', $this->getSession());
        }
        if (!empty($result['overflow'])) {
            throw new ExpectationException('The page scrolls sideways with the tool open.', $this->getSession());
        }
    }

    /*
     * ----------------------------------------------------------------------
     * The conversation: a URL in a plain-text message is a link.
     * ----------------------------------------------------------------------
     */

    /**
     * The XPath of the link the conversation makes from an article's URL: an
     * anchor whose href and whose text are both exactly that URL — what
     * thread_message makes of "Title: URL", and nothing else would.
     *
     * @param string $title The article's exact title.
     * @return string
     */
    private function conversation_article_link_xpath(string $title): string {
        $url = (new moodle_url('/local/moodesk/kb_article.php', ['id' => $this->article_id($title)]))->out(false);
        $esc = behat_context_helper::escape($url);
        return "//*[contains(concat(' ', normalize-space(@class), ' '), ' mhd-thread ')]"
            . "//a[@href = " . $esc . "][normalize-space(.) = " . $esc . "]";
    }

    /**
     * Assert that the conversation shows "Title: URL" with the URL as a real
     * link to that article.
     *
     * @Then /^the mooDesk conversation should link the article "(?P<title_string>(?:[^"]|\\")*)"$/
     * @param string $title The article's exact title.
     */
    public function the_moodesk_conversation_should_link_the_article(string $title): void {
        $this->execute('behat_general::assert_element_contains_text', [$title . ':', '.mhd-thread', 'css_element']);
        $this->execute('behat_general::should_exist', [$this->conversation_article_link_xpath($title), 'xpath_element']);
    }

    /**
     * Follow the conversation's link to an article.
     *
     * @When /^I follow the mooDesk conversation link to the article "(?P<title_string>(?:[^"]|\\")*)"$/
     * @param string $title The article's exact title.
     */
    public function i_follow_the_moodesk_conversation_link_to_the_article(string $title): void {
        $this->execute('behat_general::i_click_on', [$this->conversation_article_link_xpath($title), 'xpath_element']);
    }

    /**
     * Assert that the reader refuses the current user an article — the URL
     * may have reached them by any road, and the reader is the boundary.
     *
     * Out of band, like the ticket refusal: a rendered error page would fail
     * the scenario in Behat's after-step hook before it could be read.
     *
     * @Then /^mooDesk should refuse me the article "(?P<title_string>(?:[^"]|\\")*)"$/
     * @param string $title The article's exact title.
     */
    public function moodesk_should_refuse_me_the_article(string $title): void {
        $url = (new moodle_url('/local/moodesk/kb_article.php', ['id' => $this->article_id($title)]))->out(false);
        [$body, $httpcode] = $this->fetch_with_session($url);

        $iserror = $httpcode >= 400 || str_contains($body, 'data-rel="fatalerror"');
        if (!$iserror) {
            throw new ExpectationException(
                'Requesting the article "' . $title . '" returned HTTP ' . $httpcode . ' and no error page.',
                $this->getSession()
            );
        }
    }

    /**
     * Skip the scenario on a site where the mooDesk add-on is installed: what it
     * pins is the base on its own, as a Starter site runs it.
     *
     * @Given /^the mooDesk add-on is not installed$/
     * @throws \Moodle\BehatExtension\Exception\SkippedException
     */
    public function the_moodesk_add_on_is_not_installed(): void {
        if (\core_component::get_component_directory('local_moodeskpro') !== null) {
            throw new \Moodle\BehatExtension\Exception\SkippedException();
        }
    }
}
