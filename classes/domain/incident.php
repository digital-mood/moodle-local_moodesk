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
 * Operational incident domain model.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\domain;

/**
 * One operational failure the plugin caught and absorbed.
 *
 * A row is not a log line. It is one *class* of failure, identified by its
 * fingerprint, carrying a counter and the window it has been happening in. A
 * dead webhook endpoint over three days is one row with occurrences=4320, not
 * 4320 rows. That is what keeps the table bounded without depending on
 * retention, and what makes the health page readable without paging.
 *
 * Health is derived from time, never stored: an incident whose timelastseen
 * has fallen outside the active window is history, not a current problem. The
 * stored status only records what an administrator *decided* — that they are
 * looking at it, or that they consider it closed.
 *
 * Free of Moodle globals so it can be reasoned about (and unit tested) in
 * isolation; the repository owns persistence and the service owns the rules.
 * The exception is sources(), which asks the registry which plugins declared
 * sources of their own.
 */
class incident {
    /*
     * -------------------------------------------------------------------------
     * Sources. The base's own are a closed set; another plugin declares its
     * own through the health_sources hook (see
     * \local_moodesk\service\incident_source_registry). Licensing is
     * deliberately absent — which of its network and cache failures are
     * operationally relevant is not yet known.
     * -------------------------------------------------------------------------
     */

    /** Event observers: notifications, CSAT invites, merge, automation rules. */
    const SOURCE_OBSERVER = 'observer';

    /**
     * Outbound webhook enqueue and delivery.
     *
     * @deprecated since 3.0.2. The source belongs to local_moodeskpro, which
     * declares it. Kept, out of sources(), because local_moodeskpro 3.0.1
     * still records under it; removed once the base stops supporting that
     * add-on version.
     */
    const SOURCE_WEBHOOK = 'webhook';

    /**
     * IMAP transport and the inbound email pipeline.
     *
     * @deprecated since 3.0.2, for the same reason as SOURCE_WEBHOOK.
     */
    const SOURCE_EMAIL_INGESTION = 'email_ingestion';

    /** Any scheduled task that absorbs a throw to keep cron running. */
    const SOURCE_SCHEDULED_TASK = 'scheduled_task';

    /** The knowledge base: analytics recording and search-index removal (KB-07). */
    const SOURCE_KNOWLEDGE_BASE = 'knowledge_base';

    /**
     * The interface's AJAX handlers: a failure that was not a rule of the
     * product (those speak for themselves) but code breaking under a request.
     * The browser gets a generic message; the detail lands here (audit H7).
     */
    const SOURCE_UI = 'ui';

    /**
     * The base's own sources.
     *
     * @return string[]
     */
    public static function base_sources(): array {
        return [
            self::SOURCE_OBSERVER,
            self::SOURCE_SCHEDULED_TASK,
            self::SOURCE_KNOWLEDGE_BASE,
            self::SOURCE_UI,
        ];
    }

    /**
     * Every registered source: the base's own and those other plugins declare.
     *
     * The one method here that reaches outside the class, because which
     * sources exist depends on which plugins are installed.
     *
     * @return string[]
     */
    public static function sources(): array {
        return \local_moodesk\service\incident_source_registry::sources();
    }

    /*
     * -------------------------------------------------------------------------
     * Severities. The criterion is operational, not aesthetic: what an operator
     * has to do about it.
     * -------------------------------------------------------------------------
     */

    /** Tolerable degradation; the system kept working. Does not alert by default. */
    const SEVERITY_WARNING = 'warning';

    /** An operation was lost and nothing will retry it. */
    const SEVERITY_ERROR = 'error';

    /** A whole source is down — expired IMAP credentials, every webhook failing. */
    const SEVERITY_CRITICAL = 'critical';

    /**
     * Severities ordered least to most severe. The index is the comparison key
     * used by the alert threshold.
     *
     * @return string[]
     */
    public static function severities(): array {
        return [
            self::SEVERITY_WARNING,
            self::SEVERITY_ERROR,
            self::SEVERITY_CRITICAL,
        ];
    }

    /**
     * Rank a severity for threshold comparison. An unknown severity ranks
     * lowest so a typo can never escalate into an alert storm.
     *
     * @param string $severity
     * @return int 0-based rank; -1 when unrecognised.
     */
    public static function severity_rank(string $severity): int {
        $rank = array_search($severity, self::severities(), true);
        return $rank === false ? -1 : (int) $rank;
    }

    /*
     * -------------------------------------------------------------------------
     * Status. Administrative intent only — never a description of whether the
     * failure is currently happening. That is what timelastseen is for.
     * -------------------------------------------------------------------------
     */

    /** Default. Nobody has said anything about it. */
    const STATUS_OPEN = 'open';

    /** An administrator is looking at it. Silences alerts; stays visible. */
    const STATUS_ACKNOWLEDGED = 'acknowledged';

    /** An administrator closed it. Reappearance reopens this same row. */
    const STATUS_RESOLVED = 'resolved';

    /**
     * Every valid status.
     *
     * @return string[]
     */
    public static function statuses(): array {
        return [
            self::STATUS_OPEN,
            self::STATUS_ACKNOWLEDGED,
            self::STATUS_RESOLVED,
        ];
    }

    /*
     * -------------------------------------------------------------------------
     * Active window
     * -------------------------------------------------------------------------
     */

    /**
     * How long after its last occurrence an incident still counts as a current
     * problem, in seconds (24 hours).
     *
     * Written as arithmetic rather than using Moodle's DAYSECS so this class
     * stays free of Moodle globals.
     */
    const DEFAULT_ACTIVE_WINDOW_SECONDS = 24 * 60 * 60;

    /*
     * -------------------------------------------------------------------------
     * Retention
     * -------------------------------------------------------------------------
     */

    /**
     * How long a row survives past its last occurrence, in days.
     *
     * Retention is measured from `timelastseen`, never from `timefirstseen`: a
     * failure that has recurred for six months is a live problem, not an old
     * one, and dating it from when it started would delete it mid-incident.
     *
     * Status does not buy an exemption. A row still marked `open` after this
     * long without recurring is not an open problem — it is a problem nobody
     * closed, and keeping it forever would leave the health page arguing with
     * an outage that ended a quarter ago.
     */
    const DEFAULT_RETENTION_DAYS = 90;

    /*
     * -------------------------------------------------------------------------
     * Alerting
     * -------------------------------------------------------------------------
     */

    /**
     * How long after alerting about an incident the same incident may alert
     * again, in seconds (one hour).
     *
     * The cooldown is what stops a failure that repeats every cron tick from
     * becoming an inbox flood. It is per incident, so one noisy source cannot
     * drown out a different one that just started.
     */
    const DEFAULT_ALERT_COOLDOWN_SECONDS = 60 * 60;

    /** Severity at or above which an incident alerts, unless configured otherwise. */
    const DEFAULT_ALERT_THRESHOLD = self::SEVERITY_ERROR;

    /*
     * -------------------------------------------------------------------------
     * Context payload
     * -------------------------------------------------------------------------
     */

    /**
     * The only keys a recorded context may carry. An allowlist, not a denylist:
     * a denylist has to predict every field name that might one day hold a
     * secret, and it only has to be wrong once.
     *
     * Never admissible here, under any circumstance: passwords, API tokens,
     * JWTs, email bodies, email subjects, email addresses, email headers,
     * usernames, ticket content, webhook payloads.
     *
     * @return string[]
     */
    public static function allowed_context_keys(): array {
        return [
            'ticketid',
            'webhookid',
            'queueid',
            'ruleid',
            'taskname',
            'event_type',
            'http_status',
            'exception_class',
            'attempts',
            'folder',
            // SEC-04B. Both are low-cardinality enum values chosen by the
            // plugin, never sender-supplied text: the verdict the receiving MTA
            // produced, and which provider profile read it. Together they are
            // what tells an operator whether they are looking at a forgery or
            // at their own misconfiguration. The declared sender address is
            // deliberately NOT here — it is somebody's email address, and the
            // audit row already holds it under access control.
            'sender_auth',
            'auth_profile',
            // Audit H4. Integers: the size an IMAP server reported for a
            // message and the limit it exceeded. Nothing about whose it was.
            'size_bytes',
            'limit_bytes',
            // E5. Integers the IMAP server assigned: a message's UID, so an
            // operator can find a message the retry list gave up on, and the
            // folder's UIDVALIDITY before and after a renumbering. A UID says
            // where a message sits in a folder, nothing about whose it is.
            'uid',
            'uidvalidity_old',
            'uidvalidity_new',
        ];
    }

    /** Longest message stored; the column is char(255). */
    const MESSAGE_MAX_LENGTH = 255;

    /*
     * -------------------------------------------------------------------------
     * Fingerprint
     * -------------------------------------------------------------------------
     */

    /**
     * The identity of a *class* of failure.
     *
     * Deliberately built from the stable triple and nothing else. An exception
     * message must never feed this: messages carry ids, hostnames and timings,
     * so hashing one would mint a new incident per occurrence and defeat the
     * whole design. $scopekey is the escape hatch for when one code genuinely
     * needs splitting — per webhook subscription, say — and it is the caller's
     * job to keep it low-cardinality.
     *
     * @param string $source   One of the SOURCE_* constants.
     * @param string $code     Stable source.operation.condition identifier.
     * @param string $scopekey Optional low-cardinality discriminator.
     * @return string 40-character sha1 hex digest.
     */
    public static function fingerprint(string $source, string $code, string $scopekey = ''): string {
        return sha1($source . '|' . $code . '|' . $scopekey);
    }
}
