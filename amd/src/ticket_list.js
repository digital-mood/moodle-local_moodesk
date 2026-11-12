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
 * Keeping the ticket list's results honest after something changes them.
 *
 * The list is server-rendered and that stays true here. This module holds no
 * ticket data: it re-runs the query on the server and swaps the region the
 * server sent back. Whether a ticket still belongs in the list — after its
 * status changed, after a filter, on which page — is a question only the
 * backend can answer, and it is never asked here.
 *
 * Two things wake it up, and they share one code path on purpose:
 *
 *  - **A mutation.** Anything that changes persisted ticket state raises
 *    `moodesk:ticket-changed` on the document. The event does not say which
 *    field moved and the listener does not look: its meaning is "the stored
 *    state changed, so anything derived from it may be stale". That is what
 *    makes this work for fields that do not exist yet.
 *  - **The back/forward cache.** Raising a ticket posts to submit.php and lands
 *    on the new ticket's page, so the way back to the list is the browser's Back
 *    button. Moodle serves these pages with `Cache-Control: private, max-age=0`
 *    — "allow caching on back", deliberately — so the browser restores the DOM
 *    it froze and the PHP never re-runs. `pageshow` with `persisted` is where
 *    that restore is caught.
 *
 * The list URL is read off the region, not off `window.location`. By refresh
 * time the address bar may well name an open ticket: the detail drawer pushes
 * the ticket's canonical URL when a row is opened, which is the behaviour that
 * makes Back and Forward work there.
 *
 * @module     local_moodesk/ticket_list
 * @copyright  2026 Digital Mood LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Fragment from 'core/fragment';
import Templates from 'core/templates';
import * as Toast from 'local_moodesk/toast';

/** @type {string} Raised on the document when persisted ticket state changes. */
export const CHANGED_EVENT = 'moodesk:ticket-changed';

/** @type {string} Raised on the document after the results region is replaced. */
export const REFRESHED_EVENT = 'moodesk:ticket-list-refreshed';

const SELECTORS = {
    region: '[data-region="mhd-ticket-list-results"]',
    row: (id) => `[data-action="mhd-open-detail"][data-ticketid="${id}"]`,
};

// Long enough to collapse a burst — status, then priority, then assignee, each
// saved on selection — into one query. Short enough that a single change still
// lands while the user is looking at it.
const COALESCE_MS = 200;

/**
 * Announce that persisted ticket state changed.
 *
 * Deliberately says almost nothing. `ticketid` is here so a listener can tell
 * *which* ticket to look for afterwards, not so it can work out what to patch;
 * `source` names the module that raised it, which is worth having in a console
 * when two things could have.
 *
 * @param {number} ticketid
 * @param {string} source Module or control that made the change.
 * @param {string} [action] The specific action, when the caller has one.
 */
export const notifyChanged = (ticketid, source, action) => {
    document.dispatchEvent(new CustomEvent(CHANGED_EVENT, {
        detail: {ticketId: Number(ticketid) || null, source, action: action || null},
    }));
};

/**
 * Wire the results region on screens that render one.
 *
 * @param {number} contextid    Context the fragment is requested against.
 * @param {string} failedmsg    Shown when a refresh could not complete.
 * @param {string} leftviewmsg  Shown when the changed ticket is no longer listed.
 */
export const init = (contextid, failedmsg, leftviewmsg) => {
    let region = document.querySelector(SELECTORS.region);
    if (!region) {
        return;
    }

    // Which refresh is the current one. A response that is not the newest is
    // dropped rather than applied: three saves in a row must not end with the
    // first answer overwriting the third. Same guard the detail drawer uses for
    // its own out-of-order fragment loads.
    let loadToken = 0;
    let timer = null;
    // Tickets named by events since the last refresh started. A change can be
    // waiting out the debounce when the next one arrives, and both deserve to
    // be reported on.
    let pending = new Set();

    /**
     * Whether a ticket currently has a row on screen.
     *
     * @param {number} ticketid
     * @return {boolean}
     */
    const isListed = (ticketid) => !!ticketid && !!region.querySelector(SELECTORS.row(ticketid));

    /**
     * Re-run the query and replace the region with what the server returns.
     *
     * @return {Promise<void>}
     */
    const refresh = () => {
        const token = ++loadToken;
        const query = region.dataset.listurl ? region.dataset.listurl.split('?')[1] || '' : '';
        const watched = Array.from(pending);
        pending = new Set();

        // Read before the swap so the two can be compared after it.
        const wasListed = watched.filter(isListed);

        // done/fail rather than then/catch: loadFragment hands back a jQuery
        // Deferred resolved with two values, and this is how the detail drawer
        // reads the same call.
        return Fragment.loadFragment('local_moodesk', 'ticket_list_results', contextid, {query})
            .done((html, js) => {
                if (token !== loadToken) {
                    return; // A newer refresh is already on its way.
                }

                // replaceNode, not replaceNodeContents: the fragment renders the
                // whole partial, region wrapper included, because that partial is
                // also what the page includes. Filling the node with it would
                // nest a second region inside the first on every refresh, and the
                // published data-listurl read back afterwards would be the stale
                // outer one.
                Templates.replaceNode(region, html, js);

                // The old element is gone with it, so the handle has to be taken
                // again before anything is read off the region.
                const replaced = document.querySelector(SELECTORS.region);
                if (!replaced) {
                    return; // Nothing to read or report against.
                }
                region = replaced;

                // The server decided which tickets still match. All this does is
                // notice that one the user just touched is no longer among them,
                // so the change does not appear to have done nothing.
                const gone = wasListed.filter((id) => !isListed(id));
                if (gone.length && leftviewmsg) {
                    Toast.show(leftviewmsg.replace('{$a}', gone.join(', ')), 'info');
                }

                document.dispatchEvent(new CustomEvent(REFRESHED_EVENT));
            })
            .fail(() => {
                if (token !== loadToken) {
                    return;
                }
                // The rows on screen are stale, not wrong, and they are better
                // than an empty list. The save that triggered this already
                // succeeded and was already confirmed — this failure is about
                // the view, and must not be reported as a lost change.
                if (failedmsg) {
                    Toast.show(failedmsg, 'error');
                }
            });
    };

    /**
     * Refresh once for a burst of changes.
     */
    const scheduleRefresh = () => {
        if (timer) {
            window.clearTimeout(timer);
        }
        timer = window.setTimeout(() => {
            timer = null;
            refresh();
        }, COALESCE_MS);
    };

    document.addEventListener(CHANGED_EVENT, (e) => {
        const ticketid = e.detail && e.detail.ticketId ? Number(e.detail.ticketId) : null;
        if (ticketid) {
            pending.add(ticketid);
        }
        scheduleRefresh();
    });

    // Restored from the back/forward cache: the rows are however old the frozen
    // page was. Nothing was mutated on *this* document, so there is no ticket to
    // watch — just bring the list up to date.
    window.addEventListener('pageshow', (e) => {
        if (e.persisted) {
            refresh();
        }
    });
};
