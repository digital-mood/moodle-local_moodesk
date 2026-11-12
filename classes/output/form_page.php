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

use html_writer;

/**
 * A page whose body is a form: the header, and the card the form sits in.
 *
 *   Title                                    [Secondary] [Primary]
 *   Optional description
 *   ┌──────────────────────────────────────────────────────────┐
 *   │  the moodleform, verbatim                                │
 *   │  ────────────────────────────────────────────────────────│
 *   │                              [ Cancel ]  [ Save changes ]│
 *   └──────────────────────────────────────────────────────────┘
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 *
 * Because until it did, exactly one screen in the product had a form surface.
 *
 * submit.php wrapped its form in `.moodesk-form-page` / `.moodesk-form-card` and
 * got the product's form: a card, a stacked field, an action bar in the footer.
 * The seven admin edit screens — department, category, team, custom field,
 * automation, webhook, merge — did `render_within_shell($form->render())` and
 * got whatever Boost draws by default. That is a `col-md-3` label column beside
 * a `col-md-9` control column, flipping at 768px because Bootstrap says so, with
 * `word-break: break-word` on the label. On the department screen, whose longest
 * label is "Resolution time — Normal (hours)", the result was a 178px column
 * breaking a label across four lines mid-word.
 *
 * None of those screens chose that. They just never got the wrapper, and the
 * wrapper was three html_writer calls that lived in one entry point where nobody
 * would find them. So the wrapper is a type now, and adopting it is one line.
 *
 * ── What it does NOT do ──────────────────────────────────────────────────────
 *
 * It does not touch the form. The moodleform is rendered by its owner and passed
 * in as a string, so validation, CSRF, draft file areas, `is_cancelled()` and
 * the redirect after a save all stay exactly where Moodle put them (ADR §4 —
 * restyle moodleform, never replace it). This class contributes two `<div>`s.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class form_page {
    /**
     * Wrap a rendered moodleform in the product's form surface.
     *
     * The header is required, not optional. Eight of these screens had no title
     * at all before this, which is the drift the page_header component exists to
     * end; making the parameter nullable here would reopen it one screen at a
     * time. A screen with nothing to say in its header still has a name.
     *
     * @param string      $formhtml The output of `$form->render()`, verbatim.
     * @param page_header $header   The page's header.
     * @return string HTML, ready to hand to app_page::render_within_shell().
     */
    public static function render(string $formhtml, page_header $header): string {
        return html_writer::div(
            $header->render() . html_writer::div($formhtml, 'moodesk-form-card'),
            'moodesk-form-page'
        );
    }
}
