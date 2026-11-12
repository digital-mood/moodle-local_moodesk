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
 * Ticket detail drawer: opening a ticket from the list without leaving it.
 *
 * Progressive enhancement, strictly. Every row is a real link to
 * view.php?id=N — the canonical page, the deep link, and what a middle click,
 * a modifier click or a session without JavaScript gets. This module only
 * intercepts the plain left click and loads the same detail component into an
 * overlay through Moodle's fragment API, so everything inside the composer is
 * initialised by Moodle's own plumbing.
 *
 * Three things this module has to get right, because the panel outlives the
 * content inside it:
 *
 *  - **Teardown.** Discarding the markup is not enough: Moodle's file picker
 *    keeps a global instance map plus an `active_filepicker` pointer, and an
 *    instance left behind against a discarded composer can still receive the
 *    upload meant for the ticket that replaced it.
 *  - **Unsent content.** The composer is a plain textarea, so the reply being
 *    written is in the field itself and the guard reads it directly. Draft
 *    files count as unsent content too.
 *  - **History.** Opening pushes the ticket's canonical URL; Back and Forward
 *    are then the only source of truth for what is on screen, so the two can
 *    never disagree.
 *
 * @module     local_moodesk/ticket_detail_drawer
 * @copyright  2026 Digital Mood LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as Drawer from 'local_moodesk/drawer';
import * as ComposerAttachments from 'local_moodesk/composer_attachments';
import * as ConversationScroll from 'local_moodesk/conversation_scroll';
import Fragment from 'core/fragment';
import Templates from 'core/templates';

const SELECTORS = {
    mount: '[data-region="mhd-detail-drawer"]',
    panel: '[data-region="mhd-drawer-panel"]',
    backdrop: '[data-region="mhd-drawer-backdrop"]',
    content: '[data-region="mhd-detail-content"]',
    loading: '[data-region="mhd-detail-loading"]',
    error: '[data-region="mhd-detail-error"]',
    errorlink: '[data-region="mhd-detail-error-link"]',
    trigger: '[data-action="mhd-open-detail"]',
    newticket: '[data-action="mhd-open-new-ticket"]',
    close: '[data-action="mhd-drawer-close"]',
    // The composer's writing surface. One selector, because the composer is a
    // plain textarea rather than whichever editor the site has configured.
    input: '[data-region="mhd-composer-input"]',
    composerform: 'form',
};

// The subject heading inside the fragment; it names the dialog once loaded.
const TITLE_ID = 'mhd-detail-title';

/** @type {string} Asks the drawer to re-render the ticket it is showing. */
export const RELOAD_EVENT = 'moodesk:ticket-detail-reload';

/** @type {string} Tells the drawer whether a send is in flight. */
export const SUBMIT_STATE_EVENT = 'moodesk:ticket-detail-submitting';

/**
 * Ask the drawer to show the current ticket again, freshly rendered.
 *
 * Raised on the document rather than called directly, because the modules that
 * need it — the composer, the rail — are mounted inside the fragment and have
 * no handle on the panel that hosts them. On the canonical page nothing is
 * listening, which is correct: there is no drawer there to refresh.
 *
 * @param {object}  [options]
 * @param {boolean} [options.keepdraft] Carry the unsent message across the
 *        re-render. What the user typed survives an action that was not the
 *        sending of it — undoing a merge, say.
 */
export const requestReload = (options) => {
    document.dispatchEvent(new CustomEvent(RELOAD_EVENT, {
        detail: {keepdraft: !!(options && options.keepdraft)},
    }));
};

/**
 * Say whether something is being sent right now.
 *
 * The unsaved-work guard has to know: a reply on its way to the server is not
 * a reply about to be lost, and asking "discard it?" while it sends would be
 * asking about work that is already safe.
 *
 * @param {boolean} value
 */
export const setSubmitting = (value) => {
    document.dispatchEvent(new CustomEvent(SUBMIT_STATE_EVENT, {
        detail: {submitting: !!value},
    }));
};

/**
 * Whether this click should open the drawer, or be left to the browser.
 *
 * Modifier and middle clicks mean "open this link somewhere else", and the row
 * is a real link precisely so that keeps working.
 *
 * @param {MouseEvent} e
 * @return {boolean}
 */
const isPlainClick = (e) =>
    e.button === 0 && !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey;

/**
 * Content the user put into the composer and has not sent.
 *
 * The textarea is the whole answer now. It used to take four branches, because
 * an editor keeps what is being written in its own instance and only writes
 * back to the textarea when the form is saved, so the textarea would report an
 * empty composer for a reply that was fully written. A plain textarea holds the
 * reply itself, and `.value` is the reply.
 *
 * A file uploaded into the draft area counts on its own. Losing it costs the
 * user the upload again and, unlike the message, it leaves no trace in the
 * composer to warn them — an empty message with three files attached is unsent
 * work just the same.
 *
 * @param {Element} panel
 * @return {boolean}
 */
const hasUnsentContent = (panel) => {
    // Scoped to this panel. The ticket list also hosts the New ticket drawer,
    // which runs a composer of its own — a file waiting in *that* draft is not
    // this reply's unsent work, and would otherwise make every close of this
    // panel ask a question about somebody else's form.
    if (ComposerAttachments.hasDraftFiles(panel)) {
        return true;
    }

    const input = panel.querySelector(SELECTORS.input);
    return !!input && input.value.trim() !== '';
};

/**
 * Release what the panel's content leaves behind.
 *
 * Called before the markup is replaced or discarded, never after: the file
 * picker's registry is global and outlives the markup, so an instance left
 * against a discarded composer would still answer to its client id — and,
 * worse, still be the target an upload callback resolves through. That is the
 * failure this drawer has to prevent: opening ticket B and having its upload
 * land in ticket A's draft.
 *
 * Scoped to this panel, and that scope is the point: the New ticket drawer
 * shares this screen and runs a composer of its own, so an unscoped release
 * would tear down a form the user may be in the middle of filling in.
 *
 * The message box needs nothing released — autogrow listens once at the document
 * and sizes whatever is written into, so markup that goes away simply stops
 * being written into.
 *
 * @param {Element} panel The drawer panel whose content is about to be replaced.
 */
const teardown = (panel) => {
    ComposerAttachments.destroy(panel);
};

/**
 * Wire the detail drawer on screens that mount it.
 */
export const init = () => {
    const mount = document.querySelector(SELECTORS.mount);
    if (!mount) {
        return;
    }

    const panel = mount.querySelector(SELECTORS.panel);
    const content = mount.querySelector(SELECTORS.content);
    if (!panel || !content) {
        return;
    }

    const loading = mount.querySelector(SELECTORS.loading);
    const error = mount.querySelector(SELECTORS.error);
    const errorlink = mount.querySelector(SELECTORS.errorlink);
    const contextid = parseInt(mount.dataset.contextid, 10);
    const confirmMessage = mount.dataset.unsavedConfirm;
    const fallbackLabel = panel.getAttribute('aria-label');

    // Which ticket is on screen, whether this module owns the current history
    // entry, and whether a close has already been agreed to.
    let currentTicket = null;
    let currentUrl = null;
    let ownsEntry = false;
    let bypassGuard = false;
    let submitting = false;
    let loadToken = 0;

    const setState = (state) => {
        if (loading) {
            loading.hidden = state !== 'loading';
        }
        if (error) {
            error.hidden = state !== 'error';
        }
        content.hidden = state !== 'ready';
    };

    /**
     * Ask before anything destroys a reply the user has not sent.
     *
     * @return {boolean} Whether the caller may proceed.
     */
    const confirmDiscard = () => {
        if (submitting || !confirmMessage || !hasUnsentContent(panel)) {
            return true;
        }
        return window.confirm(confirmMessage);
    };

    const drawer = Drawer.create({
        mount,
        panel,
        backdrop: mount.querySelector(SELECTORS.backdrop),
        initialFocus: (p) => p.querySelector(SELECTORS.close) || p,
        canClose: () => bypassGuard || confirmDiscard(),
        onClose: () => {
            teardown(panel);
            content.innerHTML = '';
            setState('loading');
            currentTicket = null;

            // The subject went with it; fall back to the generic name.
            if (fallbackLabel) {
                panel.setAttribute('aria-label', fallbackLabel);
                panel.removeAttribute('aria-labelledby');
            }

            // Closing from inside the panel steps back through history rather
            // than rewriting it, so Forward still returns to the ticket. When
            // the close *came* from a history move, ownsEntry is already false
            // and the browser has moved on its own.
            if (ownsEntry) {
                ownsEntry = false;
                window.history.back();
            }
        },
    });

    // Close without asking again — the caller has already agreed.
    const closeConfirmed = () => {
        bypassGuard = true;
        drawer.close();
        bypassGuard = false;
    };

    /**
     * Put one ticket on screen, opening the panel if it is not already up.
     *
     * Also how the panel refreshes itself: a re-render is the same load, of the
     * same ticket, and the difference is only where the user should be left
     * afterwards. Opening a ticket puts focus on the close button, which is
     * where a screen reader should start; coming back from a sent reply puts it
     * in the composer, at the bottom of a conversation that just grew, because
     * that is where the agent already was.
     *
     * Either way the conversation ends up at its newest message. A ticket is
     * opened to read what has just happened on it, and that is the last thing
     * in the thread — on the first open, on the next ticket, on the one after a
     * reply and on every re-render in between. Where that scroll actually
     * happens is conversation_scroll's business: the panel is one scrollport in
     * two columns and a different one in tabs.
     *
     * @param {number}  ticketid
     * @param {Element} [trigger] Row that opened it; focus returns there.
     * @param {object}  [options]
     * @param {boolean} [options.reload] This is a re-render of what is on screen.
     * @param {string}  [options.draft]  Unsent message to write back afterwards.
     */
    const showTicket = (ticketid, trigger, options) => {
        const opts = options || {};
        if (drawer.isOpen()) {
            // Reusing the open panel: the outgoing ticket's picker instance has
            // to go before the markup it resolves through does.
            teardown(panel);
            content.innerHTML = '';
        } else {
            drawer.open(trigger);
        }

        currentTicket = ticketid;
        submitting = false;

        const token = ++loadToken;
        setState('loading');

        Fragment.loadFragment('local_moodesk', 'ticket_detail', contextid, {ticketid})
            .done((html, js) => {
                if (token !== loadToken || !drawer.isOpen()) {
                    return; // Superseded, or the user closed the panel meanwhile.
                }
                Templates.replaceNodeContents(content, html, js);
                setState('ready');

                // A message the user had written and had not sent. The freshly
                // rendered composer is empty, so nothing is being overwritten.
                if (opts.draft) {
                    const input = panel.querySelector(SELECTORS.input);
                    if (input && input.value.trim() === '') {
                        input.value = opts.draft;
                    }
                }

                // Now that the ticket is in the panel, the dialog is named by
                // its subject rather than by the generic fallback.
                if (panel.querySelector('#' + TITLE_ID)) {
                    panel.setAttribute('aria-labelledby', TITLE_ID);
                    panel.removeAttribute('aria-label');
                }

                if (opts.reload) {
                    const input = panel.querySelector(SELECTORS.input);
                    if (input) {
                        input.focus();
                    }
                } else {
                    const close = panel.querySelector(SELECTORS.close);
                    if (close) {
                        close.focus();
                    }
                }

                // After the focus, not before: focusing a control scrolls its
                // ancestors to reveal it, so a pin taken first would be undone
                // by the very next line.
                ConversationScroll.toLatest(panel);
            })
            .fail(() => {
                if (token !== loadToken) {
                    return;
                }
                // Recoverable: the panel offers the full page for this ticket
                // and a way out, rather than a dead end.
                if (errorlink && currentUrl) {
                    errorlink.setAttribute('href', currentUrl);
                }
                setState('error');
                const close = panel.querySelector(SELECTORS.close);
                if (close) {
                    close.focus();
                }
            });
    };

    /**
     * Open a ticket from a row, recording it in the browser's history.
     *
     * @param {number}  ticketid
     * @param {string}  url
     * @param {Element} trigger
     */
    const openFromRow = (ticketid, url, trigger) => {
        if (!confirmDiscard()) {
            return;
        }

        currentUrl = url;
        if (window.history && window.history.pushState) {
            const state = window.history.state;
            // Never stack the same ticket twice.
            if (!state || state.mhdDetail !== ticketid) {
                window.history.pushState({mhdDetail: ticketid}, '', url);
            }
            ownsEntry = true;
        }

        showTicket(ticketid, trigger);
    };

    // Rows are re-rendered on every filter change, so bind through the document.
    document.addEventListener('click', (e) => {
        const trigger = e.target.closest ? e.target.closest(SELECTORS.trigger) : null;
        if (!trigger || !isPlainClick(e)) {
            return;
        }

        const ticketid = parseInt(trigger.dataset.ticketid, 10);
        if (!ticketid) {
            return; // Nothing to load — let the link do its job.
        }

        e.preventDefault();
        openFromRow(ticketid, trigger.getAttribute('href'), trigger);
    });

    // Close button and any other close affordance inside the injected content.
    mount.addEventListener('click', (e) => {
        if (e.target.closest && e.target.closest(SELECTORS.close)) {
            e.preventDefault();
            drawer.close();
        }
    });

    // The new-ticket drawer opens over this one and takes the focus into its
    // own composer; ask before the reply in progress is left behind. Capture
    // phase, because that drawer's own handler is bound to the document too.
    document.addEventListener('click', (e) => {
        const trigger = e.target.closest ? e.target.closest(SELECTORS.newticket) : null;
        if (!trigger || !drawer.isOpen()) {
            return;
        }
        if (!confirmDiscard()) {
            e.preventDefault();
            e.stopPropagation();
            return;
        }
        closeConfirmed();
    }, true);

    // Sending is not discarding, and must not be challenged on the way out.
    // The composer sends over AJAX and stays put, so it says for itself when
    // the send is over (see SUBMIT_STATE_EVENT below); this still covers the
    // fallback, where a form really does navigate.
    panel.addEventListener('submit', (e) => {
        if (e.target.matches(SELECTORS.composerform)) {
            submitting = true;
        }
    }, true);

    // What is on screen changed under the panel — a reply was sent, a merge was
    // undone — and the ticket has to be re-read rather than patched. The same
    // fragment load an open does, so there is one way a ticket reaches this
    // panel and no second rendering path to keep in step.
    document.addEventListener(RELOAD_EVENT, (e) => {
        if (!drawer.isOpen() || !currentTicket) {
            return;
        }

        let draft = '';
        if (e.detail && e.detail.keepdraft) {
            const input = panel.querySelector(SELECTORS.input);
            draft = input ? input.value : '';
        }

        showTicket(currentTicket, null, {reload: true, draft});
    });

    // Whether a send is in flight. Raised false again when one ends without
    // navigating, which is every AJAX send: leaving the flag set would mean the
    // next close never asked about a reply the user had gone back to writing.
    document.addEventListener(SUBMIT_STATE_EVENT, (e) => {
        submitting = !!(e.detail && e.detail.submitting);
    });

    // Back and Forward are the source of truth for what is on screen.
    window.addEventListener('popstate', (e) => {
        const wanted = e.state && e.state.mhdDetail ? parseInt(e.state.mhdDetail, 10) : null;

        if (wanted) {
            if (currentTicket === wanted && drawer.isOpen()) {
                ownsEntry = true;
                currentUrl = window.location.href;
                return; // Already showing it.
            }
            if (drawer.isOpen() && !confirmDiscard()) {
                // The reply survives, so the address bar goes back to naming
                // the ticket that is actually on screen.
                if (currentUrl && window.history.pushState) {
                    window.history.pushState({mhdDetail: currentTicket}, '', currentUrl);
                }
                return;
            }
            ownsEntry = true;
            currentUrl = window.location.href;
            showTicket(wanted, null);
            return;
        }

        // An entry with no ticket means the list: the panel closes.
        if (!drawer.isOpen()) {
            ownsEntry = false;
            return;
        }
        if (!confirmDiscard()) {
            // The panel stays open, so the address bar goes back to matching it.
            if (currentUrl && window.history.pushState) {
                window.history.pushState({mhdDetail: currentTicket}, '', currentUrl);
            }
            return;
        }
        ownsEntry = false;
        closeConfirmed();
    });

    // Anything that leaves the page entirely — the full-page link, a bookmark,
    // a reload — gets the browser's own confirmation.
    window.addEventListener('beforeunload', (e) => {
        if (submitting || !drawer.isOpen() || !hasUnsentContent(panel)) {
            return undefined;
        }
        e.preventDefault();
        e.returnValue = '';
        return '';
    });
};
