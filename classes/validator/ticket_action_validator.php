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
 * Unified validator for ticket mutation actions.
 *
 * Used by ajax.php and ticket_page_handler to centralise permission
 * and parameter validation for all ticket actions (status, priority,
 * assign, department, category, team).
 *
 * All methods throw on failure so callers can use a single try/catch
 * without branching on return values.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\validator;

use local_moodesk\domain\ticket;

/**
 * Validates permissions and parameter values for ticket mutation actions.
 */
class ticket_action_validator {
    /**
     * Throw if the current user lacks the manage capability.
     *
     * @throws \required_capability_exception
     */
    public static function require_manage_capability(): void {
        $context = \context_system::instance();
        if (!has_capability('local/moodesk:manage', $context)) {
            throw new \required_capability_exception(
                $context,
                'local/moodesk:manage',
                'nopermissions',
                'local_moodesk'
            );
        }
    }

    /**
     * Throw if the given status is not a recognised ticket status constant.
     *
     * @param int $status
     * @throws \invalid_parameter_exception
     */
    public static function require_valid_status(int $status): void {
        if (!in_array($status, ticket::all_statuses(), true)) {
            throw new \invalid_parameter_exception('Invalid status value: ' . $status);
        }
    }

    /**
     * Throw if the given priority is not a recognised ticket priority constant.
     *
     * @param int $priority
     * @throws \invalid_parameter_exception
     */
    public static function require_valid_priority(int $priority): void {
        if (!in_array($priority, ticket::all_priorities(), true)) {
            throw new \invalid_parameter_exception('Invalid priority value: ' . $priority);
        }
    }

    /**
     * Throw if the given integer is not a positive (> 0) value.
     *
     * Use this to guard required foreign-key params such as categoryid.
     *
     * @param int    $value
     * @param string $field  Human-readable field name for the error message.
     * @throws \invalid_parameter_exception
     */
    public static function require_positive_int(int $value, string $field): void {
        if ($value <= 0) {
            throw new \invalid_parameter_exception('Invalid value for ' . $field . ': must be a positive integer');
        }
    }

    /**
     * Throw if the given value is not a string.
     *
     * Guards text fields that a caller could hand something other than text.
     * A browser cannot: a textarea submits a string. A hand-built POST can, and
     * neither of Moodle's two entry points stops it — mform cleans an array
     * value with clean_param_array() and passes it through
     * (MoodleQuickForm::getCleanedValue), and required_param() only debugs
     * before deferring to required_param_array(). Casting such a value to
     * string is how the literal "Array" ends up stored.
     *
     * Deliberately strict: no numbers, no objects with __toString(). A text
     * field that did not receive text is a malformed request, not a value to
     * be salvaged.
     *
     * @param mixed  $value
     * @param string $field Field name for the error message.
     * @throws \invalid_parameter_exception
     */
    public static function require_string(mixed $value, string $field): void {
        if (!is_string($value)) {
            throw new \invalid_parameter_exception(
                'Invalid value for ' . $field . ': must be a string, ' . gettype($value) . ' given'
            );
        }
    }
}
