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
 * Sending a reply or an internal note without leaving the drawer.
 *
 * The composer is one moodleform posting to view.php, and that stays true: it
 * is the no-JavaScript path, it is what the canonical page uses, and it is the
 * fallback here. What this module changes is where the answer lands. Inside the
 * ticket detail drawer a redirect is not a detail of the transport — it takes
 * the panel down and the list behind it with it, for the single most common
 * action on the screen. So the same form is sent to ajax.php, which runs the
 * same controller against the same rules, and the panel re-renders in place.
 *
 * Only in the drawer. On view.php the form submits normally, redirect and all:
 * there is nothing to preserve there, and the page's own confirmation already
 * works.
 *
 * Three things are deliberate:
 *
 *  - **A refused send keeps the reply.** The server saying no is a decision the
 *    user has to read, with the text still in the box. Only a transport failure
 *    falls back to the plain POST, because there the reply's one safe home is
 *    the request the browser makes itself.
 *  - **Cancel is not this module's business.** In the drawer it means "leave
 *    this ticket", and the button carries the panel's own close hook
 *    (`data-action="mhd-drawer-close"`, set in classes/form/reply_form.php), so
 *    the drawer's delegated handler takes the click and stops the submit before
 *    it ever reaches here. One close, in one place. On the canonical page the
 *    hook is inert and the button submits, which is where `is_cancelled()`
 *    picks it up.
 *  - **The client's own validation goes first.** moodleform's rules run on the
 *    same submit event; if they refused it, so does this. That order is not
 *    left to chance: this module listens on the composer wrapper, not on the
 *    form. moodleform registers its validator on the form itself, so it runs
 *    at the target, and a submit event bubbles — by the time it reaches the
 *    wrapper the validator has had its say and `defaultPrevented` means what
 *    it says. Listening on the form would make the answer depend on which
 *    listener was registered first, and in the drawer that is the order two
 *    `require()` callbacks happen to run in: the detail queues this module's
 *    init before it renders the form that queues the validator, so with the
 *    modules cached this listener registered first, ran first, saw nothing
 *    prevented, and sent an empty message the server had to refuse — one
 *    inline message from the form and one toast from the server, for one
 *    press.
 *
 * Idempotent: the drawer re-runs init() over freshly injected markup on every
 * ticket it loads, and the wrapper is part of that markup: each render is a
 * new wrapper, bound once.
 *
 * @module     local_moodesk/reply_submit
 * @copyright  2026 Digital Mood LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Pending from 'core/pending';
import * as Toast from 'local_moodesk/toast';
import {notifyChanged} from 'local_moodesk/ticket_list';
import {requestReload, setSubmitting} from 'local_moodesk/ticket_detail_drawer';

const SELECTORS = {
    editor: '#mhd-reply-editor',
    drawer: '[data-region="mhd-detail-drawer"]',
    input: '[data-region="mhd-composer-input"]',
    ticketid: 'input[name="ticketid"]',
    submits: 'input[type="submit"], button[type="submit"]',
};

const BOUND = 'mhdReplyBound';

/**
 * Send the composer's contents.
 *
 * @param {Element} form   The moodleform inside the composer.
 * @param {Element} editor The composer wrapper, which carries the endpoint.
 * @return {Promise<void>}
 */
const send = async(form, editor) => {
    const url = editor.dataset.mhdAjax;
    const ticketinput = form.querySelector(SELECTORS.ticketid);
    const ticketid = ticketinput ? ticketinput.value : null;

    const body = new FormData(form);
    // The page carries this in the form's action URL; ajax.php dispatches on
    // the POST, so it is stated here instead.
    body.set('action', 'reply');

    const buttons = form.querySelectorAll(SELECTORS.submits);
    buttons.forEach((button) => {
        button.disabled = true;
    });
    form.classList.add('is-sending');

    // Held until the answer is handled, so anything waiting for the page to
    // settle (Behat's "page is ready", above all) waits for the send too.
    const pending = new Pending('local_moodesk/reply_submit:send');

    // Whether the server answered at all. A refusal is a decision and belongs on
    // screen; a transport failure is not, and falls back to the plain POST.
    let answered = false;

    try {
        const response = await fetch(url, {
            method: 'POST',
            body,
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        });
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        const data = await response.json();
        answered = true;
        if (!data.success) {
            throw new Error(data.error || '');
        }

        // The send is over before anything else happens: the reply is on the
        // server, so there is no unsent work left for the guard to protect.
        setSubmitting(false);

        if (data.message) {
            Toast.show(data.message, 'success');
        }

        // A reply moves what the list shows about this ticket — when it was last
        // touched, whether its first response SLA is still running. What exactly,
        // the list asks the server; this only says that something did.
        notifyChanged(ticketid, 'reply_submit', 'reply');

        // Not keepdraft: the message was sent, so the composer that comes back
        // should be the empty one, with a draft area of its own.
        requestReload();
    } catch (err) {
        window.console.warn('local_moodesk reply_submit: send failed.', err);

        buttons.forEach((button) => {
            button.disabled = false;
        });
        form.classList.remove('is-sending');

        if (!answered) {
            // Transport failure. The reply is still only in this browser, and
            // the plain POST is the way it survives — it runs the same action
            // through the same server-side checks, and lands on the ticket's own
            // page. Leaving the drawer is the cost of not losing the message.
            form.submit();
            return;
        }

        // The server refused. The reply stays in the box, where the user can
        // read what happened and decide.
        setSubmitting(false);
        Toast.show(err.message || editor.dataset.mhdToastFailed, 'error');
    } finally {
        pending.resolve();
    }
};

/**
 * Wire the composer where it is inside the drawer. Safe to call repeatedly.
 */
export const init = () => {
    const editor = document.querySelector(SELECTORS.editor);
    if (!editor || !editor.closest(SELECTORS.drawer) || !editor.dataset.mhdAjax) {
        return; // The canonical page, or a screen with no composer: nothing to do.
    }

    const form = editor.querySelector('form');
    if (!form || editor.dataset[BOUND]) {
        return;
    }
    editor.dataset[BOUND] = '1';

    // On the wrapper, in the bubbling phase: see the module docblock. The form
    // is the event's target, and it is the only form the wrapper holds.
    editor.addEventListener('submit', (event) => {
        if (event.target !== form) {
            return;
        }

        // moodleform's client-side rules ran at the target and stopped the event
        // if the message is empty. Their refusal is this module's refusal, and
        // nothing is sent: the message never left the browser, so there is no
        // failure for the server to report.
        if (event.defaultPrevented) {
            return;
        }

        event.preventDefault();
        send(form, editor);
    });
};
