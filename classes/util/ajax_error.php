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
