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
 * Lets a plugin set the Reply-To and the Message-ID of a ticket notification.
 *
 * Dispatched by {@see \local_moodesk\observer} for every ticket notification
 * about a specific ticket, just before it is sent. The base sets neither; a
 * callback may set them. Moodle generates a Message-ID when none is set.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to set the Reply-To address and the Message-ID of a mooDesk ticket notification.')]
#[\core\attribute\tags('local_moodesk')]
final class notification_decorate {
    /** @var string|null The Reply-To address, or null for none. */
    private ?string $replyto;

    /** @var string The display name of the Reply-To address. */
    private string $replytoname;

    /** @var string|null The Message-ID, angle brackets included, or null for Moodle's own. */
    private ?string $messageid = null;

    /**
     * Create the hook with the base's Reply-To.
     *
     * @param int         $ticketid    The ticket the notification is about.
     * @param int         $userid      The recipient.
     * @param string      $subject     The notification subject.
     * @param string|null $replyto     The base's Reply-To, or null.
     * @param string      $replytoname Its display name.
     */
    public function __construct(
        /** @var int */
        public readonly int $ticketid,
        /** @var int */
        public readonly int $userid,
        /** @var string */
        public readonly string $subject,
        ?string $replyto,
        string $replytoname,
    ) {
        $this->replyto = $replyto;
        $this->replytoname = $replytoname;
    }

    /**
     * The Reply-To address as it stands now.
     *
     * @return string|null
     */
    public function get_reply_to(): ?string {
        return $this->replyto;
    }

    /**
     * Its display name.
     *
     * @return string
     */
    public function get_reply_to_name(): string {
        return $this->replytoname;
    }

    /**
     * Set the Reply-To address, or clear it with null.
     *
     * @param string|null $address A valid email address, or null.
     * @param string      $name    Its display name.
     * @throws \coding_exception On an invalid address.
     */
    public function set_reply_to(?string $address, string $name = ''): void {
        if ($address !== null && !validate_email($address)) {
            throw new \coding_exception('Invalid Reply-To address');
        }
        $this->replyto = $address;
        $this->replytoname = $address === null ? '' : $name;
    }

    /**
     * The Message-ID set by a callback, or null.
     *
     * @return string|null
     */
    public function get_message_id(): ?string {
        return $this->messageid;
    }

    /**
     * Set the Message-ID of the notification, or clear it with null.
     *
     * It must be unique to this one message: two notifications sharing an id are
     * one message to a mail client, and it may drop the second.
     *
     * @param string|null $messageid `<local@domain>`, or null.
     * @throws \coding_exception On anything but one bracketed id.
     */
    public function set_message_id(?string $messageid): void {
        if ($messageid !== null && !preg_match('/^<[^<>@\s]+@[^<>@\s]+>$/', $messageid)) {
            throw new \coding_exception('Invalid Message-ID');
        }
        $this->messageid = $messageid;
    }
}
