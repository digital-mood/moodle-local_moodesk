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
 * A single dashboard metric and everything the UI needs to render it honestly.
 *
 * The reason this class exists is that "zero" and "no data" are different
 * claims, and a dashboard that conflates them lies to its reader. Three states
 * are distinguished:
 *
 *  - UNAVAILABLE — the metric cannot be produced on this installation at all,
 *    because the edition does not include it or the feature behind it is turned
 *    off. The UI omits the panel entirely rather than showing an empty shell.
 *  - NO_DATA — the metric is available but nothing was measured. An average
 *    over zero tickets is not an average of zero seconds; an SLA compliance
 *    figure over zero resolved tickets is not 0% compliance, which would be a
 *    slander. The UI shows a dash.
 *  - OK — there is a real measurement, and it may legitimately be zero. Zero
 *    active tickets is a fact worth stating plainly.
 *
 * The same distinction governs deltas. A percentage change against a previous
 * period of zero is undefined, not infinite, so no delta is offered at all.
 *
 * This class holds no database access and no Moodle API calls: it is a pure
 * value object. Labels and formatting belong to the service layer.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\domain;

/**
 * One measured value, its sample size, and its movement against a prior period.
 */
class dashboard_metric {
    /** There is a real measurement. It may legitimately be zero. */
    public const STATE_OK = 'ok';

    /** The metric is available but nothing was measured. */
    public const STATE_NO_DATA = 'nodata';

    /** The metric cannot be produced on this installation. */
    public const STATE_UNAVAILABLE = 'unavailable';

    /** The value rose against the previous period. */
    public const DIRECTION_UP = 'up';

    /** The value fell against the previous period. */
    public const DIRECTION_DOWN = 'down';

    /** The value did not move. */
    public const DIRECTION_FLAT = 'flat';

    /** @var string One of the STATE_* constants. */
    private string $state;

    /** @var int|float|null The measured value; null unless the state is OK. */
    private $value;

    /** @var int How many records the value was measured over. */
    private int $sample;

    /** @var int|float|null The equivalent value for the preceding period. */
    private $previous;

    /** @var bool Whether a rise is good news — governs how the UI colours a delta. */
    private bool $higherisbetter;

    /**
     * Constructor. Use the named factories instead.
     *
     * @param string          $state          One of the STATE_* constants.
     * @param int|float|null  $value          The measured value.
     * @param int             $sample         Records behind the value.
     * @param int|float|null  $previous       Same value for the preceding period.
     * @param bool            $higherisbetter Whether a rise is good news.
     */
    private function __construct(
        string $state,
        $value,
        int $sample,
        $previous,
        bool $higherisbetter
    ) {
        $this->state          = $state;
        $this->value          = $value;
        $this->sample         = $sample;
        $this->previous       = $previous;
        $this->higherisbetter = $higherisbetter;
    }

    /*
     * =========================================================================
     * Factories
     * =========================================================================
     */

    /**
     * A counted quantity. Always has data — zero tickets is a measurement.
     *
     * @param int            $value          The count.
     * @param int|null       $previous       Count for the preceding period, or null for no delta.
     * @param bool           $higherisbetter Whether a rise is good news.
     * @return self
     */
    public static function count(int $value, ?int $previous = null, bool $higherisbetter = false): self {
        return new self(self::STATE_OK, $value, $value, $previous, $higherisbetter);
    }

    /**
     * A measurement averaged over a sample, which may be empty.
     *
     * An empty sample yields NO_DATA rather than a zero: nothing was measured,
     * and reporting "0 seconds average response" for a period with no responses
     * would invent a performance the team never achieved.
     *
     * @param int|float|null $value          The average, or null when unmeasurable.
     * @param int            $sample         Records behind the average.
     * @param int|float|null $previous       Average for the preceding period, or null.
     * @param bool           $higherisbetter Whether a rise is good news.
     * @return self
     */
    public static function average($value, int $sample, $previous = null, bool $higherisbetter = false): self {
        if ($sample <= 0 || $value === null) {
            return self::no_data($higherisbetter);
        }

        return new self(self::STATE_OK, $value, $sample, $previous, $higherisbetter);
    }

    /**
     * The metric is available but nothing was measured.
     *
     * @param bool $higherisbetter Whether a rise is good news.
     * @return self
     */
    public static function no_data(bool $higherisbetter = false): self {
        return new self(self::STATE_NO_DATA, null, 0, null, $higherisbetter);
    }

    /**
     * The metric cannot be produced on this installation.
     *
     * @return self
     */
    public static function unavailable(): self {
        return new self(self::STATE_UNAVAILABLE, null, 0, null, false);
    }

    /*
     * =========================================================================
     * State
     * =========================================================================
     */

    /**
     * The metric's state: one of the STATE_* constants.
     *
     * @return string
     */
    public function get_state(): string {
        return $this->state;
    }

    /**
     * True when this installation can produce the metric at all.
     *
     * @return bool
     */
    public function is_available(): bool {
        return $this->state !== self::STATE_UNAVAILABLE;
    }

    /**
     * True when there is a real measurement to show. A value of zero counts.
     *
     * @return bool
     */
    public function has_data(): bool {
        return $this->state === self::STATE_OK;
    }

    /**
     * The measured value, or null when there is nothing to show.
     *
     * @return int|float|null
     */
    public function get_value() {
        return $this->value;
    }

    /**
     * How many records the value was measured over.
     *
     * @return int
     */
    public function get_sample(): int {
        return $this->sample;
    }

    /**
     * The equivalent value for the preceding period, or null.
     *
     * @return int|float|null
     */
    public function get_previous() {
        return $this->previous;
    }

    /*
     * =========================================================================
     * Movement against the previous period
     * =========================================================================
     */

    /**
     * True when a percentage change can honestly be stated.
     *
     * @return bool
     */
    public function has_delta(): bool {
        return $this->get_delta_pct() !== null;
    }

    /**
     * The percentage change against the preceding period.
     *
     * Returns null when there is no comparison to make: no current measurement,
     * no previous value, or a previous value of zero. Growth from zero is
     * undefined rather than infinite, and "↑∞%" tells a reader nothing.
     *
     * @return float|null Percentage change, rounded to one decimal.
     */
    public function get_delta_pct(): ?float {
        if (!$this->has_data() || $this->previous === null) {
            return null;
        }

        $previous = (float) $this->previous;
        if ($previous == 0.0) {
            return null;
        }

        return round((((float) $this->value - $previous) / abs($previous)) * 100, 1);
    }

    /**
     * Which way the value moved: one of the DIRECTION_* constants, or null.
     *
     * @return string|null
     */
    public function get_delta_direction(): ?string {
        $delta = $this->get_delta_pct();
        if ($delta === null) {
            return null;
        }

        if ($delta > 0) {
            return self::DIRECTION_UP;
        }

        return ($delta < 0) ? self::DIRECTION_DOWN : self::DIRECTION_FLAT;
    }

    /**
     * Whether the movement is good news, accounting for the metric's polarity.
     *
     * Resolving more tickets is good and breaching more SLAs is not, though
     * both are a rise. A flat reading is neither, and returns null along with
     * every case that has no delta at all.
     *
     * @return bool|null
     */
    public function is_improvement(): ?bool {
        $direction = $this->get_delta_direction();
        if ($direction === null || $direction === self::DIRECTION_FLAT) {
            return null;
        }

        return ($direction === self::DIRECTION_UP) === $this->higherisbetter;
    }

    /**
     * Whether a rise in this metric is good news.
     *
     * @return bool
     */
    public function higher_is_better(): bool {
        return $this->higherisbetter;
    }
}
