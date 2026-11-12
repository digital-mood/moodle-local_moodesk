<?php
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_moodesk\service;

/**
 * Immutable value object describing a single mooDesk navigation entry.
 *
 * Produced by {@see navigation_service} and consumed by two renderers:
 *   - the Moodle primary-navigation hook ({@see \local_moodesk\hook_callbacks}),
 *     which is the only crossing point with Moodle's chrome;
 *   - the standalone application shell sidebar (introduced in phase F2).
 *
 * Items returned by the service are already filtered for the current user
 * (capability + edition gating happens in the service), so a renderer can
 * walk the tree unconditionally.
 *
 * The {@see $icon} is a mooDesk-semantic token (e.g. 'dashboard', 'tickets'),
 * not a Moodle pix key: the shell renders its own icon set so the product UI
 * stays visually independent of the active theme. The Moodle hook ignores it.
 *
 * The {@see $group} places the node in one of the shell sidebar's sections
 * (operation / administration / system); it is metadata for the shell
 * projection only and is ignored by the single-node Moodle crossing point.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class nav_item {
    /** Section group: end-user / agent operation screens. */
    public const GROUP_OPERATION = 'operation';

    /** Section group: administration / configuration screens. */
    public const GROUP_ADMINISTRATION = 'administration';

    /** Section group: system / cross-cutting screens (reports, license, settings). */
    public const GROUP_SYSTEM = 'system';

    /**
     * Build a navigation item.
     *
     * @param string        $key      Stable identifier, also used as the page key
     *                                 for active-state resolution (e.g. 'dashboard').
     * @param string        $label    Already-localised display label.
     * @param \moodle_url   $url       Target URL.
     * @param string        $icon      mooDesk-semantic icon token (shell-rendered).
     * @param nav_item[]    $children  Nested items (empty for leaf nodes).
     * @param bool          $external  True when the target leaves the mooDesk shell
     *                                 and lands on a Moodle screen (e.g. Settings,
     *                                 role management, "Return to Moodle").
     * @param string        $group     Shell sidebar section the node belongs to
     *                                 (one of the GROUP_* constants). Empty for the
     *                                 entry point and other ungrouped nodes.
     */
    public function __construct(
        /** @var string */
        public readonly string $key,
        /** @var string */
        public readonly string $label,
        /** @var \moodle_url */
        public readonly \moodle_url $url,
        /** @var string */
        public readonly string $icon,
        /** @var nav_item[] */
        public readonly array $children = [],
        /** @var bool */
        public readonly bool $external = false,
        /** @var string */
        public readonly string $group = '',
    ) {
    }
}
