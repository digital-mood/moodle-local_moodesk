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
 * Event observers: send Moodle messages in response to ticket events.
 *
 * All notifications are sent via the Moodle message API (message_send()).
 * The 'local_moodesk' message provider must be declared in
 * db/messages.php for the messages to be routable through user preferences.
 *
 * Each handler is gated behind the corresponding plugin config flag so that
 * admins can disable individual notification types without touching code.
 *
 * IMPORTANT: Observers must never throw. All DB access is wrapped in
 * try/catch blocks so that a notification failure never rolls back the
 * originating transaction.
 *
 * Absorbing a failure is not the same as hiding it. Every catch below records
 * an operational incident before it reports through debugging(), so a
 * production site — which runs with debugging off — still has somewhere to see
 * that its notifications stopped working. Recording cannot itself throw, so the guarantee above is unchanged.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk;

use local_moodesk\domain\incident;
use local_moodesk\domain\reply;
use local_moodesk\domain\ticket;
use local_moodesk\service\edition_service;
use local_moodesk\service\incident_service;
use local_moodesk\util\user_fields;

/**
 * Notification observer.
 */
class observer {
    /*
     * -------------------------------------------------------------------------
     * ticket_created → notify agents of new unassigned ticket
     * -------------------------------------------------------------------------
     */

    /**
     * Handle ticket_created event.
     *
     * Sends a message to all agents (users with :viewall) when:
     *  - the notify_agents_new setting is enabled, AND
     *  - the ticket is unassigned at creation time.
     *
     * @param \local_moodesk\event\ticket_created $event
     */
    public static function ticket_created(\local_moodesk\event\ticket_created $event): void {
        if (!get_config('local_moodesk', 'notify_agents_new')) {
            return;
        }

        try {
            global $DB;

            $ticketid = $event->objectid;
            $ticket   = $DB->get_record('local_moodesk_ticket', ['id' => $ticketid], 'id,userid,subject,assigneeid');
            if (!$ticket || !empty($ticket->assigneeid)) {
                return; // Assigned at creation — no broadcast needed.
            }

            $requester = $DB->get_record('user', ['id' => $ticket->userid], user_fields::for_fullname());
            if (!$requester) {
                return;
            }

            $agents = get_users_by_capability(
                \context_system::instance(),
                'local/moodesk:viewall',
                user_fields::for_fullname('u') . ',u.email',
                '',
                '',
                '',
                '',
                '',
                false
            );

            $viewurl = new \moodle_url('/local/moodesk/view.php', ['id' => $ticketid]);

            foreach ($agents as $agent) {
                if ((int) $agent->id === (int) $ticket->userid) {
                    continue; // Don't notify an agent who is also the requester.
                }
                self::send_message(
                    \core_user::get_noreply_user(),
                    $agent,
                    get_string('notify_agents_new_subject', 'local_moodesk', [
                        'id'      => $ticketid,
                        'subject' => $ticket->subject,
                    ]),
                    get_string('notify_agents_new_body', 'local_moodesk', [
                        'requester' => fullname($requester),
                        'subject'   => $ticket->subject,
                        'url'       => $viewurl->out(false),
                    ]),
                    $ticketid
                );
            }
        } catch (\Throwable $e) {
            // Never propagate — see class docblock.
            self::record_incident('ticket_created', $e, $event);
            debugging('local_moodesk observer::ticket_created failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /*
     * -------------------------------------------------------------------------
     * ticket_replied → notify requester of agent reply
     * -------------------------------------------------------------------------
     */

    /**
     * Handle ticket_replied event.
     *
     * Sends a message to the ticket requester when:
     *  - the notify_requester_reply setting is enabled, AND
     *  - the reply type is TYPE_PUBLIC_REPLY, AND
     *  - the author is not the requester themselves.
     *
     * @param \local_moodesk\event\ticket_replied $event
     */
    public static function ticket_replied(\local_moodesk\event\ticket_replied $event): void {
        if (!get_config('local_moodesk', 'notify_requester_reply')) {
            return;
        }

        if ((int) ($event->other['type'] ?? -1) !== reply::TYPE_PUBLIC_REPLY) {
            return; // Internal notes never trigger requester notification.
        }

        try {
            global $DB;

            $ticketid = $event->objectid;
            $ticket   = $DB->get_record('local_moodesk_ticket', ['id' => $ticketid], 'id,userid,subject');
            if (!$ticket) {
                return;
            }

            // Don't notify if the reply was posted by the requester themselves.
            if ((int) $event->userid === (int) $ticket->userid) {
                return;
            }

            $requester = $DB->get_record('user', ['id' => $ticket->userid]);
            if (!$requester) {
                return;
            }

            $agent = $DB->get_record('user', ['id' => $event->userid], user_fields::for_fullname());

            $viewurl = new \moodle_url('/local/moodesk/view.php', ['id' => $ticketid]);

            self::send_message(
                \core_user::get_noreply_user(),
                $requester,
                get_string('notify_requester_reply_subject', 'local_moodesk', [
                    'id'      => $ticketid,
                    'subject' => $ticket->subject,
                ]),
                get_string('notify_requester_reply_body', 'local_moodesk', [
                    'agent'   => $agent ? fullname($agent) : get_string('deleteduser', 'local_moodesk'),
                    'subject' => $ticket->subject,
                    'url'     => $viewurl->out(false),
                ]),
                $ticketid
            );
        } catch (\Throwable $e) {
            self::record_incident('ticket_replied', $e, $event);
            debugging('local_moodesk observer::ticket_replied failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /*
     * -------------------------------------------------------------------------
     * ticket_assigned → notify the new assignee
     * -------------------------------------------------------------------------
     */

    /**
     * Handle ticket_assigned event.
     *
     * Sends a message to the newly assigned agent when:
     *  - the notify_assignee_assign setting is enabled, AND
     *  - there is a new assignee (not an unassignment).
     *
     * @param \local_moodesk\event\ticket_assigned $event
     */
    public static function ticket_assigned(\local_moodesk\event\ticket_assigned $event): void {
        if (!get_config('local_moodesk', 'notify_assignee_assign')) {
            return;
        }

        $newassigneeid = $event->other['newassigneeid'] ?? null;
        if (empty($newassigneeid)) {
            return; // Unassignment — no notification.
        }

        try {
            global $DB;

            $ticketid = $event->objectid;
            $ticket   = $DB->get_record('local_moodesk_ticket', ['id' => $ticketid], 'id,subject');
            if (!$ticket) {
                return;
            }

            $assignee = $DB->get_record('user', ['id' => $newassigneeid]);
            if (!$assignee) {
                return;
            }

            $viewurl = new \moodle_url('/local/moodesk/view.php', ['id' => $ticketid]);

            self::send_message(
                \core_user::get_noreply_user(),
                $assignee,
                get_string('notify_assignee_assign_subject', 'local_moodesk', [
                    'id'      => $ticketid,
                    'subject' => $ticket->subject,
                ]),
                get_string('notify_assignee_assign_body', 'local_moodesk', [
                    'subject' => $ticket->subject,
                    'url'     => $viewurl->out(false),
                ]),
                $ticketid
            );
        } catch (\Throwable $e) {
            self::record_incident('ticket_assigned', $e, $event);
            debugging('local_moodesk observer::ticket_assigned failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /*
     * -------------------------------------------------------------------------
     * ticket_sla_breached → notify assignee (or all agents if unassigned)
     * -------------------------------------------------------------------------
     */

    /**
     * Handle ticket_sla_breached event.
     *
     * Sends a message when:
     *  - the notify_sla_breach setting is enabled.
     *
     * Recipients:
     *  - Assigned agent, if the ticket has one.
     *  - All agents (users with :viewall), if the ticket is unassigned.
     *
     * @param \local_moodesk\event\ticket_sla_breached $event
     */
    public static function ticket_sla_breached(\local_moodesk\event\ticket_sla_breached $event): void {
        if (!get_config('local_moodesk', 'notify_sla_breach')) {
            return;
        }

        try {
            global $DB;

            $ticketid   = $event->objectid;
            $assigneeid = (int) ($event->other['assigneeid'] ?? 0);

            $ticket = $DB->get_record('local_moodesk_ticket', ['id' => $ticketid], 'id,subject,priority');
            if (!$ticket) {
                return;
            }

            $viewurl = new \moodle_url('/local/moodesk/view.php', ['id' => $ticketid]);

            $subject = get_string('notify_sla_breach_subject', 'local_moodesk', [
                'id'      => $ticketid,
                'subject' => $ticket->subject,
            ]);

            if ($assigneeid > 0) {
                $assignee = $DB->get_record('user', ['id' => $assigneeid]);
                if (!$assignee) {
                    return;
                }
                self::send_message(
                    \core_user::get_noreply_user(),
                    $assignee,
                    $subject,
                    get_string('notify_sla_breach_body_assigned', 'local_moodesk', [
                        'subject' => $ticket->subject,
                        'url'     => $viewurl->out(false),
                    ]),
                    $ticketid
                );
                return;
            }

            // Unassigned — broadcast to all agents.
            $agents = get_users_by_capability(
                \context_system::instance(),
                'local/moodesk:viewall',
                user_fields::for_fullname('u') . ',u.email',
                '',
                '',
                '',
                '',
                '',
                false
            );

            $body = get_string('notify_sla_breach_body_unassigned', 'local_moodesk', [
                'subject' => $ticket->subject,
                'url'     => $viewurl->out(false),
            ]);

            foreach ($agents as $agent) {
                self::send_message(\core_user::get_noreply_user(), $agent, $subject, $body, $ticketid);
            }
        } catch (\Throwable $e) {
            self::record_incident('ticket_sla_breached', $e, $event);
            debugging('local_moodesk observer::ticket_sla_breached failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /*
     * -------------------------------------------------------------------------
     * Phase 11.D: CSAT invitation hook
     * -------------------------------------------------------------------------
     */

    /*
     * -------------------------------------------------------------------------
     * Ticket merge → notify both requesters and the target's assignee
     * -------------------------------------------------------------------------
     */

    /*
     * -------------------------------------------------------------------------
     * Private helper
     * -------------------------------------------------------------------------
     */

    /**
     * Send a Moodle message notification.
     *
     * The recipient is re-fetched via \core_user::get_user($id, '*') because
     * message_send() routes the message based on a wide set of user columns
     * (mailformat, maildisplay, lang, timezone, auth, emailstop, mnethostid,
     * deleted, suspended, ...). Most callers in this class only SELECT the
     * fields they need for body composition, so handing the partial record
     * straight to message_send() makes Moodle log "Necessary properties missing
     * in userto object, fetching full record" in DEBUG_DEVELOPER. Doing the
     * re-fetch up front avoids the warning and keeps the surrounding queries
     * minimal.
     *
     * @param \stdClass $from   Sender user object (noreply).
     * @param \stdClass $to     Recipient user object — only ->id is required;
     *                          remaining fields are re-loaded from the database.
     * @param string    $subject
     * @param string    $body     Plain-text body.
     * @param int|null  $ticketid Ticket this notification is about. When given,
     *                            and the site is configured for it, the message
     *                            carries a tokenised Reply-To so the recipient
     *                            can answer by email and be recognised.
     * @param \moodle_url|null $contexturl Where the notification points (the
     *                            popup's link); none when null.
     * @param string    $contexturlname Label for that link.
     */
    public static function send_message(
        \stdClass $from,
        \stdClass $to,
        string $subject,
        string $body,
        ?int $ticketid = null,
        ?\moodle_url $contexturl = null,
        string $contexturlname = ''
    ): void {
        if (empty($to->id)) {
            return;
        }

        $userto = \core_user::get_user((int) $to->id, '*', IGNORE_MISSING);
        if (!$userto || !empty($userto->deleted) || !empty($userto->suspended)) {
            return;
        }

        $message                     = new \core\message\message();
        $message->component          = 'local_moodesk';
        $message->name               = 'ticket_notification';
        $message->userfrom           = $from;
        $message->userto             = $userto;
        $message->subject            = $subject;
        $message->fullmessage        = $body;
        $message->fullmessageformat  = FORMAT_PLAIN;
        $message->fullmessagehtml    = text_to_html($body, false, false, true);
        $message->smallmessage       = $subject;
        $message->notification       = 1;
        $message->contexturl         = $contexturl ? $contexturl->out(false) : '';
        $message->contexturlname     = $contexturl ? $contexturlname : '';

        // Plugins may give the notification a Reply-To (hook notification_decorate).
        if ($ticketid !== null) {
            $hook = new \local_moodesk\hook\notification_decorate(
                $ticketid,
                (int) $userto->id,
                $subject,
                null,
                ''
            );
            \core\di::get(\core\hook\manager::class)->dispatch($hook);

            if ($hook->get_reply_to() !== null) {
                $message->replyto     = $hook->get_reply_to();
                $message->replytoname = $hook->get_reply_to_name();
            }

            // A Message-ID goes through the sender's custom headers: it is how
            // email_to_user() takes one (moodle_phpmailer::addCustomHeader maps a
            // `Message-ID:` header onto the mailer's own), the same route
            // mod_forum uses. A copy, so the shared sender object is untouched.
            if ($hook->get_message_id() !== null && is_object($from)) {
                $from = clone $from;
                $headers = $from->customheaders ?? [];
                $headers = is_array($headers) ? $headers : [$headers];
                $headers[] = 'Message-ID: ' . $hook->get_message_id();
                $from->customheaders = $headers;
                $message->userfrom = $from;
            }
        }

        message_send($message);
    }

    /**
     * Record that an observer absorbed a failure.
     *
     * Every catch in this class funnels through here so the incident code is
     * derived from the method name in one place: fifteen hand-written code
     * strings would be fifteen chances to mistype one, and a mistyped code
     * silently mints a second incident for a failure that already has one.
     *
     * Severity is always `error`: an observer that failed means a notification,
     * an invite, a rule or a webhook did not happen, and nothing retries it.
     *
     * @param string      $method The observer method that failed.
     * @param \Throwable  $e      The absorbed failure.
     * @param object|null $event  Originating event, for its objectid.
     */
    private static function record_incident(string $method, \Throwable $e, ?object $event = null): void {
        $context = ['exception_class' => get_class($e)];
        if ($event !== null && !empty($event->objectid)) {
            $context['ticketid'] = (int) $event->objectid;
        }

        incident_service::create()->record(
            incident::SOURCE_OBSERVER,
            'observer.' . $method . '.failed',
            incident::SEVERITY_ERROR,
            $e->getMessage(),
            $context
        );
    }
}
