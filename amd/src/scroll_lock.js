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
 * The one owner of the page's scroll lock.
 *
 * Two unrelated things cover the viewport in mooDesk: the overlay drawers built
 * on local_moodesk/drawer (new ticket, ticket detail) and the app shell's own
 * off-canvas navigation, which is not a drawer in that sense — different
 * markup, different open class, no focus trap, and it only exists below the
 * tablet step. They share one thing: while either is up, the document behind it
 * must not scroll.
 *
 * That is a single class on <body>, and a class is a boolean — the last one to
 * let go wins. Left to each caller, closing the navigation would unlock the
 * page underneath a detail drawer that is still open. So nobody touches the
 * class directly: callers hold a token here, and the class comes off when the
 * last token is released.
 *
 * The lock itself is one `overflow: hidden` rule in scss/components/
 * _drawer-base.scss. It keeps the scroll position — the document is not taken
 * out of flow — which is why there is no position:fixed dance here.
 *
 * @module     local_moodesk/scroll_lock
 * @copyright  2026 Digital Mood LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const BODY_LOCK_CLASS = 'mhd-drawer-locked';

/** @type {Set} Whoever currently needs the page held still. */
const holders = new Set();

/**
 * Hold the page still on behalf of one owner.
 *
 * Idempotent: an owner that locks twice still releases in one call.
 *
 * @param {*} owner Any stable token identifying the caller.
 */
export const lock = (owner) => {
    holders.add(owner);
    document.body.classList.add(BODY_LOCK_CLASS);
};

/**
 * Release one owner's hold, and unlock the page if it was the last.
 *
 * Safe to call for an owner that never locked, which is what lets a caller
 * release unconditionally on every close path instead of first working out
 * whether it was open.
 *
 * @param {*} owner The token passed to lock().
 */
export const unlock = (owner) => {
    holders.delete(owner);
    if (!holders.size) {
        document.body.classList.remove(BODY_LOCK_CLASS);
    }
};
