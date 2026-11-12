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
use local_moodesk\policy\kb_access_policy;
use local_moodesk\service\category_service;
use local_moodesk\service\kb_service;
use moodle_url;

/**
 * The knowledge base landing — browse and search, as a reader sees it.
 *
 * Builds the whole context of kb.php from three inputs: a query, a category
 * and a page. Everything the template shows is decided here — which of the
 * three shapes the list takes (browse, search, empty), which categories the
 * navigation offers and in what order, what an excerpt says, where "Open a
 * ticket" points — so the entry point stays a request handler and the
 * template stays presentation.
 *
 * Two rules from the UI plan (docs/kb-reader-ui-plan.md) are enforced here
 * and nowhere else:
 *
 *  - The surface is a READING surface: it lists published articles only,
 *    whoever is looking. An editor may open a draft; this page never offers
 *    one (`publishedonly` on every service read).
 *  - Search is global. A query ignores the selected category, and the
 *    category navigation stays on screen only as orientation.
 *  - One card per article, whatever its translations (KB-06): every read
 *    resolves through the service, so the reader gets the row in their
 *    language when it exists and a badged fallback when it does not, and
 *    never the same article twice. Counts and pages count groups.
 *
 * Search is bounded on purpose: the service ranks up to SEARCH_FETCH_LIMIT
 * candidates and this page shows the RESULT_LIMIT best, with no pagination.
 * That is a conscious MVP limitation (measured in KB-03 — see the plan's
 * §13), not the search's final contract; it is revisited if real knowledge
 * bases or query behaviour call for deeper result navigation.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class knowledge_page {
    /** Articles per page when browsing. */
    public const PAGE_SIZE = 20;

    /** Search results shown — the best of what the service ranked. */
    public const RESULT_LIMIT = 20;

    /** The category parameter value for uncategorised articles. */
    public const CATEGORY_NONE = 'none';

    /** @var kb_service */
    private kb_service $service;

    /** @var category_service */
    private category_service $categories;

    /** @var kb_access_policy */
    private kb_access_policy $policy;

    /** @var int */
    private int $actorid;

    /** @var string What the user typed, trimmed. */
    private string $query;

    /** @var string[] The usable tokens of the query; empty when it cannot search. */
    private array $tokens;

    /** @var string Raw category parameter: '', 'none' or digits. */
    private string $categoryparam;

    /** @var int Zero-based browse page. */
    private int $page;

    /** @var int|null Articles the last export()ed search found; null until then. */
    private ?int $resultcount = null;

    /**
     * Constructor.
     *
     * @param kb_service       $service
     * @param category_service $categories
     * @param kb_access_policy $policy
     * @param int              $actorid       Acting user.
     * @param string           $query         The `q` parameter.
     * @param string           $categoryparam The `category` parameter.
     * @param int              $page          The `page` parameter.
     */
    public function __construct(
        kb_service $service,
        category_service $categories,
        kb_access_policy $policy,
        int $actorid,
        string $query = '',
        string $categoryparam = '',
        int $page = 0
    ) {
        $this->service       = $service;
        $this->categories    = $categories;
        $this->policy        = $policy;
        $this->actorid       = $actorid;
        $this->query         = trim($query);
        $this->tokens        = kb_service::tokenize($this->query);
        $this->categoryparam = $categoryparam;
        $this->page          = max(0, $page);
    }

    /**
     * Production wiring.
     *
     * @param int    $actorid
     * @param string $query
     * @param string $categoryparam
     * @param int    $page
     * @return self
     */
    public static function create(int $actorid, string $query = '', string $categoryparam = '', int $page = 0): self {
        return new self(
            kb_service::create(),
            category_service::create(),
            new kb_access_policy(),
            $actorid,
            $query,
            $categoryparam,
            $page
        );
    }

    /**
     * Whether the page is showing search results rather than a browse list.
     *
     * @return bool
     */
    public function is_search(): bool {
        return !empty($this->tokens);
    }

    /**
     * How many articles the search found, once export() has run — for the
     * page to hand to analytics (KB-07). Null while browsing or before export.
     *
     * @return int|null
     */
    public function search_result_count(): ?int {
        return $this->is_search() ? $this->resultcount : null;
    }

    /**
     * The page header.
     *
     * @return page_header
     */
    public function header(): page_header {
        return (new page_header(get_string('kb_heading', 'local_moodesk')))
            ->with_description(get_string('kb_heading_desc', 'local_moodesk'));
    }

    /**
     * The template context for local_moodesk/kb_landing.
     *
     * @return array
     */
    public function export(): array {
        $counts     = $this->service->count_visible_by_category($this->actorid, ['publishedonly' => true, 'resolve' => true]);
        // Names go through format_string for filters but are NOT escaped
        // here: the templates escape what they print, and escaping twice is
        // how "Errors & crashes" becomes "Errors &amp; crashes" on screen.
        $names      = array_map(
            static fn(string $n): string => format_string($n, true, ['escape' => false]),
            $this->categories->get_name_map()
        );
        $active     = $this->categories->get_active_menu();
        $total      = array_sum($counts);
        $canedit    = $this->policy->can_edit($this->actorid);
        $cansubmit  = has_capability('local/moodesk:submit', \context_system::instance(), $this->actorid);

        // Which category the URL asked for, resolved against what exists:
        // null for "all", 0 for "No category", an id for a category that exists.
        $selected = $this->resolve_category($names);

        $context = [
            'searchbox'   => $this->export_search_box(),
            'categorynav' => $this->export_category_nav($counts, $names, $selected),
            'hasarticles' => $total > 0,
            'canedit'     => $canedit,
            'newurl'      => (new moodle_url('/local/moodesk/kb_article_edit.php'))->out(false),
            'landingurl'  => (new moodle_url('/local/moodesk/kb.php'))->out(false),
            'issearch'    => false,
            'articles'    => [],
            'hasrows'     => false,
            'paginationbar' => '',
        ];

        if ($this->is_search()) {
            $context = array_merge($context, $this->export_results($names));
        } else {
            $context = array_merge($context, $this->export_browse($counts, $names, $selected));
        }

        // The way out when the answer is not here. Filed under the selected
        // category when that is a real, active one; otherwise wherever the
        // ticket form defaults to.
        $ticketparams = [];
        if ($selected !== null && $selected > 0 && isset($active[$selected])) {
            $ticketparams['categoryid'] = $selected;
        }
        $context['cta'] = [
            'show'   => $cansubmit,
            'url'    => (new moodle_url('/local/moodesk/submit.php', $ticketparams))->out(false),
            'text'   => get_string('kb_cta_landing', 'local_moodesk'),
            'button' => get_string('kb_cta_button', 'local_moodesk'),
        ];

        return $context;
    }

    /**
     * Resolve the category parameter against the categories that exist.
     *
     * @param array $names Every category, id => name.
     * @return int|null null = all; 0 = uncategorised; otherwise an existing id.
     */
    private function resolve_category(array $names): ?int {
        if ($this->categoryparam === self::CATEGORY_NONE) {
            return 0;
        }
        if ($this->categoryparam !== '' && ctype_digit($this->categoryparam)) {
            $id = (int) $this->categoryparam;
            if ($id > 0 && isset($names[$id])) {
                return $id;
            }
        }
        return null;
    }

    /**
     * The search box context. The hint appears when something was typed that
     * cannot be searched (every word under three characters).
     *
     * @return array
     */
    private function export_search_box(): array {
        $box = [
            'action'      => (new moodle_url('/local/moodesk/kb.php'))->out(false),
            'name'        => 'q',
            'value'       => $this->query,
            'label'       => get_string('kb_search_label', 'local_moodesk'),
            'placeholder' => get_string('kb_search_placeholder', 'local_moodesk'),
            'buttonlabel' => get_string('kb_search_button', 'local_moodesk'),
            'hiddenfields' => [],
        ];
        if ($this->query !== '' && !$this->is_search()) {
            $box['hint']   = get_string('kb_search_hint_short', 'local_moodesk', kb_service::MIN_TOKEN_LENGTH);
            $box['hintid'] = 'mhd-kb-search-hint';
        }
        return $box;
    }

    /**
     * The category navigation: "All", then every category with at least one
     * visible article in the categories' own order, then "No category" for the
     * uncategorised ones when there are any. Never "General": that is the name
     * db/install.php gives the default ticket category, and the two would sit
     * side by side in this nav looking like one thing.
     *
     * @param array $counts   categoryid => visible articles (0 = uncategorised).
     * @param array $names    Every category, id => name, in display order.
     * @param int|null           $selected See resolve_category().
     * @return array
     */
    private function export_category_nav(array $counts, array $names, ?int $selected): array {
        // While a search is showing nothing in the nav is "current": the
        // results are not a category, and marking "All" would say they were.
        $marking = !$this->is_search();

        $items = [];
        foreach ($names as $id => $name) {
            if (empty($counts[$id])) {
                continue;
            }
            $items[] = $this->nav_item($name, (string) $id, $counts[$id], $marking && $selected === $id);
        }
        if (!empty($counts[0])) {
            $items[] = $this->nav_item(
                get_string('kb_category_none', 'local_moodesk'),
                self::CATEGORY_NONE,
                $counts[0],
                $marking && $selected === 0
            );
        }

        return [
            'label'    => get_string('kb_categories_nav_label', 'local_moodesk'),
            'all'      => $this->nav_item(
                get_string('kb_all_articles', 'local_moodesk'),
                '',
                array_sum($counts),
                $marking && $selected === null
            ),
            'items'    => $items,
            'hasitems' => !empty($items),
            // Orientation only while searching: the nav stays, the results do
            // not narrow — see the class comment.
            'dimmed'   => $this->is_search(),
        ];
    }

    /**
     * One entry of the category navigation.
     *
     * @param string $label
     * @param string $param  Value of the `category` parameter; '' for all.
     * @param int    $count
     * @param bool   $active
     * @return array
     */
    private function nav_item(string $label, string $param, int $count, bool $active): array {
        return [
            'label'  => $label,
            'url'    => (new moodle_url('/local/moodesk/kb.php', $param === '' ? [] : ['category' => $param]))->out(false),
            'count'  => $count,
            // Read as "12 articles" by a screen reader; the number alone on screen.
            'countsr' => get_string('kb_count_articles_sr', 'local_moodesk', $count),
            'active' => $active,
        ];
    }

    /**
     * The browse list: a page of published articles, all or in one category.
     *
     * @param array $counts
     * @param array $names
     * @param int|null           $selected
     * @return array
     */
    private function export_browse(array $counts, array $names, ?int $selected): array {
        global $OUTPUT;

        // One card per article group, in the reader's language when it
        // exists (KB-06): counts and pages are groups too.
        $filters = ['publishedonly' => true, 'resolve' => true];
        if ($selected !== null) {
            $filters['categoryid'] = $selected;
        }
        $total    = array_sum($counts);
        $incategory = $selected === null ? $total : (int) ($counts[$selected] ?? 0);
        $articles = $incategory > 0
            ? $this->service->list_visible($this->actorid, $filters, self::PAGE_SIZE, $this->page * self::PAGE_SIZE)
            : [];

        if ($selected === null) {
            $heading = get_string('kb_all_articles', 'local_moodesk');
        } else if ($selected === 0) {
            $heading = get_string('kb_category_none', 'local_moodesk');
        } else {
            $heading = $names[$selected];
        }

        $paginationbar = '';
        if ($incategory > self::PAGE_SIZE) {
            $params = $selected === null ? [] : ['category' => $this->categoryparam];
            $pbar = new \paging_bar($incategory, $this->page, self::PAGE_SIZE, new moodle_url('/local/moodesk/kb.php', $params));
            $paginationbar = $OUTPUT->render($pbar);
        }

        // Which empty state, if any. "Nothing at all" and "nothing in this
        // category" are different sentences to a reader.
        $empty = null;
        if ($total === 0) {
            $empty = 'none';
        } else if ($incategory === 0) {
            $empty = 'category';
        }

        return [
            'issearch'      => false,
            'heading'       => $heading,
            'headingcount'  => $incategory,
            'articles'      => $this->export_articles($articles, $names, []),
            'hasrows'       => !empty($articles),
            'paginationbar' => $paginationbar,
            'emptynone'     => $empty === 'none',
            'emptycategory' => $empty === 'category',
            'emptyresults'  => false,
        ];
    }

    /**
     * Search results: the best RESULT_LIMIT of what the service ranked, and a
     * flag when there were more than that.
     *
     * @param array $names
     * @return array
     */
    private function export_results(array $names): array {
        // One more than shown, so "there were more" is known without a count
        // query the ranking would not agree with anyway.
        $ranked  = $this->service->search($this->query, $this->actorid, [
            'limit'         => self::RESULT_LIMIT + 1,
            'publishedonly' => true,
            'resolve'       => true,
        ]);
        $hasmore = count($ranked) > self::RESULT_LIMIT;
        $shown   = array_slice($ranked, 0, self::RESULT_LIMIT, true);
        $count   = count($shown);
        $this->resultcount = count($ranked);

        $a = (object) ['count' => $count, 'query' => $this->query];
        if ($count === 0) {
            $heading = get_string('kb_search_none_title', 'local_moodesk', $this->query);
        } else if ($count === 1) {
            $heading = get_string('kb_search_results_one', 'local_moodesk', $a);
        } else if ($hasmore) {
            $heading = get_string('kb_search_results_more', 'local_moodesk', $a);
        } else {
            $heading = get_string('kb_search_results', 'local_moodesk', $a);
        }

        return [
            'issearch'      => true,
            'query'         => $this->query,
            'heading'       => $heading,
            'headingcount'  => null,
            'hasmore'       => $hasmore,
            'morehint'      => get_string('kb_search_more_hint', 'local_moodesk', self::RESULT_LIMIT),
            'clearurl'      => (new moodle_url('/local/moodesk/kb.php'))->out(false),
            'articles'      => $this->export_articles($shown, $names, $this->tokens),
            'hasrows'       => $count > 0,
            'paginationbar' => '',
            'emptynone'     => false,
            'emptycategory' => false,
            'emptyresults'  => $count === 0,
        ];
    }

    /**
     * Article cards. Title and excerpt come out as HTML with the search
     * tokens marked, escaped first — see kb_ui::highlight(). A card in a
     * language other than the reader's carries a badge and a `lang`
     * attribute (Pro); one in their own language carries nothing.
     *
     * @param \stdClass[]        $articles
     * @param array $names
     * @param string[]           $tokens
     * @return array
     */
    private function export_articles(array $articles, array $names, array $tokens): array {
        $cards = [];
        foreach ($articles as $article) {
            $title   = format_string($article->title, true, ['escape' => false]);
            $excerpt = kb_ui::excerpt($article->body, $tokens);
            $cards[] = [
                'id'           => (int) $article->id,
                'url'          => (new moodle_url('/local/moodesk/kb_article.php', ['id' => $article->id]))->out(false),
                'titlehtml'    => kb_ui::highlight($title, $tokens),
                'excerpthtml'  => kb_ui::highlight($excerpt, $tokens),
                'hasexcerpt'   => $excerpt !== '',
                'hascategory'  => $article->categoryid !== null && isset($names[(int) $article->categoryid]),
                'categoryname' => $article->categoryid !== null ? ($names[(int) $article->categoryid] ?? '') : '',
                'updated'      => userdate((int) $article->timemodified, get_string('strftimedate', 'langconfig')),
                'agentsonly'   => (int) $article->audience === kb_article::AUDIENCE_AGENTS,
                'agentsonlylabel' => kb_ui::audience_label(kb_article::AUDIENCE_AGENTS),
                'langbadge'    => kb_ui::language_badge((string) $article->lang),
            ];
        }
        return $cards;
    }
}
