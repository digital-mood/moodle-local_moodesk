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

namespace local_moodesk\output;

use local_moodesk\domain\department;

/**
 * Everything the ticket list is filtered, sorted and paginated by, in one place.
 *
 * This is a value object over the request: it reads no database and renders
 * nothing. It exists because the list is now produced by two entry points — the
 * page (index.php) and the fragment that refreshes its results region — and
 * those two must never disagree about what "the current query" is.
 *
 * Both constructors funnel into the same private one and the same cleaning, so
 * a parameter cannot be sanitised one way for the page and another way for the
 * refresh. {@see from_request()} is the page's door and {@see from_query_string()}
 * is the fragment's; between them there is a single set of rules.
 *
 * The query string is the source of truth for filter state — it already was,
 * because that is what the filter bar submits, what the chips dismiss into,
 * what a saved view stores and what pagination carries. Nothing here introduces
 * a second one.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ticket_list_query {
    /**
     * The request parameters the list itself uses, which a plugin filter may not take.
     */
    const RESERVED_PARAMS = [
        'page', 'view', 'search', 'status', 'priority', 'categoryid', 'departmentid', 'assigneeid',
        'sla', 'created_from', 'created_to', 'updated_from', 'updated_to', 'sort',
        'id', 'sesskey', 'action',
    ];

    /** @var bool Whether the viewer holds :viewall. Requesters get a reduced filter set. */
    private bool $isagent;

    /** @var int Zero-based page number. */
    private int $page;

    /** @var string One of department::valid_views(). */
    private string $view;

    /** @var string Free-text search term. */
    private string $search;

    /** @var int[] Selected status values; empty means every status. */
    private array $statuses;

    /** @var int Priority, or -1 for "any". */
    private int $priority;

    /** @var int Category id, or 0 for "any". */
    private int $categoryid;

    /** @var int Department id, or -1 for "any" (0 is the real "no department"). */
    private int $departmentid;

    /** @var int Assignee id, or -1 for "any" (0 is the real "unassigned"). */
    private int $assigneeid;

    /** @var array Plugin filter values the request carries (hook ticket_list_filters), name => value. */
    private array $pluginvalues = [];

    /** @var int 0 = off, 1 = breached only, 2 = warning only. */
    private int $sla;

    /** @var string Raw created-from date as typed, '' when unset. */
    private string $createdfrom;

    /** @var string Raw created-to date as typed, '' when unset. */
    private string $createdto;

    /** @var string Raw updated-from date as typed, '' when unset. */
    private string $updatedfrom;

    /** @var string Raw updated-to date as typed, '' when unset. */
    private string $updatedto;

    /** @var string Sort key; only 'sla' is understood today, '' is the default order. */
    private string $sort;

    /**
     * Hold one already-cleaned set of list parameters.
     *
     * @param bool     $isagent
     * @param int      $page
     * @param string   $view
     * @param string   $search
     * @param int[]    $statuses
     * @param int      $priority
     * @param int      $categoryid
     * @param int      $departmentid
     * @param int      $assigneeid
     * @param int      $sla
     * @param string   $createdfrom
     * @param string   $createdto
     * @param string   $updatedfrom
     * @param string   $updatedto
     * @param string   $sort
     */
    private function __construct(
        bool $isagent,
        int $page,
        string $view,
        string $search,
        array $statuses,
        int $priority,
        int $categoryid,
        int $departmentid,
        int $assigneeid,
        int $sla,
        string $createdfrom,
        string $createdto,
        string $updatedfrom,
        string $updatedto,
        string $sort
    ) {
        $this->isagent = $isagent;
        // A negative page is not a page. Anything above the last one is handled
        // by the service returning nothing, which is the honest answer.
        $this->page = max(0, $page);
        // Sanitise the view value.
        $this->view = in_array($view, department::valid_views(), true) ? $view : department::VIEW_ALL;
        $this->search = $search;
        // Deduplicate and cast to int.
        $this->statuses = array_values(array_unique(array_map('intval', $statuses)));
        $this->priority = $priority;
        $this->categoryid = $categoryid;
        $this->departmentid = $departmentid;
        $this->assigneeid = $assigneeid;
        $this->sla = $sla;
        $this->createdfrom = $createdfrom;
        $this->createdto = $createdto;
        $this->updatedfrom = $updatedfrom;
        $this->updatedto = $updatedto;
        // Sorting is an agent affordance; the requester list has no sort control.
        $this->sort = $isagent ? $sort : '';
    }

    /**
     * Read the query the current request is asking for.
     *
     * @param bool $isagent Whether the viewer holds :viewall.
     * @return self
     */
    public static function from_request(bool $isagent): self {
        $query = new self(
            $isagent,
            optional_param('page', 0, PARAM_INT),
            optional_param('view', department::VIEW_ALL, PARAM_ALPHANUMEXT),
            optional_param('search', '', PARAM_TEXT),
            optional_param_array('status', [], PARAM_INT),
            optional_param('priority', -1, PARAM_INT),
            optional_param('categoryid', 0, PARAM_INT),
            optional_param('departmentid', -1, PARAM_INT),
            optional_param('assigneeid', -1, PARAM_INT),
            optional_param('sla', 0, PARAM_INT),
            optional_param('created_from', '', PARAM_TEXT),
            optional_param('created_to', '', PARAM_TEXT),
            optional_param('updated_from', '', PARAM_TEXT),
            optional_param('updated_to', '', PARAM_TEXT),
            optional_param('sort', '', PARAM_ALPHANUMEXT)
        );
        $query->pluginvalues = self::read_plugin_values(
            $isagent,
            static fn(string $name, string $type, $default) => optional_param($name, $default, $type)
        );
        return $query;
    }

    /**
     * Read the query out of a list URL's query string.
     *
     * This is how the results fragment is told which list to rebuild. It takes
     * the whole query string rather than fifteen named arguments so that the
     * parameter surface cannot drift from the page's: whatever the filter bar
     * put in the URL is what arrives here, and it is cleaned by the same rules.
     *
     * Every value is passed through clean_param() explicitly. The string comes
     * from the client, so being a copy of a URL this plugin rendered is what it
     * is *meant* to be, never what it is *trusted* to be.
     *
     * @param string $querystring With or without a leading '?'.
     * @param bool   $isagent     Whether the viewer holds :viewall.
     * @return self
     */
    public static function from_query_string(string $querystring, bool $isagent): self {
        $raw = [];
        parse_str(ltrim($querystring, '?'), $raw);

        $text = static fn(string $key): string =>
            isset($raw[$key]) && is_scalar($raw[$key]) ? clean_param((string) $raw[$key], PARAM_TEXT) : '';
        $int = static fn(string $key, int $default): int =>
            isset($raw[$key]) && is_scalar($raw[$key]) ? clean_param((string) $raw[$key], PARAM_INT) : $default;
        $alnum = static fn(string $key, string $default): string =>
            isset($raw[$key]) && is_scalar($raw[$key])
                ? clean_param((string) $raw[$key], PARAM_ALPHANUMEXT)
                : $default;

        // Status arrives as the repeated status[] parameter, so it is an array
        // here. A scalar is accepted as a single value rather than discarded:
        // a hand-edited URL saying status=1 means something obvious.
        $statuses = [];
        if (isset($raw['status'])) {
            $statuses = is_array($raw['status']) ? $raw['status'] : [$raw['status']];
            $statuses = clean_param_array($statuses, PARAM_INT);
        }

        $query = new self(
            $isagent,
            $int('page', 0),
            $alnum('view', department::VIEW_ALL),
            $text('search'),
            $statuses,
            $int('priority', -1),
            $int('categoryid', 0),
            $int('departmentid', -1),
            $int('assigneeid', -1),
            $int('sla', 0),
            $text('created_from'),
            $text('created_to'),
            $text('updated_from'),
            $text('updated_to'),
            $alnum('sort', '')
        );
        $query->pluginvalues = self::read_plugin_values(
            $isagent,
            static fn(string $name, string $type, $default) =>
                isset($raw[$name]) && is_scalar($raw[$name]) ? clean_param((string) $raw[$name], $type) : $default
        );
        return $query;
    }

    /**
     * Read the plugin filter parameters (hook ticket_list_filters, params phase).
     *
     * Agents only, as every filter but search and status. Only values other
     * than their declared default are kept, so a request without plugin
     * filters carries none.
     *
     * @param bool     $isagent
     * @param callable $reader fn(string $name, string $type, mixed $default): mixed
     * @return array Name => value.
     */
    private static function read_plugin_values(bool $isagent, callable $reader): array {
        if (!$isagent) {
            return [];
        }
        $hook = \local_moodesk\hook\ticket_list_filters::for_params(self::RESERVED_PARAMS);
        \core\di::get(\core\hook\manager::class)->dispatch($hook);
        return \local_moodesk\hook\filter_extension::read_values($hook->get_declared(), $reader);
    }

    /**
     * Plugin filter values the request carries, name => value.
     *
     * @return array
     */
    public function get_plugin_values(): array {
        return $this->pluginvalues;
    }

    /**
     * The filter array the ticket service understands.
     *
     * Requesters carry only search and status: which tickets they may see is
     * decided by the service from their user id, not by anything here.
     *
     * @return array
     */
    public function to_filters(): array {
        $filters = [];

        // Search applies to both agents and requesters.
        if (trim($this->search) !== '') {
            $filters['search'] = trim($this->search);
        }

        if (!$this->isagent) {
            if (!empty($this->statuses)) {
                $filters['status'] = $this->statuses;
            }
            return $filters;
        }

        if (!empty($this->statuses)) {
            $filters['status'] = $this->statuses;
        }
        if ($this->priority >= 0) {
            $filters['priority'] = $this->priority;
        }
        if ($this->categoryid > 0) {
            $filters['categoryid'] = $this->categoryid;
        }
        if ($this->departmentid >= 0) {
            $filters['departmentid'] = $this->departmentid;
        }
        if ($this->assigneeid >= 0) {
            $filters['assigneeid'] = $this->assigneeid;
        }
        if ($this->sla === 1) {
            $filters['sla_breach'] = 1;
        }
        // The sla === 2 (warning) case is a PHP-level post-fetch filter; no DB clause.
        if ($this->get_created_from_ts() > 0) {
            $filters['created_from'] = $this->get_created_from_ts();
        }
        if ($this->get_created_to_ts() > 0) {
            $filters['created_to'] = $this->get_created_to_ts();
        }
        if ($this->get_updated_from_ts() > 0) {
            $filters['updated_from'] = $this->get_updated_from_ts();
        }
        if ($this->get_updated_to_ts() > 0) {
            $filters['updated_to'] = $this->get_updated_to_ts();
        }
        if (!empty($this->pluginvalues)) {
            $filters['plugin'] = $this->pluginvalues;
        }

        return $filters;
    }

    /**
     * Every filter currently in force, as URL parameters.
     *
     * This is what the chips dismiss out of, what pagination carries forward and
     * what the results region publishes as its own address. Page and sort are
     * deliberately absent: a chip that dropped the viewer back to page one would
     * be surprising, and the two callers that do want them add them themselves.
     *
     * @return array
     */
    public function to_active_params(): array {
        $params = [];

        if ($this->isagent) {
            $params['view'] = $this->view;
        }
        if (trim($this->search) !== '') {
            $params['search'] = $this->search;
        }

        if (!$this->isagent) {
            if (!empty($this->statuses)) {
                $params['status'] = $this->statuses;
            }
            return $params;
        }

        if (!empty($this->statuses)) {
            // Stays an array here; url_params::to_url() expands it into the
            // repeated status[] parameters moodle_url accepts.
            $params['status'] = $this->statuses;
        }
        if ($this->priority >= 0) {
            $params['priority'] = $this->priority;
        }
        if ($this->categoryid > 0) {
            $params['categoryid'] = $this->categoryid;
        }
        if ($this->departmentid >= 0) {
            $params['departmentid'] = $this->departmentid;
        }
        if ($this->assigneeid >= 0) {
            $params['assigneeid'] = $this->assigneeid;
        }
        // Only the two the list understands. An out-of-range value filters
        // nothing, so carrying it into chips and pagination URLs would put a
        // parameter on screen that does not mean anything.
        if (in_array($this->sla, [1, 2], true)) {
            $params['sla'] = $this->sla;
        }
        if ($this->sort !== '') {
            $params['sort'] = $this->sort;
        }
        if ($this->createdfrom !== '') {
            $params['created_from'] = $this->createdfrom;
        }
        if ($this->createdto !== '') {
            $params['created_to'] = $this->createdto;
        }
        if ($this->updatedfrom !== '') {
            $params['updated_from'] = $this->updatedfrom;
        }
        if ($this->updatedto !== '') {
            $params['updated_to'] = $this->updatedto;
        }

        // Plugin filters travel like the list's own; the list's keys win.
        return $params + $this->pluginvalues;
    }

    /**
     * The list URL the viewer is standing on, page included.
     *
     * The results region publishes this so a refresh rebuilds *this* list. It
     * cannot be read off window.location at refresh time: the detail drawer
     * pushes the open ticket's URL, so by then the address bar names a ticket
     * rather than the list behind it.
     *
     * @return \moodle_url
     */
    public function to_url(): \moodle_url {
        $params = $this->to_active_params();
        // Page zero is the default and is left out rather than spelled out.
        if ($this->page > 0) {
            $params['page'] = $this->page;
        }

        return \local_moodesk\util\url_params::to_url('/local/moodesk/index.php', $params);
    }

    /**
     * How many of the six secondary filters carry a value.
     *
     * These are the ones behind the "More filters" disclosure; on a phone that
     * group starts folded, so without this count an active filter would narrow
     * the list with nothing on screen to explain it. Each test mirrors the same
     * control's own "unset" value — the selects disagree on whether that is 0
     * or -1.
     *
     * @return int
     */
    public function count_secondary_filters(): int {
        return count(array_filter([
            $this->categoryid > 0,
            $this->departmentid >= 0,
            $this->assigneeid >= 0,
            $this->sla > 0,
            // The four date fields count once between them: they are one control
            // on screen, and reading "2" for a single range would be a lie.
            $this->createdfrom !== '' || $this->createdto !== ''
                || $this->updatedfrom !== '' || $this->updatedto !== '',
        ])) + count($this->pluginvalues);
    }

    /**
     * Whether the viewer holds :viewall.
     *
     * @return bool
     */
    public function is_agent(): bool {
        return $this->isagent;
    }

    /**
     * Zero-based page number.
     *
     * @return int
     */
    public function get_page(): int {
        return $this->page;
    }

    /**
     * The workspace view in force.
     *
     * @return string
     */
    public function get_view(): string {
        return $this->view;
    }

    /**
     * The free-text search term, as typed.
     *
     * @return string
     */
    public function get_search(): string {
        return $this->search;
    }

    /**
     * Selected status values; empty means every status.
     *
     * @return int[]
     */
    public function get_statuses(): array {
        return $this->statuses;
    }

    /**
     * Selected priority, or -1 for "any".
     *
     * @return int
     */
    public function get_priority(): int {
        return $this->priority;
    }

    /**
     * Selected category, or 0 for "any".
     *
     * @return int
     */
    public function get_category_id(): int {
        return $this->categoryid;
    }

    /**
     * Selected department, or -1 for "any".
     *
     * @return int
     */
    public function get_department_id(): int {
        return $this->departmentid;
    }

    /**
     * Selected assignee, or -1 for "any".
     *
     * @return int
     */
    public function get_assignee_id(): int {
        return $this->assigneeid;
    }

    /**
     * SLA filter: 0 off, 1 breached only, 2 warning only.
     *
     * @return int
     */
    public function get_sla(): int {
        return $this->sla;
    }

    /**
     * Created-from date as typed, or an empty string.
     *
     * @return string
     */
    public function get_created_from(): string {
        return $this->createdfrom;
    }

    /**
     * Created-to date as typed, or an empty string.
     *
     * @return string
     */
    public function get_created_to(): string {
        return $this->createdto;
    }

    /**
     * Updated-from date as typed, or an empty string.
     *
     * @return string
     */
    public function get_updated_from(): string {
        return $this->updatedfrom;
    }

    /**
     * Updated-to date as typed, or an empty string.
     *
     * @return string
     */
    public function get_updated_to(): string {
        return $this->updatedto;
    }

    /**
     * Sort key, or an empty string for the default order.
     *
     * @return string
     */
    public function get_sort(): string {
        return $this->sort;
    }

    /**
     * The created-from date as a timestamp.
     *
     * @return int Unix timestamp, or 0 when the field is unset or unparseable.
     */
    public function get_created_from_ts(): int {
        return $this->createdfrom !== '' ? (int) strtotime($this->createdfrom) : 0;
    }

    /**
     * The created-to date as a timestamp.
     *
     * @return int Unix timestamp, or 0 when the field is unset or unparseable.
     */
    public function get_created_to_ts(): int {
        return $this->createdto !== '' ? (int) strtotime($this->createdto) : 0;
    }

    /**
     * The updated-from date as a timestamp.
     *
     * @return int Unix timestamp, or 0 when the field is unset or unparseable.
     */
    public function get_updated_from_ts(): int {
        return $this->updatedfrom !== '' ? (int) strtotime($this->updatedfrom) : 0;
    }

    /**
     * The updated-to date as a timestamp.
     *
     * @return int Unix timestamp, or 0 when the field is unset or unparseable.
     */
    public function get_updated_to_ts(): int {
        return $this->updatedto !== '' ? (int) strtotime($this->updatedto) : 0;
    }
}
