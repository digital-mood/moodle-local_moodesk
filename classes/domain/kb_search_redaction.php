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
 * What may be kept of the words of a knowledge base search.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\domain;

/**
 * Redacts search tokens before they leave the page that received them.
 *
 * In the base so nothing past kb.php ever sees what was typed: the hook
 * kb_search_recorded carries only this output (docs/two-plugin-split-plan.md §8.2).
 */
class kb_search_redaction {
    /** Longest `querynorm` stored; six tokens never reach it, belt and braces. */
    const QUERYNORM_MAX_LENGTH = 120;

    /** A token this long or longer is dropped before storage (a pasted id, a URL). */
    const REDACT_TOKEN_MAX_LENGTH = 40;

    /** A token with this many consecutive digits is dropped (phone, id, card). */
    const REDACT_DIGIT_RUN = 5;

    /**
     * Drop the tokens that could identify a person or carry a secret.
     *
     * Applied to the tokens the KB search actually matched on — after
     * {@see \local_moodesk\service\kb_service::tokenize()} — and only when a
     * query found nothing, which is the one case a query is stored at all.
     * Three rules, each for one thing seen in real support queries:
     *
     *  - a token containing `@` — an e-mail address;
     *  - a token with {@see REDACT_DIGIT_RUN} or more consecutive digits — a
     *    phone number, a student id, a card, a one-time code;
     *  - a token of {@see REDACT_TOKEN_MAX_LENGTH} or more characters — a
     *    pasted URL, a hash, a session token.
     *
     * Order and duplicates are the tokeniser's business; this only removes.
     *
     * @param string[] $tokens Lower-cased tokens as the search saw them.
     * @return string[] The survivors, re-indexed.
     */
    public static function redact_tokens(array $tokens): array {
        $kept = [];
        foreach ($tokens as $token) {
            if (strpos($token, '@') !== false) {
                continue;
            }
            if (preg_match('~\d{' . self::REDACT_DIGIT_RUN . ',}~', $token)) {
                continue;
            }
            if (\core_text::strlen($token) >= self::REDACT_TOKEN_MAX_LENGTH) {
                continue;
            }
            $kept[] = $token;
        }
        return array_values($kept);
    }

    /**
     * The string stored as `querynorm`: redacted tokens joined by one space,
     * cut at {@see QUERYNORM_MAX_LENGTH}.
     *
     * An empty string is a legitimate answer — the search still counts as a
     * zero-result search, it just has no reportable query — and the caller
     * stores it as such rather than dropping the row.
     *
     * @param string[] $tokens Tokens as the search saw them (not yet redacted).
     * @return string
     */
    public static function normalise_query(array $tokens): string {
        $joined = implode(' ', self::redact_tokens($tokens));
        return \core_text::substr($joined, 0, self::QUERYNORM_MAX_LENGTH);
    }
}
