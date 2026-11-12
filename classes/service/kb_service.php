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
 * Knowledge base service: the one entry point for creating, changing,
 * publishing, reading and searching articles.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

use local_moodesk\domain\kb_article;
use local_moodesk\domain\kb_language;
use local_moodesk\event\kb_article_published;
use local_moodesk\policy\kb_access_policy;
use local_moodesk\repository\category_repository;
use local_moodesk\repository\kb_article_repository;

/**
 * Orchestrates knowledge base articles.
 *
 * Every public method takes the acting user and asks {@see kb_access_policy}
 * before it does anything; the pages and external functions built on top do
 * not restate the rule. Every write derives `searchtext` through
 * {@see kb_article::derive_searchtext()} in one private method, so a body can
 * never be saved with search text that describes a different body.
 *
 * On categories: an article may be filed under a ticket category, and this is
 * the only class in the knowledge base that knows a category is a row
 * somewhere else. It asks category_repository one question — does an active
 * one with this id exist — in one private method, and treats the id as opaque
 * everywhere else. If the knowledge base ever grows its own taxonomy the
 * change is this method, the column's reference and a migration.
 *
 * On language (KB-06): rows sharing a `groupid` are translations of one
 * article, one per language. A reading surface that wants one row per group
 * passes `resolve` among its options — `true` for the reader's own chain
 * (current language → its Moodle parent → site; the site alone in Starter),
 * or an explicit list of codes for a recipient who is not the actor — and
 * the repository picks, inside the actor's scope, the first the group has,
 * then the canonical row (`id = groupid`), then the lowest id. Without
 * `resolve` every row comes back, which is what the manage screen wants.
 * Category and audience belong to the group and are written to every row of
 * it, atomically, by {@see sync_shared_fields()}; title, body, status and
 * files belong to the translation. Deleting the canonical row re-points the
 * survivors to the lowest surviving id, so a group always has a row whose id
 * is its groupid and nothing about "canonical" is persisted. Opening a row by
 * id never resolves: a shared link opens what was shared.
 *
 * On search — a conscious MVP choice, not an oversight: search is a
 * case-insensitive LIKE over title and searchtext, which is a full scan of
 * the published rows. A knowledge base is hundreds of articles, not millions,
 * and the three supported databases have no portable full-text index. KB-03
 * measures it against 2,000 articles; the strategy changes only if that
 * measurement shows a real problem, not before.
 */
class kb_service {
    /** The file area article bodies embed images from. itemid = article id. */
    const FILEAREA = 'kb_article';

    /** Upper bound on tokens taken from a search query. */
    const MAX_TOKENS = 6;

    /** Shortest token that takes part in a search. */
    const MIN_TOKEN_LENGTH = 3;

    /** Default number of results search() returns. */
    const DEFAULT_SEARCH_LIMIT = 20;

    /** How many candidates the repository is asked for before ranking. */
    const SEARCH_FETCH_LIMIT = 200;

    /** How many articles suggest() offers the New Ticket composer. */
    const SUGGEST_LIMIT = 5;

    /** @var kb_article_repository */
    protected kb_article_repository $repo;

    /** @var category_repository */
    protected category_repository $categories;

    /** @var kb_access_policy */
    protected kb_access_policy $policy;

    /**
     * Constructor.
     *
     * @param kb_article_repository $repo
     * @param category_repository   $categories
     * @param kb_access_policy      $policy
     */
    public function __construct(
        kb_article_repository $repo,
        category_repository $categories,
        kb_access_policy $policy
    ) {
        $this->repo       = $repo;
        $this->categories = $categories;
        $this->policy     = $policy;
    }

    /**
     * Build a fully-wired service for production use.
     *
     * @return self
     */
    public static function create(): self {
        return new self(new kb_article_repository(), new category_repository(), new kb_access_policy());
    }

    /**
     * Options for the editor file area, shared by whoever renders the form and
     * by the save path, so the two cannot disagree about what a body may embed.
     *
     * @return array Options accepted by file_prepare_draft_area / file_save_draft_area_files.
     */
    public static function body_file_options(): array {
        return [
            'subdirs'        => 0,
            'maxfiles'       => 20,
            'accepted_types' => ['web_image'],
            'context'        => \context_system::instance(),
        ];
    }

    /*
     * -------------------------------------------------------------------------
     * Read
     * -------------------------------------------------------------------------
     */

    /**
     * One article the actor may see.
     *
     * @param int $id      Article id.
     * @param int $actorid Acting user.
     * @return \stdClass
     * @throws \moodle_exception When the article does not exist.
     * @throws \required_capability_exception When the actor may not see it.
     */
    public function get_article_for(int $id, int $actorid): \stdClass {
        edition_service::require_feature('knowledge_base');

        $article = $this->repo->get_by_id($id);
        if ($article === null) {
            throw new \moodle_exception('kb_article_notfound', 'local_moodesk');
        }
        $this->policy->require_can_view($actorid, $article);
        return $article;
    }

    /**
     * The rows of an article's group the actor may see, lowest id first.
     *
     * For a reader that is the published translations their audience allows
     * — the switcher; for an editor it is every row, drafts and archived
     * included — the translations panel. The policy answers per row, so a
     * draft sibling is absent for a requester rather than disabled.
     *
     * @param int $groupid The group.
     * @param int $actorid Acting user.
     * @return \stdClass[] Keyed by id.
     */
    public function get_translations_for(int $groupid, int $actorid): array {
        edition_service::require_feature('knowledge_base');

        $visible = [];
        foreach ($this->repo->list_group($groupid) as $id => $row) {
            if ($this->policy->can_view($actorid, $row)) {
                $visible[$id] = $row;
            }
        }
        return $visible;
    }

    /**
     * The language chain a reading surface should resolve with, for the
     * person the result is for.
     *
     * Pro and Enterprise: their current language, its Moodle parent, the site
     * language. Starter: the site language alone — one row per group still,
     * but "in your language" is what Pro adds, and a Starter site must not
     * half-promise it. A caller resolving for somebody else (an agent writing
     * to a requester) passes that person's language instead of the actor's.
     *
     * @param string|null $language A language code, or null for current_language().
     * @return string[]
     */
    public function chain_for(?string $language = null): array {
        if (!edition_service::has_feature('kb_multilingual')) {
            return kb_language::site_chain();
        }
        return kb_language::chain_for($language ?? current_language());
    }

    /**
     * Articles the actor may read, newest publication first.
     *
     * Recognised filters: categoryid (0 = uncategorised), lang, publishedonly
     * (true to see only live articles even when the actor is an editor — what
     * a reading surface shows, as opposed to what the actor may open) and
     * resolve (true, or codes: one row per group — see the class comment).
     *
     * @param int   $actorid Acting user.
     * @param array $filters
     * @param int   $limit   0 for no limit.
     * @param int   $offset
     * @return \stdClass[] Keyed by id.
     */
    public function list_visible(int $actorid, array $filters = [], int $limit = 0, int $offset = 0): array {
        edition_service::require_feature('knowledge_base');
        $scope = $this->policy->scope_for($actorid);
        return $this->repo->list($scope, $this->reader_filters($filters), 'timepublished', $limit, $offset);
    }

    /**
     * How many articles list_visible() would return.
     *
     * @param int   $actorid
     * @param array $filters
     * @return int
     */
    public function count_visible(int $actorid, array $filters = []): int {
        edition_service::require_feature('knowledge_base');
        return $this->repo->count($this->policy->scope_for($actorid), $this->reader_filters($filters));
    }

    /**
     * Visible articles per category for the actor.
     *
     * Recognised filters: lang, publishedonly, resolve (groups per category).
     *
     * @param int   $actorid
     * @param array $filters
     * @return array<int, int> categoryid => count; uncategorised under 0.
     */
    public function count_visible_by_category(int $actorid, array $filters = []): array {
        edition_service::require_feature('knowledge_base');
        return $this->repo->count_by_category($this->policy->scope_for($actorid), $this->reader_filters($filters));
    }

    /**
     * Every article, for the management screen. Editors only.
     *
     * Recognised filters: status, categoryid, lang.
     *
     * @param int   $actorid
     * @param array $filters
     * @param int   $limit
     * @param int   $offset
     * @return \stdClass[] Keyed by id.
     */
    public function list_for_manage(int $actorid, array $filters = [], int $limit = 0, int $offset = 0): array {
        edition_service::require_feature('knowledge_base');
        $this->policy->require_can_edit($actorid);
        return $this->repo->list(kb_access_policy::SCOPE_ALL, $this->manage_filters($filters), 'timemodified', $limit, $offset);
    }

    /**
     * How many articles list_for_manage() would return.
     *
     * @param int   $actorid
     * @param array $filters
     * @return int
     */
    public function count_for_manage(int $actorid, array $filters = []): int {
        edition_service::require_feature('knowledge_base');
        $this->policy->require_can_edit($actorid);
        return $this->repo->count(kb_access_policy::SCOPE_ALL, $this->manage_filters($filters));
    }

    /*
     * -------------------------------------------------------------------------
     * Search
     * -------------------------------------------------------------------------
     */

    /**
     * Articles matching a free-text query, best first, within what the actor
     * may see.
     *
     * Every token has to appear somewhere; an article whose title carries more
     * of them ranks above one that only has them in the body, and ties go to
     * the most recently published. Options: categoryid, lang, limit,
     * publishedonly, scopecap, resolve.
     *
     * `resolve` (true, or a list of codes) collapses the matches to one per
     * group. Every row in scope is matched whatever its language — an
     * article whose only translation is in another language is still found
     * — and the row kept for a group is the MATCHING row highest in the
     * chain, never a sibling the query did not match, however preferred its
     * language: the excerpt shown must be one the query hit. The group ranks
     * where its kept row ranks. Browse resolves by preferred language;
     * search resolves among the matches.
     *
     * `scopecap` narrows what is searched to a scope the *recipient* of the
     * result may see, never widening what the actor may: the effective scope
     * is the smaller of the actor's own and the cap (scopes nest — see
     * kb_access_policy). An agent choosing an article for a public reply is
     * the case: they may read agents-only articles, the requester may not, and
     * the list must be the requester's. Absent, the actor's scope applies
     * unchanged.
     *
     * @param string $query   What the user typed.
     * @param int    $actorid Acting user.
     * @param array  $options See above.
     * @return \stdClass[] Ranked, keyed by id. Empty when the query has no usable token.
     */
    public function search(string $query, int $actorid, array $options = []): array {
        edition_service::require_feature('knowledge_base');

        $tokens = self::tokenize($query);
        if (empty($tokens)) {
            return [];
        }

        $scope = $this->policy->scope_for($actorid);
        if (isset($options['scopecap'])) {
            $scope = min($scope, (int) $options['scopecap']);
        }
        if ($scope === kb_access_policy::SCOPE_NONE) {
            return [];
        }

        $filters = $this->reader_filters($options);
        $limit   = max(1, (int) ($options['limit'] ?? self::DEFAULT_SEARCH_LIMIT));

        $candidates = $this->repo->search($tokens, $scope, $filters, self::SEARCH_FETCH_LIMIT);

        // Rank in PHP: the databases do not agree on how to score a LIKE.
        $scored = [];
        foreach ($candidates as $article) {
            $title = \core_text::strtolower($article->title);
            $intitle = 0;
            foreach ($tokens as $token) {
                if (\core_text::strpos($title, $token) !== false) {
                    $intitle++;
                }
            }
            $scored[] = [
                'intitle'   => $intitle,
                'published' => (int) ($article->timepublished ?? 0),
                'id'        => (int) $article->id,
                'article'   => $article,
            ];
        }
        usort($scored, static function (array $a, array $b): int {
            return [$b['intitle'], $b['published'], $b['id']] <=> [$a['intitle'], $a['published'], $a['id']];
        });

        if (isset($filters['resolve'])) {
            $scored = $this->collapse_by_group($scored, $filters['resolve']);
        }

        $ranked = [];
        foreach (array_slice($scored, 0, $limit) as $row) {
            $ranked[$row['id']] = $row['article'];
        }
        return $ranked;
    }

    /**
     * One scored row per group: the one highest in the language chain, the
     * canonical row next, the lowest id last — chosen among rows that
     * matched, in the order the ranking already put them in.
     *
     * @param array    $scored Ranked rows from search(): intitle, published, id, article.
     * @param string[] $chain  Language codes in order of preference.
     * @return array The kept rows, still in ranking order.
     */
    private function collapse_by_group(array $scored, array $chain): array {
        $positions = array_flip(array_values($chain));
        $tail      = count($chain);
        $rank      = static function (array $row) use ($positions, $tail): array {
            $article = $row['article'];
            $chainrank = $positions[$article->lang] ?? ((int) $article->id === (int) $article->groupid ? $tail : $tail + 1);
            return [$chainrank, (int) $article->id];
        };

        $best = [];
        foreach ($scored as $index => $row) {
            $groupid = (int) $row['article']->groupid;
            if (!isset($best[$groupid]) || $rank($row) < $rank($scored[$best[$groupid]])) {
                $best[$groupid] = $index;
            }
        }
        $keep = array_flip($best);
        return array_values(array_filter($scored, static fn(int $i): bool => isset($keep[$i]), ARRAY_FILTER_USE_KEY));
    }

    /**
     * Articles worth offering while a ticket is being written: what search()
     * would find for the subject, with the ticket's category preferred.
     *
     * Not a second search. search() is the explicit one — a query, a ranked
     * answer — and this is the contextual one the composer asks for on the
     * requester's behalf. The category is a preference rather than a filter:
     * an article filed under the chosen category comes first, and when there
     * are fewer of those than $limit the rest of the list is filled from the
     * global ranking, so picking the wrong category costs the requester nothing
     * but the order. Both halves are search() calls with its own ranking, so
     * there is no score to invent and no second rule about who may see what.
     *
     * Always published articles only: the composer is a reading surface for
     * whoever is writing, editor or not, and a draft offered as an answer is
     * the failure the knowledge base exists to rule out.
     *
     * A category that is not an active one — archived, deleted, or never real —
     * is treated as none. It came from a form the requester may have been
     * handed with a stale option; that is not their mistake to be shown.
     *
     * @param string   $subject    What the requester has typed as the subject.
     * @param int      $actorid    Acting user.
     * @param int|null $categoryid The category selected on the form, or null/0.
     * @param int      $limit      Most articles to return; SUGGEST_LIMIT by default.
     * @return \stdClass[] Ranked, keyed by id. Empty when nothing usable was typed.
     */
    public function suggest(string $subject, int $actorid, ?int $categoryid = null, int $limit = self::SUGGEST_LIMIT): array {
        return $this->ranked_with_category_first($subject, $actorid, $categoryid, max(1, $limit), [
            'publishedonly' => true,
            // The person typing is the person reading: their own chain.
            'resolve'       => true,
        ]);
    }

    /**
     * search() twice, the category first: the composition behind suggest().
     *
     * An article filed under $categoryid comes first, and when there are
     * fewer of those than $limit the rest of the list is filled from the
     * global ranking. Both halves are search() calls with its own ranking, so
     * there is no score to invent and no second rule about who may see what;
     * keyed by id on both sides, the union is the de-duplication and the
     * category's rows keep their place at the front. A category that is not an
     * active one — archived, deleted, or never real — is treated as none.
     *
     * @param string   $query      What is searched for.
     * @param int      $actorid    Acting user.
     * @param int|null $categoryid Preferred category, or null/0 for none.
     * @param int      $limit      Most articles to return; at least 1.
     * @param array    $options    Passed to every search() call: publishedonly, scopecap, lang, resolve.
     * @return \stdClass[] Ranked, keyed by id.
     */
    protected function ranked_with_category_first(
        string $query,
        int $actorid,
        ?int $categoryid,
        int $limit,
        array $options
    ): array {
        $options['limit'] = $limit;
        unset($options['categoryid']);

        $ranked = [];
        if ($categoryid !== null && $categoryid > 0 && $this->categories->exists_active($categoryid)) {
            $ranked = $this->search($query, $actorid, ['categoryid' => $categoryid] + $options);
        }

        if (count($ranked) < $limit) {
            $ranked = $ranked + $this->search($query, $actorid, $options);
        }

        return array_slice($ranked, 0, $limit, true);
    }

    /**
     * Turn a query into the tokens the repository matches on.
     *
     * Lower-cased with core_text, split on whitespace, tokens shorter than
     * MIN_TOKEN_LENGTH dropped, duplicates dropped, at most MAX_TOKENS kept in
     * the order typed. Nothing is escaped here — the repository does that,
     * because escaping belongs next to the LIKE it protects.
     *
     * @param string $query
     * @return string[]
     */
    public static function tokenize(string $query): array {
        $words  = preg_split('~\s+~u', \core_text::strtolower(trim($query))) ?: [];
        $tokens = [];
        foreach ($words as $word) {
            if ($word === '' || \core_text::strlen($word) < self::MIN_TOKEN_LENGTH) {
                continue;
            }
            if (in_array($word, $tokens, true)) {
                continue;
            }
            $tokens[] = $word;
            if (count($tokens) === self::MAX_TOKENS) {
                break;
            }
        }
        return $tokens;
    }

    /*
     * -------------------------------------------------------------------------
     * Write
     * -------------------------------------------------------------------------
     */

    /**
     * Create an article as a draft.
     *
     * $data: title (required), body, bodyformat, categoryid, audience, lang,
     * draftitemid (a user draft file area whose files become the body's).
     *
     * @param \stdClass $data
     * @param int       $actorid
     * @return int New article id.
     */
    public function create_draft(\stdClass $data, int $actorid): int {
        global $CFG;

        edition_service::require_feature('knowledge_base');
        $this->policy->require_can_edit($actorid);

        $now = time();

        $record               = new \stdClass();
        $record->title        = $this->clean_title($data->title ?? '');
        $record->body         = (string) ($data->body ?? '');
        $record->bodyformat   = FORMAT_HTML;
        $record->searchtext   = kb_article::derive_searchtext($record->body);
        $record->categoryid   = $this->clean_category($data->categoryid ?? null);
        $record->audience     = $this->clean_audience($data->audience ?? kb_article::AUDIENCE_EVERYONE);
        $record->lang         = $this->clean_lang($data->lang ?? $CFG->lang);
        $record->status       = kb_article::STATUS_DRAFT;
        $record->createdby    = $actorid;
        $record->usermodified = $actorid;
        $record->timecreated  = $now;
        $record->timemodified = $now;

        $id = $this->repo->insert($record);

        if (!empty($data->draftitemid)) {
            $this->write_body($id, (int) $data->draftitemid, $record->body, $actorid, $now);
        }

        return $id;
    }

    /**
     * Change an article's title, body, category, audience or language.
     *
     * Status is not a field: it changes only through publish(), unpublish(),
     * archive() and restore().
     *
     * Category and audience belong to the group (KB-06): a change on any
     * row is written to every row of the group, in one transaction, so no
     * group is ever half-divergent. Audience is an access rule, so it needs
     * :managekb unless every row of the group is a draft — an editor may
     * write an internal article but may not turn a live one internal, or a
     * live internal one public, and not by editing its draft sibling either.
     *
     * Language is per row, Pro, and must stay unique in the group: the one
     * legitimate change is correcting a row written under the wrong code.
     *
     * @param int       $id
     * @param \stdClass $data Any of: title, body, categoryid, audience, lang, draftitemid.
     * @param int       $actorid
     * @return void
     */
    public function update_article(int $id, \stdClass $data, int $actorid): void {
        edition_service::require_feature('knowledge_base');
        $this->policy->require_can_edit($actorid);

        $article = $this->require_article($id);
        $groupid = (int) $article->groupid;
        $now     = time();

        $update               = new \stdClass();
        $update->id           = $id;
        $update->usermodified = $actorid;
        $update->timemodified = $now;

        if (isset($data->title)) {
            $update->title = $this->clean_title($data->title);
        }

        $shared = [];
        if (property_exists($data, 'categoryid')) {
            $shared['categoryid'] = $this->clean_category($data->categoryid);
        }
        if (isset($data->audience)) {
            $audience = $this->clean_audience($data->audience);
            if (
                $audience !== (int) $article->audience
                    && $this->repo->group_has_status_other_than($groupid, kb_article::STATUS_DRAFT)
            ) {
                $this->policy->require_can_manage($actorid);
            }
            $shared['audience'] = $audience;
        }

        if (isset($data->lang) && (string) $data->lang !== (string) $article->lang) {
            edition_service::require_feature('kb_multilingual');
            $update->lang = $this->require_new_group_lang($groupid, (string) $data->lang, $id);
        }

        $body = isset($data->body) ? (string) $data->body : null;
        if ($body !== null && empty($data->draftitemid)) {
            $update->body       = $body;
            $update->searchtext = kb_article::derive_searchtext($body);
        }

        $transaction = $this->repo->begin_transaction();
        try {
            $this->repo->update($update);
            $this->sync_shared_fields($groupid, $shared);
            if (!empty($data->draftitemid)) {
                // The saved body is the one with its file URLs rewritten, so
                // it is written after the files are, in one place.
                $this->write_body($id, (int) $data->draftitemid, $body ?? $article->body, $actorid, $now);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Make an article live.
     *
     * @param int $id
     * @param int $actorid
     * @return void
     */
    public function publish(int $id, int $actorid): void {
        edition_service::require_feature('knowledge_base');
        $this->policy->require_can_manage($actorid);

        $article = $this->require_article($id);
        $this->require_transition($article, kb_article::STATUS_PUBLISHED);

        if (trim($article->searchtext) === '') {
            throw new \moodle_exception('kb_body_required', 'local_moodesk');
        }

        $now = time();
        $update                = new \stdClass();
        $update->id            = $id;
        $update->status        = kb_article::STATUS_PUBLISHED;
        $update->usermodified  = $actorid;
        $update->timemodified  = $now;
        if (empty($article->timepublished)) {
            $update->timepublished = $now;
        }
        $this->repo->update($update);

        kb_article_published::create([
            'objectid'      => $id,
            'context'       => \context_system::instance(),
            'userid'        => $actorid,
            'relateduserid' => (int) $article->createdby ?: null,
            'other'         => ['title' => $article->title],
        ])->trigger();
    }

    /**
     * Take a live article back to draft.
     *
     * @param int $id
     * @param int $actorid
     * @return void
     */
    public function unpublish(int $id, int $actorid): void {
        $this->transition($id, kb_article::STATUS_DRAFT, $actorid);
    }

    /**
     * Withdraw a live article.
     *
     * @param int $id
     * @param int $actorid
     * @return void
     */
    public function archive(int $id, int $actorid): void {
        $this->transition($id, kb_article::STATUS_ARCHIVED, $actorid);
    }

    /**
     * Bring an archived article back as a draft.
     *
     * @param int $id
     * @param int $actorid
     * @return void
     */
    public function restore(int $id, int $actorid): void {
        $this->transition($id, kb_article::STATUS_DRAFT, $actorid);
    }

    /**
     * Delete an article and the files its body embedded.
     *
     * @param int $id
     * @param int $actorid
     * @return void
     */
    public function delete(int $id, int $actorid): void {
        edition_service::require_feature('knowledge_base');
        $this->policy->require_can_manage($actorid);

        $article = $this->require_article($id);
        $groupid = (int) $article->groupid;

        $transaction = $this->repo->begin_transaction();
        try {
            // What plugins keep about the row goes with it, in this transaction.
            \core\di::get(\core\hook\manager::class)->dispatch(new \local_moodesk\hook\kb_article_deleting($id));
            $this->repo->delete($id);

            // The canonical row is gone and translations remain: the group
            // is re-pointed to the lowest surviving id, so it keeps a row
            // whose id is its groupid. Ids, files and URLs do not change.
            if ($groupid === $id) {
                $survivors = $this->repo->list_group($groupid);
                if ($survivors) {
                    $this->repo->reparent_group($groupid, (int) min(array_keys($survivors)));
                }
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        get_file_storage()->delete_area_files(\context_system::instance()->id, 'local_moodesk', self::FILEAREA, $id);

        // After the commit, like the files.
        self::announce_unlisted($id);
    }

    /*
     * -------------------------------------------------------------------------
     * Internals
     * -------------------------------------------------------------------------
     */

    /**
     * A status change that publish() does not own.
     *
     * @param int $id
     * @param int $to
     * @param int $actorid
     * @return void
     */
    private function transition(int $id, int $to, int $actorid): void {
        edition_service::require_feature('knowledge_base');
        $this->policy->require_can_manage($actorid);

        $article = $this->require_article($id);
        $this->require_transition($article, $to);

        $update               = new \stdClass();
        $update->id           = $id;
        $update->status       = $to;
        $update->usermodified = $actorid;
        $update->timemodified = time();
        $this->repo->update($update);

        // Restore starts from archived: nothing to announce.
        if ((int) $article->status === kb_article::STATUS_PUBLISHED) {
            self::announce_unlisted($id);
        }
    }

    /**
     * Move the files of a user draft area under the article and store the
     * body with its URLs rewritten. The one place body and searchtext are
     * written together after files.
     *
     * @param int    $id
     * @param int    $draftitemid
     * @param string $body
     * @param int    $actorid
     * @param int    $now
     * @return void
     */
    protected function write_body(int $id, int $draftitemid, string $body, int $actorid, int $now): void {
        $context = \context_system::instance();
        $body    = file_save_draft_area_files(
            $draftitemid,
            $context->id,
            'local_moodesk',
            self::FILEAREA,
            $id,
            self::body_file_options(),
            $body
        );

        $update               = new \stdClass();
        $update->id           = $id;
        $update->body         = $body;
        $update->searchtext   = kb_article::derive_searchtext($body);
        $update->usermodified = $actorid;
        $update->timemodified = $now;
        $this->repo->update($update);
    }

    /**
     * The article, or an exception that does not say why.
     *
     * @param int $id
     * @return \stdClass
     * @throws \moodle_exception
     */
    protected function require_article(int $id): \stdClass {
        $article = $this->repo->get_by_id($id);
        if ($article === null) {
            throw new \moodle_exception('kb_article_notfound', 'local_moodesk');
        }
        return $article;
    }

    /**
     * Throw unless the domain allows the change.
     *
     * @param \stdClass $article
     * @param int       $to
     * @return void
     * @throws \moodle_exception
     */
    private function require_transition(\stdClass $article, int $to): void {
        if (!kb_article::is_valid_transition((int) $article->status, $to)) {
            throw new \moodle_exception('kb_invalid_transition', 'local_moodesk');
        }
    }

    /**
     * Write the group-shared fields to every row of the group. The only
     * writer of category and audience across a group; called inside the
     * caller's transaction.
     *
     * @param int   $groupid
     * @param array $shared categoryid and/or audience, already cleaned.
     * @return void
     */
    private function sync_shared_fields(int $groupid, array $shared): void {
        if ($shared) {
            $this->repo->update_group_fields($groupid, $shared);
        }
    }

    /**
     * A language code a row of the group may take: an installed pack the
     * group has no other row in.
     *
     * @param int    $groupid
     * @param string $lang
     * @param int    $excludeid The row being edited, left out of the uniqueness check.
     * @return string
     * @throws \moodle_exception
     */
    protected function require_new_group_lang(int $groupid, string $lang, int $excludeid = 0): string {
        $lang = clean_param($lang, PARAM_LANG);
        if ($lang === '') {
            throw new \moodle_exception('kb_invalid_lang', 'local_moodesk');
        }
        if ($this->repo->lang_exists_in_group($groupid, $lang, $excludeid)) {
            throw new \moodle_exception('kb_translation_exists', 'local_moodesk', '', kb_language::display_name($lang));
        }
        return $lang;
    }

    /**
     * A title fit to store.
     *
     * @param string $title
     * @return string
     * @throws \moodle_exception
     */
    protected function clean_title(string $title): string {
        $title = trim(clean_param($title, PARAM_TEXT));
        if ($title === '') {
            throw new \moodle_exception('kb_title_required', 'local_moodesk');
        }
        return \core_text::substr($title, 0, 255);
    }

    /**
     * A category id fit to store: null for none, otherwise an active one.
     *
     * The only place the knowledge base looks at the category table.
     *
     * @param mixed $categoryid
     * @return int|null
     * @throws \moodle_exception
     */
    private function clean_category($categoryid): ?int {
        $categoryid = (int) $categoryid;
        if ($categoryid === 0) {
            return null;
        }
        if (!$this->categories->exists_active($categoryid)) {
            throw new \moodle_exception('kb_invalid_category', 'local_moodesk');
        }
        return $categoryid;
    }

    /**
     * An audience fit to store.
     *
     * @param mixed $audience
     * @return int
     * @throws \moodle_exception
     */
    private function clean_audience($audience): int {
        $audience = (int) $audience;
        if (!in_array($audience, kb_article::audiences(), true)) {
            throw new \moodle_exception('kb_invalid_audience', 'local_moodesk');
        }
        return $audience;
    }

    /**
     * A language code fit to store: the site language unless a valid one was given.
     *
     * @param mixed $lang
     * @return string
     */
    protected function clean_lang($lang): string {
        global $CFG;
        $lang = clean_param((string) $lang, PARAM_LANG);
        return $lang !== '' ? $lang : (string) $CFG->lang;
    }

    /**
     * Filters a reader may apply, whitelisted.
     *
     * @param array $options
     * @return array
     */
    private function reader_filters(array $options): array {
        $filters = [];
        if (isset($options['categoryid'])) {
            $filters['categoryid'] = (int) $options['categoryid'];
        }
        if (!empty($options['lang'])) {
            $filters['lang'] = (string) $options['lang'];
        }
        // One row per group, by the chain given or the actor's own. In
        // Starter an explicit chain is still the site's: the reduced chain
        // is the edition's rule, not the caller's choice.
        if (!empty($options['resolve'])) {
            $filters['resolve'] = is_array($options['resolve']) && edition_service::has_feature('kb_multilingual')
                ? array_values($options['resolve'])
                : $this->chain_for();
        }
        // A reading surface shows live articles and nothing else, whoever is
        // reading. The policy's scope says what the actor MAY open — for an
        // editor, everything — and this narrows it to what a reader is shown.
        if (!empty($options['publishedonly'])) {
            $filters['status'] = kb_article::STATUS_PUBLISHED;
        }
        return $filters;
    }

    /**
     * Filters an editor may apply, whitelisted.
     *
     * @param array $options
     * @return array
     */
    private function manage_filters(array $options): array {
        $filters = $this->reader_filters($options);
        if (isset($options['status']) && in_array((int) $options['status'], kb_article::statuses(), true)) {
            $filters['status'] = (int) $options['status'];
        }
        return $filters;
    }

    /**
     * The distinct article ids linked in a body, in order of first appearance.
     *
     * Anchored on this site's wwwroot and the exact path the composer writes
     * (`/local/moodesk/kb_article.php?id=N`); a link to another site, or to
     * any other page, is not a share.
     *
     * @param string $body
     * @return int[]
     */
    public static function extract_article_ids(string $body): array {
        global $CFG;

        $root    = preg_quote($CFG->wwwroot, '~');
        $pattern = '~' . $root . '/local/moodesk/kb_article\.php\?id=(\d+)~';
        if (!preg_match_all($pattern, $body, $matches)) {
            return [];
        }
        $ids = [];
        foreach ($matches[1] as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Tell plugins a row stopped being published or is gone.
     *
     * @param int $id
     */
    private static function announce_unlisted(int $id): void {
        \core\di::get(\core\hook\manager::class)->dispatch(new \local_moodesk\hook\kb_article_unlisted($id));
    }
}
