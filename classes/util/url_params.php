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
 * URL parameter helpers for multivalue (repeated) filters.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\util;

use moodle_url;

/**
 * Builds page URLs whose parameters may carry more than one value.
 *
 * The ticket list reads its status filter with optional_param_array(), so a
 * selection travels as repeated status[] parameters. moodle_url rejects array
 * values outright on Moodle 4.5 ("Url parameters values can not be arrays!"),
 * which broke every list URL built while a status filter was active: the
 * filter chips, the SLA sort link and the pagination bar.
 *
 * The fix keeps the repeated-parameter contract instead of collapsing it to a
 * scalar: an array value is expanded into the indexed keys status[0],
 * status[1]… that PHP parses back into an array, and that
 * optional_param_array() reads unchanged. That is the exact encoding Moodle
 * 5.x emits for array parameters natively, so the query string is identical
 * across every supported release.
 */
final class url_params {
    /**
     * Build a page URL from parameters that may hold multivalue filters.
     *
     * @param string $path Page path, e.g. /local/moodesk/index.php.
     * @param array $params Parameters, values may be arrays.
     * @return moodle_url
     */
    public static function to_url(string $path, array $params): moodle_url {
        return new moodle_url($path, self::flatten($params));
    }

    /**
     * Expand array values into the indexed keys moodle_url accepts.
     *
     * ['status' => [1, 2]] becomes ['status[0]' => 1, 'status[1]' => 2].
     * Values are re-indexed from zero, so a filtered array never leaves gaps.
     *
     * @param array $params Parameters, values may be arrays.
     * @return array Parameters with scalar values only.
     */
    public static function flatten(array $params): array {
        $flat = [];
        foreach ($params as $key => $value) {
            if (!is_array($value)) {
                $flat[$key] = $value;
                continue;
            }
            // Nested arrays keep recursing, matching core's own encoding.
            foreach (array_values($value) as $index => $item) {
                $flat = array_merge($flat, self::flatten([$key . '[' . $index . ']' => $item]));
            }
        }

        return $flat;
    }

    /**
     * Drop a single value from a multivalue parameter.
     *
     * Used by the filter chips: dismissing one status keeps the rest of the
     * selection and every other filter. When the last value goes, the whole
     * parameter is removed so the URL carries no empty status at all.
     *
     * @param array $params Parameters, values may be arrays.
     * @param string $key Parameter to trim.
     * @param mixed $value Value to remove (strict comparison).
     * @return array
     */
    public static function without_value(array $params, string $key, $value): array {
        if (!array_key_exists($key, $params)) {
            return $params;
        }

        if (!is_array($params[$key])) {
            unset($params[$key]);
            return $params;
        }

        $remaining = array_values(array_filter(
            $params[$key],
            static fn($item) => $item !== $value
        ));

        if ($remaining === []) {
            unset($params[$key]);
        } else {
            $params[$key] = $remaining;
        }

        return $params;
    }
}
