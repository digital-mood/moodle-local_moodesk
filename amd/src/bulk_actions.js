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
 * Bulk action toolbar behaviour for the ticket list.
 *
 * @module     local_moodesk/bulk_actions
 * @copyright  2026 Digital Mood LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {get_strings as getStrings} from 'core/str';
import {REFRESHED_EVENT} from 'local_moodesk/ticket_list';

const countChecked = () => document.querySelectorAll('.mhd-bulk-check:checked').length;

const showActionParams = (action) => {
    document.querySelectorAll('.mhd-bulk-params').forEach((node) => {
        node.hidden = true;
    });
    const target = document.getElementById('mhd-bulk-params-' + action);
    if (target) {
        target.hidden = false;
    }
};

/**
 * Build and send the bulk POST.
 *
 * @param {string} actionUrl
 * @param {string} sesskey
 * @param {string} returnUrl
 * @param {Function} onSubmit Called just before the form leaves, and only if it does.
 */
const submitBulkForm = (actionUrl, sesskey, returnUrl, onSubmit) => {
    const select = document.getElementById('mhd-bulk-action-select');
    const action = select ? select.value : '';
    const checked = document.querySelectorAll('.mhd-bulk-check:checked');
    if (!action || checked.length === 0) {
        return;
    }

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
    appendField('bulkaction', action);
    checked.forEach((node) => appendField('ticketids[]', node.value));

    // Where to land once the action has run. The server validates this as a
    // local URL and falls back to the bare list if it is anything else.
    if (returnUrl) {
        appendField('returnurl', returnUrl);
    }

    if (action === 'status') {
        const newStatus = document.getElementById('mhd-bulk-newstatus');
        if (newStatus) {
            appendField('newstatus', newStatus.value);
        }
    } else if (action === 'assign') {
        const assignee = document.getElementById('mhd-bulk-assigneeid');
        if (assignee) {
            appendField('assigneeid', assignee.value);
        }
    }

    document.body.appendChild(form);
    onSubmit();
    form.submit();
};

/**
 * Wire the toolbar.
 *
 * @param {string} actionUrl  bulk_action.php
 * @param {string} sesskey
 * @param {number} confirmFrom From this many tickets Apply asks first; 0 never asks.
 *                             ticket_service::BULK_CONFIRM_FROM, so the number lives in one place.
 */
export const init = async (actionUrl, sesskey, confirmFrom = 0) => {
    const toolbar = document.getElementById('mhd-bulk-toolbar');
    const counter = document.getElementById('mhd-bulk-count');
    const applyBtn = document.getElementById('mhd-bulk-apply');
    const clearBtn = document.getElementById('mhd-bulk-clear');
    const actionSelect = document.getElementById('mhd-bulk-action-select');

    // Only the toolbar and its counter are load-bearing: without them there is
    // nothing to show a selection in. Select-all is a convenience on top, and
    // losing it must not take every bulk action down with it — which is exactly
    // what happened while this guard also demanded it and no template rendered
    // it. See tests/bulk_markup_test.php, which now fails before this file can.
    if (!toolbar || !counter) {
        return;
    }

    const returnUrl = toolbar.dataset.returnurl || '';

    const [oneLabel, manyLabel, applyingLabel, confirmLabel] = await getStrings([
        {key: 'bulk_count_one', component: 'local_moodesk'},
        {key: 'bulk_count_many', component: 'local_moodesk'},
        {key: 'bulk_applying', component: 'local_moodesk'},
        {key: 'bulk_confirm', component: 'local_moodesk'}
    ]);
    const applyLabel = applyBtn ? applyBtn.textContent : '';

    const refreshCounter = () => {
        const count = countChecked();
        toolbar.hidden = count === 0;
        if (count > 0) {
            counter.textContent = count === 1 ? oneLabel : manyLabel.replace('{$a}', count);
        }
    };

    // Select-all covers the rows on this page, which is every row the list
    // rendered. Nothing selects beyond it: the POST carries explicit ids.
    const refreshSelectAll = () => {
        // Looked up per call rather than held from init: the results region is
        // replaced on every list refresh, so the box that existed at init is not
        // necessarily the box on screen now.
        const box = document.getElementById('mhd-select-all');
        if (!box) {
            return;
        }
        const total = document.querySelectorAll('.mhd-bulk-check').length;
        const checked = countChecked();
        box.checked = total > 0 && checked === total;
        box.indeterminate = checked > 0 && checked < total;
    };

    // The row carries what its checkbox says, so a selection reads at a glance
    // rather than box by box. Derived from the boxes every time, never toggled
    // on its own, so the two cannot disagree.
    const refreshRows = () => {
        document.querySelectorAll('.mhd-bulk-check').forEach((node) => {
            const row = node.closest('.mhd-ticket-row');
            if (row) {
                row.classList.toggle('mhd-bulk-selected', node.checked);
            }
        });
    };

    const refresh = () => {
        refreshSelectAll();
        refreshCounter();
        refreshRows();
    };

    // While the POST is in flight the page is about to be replaced, and until it
    // is, nothing said that anything was happening. Apply says so; it and every
    // control that would change the batch are disabled, so a second click cannot
    // send the same batch twice and a box ticked now cannot look as if it joined.
    const setBusy = (busy) => {
        toolbar.setAttribute('aria-busy', busy ? 'true' : 'false');
        toolbar.querySelectorAll('button, select').forEach((node) => {
            node.disabled = busy;
        });
        document.querySelectorAll('.mhd-bulk-check, #mhd-select-all').forEach((node) => {
            node.disabled = busy;
        });
        if (applyBtn) {
            applyBtn.textContent = busy ? applyingLabel : applyLabel;
        }
    };

    // Delegated, unlike the toolbar controls above it. Select-all lives inside
    // the results region, which is replaced wholesale when the list refreshes
    // itself after a mutation — a listener bound to the node found at init would
    // be attached to markup that no longer exists, and re-running init() to fix
    // that would double every other handler in this module.
    document.addEventListener('change', (event) => {
        const box = event.target.closest
            ? event.target.closest('#mhd-select-all')
            : null;
        if (!box) {
            return;
        }
        document.querySelectorAll('.mhd-bulk-check').forEach((node) => {
            node.checked = box.checked;
        });
        // Clicking the box clears indeterminate on its own; setting it back
        // to false keeps the flag honest for anything reading it later.
        box.indeterminate = false;
        refresh();
    });

    document.addEventListener('change', (event) => {
        if (!event.target.classList.contains('mhd-bulk-check')) {
            return;
        }
        refresh();
    });

    if (actionSelect) {
        actionSelect.addEventListener('change', () => {
            showActionParams(actionSelect.value);
        });
    }

    if (applyBtn) {
        applyBtn.addEventListener('click', (event) => {
            event.preventDefault();
            // Every ticket in the batch may send its own email inside this one
            // request, so a large batch can take many seconds; it says so before
            // it starts rather than after. Only when there is an action to run.
            const count = countChecked();
            if (confirmFrom > 0 && count >= confirmFrom && actionSelect && actionSelect.value
                    && !window.confirm(confirmLabel.replace('{$a}', count))) {
                return;
            }
            submitBulkForm(actionUrl, sesskey, returnUrl, () => setBusy(true));
        });
    }

    // A list refresh replaces every row, so whatever was selected is gone with
    // the markup. Reconcile rather than remember: the toolbar hides itself and
    // the box drops back to unchecked, so no id can stay selected without a
    // checkbox on screen to show it.
    document.addEventListener(REFRESHED_EVENT, refresh);

    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            document.querySelectorAll('.mhd-bulk-check').forEach((node) => {
                node.checked = false;
            });
            refresh();
        });
    }

    // Back from the result page, the browser may restore this one from its
    // cache exactly as it was left: mid-submit, with every control disabled.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            setBusy(false);
            refresh();
        }
    });

    // A reload or a history step can hand back boxes the browser kept ticked.
    refresh();
};
