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

namespace local_moodesk\domain;

use local_moodesk\service\kb_service;

/**
 * What may be kept of the words of a search.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_moodesk\domain\kb_search_redaction
 */
final class kb_search_redaction_test extends \advanced_testcase {
    /**
     * An e-mail address never reaches the table, however it was typed.
     */
    public function test_redaction_drops_email_like_tokens(): void {
        $this->assertSame(
            ['cannot', 'log'],
            kb_search_redaction::redact_tokens(['cannot', 'log', 'juan@example.org', '@handle'])
        );
    }

    /**
     * A run of five or more digits is an id, a phone or a code; four is a year.
     */
    public function test_redaction_drops_long_digit_runs_and_keeps_years(): void {
        $this->assertSame(
            ['invoice', '2026', 'moodle4'],
            kb_search_redaction::redact_tokens(['invoice', '2026', '12345', 'id-98765-x', 'moodle4', '+34600123456'])
        );
    }

    /**
     * A token of forty characters or more is a URL, a hash or a session token.
     */
    public function test_redaction_drops_overlong_tokens(): void {
        $long = str_repeat('a', kb_search_redaction::REDACT_TOKEN_MAX_LENGTH);
        $ok   = str_repeat('b', kb_search_redaction::REDACT_TOKEN_MAX_LENGTH - 1);

        $this->assertSame([$ok], kb_search_redaction::redact_tokens([$long, $ok]));
    }

    /**
     * A query that was nothing but identifiers is stored as an empty string
     * — still a counted zero-result search, just one with nothing to report.
     */
    public function test_a_fully_redacted_query_is_the_empty_string(): void {
        $this->assertSame('', kb_search_redaction::normalise_query(['ana@example.org', '1234567']));
    }

    /**
     * The stored form is the redacted tokens joined by one space, cut at the
     * column width; the tokeniser's six-token cap keeps it well under.
     */
    public function test_normalised_query_is_joined_and_bounded(): void {
        $this->assertSame('reset password', kb_search_redaction::normalise_query(['reset', 'password']));

        $tokens = array_fill(0, 6, str_repeat('x', 30));
        $normalised = kb_search_redaction::normalise_query($tokens);
        $this->assertSame(kb_search_redaction::QUERYNORM_MAX_LENGTH, \core_text::strlen($normalised));
    }

    /**
     * The recorder and the search share one tokeniser (D14): what is stored
     * is what was matched, lower-cased, short tokens dropped, six at most.
     */
    public function test_the_stored_query_is_what_the_search_matched(): void {
        $tokens = kb_service::tokenize('  How DO I reset my Password?? a');

        $this->assertSame(['how', 'reset', 'password??'], $tokens);
        $this->assertSame('how reset password??', kb_search_redaction::normalise_query($tokens));
    }
}
