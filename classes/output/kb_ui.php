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

namespace local_moodesk\output;

use local_moodesk\domain\kb_article;
use local_moodesk\domain\kb_language;
use local_moodesk\service\edition_service;
use local_moodesk\service\kb_service;

/**
 * What the knowledge base looks like, as opposed to what it is.
 *
 * The three authoring screens (`kb_articles.php`, `kb_article_edit.php`,
 * `kb_article.php`) each need the same handful of answers — is the module
 * switched on in this site's UI, what does a status look like, what is an
 * audience called, how does a stored body become HTML a reader may see — and
 * a screen that answers any of them on its own is a screen that will drift
 * from the other two. So the answers live here, once, and the screens ask.
 *
 * Nothing here decides who may see or change an article: that is
 * {@see \local_moodesk\policy\kb_access_policy}, asked by {@see kb_service}.
 * And `kb_enabled` is deliberately a *presentation* preference — it hides the
 * navigation entry and turns the pages into a notice — rather than a rule the
 * service enforces, so that external functions and future consumers keep
 * working when an administrator merely takes the screens out of the shell.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class kb_ui {
    /** Articles per page on the management list. */
    public const PAGE_SIZE = 20;

    /**
     * Whether the knowledge base screens are switched on for this site.
     *
     * A setting that was never saved reads as on: the default is on, and a site
     * upgraded to this version should not lose its Articles entry because the
     * administrator has not yet visited the settings page.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        $value = get_config('local_moodesk', 'kb_enabled');
        return $value === false || (bool) $value;
    }

    /**
     * The status pill for an article: its label and the Pill colour variant.
     *
     * Draft is neutral (work in progress, nothing to signal), published is
     * success (live, doing its job), archived is muted (kept, withdrawn).
     *
     * @param int $status One of the kb_article::STATUS_* constants.
     * @return array{label: string, variant: string}
     */
    public static function status_pill(int $status): array {
        switch ($status) {
            case kb_article::STATUS_PUBLISHED:
                return ['label' => get_string('kb_status_published', 'local_moodesk'), 'variant' => 'success'];
            case kb_article::STATUS_ARCHIVED:
                return ['label' => get_string('kb_status_archived', 'local_moodesk'), 'variant' => 'muted'];
            default:
                return ['label' => get_string('kb_status_draft', 'local_moodesk'), 'variant' => 'neutral'];
        }
    }

    /**
     * Every status with its label, for a filter or a select.
     *
     * @return array<int, string> status => label
     */
    public static function status_labels(): array {
        $labels = [];
        foreach (kb_article::statuses() as $status) {
            $labels[$status] = self::status_pill($status)['label'];
        }
        return $labels;
    }

    /**
     * What an audience is called.
     *
     * @param int $audience One of the kb_article::AUDIENCE_* constants.
     * @return string
     */
    public static function audience_label(int $audience): string {
        return $audience === kb_article::AUDIENCE_AGENTS
            ? get_string('kb_audience_agents', 'local_moodesk')
            : get_string('kb_audience_everyone', 'local_moodesk');
    }

    /**
     * Every audience with its label, for a select.
     *
     * @return array<int, string> audience => label
     */
    public static function audience_labels(): array {
        $labels = [];
        foreach (kb_article::audiences() as $audience) {
            $labels[$audience] = self::audience_label($audience);
        }
        return $labels;
    }

    /* ----------------------------------------------------------------------
     * Language (KB-06)
     * -------------------------------------------------------------------- */

    /**
     * Whether the language layer of the interface is shown at all: the
     * badges, the fallback notice, the switcher, the translations panel, the
     * language column. Pro and Enterprise. In Starter every row still opens
     * and every group still collapses to one card — the data is not gated —
     * but nothing on screen talks about languages, because "in your
     * language" is a Pro promise.
     *
     * @return bool
     */
    public static function shows_languages(): bool {
        return edition_service::has_feature('kb_multilingual');
    }

    /**
     * Whether a row's language is not the one the viewer is reading in.
     *
     * @param string $lang The row's language code.
     * @return bool
     */
    public static function is_foreign(string $lang): bool {
        return $lang !== current_language();
    }

    /**
     * The badge for a row's language, or null when none is shown: a row in
     * the viewer's own language carries nothing, so a badge is always the
     * exception marker; and Starter shows none.
     *
     * @param string $lang The row's language code.
     * @return array{code: string, name: string, htmllang: string}|null
     */
    public static function language_badge(string $lang): ?array {
        if (!self::shows_languages() || !self::is_foreign($lang)) {
            return null;
        }
        return [
            'code'     => kb_language::badge($lang),
            'name'     => kb_language::display_name($lang),
            'htmllang' => kb_language::html_lang($lang),
        ];
    }

    /**
     * The name of a row's language when it is not the viewer's, else '' —
     * what the two external functions return as `langlabel`.
     *
     * @param string $lang The row's language code.
     * @return string
     */
    public static function language_label(string $lang): string {
        $badge = self::language_badge($lang);
        return $badge === null ? '' : $badge['name'];
    }

    /**
     * An article body as HTML a reader may be shown.
     *
     * The body is stored exactly as authored, with its embedded images as
     * `@@PLUGINFILE@@` placeholders. On the way out the placeholders become
     * pluginfile URLs into the `kb_article` area and the whole thing goes
     * through format_text() — cleaned, never `noclean`, never trusted. This is
     * the promise `riskbitmask = 0` on the three capabilities rests on, and
     * tests/kb_html_rendering_test.php is what fails if a caller reaches for
     * anything else.
     *
     * @param \stdClass $article The raw article row: needs id, body, bodyformat.
     * @return string HTML.
     */
    public static function render_body(\stdClass $article): string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $context = \context_system::instance();
        $body    = file_rewrite_pluginfile_urls(
            (string) $article->body,
            'pluginfile.php',
            $context->id,
            'local_moodesk',
            kb_service::FILEAREA,
            (int) $article->id
        );
        return format_text($body, (int) $article->bodyformat, ['context' => $context]);
    }

    /** Characters an excerpt aims for. */
    public const EXCERPT_LENGTH = 160;

    /**
     * A short piece of an article's text for a card: plain, case preserved,
     * cut on word boundaries, centred on the first search hit when there is one.
     *
     * Made from the body rather than from `searchtext` — that column is
     * lower-cased for matching, and an excerpt in all lower case reads as a
     * defect. Plain text, not HTML: the template escapes it, or hands it to
     * {@see highlight()} which escapes it itself.
     *
     * @param string   $body   Article HTML exactly as stored.
     * @param string[] $tokens Search tokens from kb_service::tokenize(), or none.
     * @param int      $length Target length in characters.
     * @return string
     */
    public static function excerpt(string $body, array $tokens = [], int $length = self::EXCERPT_LENGTH): string {
        $text = kb_article::plain_text($body);
        if (\core_text::strlen($text) <= $length) {
            return $text;
        }

        // Where to start: on the first token that occurs, else at the top.
        $start = 0;
        if ($tokens) {
            $lower = \core_text::strtolower($text);
            $first = null;
            foreach ($tokens as $token) {
                $pos = \core_text::strpos($lower, $token);
                if ($pos !== false && ($first === null || $pos < $first)) {
                    $first = $pos;
                }
            }
            if ($first !== null) {
                $start = max(0, $first - intdiv($length, 3));
            }
        }
        $start = min($start, max(0, \core_text::strlen($text) - $length));

        // Snap both ends to a word boundary, then mark the cuts.
        if ($start > 0) {
            $space = \core_text::strpos($text, ' ', $start);
            if ($space !== false && $space < $start + 20) {
                $start = $space + 1;
            }
        }
        $piece = \core_text::substr($text, $start, $length);
        if ($start + $length < \core_text::strlen($text)) {
            $lastspace = \core_text::strrpos($piece, ' ');
            if ($lastspace !== false && $lastspace > $length - 30) {
                $piece = \core_text::substr($piece, 0, $lastspace);
            }
            $piece .= '…';
        }
        if ($start > 0) {
            $piece = '…' . $piece;
        }
        return $piece;
    }

    /**
     * Plain text as HTML with the search tokens wrapped in <mark>.
     *
     * The order is the whole safety argument, and it is not negotiable:
     * (1) the text is escaped, (2) the tokens are the service's own —
     * lower-cased words from kb_service::tokenize(), themselves escaped so
     * they can be found in the escaped text — (3) one regular expression with
     * every token as an alternative runs once over the safe text, so a later
     * token can never match inside a <mark> a former one inserted. Nothing
     * that came from the user reaches the output unescaped.
     *
     * @param string   $text   Plain text (a title, an excerpt).
     * @param string[] $tokens Search tokens from kb_service::tokenize().
     * @return string HTML safe to emit with a triple mustache.
     */
    public static function highlight(string $text, array $tokens): string {
        $safe = s($text);
        $alternatives = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token !== '') {
                $alternatives[] = preg_quote(s($token), '~');
            }
        }
        if (!$alternatives) {
            return $safe;
        }
        $marked = preg_replace('~(' . implode('|', $alternatives) . ')~iu', '<mark>$1</mark>', $safe);
        // A malformed UTF-8 sequence makes preg_replace return null; the safe
        // text without marks is the right answer then, not an empty string.
        return $marked ?? $safe;
    }

    /**
     * The page a screen shows when the knowledge base is switched off.
     *
     * The header keeps the screen's name — a notice on a blank canvas says
     * nothing about where the user is — and the notice says where to switch it
     * back on for whoever may.
     *
     * @param string $title The screen's localised title.
     * @return string HTML, ready for app_page::render_within_shell().
     */
    public static function render_disabled(string $title): string {
        $message = get_string('kb_disabled_notice', 'local_moodesk');
        if (has_capability('moodle/site:config', \context_system::instance())) {
            $message .= ' ' . \html_writer::link(
                new \moodle_url('/admin/settings.php', ['section' => 'local_moodesk']),
                get_string('kb_disabled_settings_link', 'local_moodesk')
            );
        }
        return (new page_header($title))->render()
            . \html_writer::div($message, 'alert alert-info', ['role' => 'status']);
    }
}
