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
use local_moodesk\domain\kb_language;
use local_moodesk\service\kb_service;
use local_moodesk\policy\kb_access_policy;
use local_moodesk\service\category_service;
use local_moodesk\util\user_fields;
use moodle_url;

/**
 * One article, as the person opening it should see it.
 *
 * The same page serves a requester reading an answer and a manager deciding
 * whether it stays live, and the two must not see the same thing: a reader
 * gets the text, where it is filed, when it last changed and a way to open a
 * ticket; an editor gets, on top, the status, who touched it, and every
 * action KB-02 gave them. Which layers a viewer gets is decided HERE, from
 * capabilities, once — so kb_article.php stays a request handler and the
 * template never asks who is looking.
 *
 * Rules carried from the UI plan (docs/kb-reader-ui-plan.md §3.3, §10):
 *
 *  - A reader sees no administrative UI: no status pill, no names, no
 *    actions, no "first published".
 *  - The header holds the workflow's next step (Publish on a draft, Restore
 *    on an archived article) and Edit; Unpublish, Archive and Delete are the
 *    article's footer, `:managekb` only.
 *  - The breadcrumb is Knowledge base › Category for everyone who may read:
 *    opening an article is reading, whoever does it.
 *  - "Open a ticket" preselects the article's category only while that
 *    category is active; an archived one is simply not passed along.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class kb_article_view {
    /** @var \stdClass The raw article row. */
    private \stdClass $article;

    /** @var int */
    private int $actorid;

    /** @var kb_access_policy */
    private kb_access_policy $policy;

    /** @var category_service */
    private category_service $categories;

    /** @var bool */
    private bool $canedit;

    /** @var bool */
    private bool $canmanage;

    /** @var int */
    private int $status;

    /** @var kb_service|null Built on first use; only the translations ask for it. */
    private ?kb_service $service;

    /** @var \stdClass[]|null The group's rows the actor may see, keyed by id, once asked. */
    private ?array $translations = null;

    /**
     * Constructor.
     *
     * @param \stdClass        $article    An article the actor may see (the caller asked the policy).
     * @param int              $actorid    Acting user.
     * @param kb_access_policy $policy
     * @param category_service $categories
     * @param kb_service|null  $service    Injected in tests; built lazily otherwise.
     */
    public function __construct(
        \stdClass $article,
        int $actorid,
        kb_access_policy $policy,
        category_service $categories,
        ?kb_service $service = null
    ) {
        $this->article    = $article;
        $this->actorid    = $actorid;
        $this->policy     = $policy;
        $this->categories = $categories;
        $this->service    = $service;
        $this->canedit    = $policy->can_edit($actorid);
        $this->canmanage  = $policy->can_manage($actorid);
        $this->status     = (int) $article->status;
    }

    /**
     * Production wiring.
     *
     * @param \stdClass $article
     * @param int       $actorid
     * @return self
     */
    public static function create(\stdClass $article, int $actorid): self {
        return new self($article, $actorid, new kb_access_policy(), category_service::create());
    }

    /**
     * The rows of this article's group the actor may see, lowest id first.
     *
     * @return \stdClass[] Keyed by id; always holds this row.
     */
    public function translations(): array {
        if ($this->translations === null) {
            $this->service ??= kb_service::create();
            $this->translations = $this->service->get_translations_for((int) $this->article->groupid, $this->actorid);
        }
        return $this->translations;
    }

    /**
     * The title, filtered but not escaped — page_header and the breadcrumb
     * escape what they print.
     *
     * @return string
     */
    public function title(): string {
        return format_string($this->article->title, true, ['escape' => false]);
    }

    /**
     * Whether the viewer gets the editorial layers.
     *
     * @return bool
     */
    public function is_editor(): bool {
        return $this->canedit;
    }

    /**
     * Which transitions the page offers, by status and capability.
     *
     * @return array{publish: bool, unpublish: bool, archive: bool, restore: bool, delete: bool}
     */
    public function transitions(): array {
        return [
            'publish'   => $this->canmanage && kb_article::is_valid_transition($this->status, kb_article::STATUS_PUBLISHED),
            'unpublish' => $this->canmanage && $this->status === kb_article::STATUS_PUBLISHED,
            'archive'   => $this->canmanage && kb_article::is_valid_transition($this->status, kb_article::STATUS_ARCHIVED),
            'restore'   => $this->canmanage && $this->status === kb_article::STATUS_ARCHIVED,
            'delete'    => $this->canmanage,
        ];
    }

    /**
     * The page header: the title; for editors also the status and the actions.
     *
     * @return page_header
     */
    public function header(): page_header {
        $header = new page_header($this->title());
        if (!$this->canedit) {
            return $header;
        }

        $pill = kb_ui::status_pill($this->status);
        $header->with_meta(\html_writer::span($pill['label'], 'mhd-pill mhd-pill--' . $pill['variant']));

        $editaction = new header_action(
            get_string('kb_edit_heading', 'local_moodesk'),
            new moodle_url('/local/moodesk/kb_article_edit.php', ['id' => $this->article->id]),
            'edit',
        );
        $transitions = $this->transitions();
        $primary = null;
        if ($transitions['publish']) {
            $primary = header_action::submit(get_string('kb_action_publish', 'local_moodesk'), 'mhd-kb-action-publish');
        } else if ($transitions['restore']) {
            $primary = header_action::submit(get_string('kb_action_restore', 'local_moodesk'), 'mhd-kb-action-restore', 'restore');
        }
        if ($primary !== null) {
            $header->with_action($editaction);
        }
        $header->with_primary_action($primary ?? $editaction);

        return $header;
    }

    /**
     * The template context for local_moodesk/kb_article.
     *
     * @return array
     */
    public function export(): array {
        global $DB, $OUTPUT;

        $article    = $this->article;
        $names      = $this->categories->get_name_map();
        $active     = $this->categories->get_active_menu();
        $categoryid = $article->categoryid !== null ? (int) $article->categoryid : null;
        $hascategory = $categoryid !== null && isset($names[$categoryid]);
        // Filtered, not escaped: the template escapes what it prints.
        $categoryname = $hascategory
            ? format_string($names[$categoryid], true, ['escape' => false])
            : get_string('kb_category_none', 'local_moodesk');
        $dateformat = get_string('strftimedate', 'langconfig');
        $transitions = $this->transitions();

        $context = [
            'id'            => (int) $article->id,
            'breadcrumb'    => $this->export_breadcrumb($hascategory, $categoryid, $categoryname),
            'renderedbody'  => kb_ui::render_body($article),
            'hasbody'       => trim($article->searchtext) !== '',
            'isdraft'       => $this->status === kb_article::STATUS_DRAFT,
            'iseditor'      => $this->canedit,
            'categoryname'  => $categoryname,
            'categoryurl'   => $this->category_url($hascategory, $categoryid),
            'audiencelabel' => kb_ui::audience_label((int) $article->audience),
            'agentsonly'    => (int) $article->audience === kb_article::AUDIENCE_AGENTS,
            'timemodified'  => userdate((int) $article->timemodified, $dateformat),
            'actionurl'     => (new moodle_url('/local/moodesk/kb_article.php', ['id' => $article->id]))->out(false),
            'sesskey'       => sesskey(),
            'canpublish'    => $transitions['publish'],
            'canunpublish'  => $transitions['unpublish'],
            'canarchive'    => $transitions['archive'],
            'canrestore'    => $transitions['restore'],
            'candelete'     => $transitions['delete'],
            'haslifecycle'  => $transitions['unpublish'] || $transitions['archive'] || $transitions['delete'],
            'cta'           => $this->export_cta($hascategory, $categoryid, $active),
            // Editorial facts, filled below for editors only.
            'haseditor'     => false,
            'editorname'    => '',
            'haspublished'  => false,
            'timepublished' => '',
            // Language (KB-06, Pro): absent, not blank, when there is nothing to say.
            'htmllang'      => $this->export_html_lang(),
        ];

        // Sections plugins add (hook kb_article_view_extend).
        $hook = new \local_moodesk\hook\kb_article_view_extend($article, $this->actorid, $this->canedit);
        $context['pluginsections'] = $hook->dispatch_and_render($OUTPUT);
        $context['haspluginsections'] = !empty($context['pluginsections']);
        $context['plugintop'] = $hook->render_top($OUTPUT);
        $context['pluginfacts'] = $hook->render_facts($OUTPUT);

        if ($this->canedit) {
            $editor = (int) $article->usermodified > 0
                ? $DB->get_record('user', ['id' => $article->usermodified], user_fields::for_fullname())
                : null;
            $context['haseditor']     = $editor !== null && $editor !== false;
            $context['editorname']    = $editor ? fullname($editor) : '';
            $context['haspublished']  = !empty($article->timepublished);
            $context['timepublished'] = !empty($article->timepublished)
                ? userdate((int) $article->timepublished, $dateformat)
                : '';
        }

        return $context;
    }

    /* ----------------------------------------------------------------------
     * Language (KB-06)
     * -------------------------------------------------------------------- */

    /**
     * The `lang` attribute for the article surface: set when the row is in
     * a language other than the page's (Pro), else '' and the attribute is
     * not rendered.
     *
     * @return string
     */
    private function export_html_lang(): string {
        $lang = (string) $this->article->lang;
        if (!kb_ui::shows_languages() || !kb_ui::is_foreign($lang)) {
            return '';
        }
        return kb_language::html_lang($lang);
    }

    /**
     * Knowledge base › Category. The category step goes to the landing
     * filtered on it — an archived category still has its articles there.
     *
     * @param bool     $hascategory
     * @param int|null $categoryid
     * @param string   $categoryname
     * @return array
     */
    private function export_breadcrumb(bool $hascategory, ?int $categoryid, string $categoryname): array {
        return [
            'label' => get_string('kb_breadcrumb_label', 'local_moodesk'),
            'items' => [
                [
                    'label'   => get_string('kb_heading', 'local_moodesk'),
                    'url'     => (new moodle_url('/local/moodesk/kb.php'))->out(false),
                    'current' => false,
                ],
                [
                    'label'   => $categoryname,
                    'url'     => $this->category_url($hascategory, $categoryid),
                    'current' => false,
                ],
                [
                    'label'   => $this->title(),
                    'url'     => null,
                    'current' => true,
                ],
            ],
        ];
    }

    /**
     * The landing filtered on the article's category ("No category" for none).
     *
     * @param bool     $hascategory
     * @param int|null $categoryid
     * @return string
     */
    private function category_url(bool $hascategory, ?int $categoryid): string {
        return (new moodle_url('/local/moodesk/kb.php', [
            'category' => $hascategory ? (string) $categoryid : knowledge_page::CATEGORY_NONE,
        ]))->out(false);
    }

    /**
     * "Open a ticket": shown to whoever may submit, filed under the article's
     * category only while that category is active.
     *
     * @param bool               $hascategory
     * @param int|null           $categoryid
     * @param array $active Active categories, id => name.
     * @return array
     */
    private function export_cta(bool $hascategory, ?int $categoryid, array $active): array {
        $params = [];
        if ($hascategory && isset($active[$categoryid])) {
            $params['categoryid'] = $categoryid;
        }
        return [
            'show'   => has_capability('local/moodesk:submit', \context_system::instance(), $this->actorid),
            'url'    => (new moodle_url('/local/moodesk/submit.php', $params))->out(false),
            'text'   => get_string('kb_cta_article', 'local_moodesk'),
            'button' => get_string('kb_cta_button', 'local_moodesk'),
        ];
    }
}
