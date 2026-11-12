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
 * Knowledge base — write or edit an article.
 *
 * ?id=N                      → edit an existing article (its status is left alone).
 *                              draft in N's group (KB-06, Pro). Not `lang`:
 *                              core reads a GET `lang` on every page and
 *                              switches the session language to it.
 * ?fromticket=N              → a new draft a plugin prefills (hook kb_article_draft_defaults).
 * (nothing)                  → write a new one, saved as a draft.
 *
 * The form writes content only. Every change of status is an action on the
 * article page, its own request — see kb_article.php.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_moodesk\domain\kb_language;
use local_moodesk\form\kb_article_form;
use local_moodesk\output\form_page;
use local_moodesk\output\kb_ui;
use local_moodesk\output\page_header;
use local_moodesk\policy\kb_access_policy;
use local_moodesk\service\category_service;
use local_moodesk\service\kb_service;

require_login();

$context = context_system::instance();
if (!has_capability('local/moodesk:editkb', $context) && !has_capability('local/moodesk:managekb', $context)) {
    throw new required_capability_exception($context, 'local/moodesk:editkb', 'nopermissions', '');
}

$id = optional_param('id', 0, PARAM_INT);

// KB-05 — a new draft started from a ticket. The parameter is the whole of
// the "create from ticket" contract: the service turns it into a title and a
// category (and nothing else), the form is prefilled, and the article is
// created — or not — when the editor saves. Editing an existing article
// ignores it: there is nothing to prefill. The page URL and the form's action
// carry it so the notice below survives a validation re-display.
$fromticket = $id ? 0 : optional_param('fromticket', 0, PARAM_INT);

$urlparams = [];
if ($id) {
    $urlparams = ['id' => $id];
} else if ($fromticket) {
    $urlparams = ['fromticket' => $fromticket];
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/moodesk/kb_article_edit.php', $urlparams));

if ($id) {
    $pagetitle = get_string('kb_edit_heading', 'local_moodesk');
} else {
    $pagetitle = get_string('kb_new_heading', 'local_moodesk');
}

$PAGE->set_title(get_string('pluginname', 'local_moodesk') . ': ' . $pagetitle);
$PAGE->set_heading(get_string('page_global_heading', 'local_moodesk'));

$PAGE->navbar->add(get_string('kb_articles_heading', 'local_moodesk'), new moodle_url('/local/moodesk/kb_articles.php'));
$PAGE->navbar->add($pagetitle);

// Layout, assets and chrome are owned by app_page (the mooDesk application
// shell on the embedded layout). The moodleform is captured via render()
// instead of display() so it can be mounted in the shell.
$apppage = new \local_moodesk\output\app_page($PAGE, 'kb_article_edit');

if (!kb_ui::is_enabled()) {
    echo $apppage->render_within_shell(kb_ui::render_disabled($pagetitle));
    exit;
}

$kbservice = kb_service::create();
$policy    = new kb_access_policy();
$canmanage = $policy->can_manage($USER->id);

// The article being edited, fetched through the service so the policy is asked.
$article = $id ? $kbservice->get_article_for($id, $USER->id) : null;

// What a plugin lends the draft. A callback refuses by throwing, so a hand-made
// URL gets the same answer a hidden control would have.
$fromdefaults = null;
$fromnotice = '';
if ($fromticket) {
    $defaultshook = new \local_moodesk\hook\kb_article_draft_defaults($fromticket, (int) $USER->id);
    \core\di::get(\core\hook\manager::class)->dispatch($defaultshook);
    $fromdefaults = $defaultshook->get_defaults();
    $fromnotice = $defaultshook->get_notice();
}

// The language layer of the form (KB-06): Pro only. On an existing row the
// group decides whether category and audience are frozen.
$multilingual = kb_ui::shows_languages();
$siblings     = $article ? $kbservice->get_translations_for((int) $article->groupid, $USER->id) : [];

$form = new kb_article_form(
    new moodle_url('/local/moodesk/kb_article_edit.php', $urlparams),
    [
        'id'         => $id,
        'status'     => $article ? (int) $article->status : 0,
        'canmanage'  => $canmanage,
        // The select's template escapes option labels; filtered only here.
        'categories' => array_map(
            static fn(string $n): string => format_string($n, true, ['escape' => false]),
            category_service::create()->get_active_menu()
        ),
        'languages'  => $multilingual ? kb_language::installed_languages() : [],
        'shared'     => count($siblings) > 1,
    ]
);

if ($form->is_cancelled()) {
    redirect($id
        ? new moodle_url('/local/moodesk/kb_article.php', ['id' => $id])
        : new moodle_url('/local/moodesk/kb_articles.php'));
}

$formdata = $form->get_data();
if ($formdata !== null) {
    // The editor element hands back text + format + the draft area holding
    // whatever was embedded. The service moves the files under the article and
    // stores the body with its URLs rewritten, in one place. The form decides
    // which fields travel: a frozen category or audience — a row with
    // siblings, a live article for an editor
    // without :managekb — is left out, so nothing pretends the user made a
    // change they were not offered (see kb_article_form::to_service_data()).
    $data = $form->to_service_data($formdata);

    try {
        if ($id) {
            $kbservice->update_article($id, $data, $USER->id);
            $msg = get_string('kb_article_saved', 'local_moodesk');
        } else {
            $id  = $kbservice->create_draft($data, $USER->id);
            $msg = get_string('kb_draft_created', 'local_moodesk');
        }
    } catch (required_capability_exception $e) {
        throw $e;
    } catch (moodle_exception $e) {
        // A rule the service enforces that the form cannot know in advance —
        // a language another translation already has, a category archived
        // since the page was opened. The user is told, on the page they were
        // on, and nothing was written.
        redirect(
            new moodle_url('/local/moodesk/kb_article_edit.php', $urlparams),
            $e->getMessage(),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    redirect(
        new moodle_url('/local/moodesk/kb_article.php', ['id' => $id]),
        $msg,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Pre-populate on GET (not after a re-display with errors). The body goes
// through a user draft area so its @@PLUGINFILE@@ images become editable
// draft files; the id of that area travels in the editor's hidden itemid.
if (!$form->is_submitted()) {
    $draftitemid = file_get_submitted_draft_itemid('body');
    $bodytext    = file_prepare_draft_area(
        $draftitemid,
        $context->id,
        'local_moodesk',
        kb_service::FILEAREA,
        $id ?: null,
        kb_article_form::editor_options(),
        $article ? $article->body : ''
    );

    $defaults = [
        'body' => ['text' => $bodytext, 'format' => FORMAT_HTML, 'itemid' => $draftitemid],
    ];
    if ($article) {
        $defaults['title']      = $article->title;
        $defaults['categoryid'] = (int) ($article->categoryid ?? 0);
        $defaults['audience']   = (int) $article->audience;
        $defaults['lang']       = (string) $article->lang;
    } else if ($fromdefaults) {
        // Title and category, and deliberately not audience: the form's own
        // default applies, as it does to any blank draft. The language is the
        // agent's own — what the service said.
        $defaults['title']      = $fromdefaults['title'];
        $defaults['categoryid'] = $fromdefaults['categoryid'];
        $defaults['lang']       = $fromdefaults['lang'];
    } else {
        // A blank draft starts in the language the editor reads in.
        $defaults['lang']       = clean_param(current_language(), PARAM_LANG);
    }
    $form->set_data($defaults);
}

// The notice that says where this draft came from, and what did not come
// with it. Not persisted anywhere: it is on this page and nowhere else.
$notice = $fromnotice;

// Sections plugins add above the form of an existing article.
$panel = '';
if ($article) {
    $panelshook = new \local_moodesk\hook\kb_article_edit_panels($article, (int) $USER->id);
    $panel = implode('', array_column($panelshook->dispatch_and_render($OUTPUT), 'html'));
}

echo $apppage->render_within_shell(
    form_page::render($panel . $notice . $form->render(), new page_header($pagetitle))
);
