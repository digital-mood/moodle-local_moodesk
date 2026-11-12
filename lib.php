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
 * Plugin library functions.
 *
 * Only Moodle callback hooks belong here. Business logic belongs in
 * classes/service/. Do not add application logic to this file.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Declare user preferences owned by this plugin.
 *
 * Lets the application shell persist its per-user UI state through the standard
 * core_user AJAX preference endpoint (no custom web service required).
 *
 *   local_moodesk_sidebar_collapsed — whether the shell sidebar is collapsed to
 *   its icon-only rail. Each user may only read/write their own value.
 *
 * @return array preference definitions keyed by preference name
 */
function local_moodesk_user_preferences(): array {
    return [
        'local_moodesk_sidebar_collapsed' => [
            'type' => PARAM_BOOL,
            'null' => NULL_NOT_ALLOWED,
            'default' => '0',
            'permissioncallback' => [\core_user::class, 'is_current_user'],
        ],
    ];
}

/**
 * Render the ticket detail for the overlay drawer on the ticket list.
 *
 * Moodle's fragment API is what makes the drawer possible without a second
 * implementation of the screen: it renders the same
 * {@see \local_moodesk\output\ticket_detail} component the canonical page uses
 * and collects the JavaScript requirements Moodle queues while doing it, so the
 * editor and the file manager inside the composer initialise exactly as they do
 * on view.php. Access is enforced by the component (which asks the ticket
 * service), not here.
 *
 * @param array $args Fragment arguments; 'ticketid' is required.
 * @return string Rendered detail HTML.
 */
function local_moodesk_output_fragment_ticket_detail(array $args): string {
    global $PAGE, $USER;

    require_login();

    $ticketid = (int) ($args['ticketid'] ?? 0);
    if ($ticketid <= 0) {
        throw new moodle_exception('ticketnotfound', 'local_moodesk');
    }

    $detail = \local_moodesk\output\ticket_detail::create($ticketid, (int) $USER->id);

    return $detail->render($PAGE, \local_moodesk\output\ticket_detail::VARIANT_DRAWER);
}

/**
 * Re-render the ticket list's results region for a query already on screen.
 *
 * The list is server-rendered, and until now the only way to see a mutation land
 * in it was to fetch the page again. A property saved from the ticket detail
 * drawer never navigates, so the rows behind the panel kept whatever the page
 * load had put there. This fragment is the missing half: the same
 * {@see \local_moodesk\output\ticket_list_results} component index.php builds,
 * rendered on its own so the region can be replaced in place.
 *
 * The whole query string comes over as one argument rather than fifteen named
 * ones. That is deliberate: ticket_list_query cleans it with exactly the rules
 * the page uses, so the parameter surface cannot drift between the two entry
 * points, and a filter added later needs no change here at all.
 *
 * Nothing about access is decided here. The capabilities below choose which list
 * is being asked for — an agent's queue or a requester's own tickets — and the
 * ticket service is what enforces reach, exactly as it does for the page.
 *
 * @param array $args Fragment arguments; 'query' is the list URL's query string.
 * @return string Rendered results region HTML.
 */
function local_moodesk_output_fragment_ticket_list_results(array $args): string {
    global $OUTPUT, $USER;

    require_login();

    $context = context_system::instance();
    if (
        !has_capability('local/moodesk:viewall', $context)
            && !has_capability('local/moodesk:viewown', $context)
    ) {
        require_capability('local/moodesk:viewown', $context);
    }

    $isagent = has_capability('local/moodesk:viewall', $context);
    $canbulk = has_capability('local/moodesk:manage', $context);

    $query = \local_moodesk\output\ticket_list_query::from_query_string(
        (string) ($args['query'] ?? ''),
        $isagent
    );

    return \local_moodesk\output\ticket_list_results::create($query, (int) $USER->id, $canbulk)
        ->render($OUTPUT);
}

/**
 * Serve plugin files from the two declared file areas.
 *
 * File areas used by this plugin:
 *   ticket_attachment   itemid = ticket ID   (files attached at ticket creation)
 *   reply_attachment    itemid = reply ID    (files attached to a reply or note)
 *   kb_article          itemid = article ID  (images embedded in an article body)
 *
 * Access rules:
 *   Whether the reader may see the owning ticket is decided by
 *   {@see \local_moodesk\policy\ticket_access_policy}, the same rule the ticket
 *   page and the REST API ask — not restated here.
 *   Internal note attachments are never served to requesters.
 *   Whether the reader may see the owning article is decided by
 *   {@see \local_moodesk\policy\kb_access_policy} the same way, so an image in
 *   a draft is exactly as reachable as the draft: not at all, to a requester.
 *
 * A file the browser would execute rather than display is always handed over as
 * a download, whatever the request asked for. See
 * {@see \local_moodesk\util\attachment_disposition}.
 *
 * @param stdClass   $course     (unused in Phase 1 — system-level plugin)
 * @param stdClass   $cm         (unused)
 * @param context    $context    The context the file was uploaded in.
 * @param string     $filearea   'ticket_attachment', 'reply_attachment' or 'kb_article'.
 * @param array      $args       Remaining path parts: [itemid, filename].
 * @param bool       $forcedownload
 * @param array      $options
 */
function local_moodesk_pluginfile(
    $course,
    $cm,
    \context $context,
    string $filearea,
    array $args,
    bool $forcedownload,
    array $options = []
): void {
    // The requester branch below reads $USER; without it declared here that
    // path fatals instead of serving the file.
    global $DB, $USER;

    require_login();

    $syscontext = \context_system::instance();

    if (!in_array($filearea, ['ticket_attachment', 'reply_attachment', 'kb_article'], true)) {
        send_file_not_found();
    }

    $itemid   = (int) array_shift($args);
    $filename = array_shift($args);

    if ($filearea === 'kb_article') {
        local_moodesk_send_kb_article_file($context, $itemid, $filename, (int) $USER->id, $forcedownload, $options);
        return;
    }

    // Resolve the ticket that owns this file.
    if ($filearea === 'ticket_attachment') {
        $ticket = $DB->get_record('local_moodesk_ticket', ['id' => $itemid], 'id,userid', MUST_EXIST);
    } else {
        // Area reply_attachment — look up the ticket via the reply.
        $reply  = $DB->get_record('local_moodesk_reply', ['id' => $itemid], 'id,ticketid,type', MUST_EXIST);
        $ticket = $DB->get_record('local_moodesk_ticket', ['id' => $reply->ticketid], 'id,userid', MUST_EXIST);

        // Never serve internal note attachments to requesters.
        if (
            (int) $reply->type === \local_moodesk\domain\reply::TYPE_INTERNAL_NOTE
                && !has_capability('local/moodesk:viewall', $syscontext)
        ) {
            send_file_not_found();
        }
    }

    // Who may read this ticket is not a question this file gets to answer on its
    // own — it asks the same policy the ticket page and the API ask, so the
    // attachment can never be reachable by someone the ticket itself is not.
    $policy = new \local_moodesk\policy\ticket_access_policy();
    if (!$policy->can_view((int) $USER->id, $ticket)) {
        send_file_not_found();
    }

    $fs   = get_file_storage();
    $file = $fs->get_file($context->id, 'local_moodesk', $filearea, $itemid, '/', $filename);

    if (!$file || $file->is_directory()) {
        send_file_not_found();
    }

    // The forcedownload flag comes from the query string, and core only
    // ever forces it for SVG — so an .html attachment would be served inline
    // and execute on this site's own origin, in the session of the agent
    // reading the ticket. Whoever attached it is the least trusted party in the
    // system, and with email ingestion enabled need not have an account at all.
    // Never weaken what the caller asked for: only ever add to it.
    $forcedownload = $forcedownload
        || \local_moodesk\util\attachment_disposition::must_force_download($file);

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Serve a file embedded in a knowledge base article body.
 *
 * Split out of local_moodesk_pluginfile() because an article is not a ticket:
 * it has its own policy and no requester branch. The decision lives in
 * {@see local_moodesk_kb_article_file_for()} so it can be tested without
 * sending anything; this function only delivers what that one allows.
 *
 * @param context $context   The context the file was uploaded in.
 * @param int     $articleid Owning article (the file's itemid).
 * @param string  $filename
 * @param int     $userid    Who is asking.
 * @param bool    $forcedownload
 * @param array   $options
 * @return void
 */
function local_moodesk_send_kb_article_file(
    \context $context,
    int $articleid,
    string $filename,
    int $userid,
    bool $forcedownload,
    array $options
): void {
    $file = local_moodesk_kb_article_file_for($context, $articleid, $filename, $userid);
    if ($file === null) {
        send_file_not_found();
    }

    // Same rule as attachments: never weaken what the caller asked for, only
    // ever add to it. The editor accepts web images only, so this is belt and
    // braces rather than the guard — but the guard is a form option, and
    // this is what stands if the form changes.
    $forcedownload = $forcedownload
        || \local_moodesk\util\attachment_disposition::must_force_download($file);

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * The article file the user may have, or null.
 *
 * The article is fetched raw and handed to kb_access_policy, so a draft's
 * image, an agents-only article's image seen by a requester, a missing
 * article and a missing file all come back as the same null: nothing about
 * the refusal says which it was.
 *
 * @param context $context   The context the file was uploaded in.
 * @param int     $articleid Owning article (the file's itemid).
 * @param string  $filename
 * @param int     $userid    Who is asking.
 * @return stored_file|null
 */
function local_moodesk_kb_article_file_for(\context $context, int $articleid, string $filename, int $userid): ?\stored_file {
    $article = (new \local_moodesk\repository\kb_article_repository())->get_by_id($articleid);
    if ($article === null) {
        return null;
    }

    $policy = new \local_moodesk\policy\kb_access_policy();
    if (!$policy->can_view($userid, $article)) {
        return null;
    }

    $file = get_file_storage()->get_file(
        $context->id,
        'local_moodesk',
        \local_moodesk\service\kb_service::FILEAREA,
        $articleid,
        '/',
        $filename
    );
    if (!$file || $file->is_directory()) {
        return null;
    }
    return $file;
}
