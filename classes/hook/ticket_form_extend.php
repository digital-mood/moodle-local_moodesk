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
 * Lets a plugin extend the New ticket form: add fields, validate them, save them.
 *
 * One hook, dispatched three times in the life of a submission, each with its
 * own phase:
 *
 *  - definition: from {@see \local_moodesk\form\submit_ticket_form::definition()},
 *    before the action buttons. get_mform() gives the form to add elements to.
 *    Both mounts of the form (the page and the New ticket drawer) build it there.
 *  - validation: from submit_ticket_form::validation(), after the base checks
 *    passed. get_data() and get_files() give the submission; add_error()
 *    refuses it.
 *  - save: from submit.php, once the ticket exists. get_ticketid() and
 *    get_data() give what to store.
 *
 * A callback checks get_phase() and ignores the phases it has nothing for.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to add, validate and save fields of the mooDesk New ticket form.')]
#[\core\attribute\tags('local_moodesk')]
final class ticket_form_extend {
    /** Adding elements to the form. */
    const PHASE_DEFINITION = 'definition';

    /** Validating a submission. */
    const PHASE_VALIDATION = 'validation';

    /** Saving after the ticket was created. */
    const PHASE_SAVE = 'save';

    /** @var array<string, string> Errors keyed by element name. */
    private array $errors = [];

    /**
     * Use the named constructors.
     *
     * @param string                $phase
     * @param int                   $userid   The user submitting the form.
     * @param \MoodleQuickForm|null $mform    The form, in the definition phase.
     * @param array                 $data     The submitted values, in the validation and save phases.
     * @param array                 $files    The submitted files, in the validation phase.
     * @param int                   $ticketid The new ticket, in the save phase.
     */
    private function __construct(
        /** @var string */
        private string $phase,
        /** @var int */
        private int $userid,
        /** @var \MoodleQuickForm|null */
        private ?\MoodleQuickForm $mform = null,
        /** @var array */
        private array $data = [],
        /** @var array */
        private array $files = [],
        /** @var int */
        private int $ticketid = 0,
    ) {
    }

    /**
     * The hook for the definition phase.
     *
     * @param \MoodleQuickForm $mform
     * @param int              $userid
     * @return self
     */
    public static function for_definition(\MoodleQuickForm $mform, int $userid): self {
        return new self(self::PHASE_DEFINITION, $userid, $mform);
    }

    /**
     * The hook for the validation phase.
     *
     * @param array $data
     * @param array $files
     * @param int   $userid
     * @return self
     */
    public static function for_validation(array $data, array $files, int $userid): self {
        return new self(self::PHASE_VALIDATION, $userid, null, $data, $files);
    }

    /**
     * The hook for the save phase.
     *
     * @param int   $ticketid
     * @param array $data
     * @param int   $userid
     * @return self
     */
    public static function for_save(int $ticketid, array $data, int $userid): self {
        return new self(self::PHASE_SAVE, $userid, null, $data, [], $ticketid);
    }

    /**
     * The phase this dispatch is for.
     *
     * @return string One of the PHASE_* constants.
     */
    public function get_phase(): string {
        return $this->phase;
    }

    /**
     * The user submitting the form.
     *
     * @return int
     */
    public function get_userid(): int {
        return $this->userid;
    }

    /**
     * The form to add elements to.
     *
     * @return \MoodleQuickForm
     * @throws \coding_exception Outside the definition phase.
     */
    public function get_mform(): \MoodleQuickForm {
        $this->require_phase(self::PHASE_DEFINITION);
        return $this->mform;
    }

    /**
     * The submitted values.
     *
     * @return array
     * @throws \coding_exception In the definition phase.
     */
    public function get_data(): array {
        if ($this->phase === self::PHASE_DEFINITION) {
            throw new \coding_exception('No submitted data in the definition phase');
        }
        return $this->data;
    }

    /**
     * The submitted files.
     *
     * @return array
     * @throws \coding_exception Outside the validation phase.
     */
    public function get_files(): array {
        $this->require_phase(self::PHASE_VALIDATION);
        return $this->files;
    }

    /**
     * The ticket just created.
     *
     * @return int
     * @throws \coding_exception Outside the save phase.
     */
    public function get_ticketid(): int {
        $this->require_phase(self::PHASE_SAVE);
        return $this->ticketid;
    }

    /**
     * Refuse the submission with an error on one element.
     *
     * @param string $element The element name.
     * @param string $message The localised error.
     * @throws \coding_exception Outside the validation phase.
     */
    public function add_error(string $element, string $message): void {
        $this->require_phase(self::PHASE_VALIDATION);
        $this->errors[$element] = $message;
    }

    /**
     * The errors added in the validation phase.
     *
     * @return array<string, string>
     */
    public function get_errors(): array {
        return $this->errors;
    }

    /**
     * Fail unless the hook is in the given phase.
     *
     * @param string $phase
     * @throws \coding_exception
     */
    private function require_phase(string $phase): void {
        if ($this->phase !== $phase) {
            throw new \coding_exception("Only available in the {$phase} phase, not in {$this->phase}");
        }
    }
}
