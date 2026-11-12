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
 * Filter bar behaviour: status dropdown, daterange toggle, save/delete views,
 * and folding the secondary filters away on phones.
 *
 * @module     local_moodesk/filters
 * @copyright  2026 Digital Mood LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Pending from 'core/pending';
import {upToMobileQuery} from 'local_moodesk/breakpoints';

const checkboxLabel = (input) => {
    const label = input.closest('label');
    if (!label) {
        return input.value;
    }
    const clone = label.cloneNode(true);
    clone.querySelectorAll('input').forEach((node) => node.remove());
    return clone.textContent.trim();
};

const refreshStatusLabel = (dropdown, defaultLabel) => {
    const labelEl = dropdown.querySelector('.mhd-status-label');
    if (!labelEl) {
        return;
    }
    const checked = Array.from(dropdown.querySelectorAll('.mhd-status-checkbox:checked'));
    if (checked.length === 0) {
        labelEl.textContent = defaultLabel;
    } else if (checked.length === 1) {
        labelEl.textContent = checkboxLabel(checked[0]);
    } else {
        labelEl.textContent = checked.length + ' selected';
    }
};

const hasDaterangeValue = (panel) => Array.from(
    panel.querySelectorAll('input[type="date"], input[type="text"]')
).some((node) => node.value.trim() !== '');

const postJson = async (url, sesskey, payload) => {
    // Held for the round trip, so anything waiting for the page to settle
    // (Behat's "page is ready", above all) waits for the save too.
    const pending = new Pending('local_moodesk/filters:' + payload.action);
    try {
        const body = new URLSearchParams({sesskey, ...payload});
        const response = await fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
        });
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        return await response.json();
    } finally {
        pending.resolve();
    }
};

const collectFilterParams = () => {
    const bar = document.querySelector('.mhd-filter-bar');
    if (!bar) {
        return {};
    }
    const params = {};
    for (const [field, value] of new FormData(bar).entries()) {
        // A multi-select field is named `status[]` in the markup because that is
        // how a browser spells a repeated field. The parameter is `status`.
        const key = field.endsWith('[]') ? field.slice(0, -2) : field;
        if (Object.prototype.hasOwnProperty.call(params, key)) {
            if (!Array.isArray(params[key])) {
                params[key] = [params[key]];
            }
            params[key].push(value);
        } else {
            params[key] = value;
        }
    }
    return params;
};

const initStatusDropdown = () => {
    const dropdown = document.querySelector('.mhd-status-dropdown');
    if (!dropdown) {
        return;
    }
    const trigger = dropdown.querySelector('.mhd-status-trigger');
    const panel = dropdown.querySelector('.mhd-status-panel');
    if (!trigger || !panel) {
        return;
    }
    const defaultLabel = trigger.querySelector('.mhd-status-label')?.textContent.trim() ?? '';

    trigger.addEventListener('click', (event) => {
        event.stopPropagation();
        const wasHidden = panel.hidden;
        panel.hidden = !wasHidden;
        trigger.setAttribute('aria-expanded', String(wasHidden));
    });

    dropdown.addEventListener('change', (event) => {
        if (event.target.classList.contains('mhd-status-checkbox')) {
            refreshStatusLabel(dropdown, defaultLabel);
        }
    });

    document.addEventListener('click', (event) => {
        if (!dropdown.contains(event.target)) {
            panel.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
        }
    });

    refreshStatusLabel(dropdown, defaultLabel);
};

const initDaterangeValidation = (invalidMessage) => {
    if (!invalidMessage) {
        return;
    }
    document.querySelectorAll('.mhd-daterange-block, .mhd-daterange-panel').forEach((panel) => {
        panel.querySelectorAll('input[type="date"][name$="_from"]').forEach((fromInput) => {
            const toName = fromInput.name.replace(/_from$/, '_to');
            const toInput = panel.querySelector(`input[type="date"][name="${toName}"]`);
            if (!toInput) {
                return;
            }
            const validate = () => {
                const fv = fromInput.value;
                const tv = toInput.value;
                const inverted = fv && tv && fv > tv;
                const target = toInput;
                target.setCustomValidity(inverted ? invalidMessage : '');
                target.classList.toggle('is-invalid', Boolean(inverted));
            };
            fromInput.addEventListener('change', validate);
            toInput.addEventListener('change', validate);
            validate();
        });
    });
};

const initDaterangeToggles = () => {
    document.querySelectorAll('[data-toggle="mhd-daterange"]').forEach((toggle) => {
        const selector = toggle.dataset.target;
        const panel = selector ? document.querySelector(selector) : null;
        if (!panel) {
            return;
        }

        if (hasDaterangeValue(panel)) {
            panel.hidden = false;
            toggle.classList.add('is-active');
            toggle.setAttribute('aria-expanded', 'true');
        }

        toggle.addEventListener('click', () => {
            const wasHidden = panel.hidden;
            panel.hidden = !wasHidden;
            toggle.classList.toggle('is-active', wasHidden || hasDaterangeValue(panel));
            toggle.setAttribute('aria-expanded', String(wasHidden));
        });

        panel.addEventListener('change', () => {
            toggle.classList.toggle('is-active', hasDaterangeValue(panel));
        });
    });
};

const initSaveView = (ajaxUrl, sesskey) => {
    const openBtn = document.getElementById('mhd-save-view-btn');
    const inline = document.getElementById('mhd-saveview-inline');
    const nameInput = document.getElementById('mhd-saveview-name');
    const confirmBtn = document.getElementById('mhd-saveview-confirm');
    const cancelBtn = document.getElementById('mhd-saveview-cancel');

    if (!openBtn || !inline || !nameInput || !confirmBtn || !cancelBtn) {
        return;
    }

    const close = () => {
        inline.hidden = true;
    };

    const save = async () => {
        const name = nameInput.value.trim();
        if (!name) {
            nameInput.focus();
            return;
        }
        confirmBtn.disabled = true;
        try {
            const result = await postJson(ajaxUrl, sesskey, {
                action: 'save_filter_view',
                name,
                params: JSON.stringify(collectFilterParams())
            });
            if (result.success) {
                window.location.reload();
            } else {
                confirmBtn.disabled = false;
            }
        } catch {
            confirmBtn.disabled = false;
        }
    };

    openBtn.addEventListener('click', () => {
        inline.hidden = false;
        nameInput.value = '';
        nameInput.focus();
    });
    cancelBtn.addEventListener('click', close);
    confirmBtn.addEventListener('click', save);
    nameInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            save();
        } else if (event.key === 'Escape') {
            close();
        }
    });
};

const initDeleteView = (ajaxUrl, sesskey) => {
    document.addEventListener('click', async (event) => {
        const btn = event.target.closest('[data-action="delete-view"]');
        if (!btn) {
            return;
        }
        const key = btn.dataset.key;
        if (!key) {
            return;
        }
        btn.disabled = true;
        try {
            const result = await postJson(ajaxUrl, sesskey, {
                action: 'delete_filter_view',
                key
            });
            if (result.success) {
                btn.closest('.mhd-saved-view-item')?.remove();
            } else {
                btn.disabled = false;
            }
        } catch {
            btn.disabled = false;
        }
    });
};

/**
 * Fold the secondary filters on phones, leave them open everywhere else.
 *
 * The <details> ships open, which is the state that degrades safely: with no
 * JavaScript every filter stays reachable. Folding is the enhancement, and it
 * only applies below the mobile breakpoint — that width comes from the
 * stylesheet through local_moodesk/breakpoints, so this and the CSS that hides
 * the summary above the same width cannot drift apart.
 *
 * A viewer who opens the group by hand keeps it open: once they have said what
 * they want, resizing is not a reason to overrule them.
 */
const initMoreFilters = () => {
    const details = document.querySelector('[data-region="mhd-filter-more"]');
    const mq = upToMobileQuery();
    if (!details || mq === null) {
        return;
    }

    // Intent is read from the summary, not from the 'toggle' event: toggle also
    // fires for the changes made below, and it fires asynchronously, so a flag
    // around the assignment would not reliably tell the two apart. Activating
    // the summary — by pointer or by keyboard, both of which raise click — is
    // the only way a person opens a <details>.
    let touched = false;
    const summary = details.querySelector('summary');
    if (summary) {
        summary.addEventListener('click', () => {
            touched = true;
        });
    }

    const apply = () => {
        if (!touched) {
            details.open = !mq.matches;
        }
    };

    apply();

    if (typeof mq.addEventListener === 'function') {
        mq.addEventListener('change', apply);
    } else if (typeof mq.addListener === 'function') {
        mq.addListener(apply);
    }
};

const initAutoSubmit = () => {
    const bar = document.querySelector('.mhd-filter-bar');
    if (!bar) {
        return;
    }
    ['select[name="assigneeid"]', 'select[data-mhd-plugin-filter]'].forEach((selector) => {
        bar.querySelectorAll(selector).forEach((node) => {
            node.addEventListener('change', () => bar.submit());
        });
    });
};

export const init = (ajaxUrl, sesskey, daterangeInvalidMessage) => {
    initStatusDropdown();
    initDaterangeToggles();
    initDaterangeValidation(daterangeInvalidMessage);
    initSaveView(ajaxUrl, sesskey);
    initDeleteView(ajaxUrl, sesskey);
    initAutoSubmit();
    initMoreFilters();
};
