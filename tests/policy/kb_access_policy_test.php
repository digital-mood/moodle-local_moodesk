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
 * Unit tests for the knowledge base access policy.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\policy;

use local_moodesk\domain\kb_article;
use local_moodesk\repository\kb_article_repository;

/**
 * Tests for kb_access_policy.
 *
 * The whole point of the class is that a draft never reaches a requester, so
 * the first test is the full matrix — every kind of actor against every kind
 * of row — rather than a sample. The second holds the row rule and the query
 * rule to the same answer: whatever can_view() says about each row, the
 * repository asked with scope_for() must return exactly those rows.
 *
 * @covers \local_moodesk\policy\kb_access_policy
 */
final class kb_access_policy_test extends \advanced_testcase {
    /** @var kb_access_policy */
    private kb_access_policy $policy;

    /** @var array<string, int> actor label => userid */
    private array $actors;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->policy = new kb_access_policy();

        $this->actors = [
            // Any authenticated user: :viewkb through the user archetype.
            'requester' => (int) $this->getDataGenerator()->create_user()->id,
            'agent'     => $this->user_holding(['local/moodesk:agent', 'local/moodesk:viewall', 'local/moodesk:reply']),
            'editor'    => $this->user_holding(['local/moodesk:editkb']),
            'manager'   => $this->user_holding(['local/moodesk:managekb']),
            // An authenticated user whose :viewkb an administrator took away.
            // Prohibit, not prevent: a prevent in one role loses to the allow
            // the user archetype already grants in another.
            'noviewkb'  => $this->user_holding([], ['local/moodesk:viewkb']),
            'system'    => 0,
        ];
    }

    /**
     * A user holding the capabilities named, on top of the user archetype.
     *
     * @param string[] $allow    Capabilities to allow at system context.
     * @param string[] $prohibit Capabilities to prohibit at system context.
     * @return int userid
     */
    private function user_holding(array $allow, array $prohibit = []): int {
        $context = \context_system::instance();
        $user    = $this->getDataGenerator()->create_user();
        $roleid  = $this->getDataGenerator()->create_role();
        foreach ($allow as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        }
        foreach ($prohibit as $capability) {
            assign_capability($capability, CAP_PROHIBIT, $roleid, $context->id, true);
        }
        role_assign($roleid, $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        return (int) $user->id;
    }

    /**
     * Every kind of row: three statuses by two audiences.
     *
     * @return array<string, \stdClass> label => article-shaped record
     */
    private function rows(): array {
        $rows = [];
        foreach (
            [
            'draft'     => kb_article::STATUS_DRAFT,
            'published' => kb_article::STATUS_PUBLISHED,
            'archived'  => kb_article::STATUS_ARCHIVED,
            ] as $statuslabel => $status
        ) {
            foreach (
                [
                'everyone' => kb_article::AUDIENCE_EVERYONE,
                'agents'   => kb_article::AUDIENCE_AGENTS,
                ] as $audiencelabel => $audience
            ) {
                $rows["$statuslabel/$audiencelabel"] = (object) [
                    'id'       => 0,
                    'status'   => $status,
                    'audience' => $audience,
                ];
            }
        }
        return $rows;
    }

    /**
     * The matrix. Everything that is not listed here is denied.
     */
    public function test_who_sees_what(): void {
        $allowed = [
            'requester' => ['published/everyone'],
            'agent'     => ['published/everyone', 'published/agents'],
            'editor'    => array_keys($this->rows()),
            'manager'   => array_keys($this->rows()),
            'noviewkb'  => [],
            'system'    => [],
        ];

        foreach ($this->actors as $actor => $userid) {
            foreach ($this->rows() as $label => $row) {
                $expected = in_array($label, $allowed[$actor], true);
                $this->assertSame(
                    $expected,
                    $this->policy->can_view($userid, $row),
                    "$actor should " . ($expected ? '' : 'NOT ') . "see $label"
                );
            }
        }
    }

    /**
     * The scope each actor is given, by name.
     */
    public function test_scope_for_each_actor(): void {
        $this->assertSame(kb_access_policy::SCOPE_PUBLIC, $this->policy->scope_for($this->actors['requester']));
        $this->assertSame(kb_access_policy::SCOPE_AGENT, $this->policy->scope_for($this->actors['agent']));
        $this->assertSame(kb_access_policy::SCOPE_ALL, $this->policy->scope_for($this->actors['editor']));
        $this->assertSame(kb_access_policy::SCOPE_ALL, $this->policy->scope_for($this->actors['manager']));
        $this->assertSame(kb_access_policy::SCOPE_NONE, $this->policy->scope_for($this->actors['noviewkb']));
        $this->assertSame(kb_access_policy::SCOPE_NONE, $this->policy->scope_for($this->actors['system']));
    }

    /**
     * The row rule and the query rule agree, for every actor.
     *
     * Six real rows go in; for each actor the repository, asked with that
     * actor's scope, has to return exactly the rows can_view() says yes to.
     * This is the test that makes it safe for lists and searches to go
     * through SQL instead of filtering row by row.
     */
    public function test_scope_returns_exactly_what_can_view_allows(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_moodesk');
        $created   = [];
        foreach ($this->rows() as $label => $row) {
            $article = $generator->create_kb_article([
                'title'    => $label,
                'status'   => $row->status,
                'audience' => $row->audience,
            ]);
            $created[(int) $article->id] = $article;
        }

        $repo = new kb_article_repository();
        foreach ($this->actors as $actor => $userid) {
            $expected = [];
            foreach ($created as $id => $article) {
                if ($this->policy->can_view($userid, $article)) {
                    $expected[] = $id;
                }
            }
            $actual = array_keys($repo->list($this->policy->scope_for($userid)));
            sort($expected);
            sort($actual);
            $this->assertSame($expected, $actual, "Scope and can_view() disagree for $actor");
        }
    }

    /**
     * The same agreement for the one row per group a resolved list picks
     * (KB-06): for every actor, every row list() returns under `resolve` is
     * one can_view() allows, and every group with a visible row is returned
     * exactly once. Groups here mix statuses across their translations, the
     * way a half-translated article does.
     */
    public function test_resolved_list_returns_one_visible_row_per_group(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_moodesk');
        $created   = [];
        foreach ([kb_article::AUDIENCE_EVERYONE, kb_article::AUDIENCE_AGENTS] as $audience) {
            foreach ([['es', 'en', 'fr'], ['en', 'es']] as $langs) {
                $groupid = 0;
                foreach ($langs as $i => $lang) {
                    $status  = [kb_article::STATUS_DRAFT, kb_article::STATUS_PUBLISHED, kb_article::STATUS_ARCHIVED][$i];
                    $article = $generator->create_kb_article([
                        'title'    => "$audience/" . implode('-', $langs) . "/$lang",
                        'status'   => $status,
                        'audience' => $audience,
                        'lang'     => $lang,
                        'groupid'  => $groupid,
                    ]);
                    $groupid = $groupid ?: (int) $article->groupid;
                    $created[(int) $article->id] = $article;
                }
            }
        }

        $repo = new kb_article_repository();
        foreach ($this->actors as $actor => $userid) {
            $visiblegroups = [];
            foreach ($created as $article) {
                if ($this->policy->can_view($userid, $article)) {
                    $visiblegroups[(int) $article->groupid] = true;
                }
            }
            foreach ([['es', 'en'], ['fr'], ['xx']] as $chain) {
                $picked = $repo->list($this->policy->scope_for($userid), ['resolve' => $chain]);
                $groups = [];
                foreach ($picked as $row) {
                    $this->assertTrue($this->policy->can_view($userid, $row), "$actor was handed a row they may not see");
                    $this->assertArrayNotHasKey((int) $row->groupid, $groups, "$actor got a group twice");
                    $groups[(int) $row->groupid] = true;
                }
                ksort($groups);
                ksort($visiblegroups);
                $this->assertSame(
                    array_keys($visiblegroups),
                    array_keys($groups),
                    "Groups differ for $actor with chain " . implode(',', $chain)
                );
            }
        }
    }

    /**
     * Managing implies editing; editing does not imply managing.
     */
    public function test_manage_implies_edit_but_not_the_reverse(): void {
        $this->assertTrue($this->policy->can_edit($this->actors['manager']));
        $this->assertTrue($this->policy->can_manage($this->actors['manager']));

        $this->assertTrue($this->policy->can_edit($this->actors['editor']));
        $this->assertFalse($this->policy->can_manage($this->actors['editor']));

        $this->assertFalse($this->policy->can_edit($this->actors['agent']));
        $this->assertFalse($this->policy->can_edit($this->actors['requester']));
        $this->assertFalse($this->policy->can_edit(0));
        $this->assertFalse($this->policy->can_manage(0));
    }

    /**
     * A site administrator is not an agent unless made one, so an agents-only
     * article is not theirs to read as a reader — but they edit everything,
     * so they see it anyway. The point being pinned is the route: admin
     * reach comes from :editkb via doanything, never from :agent.
     */
    public function test_admin_reaches_articles_as_an_editor_not_as_an_agent(): void {
        $admin = (int) get_admin()->id;
        $this->assertSame(kb_access_policy::SCOPE_ALL, $this->policy->scope_for($admin));
        $this->assertTrue($this->policy->can_view($admin, (object) [
            'status'   => kb_article::STATUS_DRAFT,
            'audience' => kb_article::AUDIENCE_AGENTS,
        ]));
    }

    /**
     * The require_* variants throw the exception the pages expect, naming the
     * reader capability whatever the actual reason was.
     */
    public function test_require_can_view_names_viewkb(): void {
        $draft = (object) ['status' => kb_article::STATUS_DRAFT, 'audience' => kb_article::AUDIENCE_EVERYONE];
        try {
            $this->policy->require_can_view($this->actors['requester'], $draft);
            $this->fail('A requester must not see a draft.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('local/moodesk:viewkb'), $e->a);
        }
    }

    /**
     * require_can_edit and require_can_manage name their own capability.
     */
    public function test_require_edit_and_manage_name_their_capability(): void {
        try {
            $this->policy->require_can_edit($this->actors['requester']);
            $this->fail('A requester must not edit.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('local/moodesk:editkb'), $e->a);
        }
        try {
            $this->policy->require_can_manage($this->actors['editor']);
            $this->fail('An editor must not manage.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('local/moodesk:managekb'), $e->a);
        }
    }
}
