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
 * Conversation and Details as two tabs, on viewports too narrow for two columns.
 *
 * Above the breakpoint the detail is a conversation with a management rail
 * beside it. Below it there is no room for both, and the fallback until now was
 * to stack them — which buries the rail under however long the thread happens to
 * be, and buries the composer under the rail. Two tabs put each section one tap
 * away instead of one scroll away.
 *
 * Three things this module is careful about:
 *
 *  - **The ARIA only exists while the tabs do.** The tab roles are applied when
 *    the media query matches and given back when it stops matching, rather than
 *    being baked into the template. A `role="tabpanel"` with no visible tablist
 *    above it — which is what a wide viewport would have — describes a control
 *    that is not there. Given back, not removed: both panels are landmarks in
 *    the template, and a column that stops being one on the way out of tab mode
 *    is a regression nothing on screen would show.
 *  - **Switching hides, never re-renders.** The panels stay in the document and
 *    only their `hidden` changes, so the message box keeps what was typed, the
 *    attachments panel keeps its draft, and no picker instance is disturbed.
 *  - **Each panel keeps its own scroll.** Reading half a thread, checking the
 *    SLA and coming back should return to the same place, so the position is
 *    saved on the way out and restored on the way in. Saved off the scrollport,
 *    not off the panel: in tab mode the two panels share one — the columns have
 *    collapsed, which is the whole reason the tabs are here — and the panels
 *    themselves scroll nothing. Reading the position off them measured an
 *    element that is always at zero, so what came back was the top of the
 *    thread and the switch to Details looked like it had thrown the reader
 *    away. conversation_scroll finds the element that really scrolls, and is
 *    the only thing that writes to it.
 *
 *    Conversation with nothing saved yet is the first time it is shown, which
 *    is an opening ticket: it starts at the newest message rather than at the
 *    oldest, the same place every other route into the detail leaves it.
 *
 * The composer belongs to the conversation, and follows it: on Details there is
 * nothing on screen to reply to, and a reply box under the properties reads as
 * belonging to them.
 *
 * The width the tabs appear at comes from local_moodesk/breakpoints, which
 * reads it from the stylesheet, and it is **not the same width in both mounts**
 * — because the layout it replaces does not collapse at the same width either:
 *
 *   drawer — `tablet` (≤768px). The panel is 880px wide until the window is
 *            narrower than that, so the conversation keeps ~596px beside the
 *            284px rail all the way down to 769px, where the panel is
 *            full-bleed and the conversation is down to ~484px.
 *   page   — `below-desktop` (≤991px). Wider in pixels, and the same amount of
 *            room: the app shell's own 256px sidebar is still taking window
 *            here, so at 992px the content column has already fallen to about
 *            what the drawer has at 769px.
 *
 * Each query is the one modules/_ticket-detail.scss already uses to collapse
 * that mount's two columns. They have to agree — tabs over a still-two-column
 * layout would hide a section that is on screen anyway, and a collapsed layout
 * with no tabs is the stacked fallback these replace.
 *
 * @module     local_moodesk/detail_tabs
 * @copyright  2026 Digital Mood LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {upToTablet, belowDesktop} from 'local_moodesk/breakpoints';
import * as ConversationScroll from 'local_moodesk/conversation_scroll';

const SELECTORS = {
    detail: '[data-region="mhd-detail"]',
    tabs: '[data-region="mhd-detail-tabs"]',
    tab: '[data-action="mhd-detail-tab"]',
    panel: '[data-panel]',
    composer: '.mhd-detail__composer',
    // Which mount this detail is in. The two collapse at different widths, so
    // the module has to ask before it picks a query.
    drawer: '.mhd-detail-drawer',
};

/** @type {Map<Element, object>} Wired details, keyed by their root element. */
const sessions = new Map();

/**
 * Counter for the ids the tabs and panels point at each other with.
 *
 * Generated rather than written into the template because a screen can hold
 * more than one detail over its lifetime — the drawer replaces its content
 * ticket after ticket — and ids that repeat would have a tab controlling a
 * panel that is no longer there.
 *
 * @type {number}
 */
let seq = 0;

/**
 * Show one of the two sections.
 *
 * @param {object}  session
 * @param {string}  name     'conversation' or 'details'.
 * @param {boolean} [focus]  Whether to move focus to the newly selected tab.
 */
const select = (session, name, focus = false) => {
    // Where the outgoing section was, before the incoming one is measured
    // against the same port.
    if (session.panels.has(session.current)) {
        session.scroll.set(session.current, ConversationScroll.position(session.root));
    }

    const remembered = session.scroll.get(name);
    session.current = name;

    session.panels.forEach((panel, key) => {
        const active = key === name;
        panel.el.hidden = !active;
        panel.tab.setAttribute('aria-selected', active ? 'true' : 'false');
        // Roving tabindex: one stop for the whole strip, arrows move within it.
        panel.tab.tabIndex = active ? 0 : -1;

        if (active && focus) {
            panel.tab.focus();
        }
    });

    if (session.composer) {
        session.composer.hidden = name !== 'conversation';
    }

    // Last, and that order is the whole of it. Everything above changes how
    // tall the scrollport is — the panels by swapping which section fills it,
    // the composer by taking its own height out of the panel and giving it
    // back. A position written while the composer was still hidden is written
    // against a taller port and a smaller maximum, so the browser clamps it,
    // and revealing the composer a line later leaves the conversation a
    // composer's height short of where it was. The port has to be its final
    // size before anything is written to it.
    if (remembered === undefined && name === 'conversation') {
        ConversationScroll.toLatest(session.root);
    } else {
        ConversationScroll.restore(session.root, remembered || 0);
    }
};

/**
 * Turn the two sections into tabs.
 *
 * @param {object} session
 */
const enable = (session) => {
    if (session.on) {
        return;
    }
    session.on = true;

    session.tabs.hidden = false;
    session.tabs.setAttribute('role', 'tablist');

    session.panels.forEach((panel, key) => {
        panel.tab.setAttribute('role', 'tab');
        panel.tab.id = panel.tabid;
        panel.tab.setAttribute('aria-controls', panel.panelid);

        // What the panel was, before it becomes a tab panel — read first,
        // because the lines below overwrite both.
        //
        // Both panels are landmarks in the template: the conversation a
        // `region`, the rail a labelled `aside`. Neither is one while it is a
        // tab panel, and both have to be one again afterwards. `disable` used
        // to strip the attributes outright, which left the conversation with no
        // role at all the first time a window grew past the step — nothing on
        // screen changes when a landmark quietly disappears, which is why that
        // survived as long as it did.
        panel.role = panel.el.getAttribute('role');
        panel.label = panel.el.getAttribute('aria-label');

        panel.el.id = panel.panelid;
        panel.el.setAttribute('role', 'tabpanel');
        panel.el.setAttribute('aria-labelledby', panel.tabid);
        // The tab above names it now, and two names on one element is one too
        // many.
        panel.el.removeAttribute('aria-label');
        // The conversation already carries one for its own scrolling; the rail
        // needs one now that it can be the only thing on screen.
        panel.el.tabIndex = 0;
    });

    select(session, session.current);
};

/**
 * Give the two sections back to the layout.
 *
 * @param {object} session
 */
const disable = (session) => {
    if (!session.on) {
        return;
    }
    session.on = false;

    session.tabs.hidden = true;
    session.tabs.removeAttribute('role');

    session.panels.forEach((panel, key) => {
        panel.tab.removeAttribute('role');
        panel.tab.removeAttribute('aria-selected');
        panel.tab.removeAttribute('aria-controls');
        panel.tab.removeAttribute('tabindex');

        panel.el.hidden = false;
        panel.el.removeAttribute('aria-labelledby');

        // Put back what the template had, rather than removing what this
        // module added: the two are only the same thing when the template had
        // nothing, and for both of these panels it did.
        if (panel.role) {
            panel.el.setAttribute('role', panel.role);
        } else {
            panel.el.removeAttribute('role');
        }

        if (panel.label) {
            panel.el.setAttribute('aria-label', panel.label);
        }

        // Only the conversation carries one in the template; the rail was given
        // one to be reachable as a panel and has no use for it as a column.
        if (key === 'details') {
            panel.el.removeAttribute('tabindex');
        }
    });

    if (session.composer) {
        session.composer.hidden = false;
    }
};

/**
 * Move between tabs with the keyboard.
 *
 * Left/Right walk the strip and wrap; Home and End jump to its ends. This is
 * what the tab role promises, and the roving tabindex above is what makes the
 * strip a single stop for Tab itself.
 *
 * @param {object}        session
 * @param {KeyboardEvent} e
 */
const onKeydown = (session, e) => {
    const order = Array.from(session.panels.keys());
    const at = order.indexOf(session.current);
    let next = null;

    if (e.key === 'ArrowRight') {
        next = order[(at + 1) % order.length];
    } else if (e.key === 'ArrowLeft') {
        next = order[(at - 1 + order.length) % order.length];
    } else if (e.key === 'Home') {
        next = order[0];
    } else if (e.key === 'End') {
        next = order[order.length - 1];
    }

    if (next) {
        e.preventDefault();
        select(session, next, true);
    }
};

/**
 * Release the details inside a scope, or all of them when none is given.
 *
 * @param {Element} [scope]
 */
export const destroy = (scope) => {
    Array.from(sessions.values())
        .filter((session) => !scope || scope.contains(session.root))
        .forEach((session) => {
            session.mq.removeEventListener('change', session.onChange);
            session.tabs.removeEventListener('click', session.onClick);
            session.tabs.removeEventListener('keydown', session.onKey);
            disable(session);
            sessions.delete(session.root);
        });
};

/**
 * Wire one detail.
 *
 * @param {Element} root
 */
const wire = (root) => {
    if (sessions.has(root)) {
        return; // Already wired; the drawer re-runs init on every load.
    }

    const tabs = root.querySelector(SELECTORS.tabs);
    if (!tabs) {
        return; // No rail on this screen, so nothing to switch between.
    }

    const id = ++seq;
    const panels = new Map();

    Array.from(tabs.querySelectorAll(SELECTORS.tab)).forEach((tab) => {
        const name = tab.dataset.panel;
        const el = root.querySelector('[data-panel="' + name + '"]:not([data-action])');
        if (el) {
            panels.set(name, {
                tab,
                el,
                tabid: 'mhd-detail-tab-' + name + '-' + id,
                panelid: 'mhd-detail-panel-' + name + '-' + id,
            });
        }
    });

    if (panels.size < 2) {
        return; // One section is not a choice.
    }

    const session = {
        root,
        tabs,
        panels,
        composer: root.querySelector(SELECTORS.composer),
        scroll: new Map(),
        current: 'conversation',
        on: false,
        // The query that owns this mount's layout. See the note at the top of
        // the file: the drawer keeps two columns to 769px, the page does not.
        mq: window.matchMedia(root.closest(SELECTORS.drawer) ? upToTablet() : belowDesktop()),
    };

    session.onClick = (e) => {
        const tab = e.target.closest ? e.target.closest(SELECTORS.tab) : null;
        if (tab && session.on) {
            e.preventDefault();
            select(session, tab.dataset.panel);
        }
    };

    session.onKey = (e) => {
        if (session.on) {
            onKeydown(session, e);
        }
    };

    session.onChange = (e) => {
        if (e.matches) {
            enable(session);
        } else {
            disable(session);
        }
    };

    tabs.addEventListener('click', session.onClick);
    tabs.addEventListener('keydown', session.onKey);
    session.mq.addEventListener('change', session.onChange);

    sessions.set(root, session);

    if (session.mq.matches) {
        enable(session);
    }
};

/**
 * Forget the details that are no longer in the document.
 *
 * The drawer does not update the detail — it replaces it. Opening a second
 * ticket, sending a reply, undoing a merge and closing the panel all end with
 * `content.innerHTML = ''` and a freshly rendered fragment, so the root this
 * module wired is detached and a different element takes its place.
 *
 * Nothing on the detached tree can be reached or clicked, so no handler is
 * duplicated on anything the user can touch. What does accumulate is this
 * module's own bookkeeping: `sessions` holds the old root strongly, which holds
 * its listeners, which holds the MediaQueryList subscription — so every ticket
 * opened would leave behind one more resize listener reformatting a tree that
 * is not on screen. An agent works dozens of tickets without reloading the
 * list, and that is exactly the session this has to survive.
 *
 * Called from init(), which the fragment re-runs on every render, so the sweep
 * happens on precisely the events that create the garbage.
 */
const prune = () => {
    Array.from(sessions.keys())
        .filter((root) => !root.isConnected)
        .forEach((root) => {
            const session = sessions.get(root);
            session.mq.removeEventListener('change', session.onChange);
            session.tabs.removeEventListener('click', session.onClick);
            session.tabs.removeEventListener('keydown', session.onKey);
            sessions.delete(root);
        });
};

/**
 * Wire every ticket detail currently in the document.
 *
 * Idempotent twice over: details already wired are skipped by root identity,
 * and details that have gone are released first.
 */
export const init = () => {
    prune();
    Array.from(document.querySelectorAll(SELECTORS.detail)).forEach(wire);
};
