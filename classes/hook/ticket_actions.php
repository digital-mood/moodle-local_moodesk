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

namespace local_moodesk\hook;

/**
 * Lets a plugin handle a ticket action mooDesk does not know.
 *
 * Dispatched by {@see \local_moodesk\controller\ticket_page_handler::run_plugin_action()}
 * for an action name none of mooDesk's own actions answers, from both entry
 * points: view.php (a POST with a valid sesskey) and ajax.php (sesskey checked).
 * Reach to the ticket has been checked by then; anything else the action needs
 * (its own capability, edition, parameters) is the callback's to check.
 *
 * The callback that owns the action runs it and calls respond(). view.php
 * redirects back to the ticket with the message; ajax.php answers it as JSON.
 * A callback throws a moodle_exception to refuse, and its message is shown. With
 * nobody responding, both entry points behave as they always have for an
 * unknown action.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to handle their own actions on a mooDesk ticket.')]
#[\core\attribute\tags('local_moodesk')]
final class ticket_actions {
    /** @var bool Whether a callback handled the action. */
    private bool $handled = false;

    /** @var string The localised message to report. */
    private string $message = '';

    /** @var array Extra values for the JSON answer. */
    private array $data = [];

    /**
     * Create the hook for one request.
     *
     * @param string $action     The action name (PARAM_ALPHANUMEXT).
     * @param int    $ticketid   The ticket.
     * @param int    $userid     The user acting.
     * @param bool   $canaddnote Whether they may add internal notes on it.
     * @param bool   $canmanage  Whether they may manage it.
     */
    public function __construct(
        /** @var string */
        public readonly string $action,
        /** @var int */
        public readonly int $ticketid,
        /** @var int */
        public readonly int $userid,
        /** @var bool */
        public readonly bool $canaddnote,
        /** @var bool */
        public readonly bool $canmanage,
    ) {
    }

    /**
     * Report that the action ran.
     *
     * @param string $message The localised confirmation.
     * @param array  $data    Extra values for the JSON answer; `success`, `action` and `message` are reserved.
     * @throws \coding_exception When another callback already handled the action, or on a reserved key.
     */
    public function respond(string $message, array $data = []): void {
        if ($this->handled) {
            throw new \coding_exception("The ticket action {$this->action} was already handled");
        }
        foreach (['success', 'action', 'message'] as $reserved) {
            if (array_key_exists($reserved, $data)) {
                throw new \coding_exception("Reserved key in a ticket action answer: {$reserved}");
            }
        }
        $this->handled = true;
        $this->message = $message;
        $this->data = $data;
    }

    /**
     * Whether a callback handled the action.
     *
     * @return bool
     */
    public function is_handled(): bool {
        return $this->handled;
    }

    /**
     * The message reported.
     *
     * @return string
     */
    public function get_message(): string {
        return $this->message;
    }

    /**
     * The extra values reported.
     *
     * @return array
     */
    public function get_data(): array {
        return $this->data;
    }
}
