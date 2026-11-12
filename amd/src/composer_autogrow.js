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
 * Grow a composer's message box to fit what is being written.
 *
 * The box opens at the form element's `rows` and grows a line at a time as the
 * message is typed, up to a ceiling set in CSS (`max-height` on the control),
 * after which it scrolls. Deleting shrinks it back. The form therefore claims
 * the height a message actually needs and no more, which is the whole reason the
 * rich editor was dropped: it opened at a toolbar whether or not anyone was
 * writing.
 *
 * The division of labour is deliberate. This module only ever sets a height to
 * match the content; it never reads or enforces the maximum. That lives in CSS
 * next to the rest of the control's sizing, so the ceiling can be changed
 * without touching JavaScript, and a session with scripting off still gets a box
 * that scrolls from its `rows` on — not one that grows without limit.
 *
 * One listener at the document, rather than one per box. Several boxes can share
 * a screen — the ticket list hosts the New ticket drawer and the ticket detail
 * drawer, each with a message box of its own — and boxes come and go as the
 * detail drawer swaps tickets. Listening at the document means no registry of
 * wired elements to keep in step with the DOM, no teardown that one form can run
 * over another form's box, and markup injected later works the moment it exists.
 *
 * @module     local_moodesk/composer_autogrow
 * @copyright  2026 Digital Mood LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTOR = '[data-region="mhd-composer-input"]';

/** @type {boolean} Whether the document listener is already installed. */
let listening = false;

/**
 * Match a box's height to its content.
 *
 * The reset to `auto` is what makes shrinking work: scrollHeight never reports
 * less than the element's current height, so measuring without it would let the
 * box grow and never come back down.
 *
 * @param {HTMLTextAreaElement} input
 */
const fit = (input) => {
    input.style.height = 'auto';
    input.style.height = input.scrollHeight + 'px';
};

/**
 * Start sizing message boxes, and size the ones already on screen.
 *
 * Safe to call again: the listener is installed once, and every later call only
 * re-measures what is currently in the document. Both mounts of both forms call
 * it, and the fragment that loads a ticket into the detail drawer calls it once
 * more per ticket.
 */
export const init = () => {
    if (!listening) {
        document.addEventListener('input', (e) => {
            if (e.target && e.target.matches && e.target.matches(SELECTOR)) {
                fit(e.target);
            }
        });
        listening = true;
    }

    // Fit once on the way in: a draft restored after a failed validation arrives
    // with its text already in the box.
    document.querySelectorAll(SELECTOR).forEach((input) => {
        if (input.value !== '') {
            fit(input);
        }
    });
};
