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

/**
 * Tests for kb_language: the chain, and the names and attributes it shows.
 *
 * The display helpers need a language pack to name, and the test site has
 * only `en`; the plugin generator fakes one under the dataroot the way core's
 * own tests do, and the site reset removes it.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_moodesk\domain\kb_language
 */
final class kb_language_test extends \advanced_testcase {
    /* ----------------------------------------------------------------------
     * The chain (pure).
     * -------------------------------------------------------------------- */

    public function test_chain_is_current_then_parent_then_site(): void {
        $this->assertSame(['es_mx', 'es', 'en'], kb_language::preference_chain('es_mx', 'es', 'en'));
    }

    public function test_chain_skips_an_empty_parent(): void {
        $this->assertSame(['pt_br', 'en'], kb_language::preference_chain('pt_br', '', 'en'));
    }

    public function test_chain_removes_duplicates_and_keeps_order(): void {
        // Current is the site language: one entry, not two.
        $this->assertSame(['en'], kb_language::preference_chain('en', '', 'en'));
        // Parent is the site language: it keeps the parent's position.
        $this->assertSame(['es_mx', 'es'], kb_language::preference_chain('es_mx', 'es', 'es'));
        // Current equals its own "parent" (a broken pack): once.
        $this->assertSame(['es', 'en'], kb_language::preference_chain('es', 'es', 'en'));
    }

    public function test_chain_is_never_empty(): void {
        $this->assertSame(['en'], kb_language::preference_chain('', '', ''));
        $this->assertSame(['fr'], kb_language::preference_chain(' ', '', 'fr'));
    }

    /* ----------------------------------------------------------------------
     * Asking Moodle.
     * -------------------------------------------------------------------- */

    public function test_chain_for_reads_the_parent_moodle_declares(): void {
        global $CFG;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_moodesk');
        $generator->create_language_pack(['code' => 'es', 'name' => 'Español']);
        $generator->create_language_pack(['code' => 'es_mx', 'name' => 'Español - México', 'parent' => 'es']);
        $generator->create_language_pack(['code' => 'pt_br', 'name' => 'Português - Brasil']);

        $CFG->lang = 'en';
        $this->assertSame(['es_mx', 'es', 'en'], kb_language::chain_for('es_mx'));
        // Moodle declares no parent for pt_br, and no prefix heuristic invents one.
        $this->assertSame(['pt_br', 'en'], kb_language::chain_for('pt_br'));
        $this->assertSame(['en'], kb_language::chain_for('en'));

        $CFG->lang = 'es';
        $this->assertSame(['es'], kb_language::site_chain());
        $this->assertSame(['pt_br', 'es'], kb_language::chain_for('pt_br'));
    }

    public function test_names_badges_and_html_lang(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_moodesk');
        $generator->create_language_pack(['code' => 'pt_br', 'name' => 'Português - Brasil', 'iso' => 'pt']);

        $this->assertTrue(kb_language::is_installed('pt_br'));
        $this->assertTrue(kb_language::is_installed('en'));
        $this->assertFalse(kb_language::is_installed('xx_gone'));

        $this->assertSame('Português - Brasil', kb_language::display_name('pt_br'));
        $this->assertSame('English', kb_language::display_name('en'));
        // A pack that is no longer installed: the code, honestly, not a guess.
        $this->assertSame('XX-GONE', kb_language::display_name('xx_gone'));

        $this->assertSame('PT-BR', kb_language::badge('pt_br'));
        $this->assertSame('EN', kb_language::badge('en'));

        $this->assertSame('pt', kb_language::html_lang('pt_br'));
        $this->assertSame('en', kb_language::html_lang('en'));
        $this->assertSame('xx-gone', kb_language::html_lang('xx_gone'));

        $installed = kb_language::installed_languages();
        $this->assertSame('Português - Brasil', $installed['pt_br']);
        $this->assertSame('English', $installed['en']);
    }

    public function test_a_disabled_pack_still_has_a_name(): void {
        global $CFG;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_moodesk');
        $generator->create_language_pack(['code' => 'es', 'name' => 'Español']);

        // The enabled list is read when the string manager is built, so it
        // is rebuilt here — and rebuilt again after, since the singleton
        // outlives the CFG the site reset restores.
        $CFG->langlist = 'en';
        get_string_manager(true);
        try {
            $this->assertArrayNotHasKey('es', kb_language::installed_languages());
            $this->assertArrayHasKey('en', kb_language::installed_languages());
            $this->assertSame('Español', kb_language::display_name('es'));
            $this->assertTrue(kb_language::is_installed('es'));
        } finally {
            unset($CFG->langlist);
            get_string_manager(true);
        }
    }
}
