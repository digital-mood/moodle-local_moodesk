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
 * User field helpers.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\util;

/**
 * Centralizes the canonical list of user fields that Moodle's fullname()
 * requires. Any query whose result is passed to fullname() must select these
 * fields — otherwise developer mode raises:
 *   "The following name fields are missing from the user object: ..."
 *
 * The exact list comes from \core_user\fields::get_name_fields() so the plugin
 * tracks Moodle's canonical definition (currently firstname, lastname,
 * firstnamephonetic, lastnamephonetic, middlename, alternatename) instead of
 * hardcoding a parallel list that can drift.
 */
final class user_fields {
    /**
     * Return the comma-separated list of user fields required by fullname(),
     * always including the user `id` so that the caller can also use the
     * returned record for lookups.
     *
     * Usage examples:
     *   $DB->get_record('user', $cond, user_fields::for_fullname());
     *   $DB->get_records_list('user', 'id', $ids, '', user_fields::for_fullname());
     *   get_users_by_capability($ctx, $cap, user_fields::for_fullname('u') . ',u.email', ...);
     *   "SELECT " . user_fields::for_fullname('u') . " FROM {user} u ...";
     *
     * @param string $alias Optional SQL alias prefix; '' yields bare column names.
     * @return string
     */
    public static function for_fullname(string $alias = ''): string {
        return self::prefix(array_merge(['id'], \core_user\fields::get_name_fields()), $alias);
    }

    /**
     * Return the field list required to render a user's avatar as well as name
     * them: everything fullname() needs, plus what user_picture reads.
     *
     * A screen that draws a face needs `picture` and `imagealt` on top of the
     * name fields, and three places in the plugin had each spelled that list out
     * by hand — which is how one of them ends up a field short the next time
     * core adds one. The list comes from \core_user\fields::get_picture_fields()
     * for the same reason for_fullname() takes its own from core: so the plugin
     * tracks Moodle's definition instead of a parallel copy that can drift.
     *
     * Usage:
     *   get_users_by_capability($ctx, $cap, user_fields::for_userpic('u'), ...);
     *   $pic = new user_picture($user); $pic->size = 36;
     *
     * @param string $alias Optional SQL alias prefix; '' yields bare column names.
     * @return string
     */
    public static function for_userpic(string $alias = ''): string {
        $fields = array_unique(array_merge(
            ['id'],
            \core_user\fields::get_name_fields(),
            \core_user\fields::get_picture_fields()
        ));

        return self::prefix($fields, $alias);
    }

    /**
     * Join a field list into a select clause, optionally aliased.
     *
     * @param string[] $fields
     * @param string $alias
     * @return string
     */
    private static function prefix(array $fields, string $alias): string {
        if ($alias === '') {
            return implode(',', $fields);
        }

        $prefix = $alias . '.';
        return $prefix . implode(',' . $prefix, $fields);
    }
}
