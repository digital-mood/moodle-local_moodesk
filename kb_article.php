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
 * Knowledge base — one article, and what may be done with it.
 *
 * The reader's page: breadcrumb, title, where it is filed and when it last
 * changed, the text, a way to open a ticket. For whoever holds the
 * capability, also the actions its status allows: edit, publish, unpublish,
 * archive, restore, delete. Which layers a viewer gets is
 * {@see \local_moodesk\output\kb_article_view}'s decision; each transition
 * is its own POST handled here and answered by one call into kb_service. The
 * page never decides whether a transition is legal.
 *
 * Who may open the page is kb_access_policy's answer, asked through
 * kb_service::get_article_for(): a draft or an archived article opens for
 * its writers only, a published one for whoever its audience names.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_moodesk\domain\kb_article;
use local_moodesk\output\kb_article_view;
use local_moodesk\output\kb_ui;
use local_moodesk\service\kb_service;

require_login();

$context = context_system::instance();
$id      = required_param('id', PARAM_INT);
// Where the reader came from, for plugins that count it (KB-07, D5): one known word or
// nothing. Not an id, not a token, not a campaign — a link from Suggested
// Articles says `suggest`, and that is the whole vocabulary. Anything else
// is dropped here and never reaches the recorder.
$from = optional_param('from', '', PARAM_ALPHA);
$origin = $from === kb_article::ORIGIN_SUGGEST ? $from : '';

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/moodesk/kb_article.php', ['id' => $id]));
$PAGE->set_heading(get_string('page_global_heading', 'local_moodesk'));
$PAGE->navbar->add(get_string('kb_heading', 'local_moodesk'), new moodle_url('/local/moodesk/kb.php'));

// Layout, assets and chrome are owned by app_page (the mooDesk application
// shell on the embedded layout). Opening an article is reading, whoever does
// it, so the shell lights Knowledge — never Articles, and never by referrer.
$apppage = new \local_moodesk\output\app_page($PAGE, 'kb_article');

if (!kb_ui::is_enabled()) {
    $PAGE->set_title(get_string('pluginname', 'local_moodesk') . ': ' . get_string('kb_article_heading', 'local_moodesk'));
    echo $apppage->render_within_shell(kb_ui::render_disabled(get_string('kb_article_heading', 'local_moodesk')));
    exit;
}

$kbservice = kb_service::create();

// Asks the policy; throws for an article the user may not see.
$article = $kbservice->get_article_for($id, $USER->id);

/*
 * -------------------------------------------------------------------------
 * Handle POST: a status change, or deletion.
 *
 * One request per transition. The service refuses what the domain or the
 * capability does not allow; an illegal transition arrives here only when two
 * people act on the same article at once, and is reported, not swallowed.
 * -------------------------------------------------------------------------
 */

$action = optional_param('action', '', PARAM_ALPHA);

if ($action !== '' && $_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $selfurl = new moodle_url('/local/moodesk/kb_article.php', ['id' => $id]);
    $listurl = new moodle_url('/local/moodesk/kb_articles.php');

    // Each action: the service method, the success string, where to go next.
    $transitions = [
        'publish'   => ['publish', 'kb_article_published', $selfurl],
        'unpublish' => ['unpublish', 'kb_article_unpublished', $selfurl],
        'archive'   => ['archive', 'kb_article_archived', $selfurl],
        'restore'   => ['restore', 'kb_article_restored', $selfurl],
        'delete'    => ['delete', 'kb_article_deleted', $listurl],
    ];
    if (!isset($transitions[$action])) {
        throw new moodle_exception('invalidaction', 'error');
    }
    [$method, $successstring, $nexturl] = $transitions[$action];

    try {
        $kbservice->$method($id, $USER->id);
    } catch (required_capability_exception $e) {
        throw $e;
    } catch (moodle_exception $e) {
        // A rule the domain enforces — no body to publish, a transition the
        // status does not allow. The user is told, on the page they were on.
        redirect($selfurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }

    redirect($nexturl, get_string($successstring, 'local_moodesk'), null, \core\output\notification::NOTIFY_SUCCESS);
}

/*
 * -------------------------------------------------------------------------
 * Render. Everything shown — reader or editor layers, breadcrumb, actions,
 * the way to a ticket — is kb_article_view's decision.
 * -------------------------------------------------------------------------
 */

// The read that just happened, after the policy allowed it and only on the GET
// that renders. For the standard log, on every edition, so a plugin can observe it.
\local_moodesk\event\kb_article_viewed::create([
    'context'  => context_system::instance(),
    'objectid' => (int) $article->id,
    'other'    => ['origin' => (string) $origin],
])->trigger();

$view = kb_article_view::create($article, $USER->id);

$PAGE->set_title(get_string('pluginname', 'local_moodesk') . ': ' . $view->title());

$templatectx = $view->export();
$templatectx['pageheader'] = $view->header()->to_context();

echo $apppage->render_within_shell(
    $OUTPUT->render_from_template('local_moodesk/kb_article', $templatectx)
);
