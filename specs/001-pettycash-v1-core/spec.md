# Feature Specification: PettyCash V1 Core Workflow

**Feature Branch**: `001-pettycash-v1-core`

**Created**: 2026-09-19

**Status**: Approved for implementation — under convergence. 205 of 276 ledger tasks complete; the
open CRITICAL items are T326 and T327, which between them leave a purchaser unable to read a sheet's
transactions or submit it.

**Input**: User description: "Read the existing business requirements and create feature
specifications from them. Do not implement anything. Only create specs."

## Clarifications

### Session 2026-09-22

- Q: What minimum audit retention period should FR-057 require for PettyCash V1? → A: 7 years
- Q: What measurable criteria should define "normal usability" in FR-073? → A: p95 latency <3s for routine views (SC-008), error rate <0.1%, zero data loss
- Q: What measurable criteria should define "clearly show" in FR-071? → A: all elements visible without horizontal scroll at 390px viewport, WCAG AA contrast, ≥12pt Persian font
- Q: How should authorization decision matrix resolve conflicts for multi-role users? → A: explicit allow when user holds required review role + scope + stage permission; ownership grants purchaser rights only
- Q: What does "advisory decision" mean for cost center manager decisions in FR-034? → A: visible to senior manager with full details; senior may decide independently without forced acknowledgment
- Q: What audit scope rules apply to configuration audit events (FR-056) for FR-055? → A: admins see all; finance see accounts they charge; managers see their cost centers
- Q: What specific evidence restrictions constitute "advanced evidence-specific restrictions" excluded from V1 in FR-030? → A: V1 permits all authorized viewers to see all evidence attached to accessible transactions; advanced restrictions (document classification, redaction, watermarking, extended access logging, time-limited access, evidence-type permissions) deferred to V2
- Q: What maximum file size limit should V1 enforce for evidence uploads per FR-074? → A: 100 MB per file
- Q: What minimum browser versions should "current Chrome, Edge, and Firefox" resolve to for FR-072? → A: Chrome 120+, Edge 120+, Firefox 115+ (ESR)
- Q: How should "separately show approved totals" (FR-062) be presented in UI? → A: separate columns (Cost Center Approved | Senior Approved | Finance Approved) alongside latest effective outcome column
- Q: What happens when purchaser has zero active accounts in FR-020? → A: default active rial account auto-created on first sheet creation attempt (idempotent provisioning)
- Q: What TTL/expiry and access logging should public evidence links (FR-031) enforce? → A: configurable TTL (default 30 days, max 1 year) + per-access audit logging (viewer UID, timestamp, IP) in configuration audit
- Q: What constitutes a "reporting period" for group exports in FR-068? → A: predefined calendar periods (month, quarter, year) plus custom date range

### Session 2026-09-25

- Q: Which database backends should PettyCash V1 formally declare as supported for application use? → A: MySQL 8.0/8.4, MariaDB 10.6/10.11/11.4/11.8, and PostgreSQL 14/15/16/17/18, verified from the Nextcloud 34 Administration Manual system requirements; MariaDB 11.4 remains the local development backend only; Oracle and SQLite are outside V1 scope as non-recommended for application use. **Superseded later in this session:** the second answer below adds `sqlite` to the declared set for compatibility verification. The final declared set is `mysql`, `pgsql`, and `sqlite`.
- Q: What evidence should satisfy the cross-database compatibility requirement during V1 acceptance, given the MariaDB development environment must stay unchanged? → A: provision ephemeral CI database backends for every declared database family and run forward migrations/schema creation, persistence/repository integration tests, representative transaction/workflow queries, and migration/schema compatibility checks on each; additionally run static portability checks on every build that reject vendor-specific SQL and schema assumptions; keep the existing local MariaDB 11.4 Docker environment unmodified, with additional databases as CI-only ephemeral test services. The answer also fixes the declared set to the three Nextcloud types `mysql`, `pgsql`, and `sqlite`, and requires that because `mysql` covers two concrete engines CI must run one MySQL job and one separate MariaDB job rather than assuming MariaDB proves MySQL compatibility, with Oracle/`oci` outside V1 scope unless explicitly added later as an Enterprise compatibility target
- Q: Should PettyCash adopt Oracle's strictest identifier limits as a blanket rule, even though Oracle is explicitly outside V1 scope? → A: no; keep the schema within the limits required by the declared V1 targets (mysql/MariaDB, PostgreSQL, SQLite), do not rename existing tables or add migrations for hypothetical future Oracle support, and do not treat Oracle-specific identifier or schema limits as current V1 requirements; Oracle/Enterprise compatibility is recorded in `plan.md` as a deferred architecture constraint that must first be re-verified against the targeted Nextcloud and Oracle versions and given an explicit compatibility and migration plan before support is added
- Q: The repository already has four migration files that create `pc_*` tables and about nineteen repositories that reference those names; since V1 is unshipped and its data is disposable, how should those migrations be converted to an app-scoped `pettycash_*` namespace? → A: edit Version0100–0103 and every repository, test, fixture, and schema assertion in place, so the clean V1 baseline creates the logical `pettycash_*` namespace from the start; do not add a rename migration and do not keep a mixed namespace; because these migrations have already run in the shared development instance, follow the repository change with an approval-gated development-only reset of PettyCash state so the rewritten baseline executes from scratch, affecting PettyCash only, preserving all unrelated apps and data, and never deleting Docker volumes or the shared Nextcloud database; current `pc_*` development data is disposable by design and MUST NOT be preserved
- Q: Nextcloud records applied migrations per app ID and the discarded prototype used the same `pettycash` app ID, so residual migration-version rows could make Nextcloud skip the rewritten `pettycash_*` baseline migrations; how should the artifacts handle that? → A: replace the legacy framing with an explicit development-environment precondition that clearing the `pettycash` app-ID migration state is part of the development reset, so the rewritten baseline always executes; treat it as a development-environment step only, not a production or data concern
- Q: What should the artifacts say about residual `oc_pcash_*` prototype tables that remain in the shared development database? → A: leave them untouched and record them as unmanaged artifacts; V1 MUST NOT read, write, migrate, import, rename, or drop them, and their presence is not a V1 defect or a trigger for any cleanup action
- Q: Index and constraint names are global per database schema, so which app-scoped naming form should the V1 schema use? → A: `pettycash_<table>_<subject>_<kind>` where kind is `idx`, `uniq`, `fk`, or `chk` (for example `pettycash_transactions_status_idx`, `pettycash_transaction_revisions_number_uniq`); the old generic `pc_` prefix MUST NOT be used for indexes or constraints, and identifiers MUST stay comfortably below the PostgreSQL 63-byte and MySQL 64-character limits, abbreviating only when a full descriptive name would exceed the portable limit

### Session 2026-09-28

- Q: Should petty-cash balances and movements be their own report types, or data within the existing `accounts` report? (FR-065) → A: Data within the existing `accounts` report; the report-type enum is not extended.
- Q: Should FR-064 keep all twelve filtering facets even though five of them cannot currently be expressed, or should the requirement be narrowed to what exists? (FR-064) → A: Keep all twelve; they are V1 requirements, not deferred, and each facet's source is now stated.
- Q: Where should the list of accounts a finance officer has charged come from, since nothing currently records it? (FR-055) → A: Derived from the officer's own configuration audit events for account subjects, written by T294; no migration and no coupling to the command log.
- Q: Which column names should the configuration audit subject use, given the spec's data model and the shipped migration disagree? (T253, FR-056) → A: Keep the migration's `entity_type`/`entity_id` and correct `data-model.md`; the domain entity already uses those names, so the document is the outlier.
- Q: Which configuration audit events count as a finance officer charging an account, given that no event vocabulary exists for configuration audit? (FR-055) → A: Only account funding and balance-charging events, by a named event type; a rename, activation or deactivation does not grant visibility into that account's configuration history.
- Q: Whose accounts and balances may the `accounts` report show for each role? (FR-065, FR-055, FR-015) → A: A user sees their own accounts; a finance officer additionally sees every account they have charged; an administrator sees all.
- Q: When a finance approval consumes an account's balance, which audit stream records that the officer charged that account? (FR-055, T294) → A: Settlement also writes a configuration audit event naming the charged account, so a charge appears in both streams and the derivation is not left empty.
- Q: What counts as a view being "usable" within three seconds, so that SC-008 can actually be measured? (SC-008) → A: The view finishes loading and shows the rows the user is entitled to see, with no error state, measured end to end as a user sees it.
- Q: Should SC-008's three-second usability target be verified during V1, or is that verification deferred to a later milestone? (SC-008) → A: Verified during V1, not deferred; the measurement is performed and its result recorded, so the criterion is reported as met or not met rather than assumed.

### Session 2026-09-30

- Q: Both requirements about exporting selected records are numbered FR-068, so which number should the requirement about refusing unsatisfiable selections get? (FR-068) → A: Renumber it to FR-082. Both rules stay separately traceable, and the group-export coverage rule keeps FR-068 because every existing cross-reference uses FR-068 for it.
- Q: When a user opens an evidence file, should the app try to render it in the browser or only ever download it? (FR-060) → A: Render inline-safe media types in the browser -- images, PDF, plain text -- using the file's stored media type with an `inline` disposition; send every other media type as `application/octet-stream` with an `attachment` disposition, never rendered inline. The contract is amended to match, not the requirement.
- Q: When one stored record is corrupt or incomplete, what should a list or dashboard request return to the user? (FR-083) → A: `422` with an explanatory message on sheets, sheet detail and dashboard too, matching reports, with `500` reserved for genuinely unexpected faults and no unreadable record silently omitted or defaulted.
- Q: Is producing a PDF export part of V1, or is it deferred to a later release? (FR-066) → A: Part of V1. An OFL-licensed Persian font is vendored into the repository under an app-owned path with its licence text, so `pdf` is supported unconditionally and never depends on a host- or container-installed font.
- Q: Does SC-010's moderated usability test have to be run before V1 can be released? (SC-010) → A: It is measured and its result recorded, but it does not block release. When representative users or a facilitator are unavailable it is recorded explicitly as not measured, with the reason, and is never reported as met by default.
- Q: When someone picks the "month" or "quarter" export preset, which calendar defines that period's boundaries? (FR-068) → A: Jalali (Solar Hijri), matching the calendar every date in the interface is already rendered in. A period spans Farvardin to Esfand and a quarter begins at Farvardin; it resolves to a Gregorian from/to pair for querying without altering any stored expense date, and is never identified by a Gregorian year number.
- Q: When someone picks the "month" export preset, which Jalali month does it select? (FR-068) → A: The current Jalali period, running from its first day through its last, not the previous completed period and not a trailing window. Because a period in progress is incomplete, every response that resolved a predefined period states the exact date range it resolved; a previous or trailing period is requested with the explicit custom date range instead.
- Q: Which time zone decides where a Jalali month begins and ends? (FR-068) → A: The time zone of the account making the request, from that user's own account settings, falling back to the instance's configured default when the account states none; no zone is hardcoded. The resolved range is therefore relative to the requesting user, and the response states both the range and the zone it was resolved in.
- Q: When a period's boundary lands on a day, does that day count as inside the period or outside it? (FR-068) → A: Inclusive at both ends. A period covers every expense date from its first day through its last day, so a predefined period and the equivalent explicit date range select exactly the same records. A Jalali year is 365 or 366 days and is never treated as a fixed length.
- Q: If a request supplies both a predefined period and an explicit date range, which one decides the records? (FR-068) → A: They are mutually exclusive within one request. Supplying both is refused with a `422` naming the conflict and stating which of the two to remove; the application never honours one while discarding the other, and never intersects them.
- Q: When someone filters by a period such as "this month", is that a filter in its own right or simply another way of expressing a date range? (FR-064) → A: A filter in its own right. It joins FR-064's accepted list explicitly, scoped to the export and report endpoints that already accept a date range, and because FR-068 makes the two mutually exclusive it is refused alongside a date range rather than merged into or silently narrowing one.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Capture and Submit Expenses (Priority: P1)

As a purchaser, I can create one or more expense sheets, record expense transactions and their
evidence, and submit a complete sheet for review so that my expenses enter a controlled approval
process.

**Why this priority**: Expense capture and submission create the business records used by every
later workflow stage.

**Independent Test**: Create an open sheet, add transactions and evidence, resolve all submission
validation errors, and submit it. The submitted transactions become available for the first review
stage while the purchaser retains a clear view of their status.

**Acceptance Scenarios**:

1. **Given** a purchaser has only the default active petty-cash account, **When** the purchaser
   creates a sheet, **Then** the sheet uses that account's currency and the purchaser cannot select
   another currency.
2. **Given** a purchaser has multiple active petty-cash accounts in different currencies, **When**
   the purchaser creates a sheet, **Then** only those account currencies are available and the
   selected currency applies to every transaction in the sheet.
3. **Given** an open sheet, **When** the purchaser adds a transaction with an expense date and a
   positive amount, **Then** the transaction can be saved as a draft and completed later.
4. **Given** a sheet has no transactions or any transaction lacks its required cost center,
   expense type, description, or evidence, **When** the purchaser submits the sheet, **Then** the
   submission is rejected with specific validation messages.
5. **Given** every transaction is complete, **When** the purchaser submits the sheet, **Then** the
   sheet is locked against new transactions and its transactions enter formal review even when the
   source petty-cash account has insufficient or negative balance.

---

### User Story 2 - Perform Management Review (Priority: P1)

As a cost center manager or senior manager, I can review only the transactions within my assigned
responsibility, inspect their evidence and history, and approve, reject, or return each transaction
for correction.

**Why this priority**: Management review is the primary business-control path between submission
and financial settlement.

**Independent Test**: Submit transactions for two cost centers, sign in as each assigned manager,
and verify that each manager sees and acts only on the transactions in their scope. Complete both
management stages and verify the recorded decisions and next-stage routing.

**Acceptance Scenarios**:

1. **Given** a transaction is awaiting cost center review, **When** the assigned manager opens it,
   **Then** the manager sees its current details, evidence, history, and permitted decisions.
2. **Given** a cost center manager approves a transaction, **When** the decision is recorded,
   **Then** the transaction becomes eligible for senior-manager review and the decision remains in
   its history.
3. **Given** a manager rejects or returns a transaction without an explanation, **When** the
   manager attempts to submit the decision, **Then** the decision is rejected until an explanation
   is supplied.
4. **Given** the senior manager receives a transaction, **When** the senior manager decides,
   **Then** the decision may differ from the cost center manager's opinion without altering the
   earlier opinion.
5. **Given** all transactions in a sheet have been determined at a management stage, **When** the
   final decision at that stage is recorded, **Then** the sheet and its transactions advance to the
   next applicable stage.

---

### User Story 3 - Complete Financial Review and Settlement (Priority: P1)

As a finance officer, I can review transactions that have completed management review, record the
permitted financial decision, and settle approved expenses against the matching petty-cash account.

**Why this priority**: Financial review completes the workflow and is the only event that converts
an approved expense into actual petty-cash consumption.

**Independent Test**: Route one management-approved and one management-rejected transaction to
finance. Approve the eligible transaction, reject the ineligible transaction, and verify the
resulting balances, movements, final states, and preserved decision history.

**Acceptance Scenarios**:

1. **Given** a transaction has not reached finance, **When** a finance officer views the finance
   work queue, **Then** that transaction is not visible or actionable there.
2. **Given** a senior manager approved a transaction, **When** finance approves it, **Then** one
   consumption movement is recorded against the account matching the sheet currency and the
   account balance decreases by the approved amount.
3. **Given** a senior manager rejected a transaction, **When** finance reviews it, **Then** finance
   may reject it or return it for correction but cannot approve it.
4. **Given** any part of final approval, consumption recording, or balance update fails, **When**
   the operation ends, **Then** none of those effects is finalized and the transaction remains in
   its prior valid state.
5. **Given** every transaction has a final financial outcome, **When** the sheet is finalized,
   **Then** the sheet becomes completed or completed-with-rejections and permits only viewing and
   reporting.

---

### User Story 4 - Correct a Returned Transaction (Priority: P1)

As a purchaser, I can correct a returned transaction without erasing its prior version or decisions
so that the corrected expense can be reviewed again with a complete history.

**Why this priority**: Correction is part of the normal approval workflow and is essential to the
project's audit and historical-integrity rules.

**Independent Test**: Return a submitted transaction, edit it as the purchaser, resubmit it, and
verify that a new active revision enters the first management stage while the prior revision and
all earlier decisions remain viewable.

**Acceptance Scenarios**:

1. **Given** a manager or finance officer returns a transaction with an explanation, **When** the
   purchaser views their work, **Then** the transaction appears as awaiting purchaser correction.
2. **Given** a returned transaction, **When** the purchaser changes its business information and
   resubmits it, **Then** a new revision becomes active and review restarts with the cost center
   manager.
3. **Given** a new revision exists, **When** an authorized user views history, **Then** every prior
   revision, decision, actor, timestamp, and recorded change remains available.

---

### User Story 5 - Administer Business Configuration (Priority: P2)

As a system administrator or authorized finance user, I can maintain the reference data, role
assignments, currencies, petty-cash accounts, and account funding needed to operate the workflow.

**Why this priority**: Operational setup is required before real users can route expenses and
settle them correctly, but test fixtures can support the core workflow independently.

**Independent Test**: Configure currencies, cost centers, managers, expense types, user accounts,
and an account charge; then verify that the resulting choices, permissions, and account balance are
available to the appropriate users.

**Acceptance Scenarios**:

1. **Given** an administrator creates or changes a cost center, **When** managers and validity dates
   are assigned, **Then** those assignments govern future transaction routing without changing
   historical actor records.
2. **Given** an administrator activates or deactivates an expense type or currency, **When** users
   create new records, **Then** only currently valid choices are offered while historical records
   retain their original values.
3. **Given** finance charges a user's currency-specific petty-cash account, **When** the charge is
   confirmed, **Then** a movement is recorded and the account balance increases by that amount.
4. **Given** a sensitive configuration or access assignment changes, **When** the change is saved,
   **Then** the actor, time, explanation, previous value, and new value are recorded in the
   configuration audit history.

---

### User Story 6 - Trace Decisions and Evidence (Priority: P2)

As an authorized user, I can inspect the complete business history, transaction revisions, and
supporting evidence within my access scope so that decisions are explainable and auditable.

**Why this priority**: Traceability protects financial integrity and supports review, dispute
resolution, and organizational accountability.

**Independent Test**: Execute submission, approval, return, correction, evidence deactivation, and
final decision events; then verify their ordered history, before-and-after values, actor context,
revision links, and access restrictions.

**Acceptance Scenarios**:

1. **Given** a sheet is submitted, **When** an authorized user views a transaction timeline or
   history table, **Then** submission is the first formal event and every later official event is
   shown in chronological order.
2. **Given** a submitted transaction changes through correction, **When** history is viewed,
   **Then** the previous and new values and the associated revision are visible.
3. **Given** evidence is removed after submission, **When** authorized users view the transaction,
   **Then** the evidence is marked inactive rather than physically removed and its history remains
   visible.
4. **Given** a historical actor is later disabled or removed from the host user directory, **When**
   history is viewed, **Then** the actor identifier, display name, role at the event, and actions
   remain unchanged.
5. **Given** any user including an administrator attempts to alter or delete a business audit
   record, **When** the action is evaluated, **Then** it is refused.

---

### User Story 7 - Monitor, Search, Report, and Export (Priority: P2)

As a purchaser, manager, finance officer, or administrator, I can see role-relevant work and
status, find records, review reports, and export permitted information for operational follow-up
and analysis.

**Why this priority**: Visibility and reporting make the workflow manageable after core capture and
decisions are available.

**Independent Test**: Populate sheets across users, cost centers, statuses, dates, currencies, and
expense types; verify each role's dashboard, searches, reports, and exports contain exactly the
records and fields allowed for that role.

**Acceptance Scenarios**:

1. **Given** a user has one or more active roles, **When** the dashboard opens, **Then** it presents
   the queues, returned work, balances, counts, and summaries relevant to all of those roles.
2. **Given** an authorized user applies supported sheet, transaction, or audit filters, **When**
   results are returned, **Then** only matching records within the user's access scope are shown.
3. **Given** a user requests a sheet, transaction, report, audit, or evidence export, **When** the
   export is generated, **Then** it contains only information the user may view and preserves links
   among sheets, transactions, revisions, decisions, audit events, and evidence.
4. **Given** a group of records is selected, **When** an export is requested with a supported
   `fileFormat`, **Then** the system produces a complete grouped export in that format, and refuses a
   format it cannot yet produce with an explanation naming the reason.
   The supported `fileFormat` values are `csv`, `xlsx`, `zip` and `pdf`. All four are supported in V1,
   including `pdf`, because an OFL-licensed Persian font is vendored into the repository under an
   app-owned path with its licence text, so a Persian PDF renders identically in every deployment
   rather than depending on a machine-specific font location. A `fileFormat` outside these four is
   still refused with a reason naming what is supported.
   The field is named `fileFormat` and not `format` because the response-envelope format is selected by
   a request parameter of that name, so a request field called `format` would silently change the shape
   of the response instead of describing the export.

---

### User Story 8 - Use the Workflow Across Supported Devices (Priority: P3)

As any user, I can perform my permitted primary tasks through a clear Persian right-to-left
interface on desktop or mobile-sized screens.

**Why this priority**: Usability and device coverage are release requirements, while the underlying
business workflow can be validated independently first.

**Independent Test**: Run the affected role journeys in each supported browser at desktop and
mobile viewport sizes and verify readable RTL presentation, visible current status, and completion
of all primary workflow actions.

**Acceptance Scenarios**:

1. **Given** a user opens the application on a supported desktop or mobile browser, **When** a core
   page is displayed, **Then** content is readable in Persian RTL and adapts without blocking the
   primary task.
2. **Given** an action is not permitted for the current role, scope, stage, or record state,
   **When** the page is displayed, **Then** the action is not offered and cannot be completed by a
   direct request.
3. **Given** a communication failure occurs during an action, **When** the failure is detected,
   **Then** the user receives a clear error, no partial business operation is committed, and the
   user may retry manually.

### Edge Cases

- A purchaser may maintain multiple open sheets at the same time.
- A sheet may contain transactions assigned to different cost centers; each transaction follows
  the managers responsible for its own cost center.
- A sheet with no transactions, a transaction with a non-positive amount, or a transaction missing
  any submission field or required evidence cannot be submitted.
- A sheet can be submitted without available account balance, and an approved consumption may
  result in a negative balance.
- A manager's rejection is preserved even when a later authorized decision differs from it.
- A management-rejected transaction still reaches finance, but finance cannot approve it.
- A returned transaction is unresolved until corrected and must restart the full review path.
- Evidence may be added after submission, but removal after submission only deactivates it.
- Long-running work remains in its current stage; V1 performs no automatic reassignment,
  escalation, or status transition.
- A completed or completed-with-rejections sheet is locked against new transactions and edits.
- Discovering an error after final financial approval does not reopen or rewrite the original
  transaction in V1; handling such corrections is future scope.
- Disabling a user does not delete, reassign, or rewrite their historical records or decisions.
- If connectivity fails, the user must retry; V1 does not resume an interrupted operation
  automatically.
- A multi-role user may review their own transaction when the user independently has the required
  review role, responsibility scope, and workflow-stage permission.
- A query or schema change that runs correctly on MariaDB but depends on MariaDB/MySQL-specific SQL,
  functions, types, auto-increment assumptions, `GROUP BY` leniency, or delete-`LIMIT` behavior is a
  defect, not a passing result, and must be reworked so that it behaves identically on every declared
  V1 backend before it can satisfy FR-077.
- A schema element whose name, type, nullability, or default exceeds the limits required by the
  declared V1 backends (MySQL/MariaDB, PostgreSQL, SQLite) must be rejected at review time even when
  the target database accepts it, so that the schema does not become coupled to a single backend.
  Oracle-specific identifier and schema limits are not V1 requirements and MUST NOT be used to
  justify a rename or a migration of an existing table.
- Passing the full test suite against MariaDB alone does not demonstrate FR-077 compliance; the
  cross-database verification defined in the plan is the evidence of record.
- A statement that works on MariaDB and fails on any other declared V1 backend is a defect against
  FR-077, even though MariaDB is the development backend.
- Declaring MySQL/MariaDB compatibility as a single supported backend does not by itself prove
  MySQL compatibility, because MySQL and MariaDB are distinct engines that must each be verified
  independently.
- A migration that only runs because one backend tolerates a permissive default, an implicit
  `NOT NULL`, or an unenforced identifier rule is a defect against FR-077 even when it succeeds.
- A V1 table, index, or constraint whose name is not app-scoped, or that could collide with another
  application's object in the shared database, is a defect against FR-078 and FR-079.
- An application query, repository, or test that references a `pc_` table or index name is a defect
  against FR-078; the development schema is provisional V1 schema, not a preserved prior version.
- Discovering residual prototype tables such as `oc_pcash_*` in a shared development database is
  not a V1 defect and MUST NOT cause V1 to attempt any import, migration, rename, or cleanup.
- A rewritten or corrected V1 baseline migration that is skipped because stale migration-state rows
  remain for the `pettycash` application ID is an environment defect to be cleared through the
  approved development reset, never a reason to reintroduce a compatibility path.

## Requirements *(mandatory)*

### Functional Requirements

#### Roles and Access

- **FR-001**: The system MUST recognize purchaser, cost center manager, senior manager, finance
  officer, and system administrator responsibilities.
- **FR-002**: A user MUST be able to hold multiple roles simultaneously, with effective access
  determined by role, responsibility scope, record ownership, workflow stage, and record state.
  Self-review is permitted when the user independently holds the required review role, responsibility
  scope, and workflow-stage permission (per FR-008); ownership grants purchaser rights only, not
  review rights; all five factors must be satisfied for review actions.
- **FR-003**: Purchasers MUST be limited to their own sheets, transactions, evidence, histories,
  balances, and account movements.
- **FR-004**: Cost center managers MUST be limited to transactions and reports for assigned cost
  centers.
- **FR-005**: Senior managers MUST be limited to transactions and reports for cost centers within
  their assigned responsibility.
- **FR-006**: Finance officers MUST see and act only on transactions that have reached the finance
  stage; every finance officer participates in the shared V1 finance queue.
- **FR-007**: System administrators MUST have full administrative visibility but MUST NOT acquire
  financial approval authority solely from the administrator role.
- **FR-008**: A multi-role user MUST be permitted to review or decide their own transaction when
  the user otherwise holds the required role, responsibility scope, and workflow-stage permission.
- **FR-009**: Authorization MUST be applied consistently to viewing, decisions, evidence, audit,
  reports, and exports; hiding an action in the interface MUST NOT be the only restriction.

#### Configuration and Petty-Cash Accounts

- **FR-010**: Administrators MUST be able to create, update, activate, and deactivate cost centers,
  including code, name, description, validity dates, cost center manager, and senior manager.
- **FR-011**: Each cost center MUST have one current cost center manager and one current senior
  manager, and one person MAY manage multiple cost centers.
- **FR-012**: Administrators MUST be able to create, update, activate, and deactivate expense types,
  including whether a type requires specific evidence.
- **FR-013**: Administrators MUST be able to manage supported currencies and currency-specific
  petty-cash accounts for users.
- **FR-014**: Every user MUST have a default active rial petty-cash account and MAY have additional
  active accounts, each with its own currency, balance, and movement history.
- **FR-015**: Finance MUST be able to charge a selected user petty-cash account and record the
  amount, date, description, currency, and resulting balance as an account movement.
- **FR-016**: V1 finance work MUST use a shared queue in which every finance officer can view and
  act on every transaction that has reached the finance stage.
- **FR-017**: Sensitive changes to assignments, expense types, currencies, access, and control
  settings MUST record the actor, time, explanation, previous value, and new value in a separate
  configuration audit history.

#### Expense Sheets, Transactions, and Evidence

- **FR-018**: Any authenticated user with application access MUST be able to act as a purchaser and
  create multiple open expense sheets.
- **FR-019**: Each sheet MUST have one purchaser, title, creation date, status, source petty-cash
  account, and currency.
- **FR-020**: A purchaser with one active account MUST receive its currency automatically; a
  purchaser with multiple active accounts MUST choose from only those account currencies; a purchaser
  with zero active accounts has a default active rial account auto-created on first sheet creation
  attempt (idempotent provisioning).
  **This requirement owns the provisioning rule.** It previously cross-referenced "FR-014/T085" here
  while `spec.md:26` (Clarifications, session 2026-09-22) cited only FR-020, so the same normative
  behaviour had two identifiers and no consistent citation. FR-014 states the *account model* — that
  every user has a default active rial account — but not the *provisioning trigger*; the trigger is
  what this requirement specifies and what T085 implements. FR-014 is therefore left as the account
  model and is not a second owner of provisioning. Recorded 2026-10-01.
- **FR-021**: Every transaction in a sheet MUST inherit the sheet currency and MUST NOT define an
  independent currency.
- **FR-022**: An open transaction MUST be savable with an expense date and positive amount as its
  minimum initial information.
- **FR-023**: Submission MUST require every transaction to have an expense date, positive amount,
  description, exactly one cost center, one active expense type, and all required evidence.
- **FR-024**: A sheet MUST contain at least one transaction before submission.
- **FR-025**: Before submission, the purchaser MUST be able to add, edit, and delete transactions
  and add or remove their evidence.
- **FR-026**: After submission, the purchaser MUST NOT add transactions or edit transaction
  business information except through correction of a returned transaction.
- **FR-027**: Account balance MUST NOT prevent sheet creation or submission, and negative balances
  MUST be permitted after valid approved consumption.
- **FR-028**: A sheet MAY contain transactions for multiple cost centers, while decisions remain at
  transaction level and sheet status is derived from the collective transaction states.
- **FR-029**: Each transaction MUST support multiple evidence files and retain file name, type,
  size, upload time, uploader, active state, related sheet, related transaction, and revision
  traceability.
- **FR-030**: A user who may view a transaction MUST be able to view its evidence; advanced
  evidence-specific restrictions (document classification levels, redaction rules, watermarking,
  access logging beyond audit, time-limited evidence access, evidence-type-specific permissions)
  are explicitly deferred to V2.
- **FR-031**: Public evidence links MAY be created only under administrator control, MUST be
  manageable and deactivatable, and MUST be audited; links have configurable TTL (default 30 days,
  maximum 1 year) and per-access audit logging (viewer UID, timestamp, IP) recorded in configuration
  audit.

#### Review Workflow and Financial Effects

- **FR-032**: Submission MUST route each transaction to the manager assigned to its cost center and
  MUST begin the transaction's formal business history.
- **FR-033**: Cost center managers MUST be able to approve, reject, or return transactions for
  correction; rejection and return MUST require an explanation.
- **FR-034**: A cost center manager decision MUST remain an advisory decision visible to the senior
  manager with full details in the review queue, and MUST NOT by itself determine the final management
  outcome; the senior manager may approve, reject, or return independently without being forced to
  acknowledge or respond to the advisory decision.
- **FR-035**: Senior managers MUST be able to approve, reject, or return a transaction for
  correction and MAY decide independently of the cost center manager's opinion.
- **FR-036**: The senior manager decision MUST be the final management decision and MUST remain
  unchanged by finance.
- **FR-037**: Transactions approved or rejected by the senior manager MUST proceed to finance after
  all transactions required for that sheet-stage transition have been determined.
- **FR-038**: Finance MUST be able to approve, reject, or return a senior-approved transaction.
- **FR-039**: Finance MUST NOT approve a senior-rejected transaction but MAY reject it or return it
  for correction.
- **FR-040**: Final finance approval MUST record one consumption movement against the petty-cash
  account matching the sheet currency and decrease its balance by the approved amount.
- **FR-041**: Pending or finance-rejected transactions MUST NOT affect the actual petty-cash
  balance.
- **FR-042**: Recording a decision, changing workflow state, creating a revision, and recording
  final financial consumption MUST each complete atomically or leave the prior valid state intact.
- **FR-043**: A sheet MUST become completed when every transaction has a final financial outcome,
  and MUST become completed-with-rejections when at least one transaction is rejected.
- **FR-044**: Completed and completed-with-rejections sheets MUST be locked for viewing and
  reporting only.
- **FR-045**: V1 MUST NOT automatically escalate, reassign, or advance transactions that remain in
  a workflow stage for an extended period.
- **FR-046**: V1 MUST NOT reopen or rewrite a transaction after final financial approval.

#### Correction, Revision, and Audit

- **FR-047**: Any review stage MUST be able to return a transaction to the purchaser for correction
  with a required explanation.
- **FR-048**: Correcting a submitted transaction MUST create a new revision; the prior revision
  MUST remain unchanged and viewable.
- **FR-049**: A corrected transaction MUST restart review with its cost center manager and follow
  the full management and finance path again.
- **FR-050**: The active revision MUST determine the current transaction information and status,
  while all prior decisions remain linked to their original revision.
- **FR-051**: Formal transaction audit MUST begin with sheet submission and record submissions,
  routing, approvals, rejections, returns, corrections, revision creation, transaction changes,
  evidence additions, and evidence deactivations.
- **FR-052**: Every audit event MUST retain event time, actor identifier, actor display name at the
  time, actor role at the time, workflow stage, event type, decision, explanation, and applicable
  before-and-after values.
- **FR-053**: Business audit records MUST be append-only, non-editable, and non-deletable for every
  role, including administrators.
- **FR-054**: Disabling or removing a user MUST NOT delete or alter their historical identity,
  roles-at-event, decisions, records, or in-progress workflow history.
- **FR-055**: Audit visibility and export MUST follow the same role and scope restrictions as the
  related transaction; configuration audit visibility follows role scope: system administrators see
  all configuration audit events; finance officers see configuration audit for accounts they charge;
  cost center and senior managers see configuration audit for their assigned cost centers. An
  officer's charged accounts are the distinct account subjects of that officer's own **account funding
  and balance-charging** configuration audit events, so the set is derived from the audit trail rather
  than recorded on each movement: such an event MUST name the charging officer and the account, and an
  event type outside funding and charging -- a rename, an activation, a deactivation -- MUST NOT count
  toward the set, because touching an account is not charging it. Settling an approved transaction
  against an account therefore MUST write a configuration audit event naming that account as well as
  recording the decision, so a charge appears in both audit streams; relying on the transaction-audit
  decision alone would leave this scope empty for every officer who had actually charged an account. A finance officer with no funding or
  charging event sees no configuration audit, which is a true zero rather than a widened scope.
- **FR-056**: Transaction audit and configuration audit MUST remain separately identifiable from
  each other and from technical error records.
- **FR-057**: Any configurable audit retention policy MUST preserve the non-deletable historical
  record for a minimum of 7 years; policy may govern archival or availability but MUST NOT rewrite
  business history.

#### Evidence Lifecycle

- **FR-058**: Before submission, purchasers MUST be able to remove evidence from their open
  transactions.
- **FR-059**: After submission, removing evidence MUST mark it inactive rather than physically
  delete it, and its history MUST remain viewable.
- **FR-060**: Authorized users MUST be able to preview supported evidence, download it, and see its
  active state and change history. Preview means the browser renders the evidence in place rather than
  saving it: a response whose stored media type is inline-safe -- images, PDF, and plain text -- MUST
  be sent with that stored media type and an `inline` disposition so it renders. Any other media type
  MUST be sent as `application/octet-stream` with an `attachment` disposition, so it is offered as a
  download and is never rendered in the Nextcloud origin, because an attacker-supplied type served
  inline would execute as script in the viewer's session. A caller MUST be able to request either
  disposition explicitly. (Clarified 2026-09-30: `contracts/ocs-api.yaml` declares
  `application/octet-stream` as the only success content type for `GET /evidence/{evidenceId}/content`,
  which makes this requirement's preview unimplementable, while the same contract declares a
  `disposition` parameter defaulting to `inline` -- a value that cannot render an octet-stream body.
  The contract is therefore the artifact that was wrong and MUST be amended to match this requirement;
  T275 owns that change and owns naming the concrete inline-safe media-type list.)

#### Dashboards, Search, Reports, and Exports

- **FR-061**: The system MUST provide role-aware dashboards showing each user's relevant open
  sheets, returned transactions, review queues, decision counts, finance work, balances, movements,
  and administrative summaries.
- **FR-062**: Sheet summaries MUST show headline approved, rejected, and pending amounts based on
  each transaction's latest effective outcome, and MUST separately show approved totals for the
  cost center manager, senior manager, and finance stages as separate columns (Cost Center Approved | Senior Approved | Finance Approved) alongside the latest effective outcome column.
- **FR-063**: Sheet lists and details MUST show identity, owner, currency, status, transaction count,
  key dates, current and submitted totals, summary amounts, and the active transaction revision.
- **FR-064**: Users MUST be able to filter sheets by status, date, currency, and creator;
  transactions by status, cost center, expense type, date, and amount; audit by actor, stage,
  event type, and date range; and group exports by period, which is the predefined Jalali period
  defined in FR-068. **`period` is a filter in its own right, not a form of the date or date-range
  filters**, and it is scoped to the export and report endpoints that already accept a date range.
  Each facet resolves to exactly one source, so that "filter by creator"
  has a single meaning: a sheet's **creator** is its owner; a transaction's **cost center** is the one
  recorded on that transaction's own active revision -- not the sheet's, because a sheet may hold
  transactions for several cost centers (FR-028); a transaction's **expense type** and **amount** also
  come from its active revision; and an audit event's **stage** is the stage recorded on the event.
  A filter naming any field outside this set MUST be refused with a 422 naming the accepted filters, and
  MUST NOT be ignored -- an unrecognised filter silently returning the unfiltered report is a wrong
  answer, not an empty one. Because FR-068 makes `period` and an explicit date range mutually exclusive
  within one request, supplying both MUST be refused as FR-068 requires; `period` MUST NOT silently
  narrow a supplied date range, and MUST NOT be merged into one. (Clarified 2026-09-30: this set omitted
  `period` while FR-068 required it, so the specification both required the filter and -- through the
  422 rule below -- forbade it. `period` is now a named member of the accepted set rather than being
  declared a parameterisation of `date`, because FR-068 presents periods and custom date ranges as
  alternatives and makes them mutually exclusive, which a parameterisation and its own host cannot be.)
- **FR-065**: Reports MUST cover sheets, transactions, cost centers, current process status, evidence,
  and history within the requester's access scope. Petty-cash balances and movements MUST be reachable
  as data of the `accounts` report -- one account per row, carrying that account's balance and its
  movements. The report shows a user their own accounts, a finance officer every account they have
  charged in addition to their own, and an administrator every account; a report that showed only the
  caller's own accounts would hide from a finance officer the very accounts FR-015 requires them to
  charge. The reporting API publishes exactly seven report types -- sheets, transactions, cost-centers,
  accounts, process, evidence and audit -- and no requirement may add an eighth.
- **FR-066**: Authorized users MUST be able to export permitted reports, sheets, transactions,
  audit histories, and evidence in a supported `fileFormat` (`csv`, `xlsx`, `zip`, or `pdf`), and a
  format outside that set MUST be refused with a reason naming what is supported rather than answered
  with an unreadable or empty document. All four formats MUST be supported in V1. A Persian-capable
  font MUST be vendored into the repository under an app-owned path together with its licence text, and
  PDF export MUST NOT depend on a font installed on the host or inside a container, so that a Persian
  PDF renders identically in every deployment. (Clarified 2026-09-30: `pdf` was previously conditional
  on "a Persian-capable font being available", which no artifact ever established a location for, so V1
  shipped without PDF by accident rather than by decision and T109 was permanently blocked. Verified
  during that task: `tc-lib-pdf` is installed but `tecnickcom/tcpdf` is not, `vendor/` holds no font
  files at all, and `tc-lib-pdf-font` ships none and documents that they must be generated by the
  consuming project's install hooks. Bidirectional text shaping is not the constraint and was verified
  working through `tc-lib-unicode`'s UAX#9 bidi and Arabic shaping plus `setRTL()`.)
- **FR-082**: Group exports MUST honour the records the caller selected. A selection the system cannot
  satisfy MUST be refused with a reason; it MUST NOT be answered with an export that omits the selected
  records. A filter the report's records cannot answer MUST also be refused, never reported as an empty
  result. (Numbered FR-082 rather than FR-068 by the 2026-09-30 clarification: this rule and the group-export
  coverage rule below were both numbered FR-068. FR-068 is retained for the coverage rule because every
  existing cross-reference -- `tasks.md` and `plan.md`'s Reporting Periods note -- uses FR-068 for it. This
  rule therefore takes the next free identifier and keeps its position among the export requirements.)
- **FR-067**: A complete sheet export MUST preserve the relationships among sheet information,
  transactions, decisions, revisions, audit events, and evidence.
- **FR-068**: Group exports MUST support selected sheets, date ranges, cost center transactions, and
  reporting periods (predefined periods: month, quarter, year; plus custom date range) without
  including records outside the requester's access scope. **The predefined periods are Jalali
  (Solar Hijri) periods, because that is the calendar every date in the interface is already
  rendered in**; a period therefore MUST span Farvardin through Esfand, and a Jalali quarter MUST
  begin at Farvardin rather than at a Gregorian quarter boundary. A predefined period MUST resolve
  to an equivalent Gregorian from/to pair for querying, and MUST NOT alter, re-encode or re-interpret
  any stored expense date; stored dates remain Gregorian calendar dates without a time zone. A Jalali
  year is not a Gregorian year, so a period MUST NOT be identified in the request or the response by
  a Gregorian year number. **Each predefined period denotes the period in progress at the moment of
  the request** — the current Jalali month, quarter or year — running from its first day through its
  last, rather than the previous completed period or a trailing window of the same length. Because a
  period in progress is necessarily incomplete, every response that resolved a predefined period MUST
  state the exact date range it resolved, so that a partial period cannot be mistaken for a complete
  one. A request for a previous or trailing period is expressed with the explicit custom date range
  this requirement already supports, not by a second preset. **Period boundaries MUST be resolved in
  the time zone of the account making the request**, taken from that user's own account settings and
  falling back to the instance's configured default time zone when the account does not state one;
  the application MUST NOT hardcode a single zone. Because the zone belongs to the caller, the range
  a predefined period resolves to is **relative to the requesting user**, and two users in different
  time zones resolving the same period name MAY obtain different date ranges. A response MUST state
  both the resolved range and the time zone it was resolved in, so that a difference between two
  users' exports is explainable from the response rather than appearing as missing records.
  **Period boundaries are inclusive at both ends**: a period covers every expense date from its first
  day through its last day, so no day belonging to the period is excluded and no day outside it is
  included. Expense dates are whole calendar dates carrying no time of day, so a boundary cannot fall
  inside a record and there is no half-open interval to reason about. A predefined period and the
  equivalent explicit custom date range MUST therefore select exactly the same records. A Jalali year
  MUST NOT be treated as a fixed length: it has 365 or 366 days, and a period's record count MUST
  reflect its actual length. **A predefined period and an explicit custom date range are mutually
  exclusive within one request.** A request supplying both MUST be refused with a `422` whose message
  names the conflict and states which of the two to remove, and the application MUST NOT resolve the
  conflict by honouring one and discarding the other, nor by intersecting them. Silently dropping
  either instruction would make it impossible for a caller to tell which was applied, and this
  requirement already refuses rather than ignores elsewhere for the same reason.
- **FR-069**: Group evidence exports MUST identify the sheet, transaction, revision relationship,
  file metadata, and active state for every exported item.
- **FR-070**: Scheduled exports, automatic report delivery, external export interfaces, custom
  organizational templates, and direct integration with other systems MUST remain outside V1.

#### Experience and Operational Quality

- **FR-071**: The V1 interface MUST be Persian and right-to-left, with all listed elements (sheet status, transaction status, current stage, prior decisions, history, evidence) visible without horizontal scroll at 390px viewport, status color/icon meeting WCAG AA contrast, text ≥12pt Persian font, and adapt to desktop and mobile-sized screens.
- **FR-072**: The primary workflow MUST be usable in Chrome 120+, Edge 120+, and Firefox 115+ (ESR).
- **FR-073**: The product MUST support the documented V1 operating scale of up to 100 active users
  and 10,000 expense transactions per year without loss of correctness, with p95 latency <3s for
  routine views (SC-008), error rate <0.1%, and zero data loss.
- **FR-074**: Evidence size limits MUST follow the host file service rather than introduce a
  separate PettyCash limit; V1 enforces a maximum evidence file size of 100 MB per file.
- **FR-075**: Communication failures MUST show a clear error and require manual retry; V1 MUST NOT
  automatically resume interrupted operations.
- **FR-076**: Independent application backup and restore, active multilingual UI, a standalone
  mobile application, and advanced dashboard shortcuts MUST remain outside V1.
- **FR-083**: Every read endpoint MUST answer an unreadable stored record — a broken reference, an
  incomplete row — with the same status and the same class of explanation, so that a client writes
  one handler rather than one per endpoint. `GET /sheets`, `GET /sheets/{id}`, `GET /dashboard` and
  the report endpoints MUST all return `422` with a message identifying the condition as a data fault
  and ruling out permissions and filters, and MUST NOT return `500`; `500` remains reserved for
  genuinely unexpected faults. An unreadable record MUST NOT be silently omitted from a result set,
  defaulted to a valid-looking value, or rendered as if healthy: a stored record whose reference does
  not resolve is a fault to be reported, not one to paper over. (Clarified 2026-09-30: the same fault
  had two answers depending on the endpoint — the report endpoints returned `422` because
  `ReportController` translated it, while sheets and dashboard fell through to the default mapper's
  `500` with the message withheld. T313 owns making them consistent and T314 pins the settled
  behaviour with tests, including the administrator and dashboard paths that no test currently
  exercises.)
- **FR-084**: Every mutating operation MUST be replay-safe, so that a client retry, a double-click, or
  a duplicated network request can never apply a second business effect. The client MUST supply a
  `X-PettyCash-Command-ID` identifying the command, and the server MUST record the outcome of an
  applied command keyed by that identifier, so that a repeat of an already-applied command returns
  **the first outcome** rather than producing a second decision, revision, settlement or deletion. The
  recorded outcome MUST be the original response, not a recomputation. A command identifier reused
  with **different content** MUST be refused as a conflict rather than applied, and a mutating
  operation that receives **no** command identifier MUST be refused rather than assigned a
  server-generated one. The reason a server-generated fallback is forbidden is specific rather than
  stylistic: it makes a replay arrive under a different key, so the store has nothing to match and the
  idempotency wrapper silently degrades into "apply twice" — the request is accepted, and the client
  cannot recognise the result as the same command. Replay safety composes with FR-042's atomicity: a
  recorded outcome MUST describe a state that was actually committed, never one that was rolled back.
  (Added 2026-10-02 to close the gap recorded under **Command Result**:
  `contracts/ocs-api.yaml:767-772` marks the header required on every mutating operation and the
  schema, persistence layer and roughly a dozen tasks implement replay, but no requirement stated the
  rule, leaving implemented business behavior untraceable to a requirement. T027, T317 and T318 are the
  delivered implementation; T134's traceability range covers it.)
- **FR-077**: PettyCash MUST remain database-portable across the declared V1 database backends:
  MySQL/MariaDB, PostgreSQL, and SQLite. The MariaDB development environment is not an
  application-level dependency, and behavior MUST remain equivalent across the declared backends.
  The declared V1 backends are the Nextcloud database types `mysql` (MySQL 8.0/8.4 and MariaDB
  10.6/10.11/11.4/11.8), `pgsql` (PostgreSQL 14/15/16/17/18), and `sqlite` (SQLite 3.24+, declared
  for compatibility verification rather than as a production recommendation), verified against the
  Nextcloud 34 supported-backend documentation. Oracle/`oci` is outside V1 scope and MUST NOT be
  declared as supported unless it is explicitly added later as an Enterprise compatibility target.
  Application persistence and schema change MUST NOT depend on backend-specific SQL, functions,
  types, identifier-length or auto-increment assumptions, `GROUP BY` leniency, row-limiting delete
  behavior, or any other vendor-specific extension, so that equivalent behavior is achievable on
  every declared backend. The declared backend set MUST be declared explicitly rather than left
  implied, and the existing MariaDB development and Docker infrastructure MUST remain unchanged
  while any additional backends used for verification remain independent of it. A passing result on
  MariaDB alone MUST NOT be treated as evidence of cross-database compatibility. The verification
  mechanism, supported-backend declaration, and portability rules are decided in the plan.

#### Schema Ownership and Migration Scope

- **FR-078**: Every V1 persistent table MUST use the logical app-scoped name prefix `pettycash_`.
  The instance database prefix is applied by the Nextcloud database abstraction, and application
  code, migrations, tests, fixtures, and documentation MUST NOT hardcode a specific instance prefix
  such as `oc_`. Tables already present in the development schema under a non-app-scoped `pc_` name
  are provisional V1 development schema rather than legacy data, and MUST be renamed into the
  `pettycash_` namespace; they MUST NOT remain part of the V1 baseline.
- **FR-079**: Every V1 index, unique constraint, foreign key, and check constraint MUST be named
  `pettycash_<table>_<subject>_<kind>`, where kind is one of `idx`, `uniq`, `fk`, or `chk`, so that
  no schema object can collide with another application in the shared database. Identifiers MUST
  remain within the portable identifier limits of the declared V1 backends and MUST be abbreviated
  only when a full descriptive name would exceed those limits.
- **FR-080**: V1 storage MUST be created from scratch on a clean installation. PettyCash MUST
  provide no import, migration, table preservation, or data-compatibility contract for any previous
  PettyCash installation or database. Tables left behind by a discarded prototype in a shared
  development database are unmanaged artifacts that V1 MUST NOT read, write, migrate, import, rename,
  or drop, and their presence MUST NOT be treated as a V1 defect or a trigger for any V1 action.
- **FR-081**: Schema-change acceptance MUST cover clean installation on an empty database and the
  forward application of future V1 migrations in version order. It MUST NOT require, and MUST NOT be
  evidenced by, an upgrade path from any pre-V1 database. Resetting the migration state recorded for
  the `pettycash` application ID is development-environment work only: it requires explicit human
  approval, affects PettyCash only, and MUST NOT delete Docker volumes, the shared Nextcloud
  database, or any unrelated application data.

### Key Entities

- **Petty-Cash Account**: A user's balance and movement history for one currency; receives charges
  and approved expense consumption.
- **Petty-Cash Movement**: A dated charge or approved consumption with amount, currency,
  description, and resulting balance.
- **Expense Sheet**: A purchaser-owned grouping of transactions with one currency, a source
  petty-cash account, aggregate status, key dates, and summary amounts.
- **Expense Transaction**: The smallest review and decision unit, containing the expense date,
  amount, description, cost center, expense type, evidence, status, and active revision.
- **Transaction Revision**: An immutable version created when a submitted transaction is corrected;
  prior revisions and decisions remain available.
- **Cost Center**: An organizational responsibility area with identity, validity, one cost center
  manager, and one senior manager.
- **Expense Type**: A managed category used for classification and reporting, including any
  evidence requirement.
- **Evidence**: A file associated with a transaction and revision, with metadata, uploader,
  active state, and change history.
- **Workflow Decision**: An approve, reject, or return outcome recorded for a transaction at a
  specific stage by an authorized actor, including explanation where required.
- **Business Audit Event**: An immutable record of an official workflow or business-data event,
  including actor context and before-and-after values.
- **Configuration Audit Event**: An immutable record of a sensitive administrative configuration
  or access change.
- **Role Assignment**: A user's role and responsibility scope, including cost center or finance
  responsibility where applicable.
- **Currency**: A supported currency code with its configured minor-unit scale, against which every
  amount in the system is interpreted and every amount is displayed. Referenced by FR-008 and FR-020
  (the default rial account) and by the presentation rules that render an amount at its own scale.
  Added 2026-10-01: this is one of three entity families the schema has always had and this list
  omitted. It listed 12 against `plan.md`'s 15 persistent entity families and the 15 `pettycash_*`
  tables; adding Currency, Public Evidence Link and Command Result brings this list to **15**, so the
  two artifacts and the schema now agree.
- **Public Evidence Link**: A revocable, optionally-expiring share token for one evidence item,
  stored as a hash rather than in the clear, disabled by default, with per-access audit written to
  configuration audit. Required by FR-031 and FR-055/FR-056's configuration-audit scope. Added
  2026-10-01, for the same count discrepancy as Currency.
- **Command Result**: The recorded outcome of an applied mutating command, keyed by the
  client-generated command identifier, so that a repeat of an already-applied command returns the
  first outcome instead of applying a second business effect. Added 2026-10-01 for the same count
  discrepancy as Currency.
  **The gap this entity had is closed.** On 2026-10-01 this entry recorded that the entity had **no
  owning functional requirement** — `contracts/ocs-api.yaml:767-772` marks `X-PettyCash-Command-ID`
  required on every mutating operation and the schema, persistence layer and roughly a dozen tasks
  implement replay, but no FR stated the rule, so implemented business behavior was traceable to
  tasks but not to a requirement. **FR-084** now states it, and T134's range reads FR-001–FR-084. The
  requirement was added *before* the range was widened, not after: widening first would have produced
  a traceability matrix citing a requirement nothing defines, which is the same defect that range was
  widened twice for previously. Adding FR-084 moved the count from 83 to 84, which is also the change
  `plan.md`'s Scale/Scope now records.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In acceptance testing, 100% of complete sheets enter the first review stage and 100%
  of incomplete or empty sheets are rejected with actionable validation messages.
- **SC-002**: Test users in every role can complete their primary workflow journey without seeing
  or changing any record outside their assigned ownership or responsibility scope.
- **SC-003**: 100% of tested approve, reject, return, correction, and finance outcomes follow the
  permitted state transitions and preserve all earlier decisions.
- **SC-004**: Every tested correction creates exactly one new active revision while all prior
  revisions, decisions, and recorded changes remain viewable to authorized users.
- **SC-005**: Every tested final finance approval creates exactly one matching account-consumption
  movement and balance change; forced failures produce zero partial financial or workflow effects.
- **SC-006**: 100% of official events in the acceptance workflow appear in chronological history
  with the actor, role, stage, time, event, explanation, and applicable before-and-after values.
- **SC-007**: Reports and exports for every role contain all permitted selected records and zero
  records outside that role's access scope across the acceptance dataset.
- **SC-008**: At the V1 operating scale of up to 100 active users and 10,000 annual transactions,
  at least 95% of routine list, detail, history, and evidence views become usable within 3 seconds
  under normal operating conditions. This is measured during V1 rather than deferred: the measurement
  is performed at the stated scale and its result recorded, so the criterion is reported as met or not
  met instead of being read as covered because nothing ever checks it. A view counts as **usable** when
  it has finished loading and is showing the rows that user is entitled to see, with no error or empty
  state standing in for them; it is measured end to end as a user sees it, not as an internal timing.
- **SC-009**: All primary purchaser, manager, senior manager, finance, and administrator journeys
  can be completed in Persian RTL at desktop and mobile viewport sizes in each supported browser.
- **SC-010**: In moderated acceptance testing, at least 90% of representative users complete their
  primary role journey on the first attempt without facilitator intervention. The result MUST be
  measured and recorded, and this criterion MUST NOT be reported as met by default. When
  representative users or a facilitator are unavailable, it MUST be recorded explicitly as **not
  measured**, naming the reason, rather than treated as satisfied or omitted from the review. It is
  not a blocking release gate; its purpose in V1 is to make both the figure and its availability
  visible, on the same principle already applied to SC-008. No automated test can produce this number,
  so no task is expected to assert it; the release-readiness review owns recording the outcome,
  whichever way it falls. (Clarified 2026-09-30: this was the only one of the fourteen success criteria
  with neither an automatable proxy nor a task referencing it, so the review would otherwise have had
  nothing to check it against. It is distinct from SC-002, which measures the same journeys under
  fixture accounts and is automated.)
- **SC-011**: 100% of completed and completed-with-rejections sheets reject attempts to add or edit
  business records while remaining fully available for authorized viewing and reporting.
- **SC-012**: Disabling a test user leaves 100% of that user's historical actor identity, roles,
  decisions, revisions, and audit events unchanged and accessible to authorized reviewers.
- **SC-013**: V1 database-portability acceptance succeeds only when the schema/migrations and the
  defined persistence/workflow compatibility suite complete successfully against every declared V1
  database backend, with no backend-specific functional deviation.
- **SC-014**: A clean installation on an empty database creates 100% of V1 tables under the
  `pettycash_` prefix with 0 non-app-scoped PettyCash tables remaining, 100% of V1 indexes, unique
  constraints, foreign keys, and check constraints carry `pettycash_*` names, and applying the full
  V1 migration chain in version order on a clean database succeeds on every declared V1 backend.

## Assumptions

- The requirements set is version 1.1; this feature specification consolidates its currently stated
  rules. Decisions still open are named where they occur rather than resolved silently.
- V1 uses the organization's existing authenticated user directory, file service, and platform
  security controls; account creation, passwords, login policy, and infrastructure events are
  outside PettyCash business scope.
- The confirmed V1 transaction fields are expense date, positive amount, description, cost center,
  expense type, and related evidence. Type-specific custom fields are future scope.
- Finance records its decision and explanation in V1; additional finance-only business fields and
  a separate post-settlement correction process are future scope unless added through clarification.
- V1 permits authorized multi-role users to decide their own transactions; role, responsibility,
  workflow-stage, and audit requirements still apply to every decision.
- All finance officers share one finance-stage work queue in V1; scoped finance assignment is
  future scope.
- Headline sheet totals follow the latest effective transaction outcome, while separate stage
  totals preserve visibility into differing manager, senior-manager, and finance decisions.
- Anyone permitted to view a transaction may view its evidence. More granular restrictions for
  special evidence categories are future scope.
- The dashboard's detailed visual layout and table arrangement will be decided during design; the
  information and actions specified here are required regardless of layout.
- V1 has no automatic escalation, scheduled export, automatic report delivery, standalone mobile
  application, independent backup/restore product, or active multilingual interface.
- The 3-second routine-view target is a planning baseline chosen to make the documented requirement
  for acceptable normal-use performance measurable.
- PettyCash V1 is a fresh application. Any previous PettyCash installation, its database, and any
  development data produced by the discarded prototype are reference material only; no supported
  import, migration, or data-compatibility path from them exists or is planned (FR-080).

## Dependencies

- Approved organizational assignments for cost centers, managers, senior managers, finance
  responsibility, supported currencies, and expense types.
- Availability of the host organization's authenticated users, file storage, technical logging,
  and backup facilities.
