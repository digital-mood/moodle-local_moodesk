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
 * Toggle between public reply and internal note in the ticket reply editor.
 *
 * One composer sends two different things, and the mode is the difference. What
 * the toggle changes is the hidden `replytype` the form posts, the styling that
 * says a note is being written, and the send control's name — because that
 * control is an icon, and an icon that means two things has to say which one it
 * means right now. Both strings are already on the button (put there by
 * classes/form/reply_form.php), so switching mode costs no round trip.
 *
 * @module     local_moodesk/reply_toggle
 * @copyright  2026 Digital Mood LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SEND = '[data-region="mhd-composer-send"]';

/**
 * Name the send control for the mode the composer is in.
 *
 * @param {Element} send      The send button, when the composer has one.
 * @param {number}  replytype 1 for an internal note, 0 for a public reply.
 */
const nameSend = (send, replytype) => {
    if (!send) {
        return;
    }
    const label = replytype === 1 ? send.dataset.labelNote : send.dataset.labelReply;
    if (!label) {
        return;
    }
    // Both, because they answer different questions: aria-label is the name a
    // screen reader announces, title is what a pointer user gets on hover — and
    // an icon-only control owes an answer to each.
    send.setAttribute('aria-label', label);
    send.setAttribute('title', label);
};

export const init = () => {
    const editor = document.getElementById('mhd-reply-editor');
    if (!editor) {
        return;
    }
    const tabs = editor.querySelectorAll('.mhd-reply-tab');
    if (!tabs.length) {
        return;
    }
    const form = editor.querySelector('form');
    if (!form) {
        return;
    }
    const replyTypeInput = form.querySelector('input[name="replytype"]');
    if (!replyTypeInput) {
        return;
    }

    const send = form.querySelector(SEND);

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            const replyType = parseInt(tab.dataset.replytype, 10);
            replyTypeInput.value = replyType;
            tabs.forEach((other) => other.classList.remove('active'));
            tab.classList.add('active');
            if (replyType === 1) {
                editor.classList.add('is-note');
            } else {
                editor.classList.remove('is-note');
            }
            nameSend(send, replyType);
        });
    });
};
