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
 * Inline row actions for the ticket list (assign-to-me, quick status change).
 *
 * @module     local_moodesk/ticket_list_actions
 * @copyright  2026 Digital Mood LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const submitInlineForm = (actionUrl, sesskey, fields) => {
    const form = document.createElement('form');
    form.method = 'post';
    form.action = actionUrl;

    const appendField = (name, value) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    };

    appendField('sesskey', sesskey);
    Object.entries(fields).forEach(([name, value]) => appendField(name, value));

    document.body.appendChild(form);
    form.submit();
};

export const init = (actionUrl, sesskey, currentUserId) => {
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-action="assign_me"]');
        if (!trigger) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        const ticketId = trigger.dataset.ticketid;
        if (!ticketId) {
            return;
        }
        submitInlineForm(actionUrl, sesskey, {
            bulkaction: 'assign',
            'ticketids[]': ticketId,
            assigneeid: currentUserId
        });
    });

    document.addEventListener('change', (event) => {
        const select = event.target.closest('[data-action="set_status"]');
        if (!select || !select.value) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        const ticketId = select.dataset.ticketid;
        if (!ticketId) {
            return;
        }
        submitInlineForm(actionUrl, sesskey, {
            bulkaction: 'status',
            'ticketids[]': ticketId,
            newstatus: select.value
        });
    });
};
