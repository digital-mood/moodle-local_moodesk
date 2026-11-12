# mooDesk

mooDesk is a Moodle local plugin that adds an internal help desk to a Moodle site. Tickets, replies, attachments and audit history live in the Moodle database alongside the rest of the site's records. Authentication, capabilities, notifications and privacy use the standard Moodle APIs. There is no external runtime and no parallel authentication path. Since v2.0.0 every mooDesk screen renders inside a standalone application shell (built on Moodle's `embedded` page layout), so the product presents as its own app while Moodle remains the underlying substrate.

This README describes the plugin, its requirements and every page it exposes. The plugin needs no licence and makes no outbound call.

mooDesk is an independent third-party plugin and is not affiliated with or endorsed by Moodle HQ. Moodle® is a registered trademark of Moodle Pty Ltd.

---

## Requirements

| Component | Minimum | Tested |
|---|---|---|
| Moodle | 4.5 LTS (`2024100700`) | 4.5; 5.0 / 5.1 / 5.2 |
| PHP | 8.1 | 8.2, 8.3, 8.4 |
| MariaDB | 10.6.7 | 10.11 |
| MySQL | 8.4 | 8.4 |
| PostgreSQL | 13 | 16 |

Every release is tested on MariaDB, MySQL and PostgreSQL. MySQL is tested on 8.4 only, the version Moodle 5.x requires.

The minimum supported Moodle is **4.5 LTS** (4.1–4.4 are end-of-life). CI validates 4.5 LTS and the current 5.x line (5.0 / 5.1 / 5.2). (Note: there is no Moodle 4.6 — the release line runs 4.5 → 5.0 → 5.1 → 5.2.)

---

## Installation

1. Download the release ZIP and upload it via **Site administration → Plugins → Install plugins**, or copy the unzipped directory to `local/moodesk/` on the Moodle server.
2. Sign in as a site administrator and open **Site administration → Notifications**.
3. Confirm the upgrade. Moodle creates the required database tables and synchronises plugin capabilities automatically.
4. Configure the plugin under **Site administration → Local plugins → moodesk** (defaults are sane and safe to keep).
5. Assign the **mooDesk Agent** role the install created (system context) to the operators that will work the queue — from mooDesk's *Agents → Add agent* screen or from Moodle's role screens (see [Roles and permissions](#roles-and-permissions)). The plugin allows up to 2 active agents.

---

## What it includes

- Ticket submission, queue, public replies, status transitions, resolution and closure.
- Departments as routing queues, with per-agent membership.
- Categories and priorities.
- SLA thresholds per priority (first response and resolution), with status indicators and breach notifications.
- Internal notes, visible only to agents, never sent to the requester.
- Bulk actions on the queue (assign, change status, close), saved filter views, and a detail drawer that opens a ticket over the list.
- Agent dashboard with KPI cards and status / priority / department / aging / trend charts.
- System Health (`health.php`, capability `viewhealth`): the failures the plugin absorbed in the last 24 hours by source, with acknowledge / resolve, and the agent count against the limit.
- Agent management from inside mooDesk: a directory of support agents, onboarding, and offboarding with automatic requeueing of the removed agent's open tickets — up to 2 active agents.
- Auto-close of resolved tickets after a configurable delay.
- Email notifications for new tickets, replies, assignment and SLA breaches, sent through Moodle's Message API.
- File attachments on tickets and replies (configurable size and count).
- Knowledge base: article authoring (draft / published / archived, everyone or agents-only), a reader with browse-by-category and search, and *Suggested articles* on the New Ticket form that offers an answer before the ticket is sent.
- Privacy API provider for data export and erasure, covering every table that holds personal data.
- English language pack. Translations are distributed through Moodle's AMOS.

---

## Configuration

Settings live under **Site administration → Local plugins → moodesk**, in these groups:

- **General** — default category, attachment limits, `kb_enabled` (shows or hides the knowledge base screens and the suggested articles on the New Ticket form).
- **Notifications** — toggle each notification type.
- **Automation** — auto-close of resolved tickets after N days (`0` disables), incident retention.
- **SLA** — first-response and resolution hours per priority (`0` disables a priority).

The step-by-step quick start is at <https://support.moodesk.io/getting-started>.

---

## Roles and permissions

Capabilities are declared under the `local/moodesk:` prefix and granted through the standard Moodle role system. The install seeds one role — **mooDesk Agent** — and leaves every other grant to the administrator.

The capability set:

| Capability | Granted to |
|---|---|
| `local/moodesk:submit` | Authenticated users (default) — open a new ticket |
| `local/moodesk:viewown` | Authenticated users (default) — see own tickets |
| `local/moodesk:agent` | mooDesk agents — marks the user as assignable |
| `local/moodesk:viewall` | mooDesk agents — see every ticket in the queue |
| `local/moodesk:reply` | mooDesk agents — post public replies |
| `local/moodesk:addnote` | mooDesk agents — post internal notes |
| `local/moodesk:manage` | mooDesk agents — change status, priority, assignee |
| `local/moodesk:managedepartment` | mooDesk managers — create and edit departments |
| `local/moodesk:manageagents` | mooDesk managers — add and remove agents and manage their membership |
| `local/moodesk:managecategory` | mooDesk managers — create and edit categories |
| `local/moodesk:managesettings` | Site administrators — manage advanced plugin settings |
| `local/moodesk:viewhealth` | mooDesk managers — the System Health page |
| `local/moodesk:viewkb` | Authenticated users (default) — read published knowledge base articles |
| `local/moodesk:editkb` | mooDesk agents — create and edit articles, drafts and published alike; never publish them |
| `local/moodesk:managekb` | mooDesk managers (`manager` archetype by default) — publish, unpublish, archive, restore and delete articles, and change who an article is for |

The install seeds a **mooDesk Agent** role (`mhd_agent`, System context only) holding `:agent`, `:viewall`, `:reply`, `:addnote`, `:manage` and `:editkb`; mooDesk's *Add agent* screen assigns and removes that role. Management capabilities go to a separate **mooDesk Manager** role or to the built-in `manager` archetype. A user made an agent through any other role is still an agent (and still counts towards the limit of 2), but mooDesk will not remove that role — Moodle's role screens do.

---

## Entry points

| URL | Purpose |
|---|---|
| `/local/moodesk/index.php` | Ticket queue (requester and agent views) |
| `/local/moodesk/submit.php` | Submit a new ticket |
| `/local/moodesk/view.php?id={ticketid}` | Ticket detail and thread |
| `/local/moodesk/dashboard.php` | Agent dashboard |
| `/local/moodesk/agents.php` | Agents directory |
| `/local/moodesk/add_agent.php` | Onboard an agent |
| `/local/moodesk/offboard_agent.php` | Offboard an agent (+ reassign their open tickets) |
| `/local/moodesk/departments.php` | Department list |
| `/local/moodesk/department_edit.php` | Create or edit a department |
| `/local/moodesk/department_members.php` | Manage department membership |
| `/local/moodesk/categories.php` · `category_edit.php` | Category list / editor |
| `/local/moodesk/kb.php` | Knowledge base: browse by category and search |
| `/local/moodesk/kb_article.php?id={articleid}` | Read one article (editors and managers also see its status and actions) |
| `/local/moodesk/kb_articles.php` | Articles management list (editors and managers) |
| `/local/moodesk/kb_article_edit.php` | Create or edit an article |
| `/local/moodesk/health.php` | System Health (`viewhealth`) |

Each page is protected by `require_login()` and the appropriate capability check.

---

## Scheduled tasks

Every task is registered in `db/tasks.php` and managed through **Site administration → Server → Scheduled tasks**.

| Task | Default schedule | Purpose |
|---|---|---|
| `check_sla_breaches` | Every hour | Evaluate open tickets against SLA thresholds and flag breaches |
| `autoclose_resolved_tickets` | Daily 02:00 | Close tickets resolved longer than the configured threshold |
| `dispatch_incident_alerts` | Every 5 minutes | Email operators about new System Health incidents |
| `purge_incidents` | Daily 05:40 | Retention of System Health incidents |

---

## Network and privacy

The plugin makes no outbound request of its own. Notifications go through Moodle's Message API and the site's own mail settings.

## Privacy and GDPR

moodesk integrates with the Moodle Privacy API. Personal data export and erasure requests are handled through **Site administration → Users → Privacy and policies → Data requests** in the standard Moodle workflow. The plugin reports on its data, exports user-owned tickets, replies, ticket history and department membership, and anonymises personal data on erasure while preserving aggregate operational records.

---

## Languages

This package ships the English (`en`) language pack only. Translations are contributed through AMOS (<https://lang.moodle.org>) and installed with Moodle's language pack tool. All plugin strings flow through Moodle's standard string API, so adding a new locale is a translation task with no code change required.

---

## License

Plugin source code is distributed under the GNU General Public License version 3 or later. See [`LICENSE`](LICENSE) for the full text.

---

## Support

Documentation: [support.moodesk.io](https://support.moodesk.io). Bug reports, feature requests and documentation issues: [GitHub Issues on `digital-mood/moodesk-support`](https://github.com/digital-mood/moodesk-support/issues/new/choose), a public repository with issue forms and no source code. Issues are public: never post credentials, personal data or production dumps; report a security vulnerability as the [security policy](https://github.com/digital-mood/moodesk-support/blob/main/SECURITY.md) says.

Copyright © 2026 digitalMood LLC. mooDesk is an independent third-party plugin and is not affiliated with or endorsed by Moodle HQ. Moodle® is a registered trademark of Moodle Pty Ltd.
