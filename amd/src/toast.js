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
 * Transient confirmations for the mooDesk application shell.
 *
 * Kept deliberately small: a live region that already exists in the shell, one
 * element per message, and a timer. The shell runs on Moodle's 'embedded'
 * layout, which renders no notification region at all, so this is also how
 * messages queued by a redirect reach the user (view.php drains the queue and
 * calls flash()).
 *
 * Screens adopt it one at a time — the ticket detail is the first. Bulk actions
 * and saved views keep their own inline feedback until they are migrated
 * deliberately.
 *
 * @module     local_moodesk/toast
 * @copyright  2026 Digital Mood LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const REGION_SELECTOR = '[data-region="mhd-toast-region"]';
const VISIBLE_TIMEOUT = 4000;
const REMOVE_DELAY = 200;

/**
 * The shell's live region, created on demand for pages that predate it.
 *
 * @return {Element}
 */
const region = () => {
    let el = document.querySelector(REGION_SELECTOR);
    if (el) {
        return el;
    }

    el = document.createElement('div');
    el.className = 'mhd-toast-region';
    el.setAttribute('data-region', 'mhd-toast-region');
    // Polite, not assertive: a saved property must not interrupt whatever the
    // screen reader is in the middle of announcing.
    el.setAttribute('aria-live', 'polite');
    el.setAttribute('aria-atomic', 'false');
    document.body.appendChild(el);

    return el;
};

/**
 * Show one message.
 *
 * @param {string} message Plain text; markup is not rendered.
 * @param {string} [type]  'success' (default), 'error', 'info' or 'warning'.
 */
export const show = (message, type) => {
    if (!message) {
        return;
    }

    const kind = type || 'success';
    const toast = document.createElement('div');
    toast.className = 'mhd-toast mhd-toast--' + kind;
    toast.setAttribute('role', 'status');

    if (kind === 'success') {
        // The shell's sprite is on every product screen; if a page ever lacks
        // it the <use> simply renders nothing and the text still reads.
        toast.insertAdjacentHTML(
            'afterbegin',
            '<svg class="mhd-toast__icon" aria-hidden="true" focusable="false">'
            + '<use href="#mhd-icon-resolved"></use></svg>'
        );
    }

    const text = document.createElement('span');
    text.className = 'mhd-toast__message';
    text.textContent = message;
    toast.appendChild(text);

    region().appendChild(toast);

    setTimeout(() => {
        toast.classList.add('is-leaving');
        setTimeout(() => toast.remove(), REMOVE_DELAY);
    }, VISIBLE_TIMEOUT);
};

/**
 * Show a batch of messages queued server-side.
 *
 * @param {Array} messages [{message, type}, …] — Moodle notification types map
 *                         straight onto the toast kinds.
 */
export const flash = (messages) => {
    (messages || []).forEach((entry) => {
        if (!entry) {
            return;
        }
        show(entry.message, entry.type || 'success');
    });
};
