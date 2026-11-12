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
 * Knowledge base — the articles management list.
 *
 * Every article in every status, for the people who write them. Filters by
 * status and category travel as GET parameters so a filtered page can be
 * bookmarked; the list is newest edit first, twenty to a page.
 *
 * Knowledge base is a Starter-tier feature: no edition gate. `kb_enabled`
 * is a presentation switch — see {@see \local_moodesk\output\kb_ui}.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_moodesk\domain\kb_article;
use local_moodesk\domain\kb_language;
use local_moodesk\output\header_action;
use local_moodesk\output\kb_ui;
use local_moodesk\output\page_header;
use local_moodesk\service\category_service;
use local_moodesk\service\kb_service;
use local_moodesk\util\user_fields;

require_login();

$context = context_system::instance();
if (!has_capability('local/moodesk:editkb', $context) && !has_capability('local/moodesk:managekb', $context)) {
    throw new required_capability_exception($context, 'local/moodesk:editkb', 'nopermissions', '');
}

/*
 * -------------------------------------------------------------------------
 * Filters. Status is a word rather than its constant so that ?status=draft
 * reads as what it is and so that "0" (draft) and "" (all) cannot be confused.
 * Category is "" for all, "none" for uncategorised, or an id.
 * -------------------------------------------------------------------------
 */

$statusparam   = optional_param('status', '', PARAM_ALPHA);
$categoryparam = optional_param('category', '', PARAM_ALPHANUMEXT);
$page          = max(0, optional_param('page', 0, PARAM_INT));
// Language (KB-06): a filter and a column, Pro only. One row per
// translation — the person who manages them needs to see EN Published beside
// ES Draft, so nothing here collapses the group.
$multilingual  = kb_ui::shows_languages();
// The parameter is `language`, not `lang`: core reads a GET `lang` on every page and switches
// the session language to it, which a filter must not do.
$langparam     = $multilingual ? optional_param('language', '', PARAM_LANG) : '';

$statuswords = [
    'draft'     => kb_article::STATUS_DRAFT,
    'published' => kb_article::STATUS_PUBLISHED,
    'archived'  => kb_article::STATUS_ARCHIVED,
];
if (!array_key_exists($statusparam, $statuswords)) {
    $statusparam = '';
}
if ($categoryparam !== '' && $categoryparam !== 'none' && !ctype_digit($categoryparam)) {
    $categoryparam = '';
}

$activeparams = [];
if ($statusparam !== '') {
    $activeparams['status'] = $statusparam;
}
if ($categoryparam !== '') {
    $activeparams['category'] = $categoryparam;
}
if ($langparam !== '') {
    $activeparams['language'] = $langparam;
}
$hasfilters = !empty($activeparams);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/moodesk/kb_articles.php', $activeparams + ($page ? ['page' => $page] : [])));
$PAGE->set_title(get_string('pluginname', 'local_moodesk') . ': ' . get_string('kb_articles_heading', 'local_moodesk'));
$PAGE->set_heading(get_string('page_global_heading', 'local_moodesk'));

// Layout, assets and chrome are owned by app_page (the mooDesk application
// shell on the embedded layout).
$apppage = new \local_moodesk\output\app_page($PAGE, 'kb');

if (!kb_ui::is_enabled()) {
    echo $apppage->render_within_shell(kb_ui::render_disabled(get_string('kb_articles_heading', 'local_moodesk')));
    exit;
}

$kbservice  = kb_service::create();
$catservice = category_service::create();

$filters = [];
if ($statusparam !== '') {
    $filters['status'] = $statuswords[$statusparam];
}
if ($categoryparam !== '') {
    $filters['categoryid'] = $categoryparam === 'none' ? 0 : (int) $categoryparam;
}
if ($langparam !== '') {
    $filters['lang'] = $langparam;
}

$total    = $kbservice->count_for_manage($USER->id, $filters);
$articles = $kbservice->list_for_manage($USER->id, $filters, kb_ui::PAGE_SIZE, $page * kb_ui::PAGE_SIZE);

/*
 * -------------------------------------------------------------------------
 * Build template context.
 * -------------------------------------------------------------------------
 */

// Filtered, not escaped — the template escapes what it prints.
$categorynames = array_map(
    static fn(string $n): string => format_string($n, true, ['escape' => false]),
    $catservice->get_name_map(),
);

// Whoever last touched each article, in one query rather than one per row.
$editorids = [];
foreach ($articles as $article) {
    if ((int) $article->usermodified > 0) {
        $editorids[(int) $article->usermodified] = true;
    }
}
$editors = $editorids
    ? $DB->get_records_list('user', 'id', array_keys($editorids), '', user_fields::for_fullname())
    : [];

$rows = [];
foreach ($articles as $article) {
    $pill   = kb_ui::status_pill((int) $article->status);
    $editor = $editors[(int) $article->usermodified] ?? null;

    $rows[] = [
        'id'            => (int) $article->id,
        'title'         => format_string($article->title, true, ['escape' => false]),
        'agentsonly'    => (int) $article->audience === kb_article::AUDIENCE_AGENTS,
        'agentsonlylabel' => kb_ui::audience_label(kb_article::AUDIENCE_AGENTS),
        'categoryname'  => $article->categoryid !== null
            ? ($categorynames[(int) $article->categoryid] ?? '')
            : '',
        'hascategory'   => $article->categoryid !== null && isset($categorynames[(int) $article->categoryid]),
        'statuslabel'   => $pill['label'],
        'statusvariant' => $pill['variant'],
        'langname'      => $multilingual ? kb_language::display_name((string) $article->lang) : '',
        'htmllang'      => $multilingual ? kb_language::html_lang((string) $article->lang) : '',
        'timemodified'  => userdate((int) $article->timemodified, get_string('strftimedatetimeshort', 'langconfig')),
        'editorname'    => $editor ? fullname($editor) : '',
        'haseditor'     => $editor !== null,
        'viewurl'       => (new moodle_url('/local/moodesk/kb_article.php', ['id' => $article->id]))->out(false),
        'editurl'       => (new moodle_url('/local/moodesk/kb_article_edit.php', ['id' => $article->id]))->out(false),
    ];
}

$statusoptions = [];
foreach ($statuswords as $word => $status) {
    $statusoptions[] = [
        'value'    => $word,
        'label'    => kb_ui::status_pill($status)['label'],
        'selected' => $word === $statusparam,
    ];
}

$categoryoptions = [];
foreach ($catservice->get_active_menu() as $id => $name) {
    $categoryoptions[] = [
        'value'    => (string) $id,
        'label'    => format_string($name, true, ['escape' => false]),
        'selected' => $categoryparam === (string) $id,
    ];
}

$langoptions = [];
if ($multilingual) {
    foreach (kb_language::installed_languages() as $code => $name) {
        $langoptions[] = [
            'value'    => (string) $code,
            'label'    => $name,
            'htmllang' => kb_language::html_lang((string) $code),
            'selected' => $langparam === (string) $code,
        ];
    }
}

$listurl = new moodle_url('/local/moodesk/kb_articles.php');
$newurl  = new moodle_url('/local/moodesk/kb_article_edit.php');

$paginationbar = '';
if ($total > kb_ui::PAGE_SIZE) {
    // The filters ride along, so page 2 is page 2 of the same list.
    $pbar = new paging_bar($total, $page, kb_ui::PAGE_SIZE, new moodle_url('/local/moodesk/kb_articles.php', $activeparams));
    $paginationbar = $OUTPUT->render($pbar);
}

$templatectx = [
    'articles'         => $rows,
    'hasarticles'      => !empty($rows),
    'hasfilters'       => $hasfilters,
    // Filters are offered whenever there is something to filter — rows, or
    // active filters that matched nothing and need changing. Only a knowledge
    // base with no articles at all has no toolbar.
    'showtoolbar'      => !empty($rows) || $hasfilters,
    'listurl'          => $listurl->out(false),
    'newurl'           => $newurl->out(false),
    'statusoptions'    => $statusoptions,
    'categoryoptions'  => $categoryoptions,
    'categorynoneselected' => $categoryparam === 'none',
    'multilingual'     => $multilingual,
    'langoptions'      => $langoptions,
    'total'            => $total,
    'shown'            => count($rows),
    'paginationbar'    => $paginationbar,
];

// The page header. New article goes through header_action::create_new(): the
// product's one answer to what a "New <entity>" control looks like.
$pageheader = (new page_header(get_string('kb_articles_heading', 'local_moodesk')))
    ->with_description(get_string('kb_articles_description', 'local_moodesk'));
// Secondary actions plugins add (hook kb_articles_actions).
$actionshook = new \local_moodesk\hook\kb_articles_actions((int) $USER->id);
\core\di::get(\core\hook\manager::class)->dispatch($actionshook);
foreach ($actionshook->get_actions() as $action) {
    $pageheader->with_action($action);
}
$templatectx['pageheader'] = $pageheader
    ->with_primary_action(header_action::create_new(
        get_string('kb_new_heading', 'local_moodesk'),
        $newurl,
    ))
    ->to_context();

echo $apppage->render_within_shell(
    $OUTPUT->render_from_template('local_moodesk/kb_articles', $templatectx)
);
