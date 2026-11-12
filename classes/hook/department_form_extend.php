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
 * Lets a plugin extend the department form: add fields, validate, load and save them.
 *
 * One hook, four phases, all from {@see \local_moodesk\form\department_form}:
 *
 *  - definition: get_mform() gives the form, before its buttons.
 *  - validation: get_data(), get_files(), add_error().
 *  - load: editing an existing department; get_departmentid(), then
 *    set_default() for each field the plugin added.
 *  - save: once the department is created or updated; get_departmentid()
 *    and get_data().
 *
 * A callback checks get_phase() and ignores the phases it has nothing for.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to add, validate, load and save fields of the mooDesk department form.')]
#[\core\attribute\tags('local_moodesk')]
final class department_form_extend {
    /** Adding elements to the form. */
    const PHASE_DEFINITION = 'definition';

    /** Validating a submission. */
    const PHASE_VALIDATION = 'validation';

    /** Filling the form for an existing department. */
    const PHASE_LOAD = 'load';

    /** Saving after the department was created or updated. */
    const PHASE_SAVE = 'save';

    /** @var array<string, string> Errors keyed by element name. */
    private array $errors = [];

    /** @var array Defaults set in the load phase, element name => value. */
    private array $defaults = [];

    /**
     * Use the named constructors.
     *
     * @param string                $phase
     * @param int                   $userid       The user editing the department.
     * @param \MoodleQuickForm|null $mform        The form, in the definition phase.
     * @param array                 $data         The submitted values, in the validation and save phases.
     * @param array                 $files        The submitted files, in the validation phase.
     * @param int                   $departmentid The department, in the load and save phases (0 before it exists).
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
        private int $departmentid = 0,
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
     * The hook for the load phase.
     *
     * @param int $departmentid
     * @param int $userid
     * @return self
     */
    public static function for_load(int $departmentid, int $userid): self {
        return new self(self::PHASE_LOAD, $userid, null, [], [], $departmentid);
    }

    /**
     * The hook for the save phase.
     *
     * @param int   $departmentid
     * @param array $data
     * @param int   $userid
     * @return self
     */
    public static function for_save(int $departmentid, array $data, int $userid): self {
        return new self(self::PHASE_SAVE, $userid, null, $data, [], $departmentid);
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
     * The user editing the department.
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
     * @throws \coding_exception Outside the validation and save phases.
     */
    public function get_data(): array {
        if (!in_array($this->phase, [self::PHASE_VALIDATION, self::PHASE_SAVE], true)) {
            throw new \coding_exception("No submitted data in the {$this->phase} phase");
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
     * The department.
     *
     * @return int
     * @throws \coding_exception Outside the load and save phases.
     */
    public function get_departmentid(): int {
        if (!in_array($this->phase, [self::PHASE_LOAD, self::PHASE_SAVE], true)) {
            throw new \coding_exception("No department in the {$this->phase} phase");
        }
        return $this->departmentid;
    }

    /**
     * Refuse the submission with an error on one element.
     *
     * @param string $element
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
     * Set the value an element starts with when editing.
     *
     * @param string $element
     * @param mixed  $value
     * @throws \coding_exception Outside the load phase.
     */
    public function set_default(string $element, $value): void {
        $this->require_phase(self::PHASE_LOAD);
        $this->defaults[$element] = $value;
    }

    /**
     * The defaults set in the load phase.
     *
     * @return array Element name => value.
     */
    public function get_defaults(): array {
        return $this->defaults;
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
