# Feature Specification: PettyCash V1 Core Workflow

**Feature Branch**: `001-pettycash-v1-core`

**Created**: 2026-09-19

**Status**: Draft — ready for planning

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
4. **Given** a group of records is selected, **When** an export is requested in a supported format,
   **Then** the system produces a complete grouped export in Excel, CSV, or PDF as applicable.

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
  attempt (idempotent provisioning per FR-014/T085).
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
  cost center and senior managers see configuration audit for their assigned cost centers.
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
  active state and change history.

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
  transactions by status, cost center, expense type, date, and amount; and audit by actor, stage,
  event type, and date range.
- **FR-065**: Reports MUST cover sheets, transactions, cost centers, petty-cash balances and
  movements, current process status, evidence, and history within the requester's access scope.
- **FR-066**: Authorized users MUST be able to export permitted reports, sheets, transactions,
  audit histories, and evidence in Excel, CSV, and PDF where applicable.
- **FR-067**: A complete sheet export MUST preserve the relationships among sheet information,
  transactions, decisions, revisions, audit events, and evidence.
- **FR-068**: Group exports MUST support selected sheets, date ranges, cost center transactions, and
  reporting periods (predefined calendar periods: month, quarter, year; plus custom date range)
  without including records outside the requester's access scope.
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
  under normal operating conditions.
- **SC-009**: All primary purchaser, manager, senior manager, finance, and administrator journeys
  can be completed in Persian RTL at desktop and mobile viewport sizes in each supported browser.
- **SC-010**: In moderated acceptance testing, at least 90% of representative users complete their
  primary role journey on the first attempt without facilitator intervention.
- **SC-011**: 100% of completed and completed-with-rejections sheets reject attempts to add or edit
  business records while remaining fully available for authorized viewing and reporting.
- **SC-012**: Disabling a test user leaves 100% of that user's historical actor identity, roles,
  decisions, revisions, and audit events unchanged and accessible to authorized reviewers.

## Assumptions

- The requirements set is version 1.0 and remains Draft; this feature specification consolidates
  its currently stated rules while exposing unresolved decisions rather than treating them as
  approved facts.
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

## Dependencies

- Approved organizational assignments for cost centers, managers, senior managers, finance
  responsibility, supported currencies, and expense types.
- Availability of the host organization's authenticated users, file storage, technical logging,
  and backup facilities.
