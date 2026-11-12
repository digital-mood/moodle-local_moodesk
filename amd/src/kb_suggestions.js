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
 * Suggested articles in the New Ticket form (KB-04).
 *
 * Watches the subject and the category and, after a pause in typing, asks
 * local_moodesk_search_kb_articles for up to five published articles worth
 * reading before the ticket is sent. The block it fills is rendered by the
 * server (local_moodesk/kb_suggestions) and placed by the form between the
 * category row and the description, so this module never decides where it
 * goes — only what is in it.
 *
 * Three rules hold it together:
 *
 *  - **The server is the boundary.** What is offered — published only, the
 *    audience the actor may see, the category preferred — is the endpoint's
 *    and the service's decision. This module sends what was typed and renders
 *    what came back. The minimum length is repeated here only to save a
 *    round trip; removing it changes nothing about what is shown.
 *  - **A reply is only as good as its sequence number.** Every request takes
 *    the next number and a reply is rendered only if it still carries the
 *    latest one. Replies are not assumed to arrive in order: a slow answer to
 *    "printer" arriving after a fast answer to "printer jam" is dropped, not
 *    painted over the newer list.
 *  - **The form is not this module's to touch.** It reads two fields and
 *    writes into its own block. Nothing here clears, sets or submits a field,
 *    which is what keeps a half-written ticket intact whatever the block does.
 *
 * States, carried as a class on the root: is-idle (nothing to show), is-loading
 * (first results on their way, skeleton in the body), is-updating (a newer
 * request in flight while the previous list is still shown, demoted),
 * is-results, is-error. "No matches" is idle — the block goes quiet rather
 * than saying so — but the live region does say so.
 *
 * @module     local_moodesk/kb_suggestions
 * @copyright  2026 Digital Mood LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';
import {get_string as getString} from 'core/str';

const SELECTORS = {
    root: '[data-region="mhd-kb-suggest"]',
    body: '[data-region="mhd-kb-suggest-body"]',
    live: '[data-region="mhd-kb-suggest-live"]',
};

const STATES = ['is-idle', 'is-loading', 'is-updating', 'is-results', 'is-error'];

const ITEMS_TEMPLATE = 'local_moodesk/kb_suggestion_items';

/** Skeleton shown while the first results are on their way. */
const SKELETON = '<div class="mhd-kb-suggest__skeleton" aria-hidden="true">'
    + '<span class="mhd-skeleton-line mhd-skeleton-medium"></span>'
    + '<span class="mhd-skeleton-line mhd-skeleton-full"></span>'
    + '<span class="mhd-skeleton-line mhd-skeleton-short"></span>'
    + '</div>';

/** How long a load has to take before the live region mentions it. */
const LOADING_ANNOUNCE_DELAY = 400;

/**
 * Put the block in one state and no other.
 *
 * @param {Element} root
 * @param {string} state One of STATES.
 */
const setState = (root, state) => {
    STATES.forEach((name) => root.classList.toggle(name, name === state));
    root.dataset.state = state.replace('is-', '');
};

/**
 * Say something through the live region.
 *
 * Cleared first so that the same sentence twice in a row is still read: an
 * aria-live region only announces on change.
 *
 * @param {Element} live
 * @param {string} text
 */
const announce = (live, text) => {
    live.textContent = '';
    // A separate task, so the clear and the write are two mutations.
    window.setTimeout(() => {
        live.textContent = text;
    }, 0);
};

/**
 * Attach the block's hint sentence to the subject field.
 *
 * Appended rather than assigned: moodleform sets aria-describedby itself when
 * the field has a validation error, and that link must survive.
 *
 * @param {Element} subject
 * @param {string} hintId
 */
const describeSubject = (subject, hintId) => {
    if (!document.getElementById(hintId)) {
        return;
    }
    const existing = (subject.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
    if (!existing.includes(hintId)) {
        existing.push(hintId);
        subject.setAttribute('aria-describedby', existing.join(' '));
    }
};

/**
 * Wire one block.
 *
 * @param {Element} root The block's root element.
 */
const wire = (root) => {
    const subject = document.getElementById(root.dataset.subjectId);
    if (!subject) {
        return;
    }
    // By name, within the subject's own form: the ticket list holds two forms
    // with a category field, and a hidden category (one active category on the
    // site) carries no id at all — but it is still the category the ticket
    // will be filed under.
    const category = subject.form && root.dataset.categoryName
        ? subject.form.elements.namedItem(root.dataset.categoryName)
        : null;
    const body = root.querySelector(SELECTORS.body);
    const live = root.querySelector(SELECTORS.live);
    const minLength = parseInt(root.dataset.minLength, 10) || 3;
    const debounce = parseInt(root.dataset.debounce, 10) || 300;

    describeSubject(subject, root.id + '-hint');

    /** @type {number} Sequence number of the latest request. Replies quote it. */
    let sequence = 0;
    /** @type {number|null} Pending debounce timer. */
    let timer = null;
    /** @type {string} Key of the last request that completed, to skip repeats. */
    let lastKey = '';
    /** @type {boolean} Whether a list is currently on screen. */
    let showing = false;

    const current = () => ({
        subject: subject.value.trim(),
        categoryid: category ? (parseInt(category.value, 10) || 0) : 0,
    });

    /**
     * Drop whatever is in flight and show nothing.
     */
    const clear = () => {
        sequence++;
        lastKey = '';
        showing = false;
        body.innerHTML = '';
        live.textContent = '';
        setState(root, 'is-idle');
    };

    /**
     * Render what the endpoint returned for a request that is still current.
     *
     * @param {number} mine The sequence this reply belongs to.
     * @param {Array} rows
     * @return {Promise}
     */
    const render = async(mine, rows) => {
        if (mine !== sequence) {
            return;
        }
        if (!rows.length) {
            showing = false;
            body.innerHTML = '';
            setState(root, 'is-idle');
            announce(live, root.dataset.strNone);
            return;
        }
        const newtab = await getString('kb_suggest_opens_new_tab', 'local_moodesk');
        const {html, js} = await Templates.renderForPromise(ITEMS_TEMPLATE, {articles: rows, newtab});
        // The template took a moment; the subject may have moved on meanwhile.
        if (mine !== sequence) {
            return;
        }
        Templates.replaceNodeContents(body, html, js);
        showing = true;
        setState(root, 'is-results');
        announce(live, rows.length === 1
            ? root.dataset.strResultsOne
            : root.dataset.strResults.replace('{$a}', String(rows.length)));
    };

    /**
     * Ask the endpoint for the fields as they are now.
     */
    const search = () => {
        const {subject: text, categoryid} = current();

        if (text.length < minLength) {
            clear();
            return;
        }

        const key = categoryid + ':' + text;
        if (key === lastKey) {
            return;
        }

        const mine = ++sequence;
        lastKey = key;

        if (showing) {
            // The old list stays readable but visibly not current.
            setState(root, 'is-updating');
        } else {
            body.innerHTML = SKELETON;
            setState(root, 'is-loading');
        }

        // Only a load worth mentioning is mentioned.
        const announceTimer = window.setTimeout(() => {
            if (mine === sequence && !showing) {
                announce(live, root.dataset.strLoading);
            }
        }, LOADING_ANNOUNCE_DELAY);

        Ajax.call([{
            methodname: 'local_moodesk_search_kb_articles',
            args: {subject: text, categoryid},
        }])[0]
            .then((rows) => {
                window.clearTimeout(announceTimer);
                return render(mine, Array.isArray(rows) ? rows : []);
            })
            .catch(() => {
                window.clearTimeout(announceTimer);
                if (mine !== sequence) {
                    return;
                }
                // The next keystroke retries; until then, say so once.
                lastKey = '';
                showing = false;
                body.innerHTML = '';
                setState(root, 'is-error');
                announce(live, root.dataset.strError);
            });
    };

    const schedule = () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(search, debounce);
    };

    subject.addEventListener('input', schedule);
    // A field cleared or filled by something other than keystrokes — a
    // browser's autofill, a driver's clear() — reports change, not input.
    subject.addEventListener('change', schedule);
    if (category) {
        category.addEventListener('change', schedule);
    }

    // A submit takes the page with it; nothing in flight should land on it.
    const form = subject.form;
    if (form) {
        form.addEventListener('submit', () => {
            window.clearTimeout(timer);
            sequence++;
        });
    }

    // A re-rendered form (a failed validation on submit.php) comes back with
    // the subject already typed; the requester should not have to type it
    // again to be offered an answer.
    if (subject.value.trim().length >= minLength) {
        search();
    }
};

/**
 * Wire every suggestion block on the page.
 */
export const init = () => {
    document.querySelectorAll(SELECTORS.root).forEach((root) => {
        if (root.dataset.wired) {
            return;
        }
        root.dataset.wired = '1';
        wire(root);
    });
};
