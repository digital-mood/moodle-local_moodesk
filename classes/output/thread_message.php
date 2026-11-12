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

/**
 * How a stored message becomes the HTML the conversation shows.
 *
 * One rule on top of {@see format_text()}: in a plain-text message
 * (FORMAT_PLAIN — what the ticket composer writes), a URL is a link.
 *
 * Why this exists. Moodle formats FORMAT_PLAIN by escaping it and turning
 * newlines into `<br />`, and runs **no text filter over it** — whatever the
 * site's filters say, `filter_urltolink` never sees plain text
 * (`core\formatting::format_text`, the FORMAT_PLAIN case, identical from 4.5
 * to 5.x). A conversation where an agent pastes a URL, or drops a knowledge
 * base article in with "Insert article" (KB-05), would therefore show the
 * address as inert text. For a help desk that is the wrong default: the
 * requester should be able to click what they were sent.
 *
 * What it is not. The message is still stored as the plain text it was; this
 * is presentation only, on the way out, at the one place the conversation is
 * rendered. FORMAT_PLAIN does not become trusted HTML: the text is escaped
 * first, exactly as before, and the links are made *from the escaped text*,
 * by Moodle's own URL matcher — the engine of the core "Convert URLs into
 * links" filter — so there is no regular expression of this plugin's to
 * maintain and no way for typed markup to come through as markup. The
 * filter's public entry point is not used on purpose: it applies only to the
 * formats an admin listed for it (Plain is not among the defaults), and a
 * link that depends on a site setting is not a product behaviour. Its
 * "embed images" option is also left aside: an image address in a plain
 * message stays a link, never an `<img>` fetched from wherever the address
 * points, because that is an external request made in the reader's name
 * from text anyone on the ticket may have typed.
 *
 * Should the core filter class ever be absent, the message renders exactly as
 * it always did — escaped, line-broken, unlinked.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class thread_message {
    /** The core class whose URL matcher is borrowed. */
    private const URLTOLINK = \filter_urltolink\text_filter::class;

    /**
     * The HTML for one message of the conversation.
     *
     * @param string   $message The stored text.
     * @param int      $format  Its stored format (FORMAT_PLAIN, FORMAT_HTML, ...).
     * @param \context $context Context the text is shown in, for format_text().
     * @return string
     */
    public static function format(string $message, int $format, \context $context): string {
        $html = format_text($message, $format, ['context' => $context]);

        if ($format === (int) FORMAT_PLAIN) {
            $html = self::linkify($html, $context);
        }

        return $html;
    }

    /**
     * Turn the URLs in already-escaped plain-text HTML into links.
     *
     * Runs the core matcher over the text `format_text(FORMAT_PLAIN)` produced:
     * escaped, with `<br />` for the line breaks, which the matcher treats as
     * the tags they are — a URL ends where its line does. Escaped entities in
     * a URL (`&amp;` in a query string) travel into the href as they are; the
     * browser decodes them back.
     *
     * @param string   $html    Output of format_text() for a FORMAT_PLAIN message.
     * @param \context $context The context the filter is instantiated for.
     * @return string
     */
    public static function linkify(string $html, \context $context): string {
        if ($html === '' || !class_exists(self::URLTOLINK)) {
            return $html;
        }

        // The matcher is a protected method of the core filter; a subclass is
        // the supported way to reach it without copying it.
        $filter = new class ($context, []) extends \filter_urltolink\text_filter {
            /**
             * Apply the core URL matcher to the text.
             *
             * @param string $text Escaped HTML, modified in place.
             * @return void
             */
            public function link_urls(string &$text): void {
                $this->convert_urls_into_links($text);
            }
        };
        $filter->link_urls($html);

        // Undo the core "embed images" step where the site has it on. The
        // markup matched here is the one the core method writes, never text
        // from the message — that was escaped before the matcher ran, so it
        // cannot carry a tag of its own.
        $html = preg_replace(
            '~<img class="filter_urltolink_image" alt="" src="([^"]+)" />~',
            '<a href="$1" class="_blanktarget">$1</a>',
            $html
        ) ?? $html;

        return $html;
    }
}
