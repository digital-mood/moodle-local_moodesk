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

/**
 * Saved filter views for the ticket list.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

/**
 * Stores and reads the named filter views an agent saves from the ticket list.
 *
 * A view is one user preference holding the display name and the list URL
 * parameters that reproduce it. Both ends go through here so the shape written
 * is the shape read back.
 *
 * The parameter names are the ticket list's own: `status` is an array, the rest
 * are scalars. The filter bar posts its multi-select under the HTML name
 * `status[]`, and that `[]` is transport — browsers spell repeated fields that
 * way — not part of the parameter name. Saving what the form spelled rather
 * than what the page reads is what silently dropped every status filter from a
 * saved view.
 */
final class saved_view_service {
    /** @var string User preference prefix. Also read by upgrade steps and tests. */
    public const PREFIX = 'local_mhd_savedview_';

    /** @var string[] The only parameters a saved view may carry. */
    public const FILTER_KEYS = [
        'search',
        'status',
        'priority',
        'assigneeid',
        'departmentid',
        'sla',
        'categoryid',
        'created_from',
        'created_to',
        'updated_from',
        'updated_to',
    ];

    /**
     * Save a named view for a user, overwriting one of the same name.
     *
     * @param int $userid Owner of the view.
     * @param string $name Display name.
     * @param array $params Raw filter parameters as posted by the filter bar.
     * @return string The slug the view is stored under.
     */
    public static function save(int $userid, string $name, array $params): string {
        $slug = self::slug_for($name);

        set_user_preference(self::PREFIX . $slug, json_encode([
            'name'   => $name,
            'params' => self::normalise_params($params),
        ]), $userid);

        return $slug;
    }

    /**
     * Delete one saved view.
     *
     * @param int $userid Owner of the view.
     * @param string $key The slug returned by save().
     * @return void
     */
    public static function delete(int $userid, string $key): void {
        unset_user_preference(self::PREFIX . $key, $userid);
    }

    /**
     * Every saved view a user holds, in preference order.
     *
     * Parameters are normalised on the way out as well as in, so a view stored
     * before this contract settled still applies the filters it was saved with.
     *
     * @param int $userid Owner of the views.
     * @return array[] Each entry: ['name' => string, 'params' => array, 'key' => string].
     */
    public static function all_for(int $userid): array {
        $views = [];

        foreach ((array) get_user_preferences(null, null, $userid) as $prefname => $prefvalue) {
            if (strpos($prefname, self::PREFIX) !== 0 || empty($prefvalue)) {
                continue;
            }

            $viewdata = json_decode($prefvalue, true);
            if (!is_array($viewdata) || empty($viewdata['name']) || empty($viewdata['params'])) {
                continue;
            }

            $views[] = [
                'name'   => (string) $viewdata['name'],
                'params' => self::normalise_params((array) $viewdata['params']),
                'key'    => substr($prefname, strlen(self::PREFIX)),
            ];
        }

        return $views;
    }

    /**
     * The parameters plugins add to the ticket list (hook ticket_list_filters).
     *
     * @return string[]
     */
    private static function plugin_filter_keys(): array {
        $hook = \local_moodesk\hook\ticket_list_filters::for_params(\local_moodesk\output\ticket_list_query::RESERVED_PARAMS);
        \core\di::get(\core\hook\manager::class)->dispatch($hook);
        return array_keys($hook->get_declared());
    }

    /**
     * Reduce posted filter parameters to the shape the ticket list reads.
     *
     * Drops the `[]` suffix a multi-value form field carries, discards anything
     * the filter bar does not produce, and settles `status` as a de-duplicated
     * list of integers whether it arrived as one value or several.
     *
     * @param array $raw Parameters as posted or as stored.
     * @return array Normalised parameters.
     */
    public static function normalise_params(array $raw): array {
        $params = [];
        $allowed = array_merge(self::FILTER_KEYS, self::plugin_filter_keys());

        foreach ($raw as $key => $value) {
            $name = (substr((string) $key, -2) === '[]') ? substr((string) $key, 0, -2) : (string) $key;
            if (!in_array($name, $allowed, true)) {
                continue;
            }
            $params[$name] = $value;
        }

        if (array_key_exists('status', $params)) {
            $statuses = [];
            foreach ((array) $params['status'] as $value) {
                // A crafted payload can nest arrays here; only scalars are statuses.
                if (is_scalar($value)) {
                    $statuses[] = (int) $value;
                }
            }
            $statuses = array_values(array_unique($statuses));

            if ($statuses === []) {
                unset($params['status']);
            } else {
                $params['status'] = $statuses;
            }
        }

        return $params;
    }

    /**
     * Derive the storage slug from a display name.
     *
     * @param string $name Display name.
     * @return string
     */
    private static function slug_for(string $name): string {
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($name)));
        $slug = trim($slug, '_');

        return ($slug === '') ? 'view_' . time() : $slug;
    }
}
