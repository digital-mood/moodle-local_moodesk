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
 * New-ticket drawer behaviour. Vanilla DOM, no framework or dependency.
 *
 * Progressive enhancement: the "New ticket" triggers are plain links to
 * submit.php. This module only upgrades them into drawer triggers when the
 * panel is present, so without JavaScript the user still reaches the full
 * submission page.
 *
 * The panel is never display:none — it is hidden with visibility so the
 * composer's message box measures itself against a container that already has
 * its final width, and so its controls stay out of the tab order while closed.
 *
 * Panel mechanics (backdrop, scroll lock, focus trap, Escape, focus return)
 * come from local_moodesk/drawer, which the ticket detail drawer shares.
 *
 * @module     local_moodesk/ticket_drawer
 * @copyright  2026 Digital Mood LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as Drawer from 'local_moodesk/drawer';
import * as ComposerAttachments from 'local_moodesk/composer_attachments';

const SELECTORS = {
    mount: '[data-region="mhd-ticket-drawer"]',
    panel: '[data-region="mhd-drawer-panel"]',
    backdrop: '[data-region="mhd-drawer-backdrop"]',
    trigger: '[data-action="mhd-open-new-ticket"]',
    close: '[data-action="mhd-drawer-close"]',
    // moodleform's own cancel button — closing the drawer replaces its redirect.
    cancel: 'input[name="cancel"], button[name="cancel"]',
    firstfield: '#id_subject',
    // What the user may have written or attached and not sent.
    subject: 'input[name="subject"]',
    message: '[data-region="mhd-composer-input"]',
};

/**
 * Whether the form holds work the user has not submitted.
 *
 * A file waiting in the draft counts on its own. Unlike a half-written
 * description it leaves no trace in the form once the list is out of view, so
 * closing on it would cost the user the upload with nothing having warned them.
 *
 * The question is scoped to this panel: the ticket detail drawer shares this
 * screen and runs a composer of its own, and a reply being written there is not
 * this form's unsent work.
 *
 * @param {Element} panel
 * @return {boolean}
 */
const hasUnsentContent = (panel) => {
    if (ComposerAttachments.hasDraftFiles(panel)) {
        return true;
    }

    return [SELECTORS.subject, SELECTORS.message].some((selector) => {
        const field = panel.querySelector(selector);
        return !!field && field.value.trim() !== '';
    });
};

/**
 * Wire the drawer: triggers, close affordances and focus handling.
 */
export const init = () => {
    const mount = document.querySelector(SELECTORS.mount);
    if (!mount) {
        return;
    }

    const panel = mount.querySelector(SELECTORS.panel);
    const backdrop = mount.querySelector(SELECTORS.backdrop);
    if (!panel) {
        return;
    }

    const confirmMessage = mount.dataset.unsavedConfirm;

    // Submitting is not discarding. The form posts to submit.php and the page
    // goes with it, so the guard has to stand down for the one close that is
    // not a loss.
    let submitting = false;
    panel.addEventListener('submit', () => {
        submitting = true;
    });

    const drawer = Drawer.create({
        mount,
        panel,
        backdrop,
        // Land on the first field so the user can start typing straight away;
        // fall back to the close button when the form has none.
        initialFocus: () => panel.querySelector(SELECTORS.firstfield)
            || panel.querySelector(SELECTORS.close),
        // Ask before a ticket half written — or a file already uploaded into the
        // draft — is thrown away. Only when there is something to lose.
        canClose: () => submitting
            || !confirmMessage
            || !hasUnsentContent(panel)
            || window.confirm(confirmMessage),
    });

    // Triggers live outside the panel and may be re-rendered per screen, so bind
    // through the document rather than to each node.
    document.addEventListener('click', (e) => {
        const trigger = e.target.closest ? e.target.closest(SELECTORS.trigger) : null;
        if (trigger) {
            e.preventDefault();
            drawer.open(trigger);
        }
    });

    mount.addEventListener('click', (e) => {
        if (!e.target.closest) {
            return;
        }
        // Cancel is a real submit button: without JS it posts to submit.php and
        // redirects back to the list. Here it just closes the panel.
        if (e.target.closest(SELECTORS.close) || e.target.closest(SELECTORS.cancel)) {
            e.preventDefault();
            drawer.close();
        }
    });
};
