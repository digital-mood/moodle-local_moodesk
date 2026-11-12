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
 * Charts for the mooDesk operational dashboard.
 *
 * Uses Chart.js from Moodle core (`core/chartjs`), so the plugin ships no
 * charting library of its own: the package is already present on every
 * supported install, already audited, and already updated by Moodle HQ.
 *
 * WHAT GETS A CHART, AND WHAT DOES NOT. Five panels mount one — the trend, the
 * status doughnut, the SLA gauge and the priority and aging pies — because
 * those are the shapes CSS cannot draw honestly: two series against a shared
 * time axis, a ring with a total in the middle, a semicircular gauge, and a
 * circle divided by share. The category and department panels stay as CSS bars.
 * They already render exactly the shape the design calls for, they cost
 * nothing, and routing them through a canvas would trade accessible markup for
 * a picture.
 *
 * PROGRESSIVE ENHANCEMENT. Every panel renders complete without JavaScript.
 * Where a chart mounts, the CSS rendering is not deleted — it is moved out of
 * sight but left in the accessibility tree, so a screen reader still gets the
 * figures as text while the canvas is marked decorative. Nothing here is the
 * only copy of any number.
 *
 * The two pies keep their markup on screen instead of hiding it: a pie shows
 * shares but not counts, and its slices need naming. Restyled as a legend, the
 * same list is both the reading beside the chart and the text alternative
 * behind it — one set of figures serving both.
 *
 * COLOUR comes from the CSS custom properties in base/_app-tokens.scss, read at
 * runtime. A second palette hardcoded here would drift from the stylesheet the
 * first time a hue changed.
 *
 * @module     local_moodesk/dashboard_charts
 * @copyright  2026 Digital Mood LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Chart from 'core/chartjs';

/** Panels that mount a canvas, keyed by their data-mhd-chart value. */
const MOUNTS = ['trend', 'status', 'sla', 'priority', 'aging'];

/** Where the gauge starts and stops, in radians: a half circle opening upward. */
const GAUGE_ROTATION = -Math.PI / 2;
const GAUGE_CIRCUMFERENCE = Math.PI;

/**
 * Read a CSS custom property from the app shell.
 *
 * @param {string} name Property name, including the leading dashes.
 * @param {string} fallback Returned when the property is not set.
 * @return {string}
 */
const token = (name, fallback) => {
    const host = document.querySelector('.moodesk-app') || document.documentElement;
    const value = getComputedStyle(host).getPropertyValue(name);

    return value ? value.trim() : fallback;
};

/**
 * Colours for a set of ticket statuses, in the order given.
 *
 * @param {Array<number>} keys Status values as stored in the database.
 * @return {Array<string>}
 */
const statusColours = (keys) => keys.map((key) => token(`--mhd-status-${key}`, '#6c757d'));

/**
 * Colours for a set of priorities, in the order given.
 *
 * @param {Array<number>} keys Priority values as stored in the database.
 * @return {Array<string>}
 */
const priorityColours = (keys) => keys.map((key) => token(`--mhd-priority-${key}`, '#6c757d'));

/**
 * Colours for a set of aging bands, in the order given.
 *
 * @param {Array<number>} keys Band indices, youngest first.
 * @return {Array<string>}
 */
const agingColours = (keys) => keys.map((key) => token(`--mhd-aging-${key}`, '#6c757d'));

/**
 * Turn a hex colour into rgba at the given opacity.
 *
 * Chart.js wants a translucent fill under the trend lines, and the tokens are
 * opaque hex. Anything that is not a six-digit hex is handed back untouched so
 * a future token in another format degrades to a solid fill rather than breaking.
 *
 * @param {string} hex
 * @param {number} alpha
 * @return {string}
 */
const translucent = (hex, alpha) => {
    const match = /^#([0-9a-f]{6})$/i.exec(hex);
    if (!match) {
        return hex;
    }

    const int = parseInt(match[1], 16);

    return `rgba(${(int >> 16) & 255}, ${(int >> 8) & 255}, ${int & 255}, ${alpha})`;
};

/**
 * Shared Chart.js options: no animation on a data-dense operational panel, and
 * tooltips that follow the shell's typography rather than Chart.js defaults.
 *
 * @return {object}
 */
const baseOptions = () => ({
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    plugins: {
        legend: {display: false},
        tooltip: {
            backgroundColor: token('--mhd-color-text', '#212529'),
            padding: 8,
            displayColors: true,
            boxPadding: 4,
        },
    },
});

/**
 * Replace a panel's CSS rendering with a canvas.
 *
 * The markup stays in the DOM and in the accessibility tree; only its visual
 * presentation is dropped. The canvas is marked decorative because everything
 * it shows is already available as text beside it.
 *
 * @param {Element} host The element carrying data-mhd-chart.
 * @return {HTMLCanvasElement}
 */
const mountCanvas = (host) => {
    host.classList.add('mhd-chart-fallback');

    const wrapper = document.createElement('div');
    wrapper.className = 'mhd-chart-canvas';

    const canvas = document.createElement('canvas');
    canvas.setAttribute('aria-hidden', 'true');
    canvas.setAttribute('role', 'presentation');

    wrapper.appendChild(canvas);
    host.parentNode.insertBefore(wrapper, host);

    return canvas;
};

/**
 * Mount a canvas that sits beside the markup instead of replacing it.
 *
 * The list is moved in next to the canvas and restyled as a legend: its bar
 * becomes the colour swatch that names each slice, and its counts stay on
 * screen. One set of figures serves as both the reading and the text
 * alternative, so nothing is duplicated and nothing is lost.
 *
 * @param {Element} host The element carrying data-mhd-chart.
 * @return {HTMLCanvasElement}
 */
const mountCanvasBesideLegend = (host) => {
    const wrapper = document.createElement('div');
    wrapper.className = 'mhd-chart-with-legend';
    host.parentNode.insertBefore(wrapper, host);

    const canvasBox = document.createElement('div');
    canvasBox.className = 'mhd-chart-canvas mhd-chart-canvas--pie';

    const canvas = document.createElement('canvas');
    canvas.setAttribute('aria-hidden', 'true');
    canvas.setAttribute('role', 'presentation');

    canvasBox.appendChild(canvas);
    wrapper.appendChild(canvasBox);
    wrapper.appendChild(host);
    host.classList.add('mhd-bd-list--legend');

    return canvas;
};

/**
 * Tickets created against tickets resolved, over the selected window.
 *
 * @param {Element} host
 * @param {object} data
 */
const renderTrend = (host, data) => {
    const created = token('--mhd-color-primary', '#0d6efd');
    const resolved = token('--mhd-status-4', '#198754');
    const grid = token('--mhd-color-border', '#dee2e6');
    const text = token('--mhd-color-text-muted', '#6c757d');

    const series = (label, values, colour) => ({
        label,
        data: values,
        borderColor: colour,
        backgroundColor: translucent(colour, 0.12),
        borderWidth: 2,
        pointRadius: 0,
        pointHoverRadius: 4,
        tension: 0.35,
        fill: true,
    });

    new Chart(mountCanvas(host), {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [
                series(data.legend.created, data.created, created),
                series(data.legend.resolved, data.resolved, resolved),
            ],
        },
        options: {
            ...baseOptions(),
            interaction: {mode: 'index', intersect: false},
            scales: {
                x: {
                    grid: {display: false},
                    ticks: {color: text, maxRotation: 0, autoSkipPadding: 24},
                },
                y: {
                    beginAtZero: true,
                    border: {display: false},
                    grid: {color: grid},
                    // Ticket counts are whole numbers; a "2.5 tickets" gridline
                    // is noise on a low-volume helpdesk.
                    ticks: {color: text, precision: 0},
                },
            },
        },
    });
};

/**
 * Active tickets by status, as a ring with the total in the middle.
 *
 * @param {Element} host
 * @param {object} data
 */
const renderStatus = (host, data) => {
    const surface = token('--mhd-color-surface', '#ffffff');
    const text = token('--mhd-color-text', '#212529');
    const muted = token('--mhd-color-text-muted', '#6c757d');

    // The total belongs in the hole of the doughnut, which Chart.js has no
    // option for — it is drawn onto the canvas after the arcs each render.
    const centreTotal = {
        id: 'mhdCentreTotal',
        afterDraw(chart) {
            const {ctx, chartArea} = chart;
            if (!chartArea) {
                return;
            }
            const x = (chartArea.left + chartArea.right) / 2;
            const y = (chartArea.top + chartArea.bottom) / 2;

            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = text;
            ctx.font = `700 1.75rem ${getComputedStyle(chart.canvas).fontFamily}`;
            ctx.fillText(String(data.total), x, y - 6);
            ctx.fillStyle = muted;
            ctx.font = `400 0.75rem ${getComputedStyle(chart.canvas).fontFamily}`;
            ctx.fillText(data.centre, x, y + 16);
            ctx.restore();
        },
    };

    new Chart(mountCanvas(host), {
        type: 'doughnut',
        data: {
            labels: data.labels,
            datasets: [{
                data: data.values,
                backgroundColor: statusColours(data.keys),
                borderColor: surface,
                borderWidth: 2,
                hoverOffset: 4,
            }],
        },
        options: {
            ...baseOptions(),
            cutout: '68%',
        },
        plugins: [centreTotal],
    });
};

/**
 * The gauge's fixed scale: how good a compliance figure is, by the band it
 * falls in. Widths are percentage points and must total 100.
 *
 * Read left to right, worst to best — the opposite of a risk dial, because on
 * this metric a higher number is the good news.
 */
const SLA_BANDS = [
    {width: 50, token: '--mhd-priority-3', fallback: '#dc3545'},
    {width: 25, token: '--mhd-priority-2', fallback: '#fd7e14'},
    {width: 15, token: '--mhd-status-2', fallback: '#ffc107'},
    {width: 10, token: '--mhd-color-success', fallback: '#198754'},
];

/**
 * Mount the gauge's canvas inside the panel rather than in place of it.
 *
 * The figure and its caption move into the middle of the arc, so the markup
 * copies of them are hidden — but the target and the breach count are not
 * drawn on the canvas at all and stay exactly where they are.
 *
 * @param {Element} host The element carrying data-mhd-chart.
 * @return {HTMLCanvasElement}
 */
const mountCanvasInGauge = (host) => {
    host.classList.add('mhd-sla-gauge--charted');

    const wrapper = document.createElement('div');
    wrapper.className = 'mhd-chart-canvas mhd-chart-canvas--gauge';

    const canvas = document.createElement('canvas');
    canvas.setAttribute('aria-hidden', 'true');
    canvas.setAttribute('role', 'presentation');

    wrapper.appendChild(canvas);
    host.insertBefore(wrapper, host.firstChild);

    return canvas;
};

/**
 * SLA compliance as a semicircular gauge banded by how good the figure is,
 * with the reading in the middle and the agreed target marked on the arc.
 *
 * @param {Element} host
 * @param {object} data
 */
const renderSla = (host, data) => {
    const mark = token('--mhd-color-text', '#212529');
    const muted = token('--mhd-color-text-muted', '#6c757d');
    const surface = token('--mhd-color-surface', '#ffffff');

    const pct = Math.max(0, Math.min(100, Number(data.pct) || 0));

    /**
     * Where a value sits on the arc, as a canvas angle in radians.
     *
     * Two conventions meet here and they are not the same one. Chart.js
     * measures `rotation` from twelve o'clock, so the gauge starts at -90° and
     * sweeps half a turn to the right. Canvas angles start at three o'clock, so
     * the same starting point is half a turn back: -π, not -π/2. Using the
     * Chart.js figure here — as this did — puts every mark a quarter turn off,
     * in the hidden lower half of the circle.
     *
     * @param {number} value Percentage.
     * @return {number}
     */
    const angleFor = (value) => -Math.PI + (GAUGE_CIRCUMFERENCE * (value / 100));

    /**
     * Draw a radial line across the arc at the given value.
     *
     * @param {object} chart
     * @param {number} value
     * @param {string} colour
     * @param {number} width
     */
    const drawMark = (chart, value, colour, width) => {
        const meta = chart.getDatasetMeta(0);
        const arc = meta.data && meta.data[0];
        if (!arc) {
            return;
        }

        const {ctx} = chart;
        ctx.save();
        ctx.translate(arc.x, arc.y);
        ctx.rotate(angleFor(value));
        ctx.strokeStyle = colour;
        ctx.lineWidth = width;
        ctx.lineCap = 'round';
        ctx.beginPath();
        ctx.moveTo(arc.innerRadius - 3, 0);
        ctx.lineTo(arc.outerRadius + 3, 0);
        ctx.stroke();
        ctx.restore();
    };

    // The bands are a fixed scale, so on their own they say nothing about this
    // month's figure — the needle is what makes the chart report the data
    // rather than decorate it. The target keeps a thinner line of its own.
    const marks = {
        id: 'mhdGaugeMarks',
        afterDatasetDraw(chart) {
            if (data.target !== null && data.target !== undefined) {
                drawMark(chart, data.target, muted, 2);
            }
            drawMark(chart, pct, mark, 4);
        },
    };

    // The figure belongs in the hole of the gauge, which Chart.js has no option
    // for — it is painted on after the arcs.
    const centreValue = {
        id: 'mhdGaugeValue',
        afterDraw(chart) {
            const meta = chart.getDatasetMeta(0);
            const arc = meta.data && meta.data[0];
            if (!arc || !data.display) {
                return;
            }

            const {ctx} = chart;
            const font = getComputedStyle(chart.canvas).fontFamily;

            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'alphabetic';
            ctx.fillStyle = mark;
            ctx.font = `700 1.75rem ${font}`;
            ctx.fillText(data.display, arc.x, arc.y - 26);
            ctx.fillStyle = muted;
            ctx.font = `400 0.8125rem ${font}`;
            ctx.fillText(data.caption || '', arc.x, arc.y - 6);
            ctx.restore();
        },
    };

    new Chart(mountCanvasInGauge(host), {
        type: 'doughnut',
        data: {
            datasets: [{
                data: SLA_BANDS.map((band) => band.width),
                backgroundColor: SLA_BANDS.map((band) => token(band.token, band.fallback)),
                borderWidth: 2,
                borderColor: surface,
                circumference: GAUGE_CIRCUMFERENCE * (180 / Math.PI),
                rotation: GAUGE_ROTATION * (180 / Math.PI),
            }],
        },
        options: {
            ...baseOptions(),
            cutout: '68%',
            plugins: {
                legend: {display: false},
                // The bands are a scale, not readings; a tooltip naming their
                // widths would invite them to be read as data.
                tooltip: {enabled: false},
            },
        },
        plugins: [marks, centreValue],
    });
};

/**
 * A circle divided by share, with the panel's own list as its legend.
 *
 * A pie rather than a doughnut: the status panel already carries a ring, and
 * several of them on one page read as the same chart repeated.
 *
 * @param {Element} host
 * @param {object} data
 * @param {Function} colours Maps the payload's keys to colours.
 */
const renderPie = (host, data, colours) => {
    const surface = token('--mhd-color-surface', '#ffffff');

    new Chart(mountCanvasBesideLegend(host), {
        type: 'pie',
        data: {
            labels: data.labels,
            datasets: [{
                data: data.values,
                backgroundColor: colours(data.keys),
                borderColor: surface,
                borderWidth: 2,
                hoverOffset: 4,
            }],
        },
        options: baseOptions(),
    });
};

const RENDERERS = {
    trend: renderTrend,
    status: renderStatus,
    sla: renderSla,
    priority: (host, data) => renderPie(host, data, priorityColours),
    aging: (host, data) => renderPie(host, data, agingColours),
};

/**
 * Mount every chart present on the page.
 *
 * A panel with no data renders no host element at all, so absence here is the
 * normal empty state rather than a failure.
 */
export const init = () => {
    MOUNTS.forEach((name) => {
        const host = document.querySelector(`[data-mhd-chart="${name}"]`);
        if (!host || !host.dataset.chartData) {
            return;
        }

        let payload;
        try {
            payload = JSON.parse(host.dataset.chartData);
        } catch {
            // Malformed payload: leave the CSS rendering in place. A broken
            // chart must not take the figures down with it.
            return;
        }

        RENDERERS[name](host, payload);
    });
};
