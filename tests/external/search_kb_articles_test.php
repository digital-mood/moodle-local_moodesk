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
 * Tests for the suggested-articles endpoint.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\external;

use local_moodesk\service\edition_service;
use local_moodesk\service\kb_service;

/**
 * Tests for search_kb_articles.
 *
 * The endpoint hands articles to whoever is typing a ticket, so what it
 * withholds — drafts, archived articles, agents-only articles from a
 * requester — is asserted, not assumed. Its gate is the knowledge base's
 * (:viewkb), and one test holds it to that: a user who cannot open a ticket
 * but may read the knowledge base is served.
 *
 * @covers \local_moodesk\external\search_kb_articles
 */
final class search_kb_articles_test extends \advanced_testcase {
    /** @var \testing_data_generator */
    private $generator;

    /** @var int A plain authenticated user: :viewkb from the user archetype, :submit too. */
    private int $requester;

    /** @var int A user holding :agent. */
    private int $agent;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_moodesk');
        $this->requester = (int) $this->getDataGenerator()->create_user()->id;
        $this->agent     = $this->user_holding(['local/moodesk:agent', 'local/moodesk:viewall', 'local/moodesk:reply']);

        $this->setUser($this->requester);
    }

    protected function tearDown(): void {
        edition_service::set_edition_for_testing(null);
        parent::tearDown();
    }

    /**
     * A user holding the capabilities named, on top of the user archetype.
     *
     * @param string[] $capabilities
     * @return int userid
     */
    private function user_holding(array $capabilities): int {
        $context = \context_system::instance();
        $user    = $this->getDataGenerator()->create_user();
        $roleid  = $this->getDataGenerator()->create_role();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        }
        role_assign($roleid, $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        return (int) $user->id;
    }

    /**
     * A category row.
     *
     * @param string $name
     * @param int    $visible
     * @return int
     */
    private function category(string $name, int $visible = 1): int {
        global $DB;
        return (int) $DB->insert_record('local_moodesk_category', (object) [
            'name'         => $name,
            'sortorder'    => 0,
            'visible'      => $visible,
            'timecreated'  => time(),
            'usermodified' => 0,
        ]);
    }

    /**
     * Call the endpoint the way the web service layer does, so the return
     * structure is validated too.
     *
     * @param string $subject
     * @param int    $categoryid
     * @return array
     */
    private function call(string $subject, int $categoryid = 0): array {
        return search_kb_articles::clean_returnvalue(
            search_kb_articles::execute_returns(),
            search_kb_articles::execute($subject, $categoryid)
        );
    }

    public function test_it_returns_what_a_suggestion_needs_and_nothing_else(): void {
        $access = $this->category('Access & accounts');
        $wanted = $this->generator->create_kb_article([
            'title'      => 'How to reset your password',
            'body'       => '<p>Open the login page and use the <b>Forgotten password</b> link to reset it.</p>',
            'status'     => 'published',
            'categoryid' => $access,
        ]);
        $this->generator->create_kb_article(['title' => 'Printer jams', 'status' => 'published']);

        $rows = $this->call('password reset');

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame(
            ['id', 'title', 'excerpt', 'categoryname', 'url', 'lang', 'langlabel'],
            array_keys($row)
        );
        $this->assertSame('en', $row['lang']);
        $this->assertSame('', $row['langlabel'], 'a row in the viewer\'s language carries no label');
        $this->assertSame((int) $wanted->id, $row['id']);
        $this->assertSame('How to reset your password', $row['title']);
        $this->assertStringContainsString('Forgotten password link', $row['excerpt']);
        $this->assertStringNotContainsString('<', $row['excerpt']);
        $this->assertSame('Access & accounts', $row['categoryname']);
        $this->assertStringContainsString('/local/moodesk/kb_article.php?id=' . $wanted->id, $row['url']);
    }

    public function test_short_subjects_do_not_hit_the_database(): void {
        $this->generator->create_kb_article(['title' => 'Printer jams', 'status' => 'published']);

        $this->assertSame([], $this->call('Pr'));
        $this->assertSame([], $this->call('  P  '));
        $this->assertSame([], $this->call(''));
    }

    public function test_results_are_capped_at_the_service_limit(): void {
        for ($i = 0; $i < kb_service::SUGGEST_LIMIT + 3; $i++) {
            $this->generator->create_kb_article(['title' => "Printer fault $i", 'status' => 'published']);
        }

        $this->assertCount(kb_service::SUGGEST_LIMIT, $this->call('printer fault'));
    }

    public function test_a_draft_is_never_offered_even_to_its_editor(): void {
        $editor = $this->user_holding(['local/moodesk:editkb']);
        $this->setUser($editor);

        $live = $this->generator->create_kb_article(['title' => 'Wifi is live', 'status' => 'published']);
        $this->generator->create_kb_article(['title' => 'Wifi is a draft', 'status' => 'draft', 'createdby' => $editor]);
        $this->generator->create_kb_article(['title' => 'Wifi is archived', 'status' => 'archived']);

        $rows = $this->call('wifi');

        $this->assertSame([(int) $live->id], array_column($rows, 'id'));
    }

    public function test_agents_only_articles_reach_agents_and_not_requesters(): void {
        $public   = $this->generator->create_kb_article(['title' => 'VPN for everyone', 'status' => 'published']);
        $internal = $this->generator->create_kb_article(
            ['title' => 'VPN for agents', 'status' => 'published', 'audience' => 'agents']
        );

        $this->assertSame([(int) $public->id], array_column($this->call('vpn'), 'id'));

        $this->setUser($this->agent);
        $this->assertEqualsCanonicalizing(
            [(int) $public->id, (int) $internal->id],
            array_column($this->call('vpn'), 'id')
        );
    }

    public function test_the_category_puts_its_articles_first(): void {
        $access  = $this->category('Access');
        $general = $this->category('General');
        $live    = ['status' => 'published', 'body' => '<p>x</p>'];
        $filed   = $this->generator->create_kb_article(
            ['title' => 'Login in Access', 'categoryid' => $access, 'timepublished' => 100] + $live
        );
        $newer   = $this->generator->create_kb_article(
            ['title' => 'Login in General', 'categoryid' => $general, 'timepublished' => 300] + $live
        );

        $this->assertSame([(int) $newer->id, (int) $filed->id], array_column($this->call('login'), 'id'));
        $this->assertSame([(int) $filed->id, (int) $newer->id], array_column($this->call('login', $access), 'id'));
        // An id the form could not have offered is the same as none.
        $this->assertSame([(int) $newer->id, (int) $filed->id], array_column($this->call('login', 999999), 'id'));
    }

    public function test_it_is_closed_without_viewkb(): void {
        $context = \context_system::instance();
        $roleid  = $this->getDataGenerator()->create_role();
        assign_capability('local/moodesk:viewkb', CAP_PROHIBIT, $roleid, $context->id, true);
        role_assign($roleid, $this->requester, $context->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->generator->create_kb_article(['title' => 'Printer jams', 'status' => 'published']);

        $this->expectException(\required_capability_exception::class);
        search_kb_articles::execute('printer');
    }

    public function test_it_does_not_ask_for_the_ticket_form_capability(): void {
        // The contract is the knowledge base's: someone who may read it but
        // may not open a ticket is served, exactly as kb.php would serve them.
        $context = \context_system::instance();
        $roleid  = $this->getDataGenerator()->create_role();
        assign_capability('local/moodesk:submit', CAP_PROHIBIT, $roleid, $context->id, true);
        role_assign($roleid, $this->requester, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(has_capability('local/moodesk:submit', $context, $this->requester));

        $live = $this->generator->create_kb_article(['title' => 'Printer jams', 'status' => 'published']);

        $this->assertSame([(int) $live->id], array_column($this->call('printer'), 'id'));
    }

    /* ----------------------------------------------------------------------
     * Languages (KB-06).
     * -------------------------------------------------------------------- */

    /**
     * An ES+EN group: the ES requester gets one item, the ES row, no label;
     * with the ES row a draft, the EN row with its name as the label.
     */
    public function test_one_item_per_group_in_the_requesters_language_or_labelled(): void {
        global $SESSION;
        $this->generator->create_language_pack(['code' => 'es', 'name' => 'Español']);
        edition_service::set_edition_for_testing(edition_service::EDITION_PRO);
        $SESSION->lang = 'es';

        $en = $this->generator->create_kb_article([
            'title' => 'Password reset', 'body' => '<p>Reset it.</p>', 'status' => 'published', 'lang' => 'en',
        ]);
        $es = $this->generator->create_kb_article([
            'title' => 'Restablecer la contraseña', 'body' => '<p>password reset</p>', 'status' => 'published',
            'lang' => 'es', 'groupid' => (int) $en->groupid,
        ]);

        $rows = $this->call('password reset');
        $this->assertCount(1, $rows);
        $this->assertSame((int) $es->id, $rows[0]['id']);
        $this->assertSame('es', $rows[0]['lang']);
        $this->assertSame('', $rows[0]['langlabel']);

        $this->generator->create_kb_article([
            'title' => 'Password policy', 'body' => '<p>Eight characters.</p>', 'status' => 'published', 'lang' => 'en',
        ]);
        $rows = $this->call('password');
        $this->assertCount(2, $rows);
        $labels = array_column($rows, 'langlabel', 'id');
        unset($labels[(int) $es->id]);
        $this->assertSame(['English'], array_values($labels), 'the EN-only article is labelled for the ES reader');

        // Starter: same group, one item, the site-language row, and no label — nothing on screen talks about languages.
        edition_service::set_edition_for_testing(edition_service::EDITION_STARTER);
        $rows = $this->call('password reset');
        $this->assertCount(1, $rows);
        $this->assertSame((int) $en->id, $rows[0]['id']);
        $this->assertSame('en', $rows[0]['lang']);
        $this->assertSame('', $rows[0]['langlabel']);
        edition_service::set_edition_for_testing(null);
    }

    public function test_a_switched_off_knowledge_base_suggests_nothing(): void {
        $this->generator->create_kb_article(['title' => 'Printer jams', 'status' => 'published']);
        set_config('kb_enabled', 0, 'local_moodesk');

        $this->assertSame([], $this->call('printer'));
    }
}
