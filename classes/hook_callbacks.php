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

namespace local_moodesk;

use local_moodesk\service\navigation_service;

/**
 * Hook listener callbacks.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Inject the mooDesk node into Moodle's primary navigation (header bar).
     *
     * Called via the \core\hook\navigation\primary_extend hook, which fires
     * after the built-in nodes (Home, Dashboard, My courses, Site admin) are
     * added but before the navigation is rendered.
     *
     * The navigation definition lives in {@see navigation_service}, the single
     * source of truth shared with the application-shell sidebar. This callback
     * is only the adapter that materialises the single "mooDesk" crossing point
     * as a Moodle {@see \navigation_node}.
     *
     * The product's full navigation lives in the shell sidebar, so Moodle's
     * header keeps exactly one entry point ("Moodle invisible").
     *
     * @param \core\hook\navigation\primary_extend $hook
     */
    public static function extend_primary_navigation(
        \core\hook\navigation\primary_extend $hook
    ): void {
        if (!navigation_service::is_available()) {
            return;
        }

        $primaryview = $hook->get_primaryview();

        // Single entry point: clicking the "mooDesk" label navigates to the
        // ticket list. Node key kept as 'moodesk'.
        $entry = navigation_service::get_primary_nav_node();
        $primaryview->add(
            $entry->label,
            $entry->url,
            \navigation_node::TYPE_CUSTOM,
            null,
            'moodesk'
        );
    }
}
