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
 * Unit tests for the knowledge base article domain.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\domain;

/**
 * Tests for kb_article: the legal status changes and the one derivation
 * every write shares.
 *
 * @covers \local_moodesk\domain\kb_article
 */
final class kb_article_test extends \basic_testcase {
    /**
     * The four legal transitions, and nothing else.
     *
     * Enumerated in full because the table is the product rule: a draft goes
     * live, a live article comes back to draft or is withdrawn, a withdrawn one
     * comes back to draft. Everything else — including archived straight to
     * published, and every self-transition — is refused.
     */
    public function test_exactly_four_transitions_are_legal(): void {
        $legal = [
            [kb_article::STATUS_DRAFT, kb_article::STATUS_PUBLISHED],
            [kb_article::STATUS_PUBLISHED, kb_article::STATUS_DRAFT],
            [kb_article::STATUS_PUBLISHED, kb_article::STATUS_ARCHIVED],
            [kb_article::STATUS_ARCHIVED, kb_article::STATUS_DRAFT],
        ];

        foreach (kb_article::statuses() as $from) {
            foreach (kb_article::statuses() as $to) {
                $expected = in_array([$from, $to], $legal, true);
                $this->assertSame(
                    $expected,
                    kb_article::is_valid_transition($from, $to),
                    "Transition $from → $to should be " . ($expected ? 'legal' : 'refused')
                );
            }
        }
    }

    /**
     * A status the domain does not know cannot go anywhere.
     */
    public function test_an_unknown_status_has_no_transitions(): void {
        $this->assertFalse(kb_article::is_valid_transition(99, kb_article::STATUS_DRAFT));
        $this->assertFalse(kb_article::is_valid_transition(kb_article::STATUS_DRAFT, 99));
    }

    /**
     * Search text carries the words and none of the markup.
     */
    public function test_searchtext_drops_tags_keeps_words(): void {
        $body = '<h2>Reset your <strong>password</strong></h2><p>Click   Forgot&nbsp;password.</p>';
        $this->assertSame('reset your password click forgot password.', kb_article::derive_searchtext($body));
    }

    /**
     * A tag name is not a word: `<strong>` must not make the body match "strong".
     */
    public function test_searchtext_never_matches_a_tag_name(): void {
        $this->assertSame('one two', kb_article::derive_searchtext('<strong>one</strong> <em>two</em>'));
        $this->assertStringNotContainsString('strong', kb_article::derive_searchtext('<strong>x</strong>'));
    }

    /**
     * Block boundaries become spaces so adjacent paragraphs do not fuse.
     */
    public function test_searchtext_keeps_paragraphs_apart(): void {
        $this->assertSame('one two three', kb_article::derive_searchtext('<p>one</p><p>two</p><ul><li>three</li></ul>'));
    }

    /**
     * Scripts contribute their text and nothing else — the same treatment as any tag.
     */
    public function test_searchtext_of_a_script_is_just_its_text(): void {
        $text = kb_article::derive_searchtext('<p>hello</p><script>alert(1)</script>');
        $this->assertStringNotContainsString('<', $text);
        $this->assertStringNotContainsString('script', $text);
    }

    /**
     * Case folds with core_text so a non-ASCII title is found however it was typed.
     */
    public function test_searchtext_folds_case_beyond_ascii(): void {
        $this->assertSame('contraseña olvidada', kb_article::derive_searchtext('<p>CONTRASEÑA Olvidada</p>'));
    }

    /**
     * Entities decode to the character they stand for.
     */
    public function test_searchtext_decodes_entities(): void {
        $this->assertSame('a & b "c"', kb_article::derive_searchtext('a &amp; b &quot;c&quot;'));
    }

    /**
     * An empty body derives to an empty string, not to whitespace.
     */
    public function test_searchtext_of_nothing_is_empty(): void {
        $this->assertSame('', kb_article::derive_searchtext(''));
        $this->assertSame('', kb_article::derive_searchtext('<p>  </p>'));
    }
}
