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
 * What an AJAX handler tells the browser when it fails.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\util;

use local_moodesk\domain\incident;
use local_moodesk\service\incident_service;

/**
 * The failure payload of ajax.php, with the detail kept on the server.
 *
 * A \moodle_exception carries a localised message written to be shown — a
 * ticket out of reach, a merge rule, a missing parameter — and it is shown.
 * Anything else (a database error, a type error, an exception from a library)
 * carries whatever the code that threw it put in the message: a file path, a
 * fragment of SQL, a hostname. That does not belong in a browser (audit H7).
 * The browser gets one generic sentence; the detail goes to System Health as
 * an incident, where the operator who can act on it will look.
 */
class ajax_error {
    /** The request was not a POST. */
    public const REFUSED_POST_REQUIRED = 'post_required';

    /** The sesskey did not match: in practice, an expired session. */
    public const REFUSED_SESSKEY = 'sesskey';

    /** The user may not run this action. */
    public const REFUSED_PERMISSION = 'permission';

    /** A saved view arrived with parameters that are not a JSON object. */
    public const REFUSED_INVALID_JSON = 'invalid_json';

    /**
     * Nobody handles the action, or the ticket is out of the user's reach. One
     * answer for both, on purpose: it says nothing about whether the ticket exists.
     */
    public const REFUSED_UNKNOWN_ACTION = 'unknown_action';

    /** Each reason and the string it is shown as: [identifier, component]. */
    private const REFUSAL_STRINGS = [
        self::REFUSED_POST_REQUIRED => ['ajax_post_required', 'local_moodesk'],
        // Core's own sentence: the one the user sees everywhere else on the site.
        self::REFUSED_SESSKEY => ['invalidsesskey', 'error'],
        self::REFUSED_PERMISSION => ['ajax_permission_denied', 'local_moodesk'],
        self::REFUSED_INVALID_JSON => ['ajax_invalid_json', 'local_moodesk'],
        self::REFUSED_UNKNOWN_ACTION => ['ajax_unknown_action', 'local_moodesk'],
    ];

    /**
     * The JSON payload for a request ajax.php turns away before running it.
     *
     * Every refusal is a language string, so ajax.php holds no text of its own.
     *
     * @param string $reason One of the REFUSED_* constants.
     * @return array{success: false, error: string}
     * @throws \coding_exception For a reason that is not one of the constants.
     */
    public static function refused(string $reason): array {
        if (!isset(self::REFUSAL_STRINGS[$reason])) {
            throw new \coding_exception('Unknown ajax refusal: ' . $reason);
        }
        [$identifier, $component] = self::REFUSAL_STRINGS[$reason];
        return ['success' => false, 'error' => get_string($identifier, $component)];
    }

    /**
     * The JSON payload for a failed action.
     *
     * @param \Throwable $e      What the handler caught.
     * @param string     $action The action being handled, for the incident code.
     * @return array{success: false, error: string}
     */
    public static function payload(\Throwable $e, string $action): array {
        if ($e instanceof \moodle_exception) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        try {
            incident_service::create()->record(
                incident::SOURCE_UI,
                'ajax.' . preg_replace('/[^a-z0-9_]/', '_', strtolower($action)) . '.unexpected',
                incident::SEVERITY_ERROR,
                get_class($e) . ': ' . $e->getMessage(),
                ['file' => $e->getFile(), 'line' => $e->getLine()]
            );
        } catch (\Throwable $recording) {
            // Recording the failure must never replace the answer to the
            // browser. The developer channel gets both so nothing is lost.
            debugging(
                'local_moodesk ajax ' . $action . ': ' . get_class($e) . ': ' . $e->getMessage()
                . ' (incident not recorded: ' . $recording->getMessage() . ')',
                DEBUG_DEVELOPER
            );
        }

        return ['success' => false, 'error' => get_string('ajax_unexpected_error', 'local_moodesk')];
    }
}
