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
use local_moodesk\service\edition_service;

/**
 * The knowledge base landing: what a reader is offered, in every shape.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_moodesk\output\knowledge_page
 */
final class knowledge_page_test extends \advanced_testcase {
    /** @var \stdClass A requester: :viewkb through the authenticated-user role. */
    private \stdClass $reader;

    /** @var \stdClass A manager: :managekb, so :editkb too. */
    private \stdClass $manager;

    /** @var int An active category. */
    private int $access;

    /** @var int A category archived after articles were filed under it. */
    private int $legacy;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $gen = $this->getDataGenerator();
        $this->reader  = $gen->create_user();
        $this->manager = $gen->create_user();
        $managerrole   = $DB->get_field('role', 'id', ['shortname' => 'manager']);
        role_assign($managerrole, $this->manager->id, \context_system::instance()->id);

        $now = time();
        $this->access = $DB->insert_record('local_moodesk_category', (object) [
            'name' => 'Access & accounts', 'sortorder' => 5, 'visible' => 1, 'timecreated' => $now, 'usermodified' => 2,
        ]);
        $this->legacy = $DB->insert_record('local_moodesk_category', (object) [
            'name' => 'Legacy', 'sortorder' => 9, 'visible' => 1, 'timecreated' => $now, 'usermodified' => 2,
        ]);
        $DB->insert_record('local_moodesk_category', (object) [
            'name' => 'Empty shelf', 'sortorder' => 1, 'visible' => 1, 'timecreated' => $now, 'usermodified' => 2,
        ]);

        $kb = $gen->get_plugin_generator('local_moodesk');
        $kb->create_kb_article(['title' => 'Reset your password', 'body' => '<p>Click Forgot password on the login page.</p>',
            'status' => 'published', 'categoryid' => $this->access, 'timepublished' => $now - 10]);
        $kb->create_kb_article(['title' => 'Password policy', 'body' => '<p>Eight characters and a symbol.</p>',
            'status' => 'published', 'categoryid' => $this->access, 'timepublished' => $now - 20]);
        $kb->create_kb_article(['title' => 'Legacy VPN client', 'body' => '<p>Still supported for staff laptops.</p>',
            'status' => 'published', 'categoryid' => $this->legacy, 'timepublished' => $now - 30]);
        $kb->create_kb_article(['title' => 'Changing the site language', 'body' => '<p>A preference, not a setting.</p>',
            'status' => 'published', 'timepublished' => $now - 40]);
        $kb->create_kb_article(['title' => 'Escalation matrix', 'body' => '<p>Call the on-call number.</p>',
            'status' => 'published', 'audience' => 'agents', 'categoryid' => $this->access, 'timepublished' => $now - 50]);
        $kb->create_kb_article(['title' => 'Draft about passwords', 'body' => '<p>Not yet.</p>', 'status' => 'draft']);

        // Archive Legacy after its article was filed there.
        $DB->set_field('local_moodesk_category', 'visible', 0, ['id' => $this->legacy]);
    }

    protected function tearDown(): void {
        edition_service::set_edition_for_testing(null);
        parent::tearDown();
    }

    /**
     * Build the landing for an actor.
     *
     * @param \stdClass $actor
     * @param string    $q
     * @param string    $category
     * @param int       $page
     * @return array
     */
    private function landing(\stdClass $actor, string $q = '', string $category = '', int $page = 0): array {
        global $SESSION;
        $this->setUser($actor);
        if ($this->readinglang !== '') {
            // After setUser(): it starts a fresh session.
            $SESSION->lang = $this->readinglang;
        }
        return knowledge_page::create((int) $actor->id, $q, $category, $page)->export();
    }

    /** @var string The language the reader reads in, applied by landing(). */
    private string $readinglang = '';

    /**
     * Titles of the cards, in order.
     *
     * @param array $ctx
     * @return string[]
     */
    private function titles(array $ctx): array {
        return array_map(static fn(array $c): string => html_entity_decode(strip_tags($c['titlehtml'])), $ctx['articles']);
    }

    /**
     * A requester browses what a requester may read; a manager browses the same
     * shelf — never a draft, whatever they may open elsewhere.
     */
    public function test_browse_lists_published_only_for_everyone(): void {
        $reader = $this->landing($this->reader);
        $this->assertFalse($reader['issearch']);
        $this->assertTrue($reader['hasrows']);
        $this->assertSame(
            ['Reset your password', 'Password policy', 'Legacy VPN client', 'Changing the site language'],
            $this->titles($reader),
            'Newest publication first; the agents-only article is not a requester\'s.'
        );
        $this->assertSame(4, $reader['headingcount']);

        $manager = $this->landing($this->manager);
        $this->assertContains('Escalation matrix', $this->titles($manager), 'A manager may read agents-only articles.');
        $this->assertNotContains('Draft about passwords', $this->titles($manager), 'A reading surface never lists a draft.');
        $this->assertSame(5, $manager['headingcount']);
    }

    /**
     * The category navigation: only categories with something in them, in
     * their own order, an archived one under its name, "No category" last.
     */
    public function test_category_nav(): void {
        $ctx = $this->landing($this->reader);
        $nav = $ctx['categorynav'];

        $this->assertTrue($nav['all']['active']);
        $this->assertSame(4, $nav['all']['count']);
        $this->assertSame(
            ['Access & accounts', 'Legacy', 'No category'],
            array_column($nav['items'], 'label'),
            'Empty shelf is not offered; the archived category is, under its name; "No category" last.'
        );
        $this->assertSame([2, 1, 1], array_column($nav['items'], 'count'));
        $this->assertStringEndsWith('kb.php?category=none', $nav['items'][2]['url']);
        $this->assertSame('1 articles', $nav['items'][2]['countsr']);
        $this->assertFalse($nav['dimmed']);
    }

    /**
     * Selecting a category: an archived one still lists its article; "No
     * category" lists the uncategorised; an unknown id falls back to all; an existing
     * empty one is the "nothing in this category" state.
     */
    public function test_browse_by_category(): void {
        global $DB;

        $legacy = $this->landing($this->reader, '', (string) $this->legacy);
        $this->assertSame('Legacy', $legacy['heading']);
        $this->assertSame(['Legacy VPN client'], $this->titles($legacy));
        $this->assertTrue($legacy['categorynav']['items'][1]['active']);
        $this->assertStringNotContainsString('categoryid=', $legacy['cta']['url'], 'An archived category is not preselected.');

        $access = $this->landing($this->reader, '', (string) $this->access);
        $this->assertStringEndsWith('submit.php?categoryid=' . $this->access, $access['cta']['url']);

        $general = $this->landing($this->reader, '', 'none');
        $this->assertSame('No category', $general['heading']);
        $this->assertSame(['Changing the site language'], $this->titles($general));

        $bogus = $this->landing($this->reader, '', '999999');
        $this->assertSame('All articles', $bogus['heading']);
        $this->assertTrue($bogus['categorynav']['all']['active']);

        $emptyid = $DB->get_field('local_moodesk_category', 'id', ['name' => 'Empty shelf']);
        $empty = $this->landing($this->reader, '', (string) $emptyid);
        $this->assertSame('Empty shelf', $empty['heading']);
        $this->assertTrue($empty['emptycategory']);
        $this->assertFalse($empty['hasrows']);
        $this->assertFalse($empty['emptynone']);
    }

    /**
     * Search is global, marks its terms, says how many, and clears to the landing.
     */
    public function test_search(): void {
        $ctx = $this->landing($this->reader, 'password', (string) $this->legacy);

        $this->assertTrue($ctx['issearch']);
        $this->assertSame(
            ['Reset your password', 'Password policy'],
            $this->titles($ctx),
            'Global: the selected category did not narrow the results; the draft is absent.'
        );
        $this->assertSame('2 results for “password”', $ctx['heading']);
        $this->assertStringContainsString('<mark>password</mark>', $ctx['articles'][0]['titlehtml']);
        $this->assertStringContainsString('<mark>password</mark>', $ctx['articles'][0]['excerpthtml']);
        $this->assertFalse($ctx['hasmore']);
        $this->assertStringEndsWith('/kb.php', $ctx['clearurl']);
        $this->assertTrue($ctx['categorynav']['dimmed']);
        $this->assertFalse($ctx['categorynav']['all']['active'], 'Nothing is current while a search shows.');
        foreach ($ctx['categorynav']['items'] as $item) {
            $this->assertFalse($item['active']);
        }
    }

    /**
     * Fewer than three usable characters is not a search: the browse view
     * with a hint under the field, the typed text kept.
     */
    public function test_short_query_hints_and_browses(): void {
        $ctx = $this->landing($this->reader, 'ab');
        $this->assertFalse($ctx['issearch']);
        $this->assertTrue($ctx['hasrows']);
        $this->assertSame('ab', $ctx['searchbox']['value']);
        $this->assertStringContainsString('at least 3 characters', $ctx['searchbox']['hint']);
        $this->assertSame('mhd-kb-search-hint', $ctx['searchbox']['hintid']);

        $ok = $this->landing($this->reader, 'password');
        $this->assertArrayNotHasKey('hint', $ok['searchbox']);
    }

    /**
     * No match: the "nothing matches" state, and the raw query escaped by the
     * template, never here.
     */
    public function test_search_without_results(): void {
        $ctx = $this->landing($this->reader, 'xylophonic <b>');
        $this->assertTrue($ctx['issearch']);
        $this->assertTrue($ctx['emptyresults']);
        $this->assertFalse($ctx['hasrows']);
        $this->assertSame('No articles match “xylophonic <b>”', $ctx['heading'], 'Plain text; the template escapes it.');
    }

    /**
     * More than RESULT_LIMIT matches: the best RESULT_LIMIT, and a flag.
     */
    public function test_search_is_bounded(): void {
        $kb = $this->getDataGenerator()->get_plugin_generator('local_moodesk');
        for ($i = 1; $i <= knowledge_page::RESULT_LIMIT + 3; $i++) {
            $kb->create_kb_article(['title' => "Printer jam $i", 'body' => '<p>Open the tray.</p>', 'status' => 'published']);
        }
        $ctx = $this->landing($this->reader, 'printer');
        $this->assertCount(knowledge_page::RESULT_LIMIT, $ctx['articles']);
        $this->assertTrue($ctx['hasmore']);
        $this->assertSame('Best matches for “printer”', $ctx['heading']);
        $this->assertStringContainsString((string) knowledge_page::RESULT_LIMIT, $ctx['morehint']);
    }

    /**
     * Browse paginates; the category rides along.
     */
    public function test_browse_paginates(): void {
        $kb = $this->getDataGenerator()->get_plugin_generator('local_moodesk');
        for ($i = 1; $i <= knowledge_page::PAGE_SIZE + 2; $i++) {
            $kb->create_kb_article([
                'title' => "Filler $i", 'body' => '<p>x</p>', 'status' => 'published', 'categoryid' => $this->access,
            ]);
        }
        $ctx = $this->landing($this->reader, '', (string) $this->access);
        $this->assertCount(knowledge_page::PAGE_SIZE, $ctx['articles']);
        $this->assertStringContainsString('category=' . $this->access, $ctx['paginationbar']);
        $this->assertStringContainsString('page=1', $ctx['paginationbar']);

        $page2 = $this->landing($this->reader, '', (string) $this->access, 1);
        $this->assertCount(4, $page2['articles']);
    }

    /**
     * An empty knowledge base: the "nothing here yet" state; the pen for a writer only.
     */
    public function test_empty_knowledge_base(): void {
        global $DB;
        $DB->delete_records('local_moodesk_kb_article');

        $reader = $this->landing($this->reader);
        $this->assertFalse($reader['hasarticles']);
        $this->assertTrue($reader['emptynone']);
        $this->assertFalse($reader['canedit']);

        $manager = $this->landing($this->manager);
        $this->assertTrue($manager['emptynone']);
        $this->assertTrue($manager['canedit']);
    }

    /**
     * The template renders the three shapes without a missing string, and
     * category names with an ampersand come out once-escaped.
     */
    /* ----------------------------------------------------------------------
     * Language (KB-06).
     * -------------------------------------------------------------------- */

    /**
     * One card per article whatever its translations: the reader's language
     * when it exists, badged fallback when it does not; counts are groups;
     * search finds a group through any translation and shows the one that
     * matched. Starter: one card too, site language, no badge.
     */
    public function test_one_card_per_group_in_the_readers_language_or_badged(): void {
        global $DB, $OUTPUT;
        $kb = $this->getDataGenerator()->get_plugin_generator('local_moodesk');
        $kb->create_language_pack(['code' => 'es', 'name' => 'Español', 'iso' => 'es']);
        edition_service::set_edition_for_testing(edition_service::EDITION_PRO);

        $reset = $DB->get_record('local_moodesk_kb_article', ['title' => 'Reset your password']);
        $es = $kb->create_kb_article([
            'title' => 'Restablecer la contraseña', 'body' => '<p>Haz clic en Olvidé mi contraseña.</p>',
            'status' => 'published', 'lang' => 'es', 'groupid' => (int) $reset->groupid, 'categoryid' => $this->access,
        ]);

        $this->readinglang = 'es';
        $ctx = $this->landing($this->reader);
        $this->assertContains('Restablecer la contraseña', $this->titles($ctx));
        $this->assertNotContains('Reset your password', $this->titles($ctx));
        $this->assertSame(4, $ctx['headingcount'], 'four groups, not five rows');
        $this->assertSame(4, $ctx['categorynav']['all']['count']);
        $badges = array_column($ctx['articles'], 'langbadge', 'id');
        $this->assertNull($badges[(int) $es->id]);
        $policy = $DB->get_field('local_moodesk_kb_article', 'id', ['title' => 'Password policy']);
        $this->assertSame(['code' => 'EN', 'name' => 'English', 'htmllang' => 'en'], $badges[(int) $policy]);

        $html = $OUTPUT->render_from_template('local_moodesk/kb_landing', $ctx);
        $this->assertStringContainsString(
            '<article class="mhd-kb-card__body" aria-labelledby="mhd-kb-card-' . $policy . '" lang="en">',
            $html
        );
        $this->assertStringContainsString(
            '<span aria-hidden="true">EN</span><span class="mhd-sr-only" lang="en">English</span>',
            $html
        );
        $this->assertStringContainsString(
            '<article class="mhd-kb-card__body" aria-labelledby="mhd-kb-card-' . $es->id . '">',
            $html
        );

        // Search: "password" is in both rows of the group → one result, the ES one for the ES reader.
        $ctx = $this->landing($this->reader, 'contraseña');
        $this->assertSame(['Restablecer la contraseña'], $this->titles($ctx));
        $ctx = $this->landing($this->reader, 'forgot');
        $this->assertSame(['Reset your password'], $this->titles($ctx), 'only the EN row matched, so EN is shown');
        $this->assertSame('EN', $ctx['articles'][0]['langbadge']['code']);

        // Starter: still one card, the site-language row, and no badge on anything.
        edition_service::set_edition_for_testing(edition_service::EDITION_STARTER);
        $ctx = $this->landing($this->reader);
        $this->assertContains('Reset your password', $this->titles($ctx));
        $this->assertNotContains('Restablecer la contraseña', $this->titles($ctx));
        $this->assertSame(4, $ctx['headingcount']);
        $this->assertSame([null], array_unique(array_column($ctx['articles'], 'langbadge')));
        edition_service::set_edition_for_testing(null);
    }

    public function test_template_renders(): void {
        global $OUTPUT;

        foreach ([['', ''], ['password', ''], ['xylophonic', ''], ['', 'none']] as [$q, $cat]) {
            $ctx = $this->landing($this->reader, $q, $cat);
            $ctx['pageheader'] = knowledge_page::create((int) $this->reader->id, $q, $cat)->header()->to_context();
            $html = $OUTPUT->render_from_template('local_moodesk/kb_landing', $ctx);
            $this->assertStringContainsString('role="search"', $html);
            $this->assertStringContainsString('aria-label="Categories"', $html);
            $this->assertStringContainsString('Access &amp; accounts', $html);
            $this->assertStringNotContainsString('&amp;amp;', $html, 'Escaped once, not twice.');
            $this->assertStringContainsString('Open a ticket', $html);
        }

        $search = $this->landing($this->reader, 'password');
        $search['pageheader'] = knowledge_page::create((int) $this->reader->id, 'password')->header()->to_context();
        $html = $OUTPUT->render_from_template('local_moodesk/kb_landing', $search);
        $this->assertStringContainsString('<mark>password</mark>', $html);
        $this->assertStringContainsString('Clear search', $html);
        $this->assertStringNotContainsString('aria-current="page"', $html, 'Nothing current in the nav while searching.');
    }
}
