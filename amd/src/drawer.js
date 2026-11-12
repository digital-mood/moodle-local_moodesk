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
 * Shared behaviour for mooDesk's overlay drawers.
 *
 * This is the panel mechanics only — open/close state, backdrop, scroll lock,
 * focus trap, Escape and focus return. What a drawer *contains* is none of its
 * business: the new-ticket drawer hosts a submission form and the ticket detail
 * drawer hosts the detail component, and neither one shares layout or styling
 * with the other. Extracting more than this would couple two screens that only
 * happen to slide in from the same edge.
 *
 * Two drawers can exist on the same screen (the ticket list has both), so the
 * keyboard is owned by whichever one is on top of the stack.
 *
 * @module     local_moodesk/drawer
 * @copyright  2026 Digital Mood LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {lock, unlock} from 'local_moodesk/scroll_lock';

const OPEN_CLASS = 'is-open';

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]),'
    + ' select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

// Moodle's own dialogues, which open above a drawer: the YUI panel behind the
// file picker and repository views, and the core modal.
const OVERLAY = '.moodle-dialogue-base, .yui3-panel, .modal.show, dialog[open]';

/** @type {Array} Currently open drawers, innermost last. */
const openStack = [];

/** @type {boolean} Whether the shared keyboard handler is attached. */
let keyboardBound = false;

/**
 * Visible, focusable controls inside the panel, in document order.
 *
 * @param {Element} panel
 * @return {Element[]}
 */
const focusableIn = (panel) =>
    Array.prototype.filter.call(
        panel.querySelectorAll(FOCUSABLE),
        (el) => el.offsetWidth > 0 || el.offsetHeight > 0 || el === document.activeElement
    );

/**
 * Whether one of Moodle's own dialogues is currently open above the drawer.
 *
 * The file picker is a YUI panel at z-index 1042, well above the drawer, and it
 * owns the keyboard while it is up: Escape has to close the picker and Tab has
 * to move inside it, not be captured by the drawer underneath.
 *
 * @return {boolean}
 */
const overlayIsOpen = () =>
    Array.prototype.some.call(
        document.querySelectorAll(OVERLAY),
        // Both dimensions, deliberately: Moodle leaves a .moodle-dialogue-base
        // wrapper in the page at full width and zero height whether or not a
        // dialogue is up, so testing either one alone matches all the time.
        (el) => el.offsetWidth > 0 && el.offsetHeight > 0
            && getComputedStyle(el).visibility !== 'hidden'
    );

/**
 * Attach the one keyboard handler shared by every drawer on the page.
 */
const bindKeyboard = () => {
    if (keyboardBound) {
        return;
    }
    keyboardBound = true;

    document.addEventListener('keydown', (e) => {
        const active = openStack[openStack.length - 1];

        // While one of Moodle's dialogues is up it owns the keyboard: Escape
        // belongs to it, and its own controls must stay reachable with Tab.
        if (!active || overlayIsOpen()) {
            return;
        }

        if (e.key === 'Escape') {
            active.close();
            return;
        }

        if (e.key !== 'Tab') {
            return;
        }

        // Focus trap. Tab presses raised inside the editor's iframe never reach
        // this handler, which is expected: focus is already inside the panel.
        const items = focusableIn(active.panel);
        if (!items.length) {
            return;
        }
        const first = items[0];
        const last = items[items.length - 1];
        const current = document.activeElement;

        // The panel itself carries tabindex="-1" so it can be focused when it
        // opens empty. It is a holding place, not a stop on the way round:
        // tabbing from it goes to the first control, never out of the panel.
        if (current === active.panel || !active.panel.contains(current)) {
            e.preventDefault();
            (e.shiftKey ? last : first).focus();
            return;
        }

        if (e.shiftKey && current === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && current === last) {
            e.preventDefault();
            first.focus();
        }
    });
};

/**
 * Wire a drawer.
 *
 * @param {object}   config
 * @param {Element}  config.mount        Outer element carrying the open state class.
 * @param {Element}  config.panel        The dialog panel itself.
 * @param {Element}  [config.backdrop]   Clicking it closes the drawer.
 * @param {Function} [config.initialFocus] Returns the element to focus on open.
 * @param {Function} [config.canClose]   Return false to veto a close request.
 * @param {Function} [config.onOpen]     Called after the panel is shown.
 * @param {Function} [config.onClose]    Called after the panel is hidden.
 * @return {object} Controller with open(), close() and isOpen().
 */
export const create = ({mount, panel, backdrop, initialFocus, canClose, onOpen, onClose}) => {
    // The element focus returns to when the drawer closes.
    let opener = null;

    const isOpen = () => mount.classList.contains(OPEN_CLASS);

    const drawer = {
        mount,
        panel,
        isOpen,

        open: (trigger) => {
            if (isOpen()) {
                return;
            }
            opener = trigger || document.activeElement;
            mount.classList.add(OPEN_CLASS);
            lock(drawer);
            openStack.push(drawer);

            const target = initialFocus ? initialFocus(panel) : null;
            if (target && typeof target.focus === 'function') {
                target.focus();
            }

            if (onOpen) {
                onOpen();
            }
        },

        close: () => {
            if (!isOpen()) {
                return;
            }
            if (canClose && !canClose()) {
                return;
            }

            mount.classList.remove(OPEN_CLASS);

            const index = openStack.indexOf(drawer);
            if (index !== -1) {
                openStack.splice(index, 1);
            }
            // Another drawer — or the shell's navigation — may still own the
            // viewport, so the module decides whether the page is released.
            unlock(drawer);

            if (opener && typeof opener.focus === 'function') {
                opener.focus();
            }
            opener = null;

            if (onClose) {
                onClose();
            }
        },
    };

    if (backdrop) {
        backdrop.addEventListener('click', () => drawer.close());
    }

    bindKeyboard();

    return drawer;
};
