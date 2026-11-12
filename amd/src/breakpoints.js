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
 * The layout's breakpoints, read from the stylesheet that owns them.
 *
 * Some behaviour cannot be expressed in a media query: opening a navigation
 * drawer, moving focus into a tab panel, tearing down a listener. Those live in
 * JavaScript, and they have to change at the same width the CSS does.
 *
 * Rather than repeat the number here, this module reads the custom properties
 * published by scss/base/_breakpoint-tokens.scss, which take their values from
 * scss/abstracts/_breakpoints.scss — the one place the scale is written down. A
 * breakpoint edit therefore moves the CSS and the behaviour together.
 *
 * The fallbacks below are not a second source of truth: they only cover the
 * case where the stylesheet has not loaded, and they lose to the token whenever
 * one is present.
 *
 * @module     local_moodesk/breakpoints
 * @copyright  2026 Digital Mood LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Widths to assume when the stylesheet has not been applied.
 *
 * @type {Object<string, number>}
 */
const FALLBACK = {
    '--mhd-bp-mobile': 576,
    '--mhd-bp-tablet-max': 768,
    '--mhd-bp-desktop': 992,
    '--mhd-bp-wide': 1200,
    '--mhd-bp-ultrawide': 1800,
};

/**
 * Read one breakpoint token as a number of pixels.
 *
 * Resolved against the document element: the tokens are published on :root, so
 * they are in scope whether or not the app shell is the thing rendering.
 *
 * @param {string} name Custom property name, including the leading dashes.
 * @return {number} Width in pixels.
 */
const px = (name) => {
    let raw = '';

    try {
        raw = getComputedStyle(document.documentElement).getPropertyValue(name);
    } catch (e) {
        // A detached or styleless document: fall through to the fallback.
    }

    const value = parseInt(raw, 10);

    return Number.isFinite(value) ? value : FALLBACK[name];
};

/**
 * The width at which the tablet step begins — the phone step ends one pixel
 * below it.
 *
 * @return {number}
 */
export const mobileMax = () => px('--mhd-bp-mobile') - 1;

/**
 * Media query string matching phones only.
 *
 * @return {string}
 */
export const upToMobile = () => `(max-width: ${mobileMax()}px)`;

/**
 * A MediaQueryList for phones only.
 *
 * @return {MediaQueryList|null} Null where matchMedia is unavailable.
 */
export const upToMobileQuery = () =>
    (typeof window.matchMedia === 'function' ? window.matchMedia(upToMobile()) : null);

/**
 * The last pixel that still belongs to the tablet step.
 *
 * Inclusive, unlike the other boundaries — 768px is an iPad held upright and
 * the shell keeps its off-canvas drawer there. See the SCSS for the reasoning.
 *
 * @return {number}
 */
export const tabletMax = () => px('--mhd-bp-tablet-max');

/**
 * Media query string matching the tablet step and everything below it.
 *
 * This is the query the app shell's drawer and the ticket detail's tab switch
 * both key off, so the two agree by construction.
 *
 * @return {string}
 */
export const upToTablet = () => `(max-width: ${tabletMax()}px)`;

/**
 * A MediaQueryList for the tablet step and below.
 *
 * @return {MediaQueryList|null} Null where matchMedia is unavailable.
 */
export const upToTabletQuery = () =>
    (typeof window.matchMedia === 'function' ? window.matchMedia(upToTablet()) : null);

/**
 * The first pixel of the desktop layout.
 *
 * @return {number}
 */
export const desktopMin = () => px('--mhd-bp-desktop');

/**
 * Media query string matching everything below the desktop layout.
 *
 * The complement of `desktop`, and a step wider than `upToTablet`: the two
 * answer different questions. `upToTablet` is where the app shell puts its
 * navigation off-canvas; this is where a two-column content layout stops
 * fitting, which happens earlier in pixels because the shell's own sidebar is
 * still taking 256px of the window.
 *
 * @return {string}
 */
export const belowDesktop = () => `(max-width: ${desktopMin() - 1}px)`;

/**
 * A MediaQueryList for everything below the desktop layout.
 *
 * @return {MediaQueryList|null} Null where matchMedia is unavailable.
 */
export const belowDesktopQuery = () =>
    (typeof window.matchMedia === 'function' ? window.matchMedia(belowDesktop()) : null);
