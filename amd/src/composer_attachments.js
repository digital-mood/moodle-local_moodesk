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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Attachments on a form that composes a message: a button, and the files behind it.
 *
 * Neither of the two forms that use this renders Moodle's file manager. The
 * reply composer renders a paperclip in its action bar that discloses the files
 * the draft holds, so a reply with no attachments costs the conversation
 * nothing; the New ticket form renders an "Attach files" button under its
 * description, which opens the picker directly. Both are the same mechanism with
 * a different lid (ADR: docs/reply-composer-attachments.md).
 *
 * What Moodle still owns is everything that matters: the draft area, the upload,
 * the repositories, the size and count limits, the File API and the save on
 * submit. This module owns the button and the list, and nothing else.
 *
 * Four rules hold the design together:
 *
 *  - **The list is never the source of truth.** After every upload and every
 *    delete the draft is re-read from the server and the list is rebuilt from
 *    that answer. Nothing is inferred from what was just clicked, so a failed
 *    delete or a rejected upload leaves the UI telling the truth rather than a
 *    story.
 *  - **Collapsing is not discarding.** Where the control is a disclosure, the
 *    panel is a view over a draft area that exists on the server; closing it
 *    changes what is on screen and nothing else. The count on the button is what
 *    says so.
 *  - **The file picker is somebody else's problem.** All of it — YUI, the `M`
 *    globals, the instance registry — is behind local_moodesk/filepicker_bridge.
 *    Nothing in this file knows the picker's shape.
 *  - **A composer is wired by name, not by search.** The ticket list can hold
 *    the New ticket drawer and the ticket detail drawer at once, each with a
 *    composer of its own, so every session is keyed by the region it was handed
 *    and the module never guesses at the first match in the document. Getting
 *    this wrong uploads one form's file into the other form's draft.
 *
 * @module     local_moodesk/composer_attachments
 * @copyright  2026 Digital Mood LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Pending from 'core/pending';
import * as FilePicker from 'local_moodesk/filepicker_bridge';
import * as Toast from 'local_moodesk/toast';
import Templates from 'core/templates';
import {get_string as getString} from 'core/str';

const SELECTORS = {
    root: '[data-region="mhd-composer-attachments"]',
    toggle: '[data-action="mhd-toggle-attachments"]',
    addfile: '[data-action="mhd-attach-file"]',
    remove: '[data-action="mhd-remove-attachment"]',
    panel: '[data-region="mhd-attach-panel"]',
    list: '[data-region="mhd-attach-list"]',
    count: '[data-region="mhd-attach-count"]',
};

const LIST_TEMPLATE = 'local_moodesk/composer_attachment_list';

/**
 * The wired composers, keyed by the region element each one owns.
 *
 * @type {Map<Element, object>}
 */
const sessions = new Map();

/** @type {number} Bumped on every wire and unwire; stale replies check it. */
let token = 0;

/**
 * The sessions inside a scope, or all of them when none is given.
 *
 * @param {Element} [scope] Container to limit the answer to.
 * @return {object[]}
 */
const within = (scope) => Array.from(sessions.values())
    .filter((session) => !scope || scope.contains(session.root));

/**
 * Whether a message in progress is holding files the user has not sent.
 *
 * Read from what the server last reported, not from the DOM, and deliberately
 * not from whether a panel happens to be open. A file uploaded into the draft is
 * unsent work in its own right: unlike a half-written message it leaves no trace
 * in the composer, so an empty message with three files attached is exactly as
 * unsent as a written one, and the close guard has to know.
 *
 * @param {Element} [scope] Limit the question to the composers inside this
 *                          element. Without it, any composer on the page
 *                          answers — which is what a whole-page guard wants and
 *                          what a single panel's guard must not use.
 * @return {boolean}
 */
export const hasDraftFiles = (scope) => within(scope).some((session) => session.files.length > 0);

/**
 * Ask the draft endpoint something, and get back what the draft now holds.
 *
 * Both actions answer with the full listing, so a delete is also the re-sync
 * that follows it — one round trip, and no window in which the list and the
 * draft could disagree.
 *
 * @param {object} session The composer asking.
 * @param {string} action  'draft_list' or 'draft_delete'.
 * @param {object} [extra] Extra POST fields.
 * @return {Promise<Array>} The draft's files.
 */
const request = async(session, action, extra = {}) => {
    const {config} = session;

    const body = new FormData();
    body.append('action', action);
    body.append('sesskey', config.sesskey);
    body.append('ticketid', config.ticketid);
    body.append('draftitemid', config.draftitemid);
    Object.keys(extra).forEach((key) => body.append(key, extra[key]));

    // Held for the round trip, so anything waiting for the page to settle
    // (Behat's "page is ready", above all) waits for the draft too.
    const pending = new Pending('local_moodesk/composer_attachments:' + action);

    try {
        const response = await fetch(config.ajaxurl, {method: 'POST', body});
        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || '');
        }

        return data.files || [];
    } finally {
        pending.resolve();
    }
};

/**
 * Open or close the file panel.
 *
 * A no-op where the control is not a disclosure: the New ticket form shows its
 * files as soon as it has any, and has no toggle to reconcile.
 *
 * @param {object}  session
 * @param {boolean} open
 */
const setPanel = (session, open) => {
    if (!session.toggle) {
        return;
    }

    session.open = open;
    session.panel.hidden = !open;
    session.toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
};

/**
 * Put the draft's contents on screen.
 *
 * A disclosure's open state is left alone: collapsing it is a decision about
 * what the user wants to look at, and an upload finishing is no reason to
 * overrule that. The count on the button is what keeps a closed panel honest.
 *
 * @param {object} session
 * @param {Array}  files Files the server reported.
 * @param {number} mine  The session token this render belongs to.
 */
const render = (session, files, mine) => {
    if (session.token !== mine || !sessions.has(session.root)) {
        return; // Another composer took this region while the request was in flight.
    }

    session.files = files;

    if (session.count) {
        session.count.hidden = files.length === 0;
        session.count.textContent = files.length;
    }

    if (session.addfile) {
        // A limit Moodle enforces on save anyway; saying so before the upload is
        // friendlier than saying so after it.
        session.addfile.disabled = session.config.maxfiles > 0
            && files.length >= session.config.maxfiles;
    }

    if (!files.length) {
        session.list.innerHTML = '';
        return;
    }

    Templates.render(LIST_TEMPLATE, {files}).then((html, js) => {
        if (session.token !== mine || !sessions.has(session.root)) {
            return;
        }
        Templates.replaceNodeContents(session.list, html, js);
        return;
    }).catch(() => {
        // The draft is intact either way; only its picture failed to draw.
        window.console.warn('local_moodesk: could not render the attachment list.');
    });
};

/**
 * Report a failure the user needs to know about.
 *
 * @param {string} key Language string identifier.
 */
const warn = (key) => {
    getString(key, 'local_moodesk')
        .then((msg) => Toast.show(msg, 'error'))
        .catch(() => window.console.warn('local_moodesk: ' + key));
};

/**
 * Re-read the draft and redraw from what it actually holds.
 *
 * @param {object}  session
 * @param {number}  mine  The session token this sync belongs to.
 * @param {boolean} [loud] Tell the user when the read fails. Set after an upload,
 *                         where silence would read as "the file did not attach".
 * @return {Promise}
 */
const resync = (session, mine, loud = false) => {
    // The composer may have been released while this was queued — a picker
    // callback arriving after the drawer moved on, for instance.
    if (session.token !== mine || !sessions.has(session.root)) {
        return Promise.resolve();
    }

    return request(session, 'draft_list')
        .then((files) => render(session, files, mine))
        .catch(() => {
            window.console.warn('local_moodesk: could not read the attachment draft.');
            if (loud) {
                warn('attachment_upload_error');
            }
        });
};

/**
 * Open Moodle's file picker over this composer's draft area.
 *
 * @param {object} session
 */
const attach = (session) => {
    const mine = session.token;

    FilePicker.open(session.config.filepicker, () => {
        // The picker has already written the file into the draft; what it holds
        // now is a question for the server. A disclosure that the user never
        // opened stays closed — the count is what reports the upload — but a
        // control that is not a disclosure has nothing to open and shows the
        // file as soon as the list is redrawn.
        resync(session, mine, true);
    }).catch(() => warn('attachment_picker_error'));
};

/**
 * Remove one file from the draft.
 *
 * @param {object} session
 * @param {string} filename
 * @param {string} filepath
 */
const remove = (session, filename, filepath) => {
    const mine = session.token;
    // The row is about to be replaced along with the rest of the list, taking
    // the focused element with it.
    const refocus = session.addfile;

    request(session, 'draft_delete', {filename, filepath: filepath || '/'})
        .then((files) => {
            render(session, files, mine);
            if (refocus && !refocus.disabled) {
                refocus.focus();
            }
            return;
        })
        .catch(() => {
            warn('attachment_remove_error');
            // The list may now be describing a file that is gone, or one that is
            // still there; either way the draft decides, not this click.
            resync(session, mine);
        });
};

/**
 * Release wired composers.
 *
 * Called before the markup is discarded, never after: the picker's registry is
 * global and resolves through nodes that are about to stop existing.
 *
 * @param {Element} [scope] Release only the composers inside this element.
 *                          Without it every composer on the page is released,
 *                          which is right for a teardown and wrong for a panel
 *                          that shares the screen with another form.
 */
export const destroy = (scope) => {
    within(scope).forEach((session) => {
        session.root.removeEventListener('click', session.onClick);
        FilePicker.release(session.config.clientid);
        sessions.delete(session.root);
    });

    token++; // Anything still in flight now belongs to nobody.
};

/**
 * Wire one attachment control.
 *
 * @param {Element} root The region, carrying its config as a data attribute.
 */
const wire = (root) => {
    if (sessions.has(root)) {
        return; // Already ours.
    }

    let config;
    try {
        config = JSON.parse(root.dataset.config || 'null');
    } catch (e) {
        config = null;
    }
    if (!config) {
        return;
    }

    const panel = root.querySelector(SELECTORS.panel);
    const list = root.querySelector(SELECTORS.list);
    const addfile = root.querySelector(SELECTORS.addfile);
    if (!panel || !list || !addfile) {
        return;
    }

    // Without YUI there is no picker to open, so the control would promise
    // something it cannot deliver.
    if (!FilePicker.isAvailable()) {
        root.hidden = true;
        return;
    }

    const session = {
        config,
        files: [],
        open: false,
        root,
        // Present on the reply composer, absent on the New ticket form: one
        // discloses its files, the other simply shows them.
        toggle: root.querySelector(SELECTORS.toggle),
        panel,
        list,
        addfile,
        count: root.querySelector(SELECTORS.count),
        onClick: null,
        token: ++token,
    };

    session.onClick = (e) => {
        // The listener is removed on release, so this only guards the gap
        // between a click landing and the handler running.
        if (!sessions.has(root) || !e.target.closest) {
            return;
        }

        if (session.toggle && e.target.closest(SELECTORS.toggle)) {
            e.preventDefault();
            setPanel(session, !session.open);
            return;
        }

        if (e.target.closest(SELECTORS.addfile)) {
            e.preventDefault();
            attach(session);
            return;
        }

        const row = e.target.closest(SELECTORS.remove);
        if (row) {
            e.preventDefault();
            remove(session, row.dataset.filename, row.dataset.filepath);
        }
    };

    sessions.set(root, session);
    root.addEventListener('click', session.onClick);

    // The draft is not always empty on arrival: a submission that failed
    // validation comes back with the same draft item id and whatever was already
    // uploaded into it. Those files are shown rather than merely counted — the
    // user did not close this panel, so nothing is being overruled by opening it.
    const mine = session.token;
    resync(session, mine).then(() => {
        if (session.token === mine && session.files.length) {
            setPanel(session, true);
        }
        return;
    }).catch(() => {
        // resync reports its own failures.
    });
};

/**
 * Wire every attachment control on the page that is not wired yet.
 *
 * Takes no arguments, and reads each control's configuration off the element
 * itself. The config carries the whole file picker options object, which is well
 * past the 1024 characters Moodle accepts as a js_call_amd argument before it
 * warns that there are better ways to move data from PHP — and there are: this
 * is one.
 *
 * It also means a caller does not have to know which regions exist. The fragment
 * that loads a ticket into the detail drawer simply calls this, and a region
 * that is already wired is left alone — so the New ticket form's control is not
 * torn down and rebuilt every time an agent opens a ticket.
 */
export const init = () => {
    document.querySelectorAll(SELECTORS.root).forEach(wire);
};
