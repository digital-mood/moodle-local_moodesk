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
 * Unit tests for the knowledge base article repository.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\repository;

use local_moodesk\domain\kb_article;
use local_moodesk\policy\kb_access_policy;

/**
 * Tests for kb_article_repository.
 *
 * Scope-to-SQL is the part that matters: the policy test proves the scopes
 * agree with can_view(), this file proves the filters, the counts and the
 * search stay inside whatever scope they were given.
 *
 * @covers \local_moodesk\repository\kb_article_repository
 */
final class kb_article_repository_test extends \advanced_testcase {
    /** @var kb_article_repository */
    private kb_article_repository $repo;

    /** @var \testing_data_generator */
    private $generator;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->repo      = new kb_article_repository();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_moodesk');
    }

    /**
     * A category row, straight into the table.
     *
     * @param string $name
     * @return int
     */
    private function category(string $name): int {
        global $DB;
        return (int) $DB->insert_record('local_moodesk_category', (object) [
            'name'         => $name,
            'sortorder'    => 0,
            'visible'      => 1,
            'timecreated'  => time(),
            'usermodified' => 0,
        ]);
    }

    /**
     * A freshly inserted row is its own group.
     */
    public function test_insert_sets_groupid_to_the_new_id(): void {
        global $DB;
        $now = time();
        $id  = $this->repo->insert((object) [
            'lang'         => 'en',
            'title'        => 'One',
            'body'         => '<p>one</p>',
            'bodyformat'   => FORMAT_HTML,
            'searchtext'   => 'one',
            'status'       => kb_article::STATUS_DRAFT,
            'audience'     => kb_article::AUDIENCE_EVERYONE,
            'createdby'    => 2,
            'usermodified' => 2,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);

        $row = $this->repo->get_by_id($id);
        $this->assertSame($id, (int) $row->groupid);
        $this->assertSame(0, $DB->count_records('local_moodesk_kb_article', ['groupid' => 0]));
    }

    /**
     * A row inserted with a groupid keeps it — that is how a translation
     * joins its group later, and the repository must not overwrite it.
     */
    public function test_insert_keeps_an_explicit_groupid(): void {
        $now = time();
        $id  = $this->repo->insert((object) [
            'groupid'      => 42,
            'lang'         => 'es',
            'title'        => 'Uno',
            'body'         => '<p>uno</p>',
            'bodyformat'   => FORMAT_HTML,
            'searchtext'   => 'uno',
            'status'       => kb_article::STATUS_DRAFT,
            'audience'     => kb_article::AUDIENCE_EVERYONE,
            'createdby'    => 2,
            'usermodified' => 2,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);
        $this->assertSame(42, (int) $this->repo->get_by_id($id)->groupid);
    }

    /**
     * get_by_id is unscoped: a draft comes back so the policy can refuse it.
     */
    public function test_get_by_id_returns_drafts_and_null_for_missing(): void {
        $draft = $this->generator->create_kb_article(['status' => 'draft']);
        $this->assertNotNull($this->repo->get_by_id((int) $draft->id));
        $this->assertNull($this->repo->get_by_id((int) $draft->id + 1000));
    }

    /**
     * A filter narrows a scope; it never widens one.
     */
    public function test_a_status_filter_cannot_widen_a_scope(): void {
        $this->generator->create_kb_article(['status' => 'draft']);
        $this->generator->create_kb_article(['status' => 'published']);

        $public = $this->repo->list(kb_access_policy::SCOPE_PUBLIC, ['status' => kb_article::STATUS_DRAFT]);
        $this->assertSame([], $public, 'Asking SCOPE_PUBLIC for drafts must return nothing.');

        $all = $this->repo->list(kb_access_policy::SCOPE_ALL, ['status' => kb_article::STATUS_DRAFT]);
        $this->assertCount(1, $all);
    }

    /**
     * Category and language filters, including "no category" as 0.
     */
    public function test_category_and_lang_filters(): void {
        $billing = $this->category('Billing');
        $this->generator->create_kb_article(['status' => 'published', 'categoryid' => $billing, 'lang' => 'en']);
        $this->generator->create_kb_article(['status' => 'published', 'categoryid' => $billing, 'lang' => 'es']);
        $this->generator->create_kb_article(['status' => 'published', 'lang' => 'en']);

        $this->assertCount(2, $this->repo->list(kb_access_policy::SCOPE_PUBLIC, ['categoryid' => $billing]));
        $this->assertCount(1, $this->repo->list(kb_access_policy::SCOPE_PUBLIC, ['categoryid' => 0]));
        $this->assertCount(2, $this->repo->list(kb_access_policy::SCOPE_PUBLIC, ['lang' => 'en']));
        $this->assertCount(1, $this->repo->list(kb_access_policy::SCOPE_PUBLIC, ['categoryid' => $billing, 'lang' => 'es']));
        $this->assertSame(2, $this->repo->count(kb_access_policy::SCOPE_PUBLIC, ['lang' => 'en']));
    }

    /**
     * Counts per category respect the scope: a draft is not counted for a
     * reader, and an agents-only article is not counted for a requester.
     */
    public function test_count_by_category_stays_inside_the_scope(): void {
        $billing = $this->category('Billing');
        $this->generator->create_kb_article(['status' => 'published', 'categoryid' => $billing]);
        $this->generator->create_kb_article(['status' => 'published', 'categoryid' => $billing, 'audience' => 'agents']);
        $this->generator->create_kb_article(['status' => 'draft', 'categoryid' => $billing]);
        $this->generator->create_kb_article(['status' => 'published']);

        $sorted = function (int $scope): array {
            $counts = $this->repo->count_by_category($scope);
            ksort($counts);
            return $counts;
        };
        $this->assertSame([0 => 1, $billing => 1], $sorted(kb_access_policy::SCOPE_PUBLIC));
        $this->assertSame([0 => 1, $billing => 2], $sorted(kb_access_policy::SCOPE_AGENT));
        $this->assertSame([0 => 1, $billing => 3], $sorted(kb_access_policy::SCOPE_ALL));
        $this->assertSame([], $sorted(kb_access_policy::SCOPE_NONE));
    }

    /**
     * Sort orders: newest publication first for readers, newest edit first
     * for the manage list, title for a directory.
     */
    public function test_sort_orders(): void {
        $published = ['status' => 'published'];
        $a = $this->generator->create_kb_article(['title' => 'Bravo', 'timepublished' => 100, 'timemodified' => 300] + $published);
        $b = $this->generator->create_kb_article(['title' => 'Alpha', 'timepublished' => 200, 'timemodified' => 200] + $published);
        $c = $this->generator->create_kb_article(['title' => 'Chas', 'timepublished' => 300, 'timemodified' => 100] + $published);

        $ids = fn(array $rows) => array_values(array_map('intval', array_keys($rows)));
        $listed = fn(string $sort) => $ids($this->repo->list(kb_access_policy::SCOPE_ALL, [], $sort));
        $this->assertSame([(int) $c->id, (int) $b->id, (int) $a->id], $listed('timepublished'));
        $this->assertSame([(int) $a->id, (int) $b->id, (int) $c->id], $listed('timemodified'));
        $this->assertSame([(int) $b->id, (int) $a->id, (int) $c->id], $listed('title'));
        // An unknown sort falls back rather than reaching the SQL.
        $this->assertSame([(int) $a->id, (int) $b->id, (int) $c->id], $listed('id; DROP TABLE'));
    }

    /**
     * Limit and offset page through the list.
     */
    public function test_limit_and_offset(): void {
        for ($i = 1; $i <= 5; $i++) {
            $this->generator->create_kb_article(['title' => "T$i", 'status' => 'published', 'timepublished' => $i]);
        }
        $page = $this->repo->list(kb_access_policy::SCOPE_PUBLIC, [], 'timepublished', 2, 2);
        $this->assertSame(['T3', 'T2'], array_values(array_map(fn($r) => $r->title, $page)));
    }

    /**
     * Every token must match, in the title or in the search text.
     */
    public function test_search_requires_every_token(): void {
        $this->generator->create_kb_article([
            'title' => 'Reset your password', 'body' => '<p>Use the forgot link.</p>', 'status' => 'published',
        ]);
        $this->generator->create_kb_article([
            'title' => 'Enrol in a course', 'body' => '<p>Ask your password administrator.</p>', 'status' => 'published',
        ]);

        $both   = $this->repo->search(['password', 'forgot'], kb_access_policy::SCOPE_PUBLIC);
        $single = $this->repo->search(['password'], kb_access_policy::SCOPE_PUBLIC);
        $none   = $this->repo->search(['password', 'zebra'], kb_access_policy::SCOPE_PUBLIC);

        $this->assertCount(1, $both);
        $this->assertSame('Reset your password', reset($both)->title);
        $this->assertCount(2, $single);
        $this->assertSame([], $none);
    }

    /**
     * A token only in a tag never matches: the search text has no markup.
     */
    public function test_search_does_not_match_markup(): void {
        $this->generator->create_kb_article(['title' => 'Plain', 'body' => '<strong>bold</strong>', 'status' => 'published']);
        $this->assertSame([], $this->repo->search(['strong'], kb_access_policy::SCOPE_PUBLIC));
        $this->assertCount(1, $this->repo->search(['bold'], kb_access_policy::SCOPE_PUBLIC));
    }

    /**
     * LIKE wildcards in a token are literal characters.
     */
    public function test_search_escapes_wildcards(): void {
        $this->generator->create_kb_article(['title' => 'Discounts', 'body' => '<p>Save 100 percent</p>', 'status' => 'published']);
        $this->generator->create_kb_article(['title' => 'Literal', 'body' => '<p>The 100% sign</p>', 'status' => 'published']);

        $this->assertCount(2, $this->repo->search(['100'], kb_access_policy::SCOPE_PUBLIC));
        $this->assertCount(1, $this->repo->search(['100%'], kb_access_policy::SCOPE_PUBLIC));
        $this->assertSame([], $this->repo->search(['1_0'], kb_access_policy::SCOPE_PUBLIC));
    }

    /**
     * Search is case-insensitive and stays inside the scope.
     */
    public function test_search_is_case_insensitive_and_scoped(): void {
        $this->generator->create_kb_article(['title' => 'PASSWORD reset', 'status' => 'published']);
        $this->generator->create_kb_article(['title' => 'password internals', 'status' => 'published', 'audience' => 'agents']);
        $this->generator->create_kb_article(['title' => 'password draft', 'status' => 'draft']);

        $this->assertCount(1, $this->repo->search(['password'], kb_access_policy::SCOPE_PUBLIC));
        $this->assertCount(2, $this->repo->search(['password'], kb_access_policy::SCOPE_AGENT));
        $this->assertCount(3, $this->repo->search(['password'], kb_access_policy::SCOPE_ALL));
        $this->assertSame([], $this->repo->search(['password'], kb_access_policy::SCOPE_NONE));
    }

    /**
     * No tokens, no query — and no accidental "everything".
     */
    public function test_search_with_no_tokens_returns_nothing(): void {
        $this->generator->create_kb_article(['status' => 'published']);
        $this->assertSame([], $this->repo->search([], kb_access_policy::SCOPE_ALL));
    }

    /**
     * A scope the repository was not told about is treated as none.
     */
    public function test_an_unknown_scope_returns_nothing(): void {
        $this->generator->create_kb_article(['status' => 'published']);
        $this->assertSame([], $this->repo->list(99));
        $this->assertSame(0, $this->repo->count(99));
    }

    /**
     * update() touches only the fields it is given; delete() removes the row.
     */
    public function test_update_and_delete(): void {
        $article = $this->generator->create_kb_article(['title' => 'Before', 'body' => '<p>kept</p>']);
        $this->repo->update((object) ['id' => $article->id, 'title' => 'After']);

        $row = $this->repo->get_by_id((int) $article->id);
        $this->assertSame('After', $row->title);
        $this->assertSame('<p>kept</p>', $row->body);

        $this->repo->delete((int) $article->id);
        $this->assertNull($this->repo->get_by_id((int) $article->id));
    }

    /* ----------------------------------------------------------------------
     * Groups (KB-06).
     * -------------------------------------------------------------------- */

    /**
     * A group: the source row first, then one translation per code given,
     * all in one status and audience unless a row overrides it.
     *
     * @param string $title
     * @param string $status
     * @param array  $langs     source code first, e.g. ['en', 'es', 'pt_br'].
     * @param array  $overrides code => generator fields for that row.
     * @param string $audience
     * @return array<string, int> code => row id.
     */
    private function group(
        string $title,
        string $status,
        array $langs,
        array $overrides = [],
        string $audience = 'everyone'
    ): array {
        $ids     = [];
        $groupid = 0;
        foreach ($langs as $lang) {
            $row = $this->generator->create_kb_article(($overrides[$lang] ?? []) + [
                'title'    => "$title ($lang)",
                'status'   => $status,
                'audience' => $audience,
                'lang'     => $lang,
                'groupid'  => $groupid,
            ]);
            $ids[$lang] = (int) $row->id;
            $groupid    = $groupid ?: (int) $row->groupid;
        }
        return $ids;
    }

    /**
     * The database refuses two rows of one group in one language, whoever writes them.
     */
    public function test_the_unique_index_holds_one_row_per_language_per_group(): void {
        $ids = $this->group('Reset', 'published', ['en', 'es']);
        $this->expectException(\dml_write_exception::class);
        $this->generator->create_kb_article(['lang' => 'es', 'groupid' => $ids['en'], 'title' => 'Reset (es) again']);
    }

    /**
     * With `resolve`, one row per group: the first code the group has, then
     * the canonical row, then the lowest id.
     */
    public function test_resolve_walks_the_chain_then_canonical_then_lowest_id(): void {
        $a = $this->group('A', 'published', ['en', 'es', 'pt_br']);
        // B was first written in fr; its en translation has a lower id than its de one.
        $b = $this->group('B', 'published', ['fr', 'en', 'de']);

        $pick = function (array $langs): array {
            $rows = $this->repo->list(kb_access_policy::SCOPE_PUBLIC, ['resolve' => $langs], 'title');
            return array_map(static fn(\stdClass $r): int => (int) $r->id, array_values($rows));
        };

        // Preferred code present in both groups.
        $this->assertSame([$a['en'], $b['en']], $pick(['en']));
        // First code missing in B, second present.
        $this->assertSame([$a['es'], $b['en']], $pick(['es', 'en']));
        // Nothing in the chain: the canonical row of each group.
        $this->assertSame([$a['en'], $b['fr']], $pick(['xx']));
        // Order of the chain matters, not the order of ids.
        $this->assertSame([$a['pt_br'], $b['de']], $pick(['pt_br', 'de', 'en']));
        // Without resolve every row comes back, as before.
        $this->assertCount(6, $this->repo->list(kb_access_policy::SCOPE_PUBLIC, [], 'title'));
    }

    /**
     * Only rows the scope allows take part: a draft in the preferred language
     * never wins for a reader, and does for an editor; when the canonical row
     * is out of scope the lowest visible id is the tail.
     */
    public function test_resolve_chooses_inside_the_scope(): void {
        $ids = $this->group('Reset', 'published', ['en', 'es', 'de'], ['es' => ['status' => 'draft']]);

        $public = $this->repo->list(kb_access_policy::SCOPE_PUBLIC, ['resolve' => ['es', 'fr']]);
        $this->assertSame([$ids['en']], array_keys($public));

        $all = $this->repo->list(kb_access_policy::SCOPE_ALL, ['resolve' => ['es', 'fr']]);
        $this->assertSame([$ids['es']], array_keys($all));

        // Canonical (en) archived: for a reader the tail is the lowest visible id.
        $this->repo->update((object) ['id' => $ids['en'], 'status' => kb_article::STATUS_ARCHIVED]);
        $this->repo->update((object) ['id' => $ids['es'], 'status' => kb_article::STATUS_PUBLISHED]);
        $public = $this->repo->list(kb_access_policy::SCOPE_PUBLIC, ['resolve' => ['fr']]);
        $this->assertSame([$ids['es']], array_keys($public));

        // An agents-only group is invisible to the public scope whatever the chain.
        $this->group('Internal', 'published', ['en', 'es'], [], 'agents');
        $this->assertSame([$ids['es']], array_keys($this->repo->list(kb_access_policy::SCOPE_PUBLIC, ['resolve' => ['es', 'en']])));
        $this->assertCount(2, $this->repo->list(kb_access_policy::SCOPE_AGENT, ['resolve' => ['es', 'en']]));
    }

    /**
     * Counts, pagination and the category breakdown are per group under resolve.
     */
    public function test_resolve_counts_and_paginates_groups(): void {
        $cat = $this->category('Access');
        $this->group('A', 'published', ['en', 'es'], ['en' => ['categoryid' => $cat], 'es' => ['categoryid' => $cat]]);
        $this->group('B', 'published', ['en', 'es', 'fr']);
        $this->group('C', 'published', ['es']);

        $this->assertSame(6, $this->repo->count(kb_access_policy::SCOPE_PUBLIC));
        $this->assertSame(3, $this->repo->count(kb_access_policy::SCOPE_PUBLIC, ['resolve' => ['en']]));
        $this->assertSame(1, $this->repo->count(kb_access_policy::SCOPE_PUBLIC, ['resolve' => ['en'], 'categoryid' => $cat]));

        $bycat = $this->repo->count_by_category(kb_access_policy::SCOPE_PUBLIC, ['resolve' => ['en']]);
        ksort($bycat);
        $this->assertSame([0 => 2, $cat => 1], $bycat);
        $bycat = $this->repo->count_by_category(kb_access_policy::SCOPE_PUBLIC);
        ksort($bycat);
        $this->assertSame([0 => 4, $cat => 2], $bycat);

        $page1 = $this->repo->list(kb_access_policy::SCOPE_PUBLIC, ['resolve' => ['en']], 'title', 2, 0);
        $page2 = $this->repo->list(kb_access_policy::SCOPE_PUBLIC, ['resolve' => ['en']], 'title', 2, 2);
        $this->assertCount(2, $page1);
        $this->assertCount(1, $page2);
        $titles = array_map(static fn(\stdClass $r): string => $r->title, array_merge(array_values($page1), array_values($page2)));
        $this->assertSame(['A (en)', 'B (en)', 'C (es)'], $titles);
    }

    /**
     * The group helpers the service builds on.
     */
    public function test_group_helpers(): void {
        $ids = $this->group('Reset', 'published', ['en', 'es'], ['es' => ['status' => 'draft']]);
        $groupid = $ids['en'];

        $this->assertSame([$ids['en'], $ids['es']], array_keys($this->repo->list_group($groupid)));

        $this->assertTrue($this->repo->lang_exists_in_group($groupid, 'es'));
        $this->assertFalse($this->repo->lang_exists_in_group($groupid, 'es', $ids['es']));
        $this->assertFalse($this->repo->lang_exists_in_group($groupid, 'fr'));

        $this->assertTrue($this->repo->group_has_status_other_than($groupid, kb_article::STATUS_DRAFT));
        $this->repo->update((object) ['id' => $ids['en'], 'status' => kb_article::STATUS_DRAFT]);
        $this->assertFalse($this->repo->group_has_status_other_than($groupid, kb_article::STATUS_DRAFT));

        $cat = $this->category('Access');
        $this->repo->update_group_fields($groupid, ['categoryid' => $cat, 'audience' => kb_article::AUDIENCE_AGENTS]);
        foreach ($this->repo->list_group($groupid) as $row) {
            $this->assertSame($cat, (int) $row->categoryid);
            $this->assertSame(kb_article::AUDIENCE_AGENTS, (int) $row->audience);
        }
        $this->repo->update_group_fields($groupid, ['categoryid' => null]);
        foreach ($this->repo->list_group($groupid) as $row) {
            $this->assertNull($row->categoryid);
            $this->assertSame(kb_article::AUDIENCE_AGENTS, (int) $row->audience);
        }

        $this->repo->reparent_group($groupid, $ids['es']);
        $this->assertSame([], $this->repo->list_group($groupid));
        $this->assertSame([$ids['en'], $ids['es']], array_keys($this->repo->list_group($ids['es'])));
    }
}
