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
use local_moodesk\domain\ticket;
use local_moodesk\service\sla_service;
use local_moodesk\service\ticket_service;

/**
 * The part of the ticket list that answers a query: rows, count and pagination.
 *
 * Everything else on the list screen — the filter bar, the active chips, the
 * saved views, the bulk toolbar — is a function of the URL and the viewer's
 * capabilities, not of what the query returned, and none of it belongs here.
 * The line this class draws is exactly the line the results region draws in
 * the markup, and that is the point: what depends on the result is what gets
 * rebuilt when the result may have changed.
 *
 * One implementation, two callers. index.php renders it inside the full page;
 * the ticket_list_results fragment renders it on its own after a mutation. Both
 * go through {@see get_context()} into the same template, so the initial paint
 * and every refresh after it cannot drift apart — there is no second rendering
 * path for them to drift into.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ticket_list_results {
    /** @var string The template this class renders, and the partial index.php includes. */
    public const TEMPLATE = 'local_moodesk/ticket_list_results';

    /** @var int Rows read per query while the "warning only" filter walks a view. */
    public const WARNING_CHUNK = 500;

    /** @var int|null A smaller block for tests, so a walk spans several without hundreds of rows. */
    private static ?int $warningchunkfortesting = null;

    /** @var ticket_list_query What is being asked for. */
    private ticket_list_query $query;

    /** @var int Viewer. */
    private int $userid;

    /** @var bool Whether the viewer holds :manage — decides the bulk affordances. */
    private bool $canbulk;

    /** @var array Rendered row data, in display order. */
    private array $rows;

    /** @var int Total matching tickets, before pagination. */
    private int $total;

    /**
     * Hold a query and the rows it already produced.
     *
     * @param ticket_list_query $query
     * @param int               $userid
     * @param bool              $canbulk
     * @param array             $rows
     * @param int               $total
     */
    private function __construct(
        ticket_list_query $query,
        int $userid,
        bool $canbulk,
        array $rows,
        int $total
    ) {
        $this->query = $query;
        $this->userid = $userid;
        $this->canbulk = $canbulk;
        $this->rows = $rows;
        $this->total = $total;
    }

    /**
     * Run the query and shape its rows.
     *
     * @param ticket_list_query $query
     * @param int               $userid  The viewer.
     * @param bool              $canbulk Whether the viewer holds :manage.
     * @return self
     */
    public static function create(ticket_list_query $query, int $userid, bool $canbulk): self {
        global $DB, $OUTPUT;

        $isagent = $query->is_agent();
        $service = ticket_service::create();
        $filters = $query->to_filters();
        $slaservice = $isagent ? new sla_service() : null;

        // One instant for every SLA evaluation of this request, so a ticket cannot
        // change state between being filtered and being drawn.
        $now = time();

        if ($isagent && $query->get_sla() === 2) {
            // The warning filter has no column in the database: it is computed
            // from the deadlines. The whole view is walked, not one page of it.
            [$tickets, $total] = self::warning_page(
                $service,
                $slaservice,
                $userid,
                $filters,
                $query->get_view(),
                $query->get_page(),
                $now
            );
        } else {
            if ($isagent) {
                $result = $service->get_all_tickets($userid, $filters, $query->get_page(), $query->get_view());
            } else {
                $result = $service->get_tickets_for_requester($userid, $filters, $query->get_page());
            }
            $tickets = $result['tickets'];
            $total = $result['total'];
        }

        // The SLA evaluation below resolves each ticket's department for its
        // overrides. One query for the page's departments, rather than one per
        // distinct department as the rows are walked.
        if ($slaservice !== null) {
            self::prime_sla_departments($slaservice, $tickets);
        }

        // Status and priority label maps.
        $statuslabels = ticket::status_labels();
        $prioritylabels = ticket::priority_labels();

        // Batch-load requester and assignee user records (avoid N+1).
        $requesterids = array_unique(array_column($tickets, 'userid'));
        $assigneeids = array_unique(array_filter(array_column($tickets, 'assigneeid')));
        $alluserids = array_values(array_unique(array_merge($requesterids, $assigneeids)));

        $usermap = [];
        if (!empty($alluserids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($alluserids);
            $userrows = $DB->get_records_sql(
                'SELECT id, firstname, lastname, firstnamephonetic, lastnamephonetic,
                        middlename, alternatename, picture, imagealt, email
                   FROM {user}
                  WHERE id ' . $insql,
                $inparams
            );
            foreach ($userrows as $u) {
                $usermap[$u->id] = $u;
            }
        }

        $rows = [];
        foreach ($tickets as $t) {
            $requesteruser = $usermap[$t->userid] ?? null;
            $requesterfullname = $requesteruser ? fullname($requesteruser) : '';
            $requesteravatar = '';
            if ($requesteruser) {
                $userpic = new \user_picture($requesteruser);
                // 36 like every other caller in the plugin. The frame in
                // components/_avatar.scss is what decides the rendered size, but
                // the request should not disagree with the rest of the product:
                // core picks the image file it serves from this number, and for a
                // user with no photograph it stamps it into the `size-N` class on
                // the initials span.
                $userpic->size = 36;
                $requesteravatar = $OUTPUT->render($userpic);
            }

            $assigneename = '';
            if (!empty($t->assigneeid) && isset($usermap[$t->assigneeid])) {
                $assigneename = fullname($usermap[$t->assigneeid]);
            }
            if ($assigneename === '') {
                $assigneename = get_string('unassigned', 'local_moodesk');
            }

            // SLA status (agents only — compute real-time, not stored flag).
            $slastatus = ($slaservice !== null) ? $slaservice->get_status($t, $now) : 'none';
            $slatimelabel = ($slaservice !== null) ? $slaservice->get_time_label($t, $now) : '';

            // PRO-2: urgency score for sort-by-SLA (breach > warning > rest).
            $slaurgencyscore = match ($slastatus) {
                'breach'  => 3,
                'warning' => 2,
                default   => 0,
            };

            $rows[] = [
                'id'                => $t->id,
                'requesteravatar'   => $requesteravatar,
                'requesterfullname' => $requesterfullname,
                'subject'           => format_string($t->subject),
                'statuslabel'       => $statuslabels[(int) $t->status] ?? $t->status,
                'status'            => (int) $t->status,
                'prioritylabel'     => $prioritylabels[(int) $t->priority] ?? $t->priority,
                'priority'          => (int) $t->priority,
                'categoryname'      => format_string($t->categoryname ?? ''),
                'departmentname'    => format_string($t->departmentname ?? ''),
                'hasdepartment'     => !empty($t->departmentname),
                'assigneename'      => $assigneename,
                'timecreated'       => userdate($t->timecreated),
                'timeupdated'       => userdate($t->timemodified),
                'isunassigned'      => empty($t->assigneeid),
                'sla_breach'        => ($slastatus === 'breach'),
                'sla_warning'       => ($slastatus === 'warning'),
                'sla_visible'       => ($slastatus === 'breach' || $slastatus === 'warning'),
                'sla_timelabel'     => $slatimelabel,
                'sla_urgency_score' => $slaurgencyscore,
                'viewurl'           => (new \moodle_url('/local/moodesk/view.php', ['id' => $t->id]))->out(false),
            ];
        }

        // PRO-2: sort by SLA urgency (breach first, then warning, then rest).
        if ($isagent && $query->get_sort() === 'sla') {
            usort($rows, static function (array $a, array $b): int {
                return $b['sla_urgency_score'] <=> $a['sla_urgency_score'];
            });
        }

        return new self($query, $userid, $canbulk, $rows, $total);
    }

    /**
     * One page of the tickets in SLA warning, and how many there are in the view.
     *
     * The view is read in blocks of WARNING_CHUNK rows through the same service
     * call the list uses, so access, view and filters are the list's own. Only
     * statuses the SLA is evaluated for are asked for (intersected with the
     * user's status filter): resolved and closed tickets are never in warning.
     * Every block is evaluated against one $now; only the rows that fall on the
     * requested page are kept, so memory does not grow with the view. The list
     * order has a tiebreaker on id, which is what keeps a row from appearing in
     * two blocks or in none.
     *
     * @param ticket_service $service
     * @param sla_service    $slaservice
     * @param int            $userid
     * @param array          $filters
     * @param string         $view
     * @param int            $page
     * @param int            $now
     * @return array{0: \stdClass[], 1: int} The page's tickets and the total.
     */
    private static function warning_page(
        ticket_service $service,
        sla_service $slaservice,
        int $userid,
        array $filters,
        string $view,
        int $page,
        int $now
    ): array {
        $statuses = sla_service::evaluated_statuses();
        if (!empty($filters['status'])) {
            $statuses = array_values(array_intersect($statuses, array_map('intval', (array) $filters['status'])));
            if (empty($statuses)) {
                return [[], 0];
            }
        }
        $filters['status'] = $statuses;

        $from = $page * ticket_service::PAGE_SIZE;
        $to = $from + ticket_service::PAGE_SIZE;
        $found = 0;
        $tickets = [];

        $chunk = self::$warningchunkfortesting ?? self::WARNING_CHUNK;
        for ($offset = 0;; $offset += $chunk) {
            $block = $service->get_all_tickets_range($userid, $filters, $offset, $chunk, $view)['tickets'];
            self::prime_sla_departments($slaservice, $block);
            foreach ($block as $t) {
                if ($slaservice->get_status($t, $now) !== 'warning') {
                    continue;
                }
                if ($found >= $from && $found < $to) {
                    $tickets[] = $t;
                }
                $found++;
            }
            if (count($block) < $chunk) {
                break;
            }
        }

        return [$tickets, $found];
    }

    /**
     * Use a smaller block for the "warning only" walk. Tests only.
     *
     * @param int|null $chunk Rows per block, or null for WARNING_CHUNK.
     */
    public static function set_warning_chunk_for_testing(?int $chunk): void {
        if (!PHPUNIT_TEST) {
            throw new \coding_exception('set_warning_chunk_for_testing() is for PHPUnit only.');
        }
        self::$warningchunkfortesting = $chunk;
    }

    /**
     * Load the departments of a set of tickets into the SLA service in one go.
     *
     * @param sla_service $slaservice
     * @param \stdClass[] $tickets Rows carrying departmentid.
     */
    private static function prime_sla_departments(sla_service $slaservice, array $tickets): void {
        $slaservice->prime_departments(array_unique(array_filter(array_column($tickets, 'departmentid'))));
    }

    /**
     * The template context for the results region.
     *
     * @return array
     */
    public function get_context(): array {
        global $OUTPUT;

        $isagent = $this->query->is_agent();
        $view = $this->query->get_view();
        $activeparams = $this->query->to_active_params();

        // PRO-1: view-specific empty state strings.
        $viewemptytitles = [
            department::VIEW_MINE       => get_string('empty_mine_title', 'local_moodesk'),
            department::VIEW_UNASSIGNED => get_string('empty_unassigned_title', 'local_moodesk'),
            department::VIEW_DEPARTMENT => get_string('empty_department_title', 'local_moodesk'),
        ];
        $viewemptydescs = [
            department::VIEW_MINE       => get_string('empty_mine_desc', 'local_moodesk'),
            department::VIEW_UNASSIGNED => get_string('empty_unassigned_desc', 'local_moodesk'),
            department::VIEW_DEPARTMENT => get_string('empty_department_desc', 'local_moodesk'),
        ];

        // The list controls bar holds select-all and the SLA sort link. It
        // renders only when it has one of them to show: no rows means nothing to
        // select or sort, and a viewer who cannot bulk-edit a list already
        // sorted by SLA has an empty bar.
        $showlistcontrols = $isagent
            && !empty($this->rows)
            && ($this->canbulk || $this->query->get_sort() !== 'sla');

        $paginationbar = '';
        if ($this->total > ticket_service::PAGE_SIZE) {
            // Carry every active filter (search, status, priority, dates, sort,
            // sla, view…) through pagination so deep links and "page 2" don't
            // silently drop the user's filter state.
            $pageurl = \local_moodesk\util\url_params::to_url('/local/moodesk/index.php', $activeparams);
            $pbar = new \paging_bar(
                $this->total,
                $this->query->get_page(),
                ticket_service::PAGE_SIZE,
                $pageurl
            );
            $paginationbar = $OUTPUT->render($pbar);
        }

        // What a row's inline status control offers. Built here rather than
        // shared with the filter bar's option list, which the two used to do
        // only because index.php happened to build both: the filter bar is
        // outside this region and does not travel with a refresh, so a row
        // rebuilt on its own would have offered nothing.
        $quickstatusoptions = [];
        foreach (ticket::status_labels() as $value => $label) {
            $quickstatusoptions[] = ['value' => $value, 'label' => $label];
        }

        return [
            'tickets'          => $this->rows,
            'quickstatusoptions' => $quickstatusoptions,
            'isagent'          => $isagent,
            'canbulk'          => $this->canbulk,
            'showlistcontrols' => $showlistcontrols,
            'total'            => $this->total,
            'empty'            => empty($this->rows),
            'submiturl'        => (new \moodle_url('/local/moodesk/submit.php'))->out(false),
            'paginationbar'    => $paginationbar,
            // PRO-2: SLA sort.
            'issortingbysla'   => ($this->query->get_sort() === 'sla'),
            'sortslaurl'       => $isagent
                ? \local_moodesk\util\url_params::to_url(
                    '/local/moodesk/index.php',
                    array_merge($activeparams, ['sort' => 'sla'])
                )->out(false)
                : '',
            // PRO-1: view-specific empty states.
            'emptystatetitle'  => $viewemptytitles[$view] ?? get_string('ticket_list_empty_title', 'local_moodesk'),
            'emptystatedesc'   => $viewemptydescs[$view] ?? get_string('ticket_list_empty_desc', 'local_moodesk'),
            // The address of the list these results belong to. The refresh module
            // reads it off the region rather than off window.location, which by
            // then may name an open ticket rather than the list behind it.
            'listurl'          => $this->query->to_url()->out(false),
        ];
    }

    /**
     * Render the results region on its own — the fragment's path.
     *
     * @param \renderer_base $output
     * @return string
     */
    public function render(\renderer_base $output): string {
        return $output->render_from_template(self::TEMPLATE, $this->get_context());
    }

    /**
     * Total matching tickets, before pagination.
     *
     * @return int
     */
    public function get_total(): int {
        return $this->total;
    }
}
