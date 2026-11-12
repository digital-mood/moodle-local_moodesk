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
 * Where the conversation scrolls, and how it is put at its latest message.
 *
 * A ticket is opened to read what has just happened on it. The thread runs
 * oldest first, so the newest message is at the bottom, and a detail that
 * opened at the top showed the agent the oldest thing on the ticket — the one
 * message they were least likely to want.
 *
 * This module exists because "the conversation's scrollport" is not one
 * element. It moves with the layout:
 *
 *   drawer, two columns — `.mhd-detail__main` has `overflow-y: auto` and is its
 *                         own scrollport, beside the rail's.
 *   drawer, tabs        — at and below the tablet step the columns collapse:
 *                         `.mhd-detail__body` becomes the single scrollport and
 *                         the conversation inside it stops scrolling at all.
 *   canonical page      — nothing inside the detail scrolls; the window does.
 *
 * So the port is found rather than named: walk up from the conversation to the
 * detail root and take the first element the stylesheet made scrollable. That
 * keeps the breakpoints in one place — the stylesheet — instead of restating
 * them here, where they could drift.
 *
 * **One writer.** Every write to that element's `scrollTop` goes through this
 * module, and each call claims the port: a pin scheduled by `toLatest` is
 * abandoned the moment anything else claims it. Two callers with different
 * intentions — the drawer pinning a freshly loaded ticket, the tabs restoring
 * where the user was — cannot end up fighting a frame apart.
 *
 * **Timing.** The fragment's markup is in the document before its height is
 * final, and a pin against a half-measured thread lands short of the end. The
 * position is set at once and taken again on the next two animation frames,
 * by which point layout has settled.
 *
 * @module     local_moodesk/conversation_scroll
 * @copyright  2026 Digital Mood LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    detail: '[data-region="mhd-detail"]',
    conversation: '.mhd-detail__main',
};

/**
 * The current claim on each scrollport, so a stale pin can tell it is stale.
 *
 * @type {WeakMap<Element, number>}
 */
const claims = new WeakMap();

/**
 * Whether the stylesheet made this element a scrollport.
 *
 * Asked of the computed style rather than of `scrollHeight > clientHeight`,
 * because a short thread does not overflow and would otherwise report that
 * nothing here scrolls — true today, false as soon as one more reply lands.
 *
 * @param {Element} el
 * @return {boolean}
 */
const scrolls = (el) => {
    const overflow = window.getComputedStyle(el).overflowY;
    return overflow === 'auto' || overflow === 'scroll';
};

/**
 * The element that scrolls the conversation, if one does.
 *
 * @param {Element} root A ticket detail, or anything containing one.
 * @return {Element|null} Null on the canonical page, where the window scrolls.
 */
export const scrollport = (root) => {
    if (!root) {
        return null;
    }

    const detail = root.matches(SELECTORS.detail) ? root : root.querySelector(SELECTORS.detail);
    const conversation = detail && detail.querySelector(SELECTORS.conversation);
    if (!conversation) {
        return null;
    }

    for (let el = conversation; el && el !== detail.parentElement; el = el.parentElement) {
        if (scrolls(el)) {
            return el;
        }
    }

    return null;
};

/**
 * Take ownership of a scrollport, invalidating whatever was scheduled against it.
 *
 * @param {Element} el
 * @return {number} The claim this caller holds.
 */
const claim = (el) => {
    const token = (claims.get(el) || 0) + 1;
    claims.set(el, token);
    return token;
};

/**
 * Where the conversation is scrolled to right now.
 *
 * @param {Element} root
 * @return {number} 0 when nothing inside the detail scrolls.
 */
export const position = (root) => {
    const el = scrollport(root);
    return el ? el.scrollTop : 0;
};

/**
 * Put the conversation back where it was.
 *
 * @param {Element} root
 * @param {number}  top
 */
export const restore = (root, top) => {
    const el = scrollport(root);
    if (!el) {
        return;
    }
    claim(el);
    el.scrollTop = top;
};

/**
 * Show the newest message in the thread.
 *
 * @param {Element} root
 */
export const toLatest = (root) => {
    const el = scrollport(root);
    if (!el) {
        return;
    }

    const token = claim(el);
    const pin = () => {
        // Someone else has since said where this port should be.
        if (claims.get(el) !== token || !el.isConnected) {
            return;
        }
        el.scrollTop = el.scrollHeight;
    };

    pin();
    window.requestAnimationFrame(() => {
        pin();
        window.requestAnimationFrame(pin);
    });
};
