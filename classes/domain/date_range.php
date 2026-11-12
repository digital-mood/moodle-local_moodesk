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
 * The reporting date range: defaults, the 90-day ceiling and Y-m-d parsing,
 * shared by Reports and Knowledge base analytics.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\domain;

/**
 * Parse a `from` / `to` pair of Y-m-d strings into a bounded range.
 *
 * Extracted verbatim from report_service::parse_date_range() (KB-07, D14) so
 * the two range-based screens cannot drift on what "30 days" or "at most 90
 * days" means. report_service still exposes parse_date_range() and delegates
 * here; its constants alias these.
 *
 * Contract, unchanged from Reports:
 *  - `to` defaults to today and never lies beyond today's end;
 *  - `from` defaults to DEFAULT_DAYS before `to`;
 *  - `from` is raised to at most MAX_DAYS before `to`;
 *  - an unparseable string behaves as an absent one;
 *  - `from` is always at least a day before `to`.
 */
class date_range {
    /** Hard ceiling on the span a single query may cover, in days. */
    public const MAX_DAYS = 90;

    /** Span used when no `from` is given, in days. */
    public const DEFAULT_DAYS = 30;

    /** The only input format accepted, and the one the displays use. */
    public const FORMAT = 'Y-m-d';

    /**
     * Parse and validate a date range from raw input strings (Y-m-d format).
     *
     * Returns Unix timestamps at the start and end of the respective days in
     * the server's configured timezone.
     *
     * @param string|null $frominput Raw "from" string from GET param (Y-m-d or null).
     * @param string|null $toinput   Raw "to" string from GET param (Y-m-d or null).
     * @return array{from: int, to: int, from_display: string, to_display: string}
     */
    public static function parse(?string $frominput, ?string $toinput): array {
        $now = time();

        // Parse "to" first — defaults to today.
        $tots = self::parse_date_to_midnight($toinput, $now);
        // Clamp to end of day.
        $tots = $tots + 86399;
        // Cannot be in the future beyond today's end.
        $todayend = strtotime('today 23:59:59');
        if ($tots > $todayend) {
            $tots = $todayend;
        }

        // Parse "from" — defaults to DEFAULT_DAYS before "to".
        $defaultfrom = $tots - (self::DEFAULT_DAYS * 86400);
        $fromts = self::parse_date_to_midnight($frominput, $defaultfrom);

        // Enforce maximum range.
        $maxfrom = $tots - (self::MAX_DAYS * 86400);
        if ($fromts < $maxfrom) {
            $fromts = $maxfrom;
        }

        // Ensure from <= to.
        if ($fromts > $tots) {
            $fromts = $tots - 86400;
        }

        return [
            'from'         => $fromts,
            'to'           => $tots,
            // Uses date(), not userdate(): the bounds were parsed in the server
            // timezone above, and userdate() speaks strftime — handed 'Y-m-d'
            // it returned the literal string, which is what the Reports date
            // inputs and printable header showed until KB-07.
            'from_display' => date(self::FORMAT, $fromts),
            'to_display'   => date(self::FORMAT, $tots),
        ];
    }

    /**
     * The `from` string a preset link of N days should carry, so a 7 / 30 / 90
     * preset is nothing more than a pre-filled form.
     *
     * @param int $days
     * @return string Y-m-d, N days before today, in the server timezone.
     */
    public static function preset_from(int $days): string {
        return date(self::FORMAT, strtotime('today') - ($days * 86400));
    }

    /**
     * Parse a Y-m-d string to midnight of that day; fall back to midnight of
     * the default timestamp when absent or malformed.
     *
     * @param string|null $input
     * @param int         $default
     * @return int
     */
    private static function parse_date_to_midnight(?string $input, int $default): int {
        if ($input === null || $input === '') {
            return (int) strtotime('midnight', $default);
        }
        // Validate strict Y-m-d format.
        $parsed = \DateTime::createFromFormat(self::FORMAT, trim($input));
        if ($parsed === false) {
            return (int) strtotime('midnight', $default);
        }
        $parsed->setTime(0, 0, 0);
        return $parsed->getTimestamp();
    }
}
