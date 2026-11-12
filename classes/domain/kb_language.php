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
 * Knowledge base language rule: which translation a reader gets, and how a
 * language is shown.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\domain;

/**
 * The language-preference chain and the display helpers for KB-06.
 *
 * A group is the set of article rows sharing one `groupid`, one row per
 * language. When a reading surface has to pick one row of a group, it walks
 * a chain of language codes and takes the first one the group has — inside
 * the actor's scope, always; the chain chooses among rows the policy already
 * allows, never instead of it:
 *
 *  1. the reader's current language;
 *  2. the parent Moodle declares for it (`es_mx` → `es`; nothing for `pt_br`,
 *     because Moodle says nothing) — only when it declares one;
 *  3. the site language;
 *  4. the canonical row, the one whose `id = groupid` (the language the
 *     article was first written in);
 *  5. any remaining row, lowest id first.
 *
 * Steps 4 and 5 are not codes and are appended by the repository's SQL; this
 * class produces the codes of 1–3, duplicates removed and order kept, and
 * nothing else about the choice. In Starter the chain is the site language
 * alone (the tail still applies): "articles in your language" is what Pro
 * adds, and the reduced chain is how Starter stays honest about it.
 *
 * Every list mooDesk shows about languages is Moodle's own: installed packs
 * (disabled included, so a row written under a pack later disabled still has
 * a name), their localised names, and the HTML `lang` value. Nothing here
 * keeps a list of languages.
 */
class kb_language {
    /**
     * The ordered codes to try for a reader, duplicates removed.
     *
     * Pure: the caller resolves the current language, its parent and the
     * site language, so this can be tested without a language pack and so
     * there is one place that says what the order is.
     *
     * @param string $current The reader's current language (current_language()).
     * @param string $parent  What Moodle declares as its parent, '' for none.
     * @param string $site    The site language ($CFG->lang).
     * @return string[] At least one code; never empty.
     */
    public static function preference_chain(string $current, string $parent, string $site): array {
        $chain = [];
        foreach ([$current, $parent, $site] as $code) {
            $code = trim($code);
            if ($code !== '' && !in_array($code, $chain, true)) {
                $chain[] = $code;
            }
        }
        return $chain ?: ['en'];
    }

    /**
     * The chain for a reader whose current language is $current, asking
     * Moodle for the parent and the site language.
     *
     * @param string $current The reader's current language.
     * @return string[]
     */
    public static function chain_for(string $current): array {
        global $CFG;
        $parent = $current !== '' ? (string) get_parent_language($current) : '';
        return self::preference_chain($current, $parent, (string) $CFG->lang);
    }

    /**
     * The Starter chain: the site language, nothing about the reader.
     *
     * @return string[]
     */
    public static function site_chain(): array {
        global $CFG;
        return self::preference_chain((string) $CFG->lang, '', (string) $CFG->lang);
    }

    /**
     * Whether a code names a language pack installed on this site, disabled
     * packs included.
     *
     * @param string $code
     * @return bool
     */
    public static function is_installed(string $code): bool {
        return $code !== '' && get_string_manager()->translation_exists($code, true);
    }

    /**
     * The localised name of a language, for people.
     *
     * Installed packs — disabled ones too, since content written under a
     * pack that was disabled later must still be named — get their own name
     * (`Português - Brasil`). A code with no pack installed any more gets the
     * code itself, upper-cased, `pt_br` shown as `PT-BR`: not pretty, but
     * true, and the row it labels still opens.
     *
     * @param string $code
     * @return string Plain text, not escaped.
     */
    public static function display_name(string $code): string {
        if (!self::is_installed($code)) {
            return self::badge($code);
        }
        // The pack's own name for itself, as core's language menu reads it —
        // without the "(code)" core appends there, which a badge already says.
        return (string) get_string_manager()->get_string('thislanguage', 'langconfig', null, $code);
    }

    /**
     * The short form of a code for a badge: `EN`, `PT-BR`.
     *
     * @param string $code
     * @return string
     */
    public static function badge(string $code): string {
        return strtoupper(str_replace('_', '-', $code));
    }

    /**
     * The value for an HTML `lang` attribute.
     *
     * Core's own conversion for an installed pack (it reads the pack's ISO
     * code); for a code whose pack is gone, the code with `_` as `-`, which
     * is what BCP 47 would expect of `pt_br` anyway.
     *
     * @param string $code
     * @return string
     */
    public static function html_lang(string $code): string {
        if (self::is_installed($code)) {
            return get_html_lang_attribute_value($code);
        }
        return strtolower(str_replace('_', '-', $code));
    }

    /**
     * Every installed, enabled pack: code => localised name, for a select.
     *
     * @return array<string, string>
     */
    public static function installed_languages(): array {
        $languages = [];
        foreach (array_keys((array) get_string_manager()->get_list_of_translations(false)) as $code) {
            $languages[$code] = self::display_name((string) $code);
        }
        return $languages;
    }
}
