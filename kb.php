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
 * Knowledge base — the reader's entry point: browse and search.
 *
 * ?q=…            search, global (the category is orientation only)
 * ?category=N     browse one category; `none` for the uncategorised ones
 * ?page=P         browse pagination
 *
 * Everything shown is decided by {@see \local_moodesk\output\knowledge_page};
 * this file reads the request and hands the result to the shell.
 *
 * Knowledge base is a Starter-tier feature: no edition gate. `kb_enabled`
 * is a presentation switch — see {@see \local_moodesk\output\kb_ui}.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_moodesk\output\kb_ui;
use local_moodesk\output\knowledge_page;

require_login();

$context = context_system::instance();
require_capability('local/moodesk:viewkb', $context);

$query    = optional_param('q', '', PARAM_TEXT);
$category = optional_param('category', '', PARAM_ALPHANUMEXT);
$page     = max(0, optional_param('page', 0, PARAM_INT));

$urlparams = [];
if (trim($query) !== '') {
    $urlparams['q'] = $query;
} else if ($category !== '') {
    $urlparams['category'] = $category;
}
if ($page > 0 && empty($urlparams['q'])) {
    $urlparams['page'] = $page;
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/moodesk/kb.php', $urlparams));
$PAGE->set_title(get_string('pluginname', 'local_moodesk') . ': ' . get_string('kb_heading', 'local_moodesk'));
$PAGE->set_heading(get_string('page_global_heading', 'local_moodesk'));

// Layout, assets and chrome are owned by app_page (the mooDesk application
// shell on the embedded layout).
$apppage = new \local_moodesk\output\app_page($PAGE, 'knowledge');

if (!kb_ui::is_enabled()) {
    echo $apppage->render_within_shell(kb_ui::render_disabled(get_string('kb_heading', 'local_moodesk')));
    exit;
}

$landing = knowledge_page::create($USER->id, $query, $category, $page);

$templatectx = $landing->export();
$templatectx['pageheader'] = $landing->header()->to_context();

// A search that just ran, on every edition: the standard log gets the count
// without its words, and plugins get the words only redacted and only when the
// search found nothing. Paging the same query is not a new search.
if ($landing->search_result_count() !== null) {
    $tokens = \local_moodesk\service\kb_service::tokenize($query);
    if ($tokens) {
        $resultcount = (int) $landing->search_result_count();
        \local_moodesk\event\kb_searched::from_result_count($resultcount)->trigger();
        \core\di::get(\core\hook\manager::class)->dispatch(new \local_moodesk\hook\kb_search_recorded(
            (int) $USER->id,
            $resultcount,
            $resultcount === 0 ? \local_moodesk\domain\kb_search_redaction::normalise_query($tokens) : null
        ));
    }
}

echo $apppage->render_within_shell(
    $OUTPUT->render_from_template('local_moodesk/kb_landing', $templatectx)
);
