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
 * Unit tests for the shared reporting date range.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\domain;

/**
 * Tests for date_range: the 7/30/90/custom contract Reports had before the
 * parser was extracted (KB-07, D14), pinned so the extraction and every
 * later change is measured against it.
 *
 * @covers \local_moodesk\domain\date_range
 */
final class date_range_test extends \advanced_testcase {
    /**
     * No input: `to` is the end of today, `from` is 30 days earlier at midnight.
     */
    public function test_defaults_to_thirty_days_ending_today(): void {
        $range = date_range::parse(null, null);

        $this->assertSame((int) strtotime('today 23:59:59'), $range['to']);
        $this->assertSame((int) strtotime('midnight', $range['to'] - (30 * 86400)), $range['from']);
        $this->assertLessThan($range['to'], $range['from']);
    }

    /**
     * A custom pair is honoured to the day: `from` at midnight, `to` at 23:59:59.
     */
    public function test_a_custom_pair_is_honoured_to_the_day(): void {
        $range = date_range::parse('2026-04-01', '2026-04-15');

        $this->assertSame((new \DateTime('2026-04-01 00:00:00'))->getTimestamp(), $range['from']);
        $this->assertSame((new \DateTime('2026-04-15 23:59:59'))->getTimestamp(), $range['to']);
    }

    /**
     * The ceiling: a span longer than 90 days is cut to 90 by raising `from`.
     */
    public function test_a_span_over_ninety_days_is_clamped_from_the_start(): void {
        $range = date_range::parse('2025-01-01', '2026-01-01');

        $this->assertSame($range['to'] - (date_range::MAX_DAYS * 86400), $range['from']);
    }

    /**
     * `to` never lies in the future; a future `to` is today's end.
     */
    public function test_to_cannot_pass_the_end_of_today(): void {
        $range = date_range::parse(null, '2099-12-31');

        $this->assertSame((int) strtotime('today 23:59:59'), $range['to']);
    }

    /**
     * `from` after `to` is corrected to the day before `to`.
     */
    public function test_from_after_to_becomes_the_day_before(): void {
        $range = date_range::parse('2026-04-20', '2026-04-10');

        $this->assertSame($range['to'] - 86400, $range['from']);
    }

    /**
     * Garbage behaves exactly like an absent value — no exception, the default.
     */
    public function test_malformed_input_falls_back_to_the_default(): void {
        $bad  = date_range::parse('not-a-date', '15/04/2026');
        $none = date_range::parse(null, null);

        $this->assertSame($none['from'], $bad['from']);
        $this->assertSame($none['to'], $bad['to']);
    }

    /**
     * The display strings are real Y-m-d dates that round-trip through the
     * date inputs — not the literal "Y-m-d" userdate() returned for a
     * non-strftime format.
     */
    public function test_display_strings_round_trip(): void {
        $range = date_range::parse('2026-04-01', '2026-04-15');

        $this->assertSame('2026-04-01', $range['from_display']);
        $this->assertSame('2026-04-15', $range['to_display']);

        $again = date_range::parse($range['from_display'], $range['to_display']);
        $this->assertSame($range['from'], $again['from']);
        $this->assertSame($range['to'], $again['to']);
    }
}
