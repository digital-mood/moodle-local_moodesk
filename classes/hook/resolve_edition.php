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

use local_moodesk\service\edition_service;

/**
 * Lets a plugin decide the mooDesk edition of the site.
 *
 * Dispatched by {@see edition_service} once per resolution (the answer is
 * memoised for the request), with Starter and source `fallback`. A callback may
 * replace both; `local_moodeskpro` does, from the licence. With no callback the
 * site is Starter. The OEM pin and the development override are then clamped to
 * the answer, so they can only lower it (docs/two-plugin-split-plan.md §4).
 *
 * Not dispatched when a test seam decided the edition (PHPUnit or Behat):
 * those win outright, as they always have.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to set the mooDesk edition of the site.')]
#[\core\attribute\tags('local_moodesk')]
final class resolve_edition {
    /** @var string The edition, one of the edition_service::EDITION_* constants. */
    private string $edition;

    /** @var string The source that decided it. */
    private string $source;

    /**
     * Create the hook with the base's resolution.
     *
     * @param string $edition The edition the base resolved.
     * @param string $source  The source that decided it (edition_service::edition_source()).
     */
    public function __construct(string $edition, string $source) {
        $this->edition = $edition;
        $this->source = $source;
    }

    /**
     * The edition as it stands now.
     *
     * @return string One of the edition_service::EDITION_* constants.
     */
    public function get_edition(): string {
        return $this->edition;
    }

    /**
     * The source of the edition as it stands now.
     *
     * @return string
     */
    public function get_source(): string {
        return $this->source;
    }

    /**
     * Set the edition and name the source that decided it.
     *
     * @param string $edition One of the edition_service::EDITION_* constants.
     * @param string $source  A short identifier for License status and diagnostics, e.g. the component.
     * @throws \coding_exception On an unknown edition or an empty source.
     */
    public function set_edition(string $edition, string $source): void {
        $known = [
            edition_service::EDITION_STARTER,
            edition_service::EDITION_PRO,
            edition_service::EDITION_ENTERPRISE,
        ];
        if (!in_array($edition, $known, true)) {
            throw new \coding_exception("Unknown mooDesk edition: {$edition}");
        }
        if ($source === '') {
            throw new \coding_exception('An edition source is required');
        }
        $this->edition = $edition;
        $this->source = $source;
    }
}
