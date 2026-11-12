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
 * The only module that knows Moodle's file picker exists.
 *
 * Everything YUI-shaped about attachments lives here: the `Y` global, the
 * `M.core_filepicker` namespace, its instance registry and its callback
 * conventions. The composer talks to this module and to nothing else, so when
 * Moodle eventually retires YUI the repair is one file rather than a hunt
 * (ADR: docs/reply-composer-attachments.md §2.3).
 *
 * The pattern is core's own. Moodle's default editor opens the picker from a
 * button it drew itself, exactly this way — see
 * `lib/editor/tiny/amd/src/utils.js`, which passes a `formcallback` and a
 * `previousActiveElement` and lets the picker do the rest.
 *
 * A note on the shape of {@link open}. It takes a callback rather than
 * returning a promise, because a promise would be a lie about what the picker
 * does: there is no cancellation signal, so a picker the user dismisses would
 * leave a promise pending forever, and a callback that may fire zero or more
 * times is the honest contract.
 *
 * @module     local_moodesk/filepicker_bridge
 * @copyright  2026 Digital Mood LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @type {Promise|null} The in-flight or settled module load. */
let loading = null;

/**
 * Moodle's YUI instance, if this page has one.
 *
 * @return {object|null}
 */
const yui = () => (window.Y && typeof window.Y.use === 'function' ? window.Y : null);

/**
 * The file picker namespace, if it has been loaded.
 *
 * @return {object|null}
 */
const picker = () => (window.M && window.M.core_filepicker) || null;

/**
 * Load the picker's YUI module, once per page.
 *
 * Usually redundant: the server queues `M.core_filepicker.set_templates`, and
 * `js_init_call` infers the `core_filepicker` module from that function name and
 * loads it. This covers the case where those templates were already sent earlier
 * in the same request and the call was therefore skipped.
 *
 * @return {Promise} Resolves once M.core_filepicker is usable.
 */
const load = () => {
    if (loading) {
        return loading;
    }

    loading = new Promise((resolve, reject) => {
        const Y = yui();
        if (!Y) {
            reject(new Error('local_moodesk: YUI is not available on this page.'));
            return;
        }

        Y.use('core_filepicker', () => {
            if (!picker()) {
                reject(new Error('local_moodesk: the file picker module did not load.'));
                return;
            }
            resolve();
        });
    });

    return loading;
};

/**
 * Whether this page can open a file picker at all.
 *
 * @return {boolean}
 */
export const isAvailable = () => yui() !== null;

/**
 * Open the file picker over a draft area.
 *
 * The options come from the server, built by `initialise_filepicker()` and
 * carrying the draft item id, so the file the user chooses is written straight
 * into that draft — it is already saved by the time `onFile` runs. Nothing here
 * uploads, stores or validates anything.
 *
 * @param {object}   options Server-built picker options; must carry client_id and itemid.
 * @param {Function} onFile  Called after each file lands in the draft.
 * @return {Promise} Rejects if the picker could not be opened.
 */
export const open = (options, onFile) => load().then(() => {
    picker().show(window.Y, {
        ...options,
        formcallback: onFile,
        // Where the focus goes when the picker closes.
        previousActiveElement: document.activeElement,
    });
});

/**
 * Discard the picker instance belonging to one composer.
 *
 * The registry is global and outlives the markup: an instance left behind
 * against a discarded composer still answers to its client id, and the active
 * pointer still routes upload callbacks to it. Both have to go before the
 * markup does.
 *
 * @param {string} clientid The client_id this composer was rendered with.
 */
export const release = (clientid) => {
    const fp = picker();
    if (!fp || !fp.instances || !clientid) {
        return;
    }

    const instance = fp.instances[clientid];
    if (!instance) {
        return;
    }

    if (fp.active_filepicker === instance) {
        fp.active_filepicker = null;
    }

    if (typeof instance.hide === 'function') {
        try {
            instance.hide();
        } catch (e) {
            window.console.warn('local_moodesk: could not hide file picker.', e);
        }
    }

    delete fp.instances[clientid];
};
