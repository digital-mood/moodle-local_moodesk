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
 * Application-shell behaviour. Vanilla DOM, no framework or dependency.
 *
 *   - Above the tablet step: the toggle collapses/expands the sidebar and
 *     persists the choice as a per-user preference.
 *   - Tablet and below: the toggle opens the off-canvas navigation drawer; a
 *     click on the drawer's own close control, on the backdrop, or Escape
 *     closes it, and while it is open the document behind it is held still
 *     through local_moodesk/scroll_lock. The toggle closes it too, but only
 *     from the keyboard — the open drawer covers it, so no pointer reaches it,
 *     which is what the drawer's close control is there for. While it is
 *     closed the sidebar is `inert`, so the panel parked off-screen is not a
 *     dozen links the Tab key can still walk into.
 *   - The user menu (native <details>) is enhanced with outside-click / Escape
 *     close and a synced aria-expanded.
 *
 * The width those two behaviours swap at comes from local_moodesk/breakpoints,
 * which reads it from the stylesheet — layout/_app-sidebar.scss opens the
 * drawer in the same query.
 *
 * @module     local_moodesk/app_shell
 * @copyright  2026 Digital Mood LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Pending from 'core/pending';
import {setUserPreference} from 'core_user/repository';
import {upToTabletQuery} from 'local_moodesk/breakpoints';
import {lock, unlock} from 'local_moodesk/scroll_lock';

const SELECTORS = {
    root: '[data-region="moodesk-app"]',
    toggle: '[data-region="moodesk-nav-toggle"]',
    backdrop: '[data-region="moodesk-drawer-backdrop"]',
    drawerclose: '[data-region="moodesk-drawer-close"]',
    usermenu: '[data-region="moodesk-usermenu"]',
};

const COLLAPSED_CLASS = 'moodesk-app--collapsed';
const DRAWER_OPEN_CLASS = 'moodesk-app--drawer-open';

// The shell's token in local_moodesk/scroll_lock. There is one navigation
// drawer per page, so one constant is identity enough.
const LOCK_OWNER = 'moodesk-app-drawer';

/**
 * Whether the viewport is inside the drawer's step — tablet and below.
 *
 * @return {boolean}
 */
const isDrawerWidth = () => {
    const mq = upToTabletQuery();

    return mq !== null && mq.matches;
};

/**
 * Persist the collapsed state as a user preference (best-effort).
 *
 * Goes through core_user/repository, the API core itself uses since 4.3: it is
 * present on every supported release from 4.5 on. M.util.set_user_preference()
 * was removed in Moodle 5.2, so it cannot be used here.
 * Failure is swallowed: the toggle still works, only persistence is lost.
 * The pending marker lets Behat wait for the save before reloading the page.
 *
 * @param {string} prefName  Declared preference name.
 * @param {boolean} collapsed Current collapsed state.
 */
const persist = (prefName, collapsed) => {
    const pending = new Pending('local_moodesk/app_shell:persist');
    let saving;
    try {
        saving = setUserPreference(prefName, collapsed ? '1' : '0');
    } catch (e) {
        saving = Promise.reject(e);
    }
    saving
        .catch(() => {
            // Best-effort only.
        })
        .then(() => pending.resolve());
};

/**
 * Take the sidebar out of the page, or put it back.
 *
 * A closed drawer is parked off-screen with `transform: translateX(-100%)`, and
 * a transform moves where a thing is painted and nothing else: every link in it
 * stays in the tab order, stays hittable, and stays in the accessibility tree.
 * Tab from the topbar and the focus ring walks off the side of the screen.
 *
 * `inert` on the <aside> is the whole fix. One attribute takes the entire
 * subtree out of sequential focus navigation, out of pointer events and out of
 * the accessibility tree — the brand, the close control, every navigation and
 * footer link, and anything added to the sidebar later without this file
 * hearing about it. The alternative, walking the subtree stamping tabindex="-1"
 * on whatever matches a list of selectors, has to remember and restore what it
 * found, silently misses any control the list does not name, and tells a screen
 * reader nothing.
 *
 * Two conditions, not one. `inert` follows the drawer's *state* and the
 * *breakpoint* together: above the tablet step the sidebar is a rail, on screen
 * and reachable, and a collapsed rail is still every one of those links with
 * their labels hidden. Keying off the open class alone would make the desktop
 * sidebar unusable.
 *
 * @param {Element} root
 * @param {Element} toggle
 * @param {Element|null} sidebar
 */
const syncSidebarReachability = (root, toggle, sidebar) => {
    if (!sidebar) {
        return;
    }

    const offCanvas = isDrawerWidth() && !root.classList.contains(DRAWER_OPEN_CLASS);

    // Order matters, in this direction only: the focus has to leave before the
    // subtree goes inert. A browser takes the focus out of content it has just
    // been told to ignore, and it puts it on <body> — which loses a keyboard
    // user's place in the page entirely. The toggle is where the drawer was
    // opened from and where Escape already returns them, so it is where they go
    // back to whichever way the drawer was closed. Doing it here rather than in
    // each caller is what makes it true of the backdrop too, which closes the
    // drawer without moving the focus itself.
    if (offCanvas && sidebar.contains(document.activeElement)) {
        toggle.focus();
    }

    sidebar.toggleAttribute('inert', offCanvas);
};

/**
 * Open or close the off-canvas drawer.
 *
 * Every path that changes the drawer's state goes through here — the toggle,
 * the backdrop, Escape, the drawer's own close control, and leaving the
 * drawer's breakpoint — so the class on the root, the toggle's aria-expanded,
 * the page's scroll lock and the sidebar's reachability cannot drift apart.
 * Nothing else in this module touches the four.
 *
 * Closing releases the lock unconditionally: local_moodesk/scroll_lock takes a
 * release from an owner that never locked, and only lets the page go when no
 * one else is holding it, so a drawer opening above the shell is safe.
 *
 * @param {Element} root
 * @param {Element} toggle
 * @param {Element|null} sidebar
 * @param {boolean} open
 */
const setDrawerOpen = (root, toggle, sidebar, open) => {
    root.classList.toggle(DRAWER_OPEN_CLASS, open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');

    if (open) {
        lock(LOCK_OWNER);
    } else {
        unlock(LOCK_OWNER);
    }

    // After the class, never before it: the reachability is read back off the
    // DOM rather than predicted from the argument.
    syncSidebarReachability(root, toggle, sidebar);
};

/**
 * Close the native <details> user menu, if open.
 *
 * @param {Element|null} menu
 */
const closeUserMenu = (menu) => {
    if (menu && menu.open) {
        menu.open = false;
    }
};

/**
 * Wire the shell: navigation toggle, off-canvas drawer and user menu.
 *
 * @param {string} prefName Name of the user preference to persist the state in.
 */
export const init = (prefName) => {
    const root = document.querySelector(SELECTORS.root);
    const toggle = document.querySelector(SELECTORS.toggle);
    if (!root || !toggle) {
        return;
    }

    const backdrop = root.querySelector(SELECTORS.backdrop);
    const drawerclose = root.querySelector(SELECTORS.drawerclose);
    const usermenu = root.querySelector(SELECTORS.usermenu);
    const summary = usermenu ? usermenu.querySelector('summary') : null;

    // The toggle and the sidebar live in sibling partials, so the template
    // cannot carry aria-controls without pointing at an element that is absent
    // whenever the topbar is rendered on its own. Wire it up here instead,
    // where both are known to be in the document.
    // It also names the element whose reachability the drawer state governs, so
    // this is where the sidebar is picked up: no second selector, and no hook
    // in the markup that the toggle was not already publishing.
    const controls = toggle.getAttribute('data-controls');
    const sidebar = controls ? document.getElementById(controls) : null;
    if (sidebar) {
        toggle.setAttribute('aria-controls', controls);
    }

    const syncToggleAria = () => {
        const expanded = isDrawerWidth()
            ? root.classList.contains(DRAWER_OPEN_CLASS)
            : !root.classList.contains(COLLAPSED_CLASS);
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    };
    syncToggleAria();
    // A page served at phone width starts with the drawer closed, so it starts
    // inert. Applied here and never in the template: without this module the
    // shell has no drawer to speak of, and a sidebar left inert by markup alone
    // would be a navigation nobody could reach.
    syncSidebarReachability(root, toggle, sidebar);

    toggle.addEventListener('click', () => {
        if (isDrawerWidth()) {
            setDrawerOpen(root, toggle, sidebar, !root.classList.contains(DRAWER_OPEN_CLASS));
            return;
        }
        const collapsed = root.classList.toggle(COLLAPSED_CLASS);
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        persist(prefName, collapsed);
    });

    if (backdrop) {
        backdrop.addEventListener('click', () => setDrawerOpen(root, toggle, sidebar, false));
    }

    // The close control is inside the drawer and goes with it, so focus has to
    // be handed back explicitly or it falls to <body> when the button stops
    // being displayed. The toggle is where it came from, and where Escape
    // returns it too.
    if (drawerclose) {
        drawerclose.addEventListener('click', () => {
            setDrawerOpen(root, toggle, sidebar, false);
            toggle.focus();
        });
    }

    if (usermenu && summary) {
        usermenu.addEventListener('toggle', () => {
            summary.setAttribute('aria-expanded', usermenu.open ? 'true' : 'false');
        });
    }

    // Outside click closes the user menu.
    document.addEventListener('click', (e) => {
        if (usermenu && usermenu.open && !usermenu.contains(e.target)) {
            closeUserMenu(usermenu);
        }
    });

    // Escape closes the user menu and the drawer; focus returns to the toggle.
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') {
            return;
        }
        closeUserMenu(usermenu);
        if (root.classList.contains(DRAWER_OPEN_CLASS)) {
            setDrawerOpen(root, toggle, sidebar, false);
            toggle.focus();
        }
    });

    // Keep state/ARIA correct when crossing the drawer's breakpoint.
    const mq = upToTabletQuery();
    if (mq !== null) {
        const onChange = () => {
            // Above the step the sidebar is a rail again: the drawer state and
            // the scroll lock it was holding both have to go, or the page stays
            // frozen with nothing on screen to explain why. syncToggleAria then
            // restores the meaning aria-expanded has up here (expanded rail).
            if (!isDrawerWidth()) {
                setDrawerOpen(root, toggle, sidebar, false);
            }
            syncToggleAria();
            // Unconditionally, because the crossing that setDrawerOpen does not
            // cover is the one into the drawer's step: the drawer is already
            // closed, so nothing about its state changes, and the sidebar still
            // has to stop being reachable. Going the other way it is redundant
            // and harmless — the call above has already cleared the attribute,
            // which is the point. A rail left inert by a stale drawer state is
            // a navigation nobody can use.
            syncSidebarReachability(root, toggle, sidebar);
        };
        if (typeof mq.addEventListener === 'function') {
            mq.addEventListener('change', onChange);
        } else if (typeof mq.addListener === 'function') {
            mq.addListener(onChange);
        }
    }
};
