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
 * Recording and querying of operational incidents.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

use local_moodesk\domain\incident;
use local_moodesk\repository\incident_repository;

/**
 * The one place operational failures are turned into incidents.
 *
 * WHY THIS EXISTS. Fifteen catch blocks in observer.php, plus the webhook
 * dispatch task and the IMAP poller, absorb throws and report them through
 * debugging() — which production, running with debugging off, never shows.
 * The failure is real, the operation is lost, and nobody hears about it until
 * a user complains days later.
 *
 * THE INVARIANT. record() never throws. Not on a DB outage, not on malformed
 * input, not on a bug of its own. Every caller is code that already decided
 * "this failure must not break the operation the user asked for" — a
 * notification that could not be sent must not roll back the ticket that
 * triggered it. Observability that can break that guarantee is worse than no
 * observability, so the last line of defence is a debugging() call, exactly
 * where the callers were before.
 *
 * TRANSACTIONS. Moodle's DML layer has one connection and one transaction
 * stack, so a row written while a transaction is open goes with it if that
 * transaction rolls back — and a failure severe enough to abort the work is
 * exactly the one worth recording. Core does not help here: only *external*
 * event observers are deferred past a transaction, and every observer this
 * plugin registers is internal, so it runs inside whatever transaction was
 * open when the event fired.
 *
 * So the service writes first and verifies afterwards. The row goes in
 * immediately, always; and when it went in while a transaction was open, a
 * receipt is kept holding the state the write produced, so the outcome can be
 * checked once that transaction closes. If the row is gone, or is there but no
 * longer shows what the write did, it is written again. Two things settle the
 * receipts: the next record() that finds no transaction open, and a shutdown
 * callback registered once per request. Moodle runs shutdown callbacks *before*
 * it force-rolls-back any transaction still open, so a normally-terminated
 * request has already committed or rolled back by then.
 *
 * The receipt records the *outcome*, not a timestamp. Comparing timestamps
 * alone is not enough: an incident sitting at `resolved` that reappears during
 * the same second as its last-seen stamp is reopened by the write, and if that
 * rolls back the row still reads `resolved` with a timestamp identical to the
 * one recorded. A recurrence would stay hidden as handled, and — since
 * acknowledged and resolved incidents do not alert — hidden from the operator
 * twice over. So all four things the write can change are checked: the status
 * it reopened, the counter it bumped, the last-seen stamp it moved, and the
 * severity it raised.
 *
 * Write-then-verify rather than hold-then-write, because "is a transaction
 * open" is not a reliable proxy for "this write is at risk". Moodle's own
 * PHPUnit runner keeps a transaction open for the whole of every test on
 * PostgreSQL and MSSQL, and holding writes back under that condition would mean
 * recording nothing at all — a failure mode the multi-database CI caught, and a
 * good argument that any future host doing the same would silence the feature
 * completely. Writing always cannot be silenced; verification only adds
 * durability.
 *
 * The one case that still loses an incident is a request that reaches shutdown
 * with a transaction open — which Moodle itself logs as a coding error, since
 * every transaction is meant to be finished by hand or by the exception
 * handler.
 *
 * It is also not gated by edition. Failure detection is production-readiness
 * infrastructure, not a premium feature, and gating it would blind the tier
 * running without paid support. The existing gates keep working without being
 * restated: eleven of the fifteen observers already return before their try
 * block when the edition lacks the feature, so a Starter site never reaches
 * the instrumented code at all.
 */
class incident_service {
    /**
     * Patterns whose match is replaced wholesale in a recorded message.
     *
     * Defence in depth, not the primary control — the primary control is that
     * callers pass exception messages, not payloads. But imap_client throws
     * `'IMAP command failed: ' . $line` with a raw server line attached, and a
     * server line can carry anything, so a message is never trusted verbatim.
     */
    private const REDACTION_PATTERNS = [
        // Key=value, key: value or key "value" for anything secret-shaped.
        '/\b(pass(?:word|wd)?|secret|token|api[_-]?key|authorization|auth)\b\s*[:=]\s*\S+/i',
        // Bearer or Basic credentials in an HTTP header echo.
        '/\b(bearer|basic)\s+[A-Za-z0-9._~+\/=-]+/i',
        // A JWT, which is recognisable on its own without a labelling key.
        '/\beyJ[A-Za-z0-9._-]{10,}/',
        // The IMAP LOGIN command, which carries the mailbox password inline.
        '/\bLOGIN\s+\S+\s+\S+/i',
        // Any email address. Not a secret, but the payload policy forbids
        // storing addresses, and a parser or SMTP failure quotes them freely:
        // "could not deliver to alice@example.org" is a real exception message.
        // An operator needs to know delivery failed, not to whom.
        '/[\w.+-]+@[\w-]+\.[\w.-]+/',
    ];

    /** Replacement written in place of anything the patterns match. */
    private const REDACTED = '[redacted]';

    /**
     * Receipts for incidents written while a transaction was open, to be
     * re-checked once it closes. Each holds `row` (what to write again) and
     * `expected` (the state the write produced, as read back). Static because
     * they belong to the request, not to whichever instance happened to catch
     * the failure.
     *
     * @var \stdClass[]
     */
    private static array $unverified = [];

    /** @var bool Whether this request already registered the shutdown check. */
    private static bool $flushregistered = false;

    /** @var incident_repository */
    private incident_repository $repo;

    /**
     * Build the service.
     *
     * @param incident_repository|null $repo Injectable for tests.
     */
    public function __construct(?incident_repository $repo = null) {
        $this->repo = $repo ?? new incident_repository();
    }

    /**
     * Factory mirroring the other services.
     *
     * @return self
     */
    public static function create(): self {
        return new self();
    }

    /**
     * Record one occurrence of an operational failure. Never throws.
     *
     * Repeated identical failures collapse onto one row by fingerprint, so a
     * dead endpoint hammering every cron tick costs one row and a counter
     * rather than an unbounded pile of rows.
     *
     * @param string $source   A registered source ({@see incident_source_registry}).
     * @param string $code     Stable source.operation.condition identifier.
     * @param string $severity One of the incident::SEVERITY_* constants.
     * @param string $message  Human-readable detail; truncated and redacted.
     * @param array  $context  Extra detail; silently filtered to the allowlist.
     * @param string $scopekey Optional low-cardinality fingerprint discriminator.
     * @return int The incident id, or 0 when nothing could be recorded.
     * @throws \coding_exception Under PHPUnit only, for an unregistered source.
     */
    public function record(
        string $source,
        string $code,
        string $severity,
        string $message,
        array $context = [],
        string $scopekey = ''
    ): int {
        // Under PHPUnit an unregistered source is a typo, and the test that
        // wrote it must fail. Checked before the try so the throw is not turned
        // into a debugging() call like every other failure here.
        if (incident_source_registry::is_strict() && !incident_source_registry::is_registered($source)) {
            throw new \coding_exception('local_moodesk: unknown incident source ' . $source);
        }

        try {
            // Settle any receipt a previous transaction left behind, so the
            // check happens at the next opportunity rather than at shutdown.
            $this->flush_deferred_now();

            // Anywhere else it is recorded all the same. An add-on that
            // predates the health_sources hook records under sources it cannot
            // declare, and throwing would break its observers and tasks. The
            // row is kept, unseen and unalerted, until a plugin declares it.
            if (!incident_source_registry::is_registered($source)) {
                debugging(
                    'local_moodesk: incident recorded under unregistered source ' . $source
                    . '; it is neither shown nor alerted until a plugin declares it (hook health_sources).',
                    DEBUG_DEVELOPER
                );
            }
            if (!in_array($severity, incident::severities(), true)) {
                throw new \coding_exception('local_moodesk: unknown incident severity ' . $severity);
            }

            $row              = new \stdClass();
            $row->fingerprint = incident::fingerprint($source, $code, $scopekey);
            $row->source      = $source;
            $row->code        = $code;
            $row->severity    = $severity;
            $row->message     = self::sanitise_message($message);
            $row->context     = self::encode_context($context);
            $row->now         = time();

            $id = $this->repo->record_occurrence($row);

            // Written inside someone else's transaction, so it may not survive
            // their rollback. Read back what the write produced and keep it as
            // the receipt: the outcome is what has to be verified later, and it
            // cannot be reconstructed from the input alone.
            if ($this->repo->in_transaction()) {
                $receipt           = new \stdClass();
                $receipt->row      = $row;
                $receipt->expected = $this->repo->get_by_fingerprint($row->fingerprint);

                if ($receipt->expected !== null) {
                    self::$unverified[] = $receipt;
                    $this->register_flush();
                }
            }

            return $id;
        } catch (\Throwable $e) {
            // The whole point of this class is that a failure here cannot
            // become the caller's problem. Fall back to exactly what the
            // callers did before, and swallow the rest.
            debugging(
                'local_moodesk incident_service::record failed for ' . $code . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            return 0;
        }
    }

    /**
     * Mark an incident as being looked at. Silences its alerts; it stays
     * visible on the health page.
     *
     * Unlike {@see record()} this is a deliberate administrative action behind
     * a capability check in the UI, so a failure here is a real error and is
     * allowed to propagate — the operator needs to know their click did not
     * land.
     *
     * @param int $id     Incident id.
     * @param int $userid Acting administrator.
     * @return bool False when the incident does not exist.
     */
    public function acknowledge(int $id, int $userid): bool {
        return $this->repo->set_status($id, incident::STATUS_ACKNOWLEDGED, $userid, time());
    }

    /**
     * Mark an incident as closed. If the failure happens again the same row
     * reopens, keeping its original first-seen stamp.
     *
     * @param int $id     Incident id.
     * @param int $userid Acting administrator.
     * @return bool False when the incident does not exist.
     */
    public function resolve(int $id, int $userid): bool {
        return $this->repo->set_status($id, incident::STATUS_RESOLVED, $userid, time());
    }

    /**
     * The incidents that count as current problems.
     *
     * Only those of registered sources: an incident of a source nobody declares
     * stays in the table but is not a current problem anyone can see or act on.
     *
     * @param string|null $source Restrict to one source, or null for all.
     * @param int|null    $window Active window in seconds; defaults to the
     *                            configured one.
     * @return \stdClass[]
     */
    public function get_active(?string $source = null, ?int $window = null): array {
        if ($source !== null && !incident_source_registry::is_registered($source)) {
            return [];
        }
        return array_filter(
            $this->repo->get_active($this->window_start($window), $source),
            static fn(\stdClass $incident): bool => incident_source_registry::is_registered($incident->source)
        );
    }

    /**
     * Active incident counts per registered source, for the health summary.
     *
     * @param int|null $window Active window in seconds; defaults to the
     *                         configured one.
     * @return array<string, int>
     */
    public function count_active_by_source(?int $window = null): array {
        return array_filter(
            $this->repo->count_active_by_source($this->window_start($window)),
            static fn(string $source): bool => incident_source_registry::is_registered($source),
            ARRAY_FILTER_USE_KEY
        );
    }


    /*
     * -------------------------------------------------------------------------
     * Alerting (OBS-03)
     * -------------------------------------------------------------------------
     */

    /**
     * The incidents that should alert right now.
     *
     * Filtering happens in PHP rather than SQL because the severity comparison
     * is a rank, not a string ordering, and the candidate set is small by
     * construction — one row per class of failure, inside the active window.
     *
     * @param int|null $now Unix timestamp, for tests.
     * @return \stdClass[]
     */
    public function pending_alerts(?int $now = null): array {
        $now       = $now ?? time();
        $threshold = self::alert_threshold();
        $cooldown  = self::alert_cooldown_seconds();

        return array_values(array_filter(
            $this->get_active(),
            static function (\stdClass $incident) use ($threshold, $cooldown, $now): bool {
                return self::should_alert($incident, $threshold, $cooldown, $now);
            }
        ));
    }

    /**
     * Whether one incident should alert.
     *
     * Pure, so the rule can be pinned without a database. Four clauses:
     *
     *  - Acknowledged means "I am on it", and it silences alerts. This is the
     *    one clause nothing overrides, not even a critical: an operator who
     *    said they are looking at it does not need telling again.
     *  - Below the configured severity threshold, nothing alerts.
     *  - Never alerted before → alert.
     *  - Escalated past the severity it was last alerted at → alert, cooldown
     *    or not. This is how a critical bypasses the cooldown without alerting
     *    on every cron tick for as long as the failure lasts: the bypass is
     *    spent on the escalation, and a steady-state critical is back under
     *    the cooldown.
     *  - Otherwise, alert only once the cooldown has elapsed.
     *
     * Resolved incidents never reach here — get_active() excludes them.
     *
     * @param \stdClass $incident  The incident row.
     * @param string    $threshold Minimum severity that alerts.
     * @param int       $cooldown  Seconds between alerts for one incident.
     * @param int       $now       Unix timestamp.
     * @return bool
     */
    public static function should_alert(
        \stdClass $incident,
        string $threshold,
        int $cooldown,
        int $now
    ): bool {
        if ($incident->status === incident::STATUS_ACKNOWLEDGED) {
            return false;
        }

        if (incident::severity_rank($incident->severity) < incident::severity_rank($threshold)) {
            return false;
        }

        if ($incident->timelastalerted === null) {
            return true;
        }

        $alertedat = $incident->severityalerted ?? '';
        if (incident::severity_rank($incident->severity) > incident::severity_rank($alertedat)) {
            return true;
        }

        return ($now - (int) $incident->timelastalerted) >= $cooldown;
    }

    /**
     * Record that an incident was alerted about.
     *
     * @param int    $id       Incident id.
     * @param string $severity Severity alerted at.
     */
    public function mark_alerted(int $id, string $severity): void {
        $this->repo->mark_alerted($id, $severity, time());
    }

    /**
     * The configured minimum severity that alerts.
     *
     * @return string One of the incident::SEVERITY_* constants.
     */
    public static function alert_threshold(): string {
        $configured = get_config('local_moodesk', 'incident_alert_severity');
        if (!is_string($configured) || !in_array($configured, incident::severities(), true)) {
            return incident::DEFAULT_ALERT_THRESHOLD;
        }
        return $configured;
    }

    /**
     * The configured cooldown between alerts for one incident, in seconds.
     *
     * A stored zero is honoured — an operator who wants every occurrence is
     * entitled to it — but an unset or negative setting falls back to the
     * default rather than to no cooldown at all.
     *
     * @return int
     */
    public static function alert_cooldown_seconds(): int {
        $configured = get_config('local_moodesk', 'incident_alert_cooldown');
        if ($configured === false || $configured === '' || (int) $configured < 0) {
            return incident::DEFAULT_ALERT_COOLDOWN_SECONDS;
        }
        return (int) $configured * 60;
    }

    /**
     * The configured active window, in seconds.
     *
     * An unset setting reads as false, which would cast to 0 and make every
     * incident look current forever, so only an explicitly stored positive
     * value is honoured.
     *
     * @return int
     */
    public static function active_window_seconds(): int {
        $configured = get_config('local_moodesk', 'incident_active_window_hours');
        if ($configured === false || $configured === '' || (int) $configured < 1) {
            return incident::DEFAULT_ACTIVE_WINDOW_SECONDS;
        }
        return (int) $configured * 60 * 60;
    }

    /**
     * The configured retention period, in seconds.
     *
     * Unlike the alert cooldown, a stored zero is *not* honoured: zero here
     * would mean "delete every incident on the next cron run", which is a
     * catastrophic reading of a field an operator can empty by accident. Any
     * value below one day falls back to the default. Retention is not the
     * switch for a site that wants to keep less noise on screen — the active
     * window is, and it destroys nothing.
     *
     * @return int
     */
    public static function retention_seconds(): int {
        $configured = get_config('local_moodesk', 'incident_retention_days');
        if ($configured === false || $configured === '' || (int) $configured < 1) {
            return incident::DEFAULT_RETENTION_DAYS * 24 * 60 * 60;
        }
        return (int) $configured * 24 * 60 * 60;
    }

    /**
     * Start of the active window as a unix timestamp.
     *
     * @param int|null $window Window length in seconds, or null for configured.
     * @return int
     */
    private function window_start(?int $window): int {
        return time() - ($window ?? self::active_window_seconds());
    }

    /**
     * Register the end-of-request check, once per request.
     */
    private function register_flush(): void {
        if (self::$flushregistered) {
            return;
        }
        self::$flushregistered = true;
        \core_shutdown_manager::register_function([self::class, 'flush_deferred']);
    }

    /**
     * Re-check every incident written inside a transaction, and rewrite the
     * ones that did not survive it.
     *
     * Public because Moodle's shutdown manager calls it by name, and because
     * PHPUnit never reaches a real shutdown — the tests drive it directly.
     *
     * Never throws, for the same reason {@see record()} never throws: this runs
     * at the end of somebody else's request.
     */
    public static function flush_deferred(): void {
        if (!self::$unverified) {
            return;
        }

        try {
            $repo = new incident_repository();
            if ($repo->in_transaction()) {
                // Still inside one: checking now would read the very state that
                // is in question. Leave the receipts for the next attempt.
                return;
            }

            // Detach before working: a throw halfway through must not leave the
            // receipts to be retried forever by the next record().
            $pending          = self::$unverified;
            self::$unverified = [];

            foreach ($pending as $receipt) {
                $stored = $repo->get_by_fingerprint($receipt->row->fingerprint);

                if ($stored === null || !self::write_survived($stored, $receipt->expected)) {
                    $repo->record_occurrence($receipt->row);
                }
            }
        } catch (\Throwable $e) {
            debugging(
                'local_moodesk incident_service: unverified incidents could not be settled: '
                . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Whether the row on disk still shows everything the write produced.
     *
     * Deliberately not a timestamp comparison. Every field a write can move is
     * checked against what it moved it to, because a rollback can undo one of
     * them while leaving another looking untouched — the reopen-within-the-same-
     * second case, where status returns to `resolved` but timelastseen is
     * identical to the value recorded.
     *
     * Each check is "no worse than expected" rather than equality: another
     * request may legitimately have bumped the counter or raised the severity
     * further in the meantime, and that is not a lost write.
     *
     * @param \stdClass $stored   The row as it is now.
     * @param \stdClass $expected The row as the write left it.
     * @return bool False when anything the write did is missing.
     */
    private static function write_survived(\stdClass $stored, \stdClass $expected): bool {
        // The reopen was undone: it is back to resolved when the write left it
        // open or acknowledged. This is the case a timestamp check misses.
        $wasreopened = $stored->status === incident::STATUS_RESOLVED
            && $expected->status !== incident::STATUS_RESOLVED;
        if ($wasreopened) {
            return false;
        }

        if ((int) $stored->occurrences < (int) $expected->occurrences) {
            return false;
        }

        if ((int) $stored->timelastseen < (int) $expected->timelastseen) {
            return false;
        }

        if (incident::severity_rank($stored->severity) < incident::severity_rank($expected->severity)) {
            return false;
        }

        return true;
    }

    /**
     * Settle outstanding receipts if this moment allows it. Called at the top
     * of record().
     */
    private function flush_deferred_now(): void {
        if (self::$unverified && !$this->repo->in_transaction()) {
            self::flush_deferred();
        }
    }

    /**
     * How many written incidents are still waiting to be verified.
     *
     * @return int
     */
    public static function deferred_count(): int {
        return count(self::$unverified);
    }

    /**
     * Drop the receipts without acting on them. Test seam only: PHPUnit resets
     * the database between cases but static state outlives it, so a receipt
     * from one test would otherwise surface in the next.
     */
    public static function reset_deferred_for_testing(): void {
        self::$unverified      = [];
        self::$flushregistered = false;
    }

    /**
     * Strip anything secret-shaped out of a message and cut it to the column.
     *
     * Redaction runs before truncation on purpose: truncating first could cut a
     * token in half and leave the visible half sitting in the database.
     *
     * @param string $message
     * @return string
     */
    public static function sanitise_message(string $message): string {
        $clean = trim($message);

        foreach (self::REDACTION_PATTERNS as $pattern) {
            $clean = (string) preg_replace($pattern, self::REDACTED, $clean);
        }

        if ($clean === '') {
            $clean = '-';
        }

        return \core_text::substr($clean, 0, incident::MESSAGE_MAX_LENGTH);
    }

    /**
     * Reduce a context array to the allowlist and encode it as JSON.
     *
     * Keys outside the allowlist are dropped without comment, and so are values
     * that are not scalars — a nested structure is exactly how a payload or a
     * user record would sneak in under an innocent key name.
     *
     * @param array $context
     * @return string|null JSON object, or null when nothing survived.
     */
    public static function encode_context(array $context): ?string {
        $allowed = incident::allowed_context_keys();
        $clean   = [];

        foreach ($context as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            if ($value === null || is_scalar($value)) {
                $clean[$key] = is_string($value) ? self::sanitise_message($value) : $value;
            }
        }

        return $clean ? json_encode($clean) : null;
    }
}
