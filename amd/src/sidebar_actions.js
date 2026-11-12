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
 * Saving the ticket's properties from the detail, without a page in between.
 *
 * Every control here is a real <form> that posts to view.php. This module
 * upgrades them, in two shapes:
 *
 *  - **The rail's properties.** The select saves on `change` through ajax.php
 *    and the per-row Save button is hidden, because with auto-save wired it has
 *    nothing left to do.
 *  - **Forms that are submitted as a whole** — the custom field values, undoing
 *    a merge. These keep their button; what changes is that the answer comes
 *    back as JSON instead of as a new page. Only inside the drawer, where a
 *    redirect would take the panel down; on the canonical page they post the
 *    ordinary way, exactly as they always did.
 *
 * Without JavaScript, and whenever the AJAX call fails, that same form submits
 * the normal way — the server re-runs every capability, transition and
 * validation rule either way, so the shortcut can never save something the slow
 * path would have refused.
 *
 * Idempotent by design: the ticket detail drawer re-runs init() every time it
 * loads a ticket, over freshly injected markup.
 *
 * @module     local_moodesk/sidebar_actions
 * @copyright  2026 Digital Mood LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Pending from 'core/pending';
import * as Toast from 'local_moodesk/toast';
import {notifyChanged} from 'local_moodesk/ticket_list';
import {requestReload, setSubmitting} from 'local_moodesk/ticket_detail_drawer';

const SELECTORS = {
    autosave: 'form[data-mhd-autosave]',
    confirm: 'form[data-mhd-confirm]',
    submit: 'form[data-mhd-submit]',
    select: 'select',
    saved: '[data-region="mhd-prop-saved"]',
    detail: '[data-region="mhd-detail"]',
    drawer: '[data-region="mhd-detail-drawer"]',
    submits: 'input[type="submit"], button[type="submit"]',
};

const BOUND = 'mhdBound';
const BOUND_CONFIRM = 'mhdBoundConfirm';
const BOUND_SUBMIT = 'mhdBoundSubmit';
const SAVED_TIMEOUT = 2000;

/**
 * Reflect a saved value in the header, so the screen never contradicts itself.
 *
 * @param {Element} root Detail root the form belongs to.
 * @param {object}  data Decoded ajax.php response.
 */
const applyResponse = (root, data) => {
    const badge = (id) => root.querySelector('#' + id) || document.getElementById(id);

    switch (data.action) {
        case 'status': {
            const el = badge('mhd-status-badge');
            if (el) {
                el.className = el.className.replace(/mhd-pill--status-\d+/, 'mhd-pill--status-' + data.newstatus);
                el.textContent = data.statuslabel;
            }
            break;
        }
        case 'priority': {
            const el = badge('mhd-priority-badge');
            if (el) {
                el.className = el.className.replace(
                    /mhd-pill--priority-\d+/,
                    'mhd-pill--priority-' + data.newpriority
                );
                el.textContent = data.prioritylabel;
            }
            break;
        }
        default:
            break;
    }
};

/**
 * Rebuild the status select around the state the ticket is now in.
 *
 * The placeholder always shows where the ticket stands and the options are the
 * transitions legal from there. Without this the select would keep offering the
 * previous state's moves, and the next selection would be refused by the
 * service — correctly, but after the fact.
 *
 * @param {Element} form
 * @param {object}  data Decoded ajax.php response for action=status.
 */
const refreshStatusOptions = (form, data) => {
    const select = form.querySelector(SELECTORS.select);
    if (!select || !Array.isArray(data.transitions)) {
        return;
    }

    select.textContent = '';

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.disabled = true;
    placeholder.selected = true;
    placeholder.textContent = data.statuslabel;
    select.appendChild(placeholder);

    data.transitions.forEach((transition) => {
        const option = document.createElement('option');
        option.value = transition.value;
        option.textContent = transition.label;
        select.appendChild(option);
    });
};

/**
 * Flash the inline "Saved" marker next to a property.
 *
 * @param {Element} form
 */
const flashSaved = (form) => {
    const marker = form.querySelector(SELECTORS.saved);
    if (!marker) {
        return;
    }
    marker.hidden = false;
    setTimeout(() => {
        marker.hidden = true;
    }, SAVED_TIMEOUT);
};

/**
 * Send one property change.
 *
 * @param {Element} form The property form.
 * @return {Promise<void>}
 */
const save = async(form) => {
    const url = form.dataset.mhdAjax;
    const root = form.closest(SELECTORS.detail) || document;
    const select = form.querySelector(SELECTORS.select);

    // The status select opens on a disabled placeholder carrying the current
    // state; there is nothing to save until the user picks a real transition.
    if (select && select.value === '') {
        return;
    }

    form.classList.add('is-saving');

    // Held until the answer is applied, so anything waiting for the page to
    // settle (Behat's "page is ready", above all) waits for the save too.
    const pending = new Pending('local_moodesk/sidebar_actions:save');

    // Whether the server answered at all. A refusal is a decision and belongs
    // on screen; a transport failure is not, and falls back to the plain POST.
    let answered = false;

    try {
        const response = await fetch(url, {
            method: 'POST',
            body: new FormData(form),
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        });
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        const data = await response.json();
        answered = true;
        if (!data.success) {
            throw new Error(data.error || 'Request failed');
        }

        applyResponse(root, data);
        if (data.action === 'status') {
            refreshStatusOptions(form, data);
        }
        if (select) {
            select.dataset.mhdCommitted = select.value;
        }
        flashSaved(form);

        // One message for every property: the rail already says which row was
        // touched, and six near-identical strings would say nothing more.
        const holder = form.closest('[data-mhd-toast-saved]');
        if (holder) {
            Toast.show(holder.dataset.mhdToastSaved, 'success');
        }

        // Say that the ticket changed, not what changed about it. Anything
        // rendered from this ticket's stored state — the list behind this panel,
        // today — decides for itself what to do with that, and a property added
        // to this rail later needs no line here. Raised once, from the one place
        // all six properties already funnel through.
        const ticketid = form.querySelector('input[name="ticketid"]');
        notifyChanged(ticketid ? ticketid.value : null, 'sidebar_actions', data.action);
    } catch (err) {
        window.console.warn('local_moodesk sidebar_actions: property save failed.', err);

        if (!answered) {
            // Transport failure: hand the change to the plain POST, which runs
            // the same action through the same server-side checks.
            form.submit();
            return;
        }

        // The server refused. Put the control back where the ticket actually
        // is, and say so — resubmitting would only reach the same refusal, as
        // an error page, with the drawer gone.
        if (select && select.dataset.mhdCommitted !== undefined) {
            select.value = select.dataset.mhdCommitted;
        }
        const holder = form.closest('[data-mhd-toast-failed]');
        if (holder) {
            Toast.show(holder.dataset.mhdToastFailed, 'error');
        }
    } finally {
        pending.resolve();
    }

    form.classList.remove('is-saving');
};

/**
 * Submit one whole form and keep the panel where it is.
 *
 * The same shape as save() above, with one difference that matters: some of
 * these change what the ticket *is* — undoing a merge takes a whole conversation
 * back out of the thread — and no amount of patching the DOM can be trusted to
 * match what the server now holds. Those forms say so with `data-mhd-reload`,
 * and the drawer re-reads the ticket instead.
 *
 * @param {Element} form
 * @return {Promise<void>}
 */
const submitForm = async(form) => {
    const url = form.dataset.mhdAjax;
    const ticketinput = form.querySelector('input[name="ticketid"]');
    const reload = form.hasAttribute('data-mhd-reload');

    const buttons = form.querySelectorAll(SELECTORS.submits);
    buttons.forEach((button) => {
        button.disabled = true;
    });
    form.classList.add('is-saving');

    // The reply guard must not challenge a request that is already on its way.
    setSubmitting(true);

    // See save(): released once the answer is handled, whichever way it went.
    const pending = new Pending('local_moodesk/sidebar_actions:submit');

    let answered = false;

    try {
        const response = await fetch(url, {
            method: 'POST',
            body: new FormData(form),
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        });
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        const data = await response.json();
        answered = true;
        if (!data.success) {
            throw new Error(data.error || 'Request failed');
        }

        setSubmitting(false);

        if (data.message) {
            Toast.show(data.message, 'success');
        }

        notifyChanged(ticketinput ? ticketinput.value : null, 'sidebar_actions', data.action);

        if (reload) {
            // keepdraft: this was not the sending of a reply, so a message the
            // user had half-written is still theirs and survives the re-render.
            requestReload({keepdraft: true});
            return;
        }

        buttons.forEach((button) => {
            button.disabled = false;
        });
        form.classList.remove('is-saving');
    } catch (err) {
        window.console.warn('local_moodesk sidebar_actions: submit failed.', err);

        if (!answered) {
            // Transport failure: the plain POST runs the same action through the
            // same server-side checks. It leaves the drawer, which is the cost
            // of not silently dropping what the user asked for.
            form.submit();
            return;
        }

        setSubmitting(false);
        buttons.forEach((button) => {
            button.disabled = false;
        });
        form.classList.remove('is-saving');

        // The server refused, and its refusal names the rule that stopped it.
        const holder = form.closest('[data-mhd-toast-failed]');
        Toast.show(err.message || (holder ? holder.dataset.mhdToastFailed : ''), 'error');
    } finally {
        pending.resolve();
    }
};

/**
 * Wire auto-save, confirmations and whole-form submits. Safe to call repeatedly.
 */
export const init = () => {
    // Destructive actions ask before they run. The guard is advisory: without
    // JavaScript the POST still goes through, and the server-side capability
    // and TTL checks are what actually decide whether it lands.
    //
    // Bound first, so that a form which is both confirmed and sent over AJAX
    // gets its question asked before anything is sent — the submit handler
    // below stands down when this one has cancelled the event.
    document.querySelectorAll(SELECTORS.confirm).forEach((form) => {
        if (form.dataset[BOUND_CONFIRM]) {
            return;
        }
        form.dataset[BOUND_CONFIRM] = '1';
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.mhdConfirm)) {
                event.preventDefault();
            }
        });
    });

    // Whole-form submits, in the drawer only: on the canonical page these post
    // normally, land on the ticket and show their confirmation there, which is
    // what they have always done and what the no-JS path still does.
    document.querySelectorAll(SELECTORS.submit).forEach((form) => {
        if (form.dataset[BOUND_SUBMIT] || !form.closest(SELECTORS.drawer) || !form.dataset.mhdAjax) {
            return;
        }
        form.dataset[BOUND_SUBMIT] = '1';

        form.addEventListener('submit', (event) => {
            // A confirmation already said no, or the browser's own validation
            // did. Either way this send does not happen.
            if (event.defaultPrevented) {
                return;
            }
            event.preventDefault();
            submitForm(form);
        });
    });

    document.querySelectorAll(SELECTORS.autosave).forEach((form) => {
        if (form.dataset[BOUND]) {
            return;
        }
        form.dataset[BOUND] = '1';

        // Hiding the Save button is what makes auto-save honest: it is the one
        // signal that the change is committed on selection, and it only ever
        // disappears once this handler is actually attached.
        form.classList.add('mhd-prop--autosave');

        const select = form.querySelector(SELECTORS.select);
        if (select) {
            // Where the ticket actually is, to fall back to if a save is
            // refused. The status select starts on its disabled placeholder.
            select.dataset.mhdCommitted = select.value;
            select.addEventListener('change', () => save(form));
        }

        // The button survives for keyboard users who submit before the change
        // event lands, and for the no-JS fallback path.
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            save(form);
        });
    });
};
