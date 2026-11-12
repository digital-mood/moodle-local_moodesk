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
 * Knowledge base article domain: statuses, audiences, transitions and the
 * one derivation every write has to agree on.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\domain;

/**
 * Constants and pure functions for local_moodesk_kb_article.
 *
 * An article is one row in one language. Rows that share a `groupid` are
 * language variants of the same article; the variant whose `id` equals its
 * `groupid` is the one it was first written in. Until translations exist
 * (KB-06) every row is its own group, and nothing here assumes otherwise.
 *
 * Who may see a row is not decided here — {@see \local_moodesk\policy\kb_access_policy}
 * is the only place that answers that. This class only says what the values
 * mean and which changes of status are legal.
 */
class kb_article {
    /** The one value of the `from` parameter of an article link: opened from Suggested Articles. */
    const ORIGIN_SUGGEST = 'suggest';

    /* ----------------------------------------------------------------------
     * Status
     * -------------------------------------------------------------------- */

    /** Being written. Visible to editors only, whatever the audience says. */
    const STATUS_DRAFT = 0;

    /** Live. Visible to whoever the audience names. */
    const STATUS_PUBLISHED = 1;

    /** Withdrawn. Kept for its history; visible to editors only. */
    const STATUS_ARCHIVED = 2;

    /* ----------------------------------------------------------------------
     * Audience
     * -------------------------------------------------------------------- */

    /** Everyone who may view the knowledge base — requesters included. */
    const AUDIENCE_EVERYONE = 0;

    /** Agents only. Internal know-how a requester should not see. */
    const AUDIENCE_AGENTS = 1;

    /**
     * Legal status transitions, from => [to, ...].
     *
     * Publishing is the only way out of draft; unpublishing sends a live
     * article back to draft, not to archived, because "not live any more" and
     * "withdrawn" are different intents. An archived article comes back as a
     * draft: it has been off the shelf long enough that somebody should read
     * it again before it is live.
     *
     * @var array<int, int[]>
     */
    const VALID_TRANSITIONS = [
        self::STATUS_DRAFT     => [self::STATUS_PUBLISHED],
        self::STATUS_PUBLISHED => [self::STATUS_DRAFT, self::STATUS_ARCHIVED],
        self::STATUS_ARCHIVED  => [self::STATUS_DRAFT],
    ];

    /**
     * Every status value, for validation.
     *
     * @return int[]
     */
    public static function statuses(): array {
        return [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED];
    }

    /**
     * Every audience value, for validation.
     *
     * @return int[]
     */
    public static function audiences(): array {
        return [self::AUDIENCE_EVERYONE, self::AUDIENCE_AGENTS];
    }

    /**
     * Whether a change of status is allowed.
     *
     * @param int $from Current status.
     * @param int $to   Requested status.
     * @return bool
     */
    public static function is_valid_transition(int $from, int $to): bool {
        return in_array($to, self::VALID_TRANSITIONS[$from] ?? [], true);
    }

    /**
     * The body as plain text, case preserved.
     *
     * Tags stripped, entities decoded, whitespace collapsed. What a reader
     * would get if they selected the whole article and pasted it somewhere
     * plain — which is what an excerpt is made of. Block-level boundaries
     * become spaces before the tags go, so "<p>one</p><p>two</p>" does not
     * collapse into "onetwo".
     *
     * @param string $body Article HTML exactly as stored.
     * @return string
     */
    public static function plain_text(string $body): string {
        $text = preg_replace('~<(br|/p|/div|/li|/h[1-6]|/tr|/td|/th)\b[^>]*>~i', ' ', $body);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('~\s+~u', ' ', $text);
        return trim($text);
    }

    /**
     * The text search matches against, derived from the authored body.
     *
     * {@see plain_text()} lower-cased with core_text so non-ASCII titles fold
     * the same way on every database. It is derived here — one function, pure
     * — so that the repository, the service and the tests cannot disagree
     * about what "searchable text" means, and so that a `<strong>` in a body
     * never matches the word "strong".
     *
     * @param string $body Article HTML exactly as stored.
     * @return string
     */
    public static function derive_searchtext(string $body): string {
        return \core_text::strtolower(self::plain_text($body));
    }
}
