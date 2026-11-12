# Changelog

All notable changes to moodesk are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

This file is the **canonical** changelog and ships inside the distributable
package. The copy at the repository root is a pointer to this one.

---

## [Unreleased]

Nothing yet.

---

## [3.0.3] — 2026-11-11

Packaging only, before the Moodle Marketplace submission. No functional, schema, setting or
language string change, and no upgrade step. Update both plugins: `local_moodeskpro` 3.0.3
requires this version.

### Changed

- **The package leaves out four development files**: `package.json`, `package-lock.json`,
  `Gruntfile.js` and `DEVELOPER.md`. They stay in the repository; the ZIP has the compiled
  `styles.css` and `amd/build/`, as before.
- **Tracker ids, private links and personal names are removed from comments and tests**, and from
  the released entries of this file. The technical explanations are kept, and `styles.css`
  compiles byte-identical. Email addresses in test data use reserved example domains.
- **`cli/seed_smoke_data.php` describes what it does**: demonstration data for test sites,
  never production. Its code and output are unchanged.
- **The build checks every file of the package.** It fails on those development files at the
  plugin root, on a task-tracker reference anywhere in the package, and on a real email address
  in `tests/`, and so does the verification of a received package.

### Upgrade notes

- Version 2026111100. The upgrade only records the new version: no table, field or setting
  changes.

---

## [3.0.2] — 2026-11-11

Update both plugins. This version works with `local_moodeskpro` 3.0.1, except that the add-on's
operator alerts about rejected inbound email are not sent until the add-on is updated to 3.0.2;
the preference for those alerts is kept.

### Changed

- **System Health shows only the sources of the plugins installed.** The base declares its own
  (notifications and automation, scheduled tasks, knowledge base, interface) and another plugin
  declares its own through the new hook `health_sources`. Without the add-on, the page shows no
  *Webhooks* or *Email ingestion* card. Incidents of a source no installed plugin declares stay
  in the table, are not shown and do not alert; they show again when the plugin is back.
- **The message provider `email_ingestion_alert` moves to `local_moodeskpro`.** The upgrade
  copies the administrator's settings and each user's preference for it to the add-on, when the
  add-on is installed. On a site without the add-on the provider is retired and no longer appears
  in the notification preferences.
- **The agent texts name departments only**, unless a plugin names its groups: the hook
  `agent_groups` has an optional `set_group_type()`, and the add-on uses it for teams. The page
  for removing an agent asks the hook about that agent.
- The setting `email_alert_recipients` is now labelled *Alert recipients* and described as the
  recipients of operational incident alerts.
- The empty state of System Health names no source.
- `email_alert_service::send()` takes the provider and its component, and returns whether the
  message went out. Called without a provider, as the add-on 3.0.1 does, it sends nothing and
  says so with a developer debugging message.
- `incident::SOURCE_WEBHOOK` and `incident::SOURCE_EMAIL_INGESTION` are deprecated and no longer
  among `incident::sources()`; they stay for the add-on 3.0.1.

### Fixed

- The message provider `operational_alert` had no name string, so the notification preference
  pages showed `[[messageprovider:operational_alert]]`.
- The dashboard reads the time from Moodle's clock, which makes its cache test deterministic.

### Upgrade notes

- Version 2026111001 copies the `email_ingestion_alert` settings and preferences to
  `local_moodeskpro`, if it is installed, including whether the administrator turned it off. Version 2026111002 deletes `revocation_crl_url`,
  `revocation_enabled`, `revocation_list` and `revocation_updated_at` under `local_moodesk`,
  leftovers of 2.x that nothing reads since 3.0.0 (the add-on keeps its own).

---

## [3.0.1] — 2026-11-07

### Added

- **The hook `notification_decorate` can set the `Message-ID` of a ticket notification**
  (`set_message_id()`), passed to Moodle's mailer through the sender's custom headers. The add-on
  uses it to sign the id.

---

## [3.0.0] — 2026-11-07

### Changed

- **With `local_moodeskpro` no agent is refused.** A site over its capacity keeps granting agents, and
  *Add agent* is disabled only on a site without the add-on, which keeps its limit of 2. System Health
  says which applies.
- **Licensing moves to the `local_moodeskpro` add-on.** This plugin alone is Starter: 2 agents, no
  licence code, no *License status* page and no licence call. Pro and Enterprise need the add-on,
  and the licence key is entered again in its settings. At the agent limit, *Add agent* and the
  refusal state the limit and nothing else.
- **Agent supervision moves to the `local_moodeskpro` add-on,** at
  `/local/moodeskpro/agent_supervision.php`.
- **The REST API moves to the `local_moodeskpro` add-on,** with its tokens, rate limit, settings and
  the `:useapi` capability. Function names change from `local_moodesk_*` to `local_moodeskpro_*` and
  the service is now `moodeskpro_api`, so API clients change their calls and issue new tokens. The
  session endpoint for knowledge base search stays here; the merge picker's moves with ticket merge.
- **Outbound webhooks move to the `local_moodeskpro` add-on,** with their pages, delivery tasks,
  settings and the `:managewebhooks` capability, now `local/moodeskpro:managewebhooks`.
- **Automation rules move to the `local_moodeskpro` add-on,** with their pages and the
  `:manageautomations` capability, now `local/moodeskpro:manageautomations`. Auto-close stays here.
- **Satisfaction surveys move to the `local_moodeskpro` add-on.** The survey page is now
  `/local/moodeskpro/csat.php`, so links in invitations sent before 3.0.0 stop working, and the CSAT
  settings are entered again in the add-on.
- **Reports move to the `local_moodeskpro` add-on,** at `/local/moodeskpro/reports.php`, and this
  plugin keeps no locked teaser for them.
- **The dashboard's analytics move to the `local_moodeskpro` add-on:** the SLA gauge, agent workload,
  top categories and the CSAT tile. Hook `dashboard_panels` can now place a panel among the base's
  and add a tile.
- **Knowledge base analytics and "Was this article helpful?" move to the `local_moodeskpro` add-on.**
  The base keeps the search redaction: plugins get a search's words through the new hook
  `kb_search_recorded`, redacted and only when it found nothing. `:viewreports` is now
  `local/moodeskpro:viewreports`.
- **The agent's knowledge base tools and the global search area move to the `local_moodeskpro`
  add-on.** The `kb_article` search area changes component, so the index is rebuilt.
- **Adding and managing translations moves to the `local_moodeskpro` add-on,** at
  `/local/moodeskpro/kb_translate.php`, with the language notice, the switcher and the translations
  panel. Reading stays here: a group of translations still opens and collapses without the add-on.
- **The tables of the paid features move to the `local_moodeskpro` add-on,** as `local_moodeskpro_*`.
  This plugin stops declaring them and does not drop them: a site that upgrades keeps them, undeclared
  and without their data being carried over, and Moodle's schema check lists them as unexpected until
  a later release removes them.
- **Privacy of the paid features moves to the `local_moodeskpro` add-on.** Its provider exports and
  erases what those tables hold. The 2.x tables an upgraded site keeps stay covered here: where they
  exist, this plugin declares them, exports what they hold about a person under *Data from before
  3.0.0*, and erases it by the add-on's rules (`privacy\legacy_pro_data`). The new hook `user_erasing` hands over an erasure before the ticket
  and reply references are zeroed, and `snapshot_redactor` is shared.
- **The agent panel moves to the `local_moodeskpro` add-on,** at `/local/moodeskpro/agent_detail.php`.
  The directory links it through the new hook `agent_detail_link`, which replaces
  `agent_detail_sections`.
- **The Pro columns leave the schema of the base:** the team, merge and reply token columns of the
  ticket and the SLA columns of the department. They are not dropped, and nothing is migrated: a site
  that keeps them sees them in the schema check until the release after 3.0.0. On a site without the
  add-on a merged ticket is an ordinary closed ticket.
- **Teams move to the `local_moodeskpro` add-on,** with their pages and the `:manageteam` capability,
  now `local/moodeskpro:manageteam`. The base keeps `ticket_detail_sections`, which gains a properties
  slot, and adds the hooks `agent_directory_filters`, `agent_groups`, `agent_detail_sections` and
  `agent_revoking`. Saved views keep the filters plugins declare.
- **Custom fields move to the `local_moodeskpro` add-on,** with their pages at
  `/local/moodeskpro/custom_fields.php`. `ticket_detail_sections` gains a fields slot.
- **SLA per department moves to the `local_moodeskpro` add-on.** New hooks `sla_overrides` and
  `department_list_columns`. A site without the add-on uses the global SLA settings.
- **Ticket merge moves to the `local_moodeskpro` add-on,** with `/local/moodeskpro/merge.php`, the
  `:mergetickets` and `:revertmerge` capabilities, now `local/moodeskpro:*`, and the merge ordering
  setting, entered again in the add-on. The lock on a merged ticket is the add-on's: new hook
  `ticket_lock`, and `ticket_detail_sections` gains an actions slot. `ajax.php` offers any action it
  does not own to `ticket_actions` on reach alone, and each callback checks what it needs.
- **Email ingestion moves to the `local_moodeskpro` add-on,** with the bundled libraries, now under
  `local_moodeskpro\_vendor`, the settings, the reply probe and both tasks. `email_alert_service`
  stays for incident alerts. `ticket_detail_sections` gains a badges slot.
- **The upgrade prompt is gone,** with its template, `edition_service::get_upgrade_prompt_ctx()` and
  its strings, and `featurenotavailable` no longer asks anyone to upgrade.

### Added

- **Licence expiry reminders.** 30 and 7 days before a licence expires, the site
  administrators get a notification (email and popup, under its own provider,
  *mooDesk licence expiry reminders*) saying when it expires, that the site then runs as
  Starter without deleting any data, and that renewing means purchasing a new annual key.
  It carries a link to License status and no purchase link. A new daily scheduled task,
  *Send licence expiry reminders to site administrators*, runs at 03:45, after the
  revocation list refresh, so a licence revoked that night is not reminded.

  Each reminder goes out once per licence and stage. A licence is identified by its
  subscriber and expiry, so re-issuing it with agent blocks (same expiry) does not repeat the
  reminder, and a renewal (new expiry) starts a new cycle. If Moodle refuses the message for
  an administrator, the task fails and the stage is retried for all of them on the next run,
  three runs at most; after the third refused run the stage is closed and not retried again.
  A failed delivery is never silent: the refused runs appear in System Health as one warning
  incident (its count grows with each retry), and giving up adds an error incident; neither
  names a user. Once a stage is given up, License status adds a note under the expiry banner,
  for as long as that licence is current, that the reminder could not be delivered and what to
  check (outgoing mail and the administrators' notification preferences). The stored state is a hash of the licence identity and timestamps: no
  user ids and no customer name. Sites without a valid licence get nothing.

  The reminders and the banner below live in `local_moodeskpro`, with licensing.
- **Expiry banner on License status.** While a valid licence has 30 days or less left, the
  page says so above the licence details (a warning in the last 7 days).
- **Extension hooks for a separate add-on plugin (first set).** mooDesk now dispatches four
  hooks through Moodle's Hooks API: `resolve_edition` (the site's edition),
  `agent_capacity` (the agent limit and whether grants over it are refused),
  `shell_notices` (notices above the content of every mooDesk page) and
  `navigation_items` (entries in the mooDesk navigation). `local_moodeskpro` listens to them.
- **Extension hooks for the ticket screens (second set).** `ticket_detail_sections` (cards in
  the ticket rail, tools in the reply composer and notices above the conversation),
  `ticket_form_extend` (fields of the New ticket form: add, validate, save) and
  `ticket_actions` (ticket actions mooDesk does not know, from the page and from the
  drawer). `local_moodeskpro` listens to them.
- **Extension hooks for queries, writes and notifications (third set).**
  `ticket_list_filters` and `dashboard_filters` (a filter parameter the page keeps, a control
  in the filter bar and an `EXISTS` condition on the query), `before_ticket_write` (refuse a
  write to a ticket), `thread_sources` (other tickets whose replies a conversation includes)
  and `notification_decorate` (the Reply-To of a ticket notification). `local_moodeskpro` listens to them.
- **Extension hooks for panels and forms (fourth set), and three knowledge base events.**
  `dashboard_panels`, `health_panels` and `kb_article_view_extend` (sections after the
  page's own), `department_form_extend` (fields of the department form: add, validate, load,
  save). `local_moodeskpro` listens to them. The new events go to the standard log on every edition:
  *Knowledge base article viewed*, *Knowledge base searched* (how many articles it found, never
  the search words) and *Knowledge base article linked in a reply*. Those log entries name the
  user, unlike the anonymous analytics, so the privacy provider declares the log stores for them.

### Changed

Package changes for the Moodle Marketplace submission guidelines. No feature changes.

- **The package carries a `LICENSE` file** with the full GPL v3 text, and the readme
  links to it, to the online documentation and to the per-edition feature list instead of
  repository paths that are not in the package.
- **English only in the package.** The Spanish pack stays in the repository, still checked
  key for key against English, and reaches sites through Moodle's AMOS instead. Until it is
  published there, a site set to Spanish shows mooDesk in English.
- **Standard Moodle licence header** on every AMD module and Mustache template.
- **`$plugin->supported` declares Moodle 4.5 to 5.2**, the range CI tests.
- **MySQL 8.4 is a supported database again.** The PHPUnit matrix tests it on Moodle 4.5
  and 5.2, next to MariaDB and PostgreSQL, and the readme lists it.

### Fixed

- **The agent directory's warning about agents with no membership tells the truth.** It said they
  could not be assigned any ticket. They can be assigned tickets with no department.
- **A reply by email no longer opens a duplicate ticket because of a long thread.** Only the first id
  of `References` and `In-Reply-To` was read, so a reply without a token whose match sat further down
  the chain became a new ticket. All of them are read now.
- **A reply token is found wherever it sits in the recipients.** Only the first address of each
  recipient header was scanned, so a reply to all with the token second lost it.
- **Automatic mail is recognised in more forms.** `X-Auto-Response-Suppress` lists and `Auto-Submitted`
  with parameters no longer pass as a person's message, which could open tickets from out-of-office
  replies.
- **Privacy API: outbound webhooks are declared as an external location.** A delivery
  sends the ticket subject, the requester and assignee user ids and, for a field change,
  the old and new values (an assignee is a user id) to the URL an administrator configured.
  The queue and log tables that keep a copy were declared; what leaves the site was not.
- **Privacy API: knowledge base events are declared.** `local_moodesk_kb_event` keeps the
  search words of searches that found nothing, redacted and linked to no user. It was
  documented as holding no personal data; it is now declared, and still cannot be
  exported or erased per user because nothing ties a row to one.
- **The reply form reads the posted message through `data_submitted()`** instead of
  `$_POST`. The check that refuses an array where text is expected is unchanged.
- **`cli/seed_smoke_data.php` gives its test users a random password** instead of a fixed
  one written in the script.
- **System Health counts incidents per source in a fixed order.** The query grouped without
  ordering, so MySQL 8 returned the sources in a different order from MariaDB and PostgreSQL.
  Found by the new MySQL leg.
- **This changelog no longer quotes internal task tracker ids.**


### Upgrade notes

- **REST API tokens issued before 3.0.0 stop working.** The upgrade removes the old `moodesk_api`
  service and its tokens. On a site with `local_moodeskpro`, issue new tokens on the *API tokens*
  page and change REST calls to the `local_moodeskpro_*` function names.
- **Satisfaction survey links change.** The survey page is now `/local/moodeskpro/csat.php`, so links
  in invitations sent before 3.0.0 stop working. Invitations sent after the upgrade use the new
  address.
- **Email ingestion is configured again in the add-on.** Enter the mailbox password and the email
  settings in `local_moodeskpro`. Polling stays off until then. Replies to notifications sent before
  3.0.0 are no longer matched to their ticket, because the add-on creates a new signing key.
- **Merge ordering and other moved settings** return to their defaults until they are set in the
  add-on.

---

## [2.39.1] — 2026-10-23

A privacy maintenance release. It changes one request header and the texts that describe it;
nothing else. No database change, no upgrade step, no change to licences, editions, the
revocation list format or the scheduled task.

### Fixed

- **The revocation list download no longer sends the site URL.** `refresh_revocation_list`
  fetched `revocations.json` and its `.sig` through Moodle's `\curl`, which sends
  `core_useragent::get_moodlebot_useragent()` unless told otherwise:
  `MoodleBot/<major> (+wwwroot)`. Every install therefore told the licence host its site URL
  once a day, while the readme, the setting description and the admin guide said the request
  carried no site data (audit C5). Both GETs now set `CURLOPT_USERAGENT` to the fixed
  `moodesk-crl/2` (`revocation_service::FETCH_USER_AGENT`), identical on every install. It has to
  be the curl option: a `User-Agent:` line given to `setHeader()` is replaced by Moodle. TLS
  verification, timeouts, the redirect limit, signature checks, anti-rollback, the fail-closed
  behaviour on a failed download and the 03:30 schedule are unchanged.
  The request still lets the licence host see the IP address it comes from (the server's, or
  that of its proxy).
- **No text claims the download sends no personal data any more.** The `revocation_enabled`
  setting description (en, es), the readme's *Network and privacy*, the privacy provider's
  comment, `db/tasks.php` and the admin guide now say what the request does not send (licence
  key, user records, ticket content, site URL) and that the source IP is visible to the host.

Out of scope, tracked separately: the webhook User-Agent does not match the documented one, and
`license_sync_service` would send Moodle's default User-Agent (it ships disabled,
`SYNC_ENABLED = false`).

---

## [2.39.0] — 2026-10-17

### Changed

- **Licences follow contract v2 (C2-1a; `docs/license-contract-v2.md`).** A licence is now accepted
  only after its signature and site checks pass **and** it is a v2 licence:
  - `cv` is the integer 2;
  - `tier` is `pro` or `enterprise`;
  - `jti` is a lowercase UUID v4;
  - `max_agents` is an allowed value for its tier: Pro 10, 15, 20 or 25; Enterprise 50 to 1000 in
    steps of 25.

  A licence issued before C2 is refused as *contract_unsupported*, and one with any other capacity
  as *capacity_invalid*. In both cases the site runs as Starter and License status says why. A Pro
  licence can never carry an Enterprise capacity.

  **Breaking for any site holding a licence issued before C2.** There are no customers with such a
  licence. Staging's licence must be replaced with a v2 licence, through
  `docs/staging-c2-licence-transition.md`, before this code is deployed there.

  A v2 licence is also accepted by 2.38.1, so a site can paste it before upgrading. 2.38.1 keeps
  its own agent limits until the upgrade.
- **Agent capacity and edition rules follow contract v2 (C2-1b).**
  - **The licence's `max_agents` is the agent capacity**, enforced when an agent is added from
    mooDesk. Starter (no valid licence) is 2. Pro includes 10 and Enterprise 50, each with blocks up
    to 25 and 1000. **No edition is unlimited any more:** Enterprise without a valid capacity is
    refused, as above. A forced test edition never gets more than the licence.
  - **The OEM pin (`edition_dist.php`) and the development override (`moodesk_LOCAL_EDITION`) can
    only lower the edition**, never raise it above the licence, and without a valid licence the site
    is Starter whatever they say (decision O-2). License status and System Health say when one of
    them lowered the edition or was ignored. Inside a PHPUnit run the override keeps its old,
    unclamped behaviour, because the CI legs use it to choose their edition.
  - **License status and System Health show how the capacity is made up**, for example
    `12 / 15 agents (10 included + 1 block)`. A site over its capacity keeps every agent and shows
    *Agent limit exceeded* with "no agent has been removed".
  - **At the capacity, the refusal and the *Add agent* notice name the way out** for the plan: get a
    Pro or Enterprise licence, add an agent block, move to Enterprise, or contact us above 1000
    agents.
  - The "Unlimited" label and the "License claim ignored" note are gone, and so is
    `entitlement_service::agent_claim()`. The feature prompt no longer says to change the edition in
    the plugin settings: the licence decides it. The Spanish agent-limit strings use neutral Spanish.
- **The Spanish language pack uses neutral Spanish throughout.** Seven strings still used the
  Rioplatense *voseo* (for example *Dejá*, *Usá*, *Gestionalo*, *acá*); they now use the pack's
  neutral *tú* form (*Deja*, *Usa*, *Gestiónalo*, *aquí*), as `docs/translation-style-guide.md`
  requires. Wording only: no string key, placeholder or meaning changed.
- Technical: `mailbox\reply_token` reads the time from `\core\clock` instead of `time()` (#268).
  In production that clock is `time()`, so reply tokens are minted and checked exactly as before;
  the tests freeze it, so the expiry tests no longer fail when run in the first second of an hour.

### Fixed

- **The target picker of the merge page finds tickets again.** Typing in
  *Target* on `merge.php` showed nothing, so no merge could be completed from
  the interface (Enterprise). The picker's search module
  (`amd/src/merge_target_search.js`) had a default export; core's
  autocomplete calls `transport()` and `processResults()` on the module itself,
  found neither, and the browser stopped with "ajaxHandler.transport is not a
  function" before any search was sent. The module now exports both by name,
  as core's own pickers do. The server side (the search, the merge, the undo)
  was not affected. The module has been unchanged since it was added in 2.9.0;
  the failure was reproduced on 2.38.1 on Moodle 4.5.14, and earlier releases
  were not run. (Found in the 2.38.1 staging validation, F7.2, on the lab.)
- **A ticket merged away no longer accepts custom field values.** Its values
  could still be saved from the ticket page, with or without JavaScript. The
  change showed on the target's "Custom fields from merged ticket(s)" panel
  and left nothing in the ticket's history (Pro and Enterprise; reproduced on a
  lab with 2.38.1 plus the picker fix above).
  `custom_field_service::save_ticket_values()` now refuses with
  `ticket_locked_by_merge`, the same error the other ticket changes give. The
  check runs before anything is written, so even an unchanged or empty save is
  refused. The ticket page shows those values read-only instead of the form.
  Active tickets, merge targets and a source whose merge was undone save as
  before, and so does privacy erasure. A save for a ticket that does not exist
  is now refused (`ticketnotfound`) instead of writing values for it.
- **Acceptance tests wait for the ticket page's own saves.** The property
  auto-save, the drawer's whole-form submits, the reply send, the composer's
  draft file requests and the saved filter views all post with `fetch()`, and
  none of them told Moodle a request was in flight. So "I wait until the page
  is ready" could return before a save had reached the server, and a step
  reading the database just after could fail at random. That happened once on
  `main` after 2.38.1, on the status change at the end of
  `ticket_merge_js.feature`. Each request now holds a `core/pending` until its
  answer is handled, on success and on failure. Nothing changes for users.
- **A ticket merged away shows its properties as values, not controls.** Its
  status, priority, category, department, team and assignee were still offered
  as selects. The server refused every change, so picking a value only ended in
  an error toast. The Properties card of a merged source now lists the current
  values read-only, as its custom fields already did. The merge target and a
  ticket whose merge was undone keep their controls. No server rule changed. A
  test now also pins that `assign_team()` refuses a merged ticket.
- **Undoing a merge says so in both threads and in the source's history.**
  Each thread ended with the note announcing the merge, so after an undo the
  source still read "It is now closed." although it was open again, and its
  history showed it closing but never reopening. The undo now adds a system
  note to each ticket ("The merge into ticket #2 was undone." and "The merge of
  ticket #1 into this ticket was undone."; the merge notes are kept). It also
  records the source's status going back, with the merge's audit id, when that
  status changes; a source closed before the merge records nothing. Neither
  notifies anyone or fires a status-change event, so CSAT, automation rules and
  webhooks are not triggered; `ticket.merge_reverted` stays the one
  announcement. `docs/ticket-merge-design-doc.md` §9.5 no longer claims that
  the merge's system notes fire `ticket_replied`, which they never did.

### Added

- Behat only: a site trusts one ephemeral licence key, held in a config row, **only** while
  `BEHAT_SITE_RUNNING` is defined. Steps `the mooDesk ephemeral licence key is trusted` and
  `the mooDesk licence is "<tier>" with <N> agents` drive real v2 licences in acceptance tests, so the
  real signing key is never used. On a normal install the row is ignored; PHPUnit proves it.
- The shared contract fixture moves to version 2, with the capacity grid and 32 test vectors that
  this plugin and the licence issuance tool both run. `license_contract_v2.feature` covers the v2
  statuses on the License status page.
- `dev_scripts/check-amd-datasources.mjs`: loads every compiled module that a
  form hands to an autocomplete as its `ajax` datasource and fails unless
  `transport` and `processResults` are functions on the module. It fails on the
  2.38.1 build and passes on this one. Not in the package.
- Behat `ticket_merge_js.feature`: the merge page used by a manager who is also
  an agent, typing in the picker, merging, undoing and closing both tickets;
  and the two refusals described under Documentation. It also covers a custom
  field value on the merged source: read-only after the merge, shown on the
  target, and editable again after the undo.
- PHPUnit `custom_field_merge_lock_test`, plus cases in
  `ticket_page_handler_test` and `ticket_detail_test`: the merge lock on
  custom field values, through the service, the AJAX and plain-form paths and
  the ticket page.
- Behat steps `mooDesk should hold N merge audits for "<subject>"` and
  `the mooDesk merge of "<source>" into "<target>" is standing|undone`, read
  straight from `local_moodesk_merge_audit`. `ticket_merge_js.feature` now
  asserts that both refused merges leave no audit row, and that the accepted
  merge writes one, which the undo stamps instead of deleting.
- **Integration tests between the licence issuance tool and this plugin (C2-3; #313, #314).** Test
  only, with ephemeral keys; the real signing key is never used.
  - PHPUnit `lm_issued_tokens_test` reads `tests/fixtures/lm_issued_tokens.json`: 39 licences that
    `lm` signed with an ephemeral key for a fictitious site. Pro 10 to 25 and Enterprise 50 to 1000
    come from `lm`'s real issuing path; every other contract vector, and the signature, site,
    expiry and key failures, are signed directly. For each one the test checks the status, the
    edition, the enforced capacity and the grant gate at the limit. Without the test key seam no
    case is valid and the site stays Starter at 2, so the fixture grants nothing on a real
    install. `lm` checks a byte-identical copy. The fixture expires on 2029-10-17, and both
    suites then fail with the instruction to regenerate it.
  - Behat `license_c2_3_walk.feature` walks one site through Starter at 2, Pro 15 (the 16th agent
    is refused), back to Pro 10 (over capacity, nobody removed), Enterprise 50 then 75, and a
    `capacity_invalid` and a pre-C2 licence shown with their reasons.

### Quality

- **No PHPUnit test is skipped on any CI leg (Q1, #318).** Down from 2 to 261 per leg. The nine
  incident tests that skipped on PostgreSQL now own their transaction and run, the stale edition
  guards are gone, and the one remaining `markTestSkipped` (no `proc_open`) is never reached in
  CI. A sender-refusal mail test that asserted nothing on PostgreSQL now asserts there too. Tests
  only: no production code changed and no functional defect was found
  (`docs/certification-q1-skipped-tests.md`).

### Documentation

- **Who can merge.** A merge and its undo need the capability
  (`mergetickets`, `revertmerge`, Manager archetype) **and** sight of both
  tickets (`viewall`, from the agent role). A Moodle Manager who is not a
  mooDesk agent is refused; an agent without the capability is not offered the
  action. Stated in the admin guide and in `docs/ticket-merge.md`.

---

## [2.38.1] — 2026-10-08

### Fixed

- **Replying by email works on a fresh install.** The key that signs the
  reply address in notifications was only created by the upgrade, so a site
  installed fresh had none: notifications carried the no-reply address and a
  requester's emailed reply opened a new ticket instead of joining theirs. The
  install now creates the key, and the upgrade gives one to every site
  installed fresh since 2.23.0. A key that already exists is kept, so reply
  addresses already sent out keep working. (Found in the 2.38.0 certification
  lab.)
- **A mailbox on SSL/TLS whose certificate is refused now says so.** With
  *Connection security* set to SSL/TLS, a certificate that does not validate
  (an untrusted chain, a name that does not match) was reported by the
  `email_ingestion.imap.poll_failed` incident and the cron output as
  `IMAP connect failed:  (0)`: PHP returns an empty error for a failed
  `ssl://` handshake and the cause was only in a suppressed warning. The
  connection collects those warnings, as STARTTLS already did, and the
  incident names the cause. The connection was always refused; only the
  message changes. (Found in the 2.38.0 certification lab.)
- **A message that meets a locked table is retried, not discarded.** Email
  ingestion treated every refused database write as a fact about the message
  and marked it unprocessable for good. Moodle reports a deadlock, a lock wait
  timeout, a serialisation failure or a read-only database the same way, so a
  moment of contention could drop incoming mail: it was flagged in the mailbox,
  audited as `unprocessable` and never tried again. Those refusals now take the
  transient path (the poll stops at the message and retries it, then the retry
  list), with an `email_ingestion.route.failed` incident. A value that does not
  fit its column or breaks a constraint is still final. (Found in the 2.38.0
  certification lab with a real InnoDB lock wait timeout.)
- **Saving a ticket's custom fields no longer wipes an old date that is not
  a date.** A date field saved before values were validated (for example
  `30/09/2026`) cannot be shown in the agent's date input, so the input was
  empty, and saving any field on the ticket posted it back empty and erased
  the stored value. The value is now kept until the agent picks a real date,
  and the rail shows what is stored under the input. (Found in the 2.38.0
  certification lab.)

---

## [2.38.0] — 2026-10-06

The licence sync built to the approved verification contract, still switched
off, plus three display defects, one wording fix and corrected requirements.
A minor release because the sync is new behaviour, even while inactive. No
schema change, no new setting, no upgrade step.

### Fixed

- **A malformed date custom field no longer takes the ticket page down.** The
  agent detail page stored any text sent for a date field as typed, and on
  PHP 8.3 or later rendering it threw `DateMalformedStringException`, so the
  ticket stopped opening for everyone. Only real `YYYY-MM-DD` dates are stored
  now; a value saved before this fix is shown as typed instead of failing.
- **SLA times roll over to the next unit.** The remaining time could read
  "7h 60m" or "60m", and an overdue time "60m" or "24h". Times are rounded
  once and then split, so they read "8h", "1h" and "1d".
- **Saved view names show as typed.** A view called `A&B` showed as `A&amp;B`
  in the ticket list because its name was escaped twice.
- **The untrusted-key licence status names the company correctly**
  ("digitalMood", not "Digital Mood").

### Changed

- **The licence sync follows the approved verification contract, and is still
  off.** `license_sync_service` now implements
  `docs/license-verify-contract.md`: it asks only
  `https://licenses.moodesk.io/api/v1/verify` (no setting can point it
  elsewhere; a leftover `license_sync_endpoint` row is ignored), over HTTPS
  with certificate checks, without redirects and with short timeouts. A
  `revoked` answer is acted on only when the site's own signed revocation list,
  refreshed on the spot, confirms it, and even then the licence key is kept and
  the licence is denied through that list. Any other answer or failure leaves
  the licence as it is. The service can no longer replace a licence
  (`updated_license` is gone). The License status page shows the last online
  check once sync runs. `SYNC_ENABLED` stays `false`: nothing is sent until a
  release turns it on.
- **Supported databases are the ones we test: MariaDB and PostgreSQL.** MySQL
  was listed as supported but no test covers it, so it is no longer listed.
  The package readme's "Tested" column now matches the test matrix (PHP 8.2 to
  8.4, MariaDB 10.11, PostgreSQL 16).
- **The readme gives the revocation list's current default address**,
  `https://licenses.moodesk.io/crl/v2/revocations.json`, which 2.37.0 moved it to.

---

## [2.37.0] — 2026-10-03

### Security

- **The licence signing key was rotated.** The private key that signed
  licences and the revocation list was exposed on 2026-10-01 and is no longer
  trusted. This version trusts one new key (RSA 3072, kid `moodesk-2026-10`,
  fingerprint `2eec173b…a1e84efd`) and no other: a licence signed with the
  retired key now shows **`untrusted_key`** on License status and the site runs
  as Starter until a re-issued licence is pasted. No licence had been issued
  outside digitalMood, so there is no transition window.
- **Keys are named.** Licence JWTs carry a `kid` header and the revocation
  list a `kid` field; each is verified only with the trusted key it names
  (`license_service::TRUSTED_KIDS`, files in `license_keys/`). A future
  rotation adds a kid instead of replacing a file.
- **The revocation list moved to `/crl/v2/`** and is refused unless signed by
  a trusted key. The upgrade step moves a site still on the old default URL
  and forgets the list it held, with its `updated_at`: a list signed with the
  leaked key could have set that far in the future and locked out every
  legitimate list after it.

### Added

- **Email ingestion signs in with OAuth 2.0 (E4)**, for Gmail / Google
  Workspace and Microsoft 365. *Authentication* now offers **Password** or
  **OAuth 2.0**; with OAuth 2.0 the poller sends IMAP `AUTHENTICATE XOAUTH2`
  with an access token from a Moodle OAuth 2 service (*Site administration →
  Server → OAuth 2 services*) chosen as *OAuth 2 service*. mooDesk adds the mail
  scope to that service's system account and **stores no token**: Moodle keeps
  and renews it. **Microsoft mailboxes can now be connected over IMAP**, which
  takes no password there, so before this they could not. Validated end to end
  on a Google Workspace tenant and, for Microsoft, against an outlook.com
  account. What only a Microsoft 365 business tenant has — shared mailboxes,
  admin consent, single-tenant endpoints, tenant policies such as Conditional
  Access, administrative revocation — is **not yet validated** (admin guide
  §14.6.2).
- **Microsoft 365 needs a Microsoft service set aside for mail**, not the one
  users log in with: *Show on login page* = **SMTP with XOAUTH2 only**, and
  offline scopes `openid profile email offline_access
  https://outlook.office.com/SMTP.Send` with **no `user.read`**. Microsoft
  issues a token for one resource only; the login service also asks for
  Microsoft Graph, so its system account cannot be connected for mail
  (`AADSTS70011`). mooDesk refuses any other Microsoft setup, and gives such a
  service no scope, so the login service is never affected. Moodle requests
  `SMTP.Send` for that service although **mooDesk never sends mail through
  it** — it only reads the mailbox. Requires **Moodle 4.5.5, 5.0.1 or later**
  (MDL-80380), detected at runtime; this applies to Microsoft 365 over OAuth
  2.0 only. Admin guide §14.6.
- **Four incidents tell OAuth 2.0 failures apart**, one per remedy:
  `email_ingestion.oauth2.not_ready` (the configuration cannot work — the
  settings page lists why, including a system account connected before the
  mailbox chose its service, which lacks access to mail),
  `email_ingestion.oauth2.token_refresh_failed` (connect the system account
  again), `email_ingestion.oauth2.blocked_by_policy` (the tenant's
  administrator blocks the app; only they can allow it) and
  `email_ingestion.oauth2.auth_rejected`
  (the server refused a freshly renewed token). A token the server refuses is
  renewed once before anything is reported, so the first poll after a user
  removes the app already says to reconnect, and a stale token recovers on its
  own. *System health* shows how the mailbox signs in, and with OAuth 2.0 the
  service and whether it is ready.

### Changed

- **Email ingestion reads the mailbox by UID, not by flag (E5).** The poller
  used to ask the server for messages without the `MoodeskProcessed` keyword,
  or, where a folder takes no custom keyword, for unread ones — so on such a
  server a person reading the mailbox hid mail from mooDesk for good, and
  Microsoft 365, which takes no custom keyword, would have run that way for
  every customer. Each polled folder now has a cursor
  (`local_moodesk_mailbox_cursor`) recording the highest UID read and the
  folder's `UIDVALIDITY`, and each poll asks for what is above it. The keyword
  and `\Seen` are still written, so webmail shows what was handled, and never
  read back.
- **A message that keeps failing no longer holds a slot forever, and an
  outage skips nothing.** A transient `error` stops the poll at that message;
  after three polls in a row it moves to a retry list of up to 20 messages,
  retried first on every poll, and the poll moves past it. Past 20, the oldest
  is abandoned with an `email_ingestion.cursor.retry_abandoned` incident.

### Upgrade notes

- **A licence issued before this version stops working on upgrade.** It was
  signed with the retired key, so License status shows **`untrusted_key`** and
  the site runs as Starter until a licence re-issued with the new key is
  pasted. Ask digitalMood for the new licence before upgrading. See *Security*
  above.
- **Nothing changes for a mailbox signed in with a password.** *Authentication*
  defaults to **Password**, and no OAuth 2 service is touched until one is
  chosen for the mailbox. To move a mailbox to OAuth 2.0, follow the order in
  admin guide §14.6: choose the service and save **before** connecting its
  system account, or connect it again afterwards.
- **The first poll after upgrading re-scans each folder once**, as does any
  poll that finds the folder's `UIDVALIDITY` changed (recorded as an
  `email_ingestion.cursor.uidvalidity_changed` incident). It reads the last 14
  days only, so older mail that was never ingested does not become tickets
  now; messages already ingested are recognised by Message-ID
  (`already_processed`); and a message already in the folder without a
  Message-ID is left alone (`rescan_skipped_no_message_id`), since nothing
  could tell whether it is already a ticket. The per-run cap still applies and
  the cron log says when the re-scan is complete.

### Fixed

- **The way to take in an oversized message was wrong.** The setting help, the
  admin guide and the `email_ingestion.message.oversized` incident said to
  clear the processed flag on the message; its Message-ID is on record, so the
  next poll would have reported it `already_processed` whatever its flags. They
  now say what works: ask the sender to send it again within the limit.

---

## [2.36.0] — 2026-10-01

### Security

- **A mailbox can no longer make the poller allocate without bound.** The
  IMAP client read every literal a server declared, whatever its size, so a
  broken or hostile server — or one enormous message — could exhaust the cron
  process. Each message's size is now asked for before it is downloaded, and a
  message over the new *Maximum message size* setting (default 50 MB; 10, 25,
  50 or 100 MB) is not fetched: its headers are recorded in the email audit,
  an `email_ingestion.message.oversized` incident is raised with the two sizes
  and nothing else, and the message is flagged as processed. A server that
  declares a literal a quarter over the limit anyway has the connection closed
  before any of it is read.
- **IMAP over STARTTLS.** *Use SSL/TLS* becomes *Connection security*, with
  three choices: SSL/TLS, STARTTLS and None. STARTTLS connects on the plain
  port (usually 143) and upgrades to TLS 1.2 or 1.3 before logging in. It
  fails closed: a server that does not offer STARTTLS is refused rather than
  used in the clear, the certificate is checked against the host name, and a
  server that sends anything between accepting STARTTLS and the handshake has
  the connection dropped.
- **A mailbox polled without encryption is called out.** While email
  ingestion polls a server with SSL/TLS off, the email ingestion settings show
  a warning above the transport option, and *System health* has a *Mailbox
  connection* section naming the server and marking it *Not encrypted*. It is
  configuration, not an incident: the overall state is unchanged. Plaintext
  stays selectable.

### Fixed

- **A failed mailbox poll says why.** Cron output and the
  `email_ingestion.imap.poll_failed` incident read "Error occurred" for every
  IMAP failure, because the cause travelled in the exception's debug info and
  only its message was recorded. They now name it: the connection refused,
  the certificate rejected, a server without STARTTLS, the server's answer to
  a failed login. (Found in the H4 lab, `docs/lab/imap-transport-2026-10-01.md`.)

### Upgrade notes

- The upgrade carries *Use SSL/TLS* over as the transport it meant: on
  becomes SSL/TLS, off becomes None. No mailbox changes how it is reached by
  upgrading; one polled in the clear keeps working and is now warned about.
  To move a mailbox to STARTTLS, choose it under *Connection security* and set
  the port your provider gives for it.

---

## [2.35.0] — 2026-10-01

### Changed

- **Bulk actions: Apply says it is working, and selected rows look
  selected.** While a batch is being sent, Apply reads *Applying…* and the
  toolbar, the checkboxes and select-all are disabled, so a second click
  cannot send the batch twice; coming back with the browser's Back button
  restores them. A row whose checkbox is ticked is tinted, in the desktop list
  and in the card layout below it — the style had been in the stylesheet all
  along, and nothing applied it.
- **Bulk actions: the toolbar stays in view, large batches ask first, and a
  batch is capped at 30 tickets.** The toolbar sticks under the top bar, so
  an agent who selects at the bottom of a page acts without scrolling back
  up. From 10 tickets Apply asks before sending, because every ticket in the
  batch may send its own email inside the same request — against a real mail
  relay that is roughly half a second each (`docs/lab/bulk-bench-2026-10-01.md`).
  A request carrying more than 30 tickets is refused whole, before anything
  changes, with a message on the list; the list itself never selects more
  than a page, which is 30.
- **Ticket detail: the rail is 268 px and the SLA card sits above the rest.**
  The management rail's cards are 24 px wider, on the ticket page and in the
  drawer alike, which is the width of the reference design; the drawer's rail
  width is now derived from the page's instead of being kept in step by hand.
  The SLA card carries a faint shadow, so in the drawer, where it holds still
  at the top of the rail, the cards scrolling under it pass beneath an edge
  rather than into a seam. (The toast already matched the reference; the word
  count under the composer is not built, by decision.)

### Internal

- **The edition gate check covers the knowledge base services.**
  `dev_scripts/check-feature-guards.php` modelled a service as gated by one
  key or not at all, so `kb_service` and `kb_analytics_service` — which mix
  Starter, `kb_multilingual`, `kb_agent_tools`, `kb_feedback` and
  `kb_analytics` with methods that are ungated on purpose — sat outside it: a
  removed `require_feature()` there failed nothing. The baseline now maps each
  public method of those two services to the gates it carries, `has_feature()`
  included, with an empty list for a method meant to work on every edition; a
  mapped gate that disappears fails, and so does a public method with no
  mapping. Gates are also followed through the calls a method makes inside its
  own class, which was a gap in the existing check too: `merge_service::merge()`
  and `preview()` are gated by the private `require_gates()`, were recorded as
  ungated, and losing that guard went unnoticed. Dev tooling only; the package
  does not change.
- **Behat asserts the composer's one-panel rule in both directions.** The
  paperclip closes the Insert article tool, and opening the tool closes an
  open attachments panel; until now only the first was automated, on the
  belief that the Behat page had no file picker and that `hidden` could not
  hide the attachments wrapper. Neither held: the picker is there, and
  Bootstrap's `[hidden] { display: none !important; }` wins over the
  wrapper's `display: contents`. Tests and comments only; no behaviour
  change.
- **Bulk action timing** (`dev_scripts/bench/bulk_bench.php`,
  `docs/lab/bulk-bench-2026-10-01.md`): the real POST to `bulk_action.php`,
  timed over HTTP on MariaDB and PostgreSQL for batches of 1–50, with mail on
  and off. The database side costs 2–4 ms per ticket; everything else is
  SMTP, which a web request pays once per email over a new session — ~380 ms
  per email against a relay 50 ms away, so a batch of 30 takes ~11 s. The
  numbers D4b's confirmation threshold and cap were chosen from. Dev
  tooling and a report; the package does not change.
- **The ticket list has Behat coverage on phones and tablets.**
  `ticket_list_small_widths_js.feature` opens the list at 360, 390, 576 and
  768 px and asserts that rows are cards, that nothing scrolls sideways, that
  the secondary filters fold and unfold on phones and stay open above, that an
  active filter shows as a chip, and that the bulk toolbar with an action
  chosen fits. Widths come from Chrome's device emulation through a new step,
  because the CI browser widens any window below ~500 px and a window step
  asking for 360 would have tested 500. The suspected bulk toolbar overflow at
  360 px does not reproduce: the selects wrap. Tests only.

---

## [2.34.0] — 2026-09-30

### Security

- **The REST API only accepts tokens issued by mooDesk.** A web service call
  that does not carry a token from *API tokens* (`api_tokens.php`) is refused
  with `api_token_unmanaged` before the function does anything. That covers a
  token minted on Moodle's own *Manage tokens* screen, which used to run with
  its owner's capabilities and no scopes, and Moodle's username/password entry
  point (`webservice/rest/simpleserver.php`), which also escaped the rate
  limit. The plugin's own pages call over AJAX with a session and are not
  affected.

### Upgrade notes

- An integration that authenticates with a token created on *Site
  administration → Server → Web services → Manage tokens*, or with a web
  service username and password, stops working. Issue it a token from
  *mooDesk → API tokens* for the same user, with the scopes its calls need,
  switch the integration over, and revoke the old token. `docs/api-reference.md`
  has the details.

---

## [2.33.0] — 2026-09-30

### Security

- **A webhook's signing secret is generated, has a floor, and is shown once.**
  The secret was typed by the administrator, optional, of any length, and put
  back into the edit form on every visit. Now a new webhook always has one:
  left empty, a 64-character secret is generated (32 random bytes); typed, it
  must be at least 32 characters. It is shown once, on the webhook list, right
  after it is created, and never again: the edit form has no secret field, and
  a new *Signing secret* panel on the edit page offers **Rotate secret**, which
  generates a replacement and shows it once the same way. Webhooks created
  before this release keep their secret and keep working; the list marks the
  ones that are *Unsigned* or have a *Weak secret*.
- **Values inside HTML attributes are escaped by the template.** 151 attribute
  values across 39 templates were rendered with `{{{ }}}` (unescaped). All but
  one were URLs that happened to be safe; the exception was the webhook URL in
  the list's tooltip, safe only because the URL field refuses quotes on input.
  All of them are `{{ }}` now, and a test fails if one comes back. No visible
  change.
- **The new-ticket form refuses a subject or message that is not text.** A
  hand-built request could send either as a list: the subject then failed
  with a server error, and the message was stored as the word "Array". Such a
  request now gets a validation message. (Audit H14.)

### Changed

- **REST: `create_webhook` returns the secret; an empty secret on
  `update_webhook` keeps it.** The create response adds `secret`, the stored
  secret, supplied or generated: the only time the API returns it. A supplied
  secret shorter than 32 characters is refused on create and update. On
  update, an empty or omitted `secret` now keeps the current one; before, it
  removed it and left deliveries unsigned. A secret can no longer be removed.
  See `docs/api-reference.md`.

### Internal

- **Custom field dates are documented as what they are: `Y-m-d`.** The
  domain docblock, both `install.xml` comments, `DEVELOPER.md` and the admin
  guide said a date value was a Unix timestamp; the service has always stored
  a calendar date. Comments only: no schema change, no upgrade step.
- **`custom_field_service` has a unit suite again.** The admin write path
  (`create_field`, `update_field`, `toggle_active`, validation and
  `options_to_textarea`) had no PHPUnit coverage since the original suite was
  lost in a dropped stash; `tests/service/custom_field_service_test.php` is
  rewritten against the current contract — the edition gate driven through
  the testing seam, and every failure case asserting its specific error code
  rather than the base exception. No change to the service.
- **The custom field runtime helpers are tested directly.**
  `get_active_fields`, `save_ticket_values` and `get_rendered_fields` were
  only exercised through the pages; `tests/service/custom_field_runtime_test.php`
  covers the edition gates, visibility, date and checkbox normalisation, the
  upsert and the rendered values. Tests only.

---

## [2.32.0] — 2026-09-18

### Changed

- **The public support channel is named.** The README, the plugin's `readme.md`
  and the getting-started guide carried the placeholder
  `<public support channel — pending>`; the channel now exists: documentation at
  [support.moodesk.io](https://support.moodesk.io) and bug reports, feature
  requests and documentation issues through the issue forms of the public
  `digital-mood/moodesk-support` repository, which holds no source code and no
  release binaries. The three places point there, with the reminder that issues
  are public and that a security vulnerability is reported privately.

### Fixed

- **Email ingestion no longer claims to need the PHP `imap` extension.** The
  description of the *Email Ingestion* settings section ended with "Requires the
  PHP `imap` extension", and an unused string said the extension was not loaded.
  Neither was true: the mailbox client has spoken IMAP over a plain socket
  since it shipped (`classes/mailbox/`), and no extension beyond Moodle's own
  requirements is needed. The description now says what is needed (a dedicated
  IMAP mailbox with its host, port, TLS setting and credentials, and outbound
  access from the Moodle server to it), and the unused string is gone from both
  language packs.

---

## [2.31.0] — 2026-09-17

### Security

- **The reports upgrade link is kept to a URL.** `reports.php` passed the
  `upgrade_url` config value straight from the database into an `href`
  through an unescaped triple mustache. Only an administrator can set that
  value, so this is defence in depth: the page now cleans it with
  `PARAM_URL` (a `javascript:` value renders no link at all) and the
  template escapes it. Pinned by the repository's first hostile-payload
  render tests.

### Performance

- **The ticket list no longer reads one department per department on the
  page.** The SLA evaluation of each row loaded its department to resolve
  the department's overrides — cached per request, so one read per distinct
  department on the page: 9 reads at 10k tickets, 12 at 50k on the scale
  bench. The list now loads the page's departments in one query before the
  rows are walked (the warning filter's full pass included) and costs the
  same reads whatever the departments. New `sla_service::prime_departments()`
  is the hook; the overrides resolve exactly as before. Pinned by a test that
  counts reads.

- **The agent directory and Supervision no longer read two rows per agent.**
  Every row of `agents.php` and of the supervision screen looked up its
  departments and its teams one agent at a time — 105 reads for 50 agents on
  the scale bench, and a 300-agent Enterprise site would have spent ~600
  opening the directory. The memberships of the whole roster now come from
  one query per table (new `department_repository::get_user_departments_for()`
  and `team_repository::get_user_teams_for()`), and the read count is the
  same for 5 agents and for 40. Each row still lists its own memberships,
  sorted by name, active departments only. Pinned by tests that count reads.

### Fixed

- **The CSAT invitation is delivered.** Since the survey shipped, reaching the
  trigger status stored the requester's survey token and nothing carried it to
  them: no mail, no link on any page, while the invitation strings and the
  *Custom invitation message* setting sat unused. The requester now receives an
  invitation through the *Ticket notification* provider (their message
  preferences apply) with the survey link, its validity in days and the custom
  text in front when one is set — sent once per ticket, never on a later
  transition — and their own ticket page shows a *How did we do?* card with a
  *Rate your experience* button while the invitation is open. Agents never see
  the token; the sidebar card is unchanged. The invitation mail carries no
  reply token, since it is not part of the conversation. Pinned by delivery and
  once-only tests on the observer, render tests on the detail and a Behat
  scenario end to end.

---

## [2.30.0] — 2026-09-16

Launch readiness: closing the hardening rows of the core security audit
(`docs/core-security-audit.md`, ticket SEC-05), and the agent entitlement
the pricing promises.

### Added

- **The agent limit of each edition is enforced.** Starter allows 2 active
  agents, Pro 10, Enterprise any number. *Add agent* refuses to grant the
  role once the site is at its cap and says why; the screen shows the
  limit before a person is chosen. An *active agent* is anyone holding
  `local/moodesk:agent` at system level who is neither deleted nor suspended
  — managed and unmanaged alike, site administrators only when granted the
  capability. Agents added through Moodle itself (a role assigned on Moodle's
  screens, an SSO role mapping, a script) are counted but not intercepted:
  such a site can be over its limit, in which case mooDesk adds nobody until
  it is back under and every existing agent keeps working. A downgrade
  removes no one; an upgrade widens the limit at once. New
  `entitlement_service` is the single source of the caps.
- **License status and System Health show the entitlement.** The licence
  page's *Agents* row reads `active / limit` for the effective edition (also
  on Starter with no licence), with an *Agent limit exceeded* pill when over.
  System Health gains an *Entitlement* section with the same figures; being
  over the limit is a soft warning there and never changes the overall
  healthy / degraded / down state — operational health and licensing state
  are two different questions.

### Changed

- **The licence host is `licenses.moodesk.io`.** The default revocation-list
  URL is now `https://licenses.moodesk.io/crl/revocations.json` (signature at
  `.sig`), and the dormant verification endpoint follows it
  (`https://licenses.moodesk.io/api/v1/verify`). The previous default,
  `licenses.digitalmood.io`, never resolved. Because Moodle stores the
  default of an admin setting, every existing site holds the old URL in
  `revocation_crl_url`; the upgrade rewrites a value that is exactly the old
  default and leaves any other value alone, so a site pointing at its own
  mirror keeps it. `readme.md` and the admin guide name the new host in the
  outbound-request disclosure.
- **The licence's `max_agents` claim is no longer presented as the limit.**
  The cap follows the licensed tier; a claim that disagrees with it (25 on a
  Pro licence, or a value that is not a number) neither widens nor narrows
  the tier and is shown as *License claim ignored: 25* beside the row, so an
  operator can see the licence was issued with a value the runtime ignores.
  `docs/license-issuance.md` now issues Pro with `max_agents = 10` and
  Enterprise with no claim; the "+25 % headroom" rule is gone.

### Security

- **Outbound TLS is verified.** Webhook deliveries and the licence
  revocation-list fetch now set `CURLOPT_SSL_VERIFYPEER` and
  `CURLOPT_SSL_VERIFYHOST` explicitly; Moodle's `\curl` resets peer
  verification to off, so until now an endpoint presenting any certificate —
  self-signed, expired, for another host — received ticket content. There is
  deliberately no setting to turn verification off: an endpoint that cannot
  present a valid certificate gets a failed delivery in the log, a retry and
  an incident, which is the outcome an operator needs to see. **Sites whose
  webhook endpoints use self-signed certificates will see deliveries fail
  after this release**; that is the fix working. (Audit H2.)
- **The webhook delivery log has a retention period.** A new nightly task,
  `purge_webhook_log`, deletes delivery records older than
  `webhook_log_retention_days` (default 90, new setting under *Webhooks*).
  Each record holds the payload that was sent and the first part of the
  endpoint's response, so until now ticket content and third-party replies
  accumulated without bound. Age is the only predicate; success is not an
  exemption. (Audit H10.)
- **Unexpected failures in the interface no longer reach the browser.** The
  AJAX endpoint used to return the raw message of any exception — including a
  database or type error, which can carry file paths and SQL. A product rule
  (a ticket out of reach, a merge refused) still answers with its own message;
  anything else answers with one generic sentence and is recorded as a System
  Health incident under the new *Interface* source, with class, message, file
  and line for the operator. (Audit H7.)
- **A ticket cannot name a course its requester cannot see.**
  `submit.php?courseid=N` snapshotted the full name of any course on the
  site — hidden ones included — onto the requester's ticket. A course the
  actor cannot access, or one that does not exist, is now treated as no
  course; the ticket is still created, at site level. (Audit H8.)
- **The public survey page escapes the ticket subject** (audit R3), and the
  merge target search cleans the subject in its autocomplete label (R6).
  Every entry point cleans subjects today; these two surfaces no longer
  depend on that.
- **The merge page checks the capability before it renders anything.** A
  user without `local/moodesk:mergetickets` used to be shown the edition
  upgrade prompt first, learning that the feature exists. (Audit R7.)
- **The `References` header is capped at 32 ancestors** before the threading
  lookup; the header is sender-controlled and was unbounded. (Audit H9.)

### Performance

- **The ticket detail's query count no longer grows with the thread.** The
  conversation asked the file store for each reply's attachments one reply at
  a time — one read per message, files or not: 13 + N reads for a thread of N
  on the bench (63 at 50 replies, 213 at 200). The attachments of every reply
  shown, merged-in ones included, now come from one query keyed by reply;
  the detail costs 14 reads at any length, and a 200-reply thread renders in
  half the time. Pinned by a test that counts reads.

### Fixed

- **The revocation list was never fetched from cron.** `revocation_service`
  created Moodle's `\curl` without loading `lib/filelib.php`, which the
  bootstrap does not load for cron or CLI; the resulting error was swallowed
  by the fail-closed guard, so every daily `refresh_revocation_list` run
  silently kept the (empty) previous list. The class is now loaded before
  use, here and in the dormant `license_sync_service`, and a download failure
  is reported under `DEBUG_DEVELOPER` so a programming error can be told
  apart from an unreachable host. Validated on the real scheduled-task CLI
  path; automated coverage is not meaningful here because Moodle's PHPUnit
  bootstrap already loads `filelib.php`.
- **`local_moodesk_list_tickets` honours `limit` and `offset`.** The REST
  function declared both and honoured neither: the offset was rounded down to
  a page of 30 rows (the web UI's page size) and the whole page came back
  whatever `limit` said. `limit` (clamped to 1–100, default 25) and `offset`
  (0-based) are now a plain row window over the newest-first order, and an
  offset past the end is an empty list. **Compatibility:** a call with the
  default arguments still returns the newest tickets first, but 25 rows where
  it used to return 30; a call that passed `offset` receives the rows it
  asked for instead of the page containing them. No external consumer is
  known. The service gains `get_all_tickets_range()` and
  `get_tickets_for_requester_range()`; the web UI's paging is unchanged.
  (Audit O3.)
- **Attachments of tickets raised by email before 2.14.3 are visible again.**
  Between 1.4.0 and 2.14.2 the email ingestion stored an emailed ticket's
  attachments in the `ticket_attachment` file area, which no screen has ever
  read; 2.14.3 corrected the write, and this release corrects the data. An
  upgrade step moves each such file to the reply that holds the opening
  email — the reply the ingestion's own audit row names, or, when there is
  no such row, the requester's unmistakable opening message. A file whose
  destination cannot be determined without guessing (a ticket with no reply,
  contradictory audit rows, a different file already under that name) is
  left where it is, still served and still covered by the privacy provider;
  the upgrade continues and its log states how many files moved, how many
  were already in place and which tickets were left alone. Idempotent;
  content and metadata are preserved (the file store is content-addressed,
  so only rows move). Version `2026091603`.
- **The agent detail's add controls read as one control on a phone.** The
  two "add to department / add to team" forms used Bootstrap's `.form-inline`,
  which below its breakpoint stacks the select and the button with no gap.
  They now use the `.mhd-member-add` wrapper the member rosters carry, and on
  a phone the select fills the line and the button sits at the end of the
  row below it. Extracted from the stale `fix/agent-detail-responsive` branch
  onto current `main`; the built stylesheet was regenerated.
- **The IMAP password setting no longer claims to be encrypted.** Its
  description said the value was "stored encrypted in the Moodle
  configuration"; it is stored as plain text in `config_plugins`, like every
  other setting. The description now says so and recommends a dedicated
  least-privilege mailbox account. English and Spanish. (Audit R4.)
- Two Spanish strings had drifted back into Río de la Plata *voseo*
  (`setting_email_auth_gateway_asserted_desc`,
  `setting_email_verify_new_tickets_desc`); they match the pack's neutral
  register again.

### Documentation

- **Public documentation refreshed for 2.30 (English canonical).** The root
  README and the shipped `readme.md` describe the product as it is — knowledge
  base, System Health, saved views and the detail drawer, the complete
  capability and scheduled-task lists, the agent cap of each edition, the
  official pricing (per site, per year), what the plugin sends over the
  network (the daily licence revocation list, and nothing else on its own),
  and the trademark notice. `docs/getting-started.md` is now a ten-step admin
  quick start ending in a smoke check and a look at System Health; the agent
  guide covers saved views, the drawer and mobile tabs, the knowledge base
  tools and merging; `docs/README.md` indexes every document as public or
  internal; the API reference states that supported tokens are the ones
  mooDesk issues. Download and support links are placeholders until the
  public pages exist.
- **Admin guide, sections 1–4 in English** (requirements, installation, initial
  configuration, roles and capabilities), verified against the code: the
  install seeds the mooDesk Agent role (the Spanish text said it did not),
  every capability with its default archetype and edition, the agent cap and
  managed / unmanaged agents. The remaining sections follow group by group.
- **Admin guide, sections 5–9 in English** (categories, departments, teams,
  agents and the queue, the ticket lifecycle), verified against the code:
  categories and departments are Starter (the Spanish text said Pro), there
  is no *My teams* tab, bulk actions are status and assignment only, the
  audit log is written but not shown, and a resolved ticket still takes
  replies.
- **Admin guide, sections 10–13 in English** (custom fields, SLA,
  notifications, automations), verified against the code: automations are
  Pro, not Enterprise; the `time_elapsed` trigger is stored but nothing
  evaluates it yet; a rule fires once per ticket; the SLA warning is at 20 %
  remaining; only a public reply counts as first response; the breach flag
  clears again on active tickets.
- **Admin guide, sections 14–17 in English** (email ingestion, CSAT, webhooks,
  REST API), verified against the code: the "user for unknown senders" setting
  does not exist (such mail is refused), the ingestion outcome list is
  complete, the webhook retry ladder is four steps, a status change in
  `ticket.updated` carries `oldstatus`/`newstatus`, the rate limit is a setting.
  §15 records that the CSAT invitation is never delivered.
- **Admin guide, sections 18–22 in English** (reports, merge, editions and
  licence, settings reference, scheduled tasks), verified against the code:
  merge is Enterprise, there is no edition selector, `sync_license` is a
  no-op, the settings reference lists every key with its real default and
  the task table lists all twelve tasks with their schedules.
- **Admin guide, sections 23–27 in English, and the FAQ rewritten** (privacy,
  System Health, troubleshooting, knowledge base feedback, analytics and
  global search), verified against the code: the privacy table lists every
  declared table and what erasure does to each; System Health has six sources
  and the entitlement, sender-authentication and retention blocks; the FAQ
  no longer describes a 1.0 beta. The whole guide is now the English edition.
- Admin guide §9.8 and §21: `attachments_enabled` is **off** by default, not on
  (found by the scale benchmark, which first measured the detail page without
  its attachment query because of it).
- **Knowledge base guide** (`docs/knowledge-base.md`): articles, the reader,
  suggested articles, translations, the agent tools, feedback and analytics,
  global search, REST, settings and tasks — with the edition of each part.

### Internal

- **Scale benchmark** (launch readiness §9–§10, D7): `dev_scripts/bench/`
  seeds 10,000 or 50,000 tickets with the plan's distribution (long-tail
  replies, attachments on a fifth of them, 50 agents, custom fields, rules,
  merges, 100k webhook rows, 5k incidents, the P1–P4 heavy profiles) and
  measures every §9.2 scenario by wall time and `perf_get_reads()`, with the
  five slowest queries EXPLAINed. Run on MariaDB and PostgreSQL at both sizes:
  no scenario near its threshold, reads constant in the ticket count, the
  #178 fix confirmed at 200 replies. Results in
  `docs/lab/scale-bench-2026-09-16.md`.
- The CSAT survey's star picker moved from an inline `<script>` in the
  template to `amd/src/csat_survey.js` (audit R9a); `webhook_list::execute()`
  validates its (empty) parameters like every other external function (R9b);
  the three `LIMIT 1` literals in `email_message_repository` went through the
  DML API instead (R5). `docs/core-security-audit.md` §11 records every row.
- `webhook_service::http_post()` is the single place a `\curl` is built for
  a delivery, so a test can replace the transport and still exercise
  everything `deliver()` does around it; `revocation_service` gets the same
  seam as `set_transport_for_testing()`. Both services publish the options
  they request (`delivery_curl_options()`, `fetch_curl_options()`).

---

## [2.29.0] — 2026-09-15

### Added

- **Knowledge base feedback (KB-07a, Pro).** Every published article now
  closes with *Was this article helpful?* — Yes / No — for whoever may read
  it, editors included. One answer per reader per translation, replaced when
  they change their mind, never a history; works without JavaScript (a plain
  form and a redirect) and, with it, in place through the new AJAX function
  `local_moodesk_set_kb_feedback`. Gated by the new `kb_feedback` key; a
  Starter site keeps the answers and asks nothing. The answers are a person's
  own data: exported with them and **deleted** on erasure, not anonymised.
- **Knowledge base analytics (KB-07a, Pro).** An anonymous, typed event
  stream — a translation opened (once per reader session), a search with its
  result count, a suggested article shown while a ticket was written and
  opened from there, an article link that landed in a saved reply or note —
  with no user id, no IP, no JSON, and the search words stored only when the
  search found nothing, tokenised and stripped of anything resembling an
  e-mail address, a long number or a pasted token. Two retention windows on
  the one table: `kb_analytics_retention_days` (365) and
  `kb_search_analytics_retention_days` (90), applied by the new
  `purge_kb_events` task on every edition. A new page, reached from the
  *Analytics* action on *Articles* (`managekb` or `viewreports`, no new
  capability), answers the five questions — most viewed, lowest helpful rate,
  searches with no results, offered while opening a ticket, by language —
  over a 7 / 30 / 90-day range with language and category filters, a
  *By translation / By article* toggle, CSV per section and a printable view.
  Gated by the new `kb_analytics` key, placed in Pro beside Advanced Reports
  by decision D1 of 2026-09-15 (the 2026-09-12 roadmap line that put
  analytics in Enterprise is superseded, not contradicted). Plan and the
  fifteen approved decisions: `docs/kb-feedback-analytics-search-rest-plan.md`.
- Two tables, `local_moodesk_kb_feedback` and `local_moodesk_kb_event`, with
  covering indexes chosen in a lab at one million events on MariaDB and
  PostgreSQL (`dev_scripts/kb_analytics_bench.php`, results in `docs/lab/`).
  A new `knowledge_base` incident source for a recording that fails.
- **Knowledge base in Moodle's global search (KB-07b, Pro).** A search area
  (`local_moodesk\search\kb_article`) puts every published translation in
  the site's global search as its own result, labelled with its language,
  linking to exactly that row. The access rule is the article page's:
  Moodle asks it per hit before showing a title or an excerpt, so a draft,
  an archived row or an *Agents only* article never reaches a requester.
  Unpublish, archive and delete remove the document at once; a failing
  external engine is recorded as `kb.search_index.remove_failed` and the
  operation completes. Gated by the new `kb_core_search` key at the hit,
  not at the index: a Starter site indexes and shows nothing, and needs no
  reindex when it becomes Pro. The area is enabled and disabled where every
  area is (*Manage global search*); the plugin adds no setting.
- **Knowledge base REST API (KB-07b, Enterprise).** Eight functions join
  `moodesk_api` under the existing `api_access` gate:
  `local_moodesk_list_kb_articles`, `get_kb_article`, `create_kb_article`,
  `update_kb_article`, `transition_kb_article`, `delete_kb_article`,
  `add_kb_translation`, `get_kb_analytics`. Three scopes —
  `kb:read`, `kb:write`, `kb:analytics` (analytics deliberately outside
  `kb:read`). `id` is always the translation row and `get` returns exactly
  it; `list` resolves one row per article only on an explicit
  `resolve=<lang>` and never by the session language; every row carries
  `groupid`, `lang` and the siblings the caller may see; authorship is not
  exposed; feedback has no REST write. Reference: `docs/api-reference.md`,
  which now also states the site prerequisites Moodle imposes — web
  services, the REST protocol and `webservice/rest:use` on the token's
  owner, which core grants to no archetype.

### Changed

- The dashboard's KPI cards and the Reports bar chart are components
  (`scss/components/_kpi.scss`, `_bars.scss`) so the analytics page shares
  them; nothing on those two pages changed.
- The reporting date range lives in `domain/date_range`, shared by Reports
  and the analytics page; `report_service::parse_date_range()` delegates.
- `services_contract_test` now lists the two merge functions it had missed
  and holds the four AJAX functions out of the `moodesk_api` service: the
  service holds twenty-one functions (thirteen before KB-07b), the UI four
  more.
- `docs/admin-guide.md` §17: the REST function table is the real one (the
  old table named two functions that never existed) and the setup steps
  include `webservice/rest:use`; new chapter 27 on the global search area.

### Fixed

- The Reports date inputs and printable header showed the literal text
  `Y-m-d` instead of the dates: `userdate()` takes a strftime format.

---

## [2.28.0] — 2026-09-15

### Added

- **Knowledge base translations (KB-06, Pro).** One article can now exist in
  several languages as separate rows of one group (`groupid`), one row per
  language, gated by the new `kb_multilingual` feature key (Pro and above).
  *Reading*: every reading surface — the landing, browse by category, search,
  Suggested Articles on the New Ticket form and Insert Article in the reply
  composer — shows **one card per article**, in the reader's language when it
  exists, else by the chain current language → the parent Moodle declares for
  it → site language → the row the article was first written in → any; only
  rows the reader may see take part, so a draft or an agents-only translation
  never wins for a requester. A row shown in a language other than the
  reader's carries a small badge (`EN`, `PT-BR`, the name for screen readers)
  and a `lang` attribute; on the article page a quiet notice says the article
  is also available in their language, with the link, or that it is not and
  which version they are reading. A reader with two or more visible
  translations gets a switcher in the meta line (links up to four languages, a
  select beyond). **A direct URL always opens the row it names** — a shared
  link opens what was shared, never a redirect. Search matches every
  translation and shows, per article, the translation that actually matched
  (highest in the chain among the matches) — never a sibling the query did not
  hit. Insert Article resolves for the *recipient*: the ticket requester's
  language on a public reply, the agent's on an internal note.
  *Authoring*: the article page shows editors a translations panel — every
  row of the group with its status, the one being viewed marked — and an *Add
  translation* control listing only the installed languages the article lacks.
  A translation starts **empty** (nothing copied, nothing machine-translated),
  as a draft of its own, with the original one click away; category and
  audience belong to the article and are shared by every translation —
  changing either on any row changes all rows, in one transaction, and an
  audience change needs `local/moodesk:managekb` when any translation of the
  article is live. Each translation is published, unpublished, archived and
  deleted on its own; deleting the row an article was first written in keeps
  the others, at the same addresses. The form gains a Language select (default:
  the language the editor reads in; a draft started from a ticket takes the
  agent's); the manage list a Language column and filter. Externals
  `local_moodesk_search_kb_articles` and `local_moodesk_search_kb_for_reply`
  return two additive fields, `lang` and `langlabel`; existing keys unchanged.
  Plan and the fifteen approved decisions: `docs/kb-multilingual-ui-plan.md`.
- **Starter with translations.** Nothing about the data is gated: a site that
  drops from Pro keeps every row and file, still shows one card per article
  (resolved by the site language), still opens every row by URL — and shows no
  language UI (no badges, notice, switcher, panel, column or Add translation);
  starting a translation or changing a row's language is refused server-side.
  Back on Pro everything reappears without migration.

### Changed

- **Schema (upgrade step 2026091500).** `(groupid, lang)` is unique on
  `local_moodesk_kb_article` (`ux_groupid_lang`), and the plain `groupid`
  index is dropped as redundant. The upgrade backfills `groupid = id` on any
  stray row and **stops, naming the rows**, if a site already holds two rows
  with one group and language — it never picks a loser. No `lang` is
  rewritten: every existing article becomes a one-row group. Verified on
  MariaDB and PostgreSQL 17.
- The audience rule widens with sharing: changing a *draft's* audience needs
  `local/moodesk:managekb` when a sibling translation is live (it was the
  draft's own status that decided before). A one-row article behaves as it did.
- `kb_service::draft_defaults_from_ticket()` returns a fourth key, `lang` (the
  agent's current language — nothing read from the ticket or its requester).
- Privacy export of an article row carries its `groupid`. Metadata unchanged.

### Fixed

- Saving the edit form of an article row whose category and audience were
  frozen — any row with siblings, a translation being started, a live article
  for an editor without `local/moodesk:managekb` — no longer re-files the
  article as "No category" nor turns an agents-only article public: formslib
  exports the definition default for a hard-frozen element, and the page took
  it for a choice. The form now says which fields it offered
  (`kb_article_form::to_service_data()`), and the frozen ones never reach the
  service. Found in the v2.28.0 release gate; before KB-06 the same mechanism
  only blocked an editor's save on a live agents-only article.
- The knowledge base landing's template comment could close early and print
  its example context at the top of the page (found while checking KB-06).

---

## [2.27.0] — 2026-09-14

### Added

- **Knowledge base agent tools (KB-05, Pro).** Two tools in the agent's
  ticket workflow, gated by the new `kb_agent_tools` feature key (Pro and
  above; a Starter site refuses every call server-side, not only in the UI).
  *Insert article* is a tool of the reply composer, beside the paperclip on
  both mounts: it opens on the articles matching the ticket's own subject,
  searches as the agent types (three characters, 300 ms, eight rows, the
  ticket's category first), and drops `Title: URL` on its own line at the
  caret — what was already written stays, the caret ends after the link,
  the box grows, the focus comes back, nothing is sent. What it offers is the
  *recipient's* audience, decided by the server: everyone-only articles for a
  public reply, agents-only ones too (marked "Agents only") for an internal
  note; drafts and archived articles never, whoever the agent is. Switching
  the mode with the tool open re-queries, and — the R1 safeguard, documented
  as a UX measure and not a security boundary — an internal article inserted
  in this session shows a "review the message" warning when the reply is
  turned public; nothing is deleted or rewritten. *Create article* is an
  action in the ticket header, on open and closed tickets alike, for anyone
  who may see the ticket and edit the knowledge base (`local/moodesk:editkb`
  or `:managekb` — not `:manage`): it opens the authoring page in a new tab
  with the title and the still-active category prefilled and nothing else —
  no description, reply, note, attachment, custom field or requester data —
  writes nothing until Save, and the draft goes through KB-02's own controls.
  Underneath: the UI-only endpoint `local_moodesk_search_kb_for_reply`
  (ticket, query, mode), `kb_service::search()` with an optional `scopecap`
  that narrows the actor's scope to the recipient's and never widens it, the
  category-first composition behind `suggest()` moved into one shared helper
  with `suggest()` untouched, `search_for_reply()` and
  `draft_defaults_from_ticket()` (returns exactly title, category, ticket
  id). Plan and decisions: `docs/kb-agent-tools-ui-plan.md`.

### Fixed

- **A URL in a plain-text message is a link in the conversation.** Moodle
  runs no text filter over `FORMAT_PLAIN`, so the `Title: URL` the Insert
  article tool drops into a reply — and any address an agent typed — reached
  the requester as inert text. The conversation now renders every plain
  message through the new `output\thread_message`: `format_text()` as
  before, then the URLs in the *escaped* output made into links with Moodle's
  own `filter_urltolink` matcher, whatever the site's filter settings say.
  Presentation only: the message is stored as the text it was, typed markup
  stays text (`<b>`, `<script>` render as the characters they are, `&` is
  encoded once), an image address stays a link and is never fetched as an
  image, emails and the knowledge base reader are untouched. Found at the
  v2.27.0 release gate.

---

## [2.26.0] — 2026-09-12

### Added

- **Knowledge base foundation (KB-01).** The first unit of the Knowledge Base
  stream: everything an article *is* before anything draws one. One table,
  `local_moodesk_kb_article`, holding one row per article in one language —
  title, HTML body, a derived `searchtext`, `draft / published / archived`
  status, an `everyone / agents only` audience, an optional ticket category,
  and the `groupid` + `lang` pair that later lets translations share an
  identity. Around it: the `kb_article` domain constants and transitions, the
  repository (the only class that queries the table), `kb_access_policy` as
  the single place that decides who may read, edit or manage an article,
  `kb_service` for create / update / publish / unpublish / archive / restore
  / delete / search, the `kb_article_published` event, the `kb_article` file
  area for images embedded in a body, and full Privacy API coverage. No page,
  template, navigation entry or setting ships in this unit; the authoring and
  reader screens are KB-02 and KB-03 of the same release.
- **Knowledge base authoring (KB-02).** The screens that write the knowledge
  base, for holders of `local/moodesk:editkb` or `:managekb`, under
  Administration → Articles (right after Categories, since articles are filed
  under the same taxonomy). An articles management list — filtered by status
  and category as GET parameters, newest edit first, twenty a page, table on
  desktop and cards below 992px, with an *Agents only* indicator and distinct
  empty states for "nothing written yet" and "nothing matches these filters".
  A create / edit form on the product's form surface — title, category,
  audience, then the body in the site's editor with embedded images on the
  `kb_article` file area; the form writes content only and never changes
  status. An article page that shows the body as readers will see it inside
  the new `.mhd-prose` component, with publish / unpublish / archive / restore
  / delete as one POST each, offered only to the capability that may take
  them and with the emphasis on the workflow's next natural step (Publish on a
  draft, Edit on a published article, Restore on an archived one; Delete
  always secondary and behind a confirm). A `kb_enabled` setting hides the
  screens from the navigation as a presentation preference — the domain, the
  service and future consumers do not consult it. `header_action` learned to
  submit a form already on the page and to mark itself destructive, so a
  transition can sit in the header without the header knowing what one is.
- **Knowledge base reader and search (KB-03).** The reader's side of the
  knowledge base, under Operation → Knowledge for everyone with
  `local/moodesk:viewkb`. A landing (`kb.php`) with a prominent search box,
  a category navigation with counts beside a list of article cards (chips
  above the list below 992px), twenty a page; a global search over title and
  body that shows the twenty best of up to two hundred ranked matches with
  the terms marked, tells the reader when there were more, and asks for at
  least three characters. The article page becomes the definitive reader:
  breadcrumb, the page title as the article's heading, category and date,
  the body in `.mhd-prose` at reading width, and an "Open a ticket" call to
  action that preselects the article's category while that category is
  active — `submit.php` and the New Ticket drawer accept `categoryid` for
  it. A requester sees no editorial UI at all; editors and managers keep
  every KB-02 layer on the same page. Articles whose category was archived
  stay listed, searchable and filed under that category's name. Reading
  surfaces list published articles only, whoever is looking. Two reusable
  components arrive with it: the search box and the breadcrumb. The search
  strategy was measured against 2,000 articles on MariaDB and PostgreSQL
  before any of this was built (`docs/kb-reader-ui-plan.md` §13) and stays a
  portable `LIKE`; result depth is a deliberate MVP bound, not the search's
  final contract.

- **Knowledge base deflection (KB-04).** The New Ticket form — the drawer on
  the ticket list and dashboard, and `submit.php`, which are one form —
  carries a *Suggested articles* block between the category row and the
  description. As the subject is typed (three characters, 300 ms of quiet)
  it asks `local_moodesk_search_kb_articles`, a session-authenticated AJAX
  function outside the REST service, for up to five published articles;
  the chosen category's articles come first and the global ranking fills the
  rest, through the new `kb_service::suggest()` — two `search()` calls, no
  new ranking. Each row is a title that opens the article in a new tab, a
  one-line excerpt and the category; the form is never touched, so nothing
  written is lost by reading. A newer request always wins: replies carry a
  sequence number and a late one is dropped, never painted over the current
  list; while a newer request is in flight the previous list stays, demoted,
  with a small "Updating…" beside the heading. No match means the block goes
  quiet — a live region says so for screen readers — and on phones an idle
  block takes no room at all. The block exists exactly when the knowledge
  base does for the reader: the `knowledge_base` feature, `kb_enabled`, and
  `local/moodesk:viewkb`; the endpoint is authorised by `:viewkb` and
  `kb_access_policy` alone, deliberately not by `:submit`, and returns plain
  text only — no body, status, audience, author or score. Nothing is tracked:
  no clicks, no "did this help", no deflection rate. Plan and decisions in
  `docs/kb-deflection-ui-plan.md`.

- **Three capabilities.** `local/moodesk:viewkb` (every authenticated user, the
  reader), `local/moodesk:editkb` (create and edit drafts and published
  articles; agents) and `local/moodesk:managekb` (publish, unpublish,
  archive, restore, delete, change audience; managers). All three declare
  `riskbitmask = 0` on the reasoning recorded in `db/access.php`, which no
  longer rests on "every composer is a plain textarea": an article body is
  HTML, stored as typed and rendered through `format_text()` with neither
  `noclean` nor `trusttext`, so HTML Purifier stands between an author and
  every reader. `tests/db/access_test.php` keeps the XSS bit at zero and
  `tests/kb_html_rendering_test.php` fails the moment either option appears
  in the tree.

- **`knowledge_base` feature key.** Starter, like `sla`, `multi_department`
  and `categories`: it exists so `require_feature()` reads the same in every
  service and never blocks in any tier.

### Upgrade notes

- **`local/moodesk:editkb` reaches existing roles by cloning `local/moodesk:reply`.**
  The capability declares `clonepermissionsfrom`, so when the upgrade
  registers it Moodle copies every `role_capabilities` row of `:reply` —
  role, context and permission, whether allow, prevent or prohibit — onto
  `:editkb`. The seeded *Helpdesk Agent* role therefore gains it, and so does
  any role an administrator built that already holds `:reply`. A role that
  should answer tickets but not draft articles is one edit in the role
  editor. No role is modified by `db/upgrade.php` itself. A fresh install
  seeds the agent role with all six capabilities from `db/install.php`.


---

## [2.25.0] — 2026-09-11

### Fixed

- **The custom-fields section of the New ticket drawer collapses for real.**
  Toggling it flipped the state and turned the chevron, and the fields stayed
  where they were. Bootstrap hides a collapsed section with
  `.collapse:not(.show) { display: none }`; the drawer laid the same element out
  as a grid with a selector one class more specific, which won in every state.
  The grid is tied to the shown state now, so once the section closes
  Bootstrap's rule is the only one that matches. One selector in the drawer's
  stylesheet; the standalone New ticket page and every other form are untouched.

- **The admin forms get the product's form layout, and stop borrowing
  Bootstrap's.** Seven edit screens — department, category, team, custom field,
  automation, webhook and merge — handed their `moodleform` straight to the app
  shell and inherited whatever Boost draws by default: a `col-md-3` label column
  beside a `col-md-9` control column, flipping to stacked at 768px because
  Bootstrap says so, with `word-break: break-word` on the label.

  On the department screen that produced a 178px column breaking "Resolution
  time — Normal (hours)" across four lines, mid-word — while an iPad held
  upright sat on the wide side of the breakpoint and kept two columns it had no
  room for, and a phone, below the breakpoint, read perfectly. The screen that
  looked right was the one the framework had given up on.

  All seven now render on the same surface `submit.php` already used: a page
  header, a card, a stacked field, an action bar in the footer. The surface is a
  type now (`local_moodesk\output\form_page`) rather than three `html_writer`
  calls in one entry point, so adopting it is one line and forgetting it is a
  test failure.

- **The department SLA overrides pick their column count from the card, not from
  the window.** The eight override fields were wrapped in a `<div class="row">`
  that no stylesheet had ever styled; its only effect was to nest one Bootstrap
  grid inside eight others. They are laid out on a real grid now, and the number
  of columns comes from `auto-fit` — that is, from the width the card actually
  has. Two columns on a laptop and on a tablet held sideways, one column on a
  tablet held upright and on a phone, and the change happens before the labels
  start compressing rather than after. No breakpoint was added to the product's
  scale, because none was needed.

### Changed

- The rules that turn a Moodle form row into a stacked field live in one place
  (`scss/components/_moodleform.scss`) instead of three. The copies had drifted:
  the form card's never zeroed the grid gutter, so every control sat 12px past
  its own label, and never handled the label addon, so the required marker
  drifted to the far edge of the label cell. Both are fixed by the standalone
  form page adopting the complete set. The new ticket drawer's compiled CSS is
  unchanged — same 325 declarations, verified rule by rule — and the reply
  composer keeps its own rules on purpose: it shares moodleform's markup, not
  its shape.

---

## [2.24.0] — 2026-09-10

### Added

- **A ticket's own inbound mail stops being kept forever either.** DATA-02, the
  other half of `local_moodesk_email_message`.

  DATA-01 retires refusals at 90 days and deliberately left the successful mail
  alone: a ticket opened by email in 2026 still held the requester's address in
  this table in 2031. The nightly task now runs a second policy over
  `ROLE_TICKET_ORIGIN`, `ROLE_REPLY` and `ROLE_DUPLICATE`. New setting
  `email_correspondence_retention_days`, default **365 days**.

  **A year, against ninety days, because the two answer different questions.** A
  refusal answers "why did this message not arrive", which goes stale within a
  quarter. Correspondence answers "who actually sent this, and from what
  address" about a real conversation — what an agent reopens a year-old ticket
  to check.

  **Measured from the message, not from the ticket.** Age is `timecreated`, so a
  ticket that stays open for three years does not keep its first email's address
  for three years on that account. Anchoring to `timeclosed` would tie retention
  to a column that moves when a ticket is reopened, and no consumer reads these
  columns after the deduplication window anyway.

  **`ROLE_DUPLICATE` is correspondence, not a refusal.** A duplicate was
  recognised as one already handled, and its row points at the ticket that
  handled it — the same person's address as the origin row's. On the refusal
  window, half of one conversation would have been blanked at ninety days and
  the other half at three hundred and sixty-five. Swept, never deleted: its
  whole job is to be found by `exists_by_message_id()` on a later poll, and it
  still is.

  **The two role sets are disjoint and together cover every role.** A role in
  both would be swept by whichever policy ran first, silently applying the
  shorter window; a role in neither would keep an address forever. A test
  asserts both halves, which is the only thing standing between a new role and
  one of those two failures.

  **What survives.** `message_id`, `ticketid`, `role`, `reject_reason`,
  `sender_auth` and `timecreated` — so idempotency holds, and
  `sender_auth_for_ticket()` still shows a ticket's inbound authentication
  verdict in its header a year later. The threading headers survive here too,
  which is the one deliberate difference from the refusal sweep: DATA-01 drops
  them because on a message that never joined a thread they describe ancestry
  for a conversation that does not exist, and on correspondence the opposite is
  true.

  System Health keeps one card and now states both policies: the window in force
  and the rows taken for each, next to a single last-run time, because both
  policies run in one task and two copies of one timestamp are a reader's
  problem rather than a reader's aid.

- **Refused inbound mail stops holding an address forever.** DATA-01, split out
  of the R2 privacy work because it is a retention policy rather than a Privacy
  API gap.

  `local_moodesk_email_message` keeps an audit row for every inbound message,
  including the ones that were refused — unknown sender, forged `From:`, missing
  or invalid reply token, sender authentication failure. Those rows carry a real
  person's address, display name and subject line, and nothing has ever removed
  them: there was no purge of this table at all.

  A nightly task now blanks the identifying columns on refusals past their
  retention. New setting `email_audit_retention_days`, default **90 days**.

  **The row survives; only the person leaves it.** Deleting would take the
  `message_id` with it, and that key is what stops a refused message being
  processed again on the next poll. There is no safe window to delete inside
  either: the poller reads only UNSEEN mail and marks it `\Seen`, so a message
  sits in the mailbox until somebody removes it, and anything that marks it
  unread again makes it a candidate once more. The horizon is unbounded, so the
  key is kept for good. `role`, `reject_reason`, `sender_auth` and `timecreated`
  stay too — they say what happened and why without saying to whom.

  **The floor is a supported minimum.** Anything configured between 1 and 7 is
  raised to **8 days**, the shortest retention this plugin will act on for
  refused-mail audit rows: a value in that range is a real instruction rather
  than a typo, so it is honoured at the floor rather than discarded. An unset or
  zero value falls back to the default instead — a site that never configured
  retention has not asked for an aggressive sweep, and no purge in this plugin
  reads zero as "keep forever".

  **The sweep follows the role, not `ticketid IS NULL`.** The two look
  interchangeable and are not, in both directions: a token refusal records the
  thread it was aimed at when one was identified, so refusals carrying a ticket
  id exist; and `ROLE_DUPLICATE` carries the origin ticket's id while being no
  refusal at all. The purgable set is named once, in
  `email_message::PURGABLE_AUDIT_ROLES`.

  **A live ticket's own correspondence is deliberately out of scope.**
  `ROLE_TICKET_ORIGIN` and `ROLE_REPLY` are a ticket's record rather than a
  refusal's, and retiring them is a general retention policy for ticket
  correspondence — a different question, with consequences for support and
  traceability. Split to DATA-02.

  System Health gains a card in the existing shape: the policy in force, when
  the sweep last ran, and how many rows it took. The policy shown is the
  effective one, so a site that typed 3 sees the 8 that actually runs.

  One new column, `ttl_purgedat`, records when a row was swept — which keeps the
  count honest on a re-run and lets the health card tell "never ran" apart from
  "ran and found nothing". **`$plugin->version` is bumped** for the schema
  change.

### Security

- **Every capability now tells an administrator what granting it permits.**
  Audit finding R1. `db/access.php` declared nineteen capabilities and not one
  `riskbitmask`, so Moodle's role editor — the only place most operators ever
  learn what a capability actually allows — warned about none of them. It closes
  no vulnerability; it stops the plugin from being silent about its own
  permission surface, and it is a standard item on the Moodle plugin review
  checklist.

  The masks describe the surface rather than the worst case, because a bit that
  is not true here is a false warning and costs the mask its meaning:

  - **No capability claims `RISK_XSS`.** Both composers are plain textareas
    stored as typed and rendered through `format_text($message, FORMAT_PLAIN)`,
    and nothing in the tree passes `noclean`. A holder of `:submit`, `:reply` or
    `:addnote` cannot put markup in front of another user.
  - **`RISK_SPAM` follows the observers.** `:submit` mails the agents, `:reply`
    mails the requester, `:manage` reassigns and mails the new assignee, and an
    automation rule reaches all three across many tickets at once. `:addnote` is
    deliberately excluded: an internal note notifies nobody, because
    `observer::ticket_replied()` returns early for anything that is not a public
    reply.
  - **`RISK_PERSONAL` follows reach.** `:viewall` and `:manage` both satisfy
    `ticket_access_policy::can_view()`, `:viewreports` exposes per-agent names
    and metrics, `:manageagents` gates the supervision view's individual
    response and resolution times, and `:managewebhooks` ships ticket subjects
    and requester ids to an operator-chosen URL.
  - **`:manageagents` carries `RISK_MANAGETRUST`** — it is the only thing in the
    plugin that calls `role_assign()`/`role_unassign()`.
  - **Merging and reverting a merge carry `RISK_DATALOSS`** — they restructure
    tickets and take one out of the queue.
  - **`:useapi` carries `RISK_PERSONAL`, not core's five-risk mask.** Since
    SEC-02 it buys self-service only; reaching another account needs
    `moodle/webservice:managealltokens`. What it still does is mint a long-lived
    credential that reads ticket data.

  Four capabilities declare `0` deliberately: `:viewown`, `:agent`,
  `:addnote` and `:viewhealth`. `tests/db/access_test.php` requires the
  `riskbitmask` key on **every** capability — zero is an answer, absence is not —
  and pins all nineteen masks so widening one silently is a test failure.

  **`$plugin->version` is bumped** because Moodle applies capability metadata
  during the plugin upgrade: verified against a 2.23.0 install, deploying the new
  `db/access.php` alone reports "No upgrade needed" and leaves every mask at 0.

### Fixed

- **System Health's sender authentication card pairs its labels with its
  values again.** The three-column grid took `dt` and `dd` as separate cells, so
  a label and the value under it landed in different rows as soon as the reader
  had more than one pair to follow. Each pair is now one cell, which is what the
  retention card already did. Markup only — no styles, no content, no new
  strings.

- **Inbound mail is exported and erased by who sent it, not by whose ticket it
  landed on.** PRIV-01, found while designing DATA-02.

  The Privacy API reached `local_moodesk_email_message` through `ticket.userid`
  alone. An agent answering a ticket **by email** writes a row carrying their own
  address, display name and subject line onto somebody else's ticket, and that
  one attribution got four things wrong at once:

  - a subject access request by the requester **handed them the agent's address,
    name and subject lines**, on the strength of owning the thread;
  - the agent's own rows were **absent from the agent's own export**, because the
    only other route was filtered to mail that never reached a ticket;
  - the agent's address **survived their own erasure**, falling between a
    ticket-scoped pass that ran for the requester and an address-matched pass
    that ignored rows with a ticket. Refusals that identified the thread they
    were aimed at — a rejected or mismatched reply token — were outside both for
    the same reason;
  - erasing the **requester** blanked the sender columns on other people's rows,
    destroying a third party's security evidence as a side effect.

  **A row has more than one subject, and they are asked separately.**
  `sender_email`, `sender_name`, `resolved_userid` and `raw_subject` answer to
  the sender; `token_userid` answers to the user the conversation token was
  issued to, who on a sender mismatch is deliberately somebody else. Owning the
  ticket is not a claim on either. A row held through two relations is exported
  once, carrying both halves.

  **The subject line follows the sender, not the ticket.** It is what that person
  wrote in their message, and routing it to the ticket's requester is the same
  disclosure in a different column.

  **A sender is recognised by an id where one exists** — `resolved_userid`, then
  the author of the reply the message created, then, on an origin row, the ticket
  the message opened as its sender. The declared address is the last resort, for
  rows written before SEC-04A, and it is **skipped entirely when two live
  accounts share it**: `allowaccountssameemail` makes that possible, and
  attributing a message to the wrong account would export one person's mail to
  another and anonymise it on the wrong request. Discovery applies the same rule,
  so it never lists a user erasure will not act on.

  **Nothing that makes the row work is touched.** `message_id` stays, so a
  message anonymised by erasure is still refused on the next poll — the same line
  DATA-01 holds for retention — along with the threading headers, `role`,
  `reject_reason`, `sender_auth` and `timecreated`.

- **A data subject now gets their attachments, and an erased user stops being
  named by nine tables.** Audit finding R2 — the Privacy API declared eleven of
  the plugin's twenty tables and reached less than it appeared to.

  - **Export was returning a requester's words without their files.** The
    requester branch exported the `ticket_attachment` area and never
    `reply_attachment`, while the agent branch had always done both. The gap is
    wider than it sounds: email ingestion writes **every** attachment to
    `reply_attachment`, including the one on the message that opened the ticket,
    so a requester who works by email had no attachment exported at all.
  - **Nine undeclared tables.** `webhook_queue` and `webhook_log` carry payloads
    quoting the ticket subject and the requester id; `rule` embeds the agent it
    assigns to; `rule_log.note` describes what a rule did; and `webhook`,
    `department`, `category`, `team` and `cfield` each record who last edited
    them. None were declared, and the queue, the log and the rule log were not
    removed when a whole context was wiped either.
  - **`usermodified` is now cleared on erasure — on eleven tables, not the six
    the audit listed.** The provider's own docblock had called it personal data
    from the beginning while no code ever touched it. `cfield_value`,
    `csat_rating` and `team_member` were found by the new structural test rather
    than by reading the schema, which is the argument for having it.
  - **Refused mail is reachable by the person it names.** An inbound message
    that never became a ticket leaves an audit row with `ticketid IS NULL`
    carrying the sender's address and name, and every code path joined through
    `ticketid` — so those rows were reachable by nothing. Where the address
    belongs to a Moodle user, the row is now in that user's export and is
    anonymised with them.
  - **User preferences are declared, exported and erased.** The sidebar state
    and every saved view — each holding a name the user typed and the filters
    behind it. The provider did not implement `user_preference_provider` at all.
  - **Erasing one person now empties the outbound traffic about their
    tickets.** A webhook payload is a snapshot taken when the event fired, so
    redacting the ticket never reached it: `webhook_log` went on quoting the
    subject as written and naming the requester by id, and `rule_log.note`
    described what an automation did to whose ticket. The queue is deleted
    rather than emptied — a queued row is a delivery about to leave the site,
    and an erased person's subject must not be sent to an operator's endpoint
    after they have asked for it to go. The log row survives with the copy of
    the ticket taken out of it, because whether the endpoint accepted a
    delivery stays true.
  - **An erased user is no longer named inside a JSON snapshot on somebody
    else's row.** Three columns freeze a user id into text at the moment an
    event happened — `webhook_queue.payload`, `webhook_log.payload` and
    `ticket_history.oldvalue`/`newvalue` — and redacting a row's own columns
    never reached them. The case that scoping by ticket cannot find: an agent
    assigned to a ticket somebody *else* raised. The payload keeps the assignee
    it was written with even after the ticket has been reassigned, so the ticket
    does not lead back to the name inside it either.

    **The id becomes `-1`, and the choice is the substance of the fix.** The
    snapshot schema already gives two values a meaning: `null` **is** the word
    for unassigned, and `0` is the unassign sentinel the bulk form sends
    (`$assigneeid > 0 ? $assigneeid : null`). Writing either would rewrite
    "assigned to this person" into "unassigned" — inventing an event that never
    happened. Dropping the key is worse still, because the idiomatic reader
    spells it `$decoded['assigneeid'] ?? null` and lands back on unassigned with
    nothing looking wrong; a string marker breaks the documented integer type
    and casts to `0` anyway. `-1` collides with nothing the schema means, can
    never resolve to a person, and stays an integer, so every existing reader
    survives it.

    Redaction walks **named keys**, never values: a snapshot is full of
    integers, and hunting for the number would rewrite a ticket id or a status
    that merely happened to match. The keys are `ticket.requesterid`,
    `ticket.assigneeid` and `extra.actor` in a payload — `ticket_field_changed`
    never reports an assignee, so the other `extra` fields are left alone — and
    `assigneeid` in a history value.

    The queue row survives rather than being deleted: the ticket belongs to
    somebody else and their integration is entitled to the event. A test drives
    the real dispatch afterwards and reads back the body that was posted.

    **`webhook_log.response_body` is emptied on any row this touches**, rather
    than redacted. It is whatever the operator's endpoint wrote back — JSON,
    XML, HTML, a stack trace, a bare string — and receivers commonly echo the
    request, so it may quote the ticket and the person the payload named. There
    is no key to walk, and `deliver()` truncates it at 4096 bytes, so even a
    JSON response is routinely cut mid-structure and will not parse. It is a
    diagnostic log rather than contractual state, and what matters
    operationally — `http_status` and `success` — is untouched. Emptying is
    coupled to redaction, not applied to the table: another person's delivery
    keeps its diagnostics.
  - **A full context wipe no longer leaves configuration naming people.** Six
    tables survive the wipe by design — `department`, `category`, `team`,
    `cfield`, `webhook` and `rule` describe how the site is set up, not what
    anybody did — but their `usermodified` is declared personal data all the
    same, and surviving the wipe was being read as keeping the name. The rows
    stay and the names go. `rule.actions` deliberately keeps the agent it
    names: that is the instruction the rule executes, not provenance, and
    blanking it would leave a live rule assigning tickets to nobody.

  **`usermodified` was decided one table at a time, not once for eleven.** All
  eleven land on the same treatment — keep the row, clear the reference — but
  for eleven different reasons, and the reason each row survives is recorded
  next to it in `provider::USERMODIFIED_TABLES`. Deleting the row was the right
  answer for the erased user's own department and team memberships, and is the
  wrong one here: a `usermodified` row belongs to somebody else — the team whose
  membership this person edited, the ticket another user raised — so deleting it
  would erase a second person's data to satisfy the first one's request. Keeping
  the reference would need a lawful basis for continuing to name an erased user,
  and provenance is not one.

  `merge_audit.source_snapshot` stays the one documented exception: unlike these,
  it has a concrete functional need — it is the payload revert reads to restore a
  ticket — so it remains tied to its retention mechanism and is cleared when the
  revert window closes, rather than being scrubbed on erasure and silently
  breaking the undo.

  **Known limitation, deliberately not addressed here.** An orphan row whose
  address matches no Moodle user cannot be reached by the Privacy API: there is
  no data subject, and having no account is often precisely why the message was
  refused. That is retention rather than privacy, and there is currently no purge
  of this table at all. Designed separately in
  DATA-01.

  `tests/privacy/metadata_coverage_test.php` compares `db/install.xml` against
  the declaration on every run, so a table or a person-shaped column added later
  fails until somebody declares it or records, with a reason, that it holds no
  personal data — and a twelfth `usermodified` column fails until somebody says
  what erasure should do with it. `tests/privacy/attachment_export_test.php`
  drives the four routes a file arrives by — the web composer at ticket
  creation, a web reply, a ticket opened by email, an emailed reply — through
  the real services rather than seeding a row where a service is assumed to put
  one, and asserts that an agent's attachment is exported to the agent and not
  to the requester whose ticket it sits on. Ownership is read from the reply's
  author, never from the file area. `tests/privacy/snapshot_redaction_test.php`
  covers the four JSON columns, that other people's ids in the same snapshot
  survive, that a number merely equalling the erased id in a ticket id, status,
  priority, category, reply id or timestamp is left alone, that an unparseable
  payload is not rewritten, that a second erasure changes nothing, and that a
  redacted delivery does not carry the erased id out of the site. No schema
  change.

- The comment block describing outbound webhook management sat above
  `:viewhealth`, leaving `:managewebhooks` undocumented and `:viewhealth`
  described as something it is not.

---

## [2.23.0] — 2026-09-07

> **2.22.0 was never released, and never will be.** The number was written into
> `version.php` and given a dated section in this file while the work was still
> in progress, and no tag, GitHub release or artifact was ever produced for it.
> A dated heading here means "released", so leaving one for a version nobody can
> install was the changelog stating something untrue. Everything that had been
> filed under 2.22.0 is folded into 2.23.0 below, together with the eight
> changes that landed after it and were never written down at all. Nothing
> upgrades *from* 2.22.0: the release before this one is `v2.21.0`.
> `dev_scripts/check-release-metadata.php` now fails CI on this shape.

### Security

- **SEC-01 — an executable attachment is handed over as a download, never
  inline.** A ticket attachment is chosen by the least trusted party in the
  system — a requester, or on a site with email ingestion, someone with no
  Moodle account at all — and `pluginfile.php` serves it from the site's own
  origin to an agent. The callback forwarded the request's own `forcedownload`
  flag straight through, and core forces a download for SVG and nothing else, so
  an `.html` attachment came back as `Content-Disposition: inline` and ran as
  script in the reader's session. `\local_moodesk\util\attachment_disposition`
  now decides, reading core's own `web_file` typegroup (html, xhtml, htm, css,
  js, scss) plus SVG rather than a list of its own, so a future Moodle adding a
  web type is covered without a change here. Images, PDFs and plain text still
  open in the browser. A caller that already asked for a download is never
  weakened.

- **SEC-02 — API tokens are self-service unless a role is trusted with
  everyone's.** A mooDesk API token acts as the user it is bound to, so minting
  one for somebody else is impersonation. `issue()` checked
  `local/moodesk:useapi` on the actor and then took the target user id from the
  request without ever relating the two; `get_all()` and `revoke()` were
  site-wide. `:useapi` ships on the manager archetype, which holds none of
  `:viewall`, `:manage` or `:reply` — so a manager could mint a credential
  acting as a site administrator and read, reply to, reassign and merge every
  ticket on the site. `:useapi` now buys self-service and nothing else; reaching
  another account needs `moodle/webservice:managealltokens`, a core capability
  no role holds until an administrator grants it; a token bound to a site
  administrator may only be minted by one. Revoking someone else's token answers
  exactly as a missing one does, so the error cannot be used to enumerate token
  holders. **Existing tokens are left untouched by the upgrade** — nothing in
  the data distinguishes a legitimate one from the rest — so review them: see
  the upgrade note in `docs/api-reference.md`.

- **SEC-03 — an emailed reply now has to be allowed, and an over-long header no
  longer loops.** `append_reply()` resolved the `From` address to a Moodle
  account and wrote a public reply into whichever ticket the `In-Reply-To`
  lookup matched. Resolving a sender says who they are; it never said what they
  may do, so one stored `Message-ID` — which every recipient of the original
  mail holds — was enough to post into a stranger's thread as a real user.
  `ticket_access_policy::can_reply()` is now asked: system actor, then reach,
  then `:reply`, then the requester's own ticket. A denial is terminal: no reply
  and no fallback ticket, and nothing goes back to the sender, because any
  answer discloses whether the ticket exists. Separately, `record()` wrote five
  header-derived values into `char(255)` columns unchecked after the ticket
  transaction had committed, so an over-length header left a ticket with no
  audit row — and both duplicate guards read that row, so every poll made
  another ticket. Values are fitted with `core_text::substr()`; the `Message-ID`
  is not, because it is an identity, and becomes a deterministic sha1 surrogate
  through one function that storage, the idempotency guard, `In-Reply-To` and
  the `References` chain all call. `RESULT_ERROR` is split from
  `RESULT_UNPROCESSABLE`, which is flagged and leaves the working set.

- **SEC-04A — an emailed reply needs a token mooDesk issued, not just a `From:`
  anyone can type.** Reply authorisation rested on a header that is trivially
  forged. mooDesk now puts a secret in the `Reply-To` of its own notifications
  that names one ticket and one recipient and is verified by a 128-bit MAC
  rather than trusted for where it was found. It says which conversation and who
  it was sent to; **it does not say who wrote the message** — a forwarded token
  is still a bearer secret, bounded by the ticket binding, the user binding, the
  expiry, and the checks run on every use. Token version sits inside the MAC
  input as well as in front of the token, so a token minted under one version
  can never be reinterpreted under another; only our exact token shape earns a
  refusal, so a coincidental `+…` plus-tag from someone else cannot block real
  mail.

  Three operator-facing consequences:

  - **Sub-addressing has to be proved before it can be enforced.** The mode
    setting refuses `require_token` until a probe message sent to the
    sub-addressed support address has come back through the poller, for the
    address configured *right now* — changing the address withdraws the proof.
    Arming enforcement where a tokenised address never reaches the mailbox is
    not a policy, it is a guaranteed outage.
  - **New installs default to `require_token`; upgrades land on
    `prefer_token`.** There are no pre-token threads on a new install to break;
    an existing one refuses nothing on the day of the upgrade while producing
    the counts an operator needs before choosing to enforce.
  - **Reply access to one conversation can be withdrawn.** Raising the ticket's
    token generation invalidates every reply address handed out for it in one
    write, and everyone on the thread gets a fresh one with the next
    notification. Guarded by `ticket_access_policy::can_manage()`.

  The RFC 5321 local-part budget leaves 13 octets for the configured address
  (64 − 1 delimiter − 50 token), which covers `support`, `soporte`, `tickets`
  and `helpdesk` but genuinely not `customersupport`. It is checked against the
  address an operator actually typed, never a presumed `support@`.

- **SEC-04B — a forged `From:` can no longer open a ticket in somebody else's
  name.** The other half of the same finding. Where SEC-04A authenticates a
  *conversation*, this authenticates a *sender*: forging `From:` on first
  contact created a ticket attributed to a real Moodle user, with a subject and
  body of the attacker's choosing, and made the site email that to every
  notified agent from its own address. An SMTP client and one active user's
  address were the whole attack.

  **mooDesk does not verify SPF, DKIM or DMARC, and this does not pretend to.**
  It cannot: SPF authenticates a connecting IP and an envelope sender, both gone
  by the time a message is fetched over IMAP, and checking DKIM would need DNS
  from cron plus the byte-exact message as signed rather than the copy a
  provider's store returns. The only usable evidence is the verdict the
  **receiving mail server** stamped on the message, so the whole question is
  *which stamp is ours*. A header must lead with an authserv-id the operator
  configured, the topmost one bearing it wins, and everything below it is
  discarded before anything is evaluated — including any the sender wrote.

  - **Three modes: `off`, `warn`, `require`.** `warn` is the default on a fresh
    install **and** on an upgrade — deliberately unlike SEC-04A's reply mode,
    which starts strict on a new site. A new site has no pre-token threads, so
    strict replies break nothing; strict first contact on a site with no
    provider profile configured would refuse every inbound ticket. Refusing
    first contact is a business decision and it stays the operator's.
  - **`require` is fail-closed.** Only an authenticated sender opens a ticket.
    A failed verdict, a sending domain with no policy, a message carrying no
    verdict, a site that cannot judge at all, and a verdict the configured
    profile cannot read all refuse. A provider mooDesk cannot read must never be
    the reason a stricter policy quietly relaxes. Refusals are terminal — no
    ticket, no fallback ticket — audited, raised as an operator incident, and
    silent to the sender. The person whose address was forged is deliberately
    **not** told: mailing them would deliver attacker-chosen text to a recipient
    the attacker picked, from the institution's own address.
  - **Enforcement has to be earned, and it is earned by observation.** There is
    no probe to shortcut it; a probe would travel the site→mailbox path and say
    nothing about what a provider stamps on mail from the outside world. The
    setting refuses `require` until the configured profile has demonstrably been
    reading real mail: over the last 7 days, at least 20 evaluated messages, at
    least 10 authenticated, and at least 80% producing a verdict the profile
    could read. Once armed it is never relaxed — evidence drying up raises an
    error and keeps refusing.
  - **Agents see it in `warn`.** A ticket opened by an evaluated email carries a
    pill on its detail page: sender verified, sender authentication failed, or
    sender authentication unavailable. It is shown for the verified case too, so
    that no pill means "not from email, or not evaluated" rather than something
    ambiguous. System health gains the verdict distribution for the window and
    says whether `require` can be armed.
  - **Gmail / Google Workspace is the profile that ships**, and its authserv-id
    is configured rather than assumed — a tenant on its own domain may stamp
    something other than `mx.google.com`. A second profile covers any other mail
    server, and produces a verdict only when the operator explicitly asserts
    that their boundary server strips inbound headers impersonating its own
    identifier; mooDesk cannot check that and says so. Without the assertion it
    yields no verdict, which is the honest answer.
  - **Microsoft 365 has no profile and is not offered as one.** Its
    `Authentication-Results` format has not been validated against a real
    tenant, and shipping a profile written from documentation would look like
    security without being it. An Exchange Online site uses the generic profile
    with the assertion, or gets no verdict.

  No schema change: the audit column SEC-04A reserved for this is now written.
  Null still means the question was never asked, which stays distinct from "asked
  and unanswerable".

### Changed — access contract (read this before upgrading)

- **`local/moodesk:viewall` is now genuinely global, and the ticket detail agrees
  with the ticket list.** Product decision of 2026-09-04. A user holding
  `local/moodesk:viewall` can reach **every** ticket — in the list, on the detail
  page, in its attachments, and in every operation that depends on visibility.
  Department and team membership decide what an agent *looks at* by default (the
  Department and Team views); they no longer decide what an agent is *allowed to
  reach*.

  Before this release the ticket list was already global for `:viewall`, while
  `ticket_service::get_ticket()` applied a department rule of its own — so an
  agent could see a row in the queue, open it, and be told the ticket does not
  exist. The rule now lives in one place,
  `\local_moodesk\policy\ticket_access_policy`, and the detail page, the
  `pluginfile` callback and the merge-revert path all ask it instead of restating
  it.

  **Operator impact.** On a default install nothing changes: the shipped
  `mhd_agent` role holds `local/moodesk:manage`, which was already global. Sites
  that hand-built a `:viewall`-without-`:manage` role and *relied on* the detail
  page's department scoping as a confidentiality boundary will find that boundary
  gone — it was never enforced on the list, so it was never a boundary. Review
  those roles before upgrading. Departmental isolation, if wanted, has to be
  expressed through explicit capabilities an administrator can see in the role
  editor.

### Added

- **A results region that can be rebuilt on its own.** The part of the list that
  depends on what the query returned — the list controls bar, the empty state,
  the rows and the pagination — is now `templates/ticket_list_results.mustache`
  behind `data-region="mhd-ticket-list-results"`, produced by
  `\local_moodesk\output\ticket_list_results`. `index.php` includes it as a
  partial and the new `ticket_list_results` fragment renders the same component,
  so the initial paint and every refresh come from one implementation. There is
  no second rendering path for them to drift into.

- **`\local_moodesk\output\ticket_list_query`.** One value object over the
  fifteen parameters the list is filtered, sorted and paginated by, with two
  doors into the same cleaning: `from_request()` for the page and
  `from_query_string()` for the fragment. The fragment is handed the whole query
  string rather than fifteen named arguments, so a filter added later needs no
  change to the fragment at all.

- **`moodesk:ticket-changed`.** A document event meaning "the stored state of a
  ticket changed", raised once from the single point all six rail properties
  already funnel through. It does not say which field moved and the listener
  does not ask: `local_moodesk/ticket_list` re-runs the query on the server and
  replaces the region with the answer. Whether a ticket still belongs in the
  list — after a status change, under the filters in force, on which page — is
  decided by the backend and never by the client, so a field added to the rail
  later is covered without a line of new logic.

- **A word when a ticket leaves the view.** Closing a ticket while filtering on
  `Open` correctly removes its row, the total and the pagination recompute, and
  the drawer stays open. A discreet notice says the ticket was updated and no
  longer appears here, so the change does not read as having done nothing.

- **Conversation / Details tabs on a narrow ticket detail.** `detail_tabs.js`
  had been in the tree and compiled into `amd/build` since 9809dcf with nothing
  ever calling its `init`, so the tab strip rendered `hidden` and what actually
  shipped below the collapse width was the stacked fallback. It is wired now.
  The module keyed off `tablet` (≤768px) in both mounts, and only the drawer
  collapses there — the canonical page collapses at `below-desktop` (≤991px),
  which `modules/_ticket-detail.scss` has always said, so between 769 and 991
  the page stacked its rail with no tabs to reach it by. Each mount now watches
  the query that owns its own layout. Neither width is a new number: both are
  existing tokens, and the two the stylesheet already uses. Guarded under Behat
  `@javascript` at 360, 390, 576 and 768.

### Changed

- **The ticket's own fields moved out of the conversation and into the rail.**
  The detail screen's main column held the thread, the custom fields, the fields
  carried across a merge, and the composer under all of them — so in the drawer,
  where the column is a scrollport a few hundred pixels wide, reading a thread
  meant scrolling past a form. Custom fields and merged source fields render in
  the management rail now, directly under Merge ticket; the conversation column
  is the thread and the composer and nothing else. The rail is `hasrail`, i.e.
  `local/moodesk:manage`; a requester has no rail, so for them the same two
  partials still render read-only in the main column, which is the view they
  have always had. One component mounted in one of two places, never both. The
  page and the drawer share the template and keep sharing it.

- **The send button is joined to the message box.** The composer frame carries
  the outer radius and clips to it, but the button kept `border-radius: 6px` on
  all four corners, so its two left corners curved away from the box and let the
  frame's white background show through as a notch at each end of the join. The
  button is round on the right only now. Separately, Bootstrap's
  `.form-control:focus` painted its translucent 4px halo outside the control, so
  a focused box spilled a soft second ring into the composer's inner margin that
  stopped dead at the button's edge; the halo is cleared on `:focus` in this
  rule only, leaving the inset outline as the box's one ring, and `.is-invalid`
  restates `:focus` so its red inset line survives focus.

- **`index.php` went from 776 lines to 520.** The query parsing, the fetch,
  the row building and the pagination moved into the two new classes. The filter
  bar, the chips and the saved views stayed exactly where they were: they are a
  reading of the URL, not of the result, and rebuilding them was never the
  problem.

- **The active filter chips and the bulk toolbar moved above the list controls
  bar.** The three results-dependent blocks were not adjacent, and one region is
  what makes one refresh possible. The chips now sit with the filter bar they
  describe, and the select-all bar sits against the rows it heads — which is
  what `scss/modules/_ticket-list.scss` already said it was for.

- **Select-all is bound by delegation.** It lives inside the region that gets
  replaced, so a listener bound to the node found at init would attach to markup
  that no longer exists. Re-running `bulk_actions.init()` would have doubled
  every other handler in that module, so the one control that needed it was
  delegated instead. Selections do not survive a refresh, and are reconciled
  rather than remembered: no id can stay selected without a checkbox to show it.

### Fixed

- **A property saved from the ticket detail drawer now reaches the list behind
  it.** Changing a status, priority, category, department, team or assignee in
  the drawer's rail posts to `ajax.php` and never navigates, so the rows behind
  the panel kept whatever the page load had put there. Only two of the six
  actions wrote anything back at all, and both wrote it *inside* the panel. The
  list was a snapshot with no way to invalidate it, and a full page refresh was
  the only way to see the change land.

- **Raising a ticket and going back now shows it.** The new-ticket drawer posts
  to `submit.php` and lands on the new ticket's page, so the way back to the list
  is the browser's Back button. Moodle serves these pages with
  `Cache-Control: private, max-age=0` — "allow caching on back", deliberately —
  so the browser restored the frozen DOM and the PHP never re-ran. `pageshow`
  with `persisted` catches that restore and runs the same refresh.

- **An agent role that came back from an uninstall gets its capabilities.**
  `db/install.php` granted the five agent capabilities only on the branch that
  *creates* the role. A role with that shortname survives an uninstall — roles
  are site data — but `uninstall_plugin()` does remove every `role_capabilities`
  row for a capability the plugin defined, so a reinstall found the role, took
  the existing-role branch, granted nothing, and reported success: an agent role
  holding zero permissions, every agent locked out, no error anywhere. The
  existing-role branch now asks one question — does this role hold any
  `local/moodesk:*` capability at all, in any state, at any context? If none, it
  came back from an uninstall and the five defaults are restored; if any, the
  role is the administrator's and is not touched, because allowed, prevented or
  prohibited, a row is a decision. The context-level restriction is applied only
  when the role is created: where a role may be assigned is the
  administrator's to change, and a reinstall does not reset it.

- **An empty composer gives one answer, not two.** Pressing send on an empty
  reply produced moodleform's inline "You must supply a value here." and, a
  moment later, a generic "The change could not be saved." toast, because the
  AJAX send went out anyway and the server refused a message the browser had
  already refused. The order was the bug and it was decided by listener
  registration: two submit listeners on one element fire in the order they were
  added, and `reply_submit` bound first, ran first, saw nothing prevented, and
  sent. The listener moves from the form to the composer wrapper
  (`#mhd-reply-editor`), in the bubbling phase — the form is the event's target,
  so the validator bound at the target always runs first and `defaultPrevented`
  is settled by the time the event bubbles up. No ordering assumption, no
  dependency on cache state or queue position.

### Removed

- `RESTRICTIVE_MODE` and its `passes_restrictive_check()` stub — a private
  constant that would have scoped `:manage` to department membership with no
  capability declaring it. Superseded by the access decision above.
- `ticket_repository::get_accessible_for_agent()`, `count_accessible_for_agent()`
  and `build_accessibility_sql()` — no caller, no test, never executed against a
  database, and contradicting the decided contract.

### Notes

- The dashboard mounts the same new-ticket drawer and has the same staleness for
  its widgets. It is deliberately out of scope here and needs its own ticket.
- SEC-04A binds the conversation, not the author; SEC-04B authenticates the
  sender. Both ship here, and neither substitutes for the other — a valid
  conversation token still says nothing about who wrote the message.
- ARC is not implemented. Accepting forwarded mail on the strength of an ARC
  chain needs a real forwarded message to design against and none was available,
  so no code path can produce that verdict. Forwarded mail whose DKIM signature
  still aligns is accepted by the ordinary rule.

---

## [2.21.0] — 2026-08-31

**One page header for the whole product, and a `+` that means the same thing on
every screen.**

### Added
- **The mooDesk Page Header pattern.** Twenty-eight screens carried eight
  different header idioms: `.mhd-list-header` (the one deliberate abstraction),
  `.mhd-dash-header` (a near-copy in the dashboard module), `.mhd-page-header`
  (a third copy under the most general name in the product, living inside
  `modules/_reports.scss`), `.moodesk-form-page__head` (a fourth, built with
  `html_writer` in PHP), a raw Bootstrap `d-flex justify-content-between` on
  eleven screens in two spacing variants and two alignments, a bare `<h2>` on
  two, and nothing at all on eight. Four title sizes were in play, and on the
  eleven Bootstrap screens the size was **whatever the site's Moodle theme said
  an `<h2>` was** — `base/_app-reset.scss` resets a heading's margin, colour,
  weight and line-height, but not its size.

  The pattern is now three files: `classes/output/page_header.php` (a builder),
  `classes/output/header_action.php` (a value object), and
  `templates/page_header.mustache` with its action partial. Styling is
  `scss/layout/_page-header.scss` and nowhere else, which
  `tests/page_header_markup_test.php` enforces.

- **The primary action is a named slot, not a position.** `with_action()`
  appends a secondary action and renders it outlined; `with_primary_action()`
  sets the one primary action and renders it filled, last. `header_action`
  carries no variant and no size, so "primary first" and "outlined primary" are
  not expressible from a call site. The invariant stopped being a convention and
  became a property of the type.

- **`#mhd-icon-plus`.** The same glyph as `newticket`, under the name that says
  what it means wherever it is not about a ticket. `newticket` is a navigation
  key from `nav_item`, and a "New department" button borrowing it read as a
  mislabel every time anyone opened the template.

### Changed
- **Every "New &lt;entity&gt;" action is now the plus glyph alone.** Nine
  buttons across eight screens — New Department, New Team, New Category, New
  Custom Field, New Automation Rule, Add webhook, Add agent, and New ticket on
  both the ticket list and the dashboard — render as `[+]` with no visible
  label. The label is not discarded: it becomes the button's `aria-label` and
  its `title`, so the control still announces itself to a screen reader and
  still names itself on hover. Empty-state calls-to-action keep their labels —
  an empty state exists to explain what to do next, and a lone glyph explains
  nothing.

- **One title treatment, product-owned.** `--mhd-font-size-xl` (22px) at
  `--mhd-font-weight-bold`, with literal fallbacks so the reports screen's
  printable view — which renders outside the shell, where the tokens do not
  resolve — does not come off the printer as an unstyled document. Changing the
  site's Moodle theme no longer changes the size of a mooDesk page title.

- **One header bottom margin** (`$mhd-space-lg`), **one action gap**
  (`$mhd-space-sm`), **one vertical alignment** (`flex-start`, whether or not a
  description is present). Alignment used to track whether a description
  happened to exist, so two sibling screens put their buttons at different
  heights.

- **The page title is now an `<h1>`.** `app_page` renders on Moodle's `embedded`
  pagelayout, which draws no page heading of its own, so every screen but one
  opened its heading outline at `<h2>` with nothing above it.

- **Reports** promotes Print from one of three outlined buttons to the primary
  action — the header previously offered no entry point at all — and the
  printable view's date range moved from a smuggled-in paragraph to the header's
  description.

- **License** gained a header and its one filled action moved from the bottom of
  a diagnostic table, below the fold on most viewports, to where a page's one
  commitment belongs.

- **The header now sits above the edition gate**, not inside the success path,
  on the nine gated screens. A Starter user used to meet the upgrade prompt on a
  blank canvas with nothing saying which screen they were on.

### Fixed
- **Two screens had the primary action on the left.** The dashboard rendered
  `[New ticket (filled)] [View all tickets (outlined)]`, and the agents
  directory rendered `[Add agent (filled)] [Manage roles in Moodle (outlined)]`.
  The agents header additionally used `flex-column flex-sm-row`, so below 576px
  the pair stacked with the filled button **on top** — the inversion survived
  the reflow rather than being corrected by it.

- **Titles no longer truncate.** `.mhd-list-header` refused to wrap and
  ellipsis-clipped the title below 576px instead. The measurement behind that
  was taken against "Tickets" plus one button at 390px and does not survive
  "Agent supervision" with two. Titles wrap now, in place, without giving up the
  line.

- **The actions never leave the title's line.** The header band is
  `Title → space → action(s)` at every width, phones included, with the
  description under it. The first version of this component let the action row
  drop to a second, full-width line once the title could no longer hold 220px:
  on Agents at 402px that stranded "Manage roles" and the `+` on a line of their
  own, under a title that had the whole band to itself. The row no longer wraps
  at all. When it is too narrow, the **actions** fold first — the buttons wrap
  inside their own right-hand column, so Reports shows two short rows of buttons
  beside its title rather than one row beneath it — and only once they are as
  narrow as their widest button does the title start giving up width. Checked at
  288, 320, 360, 402, 480, 576, 768, 992 and 1200px, in English and Spanish,
  against every header the product renders: no second row, no overlap, no
  horizontal overflow.

- **A Spanish action label that could not fit a phone.** `api_manage_full` was
  "Administración de servicios externos", a single 246px button — wider on its
  own than a 320px screen has room for once the title and the gap are paid for.
  It is now "Servicios externos". The English label is unchanged.

- **Five screens had no page title at all** inside the shell: department
  members, team members and license (which opened straight onto a
  `.mhd-datatable-title` panel heading or an alert), plus both steps of the
  merge flow. `$pagetitle` was being computed and handed to `set_title()` and
  the navbar, neither of which the `embedded` layout renders.

### Notes
- Six edit forms — `department_edit`, `team_edit`, `category_edit`,
  `custom_field_edit`, `automation_edit`, `webhook_edit` — are **deliberately
  unchanged**, pending a redesign of the form pages as a group. They still
  render no page header. The boundary is enforced in both directions by
  `page_header_markup_test`: one test excludes them by name, another asserts
  they have not quietly acquired one.
- The ticket detail screen keeps `.mhd-detail__header`. That is the *ticket's*
  identity — pills, subject, `#id · requester · created` — shared verbatim with
  the detail drawer, and it answers a different question from "what page is
  this".

---

## [2.20.0] — 2026-08-27

**One table system for the whole product, and a sidebar that behaves like a
sidebar on a phone.**

### Added
- **The mooDesk Data Table pattern.** The plugin had eighteen tables in two
  incompatible families: thirteen were Bootstrap's `.table.table-sm` wearing
  whatever the active Moodle theme decided, five were `.mhd-table` wearing ours.
  They disagreed on the container, the radius, the header fill, the cell
  padding, the divider, the vertical alignment, the empty state and what happens
  on a phone — and the Bootstrap half is exactly why the administration screens
  read as Moodle screens rather than as mooDesk. Four new partials carry the
  pattern: `components/_datatable.scss` (surface, scrollport, header, row and
  cell roles, hover/focus/selected, empty state, pagination slot, and the
  table-to-card treatment), `components/_entity-cell.scss` (figure + name + one
  fact, for every entity the product lists), `layout/_table-toolbar.scss` (the
  band of controls above a table), and `.mhd-btn--icon` in
  `components/_button.scss` — a shape, not a colour, worn alongside a variant.
  Departments and Agents ship as the reference implementations.
- **One responsive rule, product-wide.** If a row contains **controls** it
  becomes a card; if it contains only **data** it scrolls sideways inside its own
  scrollport. A control behind a horizontal scroll is a control nobody finds. The
  switch is at 992px on every screen, so a reader never has to learn where a
  given table changes shape, and the markup is identical at every width — one
  loop, one set of cells, each carrying its column name as real text.
- **Department rows become cards below 992px.** Five columns and three controls
  per row do not survive a phone. Each row is painted as a card — name and status
  on the first line, members and SLA as labelled pairs, the three actions along
  the bottom. At 992px and above the table is untouched.
- **The mobile navigation drawer has a close control.** The toggle that opens the
  drawer is covered by it — the drawer is a fixed 256px panel over the left of
  the topbar, and its brand mark lands on the toggle's exact rectangle — so a
  pointer could open the drawer but not close it. The drawer now carries its own
  close control in its top-right corner. The backdrop and Escape still close it,
  and the toggle still does from the keyboard.

### Changed
- **The sidebar is three bands, and only the middle one scrolls.** `overflow-y:
  auto` sat on the `<aside>`, so once the navigation outgrew the viewport the
  logo and the pinned actions scrolled away with it — on a 720px-tall window with
  fourteen items, that is every screen. The brand and the footer now take their
  intrinsic height, the navigation takes what is left, and it is the only element
  that scrolls. No height is computed anywhere. "Return to Moodle" moves into the
  footer, where it always looked like it was, and the footer no longer depends on
  the capability-gated Settings item — users without `local/moodesk:managesettings`
  were losing the way back to Moodle along with it.
- **The app shell measures itself against the viewport that is actually visible.**
  Every viewport-height declaration is now written twice, `100vh` first and
  `100dvh` immediately after. On a phone `100vh` is the viewport with the
  browser's bars hidden — taller than what is on screen — so the sidebar's footer
  ended up underneath the address bar. Browsers without dynamic viewport units
  keep the static fallback.
- **Deduplications rather than additions**, from the table work: `.mhd-filter-bar`
  takes its row geometry from the toolbar mixin; `.mhd-table-responsive` is
  grouped with `.mhd-datatable-scroll`, so there is one scrollport with two
  spellings; `mhd-table-as-cards` is replaced by `mhd-datatable-as-cards`; the
  per-pill `.mr-1` is replaced by `.mhd-tag-list`; and
  `user_fields::for_userpic()` replaces three hand-written field lists.

### Fixed
- **The page behind the mobile drawer no longer scrolls.** Opening the navigation
  on a phone, flicking, and closing it used to put you somewhere else in a list
  you never touched. The lock is the overlay drawers' own
  `body.mhd-drawer-locked`, now shared through a reference-counted module so the
  first panel to close cannot unlock the page underneath one still open. The
  document keeps its scroll position: no jump on open or close, and no horizontal
  shift.
- **A closed drawer is no longer somewhere the Tab key can go.** The panel is
  parked off-screen with `transform: translateX(-100%)`, and a transform moves
  where a thing is painted and nothing else — every link in it stayed in the tab
  order and in the accessibility tree, so a keyboard user watched the focus ring
  walk off the side of the screen. The sidebar is now `inert` while it is a
  closed drawer, and only then: a desktop rail, expanded or collapsed, is
  untouched. Focus is handed back to the toggle before the subtree goes inert,
  whichever way the drawer was closed.
- **Table cells are labelled with real text.** Changing `display` on table
  elements costs the table its implicit semantics. Each cell now carries its
  column name as real text in a `.mhd-cell-label`, hidden by CSS wherever the
  header row is already doing that job — rather than a CSS `::before`, which is
  not reliably announced, and rather than explicit ARIA roles, which the HTML
  validator rejects inside a table that still says `role="table"`.
- **The departments table no longer pushes the page sideways at 992px.** It used
  to overflow the document by 11px at exactly that width. Measured after the
  table work at 992px, 1024px and 1200px: the document's horizontal overflow is
  0 at all three, and the 2px the table exceeds its container by at 992px is
  absorbed by its own scrollport, which is what the scrollport is for.

---

## [2.19.0] — 2026-08-24

**The filter bar stops taking half the phone.**

### Added
- **"More filters" on phones.** The six narrower filters — assignee, department,
  SLA, team, category and date range — now sit behind a disclosure that starts
  folded below 576px. The filter bar drops from 436px tall to 172px, which is
  264px of phone screen handed back to the tickets. Tablet and desktop are
  unchanged: the group is open and the toggle is not rendered.
- **A count of what is folded away.** When one of the six carries a value the
  summary shows how many, because a collapsed filter still narrows the list and
  nothing else on screen would explain why. The four date fields count once
  between them — they are one control, and reading "2" for a single range would
  be a lie.

### Fixed
- **The date range panel opened off the screen.** It grew rightwards from
  `left: 0` while its trigger is the last control in the bar at every width, so
  it ran 90px past the edge at 1400px and 102px at 768px. It now anchors its
  right edge to the trigger's. On phones it had a second, separate problem: the
  rule meant to let it fill the viewport paired `width: calc(100vw - 32px)` with
  `max-width: 100%`, and that percentage resolves against the ~99px trigger
  cell, not the viewport — so the cap undid the width and squeezed the panel to
  the width of its own button, clipping all four date inputs. Measured across
  320–1400: the panel is fully on screen at every width.
- **Status and priority now share a row on phones.** The filter bar already had
  a rule putting two controls to a row below 992px, but it never reached the
  status dropdown: `.mhd-filter-item` set `flex: 0 0 auto` further down the same
  file, and since a media query adds no specificity, the later rule simply won.
  The status filter stayed at its natural ~102px while priority took the rest of
  the row. Moving the base rule above the responsive blocks makes the two-column
  step mean what it says, and the bar drops from 172px to 128px on a phone.
- **The page header stacked on phones for no reason.** The New ticket button
  dropped to its own row below the title, costing a whole row to a button that
  fits beside it: at 390px the title takes ~80px and the button 133px, well
  inside the 374px available. The header now keeps one row at every width, and
  the title truncates before the button does.

### Notes
- The disclosure ships **open** in the markup and is folded by JavaScript, so a
  session without JS keeps every filter reachable rather than locked behind a
  control that cannot open. It reads its breakpoint from
  `local_moodesk/breakpoints`, so the fold and the CSS that hides the summary
  cannot drift apart.
- Opening the group by hand keeps it open for the rest of the visit; a resize
  does not overrule a choice the viewer already made.

---

## [2.18.1] — 2026-08-24

**One breakpoint scale, and the JavaScript reads it.**

The scale itself does not move: 576 / 768 / 992 / 1200 / 1800 stay exactly where
they were, aligned with Bootstrap's, because the plugin renders inside Moodle
pages whose theme reflows at those widths. What changes is that there is now one
place the numbers are written down, and everything else asks that place.

### Fixed
- **The ticket card put its checkbox on top of its timestamp** at every width
  from 576 to 991. The card's head was a single right-aligned row while the bulk
  checkbox sat absolutely positioned in the same corner, so the two occupied the
  same pixels — the end of the date was unreadable and the checkbox awkward to
  hit. The head is now a column across the whole card range: SLA pill first,
  timestamp under it, both reading from the left edge, with the right side left
  to the checkbox. The phone layout that already did this stopped being a
  separate override.
- **A viewport of exactly 768px was ambiguous.** The app shell opened its
  off-canvas drawer at `max-width: 768px` while the `'tablet'` mixin stopped at
  767px, so an iPad held upright got the drawer with desktop styling inside it.
  The tablet step is now inclusive at its top edge and the shell switches to a
  fixed rail at 769px, which keeps the drawer where a tablet wants it.

### Changed
- **Breakpoints are tokens, and JavaScript reads them.** `app_shell` and
  `detail_tabs` each carried their own `'(max-width: 768px)'` string with nothing
  tying it to the stylesheet: a breakpoint edit would have moved the CSS and left
  the behaviour behind, with no error to show for it. The scale is now published
  as custom properties on `:root` and read through the new
  `local_moodesk/breakpoints` module, so the two move together.
- **The mixin names the steps mobile-first** and rejects an unknown name with an
  `@error` instead of emitting nothing. `'tablet-up'`, previously declared and
  never used, is now the exact complement of `'tablet'` and is what the shell
  uses for its fixed rail.

### Removed
- `.mhd-hide-mobile`, `.mhd-hide-below-desktop` and `.mhd-show-below-desktop`.
  No template or AMD module ever used them, and each was a second place a layout
  decision could be made without the module that owns it knowing.

---

## [2.18.0] — 2026-08-24

**One Button, one Pill, and the tests that keep them that way.**

Status labels had drifted into thirteen implementations and buttons into
twenty-eight spellings across twenty-six templates — four font sizes, five
paddings, three radii, plus twelve admin screens inheriting Bootstrap's `.badge`,
whose font-size is a percentage and therefore measured differently in every
container it landed in. This release replaces both with one component each, sized
by Material Design 3, and closes the door behind them with contract tests.

### Added
- **The Pill component.** `.mhd-pill` owns geometry and typography; twenty-five
  variants own colour and nothing else. Sized by M3 chip tokens — 32px container,
  8px corner, 0.875rem/1.25rem label, 18px icon. The filter chip and the
  saved-view link became M3 input chips, gaining the state layer and focus ring
  they never had; status labels stayed `<span>`, because a label that is not
  actionable does not get promoted to a control.
- **The Button component**, in M3 Expressive's five sizes (32 / 40 / 56 / 96 /
  136px), each with its icon size, spacing, outline width and pressed corner in
  one Sass map. The shape morph on press is implemented because it is spec.
- **The Moodle bridge** (`vendors/_moodle-buttons.scss`). Nine screens render a
  moodleform whose Save and Cancel are emitted by Moodle, not by our templates,
  so there is no class we can put on them: the bridge maps the Bootstrap classes
  onto the component by including its mixins, and declares no dimension of its
  own. `btn-secondary` maps to outlined rather than filled-tonal, because every
  use of it here sits beside a primary and a second filled container would read
  as two primary actions.
- **`pill_markup_test` and `button_markup_test`.** The build now fails if a
  Bootstrap badge reappears, a pill ships without a colour variant, the
  interactive modifier lands on something that is not a link or a button, a
  second stylesheet starts having opinions about a component, or any template of
  ours spells a button in Bootstrap. The drift did not happen through
  carelessness — it happened because nothing was watching.

### Fixed
- **Six contrast failures.** High priority was white on orange at 2.57:1; the
  metadata chips 4.36:1; two soft health states at 3.89:1 and 3.82:1. All
  twenty-five pill variants and every button variant now clear AA in enabled,
  hover, focus and pressed — worst case 4.68:1.
- **An invisible action.** The shell's link reset outranked a variant's own
  colour by one element, so `+ New department` rendered its label and icon in the
  same blue as its container: 1.00:1. The reset now excludes buttons and chips,
  and both components self-qualify their variants rather than depending on a
  distant file staying well behaved.
- **The state layer painted over the label** rather than under it, so every
  hover, focus and press quietly cost the text 8–12% of its contrast.
- **moodleform submits are `<input type="submit">`**, and `::after` does not
  apply to a replaced element — those buttons would have got every M3 property
  except the one that makes them feel pressed. They use an inset shadow instead.
- **Three tables scroll inside their panel** instead of dropping their last
  columns off the edge. Reports and the dashboard had each grown a private copy
  of that rule under a different name; there is now one.

### Changed
- **Ninety lines of bespoke button SCSS are gone** — `.mhd-quick-btn`,
  `.mhd-rail-btn`, `.mhd-prop__save` and the attachment pair — and no screen lost
  a control.
- **Some calls went against what the old Bootstrap class suggested**, because the
  rule was never "filters are outlined" but "a filter must not compete with the
  page's primary action". Reports has no Add, so applying a date range is the
  primary action there and it is filled. Offboard and the merge confirmation are
  the two filled dangers in the product, because a page whose single action is
  irreversible is where weight reads as weight. Remove member stays outlined: a
  filled red repeated down a table reads as an alert rather than as an action.
- **Two name columns stopped being links.** Category name and Custom field name
  are plain text now, since the row's Edit button already owns that navigation.
  The agent name was the last clickable name column, and it was clickable only
  because the link was the sole route to the agent's page — so it gained a Detail
  button rather than losing the route, and the button respects the same edition
  gate the absent link used to mean.
- **`btn-warning` is gone.** M3 has no warning button and amber cannot carry
  white text, so on the upgrade prompt the emphasis survives the move and the
  colour does not.

### Note on upgrading
This release changes `styles.css` substantially. Moodle caches assets by plugin
version, so the version bump is what makes the new stylesheet reach a site that
already had the old one.

---

## [2.17.1] — 2026-08-23

**Bulk actions start working.**

The ticket list has rendered a checkbox on every row since the feature shipped,
and selecting them did nothing at all. `bulk_actions.js` opened by looking up
`#mhd-select-all` and returned early when it came back null; no template ever
rendered that id. The module bailed out before binding a single listener, so the
toolbar stayed behind its `hidden` attribute and no selection ever reached the
server. Every test was green throughout, because none of them read the template
and the module together.

### Fixed
- **The select-all checkbox exists.** It sits in a new list controls bar
  alongside the SLA sort link, aligned to the row grid's checkbox column. It
  covers the rows on the current page — 30 at most — and tracks the mixed state
  when only some of them are ticked.
- **Bulk actions run.** Selecting tickets opens the toolbar, and Apply posts the
  selection to `bulk_action.php` as it always meant to.
- **The module survives losing its markup.** Only the toolbar and its counter
  are required now; a missing select-all disables that one control instead of
  every bulk action.
- **The ticket list loads for an agent who cannot manage tickets.** Building the
  assignee list called a service method requiring `:manage`, so the page threw
  an uncaught exception for anyone holding only `:viewall`.
- **The row checkbox has an accessible name.** Its `aria-label` interpolated the
  ticket id outside the string, so screen readers read the placeholder verbatim:
  "Select ticket #{$a} 12".

### Changed
- **Bulk and quick actions follow `local/moodesk:manage`**, the capability the
  endpoint enforces, rather than `local/moodesk:viewall`, which only decides who
  may read the queue. An agent without it now sees no checkboxes, no select-all,
  no toolbar and no row quick actions.
- **Filters, page and view survive a bulk action.** The list posts the URL the
  agent was standing on and the endpoint returns them to it, validated as a
  local URL. Previously every bulk action dropped the agent back on a bare list.
- **Partial failures name their tickets.** A bulk result reported counts only;
  it now reports which ids could not be changed, so an agent is not left
  hunting through a page of thirty.

### Added
- `ticket_service::apply_bulk()` and a `bulk_result` domain object. The endpoint
  is now a controller: parameters, capability, redirect. Each ticket goes
  through the same single-ticket path an agent would take by hand, with no
  enclosing transaction — a ticket the state machine refuses must not roll back
  the ones that moved.
- `bulk_markup_test`, which asserts that every element id and class
  `bulk_actions.js` looks up is one its template renders, and that the built
  bundle agrees with its source. This is the test that would have caught the
  original defect on the day it shipped.
- `ticket_bulk_test` — 17 cases over normalisation, partial success, unknown
  actions and authorisation.
- Behat coverage of the capability gate. The scenarios driving the UI itself
  live in `bulk_actions_js.feature`, which needs a JavaScript profile the CI
  Behat job does not currently run.

---

## [2.17.0] — 2026-08-21

**mooDesk stops failing silently.**

The plugin absorbs failures on purpose: a notification that cannot be sent must
not roll back the ticket that triggered it. Until now those failures reported
through `debugging(DEBUG_DEVELOPER)`, which production — running with debugging
off — never shows. An SMTP outage, expired IMAP credentials or a dead webhook
endpoint could last days until a user complained. This release makes every
absorbed operational failure visible, alertable and bounded in time.

### Added
- **Operational incidents.** A new table records one row per *class* of failure,
  deduplicated by fingerprint and counted, rather than one row per occurrence.
  Recording an incident can never throw: observability able to break the
  operation it observes is worse than none.
- **Instrumentation** of the four sources that absorb failures — observers,
  webhook dispatch, email ingestion and scheduled tasks.
- **Operational alerts** by email, above a configurable severity
  (`incident_alert_severity`, default Error) and behind a per-incident cooldown
  (`incident_alert_cooldown`, default 60 min) so a failure that repeats on every
  cron run cannot flood the inbox. An incident that escalates alerts immediately
  regardless.
- **System Health page** at `/local/moodesk/health.php`, behind the new
  `local/moodesk:viewhealth` capability: overall state, all four sources always
  listed, active incidents with first seen, last seen, occurrences and severity,
  and acknowledge/resolve actions behind `local/moodesk:managesettings`.
- **Retention.** `purge_incidents` runs daily and deletes incidents whose last
  occurrence is older than `incident_retention_days` (default 90).
- **Health is derived from time, not stored.** An incident whose last occurrence
  falls outside `incident_active_window_hours` (default 24) stops counting as a
  current problem while staying on record.

Available in **every edition, Starter included**. Failure detection is production
readiness, not a premium feature — gating it would blind precisely the tier that
runs without paid support.

### Notes for administrators
- `incident_active_window_hours` and `incident_retention_days` are not the same
  control. The window stops treating an incident as current; retention **deletes
  it**. For a quieter health page, use the window.
- Retention measures from the last occurrence, not the first, so a failure that
  keeps recurring is never deleted mid-incident. Incidents still marked `open`
  are deleted too: after that long without recurring, an open incident is one
  nobody closed, not one still happening.
- Acknowledging an incident silences its alerts but does not return the site to
  healthy. Only resolving it, or the failure ageing out of the active window,
  does.

### Internal
- A CI check now enforces what `scss/abstracts/_colors.scss` has always claimed:
  no other stylesheet defines raw hex colours. Existing debt is frozen in a
  baseline; the check fails when it grows.

---

## [2.16.0] — 2026-08-20

**An IMAP poll can no longer swallow a whole mailbox in one cron run.**

### Added
- **Maximum messages per run** (`email_imap_maxmessages`, default 100) caps how
  many messages a single email-ingestion poll turns into tickets. Everything
  above the cap stays in the mailbox for the next run; the processed keyword
  guarantees the next poll resumes exactly where this one stopped. Setting it to
  `0` removes the cap.

  Without it, `UID SEARCH UNKEYWORD MoodeskProcessed` returned the *entire*
  folder on the first poll of a mailbox with history — one ticket per message,
  each MIME-parsed and file-stored — which is a cron run that runs out of time or
  memory and leaves half-created tickets behind. The old `\Seen` strategy was
  bounded in practice by the unread count; moving to a keyword removed that
  implicit ceiling, and this restores an explicit one.

### Changed
- The ingestion cron line now reports when the cap stopped a run short and how
  many messages are still queued, so a capped poll cannot be mistaken for a
  drained mailbox.
- Messages are handled oldest UID first. `UID SEARCH` is not required to return
  results in order, and under a cap an unsorted batch could leave the oldest mail
  permanently at the back of the queue.

---

## [2.15.2] — 2026-08-19

**The schema file says what XMLDB would write, and the schema documentation moved somewhere it survives.**

### Fixed
- **`db/install.xml` did not match XMLDB's canonical output**, so core's
  `core\db\plugin_checks_test::test_db_install_file` failed for anyone running
  Moodle's own suite with the plugin installed. The entire divergence was one
  attribute: 170 of the 189 fields omitted `SEQUENCE="false"`. Legal XML,
  installs correctly, and exactly what the check exists to catch. All 189 now
  declare it.

  **The schema itself did not change.** Verified three ways: the parsed
  structure — every table, field, type, length, notnull, sequence, default, key
  and index — is byte-identical before and after; the `CREATE TABLE` statements
  the DDL generator produces from the file are identical apart from the table
  comments below; and `admin/cli/check_database_schema.php` reports a database
  built from the old file as an exact match for the new one.

### Changed
- **The schema documentation moved out of XML comments.** `install.xml` carried
  ~330 lines of `<!-- -->` prose that the XMLDB editor deletes the first time
  anyone saves the file through it. What belongs with the schema is now in
  `COMMENT` attributes, which are part of the structure and survive the round
  trip — sixteen table comments grew from a label to the contract they describe.
  What is architecture rather than schema is now `DEVELOPER.md`, section
  **Database schema**: nineteen tables, what each is for, how they relate, and
  the parts that have bitten before — the SLA pause pair, the merge columns, the
  reply file area, departments routing tickets while teams group agents.

  `DEVELOPER.md` also stopped telling people to name tables `local_mhd_*`, four
  releases after the rename, and the step on adding a table now states the two
  `install.xml` rules that cost this release.

### Added
- **CI runs core's component-parameterised tests.** Five tests, under a second,
  on one PHPUnit leg that is also in the pull-request subset. Both of them have
  now found a real defect — the privacy provider's `core_files` link in 2.15.1
  and this release's `install.xml` — and neither had ever run here, because
  `moodle-plugin-ci phpunit` runs the plugin's suite, not core's.

  The step fails rather than skips when a core test file is not where it expects
  it. The first version skipped politely and passed, and spent a whole CI run
  reporting success while executing nothing: `moodle-plugin-ci install` does not
  export `MOODLE_DIR` to later steps, so the `cd` went nowhere and both paths
  looked missing. A check that cannot find what it checks is a failure.

---

## [2.15.1] — 2026-08-19

**CI runs the smoke suite, one more PHP, and a check that its own documentation is true.**

### Fixed
- **The privacy provider declared `core_files` as a plugin type.** It is a
  subsystem. `add_plugintype_link('core_files', …)` made mooDesk the only
  component in the entire Moodle tree making that call, and it fails core's
  `core_privacy\privacy\provider_test`, which is parameterised over every
  installed component and which nothing in this project's CI runs. Now
  `add_subsystem_link`, with a plugin-owned test that walks the collection and
  asserts every item names something real — a table that exists, a plugin type
  that is one, a subsystem that is one.

### Added
- **`behat.yml`** — the eight-scenario acceptance smoke on every push and pull
  request. One leg (Moodle 4.5 / PHP 8.2 / PostgreSQL), no browser, no
  Selenium, and `--auto-rerun 0`: `moodle-plugin-ci` retries failed scenarios
  twice by default, which is how a flaky acceptance suite stays green and stops
  meaning anything.
- **A PHP 8.4 leg** in the PHPUnit matrix, on `main` and dispatch. Moodle 5.0
  supports 8.4 and the matrix stopped at 8.3, which is why twelve
  implicitly-nullable constructors reached 2.15.0 unseen.
- **`dev_scripts/check-ci-docs.php` and `docs/ci-inventory.md`.** The inventory
  is generated from `.github/workflows/` and CI fails if the committed copy
  differs — the same contract the `Built assets` job applies to `styles.css`.
  It also fails if a workflow calls a `dev_scripts/` file that is not in the
  repository.

### Documentation
- `docs/release-process.md` §5 described a `code-checker.yml` that still ran
  phpcpd and grunt, months after the workflow deleted both with a comment
  explaining why, and had never heard of the language-parity or feature-gate
  checks that replaced them. Both it and `docs/tests.md` now carry the
  reasoning and link to the generated inventory for the list.
- `docs/pro-test-coverage-report.md` and `docs/email-ingestion-report.md` both
  still warned about a `workflow` OAuth-scope push blocker resolved in May, and
  the first described a three-leg matrix. Marked with their status rather than
  rewritten — they are dated reports, not living documents.

---

## [2.15.0] — 2026-08-19

**Eight scenarios that would have caught the bug 2.14.2 shipped.**

### Added
- **A Behat smoke suite.** Eight scenarios, ~13 seconds, no browser and no Selenium —
  they run on the BrowserKit driver. They cover what unit tests structurally cannot
  see: a file emailed to support being linked on the ticket page and downloading with
  its bytes intact, a ticket raised in the web form coming back answered, an internal
  note staying out of the requester's thread, a requester being refused someone else's
  ticket, and Reports locked on Starter and open on Pro.

  The first of those was written against 2.14.2's attachment defect. Put the old call
  site back and the scenario fails on the missing link — which is the whole reason the
  suite exists, since the unit test of the day asserted the broken behaviour and CI
  stayed green. Scope, limits and how to run it: `docs/behat-smoke-suite.md`.

- **Data generators for tickets and replies** (`tests/generator/`), shared by Behat and
  available to PHPUnit. They create rows through `ticket_service` and `reply_service`
  rather than by insert, so a fixture cannot describe a ticket the product is no longer
  able to produce.

### Fixed
- **The plugin deprecated its way through every page on PHP 8.4.** Twelve constructors
  declared an implicitly nullable parameter (`\moodle_database $db = null`), which PHP
  8.4 deprecates and PHP 9 will reject. Moodle 5.0 supports 8.4; the CI matrix stops at
  8.3, so nothing had ever run the plugin on it. Every one of them is now explicit
  (`?\moodle_database $db = null`). No behaviour changes — the parameters were always
  nullable in practice.

### Changed
- `edition_service::get_edition()` reads a `behat_edition` config row while
  `BEHAT_SITE_RUNNING` is defined, so a feature file can drive both sides of an edition
  gate. The constant is set only for requests that matched `$CFG->behat_wwwroot`, which
  no production install has, and the resolution order is otherwise untouched.

---

## [2.14.4] — 2026-08-19

**One register in the Spanish pack, this time all of it.**

### Fixed
- **Four more Spanish strings were still in voseo.** 2.14.3 rewrote the four the
  audit had listed; the pack held eight. Three of the survivors sat on the agents
  screen — `agents_intro`, `agents_managed_moodle_hint`, `agents_role_missing` —
  the same screen the previous fix was meant to make consistent, so an admin
  opening it still read `Gestioná … desde acá` above strings in neutral tuteo. The
  fourth was the licence key setting (`Pegá el JWT`). All 978 keys now share one
  register.

### Documentation
- `docs/translation-style-guide.md` — the register decision is now written down
  rather than inferred from whatever the pack happened to do: informal second
  person in its neutral international form, for `es` today and for `pt` / `fr` /
  `de` when they land. It also lists the voseo forms to keep out and the terms
  that never get translated.

---

## [2.14.3] — 2026-08-19

**An emailed attachment reaches the person who has to read it.**

### Fixed
- **Attachments on a ticket raised by email were stored where no screen looks.**
  A requester emails support with a screenshot; the ticket is created, the file
  is saved, and no agent ever sees it. Ingestion wrote a new ticket's
  attachments to the `ticket_attachment` area keyed by ticket id, and nothing
  reads that area — the ticket page resolves attachments per reply. The file was
  kept, served on a direct URL, exported by the privacy provider and erased with
  the user, while never being linked from any page.

  A manually raised ticket has always saved its attachments against the first
  reply, and the opening email is stored as a reply too, so ingestion now uses
  the same target. Files written to the old area by earlier versions stay
  downloadable, exportable and erasable — only new writes change.

  The test suite had agreed with the broken behaviour, which is why CI stayed
  green: it asserted the `ticket_attachment` area. It now pins the reply area
  and fails if the call site is reverted.

- **The webhook form's "Active" checkbox read as a fifth event.** It sat inside
  the Events fieldset, at the same visual level as the four event checkboxes, so
  an admin clearing it to stop one kind of notification would have silently
  disabled the whole subscription. It now sits above the fieldset with the other
  fields that describe the subscription rather than what it listens for.

- **The Spanish pack mixed two registers.** Four strings were in Río de la Plata
  voseo among 974 in neutral tuteo, two of them a glance apart on the same agents
  screen. All four now match the rest of the pack, and a missing accent
  (`otorgale` → `otórgale`) went with them.

### Verified
- **The distributable ZIP on PostgreSQL, live.** Install, upgrade from 2.9.0 with
  data intact, both edition gates across all 19 screens, every aggregation path
  against 250 tickets, and the REST API — 0 failures. `db/upgrade.php` had never
  executed anywhere before this: PHPUnit rebuilds the schema from `install.xml`
  on every run. See `docs/postgresql-live-verification.md`.
- **Privacy export and erasure, live.** A real subject with tickets in five
  states: the export archive carries tickets, replies, audit history and CSAT;
  erasure leaves zero residual references and touches nobody else's data. See
  `docs/privacy-erasure-verification.md`.

### Documentation
- `docs/tests.md` described a CI that did not exist yet — it said nobody ran the
  suite automatically — and listed as uncovered a dozen services that have had
  tests for months. Both sections now describe what is actually there.
- `docs/ticket-merge-design-doc.md` still used the `local_mhd_` prefix retired in
  2.8.0, and specified an audit table whose name is four characters over Moodle's
  limit. Corrected, along with a status table that had gone backwards from
  reality.

## [2.14.2] — 2026-08-19

**A reply body has to be text.**

### Fixed
- **The reply composer no longer stores the literal `"Array"`.** A hand-built
  POST could send `message` as an array — `message[]=foo`, `message[text]=hola` —
  and the reply was accepted: the value was cast to a string, which PHP renders
  as `Array`, and the full success path ran behind it. One reply row, one
  history entry, one `ticket_replied` event, all for a body that says nothing.

  Not reachable from the interface — the composer is a plain `textarea` named
  `message`, and a browser cannot submit anything else — so nobody could hit it
  by accident and the only content at risk was the author's own. It was still a
  server that trusted its client.

  Neither of Moodle's entry points refuses this on our behalf: for a
  scalar-typed element holding an array value, `MoodleQuickForm::getCleanedValue()`
  cleans it with `clean_param_array()` and hands the array on, and
  `required_param()` only raises a debugging notice before deferring to
  `required_param_array()`. So the request is now refused where `clean_param()`
  would have thrown — at the entry point, before the form is built — and
  `reply_service` enforces the same contract for every caller, ahead of any
  write. Neither cast survives.

  Deliberately strict: no numbers, no objects with `__toString()`. A text field
  that did not receive text is a malformed request, not a value to salvage.
  Valid replies are untouched — newlines, Unicode, `<`, `<div>` and quotes are
  still stored exactly as typed, and an empty message still reports a missing
  parameter rather than a wrong type.

## [2.14.1] — 2026-08-19

**The status filter works again.** Selecting any status in the ticket list threw
`Url parameters values can not be arrays!` and the page answered 404. The filter
bar is a GET form with seven `status[]` checkboxes, so the crash needed nothing
more than a normal click — it was not reachable only through a hand-built URL.

### Fixed
- **Multi-select status filter on the ticket list.** The selection is read with
  `optional_param_array()` and stayed an array all the way into `moodle_url`,
  which rejects array values on Moodle 4.5. Every list URL built while a status
  filter was active went down with it: the active-filter chips, the SLA sort
  link and the pagination bar. Present since the multi-select replaced the old
  single `status=N` integer; 2.14.0 did not introduce it.

  The repeated-parameter contract is unchanged — `status` is still an array end
  to end, from the checkboxes through the repository query. The new
  `\local_moodesk\util\url_params` helper expands it into the indexed
  `status[0]`, `status[1]`… keys that `moodle_url` accepts and PHP parses back
  into an array, which is the same encoding Moodle 5.x emits natively. No
  comma-joined scalar, no contract split between the form and the URL.

- **The status filter now actually filters.** Found while smoke-testing the fix
  above: the ticket list built its filter array under the key `statuses`, while
  the repository reads `status`. The selection reached the chips and the URL but
  never the query, so a filtered list returned every ticket regardless of the
  statuses picked. Present since 1.1.0 — the crash had been masking it, because
  the page never got far enough to show a wrong result set.

- **Saved views keep their status filter.** Saving a view with statuses
  selected discarded them silently: every other filter came back, `status`
  did not. The filter bar posts its multi-select under the HTML name
  `status[]` — that is how a browser spells a repeated field — and the
  allowlist guarding a stored view only knew the parameter name `status`, so
  `array_intersect_key()` dropped the selection without a word.

  Saving and reading a view now both go through
  `\local_moodesk\service\saved_view_service`, which drops the `[]` transport
  suffix, applies the allowlist and settles `status` as a de-duplicated list
  of integers whether it arrived as one value or several. Views saved before
  this are unaffected, and a legacy scalar `status` is read back as a
  one-element list rather than ignored.

## [2.14.0] — 2026-08-17

**Enterprise security hardening.**

### Added
- **Licence decisions are audited.** Two events — *mooDesk licence accepted* and
  *mooDesk licence rejected* — reach Site administration → Reports → Logs,
  carrying the licence id, the tier, the rejection reason and a short
  fingerprint. Never the key itself, its signature or its payload: a licence key
  is a credential, and a log is not where credentials belong.

  One event per *change* of decision, not per request. Validation sits behind a
  cache that misses on every node and daily on TTL; a line each time would bury
  the one that matters. Feature gate blocks are not logged at all — they run on
  every page and are the gate working, not an incident.

- **CI enforces the edition gates.** `dev_scripts/check-feature-guards.php`
  parses the plugin with `token_get_all()` and fails the build when a
  `require_feature()` key does not exist in `edition_service::FEATURES` — those
  calls return silently, so a typo disables a gate while looking perfectly
  guarded — when a guarded method loses its guard, or when a new public method
  of a gated service ships without one.

- **Per-token rate limiting on the REST API.** Each token gets 300 requests per
  60 seconds by default, configurable or disabled outright. Requests over the
  budget are refused with HTTP 429, `Retry-After` and the `RateLimit-*` headers
  — refused, not merely logged. Counters live in Moodle's cache API keyed by
  token and window, so there is no new table and nothing to prune. Details in
  `docs/api-reference.md`.

  Limits are per token rather than per site or per IP: a site-wide limit lets
  one noisy integration throttle everyone else, and an IP limit is both too
  coarse and too easy to sidestep.

### Changed
- **Webhook signatures now cover the delivery's timestamp, not just its body.**
  Every delivery carries `X-Moodesk-Timestamp`, and `X-Signature-256` is the
  HMAC-SHA256 of `"<timestamp>.<body>"`. Receivers should reject anything more
  than 5 minutes from their own clock.

  **Breaking for existing receivers.** The header keeps its name but the signed
  material changed, so a verifier written against the old contract will reject
  every delivery until it is updated. Verification guide, with worked examples
  in PHP and Python, in `docs/admin-guide.md` §16.5.

  Signing the body alone meant a captured request stayed replayable forever: the
  bytes never change, so neither does the signature. There is no nonce store —
  the timestamp inside the signed material is what makes a replay detectable,
  and it costs no state to keep.

## [2.13.1] — 2026-08-14

**The Spanish translation catches up.** A Spanish site was showing the app shell
in English — the navigation groups, `Teams`, `Custom fields`, `Return to Moodle`,
the brand tagline and the user menu — because those strings were added to the
English pack and never mirrored.

### Fixed
- **39 missing Spanish strings.** The app shell (`shell_*`), the navigation
  entries for teams, custom fields, webhooks, licence and API tokens, the API
  token screens, the site-mismatch licence status, the new-ticket intro and the
  API token privacy metadata. `lang/es` now matches `lang/en` key for key.
- **A hardcoded `aria-label`** on the active-filter chip group in the ticket
  list, which read `Active filters` in every language. It now goes through
  `filter_chips_label`.

## [2.13.0] — 2026-08-14

**The dashboard becomes an operational panel.** What was a four-tile summary is
now the screen a support lead can run a day from: how much needs attention right
now, whether the backlog is growing, who is carrying it, and what has just
happened — all under one set of filters that live in the URL.

### Added
- **Global filters** — date range (7 / 30 / 90 days), department, team and
  agent, carried in the URL so a view can be bookmarked and shared. Each active
  filter shows as a chip that drops its own criterion and keeps the rest.
- **Eight KPI tiles** — active tickets, unassigned, SLA breaches, resolved this
  week, average first response, average resolution time, SLA compliance and
  customer satisfaction, each comparing against the equivalent preceding period
  where an honest comparison exists.
- **Nine panels** — tickets over time, by status, by priority, by department,
  SLA performance, agent workload, ticket aging, top categories and recent
  activity.
- **Charts from Moodle core.** Five panels mount Chart.js through
  `core/chartjs` — the trend line, the status doughnut, the banded SLA gauge and
  the priority and aging pies. No charting library is added to the package. Every
  panel renders complete without JavaScript, and no figure exists only inside a
  canvas.
- **`sla_compliance_target`**, a new optional setting holding the proportion of
  tickets you aim to resolve within SLA. Empty by default: with no value the
  gauge reports compliance without inventing a goal nobody agreed to.
- **A `timemodified` index** on the ticket table, which the recent-activity
  panel needs to avoid a filesort over every ticket on the site.

### Changed
- **The panel is scoped by role, before any query runs.** A manager sees
  everything they are entitled to; an agent sees the same panel pinned to their
  own assigned work; a requester sees a reduced view of the tickets they raised.
  The scope is baked into the filter, so a hand-edited URL cannot widen it.
- **Zero and "no data" are now different claims.** A period with no responses
  reports a dash rather than an average of zero seconds, and SLA compliance over
  nothing resolved is not 0% compliance. Zero active tickets, by contrast, is a
  measurement and is stated plainly.
- **Snapshot metrics ignore the date range.** Tickets open right now are not
  filtered by when they were created — a 7-day view still shows the ticket that
  has been open for a year, which is exactly the one an operations panel exists
  to surface.
- **Active tickets and Unassigned carry no delta**, deliberately: mooDesk keeps
  no historical snapshots and reconstructing one is wrong for reopened tickets.
  An imprecise delta on a headline figure is worse than none.
- **The Spanish pack now covers the dashboard**, including the new SLA target
  setting.
- **One table skin across the plugin.** The dashboard's workload table, both
  report tables, the SLA breakdown and the licence status table now share
  `.mhd-table`, which opts out of the full cell border Moodle 5 draws on any
  table without the `.table` class.

### Fixed
- **SLA compliance was always shown in the failure colour**, whatever the figure
  and whether or not a target had been agreed.
- **A KPI's movement was legible only to a reader who could see it.** Direction
  travelled in an `aria-hidden` arrow and in colour alone; it is now also stated
  in words. A figure that has not moved is no longer painted as bad news.
- **An empty result caused by a filter whose chip could not be labelled** — an
  archived department, a departed agent — reported a quiet period and offered no
  way back out.

---

## [2.12.0] — 2026-08-14

**The SLA clock learns to wait.** Time a ticket spends waiting on the requester
no longer counts against its resolution target, and the flag the reports read
now agrees with the verdict the queue shows.

### Changed
- **The resolution SLA clock stops while a ticket waits on the requester.** Time
  spent in `pending` no longer counts against the resolution target, so a ticket
  that has been waiting three weeks for a customer to answer is no longer
  reported as a team failure. The metric could not previously tell "the team did
  not act" from "the customer did not reply": on a realistic backlog that put 73%
  of active tickets in breach by arithmetic alone. First response is unchanged —
  it already stopped on its own at the first agent reply, and a requester's
  silence cannot excuse never having answered. Working calendars (office hours,
  weekends) remain out of scope. See `docs/sla-resolution-pause.md`.
- **`sla_breached` is now current state on an active ticket, not a one-way
  latch.** The hourly task clears the flag as well as setting it, because a
  ticket that enters a pause can drop back inside its deadline and a permanent
  flag would show red in the reports while the queue showed green. On resolved
  and closed tickets the flag keeps its old meaning — the historical record of
  whether that ticket was answered in time — and is never revisited.
- **Resolution-time reporting subtracts paused time.** Average and median
  resolution durations, and the per-agent supervision figures, measure how long
  the team took rather than how long the customer took to reply.
- **The ticket detail sidebar shows the resolution SLA as paused.** A stopped bar
  with a countdown that never moves reads as a broken panel; it now says
  "Paused — awaiting requester" and holds at the percentage it had reached.

### Fixed
- **The SLA task judged every ticket against the global thresholds.** It selected
  tickets without `departmentid`, so per-department SLA overrides were applied by
  the live list but ignored by the task that writes the flag the reports count —
  a department with a longer target had its tickets flagged against a deadline
  that does not apply to them.
- **The SLA task never evaluated in-progress tickets.** It filtered
  `status < STATUS_RESOLVED`, and `STATUS_INPROGRESS` is 6 against
  `STATUS_RESOLVED`'s 4, so the tickets agents are actively working on were the
  one group the task skipped entirely.
- **The SLA task only ever evaluated its first batch.** A fixed 500-row limit
  over a `timecreated` ordering meant the oldest tickets could monopolise every
  run, leaving the rest of the queue permanently unevaluated. It now pages
  through every active ticket by id.

---

## [2.11.0] — 2026-08-14

**The ticket detail, reopened.** The screen is redesigned around the
conversation and now also opens as a drawer over the queue, and the box an agent
writes in — a reply, a note, or a whole new ticket — is one component on every
screen that has one. Moodle's editor and its file manager are gone from both
composers; the File API underneath them is untouched.

### Added
- **The ticket detail opens over the list.** Clicking a row slides the ticket in
  as an 880px drawer instead of navigating away, so an agent can triage a queue
  without losing their place. It is progressive enhancement, not a rewrite:
  every row is still a real link to `view.php?id=N`, which stays the canonical
  URL, the deep link, the "Open full page" target in the drawer's header, and
  what a middle click, a modifier click or a session without JavaScript gets.
  Opening pushes that URL onto the history stack, and Back and Forward are then
  the only source of truth for what is on screen: Back closes the panel or
  steps to the previous ticket, Forward reopens it, and a reload lands on the
  full page.
- **A reply you have not sent is not thrown away.** Closing the panel — with
  the button, Escape, the backdrop, by opening another ticket, by opening the
  new-ticket drawer, with Back, or by leaving the page — asks first, and only
  when there is something to lose. Sending never asks.
- **A reusable toast in the application shell.** The shell runs on Moodle's
  'embedded' layout, which renders no notification region — messages queued by
  a redirect were being dropped on the floor. The detail screen now drains that
  queue into an `aria-live` region, which is also where property saves confirm
  themselves. Only the detail adopts it in this release; bulk actions and saved
  views keep their inline feedback until they are migrated deliberately.

### Changed
- **Attaching a file to a reply is a paperclip, not a file manager.** The
  composer's attachments are now a paperclip in the action bar that discloses
  the files the reply is carrying, with the count on the button while the panel
  is closed — so a reply with nothing attached costs the conversation no height
  at all, where the file manager claimed a permanent 140px band whether or not
  it held anything. Closing the panel does not discard anything: the files stay
  in the draft, which is what the count is there to say.
  Moodle keeps everything that matters: the draft area, the upload, the
  repositories, the size and count limits, the File API and the save on submit
  are untouched, and the button opens Moodle's own file picker over the same
  draft area the form has always carried. The chips are never the source of
  truth — after every upload and every removal the draft is re-read from the
  server and redrawn from what it actually holds. The drawer and the full page
  get the same composer. Reasoning: `docs/reply-composer-attachments.md`.
- **Raising a ticket writes into the same box as replying to one.** The New
  ticket form's description is now a plain textarea that grows with what is
  written, and attaching a file is an "Attach files" button sitting directly
  under it — Moodle's editor and file manager are gone from this form too, on
  the drawer and on the full page alike. Moodle keeps the draft area, the
  upload, the repositories, every limit and the save on submit; what changed is
  the surface. The files a ticket is carrying are listed under the button, each
  removable before it is sent, and the list is redrawn from what the server
  reports the draft holds rather than from what was just clicked. A description
  is now stored as plain text, so what a requester types is what every agent
  reads; tickets raised before this release keep the format they were stored
  with and render unchanged.
  The trade: without JavaScript a ticket can still be raised from `submit.php`,
  but not with files attached — the picker is what uploads them.
- **Closing the New ticket drawer on a half-written ticket asks first.** It used
  to close on the spot. A file already uploaded into the draft counts as unsent
  work in its own right: it leaves no trace in the form once the list is out of
  view, so losing it silently was the worst of the two.
- **The ticket detail was redesigned around the conversation.** Header carrying
  the status and priority pills, the subject, and a meta line that now names the
  requester; the thread and a 244px management rail side by side; the composer
  at the foot of the screen. The page and the drawer render one component
  (`\local_moodesk\output\ticket_detail`), so there is no second implementation
  of the screen to keep in sync — `view.php` went from 538 lines of context
  building to 82 lines of request handling.
- **Ticket properties save when you change them.** Status, priority, category,
  department, team and assignee moved out of six separate cards into one
  Properties card and commit on selection, with an inline "Saved" marker and a
  toast. Nothing about the rules moved: every save goes through `ajax.php`,
  which re-runs the capability, transition and validation checks, the status
  select still offers only legal transitions out of the current state, and
  assignee is still filtered to the ticket's department. Each row is still a
  real form with a Save button — that is the no-JavaScript path and the
  fallback when the AJAX call fails, and the button is only hidden once
  auto-save is actually attached.
- **The management rail is for agents.** It renders for `local/moodesk:manage`
  only, and each card keeps its own gate on top (SLA while the ticket is open,
  Teams and CSAT by edition, merge behind its four-layer gate). Requesters get
  a single column with their status and priority as read-only pills.
- **The reply form's DOM ids are prefixed** (`id_mhdreply_…`). The ticket list
  hosts the new-ticket drawer, whose form declares the same element names; with
  the detail drawer open both are in the document, and Moodle initialises
  editors and file managers by element id. Element names, and therefore the
  POST payload, validation and `is_cancelled()`, are untouched.
- **Replying tells you which thing you did.** A note now confirms with
  "Internal note added to #N" instead of "Reply sent".

### Fixed
- **A ticket card overflowed the viewport by a pixel on narrow screens.** The
  "updated" timestamp is a full date in card mode and was `nowrap`, so ~200px
  of it pushed the row past the edge of the page. It wraps below desktop now.
  Pre-existing on the ticket list; found while measuring the drawer.
- **Attachments on a requester's own ticket could not be downloaded.**
  `local_moodesk_pluginfile()` read `$USER` without declaring it, so the
  requester branch fatalled instead of serving the file. Agents were unaffected,
  which is why it went unnoticed.
- **The merge confirmation screen said the opposite of what happens.** It read
  "This action cannot be undone. The source ticket will be permanently closed"
  — untrue since 2.9.0, which gave every merge a 30-day undo, and untrue again
  since 2.9.1, where a source that is already closed is not closed by anything.
  It now names both tickets, says the conversation stays where it is and is
  also shown on the target, closes the source **only if it is still open**, and
  states the undo window. Found by smoke-testing 2.9.1 against a real
  install; it is the last thing an operator reads before committing.
- **That undo window is no longer a number typed into the copy.** The English
  and Spanish strings take it as a placeholder and the page fills it from
  `merge_audit::revert_ttl_days()`, the same source the ticket sidebar already
  used. Changing `REVERT_TTL_SECONDS` used to leave the confirmation screen
  quoting 30 days regardless.
- **The same block is no longer an alert at all.** It was `alert-danger`, left
  over from when the copy said the merge could not be undone. Recolouring it to
  `alert-warning` only produced three identical yellow blocks stacked on a
  conflicted cross-requester merge, so it is now a neutral summary —
  `.moodesk-merge-summary`, a "What will happen" heading, no alert colour, no
  icon, no `role="alert"`. The two real warnings above it are untouched, and no
  new semantic colour was introduced. The hierarchy on the page reads as two
  warnings and one summary.
- **The string behind it is no longer called `merge_confirm_warning`.** The key
  outlived the block it named. It is now `merge_confirm_summary_body`, paired
  with `merge_confirm_summary_title`, and the old key is listed in the plugin's
  new `lang/en/deprecated.txt` so a site still calling it gets the standard
  deprecation notice instead of a silent miss.

---

## [2.9.1] — 2026-08-13

**Ticket merge follow-up.** One rule loosened and one piece of bookkeeping
corrected; no schema change and no new surface.

### Changed
- **A closed ticket can be merged.** F4 rejected it from `merge.php`; design doc
  §8 always said it was allowed, and it is the common case — a duplicate is
  usually recognised after both tickets have been dealt with. The gate lived
  only on the page, so the REST endpoints added in 2.9.0 were already accepting
  what the UI refused; the two now agree.
- **A merge no longer re-closes a source that was already closed.** It keeps its
  original `timeclosed`, writes no status-change history entry, and fires no
  `ticket_status_changed` event, because nothing changed status. Merging a
  ticket that is still open behaves exactly as before.

### Removed
- The `merge_source_is_closed` string, which no longer has anything to say.

---

## [2.9.0] — 2026-08-12

**Enterprise ticket merge, phase F5 — the feature goes live.** Everything built
in F0–F4 was invisible: `view.php` forced `canmerge` to false and the target
showed nothing of what had been merged into it. This release turns the entry
point on and adds the read path that makes a merge visible at all.

### Added
- **The linked read path.** A merged target's thread is the union of its own
  replies and those of every source merged into it, interleaved
  chronologically, each entry labelled with the ticket it was written against
  and linking back to it. No row is copied or moved, so attachment URLs already
  sitting in a customer's inbox keep working.
- **Custom field values carried by the source** appear on the target in a
  collapsed, attributed, read-only panel — never overwriting the target's own.
- **The merge panel** in the agent sidebar: what was merged in, by whom, when,
  why, and the state of its 30-day undo window. Inside the window it offers the
  undo; past it, it says the window closed rather than dropping the button
  without explanation; an undone merge stays listed, because the audit row is
  permanent.
- **The merge entry point**, gated on Enterprise edition, `:mergetickets`, the
  ticket still being active, and not terminal — the same four conditions
  `merge.php` re-checks before rendering.
- **Two REST endpoints**: `local_moodesk_merge_ticket` and
  `local_moodesk_revert_merge`, both under the `tickets:write` scope and split
  by capability the same way the UI is.
- `merge_service::get_linked_sources()`, `::get_consolidated_history()` and
  `::is_revertable()` — the read helpers behind all of the above.

### Changed
- **The cross-requester acknowledgement moved from `merge.php` into
  `merge_service::merge()`**, which now takes it as an argument and refuses
  without it. Merging two people's tickets makes each one's replies readable to
  whoever can see the other; that has to be a deliberate act on every channel,
  not only on the one that renders a checkbox. **Callers of `merge()` that omit
  the new argument will now be refused when the two tickets belong to different
  requesters.**
- The source's merged banner now reads `merged_status` and resolves the target
  through the audit row, consulting the legacy `mergedinto` column only for
  tickets backfilled from before the audit table existed.
- `merge.php` no longer pre-checks the target: the service runs the same
  edition, capability and visibility gates and answers with the same strings.

### Notes — who reads what
- Visibility across a merge link is decided per source, in SQL, in one place.
  Agents read every source and every reply type. A **requester reads only
  sources they raised themselves**, public types only: the common merge is one
  person filing the same issue twice and they keep their own words, but nobody
  gains sight of a stranger's ticket because an agent merged it into theirs.
- **The read path carries no edition gate.** Performing a merge is Enterprise;
  reading one already performed is not, so a licence that lapses cannot make
  content vanish from tickets.
- **Ticket history consolidates at the data layer only.** The product renders no
  history view today, so none was invented here;
  `merge_service::get_consolidated_history()` is ready for the screen that
  eventually wants it.

### Migration notes
- **No schema change in this release.** The `merged_status` enum and the
  `local_moodesk_merge_audit` table shipped in 2.8.1 and are unchanged.
- **Deprecated: `local_moodesk_ticket.mergedinto`.** It is still written and
  still read as a fallback for pre-audit rows. Nothing new should depend on it;
  it is scheduled to be dropped in the next minor release, and the audit row is
  the answer to "where did this ticket go".
- Sites that have never merged a ticket see no behavioural change beyond a new
  sidebar button for Enterprise managers.

---

## [2.8.5] — 2026-08-12

**Enterprise ticket merge, phase F4 — the merge screen.** The page exists but
nothing links to it: `view.php` still forces `canmerge` to false, so the feature
stays off until F5. It can be reached by URL to be exercised.

### Added
- **`merge.php`** — pick a target, review what the merge will do, then commit.
  The review step is not decoration: a merge is undoable for 30 days only, and
  the preview is where conflicts and a cross-requester merge become visible
  before anything is written. Four gates run in order — edition, capability,
  visibility, then ticket state.
- **`merge_form`** with a target autocomplete, an optional reason, and a
  cross-requester confirmation that only matters when the two tickets belong to
  different people. Every rule it enforces is enforced again by `merge_service`;
  the form exists to explain a rejection while the agent can still fix it.
- **`local_moodesk_search_active_tickets`** — the endpoint behind the
  autocomplete, and the first function in this plugin declared `ajax => true`.
  It is deliberately **not** part of the mooDesk API service: it is called from
  the plugin's own UI with a session rather than a token, so gating it behind
  the Enterprise `api_access` feature would have been wrong. Its gate is the
  merge feature plus `:mergetickets`.
- A `merged_status` filter on the ticket repository, so candidates are excluded
  in SQL. Filtering after the query would silently shrink a page of results
  below its limit.

### Notes
- Candidate filtering — visible to this agent, not already merged, not the
  source — happens entirely server-side. The AMD module deliberately does no
  filtering of its own, since a second copy of the rule in the browser is one a
  crafted request can skip.
- `merge.php` is registered in the page-gate contract test, so its edition gate
  is now watched by the same test that guards every other gated page.

---

## [2.8.4] — 2026-08-12

**Enterprise ticket merge, phase F3 — events, notifications and webhooks.**
Still invisible to users: `view.php` keeps forcing `canmerge` to false.

### Added
- **Two events**: `ticket_merged` (carrying both ticket ids, the audit id and
  what the source was holding) and `ticket_merge_reverted`. Both fire *outside*
  the merge transaction, so a failing observer cannot undo a committed merge.
- **Notifications to the three people a merge affects** — the source requester
  (their conversation moved), the target requester (someone else's ticket was
  folded into theirs) and the target's assignee (they inherited the content).
  Recipients are deduplicated, since one person is often two of those roles at
  once, and the agent who performed the merge is not notified about their own
  action.
- **Two webhook events**, `ticket.merged` and `ticket.merge_reverted`, carrying
  both ticket ids and the audit id so a subscriber can reconcile the two threads
  from a single delivery.

### Fixed
- **A merge no longer sends the requester a satisfaction survey.** With the CSAT
  trigger set to "closed", closing the source as part of a merge looked
  identical to an agent resolving it, so the requester was invited to rate the
  handling of a duplicate nobody ever worked — arriving right after being told
  their ticket had moved. The status change caused by a merge is now marked as
  such, and CSAT ignores it.
- **Automation rules no longer fire on the merge's own close.** Every rule
  matching that transition would have tried to mutate a ticket F2 had already
  locked, failing and writing a failure row — guaranteed noise in `rule_log` on
  every merge, for every matching rule.
- **The merge no longer emits a bare `ticket.updated` webhook** alongside its own
  event. Subscribers were going to be told the ticket closed, without being told
  why, and would have had to correlate two deliveries to reconstruct one action.

---

## [2.8.3] — 2026-08-12

**Enterprise ticket merge, phase F2 — merged tickets stop accepting writes.**
Still invisible to users: `view.php` keeps forcing `canmerge` to false.

### Changed
- **A ticket merged into another one now rejects every write.** Enforced in
  `transition_status`, `set_priority`, `assign`, `set_department`, `set_category`
  and `add_public_reply`, plus `assign_team` — which design doc §4.2 does not
  list, but which is the same kind of write and would have been a hole in the
  same wall. Only `merged_status = merged_into` locks: the surviving target of a
  merge and a reverted merge both stay fully writable.
- **The guard runs before the "nothing to do" short-circuits**, so setting a
  field to the value it already holds is refused rather than silently reported
  as success. A locked ticket rejects an operation; it does not ignore it.
- **Automation rules refuse to touch a merged ticket** and the refusal is written
  to `rule_log` instead of failing silently. This covers every action type,
  including `add_note` — a rule writing notes onto a merged ticket is exactly
  what §6.1 rules out, even though manual internal notes remain allowed.
- **Public replies now name the merge target.** These were already rejected,
  because a merged ticket is closed and the terminal-state check caught them —
  but the caller was told "this ticket is closed", which says nothing about where
  the conversation went. The message now points at the target.
- Internal notes are deliberately still allowed on a merged ticket: annotating
  one after the fact is an audit action, not a write to a live thread.

### Fixed
- **An emailed reply to a merged ticket no longer opens a duplicate ticket.**
  Inbound email treats a closed ticket as a signal to start a fresh one, and a
  merged ticket is closed — so answering a merged thread silently created a
  second ticket for a conversation that already had a home on the target. Such
  replies are now refused outright, audited with the new `merged_rejected`
  outcome, and the sender is told which ticket to continue on. The audit row is
  written before the notification, so a messaging failure cannot lose the email.

### Testing
- Regression coverage for `set_department` and `set_category`, which had none at
  all before their write paths were modified, plus rejection tests for every
  guarded path, the offboard requeue (which shares the assignment path), and the
  merge states that must *not* lock.

---

## [2.8.2] — 2026-08-12

**Enterprise ticket merge, phase F1 — the merge core.** Still invisible to users:
`view.php` keeps forcing `canmerge` to false, so nothing reaches the service yet.

### Added
- **`merge_service`** as a dedicated class (design doc §6.3), exposing
  `merge()`, `revert()`, `preview()` and `get_audit()`. Merging is **linked, not
  copied**: replies and history are re-parented, which preserves attachment URLs
  and foreign-key integrity.
- **Revert with a 30-day TTL**, restoring the source ticket from the audit
  snapshot. New scheduled task `purge_merge_snapshots` retires expired snapshots.
- Capabilities `local/moodesk:mergetickets` and `local/moodesk:revertmerge`,
  brought forward from phase F4 — the service gate could not be written against
  a capability that did not exist yet.

### Changed
- **Row locking uses a plain `SELECT ... FOR UPDATE` in id order.** PostgreSQL
  refuses `FOR UPDATE` on the nullable side of the outer joins in
  `base_select()`, so the lock is taken on a bare select and every precondition
  is re-validated after acquiring it.
- **Erasure policy (signed decision #11).** A privacy erasure zeroes the actor
  and redacts the reason while the audit row itself survives; the snapshot is
  cleared by retention instead. Erasure therefore cannot silently break a revert
  that is still within its TTL.
- Removed the incomplete `ticket_service::merge_tickets()` prototype.

### Testing
- 29 tests in `tests/service/merge_service_test.php` covering the merge, the
  revert, the TTL, the gates and the locking path.

---

## [2.8.1] — 2026-08-12

**Enterprise ticket merge, phase F0 — data foundations.** Schema only: nothing
writes to these structures yet and the merge UI stays off.

### Added
- Table **`local_moodesk_merge_audit`** — one row per merge attempt, immutable
  except for the revert columns, carrying `source_snapshot`, `migrated_items`,
  `conflicts` and the TTL/revert timestamps. The name is 25 characters, not the
  32-character one in design doc §5.1, which would not fit Moodle's cap.
- **`merged_status`** and `merged_audit_id` columns on the ticket table, with an
  idempotent backfill from the legacy `mergedinto` column. `mergedinto` is kept
  for one compatibility release so external readers do not break.
- **`ticket::is_read_only()`** — the single place that decides whether a merge
  state blocks writes. Phase F2 is what will consult it.

### Changed
- The privacy provider declares the new audit table from the day it exists,
  rather than after the fact.

---

## [2.8.0] — 2026-08-12

Table prefix migration, required by `moodle-plugin-ci`'s `TablePrefixFinder`.

### Changed
- **The 17 plugin tables move from the `local_mhd_` prefix to `local_moodesk_`**,
  matching the component name. The custom-field pair becomes
  `local_moodesk_cfield` / `local_moodesk_cfield_value` to stay under Moodle's
  28-character table-name cap.
- `db/upgrade.php` keeps the legacy names inside its historical steps, gated
  behind `$legacyschema`; the rename step itself is ungated so it runs on every
  install.
- The `local_mhd_savedview_` user-preference prefix is **intentionally
  untouched** — it is a preference key, not a table.

> Closes the last red step of the Code checks workflow (`Validating`).

---

## [2.7.0] — 2026-08-07

Reporting export, REST scope enforcement, and two defects found by the pre-listing
QA pass on PostgreSQL.

### Added
- **Reports CSV export + printable view (Pro)** — per-section CSV download
  (volume, resolution, agents, departments, SLA) and a print layout that drops
  the app-shell chrome. `report_service::get_csv_dataset()` reuses the on-screen
  report data, so exports cannot drift from what the page shows.
- **Per-token scope enforcement on the REST API (Enterprise)** — scopes were
  stored and displayed but inert; authorization came only from the token
  owner's capabilities. They are now a third gate, evaluated after edition and
  capability. Scopes only ever restrict, never widen.

### Fixed
- **Reports was broken on every PostgreSQL install.** `get_volume_by_day()`
  bound the day-bucket size as a query parameter and repeated it in the
  `GROUP BY` under different placeholder names. PostgreSQL matches `GROUP BY`
  expressions against the SELECT list textually, saw `$1` vs `$3`, and rejected
  the query with `column timecreated must appear in the GROUP BY clause`.
  MySQL/MariaDB accepted it, so the breakage was PostgreSQL-only and had no
  test coverage. The bucket is now interpolated as a literal.
- **The distributable ZIP used the wrong root directory.** It packaged the tree
  as `local_moodesk/`, so the documented manual install — unzip into `local/` —
  produced `local/local_moodesk/` and a plugin Moodle never detected. The root
  is now `moodesk/`. Installing through the web UI was unaffected and had
  masked the bug.
- Residual `moodDesk` (double d) spelling in debug strings, CLI messages,
  docblocks and build config.

### Changed
- **Unscoped API tokens are backfilled on upgrade.** Tokens issued before
  enforcement with no scopes ticked stored an empty list, which now denies every
  call — their integrations would have started failing on an upgrade with no
  obvious connection. The upgrade step grants them the full scope set, matching
  the access they effectively had. Tokens with an explicit list are untouched.
- The API token form now refuses to issue a token with no scopes selected,
  instead of minting a credential that fails on first use.
- `build-package.sh` refuses to build from a dirty working tree, so a released
  artifact is always reproducible from its manifest sha. `--allow-dirty` covers
  local iteration; `build-image.sh` passes it.
- READMEs and docs reconciled to the current feature set (Agent Management,
  Moodle 4.5 floor, S1 distribution).

### Testing
- First tests for `report_repository` and `report_service`, both exercising the
  aggregate SQL. They exist mainly as a cross-database guard — running them on
  the PostgreSQL CI matrix is the point.

---

## [2.6.0] — 2026-07-18

**Agent Management A5 — operational metrics, capacity & supervision (Enterprise).**
Per-agent workload and responsiveness metrics, capacity indicators and load
distribution across the roster.

---

## [2.5.0] — 2026-07-18

**Agent Management A4 — consolidated agent panel (Pro).** A single panel per
agent with their department and team membership, editable in place.

---

## [2.4.0] — 2026-07-18

**Agent Management A1 — directory polish.** Search, filters and attention states
on the agents directory.

---

## [2.3.0] — 2026-07-18

**Agent Management A3 — offboard (Starter).** Remove an agent from inside
mooDesk and requeue the tickets they were holding, so nothing is stranded on a
departed account.

---

## [2.2.0] — 2026-07-18

**Agent Management A2 — onboard (Starter).** Add an agent from inside mooDesk
instead of routing the admin through Moodle's role assignment screens.

---

## [2.1.0] — 2026-07-18

**Agent Management A0 — foundation.** The `local/moodesk:manageagents`
capability, `agent_service`, the roster query and the Agents navigation entry.
Groundwork for A1–A5; no agent mutation yet.

---

## [2.0.0] — 2026-06-02

UX/UI close-out **F8 — Legacy removal & final consolidation** (epic [`docs/ui-app-shell-architecture.md`](docs/ui-app-shell-architecture.md)). Retires the dual-mode safety net: the mooDesk application shell is now the **only** interface. **Breaking** — the `ui_mode` rollback contract is removed, hence the major bump.

### Removed
- The **`ui_mode`** admin setting (legacy | app) and its language strings.
- `app_page::shell_enabled()` and the entire legacy path in `app_page` (the `admin`/`standard` pagelayout + Moodle header/footer wrapping). `app_page` now always renders on `embedded` inside the shell; the `$legacylayout` constructor argument is gone.
- The `{{^appmode}}` legacy branch of `dashboard.mustache` (and the `appmode` flag plumbed from `dashboard.php`); the F5 redesign is the only dashboard markup.
- `navigation_service::get_items()` (the legacy Moodle-primary projection) and the legacy children branch of the primary-navigation hook — Moodle's header now always shows the single "mooDesk" crossing point.

### Changed
- **`reports.php` migrated into the shell** (page key `reports`) — the last operator screen still on Moodle chrome; it now renders through `app_page` like every other screen, so the shell is genuinely the only mode.
- Stale `ui_mode = app` comments across the entry files and docblocks normalized.
- **Submit-ticket form** wrapped in a mooDesk product card with a page header and scoped vertical-layout styling (labels on top, full-width controls, integrated editor/attachments, footer action bar, brand primary button) — `$form->render()` is wrapped verbatim, so moodleform validation/CSRF are untouched (ADR §4). Post-lab refinement of the showcase form.
- `version.php` → `2026060512`, release **`2.0.0`**.

> After this phase the only product screens intentionally outside the shell are the **public CSAT survey** (`csat.php`, `login` layout — by design) and Moodle's **admin settings tree** (the legitimate sysadmin boundary). See the close-out report for the full residual-surface list.

---

## [1.11.0] — 2026-06-02

UX/UI close-out **F7 — Global visual polish & UX close-out** (epic [`docs/ui-app-shell-architecture.md`](docs/ui-app-shell-architecture.md); backlog [`docs/ui-polish-backlog.md`](docs/ui-polish-backlog.md)). Visual/UX polish only; **does not** change navigation architecture or remove legacy — `ui_mode` and rollback stay intact. No new features/data; everything scoped under `.moodesk-app`.

### Added
- **Notifications** in the top bar — a bell with an unread-count badge that surfaces Moodle's **own** notification stream (chrome the embedded layout removes); count comes from Moodle's popup-notifications API when present, guarded for 4.5–5.2. No new data store.
- **Off-canvas mobile drawer** — below 768px the sidebar slides in over the content via the top-bar toggle, with a backdrop (click / Escape to close); collapse stays a desktop-only concept.
- **Accessibility** — skip-link to content, visible `:focus-visible` rings across the shell, ARIA wiring for the toggle (`aria-controls`/`aria-expanded`) and the user menu (`aria-haspopup`/`role=menu`), Escape/outside-click close.
- **Branding** — mooDesk logo glyph in the sidebar brand mark; new `bell`, `close`, `logo` shell icons.
- **Scoped form styling** (`components/_forms-controls.scss`) giving the submit/reply showcase forms a product-native look while keeping moodleform validation/CSRF (ADR §4 — style scoped, upgrade-safe).

### Changed
- Global shell spacing/rhythm and icon-set normalization (sidebar groups, topbar); branded primary buttons; notification badge.
- `app_shell.js` enhanced (drawer, user-menu close, breakpoint-aware ARIA) while keeping the desktop collapse + per-user preference.
- `version.php` → `2026060508`, release `1.11.0`.

---

## [1.10.0] — 2026-06-02

UX/UI close-out **F6 — Navigation model** (epic [`docs/ui-app-shell-architecture.md`](docs/ui-app-shell-architecture.md); decision [`docs/ui-navigation-model.md`](docs/ui-navigation-model.md)). Implements the Accepted *two-projections-of-one-catalogue* model. App-shell only; legacy mode and the `ui_mode` rollback stay intact. No new features.

### Added
- **`navigation_service::get_catalogue()`** — the single source of truth for the shell: the complete internal navigation (now incl. **Tickets, Teams, Custom Fields, Webhooks, API Tokens, License**), each node tagged with a section group. Consumed via **`get_shell_items()`**.
- **`navigation_service::get_primary_nav_node()`** — the single "mooDesk" crossing point for Moodle's primary navigation in app mode.
- **`navigation_service::resolve_active_key()`** — page-key → node map (nav model §4): detail/edit/member sub-pages light up their top-level item (closes the F3 *Tickets* gap and the F4 admin gaps).
- Section grouping in the shell sidebar (**Operation / Administration / System**), `nav_item::$group`, and shell icons for the new nodes.

### Changed
- The shell sidebar consumes `get_shell_items()` (grouped) instead of the flat `get_items()`.
- The primary-navigation hook is **mode-aware**: app mode renders a single crossing point ("Moodle invisible"); **legacy mode is byte-identical** to pre-F6 (entry point + `get_items()` children).
- `version.php` → `2026060507`, release `1.10.0`.

---

## [1.9.0] — 2026-06-02

**UI F5 — modern SaaS dashboard.** Visual redesign of the operational dashboard
inside the shell, app-mode gated. Reuses `dashboard_service` output as-is.

---

## [1.8.2] — 2026-06-02

**UI F4 — admin CRUD screens migrated to the application shell**, flag-gated.
Same `app_page` / `render_within_shell` pattern proven by F2/F3; UI re-wrapping
plus one table normalization, no new functionality.

---

## [1.8.1] — 2026-06-02

**UI F3 — operation screens migrated to the application shell** (list, submit,
detail), flag-gated.

---

## [1.8.0] — 2026-06-02

**UI F2 — application shell on Moodle's `embedded` layout**, flag-gated, with
the dashboard as pilot screen.

---

## [1.7.2] — 2026-06-02

**UI F1 — design tokens + scoped reset under `.moodesk-app`.** Zero visual
change; groundwork for the shell.

---

## [1.7.1] — 2026-06-02

**UI F0 — foundations.** `navigation_service` + `app_page`. Zero visual change.

---

## [1.7.0] — 2026-06-02

Supported-versions update: floor raised to Moodle 4.5 LTS; the current 5.x line (5.0 / 5.1 / 5.2) is validated in CI.

### Changed
- Minimum Moodle raised from 4.1 to **4.5 LTS** (`requires = 2024100700`); 4.1–4.4 are EOL.

### Added
- Moodle **5.0 / 5.1 / 5.2** legs added to the PHPUnit matrix (PostgreSQL 16), Starter + gate-open Enterprise.

---

## [1.6.0] — 2026-06-02

Revocation without a release (JWT Gate #2, Option A). A licence can now be revoked by publishing a **signed static revocation list** (CRL) instead of editing a constant and cutting a release. Completes the JWT gate set ([`docs/jwt-gates-implementation-plan.md`](docs/jwt-gates-implementation-plan.md)); the hardcoded `REVOKED_LICENSES` const stays as the offline fallback.

### Added
- **Signed CRL revocation.** New `revocation_service` fetches `revocations.json` + a detached `revocations.json.sig` from a configurable URL, verifies the RS256 signature over the raw bytes with the **already-embedded** public key, and persists the verified list. `license_service::validate_token()` now denies a licence listed in **either** the CRL **or** the `REVOKED_LICENSES` const (additive — the CRL can never grant access or raise an edition).
- New daily scheduled task `refresh_revocation_list` (independent from the future `sync_license` backend), a `revocation_list` MUC cache, and admin settings for the CRL URL + an enable toggle. The fetch is a plain GET of signed files — **no site or personal data is transmitted** (declared via an empty-fields Privacy API external-location link).
- `dev_scripts/sign-revocations.sh` to build, sign and self-verify the CRL.

### Changed
- **Fail-closed + anti-rollback.** Any download/signature/freshness failure keeps the last verified list (never opens access); a CRL whose `updated_at` is not strictly newer is ignored. Applying a newer CRL purges the cached licence claims so a revocation takes effect on the next request.
- `docs/license-issuance.md` documents the CRL as the default revocation path (const = offline net). `version.php` bumped to `2026060400`, release `1.6.0`.

---

## [1.5.0] — 2026-06-02

Exit license compatibility mode (JWT Gate #3). The legacy `local_moodesk/edition` config row no longer elevates the active edition — the signed JWT is now the **only** non-Starter signal. This closes the bypass where a site administrator could self-promote a Starter install to Pro/Enterprise by editing `mdl_config_plugins` without any licence. Completes the S1 distribution-model unblock together with Gate #1 ([`docs/jwt-gates-implementation-plan.md`](docs/jwt-gates-implementation-plan.md)).

### Removed
- **`COMPATIBILITY_MODE`** and the legacy edition fallback in `license_service::get_edition()`, plus the once-per-request compat `debugging()` warning and its test reset hook.
- **Edition selector.** The `local_moodesk/edition` admin setting and its section heading are gone; the active edition is taken solely from the licence JWT. The orphaned `edition` config row is dropped on upgrade.
- **Compatibility banner.** The admin-page "licence not configured" banner (`before_standard_top_of_body_html_generation` hook + `compat_banner_*` strings) is removed.

### Changed
- **BREAKING (operational).** A site running Pro/Enterprise *only* via the legacy `edition` config (no valid JWT) now resolves to **Starter**. Sites with a valid, site-bound JWT are unaffected. No production tenant relied on compat mode at the time of this change (pre-commercial).
- New PHPUnit-only test seam `edition_service::set_edition_for_testing()` replaces `set_config('edition', …)` as the way suites open Pro/Enterprise feature gates.
- `version.php` bumped to `2026060300`, release `1.5.0`.

---

## [1.4.1] — 2026-06-02

Licence site-binding enforcement (JWT Gate #1). The mandatory `wwwroot` claim is now hard-enforced, so a genuinely-signed licence can only activate on the install it was issued for. Part of the S1 distribution model unblock ([`docs/jwt-gates-implementation-plan.md`](docs/jwt-gates-implementation-plan.md)).

### Added
- **Site binding.** `license_service::validate_token()` now compares the `wwwroot` claim against `$CFG->wwwroot` after signature verification. Normalization is strict — `rtrim(strtolower(trim(...)), '/')` only — so `http`↔`https` and `www.`↔apex differences are **not** tolerated; a domain change requires re-issuance. `wwwroot` is now a **required** claim (absent ⇒ malformed).
- New status `STATUS_SITE_MISMATCH` (distinct from `forged`): a valid signature pointed at the wrong site reports the real cause on the License status page, which also surfaces the bound URL via `get_diagnostic()`.
- Tests covering host/scheme/www-apex mismatch, normalization (trailing slash + case), missing/non-string claim, and `get_status` / `get_active_license` / `edition_service` / diagnostic integration.

### Changed
- `version.php` bumped to `2026060200`, release `1.4.1`.

---

## [1.4.0] — 2026-06-01

Enterprise REST API (GA). Promotes the previously experimental "Lab API" to a supported, capability-driven REST surface over Moodle native web services, adds ticket status/assignment and webhook CRUD endpoints, and ships a self-service API token manager.

### Added
- **REST API (Enterprise).** New `moodesk_api` web service — native `wstoken` authentication, `restrictedusers=0` (capability-driven), gated by the `api_access` Enterprise feature so Starter/Pro installs return `featurenotavailable`. Ticket endpoints: `local_moodesk_list_tickets`, `_get_ticket`, `_create_ticket`, `_reply_ticket`, `_transition_status`, `_assign_ticket`. Webhook CRUD: `local_moodesk_list_webhooks`, `_get_webhook`, `_create_webhook`, `_update_webhook`, `_delete_webhook`. Full contract in [`docs/api-reference.md`](docs/api-reference.md).
- **Self-service API tokens.** New page `/local/moodesk/api_tokens.php` (capability `local/moodesk:useapi`) mints native tokens with a friendly label and scopes, showing the plaintext exactly once. Backed by the new `local_mhd_api_token` companion table; the token secret is never stored by the plugin (expiry/last-used are read through from `{external_tokens}`).
- Token scopes (`tickets:read`, `tickets:write`, `webhooks:read`, `webhooks:write`) are recorded and displayed; enforcement is planned for a later release (authorization is governed by Moodle capabilities).

### Changed
- The experimental `moodesk_lab_api` service and the `local_moodesk_lab_*` functions were renamed to `moodesk_api` / `local_moodesk_*`. The lab service shipped disabled (`enabled=0`), so no GA consumer is affected.
- `version.php` bumped to `2026060100`, release `1.4.0`.

### Database
- New table `local_mhd_api_token` (companion metadata for native web service tokens). The privacy provider exports token metadata and, on erasure, revokes the underlying native token and drops the companion row.

---

## [1.3.1] — 2026-05-30

Reconciliation release. Brings the staging-line fixes (CI/PHPUnit, capability
strings, automation recursion, ticket visibility) onto `main` on top of the
1.3.0 mooDesk rebrand, and restores a coherent `version.php` bump so installed
sites upgrade cleanly.

### Fixed
- **Capability language strings**: role/permission descriptions were keyed as
  `local/moodesk:*` and never resolved, so the Define-roles and Check-permissions
  screens showed `[[local/moodesk:submit]]` placeholders. Re-keyed to the
  Moodle-native `moodesk:*` form (`get_capability_string()` strips the component),
  and added the three strings that were defined in `db/access.php` but missing
  from `lang`: `moodesk:manageteam`, `moodesk:managewebhooks`, `moodesk:useapi`.
- Automation engine recursion guard: `process_event_trigger` now claims the `(rule, ticket)` slot with a placeholder `rule_log` row *before* running the rule's actions, so a re-entrant event raised by an action (e.g. a `set_status` action firing `ticket_status_changed` again) sees `has_fired()` as true and short-circuits instead of re-firing. The placeholder outcome is rewritten via the new `rule_repository::update_log_result()` once the actions complete.
- License compatibility-mode warning no longer fires during site install or the
  `moodle-plugin-ci` PHPUnit bootstrap — gated on `during_initial_install()` /
  `PHPUNIT_UTIL`, which had been red-lighting the whole PHPUnit job.
- Ticket `VIEW_ALL` list is now global for users with the `viewall` capability.

### Tests
- Greened the PHPUnit suite: `property_exists()` instead of the removed
  `assertObjectHasProperty()`, primary-nav hook tests skipped on Moodle < 4.4,
  and the automation runtime suite now runs under the Pro gate so the engine is
  actually exercised.

---

## [1.3.0] — 2026-05-11

Brand + UX consistency release. Canonical product name is now **mooDesk** (1 D, capital D), replacing the prior "Help Desk" / "moodDesk" mix across navigation labels, page headings, capability strings, role names, message-provider names, privacy metadata, REST-API documentation, and all user-facing docs. The plugin also fixes the duplicated page-header bug ("Dashboard / Dashboard", "Categories / Categories", …) that the v1.2.0 Starter smoke test surfaced.

### Changed
- **Primary navigation label: "Help Desk" → "mooDesk".** `nav_helpdesk` lang key is preserved (no rename) but its EN/ES value flips to "mooDesk", so the parent navbar node injected by `\local_moodesk\hook_callbacks::extend_primary_navigation` now reads "mooDesk". `pluginname` flips for both locales as well, so the admin tree under **Site administration → Plugins → Local plugins** also shows "mooDesk".
- **Page heading hierarchy: brand on top, context below.** Every entry point (22 pages: `index.php`, `dashboard.php`, `reports.php`, `submit.php`, `categories.php`, `departments.php`, `teams.php`, `automations.php`, `webhooks.php`, `license.php`, `csat.php`, `custom_fields.php`, `view.php`, plus the eight edit forms and two member-management pages) now calls `$PAGE->set_heading(get_string('page_global_heading', 'local_moodesk'))` — a new lang key whose constant value is "mooDesk". `set_title()` carries the contextual label as "mooDesk: Dashboard", "mooDesk: Categories", "mooDesk: Edit team: Tier-1", "mooDesk: &lt;ticket subject&gt;", and so on, composed from `pluginname` + ': ' + the existing `*_heading` / `$pagetitle` / `format_string($ticketobj->subject)`. The inline `<h2>` blocks already rendered inside the templates (dashboard, ticket_list, reports, all admin/* templates, csat_survey, csat_survey_submitted) continue to consume their `*_heading` lang keys — which now carry the clean context-only label ("Dashboard", "Categories", "Webhooks", "API Tokens", …) instead of "Help Desk — X".
- **Lang strings rebranded across the board.** All occurrences of "Help Desk" / "Helpdesk" / "Help desk" / "help desk" / "helpdesk" / "moodDesk" / "Mesa de ayuda" / "mesa de ayuda" inside string VALUES become "mooDesk" — including `pluginname`, `nav_helpdesk`, `messageprovider:ticket_notification`, the eight capability description strings (`local/moodesk:submit`, `:viewown`, `:viewall`, `:reply`, `:agent`, `:managesettings`, `:managedepartment`, `:manageautomations`, `:viewreports`), the API token documentation block (`api_tokens_desc`, `api_webservices_disabled`), the privacy metadata (`privacy:metadata:local_mhd_ticket`, `privacy:metadata:core_files`), the misconfiguration banner (`compat_banner_title`), the seeded role name and description (`role_agent_name` = "mooDesk Agent" / "Agente de mooDesk", `role_agent_description`), and the agent error string (`invalidassignee`). Lang KEYS remain stable — every `get_string()` call site keeps working without changes.
- **Documentation rebranded** across `README.md`, `local_moodesk/readme.md`, and 15 files under `docs/` (`admin-guide.md`, `backup-restore.md`, `docker-image.md`, `etapa2-hardening-audit.md`, `faq.md`, `feature-matrix-starter-pro-enterprise.md`, `getting-started.md`, `lab-testing-checklist.md`, `license-issuance.md`, `local-dev-edition-override.md`, `release-process.md`, `rest-api-enterprise-lab-notes.md`, `scss-build-validation.md`, `ticket-merge-design-doc.md`, `webhooks-pro-implementation-notes.md`). Historical snapshots under `docs/estado*.md` and prior CHANGELOG entries are kept verbatim — they are an immutable record of what was true at the time. The component identifier `local_moodesk`, the path `local/moodesk/`, and capability prefix `local/moodesk:*` are technical names, not branding, and stay unchanged.

### Notes
- Operators upgrading from 1.2.x must purge caches once (Site administration → Development → Purge all caches, or `php admin/cli/purge_caches.php`) so Moodle re-compiles the Mustache templates and re-renders the navigation tree with the new strings.
- No DB schema changes, no capability changes, no settings changes, no breaking changes for plugins that consume `get_string()` calls or check the navigation node ID. The visual change is intentional: every previously "Help Desk"-branded UI surface now reads "mooDesk".

---

## [1.2.1] — 2026-05-11

Hotfix release. Blocker found during clean-install smoke test of the v1.2.0 Starter ZIP: the ticket detail page leaked the template docstring + JSON example context into the rendered UI.

### Fixed
- **`templates/ticket_detail_thread.mustache`: comment closed early, leaking docstring into the rendered ticket page.** The `{{! ... }}` header block contained the literal sequence `attachments[]: {url, filename, size}}`. Moodle's Mustache parser (`bobthecow/mustache.php`) closes a `{{!` comment at the *first* `}}` it encounters; the trailing `}}` of the shape annotation terminated the comment at line 10 of the file, so lines 11–28 (remaining context-variable docs + the full `Example context (json)` block) were emitted as HTML on every ticket detail render. Caught during the clean-install smoke test of `dist/local_moodesk-1.2.0-starter.zip` against a fresh Moodle instance — ticket #1 displayed the comment tail between the header card and the reply thread. Rewrote the `thread[]` shape annotation in prose so no literal `}}` survives inside the comment body. No HTML or logic changes; only the comment text. Operators upgrading from 1.2.0 must purge caches (Site administration → Development → Purge all caches, or `php admin/cli/purge_caches.php`) after install because Moodle caches the compiled Mustache output.

---

## [1.2.0] — 2026-05-11

First public **license-aware** Starter release. Closes Phase A (Stabilize) of the Fase A–D roadmap. Cuts the gap between pre-license `1.1.x` and the runtime that has been live in main since 2026-05-10. Also publicly distributable for the first time: ZIP available at `digital-mood/moodesk` releases.

### Documentation
- **README.md (repo root)** — new, top-level overview describing what ships, the three editions, the install path, and where each doc lives. Audience: first-time visitor on the GitHub repo page. Companion to `local_moodesk/README.md` (developer reference) and `docs/getting-started.md` (operator install runbook).
- **`docs/getting-started.md`** — full rewrite in English. Replaces the legacy `1.0.0-beta` Spanish draft that contained drift (it advertised email ingestion as a Starter feature; in reality email ingestion is Phase D scaffolding without a service). The new version reflects the real `edition_service::FEATURES` map, walks through install → settings → role setup → smoke check, and includes the Pro/Enterprise license JWT activation flow.
- **`local_moodesk/README.md`** — refreshed for `1.2.0`. License JWT model (RS256, embedded public key, transparent fallback) and the migration compat banner are now documented in the Edition section.

### Privacy
- **GDPR Privacy provider extended to 5 additional tables.** `local_moodesk\privacy\provider` now declares metadata, exports, and erasure for `local_mhd_dept_member`, `local_mhd_team_member`, `local_mhd_csat_rating` (indirect via ticketid), `local_mhd_email_message` (indirect via ticketid). The previous provider only covered tickets, replies, and history; agents removed from a department or team had no visible audit/erasure path, and CSAT comments / email senders contained PII that survived `delete_data_for_user`. `anonymise_user()` now captures the requester's ticket IDs *before* zeroing FKs so it can scrub indirect PII (CSAT comment, email sender_email + sender_name + raw_subject) on the linked rows. `delete_all_plugin_data()` orders the deletions to respect FK dependencies. Lang strings added in EN + ES for the new metadata blocks.

### CI / Tooling
- **`.github/workflows/code-checker.yml`** — Issue #10 A1 fix. Every `moodle-plugin-ci` subcommand (`phplint`, `phpcpd`, `codechecker`, `phpdoc`, `validate`, `savepoints`, `mustache`, `grunt`) now passes `./plugin/local_moodesk` as the explicit plugin path. `moodle-plugin-ci ^4` made the positional plugin argument required on subcommands; before this fix every static check after the install step exited because the plugin was not registered for the subcommand call.
- **`local_moodesk/cli/seed_smoke_data.php`** — Issue #10 B5 fix. Completed `@param` blocks on the four `smoke_get_or_create_*` helpers (`user`, `course`, `department`, `ticket`).
- **18 mustache templates** — Issue #10 B3 Cat A fix. Added a `{{! @template local_moodesk/<name> ... }}` documentation block at the top of every admin / detail / dashboard / form template that previously failed `moodle-plugin-ci mustache --max-lint-warnings 0`. Each block follows the canonical `license_status.mustache` style: short description, `Context variables:` section enumerating the consumed variables, and a minimal `Example context (json):` block.

### Infrastructure
- **Container image: generate the locales the shipped language packs rely on.** Moodle's environment page was emitting _"Your server does not seem to fully support the following languages: English (en), Español - Internacional (es) … Instead, the global locale (en_AU.UTF-8) will be used to format certain strings such as dates or numbers"_ because the base image (`php:8.2-apache`, debian bookworm) only ships `C.UTF-8` / `POSIX`, so Moodle's per-render `setlocale()` call fell back to the global default for every non-English language. [`dev_scripts/docker/Dockerfile`](dev_scripts/docker/Dockerfile) now installs the `locales` package and runs `locale-gen` for `en_US.UTF-8`, `en_AU.UTF-8`, `es_ES.UTF-8`, `pt_BR.UTF-8`, `pt_PT.UTF-8`, `fr_FR.UTF-8` and `de_DE.UTF-8`. The locale work is folded into the existing `apt-get` `RUN` block (single layer, shares `apt-get update`). `ENV LANG` / `LC_ALL` are deliberately left unset — the system stays on `C.UTF-8` so Apache, the cron sidecar and the entrypoint logs remain locale-invariant; only Moodle's per-request `setlocale()` ever activates a non-C locale. Rationale, full locale list, image-size impact, and rebuild/verify runbook live in the new [`docs/docker-image.md`](docs/docker-image.md). No change to the entrypoint, cron loop, or healthchecks. Roll-out: `workflow_dispatch` of [`.github/workflows/docker-image.yml`](.github/workflows/docker-image.yml) → bump `MOODESK_IMAGE_TAG` in Coolify staging → redeploy.

### Added
- **Async webhook dispatch (Fase 1.5c).** Outbound webhooks no longer block the agent's request thread on each ticket event. New table `local_mhd_webhook_queue` (id, webhookid, event_type, ticketid, payload, attempts, next_retry_at, status, last_error, timecreated, timemodified) buffers deliveries between the observer-driven event and the new `\local_moodesk\task\dispatch_webhook` scheduled task, which drains up to 50 pending rows per minute. `webhook_service::dispatch()` keeps its public signature (still called by the four observer callbacks) but writes one queue row per active subscription instead of issuing an HTTP POST inline; `webhook_service::process_queue_row()` is the new public entry point used by the task to attempt delivery, fall through `webhook_service::deliver()` (now `protected` for test override), and update the queue row to `done`, schedule a retry with exponential backoff (1m → 5m → 30m → 2h, max 5 attempts), or mark `failed` (subscription deleted/disabled, payload corrupt, or retry cap hit). Successful deliveries continue to write a `local_mhd_webhook_log` row, so the existing delivery-log UI surfaces every attempt without changes. `send_test()` from the admin UI remains synchronous so the modal can render its result immediately. The new scheduled task ships **enabled by default** (cron every minute); the `edition_service::has_feature('webhooks')` gate at the top of `execute()` short-circuits Starter and Pro installs so they pay only the cost of one cron tick. Coverage: 11 tests in `tests/service/webhook_service_test.php` (covers `dispatch` enqueue, `process_queue_row` for done / retry / max-attempts / disabled / deleted / corrupt-payload, backoff progression, gate-closed, and queue ordering / limit) plus 2 integration tests in `tests/task/dispatch_webhook_test.php` (gate short-circuit and end-to-end drain against an unreachable loopback subscription).
- **License sync service scaffold (Fase 1.6 / Etapa 5.3).** New `local_moodesk\service\license_sync_service` defines the contract for pull-based remote verification against the digitalMood licensing backend (POST `licenses.digitalmood.io/api/v1/verify` by default, overridable via `local_moodesk/license_sync_endpoint` config). The service ships disabled by default — `SYNC_ENABLED = false` short-circuits `sync()` to a no-op so the runtime is safe even though no backend exists yet; a single const flip in a future release activates everything. Response contract: `{status: valid|revoked|updated_license, new_license?: jwt}`. Auth uses the active licence JWT as bearer (`Authorization: Bearer <license_jwt>`) — reuses the RS256-signed claim instead of introducing a second secret. On `revoked` the local `license_key` config is cleared and the MUC cache purged; on `updated_license` the proposed JWT is re-validated through `license_service::validate_token()` before being persisted, so a compromised backend cannot lock a customer out of a working licence. Network failures, HTTP non-2xx, malformed JSON, and unknown statuses are all silent. Companion scheduled task `\local_moodesk\task\sync_license` (registered in `db/tasks.php`, daily at 03:00, enabled by default) wraps `sync()` so flipping `SYNC_ENABLED = true` is the only step needed to activate the subsystem. Coverage in `tests/service/license_sync_service_test.php` (13 tests) pins the activation gate, the per-status response contract, the forged-JWT and garbage-JWT defences on `updated_license`, and the endpoint resolution helper.
- **Etapa 2 entry-point hardening audit (`docs/etapa2-hardening-audit.md`).** Inventory of every UI page, scheduled task, and primary-navigation node in the plugin with its capability check, edition-gate feature key, classification (`STARTER_FREE` / `PRO_GATED` / `ENTERPRISE_GATED` / `INTERNAL`), and notes on follow-up work. It is the canonical source for entry-point gating policy going forward. Surface count: 24 pages (15 free / 6 Pro-gated / 2 Enterprise-gated / 3 internal) + 2 starter scheduled tasks + 8 primary-nav nodes.
- **Page-gate regression suite (`tests/page/page_gate_test.php`).** Static contract test asserts every Pro/Enterprise page calls `edition_service::has_feature()` with the feature key declared in the audit doc — catches accidental gate removal in future refactors. Two navigation tests verify the Help Desk parent stays visible on Starter while the Reports / Automations children are hidden (D7=b lock-in). Navigation tests are skipped when `moodesk_LOCAL_EDITION` is defined (dev-override skip pattern).

### Changed
- **Primary navigation: hide Pro-only children on Starter (Etapa 2 / D7=b).** `\local_moodesk\hook_callbacks::extend_primary_navigation` now AND-combines the existing capability check with `edition_service::has_feature()` for Reports (`advanced_reports`) and Automations (`automation`). On a Starter site, an admin who otherwise has the `viewreports` / `manageautomations` capability no longer sees a navbar link that would only redirect to the upgrade prompt.
- **`reports.php`: standardize on the redirect-and-exit gate pattern (Etapa 2 / D2=a).** The page now bails out with `header() → edition_upgrade_prompt → footer() → exit` when `has_feature('advanced_reports')` is false, instead of rendering the report template with an `is_pro` flag and letting the template render the upgrade copy. This matches every other Pro-gated page (`automation_edit`, `csat`, `custom_field*`, `team*`) and means the report template is no longer responsible for the upgrade messaging path. The `is_pro` key in the template context is preserved (always true past the gate) so `templates/reports.mustache` does not need a structural change.

### Added
- **`automation_service`: wire the runtime engine the observers already expected.** The Phase 8 automation feature shipped with a complete data model (`local_mhd_rule`, `local_mhd_rule_log`), CRUD UI (`automation_edit.php`), and the three observer hooks `rule_on_ticket_created` / `rule_on_status_changed` / `rule_on_sla_breached` in [`observer.php`](local_moodesk/classes/observer.php) — but the dispatcher they all called, `automation_service::process_event_trigger()`, was never implemented. The service's own docblock declared runtime execution _"intentionally out of scope"_, and every Pro/Enterprise install with the `automation` feature active was raising `debugging('local_moodesk observer::rule_on_ticket_created failed: Call to undefined method local_moodesk\\service\\automation_service::process_event_trigger()')` on every triggering event (caught silently by the observer's try/catch, never seen by the user — except in `DEBUG_DEVELOPER`, where it surfaced as a wall of warnings on the ticket view path). Implemented in [`classes/service/automation_service.php`](local_moodesk/classes/service/automation_service.php): `process_event_trigger(string $trigger_type, \stdClass $ticket, array $extra = [])` plus private helpers `process_single_rule`, `trigger_config_matches`, `conditions_match` / `single_condition_matches` / `compare_int`, `execute_action`, and `resolve_automation_actor_id`. Behaviour: (a) loads `repo->get_active_rules_by_trigger($type)`; (b) per rule, checks `repo->has_fired($ruleid, $ticketid)` as the idempotency + recursion guard (`set_status` during a `status_changed` trigger does not loop); (c) honors `trigger_config.from` / `.to` for `TRIGGER_STATUS_CHANGED`; (d) AND-evaluates all 6 `CONDITION_*` types (`priority_is`, `status_is`, `department_is`, `category_is`, `assignee_is`, `is_unassigned`) with `OPERATOR_EQUALS` / `OPERATOR_NOT_EQUALS`; (e) dispatches all 5 `ACTION_*` types — `set_status` → `ticket_service::transition_status()`, `set_priority` → `set_priority()`, `assign_to` → `assign()`, `set_department` → `set_department()`, `add_note` → `reply_service::add_internal_note()` — under the `get_admin()` actor so `require_capability()` guards stay intact on the public service methods; (f) writes a `rule_log` row per fire attempt with success=1 (all actions OK) or success=0 plus per-action error notes. Unknown / malformed trigger_type, conditions JSON, or actions JSON are silently no-ops or logged as failures — no exception escapes the engine. New coverage in [`tests/service/automation_service_runtime_test.php`](local_moodesk/tests/service/automation_service_runtime_test.php): 6 tests / 14 assertions exercising the no-op safety paths (unknown trigger, no matching rules), the happy path (`set_priority` action applies + log row), conditions-block-firing, the idempotency guard (second `process_event_trigger()` call must not re-mutate the ticket), and `trigger_config.from/to` filtering for `status_changed`. Suite regression went from 15 errors → 2 in this commit alone — most of the prior "15 errors" baseline was tests that incidentally triggered `create_ticket()` and tripped the observer's debugging call.

### Fixed
- **`csat_service`: implement the per-ticket API every caller already assumed.** `view.php:344` invoked `csat_service::create()->get_for_ticket($ticketid)` and `observer.php:368` invoked `csat_service::create()->invite($event->objectid)`, but neither method existed on the class — every ticket detail page on a Pro install (with `csat` enabled, the dev-override or a Pro JWT in place) fatalled with _"Call to undefined method local_moodesk\\service\\csat_service::get_for_ticket()"_; the observer leg was masked by its own try/catch but silently broke survey invitations on `ticket_status_changed`. Added two methods to [`classes/service/csat_service.php`](local_moodesk/classes/service/csat_service.php): `get_for_ticket(int $ticketid): ?\stdClass` (thin delegate to `csat_repository::get_by_ticket()`, no edition gate — caller-authorized; matches the DTO shape the sidebar template consumes: `rating`, `comment`, `submitted_at`, `token_expires`) and `invite(int $ticketid): void` (idempotent — short-circuits when a row already exists for the ticket; `edition_service::require_feature('csat')` enforced so direct callers cannot bypass; 64-hex token via `random_bytes()`; TTL reads `local_moodesk/csat_token_expiry_days`, defaulting to 30 days; `usermodified = 0` for the system-initiated record). New coverage in [`tests/service/csat_service_test.php`](local_moodesk/tests/service/csat_service_test.php): `get_for_ticket` (null + populated paths, 2 tests), `invite` create/idempotent/feature-gate (3 tests, of which the feature-gated ones skip when the active edition lacks csat — matches the project-wide dev-override skip pattern from `page_gate_test`). Smoke validated against the real `view.php?id=4` rendering path.
- **Notifications: hand `message_send()` a fully-loaded recipient record.** `\local_moodesk\observer::send_message()` (the private wrapper every notification path uses) now re-fetches the recipient via `\core_user::get_user($id, '*', IGNORE_MISSING)` before building the `\core\message\message`, and short-circuits on deleted/suspended users. Previously every callsite — `ticket_created` broadcast, `ticket_replied`, `ticket_assigned`, and the two `ticket_sla_breached` branches — handed `message_send()` a partial record (only the columns each upstream SELECT happened to need for body composition), so Moodle 4.5 logged _"Necessary properties missing in userto object, fetching full record"_ at `DEBUG_DEVELOPER` on every notification fired in dev environments. Functionally Moodle had been masking the bug with its own internal re-fetch; this change silences the warning and removes the assumption that upstream queries must know which columns `message_send()` cares about (`mailformat`, `maildisplay`, `lang`, `timezone`, `auth`, `emailstop`, `mnethostid`, `deleted`, `suspended`, ...). New regression test in `tests/observer_test.php` drives `ticket_created` + `ticket_replied` + `ticket_assigned` end-to-end with all four `notify_*` configs enabled, redirects the messages through `redirectMessages()`, and asserts the missing-properties warning never appears in `getDebuggingMessages()`. Companion to the earlier `fullname()` hardening — the previous fix covered name-field warnings at render time, this one covers transport-routing warnings at dispatch time.
- **Tickets list: saved views never appeared after saving.** `ajax.php::save_filter_view` writes user prefs under `local_mhd_savedview_<slug>` (slug derived from the display name) but `index.php` was reading a different scheme — `mhd_filter_view_1..10`, a numbered-slot prefix that nothing in the plugin ever writes — so the saved-views list was always empty and the inline ✕ delete button had no `key` to send to `delete_filter_view`. `index.php` now scans `get_user_preferences()` for the `local_mhd_savedview_` prefix used by both save and delete handlers, decodes each entry, and emits the per-row `key` the template expects. No version bump (pure runtime read-path change, no schema/capability/cache impact).
- **`departments.php`: remove dead edition gate.** The page guarded itself with `if (!edition_service::has_feature('multi_department')) { upgrade_prompt; exit; }`, but `multi_department` is mapped to `EDITION_STARTER` in `edition_service::FEATURES` — the condition never triggered. Removed the dead block. Documentation in `docs/etapa2-hardening-audit.md` records why a future re-tier of `multi_department` would gate at the create flow rather than the list page.
- **Compat banner: migrate to Moodle 4.4+ hook API.** The compat-mode banner was registered as the legacy callback `local_moodesk_before_standard_top_of_body_html` in `lib.php`. On Moodle 4.5 with developer debugging on, the legacy callback throws a `Whoops\Exception\ErrorException` ("Callback before_standard_top_of_body_html should be migrated to new hook callback for `core\hook\output\before_standard_top_of_body_html_generation`") and the banner never reaches the page; in production the same path emits a `debugging()` warning on every render. Moved the rendering logic into `\local_moodesk\hook_callbacks::before_standard_top_of_body_html_generation()` and registered the hook in `db/hooks.php`. The legacy function in `lib.php` is removed. Behaviour is unchanged on Moodle 4.4+; on Moodle 4.1–4.2 (no hook system) the banner is silently skipped, matching the existing primary-navigation hook policy. Bumps `version.php` to `2026051001` to force the hook cache rebuild.
- **`license_service_test::test_compat_get_edition_returns_null_when_legacy_config_unset`**: the test was failing because `settings.php` registers `local_moodesk/edition` with default `EDITION_STARTER`, so `mdl_config_plugins` already has the row populated when each PHPUnit test starts. Added an explicit `unset_config('edition', 'local_moodesk')` so the test really exercises the "legacy config unset" branch of the resolution-order contract.

### Added
- **License compatibility mode (Fase 1.6 / Etapa 5.1).** New `license_service::COMPATIBILITY_MODE` class constant (true in 1.2.x, flipped to false in 1.3.0 — Etapa 5.4). While true, `license_service::get_edition()` falls back to the legacy `local_moodesk/edition` config row when no licence JWT is installed, so beta clients that pre-date the JWT system keep their tier across the migration. The fallback is observable: a once-per-request `debugging()` warning fires the first time it resolves an edition, and a top-of-body banner appears on every admin page (rendered via the `\core\hook\output\before_standard_top_of_body_html_generation` hook in `\local_moodesk\hook_callbacks`) until the admin pastes the JWT on the License status page. Compatibility mode is bypassed as soon as `license_service::is_installed()` returns true — even a malformed JWT routes the admin to the License status diagnostic instead of silently being served by the legacy config. Resolution-order contract: verified JWT tier > legacy `edition` config (compat only) > null (caller floor). New public surface: `license_service::is_installed(): bool`, `license_service::get_edition(): ?string`. Coverage in `tests/service/license_service_test.php` (8 new tests) and `tests/service/edition_service_test.php` (1 updated test) exercises both branches conditionally on the flag value, so the same suite stays green when 1.3.0 flips the constant.
- **License key system (Fase 1.1).** New `local_moodesk\service\license_service` validates a JWT RS256 licence configured via the new `local_moodesk/license_key` admin setting and caches the verified claims for 1 day in MUC (`license_state`). Validation enforces structural integrity, RS256-only header (rejects `alg=none` and HS256 confusion attacks), required claims (`sub`, `tier`, `iat`, `exp`), tier whitelist, signature verification before any non-structural claim, expiry / issued-at with 60s clock-skew tolerance, and a manually maintained revocation list.
- **License status admin page** at `Site administration → Plugins → Local plugins → License status` (`/local/moodesk/license.php`). Shows the resolved status (valid / missing / malformed / forged / expired / revoked / nopubkey) with Bootstrap colour coding; for valid licences renders the claim table (effective edition, tier, client_id, max_agents, issued_at, expires_at with relative "expires in X" hint, wwwroot binding with mismatch badge, and SHA256 fingerprint of the embedded public key).
- **Operator tooling for licence issuance** in `dev_scripts/generate-license-keypair.sh` (RS2048 keypair generator that refuses to overwrite without `--force` and prints a DER-SHA256 fingerprint) and `docs/license-issuance.md` (manual runbook with `openssl` + `jq` signing one-liner, decode/verify checks, and revocation procedure). Manual issuance is the chosen path until customer N=6 (decision-history 2026-05-08).
- **MUC cache definition** `local_moodesk/db/caches.php` (`license_state`: application mode, simple keys, TTL 1 day, static acceleration size 1).
- **PHPUnit coverage** in `tests/service/license_service_test.php`: 29 tests / 42 assertions covering structural validation, algorithm-confusion rejection, claim requirements, signature forgery, expiry handling, clock-skew tolerance, status enum surface, diagnostic payload, cache hits and natural invalidation on JWT change, and the `edition_service::get_edition()` integration path. Six integration tests skip cleanly when `moodesk_LOCAL_EDITION` is defined.

### Changed
- **`edition_service::get_edition()` resolution order — security contract change.** The plugin config `edition` selector is no longer trusted on its own; the new resolution order is dev override → verified licence JWT → `EDITION_STARTER` fallback. This closes the bypass where any Moodle administrator could promote the install to Pro/Enterprise by editing `mdl_config_plugins`. The selector remains visible in admin settings to document intent for installs without a licence.
- **`tests/service/edition_service_test.php`** updated to match the new contract: tests of the OLD config-driven path are replaced with `test_get_edition_ignores_config_value_without_license`, and tests that depend on `get_edition()`'s resolution skip when `moodesk_LOCAL_EDITION` is defined.
- **Public key embedded** at `local_moodesk/license_public_key.pem` (RS2048, fingerprint `132720d3…ae5876`). The matching private key never enters the repository or any server — it lives only in the operator's vault.

---

## [1.1.3] — 2026-05-08

### Fixed
- `db/install.php`: hardened post-install seeding so a fresh install reaches a usable state on every supported Moodle 4.1+ build. Re-runs are now idempotent (the seeded "General" category, default department, and `mhd_agent` role are each re-checked before insertion) and the missing-config recovery path repopulates `defaultcategoryid` / `agentroleid` if they were cleared manually. Resolves the install regression introduced together with the Phase 11 capability set.
- `classes/observer.php`: webhook callbacks now bail out via `class_exists(webhook_service::class)` before invoking `webhook_service::create()->dispatch(...)`. The Pro `webhook_service` class is still pending implementation; until it lands, the observer was throwing inside the `try`/`catch` and emitting a `debugging()` line on every dispatched event in any Pro install. The new guard short-circuits cleanly — no log spam, no behaviour change for Starter, and no risk of partial dispatch when the class finally ships.
- `lang/en` and `lang/es`: refreshed the notification strings used by `ticket_created`, `ticket_replied`, `ticket_assigned` and `ticket_sla_breached`. The English copy was tightened first; the Spanish file was then re-aligned key-by-key so EN/ES diff is empty again (621 keys each).

### Changed
- `CLAUDE.md`: slimmed the repo-local instructions to only describe the physical layout of this checkout (what ships vs. what does not) and the gitignore reminders. All strategic/architectural rules now live in the digitalMood skills system under `$DIGITALMOOD_SYSTEM_ROOT/.claude/products/moodesk/` and `$DIGITALMOOD_SYSTEM_ROOT/.claude/knowledge/moodesk/`. The product-skills integration document (`docs/skills-integration.md`) explains how those files are loaded.
- `classes/service/edition_service.php`: realigned `webhooks` from `EDITION_PRO` to `EDITION_ENTERPRISE` to match the canonical decision recorded in `products/moodesk/decision-history.md` (2026-04-11) and the product profile / edition-system knowledge. No runtime impact under the current dev override (`moodesk_LOCAL_EDITION`); webhooks will gate at Enterprise tier once `license_service` ships. The previous Pro listing was a transitional value that contradicted the documented edition matrix.

### Added
- `tests/db/install_test.php` and `tests/db/upgrade_test.php`: first regression coverage for the install and upgrade routines (`xmldb_local_moodesk_install`, `xmldb_local_moodesk_upgrade`). Backed by `advanced_testcase` so they run against the real Moodle test DB.

---

## [Pre-1.2.0 unreleased entries — superseded]

These entries were captured under `[Unreleased]` before the 1.2.0 cut and are now incorporated into the 1.2.0 release notes above. Kept here only as a paper trail of the original wording.

### Fixed
- Ticket list pagination now preserves every active filter (search, status, priority, dates, sort, assignee, team, category, SLA). The paging bar previously rebuilt its URL from a hand-picked subset (`view`, `sla` only), silently dropping the rest when the user navigated to page 2+.
- Daterange filter form now reaches the backend. Inputs were named `createdfrom`/`createdto`/`updatedfrom`/`updatedto` while `index.php` read `optional_param('created_from', …)` etc., so the form submit had never populated the date filter — only hand-written URLs activated it. Renamed the inputs and updated the saved-views allowlist in `ajax.php` to match.
- Daterange trigger button now renders a real label. The mustache template referenced `{{#str}} filter_daterange_trigger {{/str}}` but the lang key was undefined, so Moodle rendered `[[filter_daterange_trigger]]`. Added the key in `en` (`Date range`) and `es` (`Rango de fechas`).

### Added
- Client-side validation that blocks inverted date ranges (`from > to`) on the ticket list filter, using the native HTML5 `setCustomValidity` API plus a Bootstrap `is-invalid` style and a localized message (`filter_date_range_invalid`).

### Changed
- Ticket detail sidebar now stacks below the thread on tablets (`< 992px`) instead of squeezing into a 33%-wide column. `col-md-8` / `col-md-4` switched to `col-12 col-lg-8` / `col-12 col-lg-4`.
- Daterange filter panel collapses to viewport width with `max-height: 60vh` and vertical scroll on phones, instead of overflowing horizontally because of its previous 340px hard minimum.
- Quick action buttons in the ticket list (`.mhd-quick-btn`, `.mhd-quick-status-select`) now have larger padding so the desktop hover row is comfortably clickable.
- Filter toolbar mobile alignment: `.mhd-filter-item` (used by the daterange trigger) now collapses to 50% / 100% width in step with the search and select fields, and the dropdown trigger stretches with `justify-content: space-between` so the caret sits cleanly at the right edge.

---

## [1.1.2] — 2026-04-11

### Fixed
- `edition_service`: restored `EDITION_PRO` and `EDITION_ENTERPRISE` constants and their entries in `EDITION_ORDER` and the upgrade-prompt label map. They were trimmed in 1.1.0 to scope the codebase to Starter, but `edition_service::get_upgrade_prompt_ctx()` still referenced `self::EDITION_PRO` as a fallback for unknown features — invoking the prompt would have fatalled until the constants were back. End-user behaviour is unchanged.

---

## [1.1.1] — 2026-04-11

### Fixed
- `db/install.php` now calls `update_capabilities('local_moodesk')` before seeding role assignments. On fresh installs Moodle runs `install.php` before syncing capabilities defined in `access.php`, so `assign_capability()` could not find the plugin's capabilities and the installer aborted. Forcing the sync up-front makes the install routine idempotent on clean sites.

---

## [1.1.0] — 2026-04-11

First public **Starter** release. The pre-1.0 work tracked under the 1.0.0-beta entries below is now packaged and shipped as the Starter edition. Pro and Enterprise editions remain on the roadmap.

> **Note (reconciled 2026-05-08):** the original 1.1.0 entry described `edition_service::FEATURES` as "reduced to `sla` only". That snapshot of the gate map was reverted before 1.1.2: the file in `main` carries 12 feature entries (Starter: `sla`, `multi_department`, `categories`; Pro: `teams`, `csat`, `custom_fields`, `automation`, `webhooks`, `email_ingestion`, `advanced_reports`, `sla_per_department`; Enterprise: `api_access`). The 1.1.x line keeps the Pro/Enterprise gates active in code so Pro UI and observers can compile and run under a dev override (`moodesk_LOCAL_EDITION = 'pro'`); the **commercialised SKU is still Starter only**, gated at runtime by `edition_service::get_edition()` falling back to Starter when no `edition` config or license is present. Pro/Enterprise will become commercially available once `license_service` and the missing services (`webhook_service`, `automation_service::process_event_trigger`, `email_ingestion_service`) ship.

### Changed
- Edition naming: the base edition is **Starter** (previously referred to internally as `mvp`). `edition_service::EDITION_STARTER = 'starter'`.
- `edition_service::FEATURES` initially trimmed to `sla` only at the 1.1.0 cut, then re-expanded to the 12-entry map described above before 1.1.2 to keep Pro/Enterprise services reachable under the dev override. End-user behaviour on Starter is unchanged: any feature not in the Starter tier resolves to `false` via `has_feature()`.
- `edition_service::get_edition()` resolves in this order: dev-only `moodesk_LOCAL_EDITION` constant → `get_config('local_moodesk', 'edition')` → fallback to Starter. License-key validation is still deferred to a future phase (tracked in `roadmap-status.md` Bloque 3).

### Added
- `edition_service::require_feature(string $feature)` — throws `moodle_exception` when the active edition does not include the feature. Intended as a guard at the top of any future Pro/Enterprise service method.
- `edition_service::has_feature(string $feature)` — non-throwing variant for conditional UI rendering.
- `edition_service::get_upgrade_prompt_ctx(string $feature)` — returns the Mustache context for `edition_upgrade_prompt.mustache`, ready for `$OUTPUT->render_from_template()`.
- Development-only edition override: define `moodesk_LOCAL_EDITION` in a gitignored `local_config.php` (see `local_config.php.example`) to switch edition locally without touching plugin settings. Production deployments are unaffected.

---

## [1.0.0-beta] — 2026-04-10

### Added — Phase 11.H: Documentation
- `README.md` — full technical installation and feature reference
- `DEVELOPER.md` — architecture guide, layer contracts, contribution guidelines
- `CHANGELOG.md` — structured release history
- PHPDoc audit pass across all public-facing PHP classes

---

## [1.0.0-beta] — 2026-04-10

### Added — Phase 11.F: Internationalization
- PHP string API: all 568 plugin strings extracted to `lang/en/local_moodesk.php`
- JavaScript string API: AMD module `local_moodesk/strings` for runtime JS string loading via `core/str`
- Spanish translation: `lang/es/local_moodesk.php` — full 568-string Spanish (es) translation
- All templates and JS modules updated to use i18n strings (no hardcoded English remaining)

---

## [1.0.0-beta] — 2026-04-09

### Added — Phase 11.G: Edition UI
- License & Edition settings page (`local_config.php`)
- Edition-gated feature flags via `edition_service`
- Upgrade prompts displayed on Pro/Enterprise-gated feature pages when running on Starter edition
- `edition_service::has_feature()` used as the single gate for all feature checks

---

## [1.0.0-beta] — 2026-04-09

### Added — Phase 11.E: Teams
- Agent teams as a cross-department grouping unit (`local_mhd_team`, `local_mhd_team_member` tables)
- Team management UI: list, create, edit, archive, member management
- Ticket-team assignment in the ticket detail view
- `VIEW_TEAM` tab in the agent workspace (tickets belonging to my teams)
- Capability: `local/moodesk:manageteam`
- `team_repository`, `team_service`, `team_form`

---

## [1.0.0-beta] — 2026-04-09

### Added — Phase 11.D: Customer Satisfaction (CSAT)
- CSAT survey system with token-based invite links (`local_mhd_csat` table)
- Configurable trigger: invite sent on ticket resolved or ticket closed
- Public survey response page (`csat.php`) — no login required, token-authenticated
- CSAT summary dashboard on reports page
- `csat_service::invite()` — idempotent, skips if rating already exists
- Observer hook: `csat_on_status_changed` fires after `ticket_status_changed`
- Capability gate: `edition_service::has_feature('csat')` (Pro+)

---

## [1.0.0-beta] — 2026-04-09

### Added — Phase 11.C: Custom Fields
- Admin-defined custom fields on tickets (`local_mhd_custom_field`, `local_mhd_custom_field_value` tables)
- Field types: text, textarea, select (dropdown), checkbox, date
- Custom fields rendered on the ticket submission form and ticket detail view
- `custom_field_service`, `custom_field_repository`, `custom_field_form`
- Admin UI: create, edit, reorder, delete custom fields (`custom_fields.php`, `custom_field_edit.php`)

---

## [1.0.0-beta] — 2026-04-09

### Added — Phase 11.B: Ticket Merge
- Merge duplicate tickets into a canonical (primary) ticket (`merge.php`)
- All replies from the source ticket are re-parented to the target
- Source ticket is closed with a system event recording the merge
- Capability: requires `local/moodesk:manage`

---

## [1.0.0-beta] — 2026-04-09

### Added — Phase 11.A: SLA Per Department
- Per-department SLA threshold overrides for response and resolution times, per priority level
- Eight new nullable columns on `local_mhd_department`: `sla_response_hours_{priority}`, `sla_resolve_hours_{priority}`
- `sla_service::get_thresholds()` resolves: department override → global config → disabled
- Department edit form updated with SLA override fields

---

## [0.10.0] — 2026-04-09

### Added — Phase 10: REST API & Integrations
- Moodle External Functions API: `list_tickets`, `get_ticket`, `create_ticket`, `reply_ticket`, `list_departments`, `list_agents`
- API token management UI (`api_tokens.php`)
- Outbound webhooks: subscribe to `ticket.created`, `ticket.updated`, `ticket.resolved`, `ticket.replied` events
- Webhook delivery via `dispatch_webhook` scheduled task with retry logic
- `webhook_service`, `webhook_repository`, `webhook_form`
- Capability: `local/moodesk:useapi`, `local/moodesk:managewebhooks`
- Webhooks UI: `webhooks.php`, `webhook_edit.php`

---

## [0.9.0] — 2026-04-09

### Added — Phase 9: Email Ingestion
- Inbound email-to-ticket pipeline via IMAP polling (`process_incoming_email` scheduled task)
- `email_ingestion_service`: parses raw email, matches sender to Moodle user, creates or replies to tickets
- Thread matching by In-Reply-To / References headers and ticket ID in subject
- `email_message_repository` — stores raw inbound email records for audit
- Plugin settings: IMAP host, port, username, password, mailbox, encryption, default department for ingested tickets

---

## [0.8.0] — 2026-04-09

### Added — Phase 8: Automation Rules Engine
- Condition-action rule engine (`local_mhd_rule`, `local_mhd_rule_log` tables)
- Trigger events: `ticket.created`, `ticket.status_changed`, `ticket.sla_breached`
- Conditions: status, priority, department, category, assignee (is/is_not operators)
- Actions: set status, set priority, assign agent, assign department
- Observer hooks: `rule_on_ticket_created`, `rule_on_status_changed`, `rule_on_sla_breached`
- Admin UI: automations list, create/edit (`automations.php`, `automation_edit.php`)
- Capability: `local/moodesk:manageautomations`
- `automation_service`, `rule_repository`

---

## [0.7.0] — 2026-04-09

### Added — Phase 7: Reports & Analytics
- Reports page (`reports.php`) with configurable date range and filter controls
- Metrics: tickets opened, tickets closed, average first response time, average resolution time
- Volume over time chart (tickets per day/week)
- Agent performance breakdown table
- CSAT summary (when feature is enabled)
- `report_service`, `report_repository`
- Capability: `local/moodesk:viewreports`

---

## [0.6.0] — 2026-04-09

### Added — Phase 6: Dashboard & Agent Workspace
- Agent dashboard (`dashboard.php`) with KPI summary cards
- Metrics: open tickets, tickets assigned to me, SLA breached, resolved today
- Agent workspace views: All / Mine / Unassigned / Department / Team tabs
- `dashboard_service`, `dashboard_repository`
- Bulk actions on ticket list: bulk assign, bulk change status, bulk close (`bulk_action.php`)

---

## [0.5.0] — 2026-04-09

### Added — Phase 5: SLA
- SLA thresholds by priority: first-response hours and resolution hours
- `sla_service` — stateless evaluation: `ok`, `warning`, `breach`, `none` states
- `check_sla_breaches` scheduled task — evaluates open tickets in batches of 500
- `autoclose_resolved_tickets` scheduled task — closes resolved tickets after N days
- `ticket_sla_breached` event
- `sla_breached` flag on `local_mhd_ticket` (TINYINT, 0/1)
- SLA indicators in ticket list and ticket detail views

---

## [0.4.0] — 2026-04-09

### Added — Phase 4: Categories
- Ticket categories (`local_mhd_category` table)
- Category management UI (`categories.php`, `category_edit.php`)
- Categories available on ticket submission form and ticket detail
- Default category configurable in plugin settings
- Capability: `local/moodesk:managecategory`
- `category_service`, `category_repository`

---

## [0.3.0] — 2026-04-08

### Added — Phase 3: Departments
- Departments as routing queues (`local_mhd_department`, `local_mhd_dept_member` tables)
- Department management UI (`departments.php`, `department_edit.php`, `department_members.php`)
- Capability: `local/moodesk:managedepartment`
- Agent workspace scoped by department membership
- Ticket accessibility rule: global (no dept) OR assigned to agent OR agent is dept member
- `department_service`, `department_repository`
- Four agent workspace views: All, Mine, Unassigned, Department (`department` domain class)

---

## [0.2.0] — 2026-04-07

### Added — Phase 2: Agent Model
- Agent capability model (`local/moodesk:agent`, `:viewall`, `:reply`, `:addnote`, `:manage`)
- Ticket assignment: assign/unassign agents on ticket detail view
- `ticket_assigned` event
- Internal notes (TYPE_INTERNAL_NOTE) — agent-only, never shown to requesters
- Ticket history / audit log (`local_mhd_ticket_history` table)
- System events inline in thread (TYPE_SYSTEM_EVENT)
- Status transitions enforced by state machine (`ticket::VALID_TRANSITIONS`)
- Moodle message notifications: new ticket → agents, reply → requester, assign → assignee

---

## [0.1.0] — 2026-04-07

### Added — Phase 1: Core Foundation
- Plugin scaffolding: `version.php`, `lib.php`, `settings.php`, `db/install.xml`
- Ticket submission form (`submit.php`, `classes/form/submit_ticket_form.php`)
- Ticket list for requesters (`index.php`)
- Ticket detail / thread view (`view.php`)
- Public reply form for requesters and agents (`classes/form/reply_form.php`)
- `local_mhd_ticket` and `local_mhd_reply` database tables
- Status: New, Open, Pending, Resolved, Closed, In Progress
- Priority: Low, Normal, High, Urgent
- `ticket_created`, `ticket_replied`, `ticket_status_changed` events
- Privacy API: `classes/privacy/provider.php`
- Layered architecture: domain → repository → service → form → page

---
