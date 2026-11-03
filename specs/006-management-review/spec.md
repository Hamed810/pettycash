# Feature Specification: Sheet-First Management Review

**Feature Branch**: `006-management-review` (logical feature directory; the current worktree branch is `feat/pettycash-purchaser-ui-batch-a`, and no branch operation is authorized by this specification)

**Created**: 2026-10-29

**Status**: Draft — visual-first implementation authorized for the Cost Center Manager Sheets Queue only. The Sheet Review Workspace is planned but MUST NOT be implemented before the first owner visual gate is recorded. No backend, API-contract, workflow-contract, authorization, financial-rule, or 001-specification change is authorized by this specification.

**Input**: Owner directive: "Implement the new sheet-first Cost Center Manager review interface inside the existing Nextcloud application, using the approved Manus management information architecture and the established Purchaser UI visual language. Preserve existing working functionality. Where backend support is missing, implement honest unavailable or empty states and keep unsupported functionality disabled. Do not modify the backend in this phase."

---

## Source of Truth and Scope

This feature governs the **management review presentation and interaction redesign**. It does not restate or redefine domain rules.

1. `.specify/memory/constitution.md` governs delivery, verification, and visual evidence.
2. `specs/001-pettycash-v1-core/spec.md` and its approved contracts govern domain, authorization, financial, lifecycle, evidence, currency, and revision behavior.
3. This specification governs the management information architecture, presentation, interaction, capability classification, and visual gates.
4. `specs/006-management-review/plan.md` governs the technical approach.
5. `specs/006-management-review/tasks.md` is the canonical task ledger for this feature.
6. The Manus screenshots under `docs/frontend-redesign/references/managment/cost-center/` are **visual references only**, not API evidence or owner acceptance.

Existing requirements, canonical task IDs, and working functionality MUST be preserved. This feature MUST NOT be folded into `005-purchaser-ui-redesign`.

### Phase Boundary

**Phase 1 (this authorization): Cost Center Manager — Sheets Queue.** Desktop and mobile presentation, using the approved visual language, rendered inside Nextcloud, with a fresh screenshot comparison and a mandatory owner visual gate.

**Phase 2 (next, after Phase 1 approval): Cost Center Manager — Sheet Review Workspace.** Planned in this specification and task ledger, but not implemented before the Phase 1 gate is recorded.

**Out of scope for both phases:** Senior Manager screens, Finance screens, transaction decision redesign, backend changes, sheet-level approval, new workflow actions, and any change to a public contract.

---

## Workflow Reconciliation

The owner-approved rules below are explicit. They conflict with parts of the current implementation and with the current reading of 001 `FR-037`/`FR-043` and `WorkflowStateMachine`.

| Owner-approved rule | Current behavior / artifact | Disposition in this phase |
|---|---|---|
| A sheet MUST NOT become eligible for Senior Review until **all** transactions in that sheet have completed the required Cost Center Review processing. | Every Cost Center decision advances its own transaction immediately (`WorkflowStateMachine::next` maps Cost Center → Senior for Approve and Reject). No sheet-wide gate exists. | **Conflict recorded.** Backend change required. The redesigned interface MUST NOT claim sheet-wide eligibility from per-transaction state. Eligibility is rendered as unavailable until a backend capability exists. |
| At least one transaction in the sheet MUST be eligible for Senior Review. | No sheet-level eligibility concept exists. | **Conflict recorded.** Backend change required. |
| A rejected transaction MUST NOT automatically advance to Senior Review. | A Cost Center rejection advances that transaction to Senior. | **Conflict recorded.** Backend change required. The interface MUST NOT present rejection as senior-ready. |
| A returned-for-correction transaction remains unresolved until the required correction workflow completes. | Return routes the transaction to Correction; the sheet status derivation already treats Correction as outstanding. | Consistent with the owner rule at the sheet level; per-transaction senior eligibility still lacks a sheet-wide gate. |
| A Cost Center Manager finishing their own assignments does **not** mean the entire sheet completed Cost Center Review. | No manager-scoped sheet aggregation or sheet-wide completion indicator exists. | **Conflict recorded.** Backend change required. The interface MUST NOT infer sheet completion from the current manager's remaining queue. |

**No backend behavior is changed by this specification.** The conflict is recorded here so it is not silently preserved or silently redefined in code. Resolving it requires a separate owner authorization, an amendment to 001, and backend work.

---

## Capability Classification

Every required management capability is classified as one of: **supported**, **frontend adaptation**, or **backend change required**.

| Capability | Classification | Evidence | Phase |
|---|---|---|---|
| Manager-scoped transaction queue (`GET /queues/cost-center`) | Supported | `ReviewController::index`, `ManagementReviewService::getCostCenterQueue` | 1 |
| Transaction detail and transaction-level decision types | Supported | `GET /transactions/{id}`, `POST /transactions/{id}/decisions`, existing review views | 1 (preserved) |
| Approve / Reject / Return for Correction | Supported | `Decision` enum and existing decision form | 1 (preserved) |
| Sheet grouping for the manager's **pending** transactions | Frontend adaptation with a completeness guard | Queue returns `sheetId` on each summary; no sheet-grouped response | 1 |
| Manager-specific sheet count and pending-transaction count | Frontend adaptation with a completeness guard | Derived only from a proven-complete pending set | 1 |
| Manager-specific pending amount | Frontend adaptation with a completeness guard | Sum of loaded pending transaction amounts, grouped by currency | 1 |
| Sheet title, purchaser display name, sheet total, all-transaction count | **Backend change required** | `GET /sheets/{id}` is owner-only; `TransactionSummary` carries no sheet title, owner, or sheet total | 1 (honest unavailable) |
| Reviewed-sheet history and "Reviewed" filter | **Backend change required** | Decided transactions leave the Cost Center queue; no manager-scoped sheet history endpoint | 1 (disabled) |
| Sheet-wide Cost Center completion and Senior Review eligibility | **Backend change required** | No sheet-wide gate or aggregation exists; `WorkflowStateMachine` advances per transaction | 2 (honest unavailable) |
| Manager-scoped sheet detail with only authorized transactions | **Backend change required** | No manager-scoped sheet endpoint; transaction detail is authorized per transaction | 2 (workspace reads transaction details individually) |
| Submitted date / sheet queue chronology | **Backend change required** for a true submitted date | `TransactionSummary.activeRevision.createdAt` is a revision creation time, not a sheet submission time | 1 (honest alternative label) |

The implementation MUST connect only the supported and safely adaptable capabilities. Unsupported controls MUST be visibly disabled with a reason, or replaced by an honest unavailable state. They MUST NOT be presented as working.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 — A sheet-first queue that shows what this manager must decide (Priority: P1)

A Cost Center Manager opens Review Queue and sees **sheets**, not a flat transaction table. Each sheet row summarizes only the transactions awaiting this manager's decision. Selecting a sheet is the handoff to the review workspace. The queue is honest about what the backend can prove: it shows pending sheets and pending amounts, and it does not claim reviewed sheets, sheet titles, purchaser identities, or sheet-wide completion.

**Why this priority**: This is the entry point to the entire management journey and the first owner visual milestone. It must be visible inside Nextcloud before any other screen is redesigned.

**Independent Test**: With a manager holding one assigned cost center and a pending queue that fits in a single complete response, the queue renders one row per sheet, the number of pending transactions per sheet, the pending amount per currency, and the cost-center scope. With a queue that cannot be proven complete, it renders an honest unavailable state and a working link to the existing transaction queue. No row exposes a transaction outside the manager's scope.

**Acceptance Scenarios**:

1. **Given** a Cost Center Manager with pending transactions, **When** the queue loads, **Then** the page renders sheet cards/rows grouped from the complete pending queue, and the counts are derived from that same set.
2. **Given** the queue response is complete, **When** a sheet has three pending transactions, **Then** its row states three pending transactions and the pending amount for that sheet, grouped by currency.
3. **Given** two currencies in the pending set, **When** totals render, **Then** each currency is shown separately and no cross-currency sum is presented.
4. **Given** the queue response is truncated or cannot be proven complete, **When** the view renders, **Then** it shows an honest "sheet grouping unavailable" state, does not invent counts, and offers the existing transaction queue.
5. **Given** a manager has no pending transactions, **When** the queue loads, **Then** an honest empty state is shown, not a fabricated sheet.
6. **Given** the "Reviewed" filter or reviewed-sheet count, **When** it renders, **Then** it is visibly disabled with an explanation that a backend sheet-history capability is required.
7. **Given** a search or sort control that operates only on the complete loaded set, **When** the user searches/sorts, **Then** results are filtered/sorted without a network request and no paginated partial set is presented as complete.
8. **Given** a sheet row, **When** the manager activates it, **Then** the manager reaches the existing transaction-level review path for a transaction in that sheet, or, until the workspace exists, an explicit Phase 2 handoff state. No sheet-level Approve or Reject action exists.
9. **Given** a 390px viewport and Persian RTL, **When** the queue renders, **Then** there is no horizontal scroll, the layout is RTL-correct, and the mobile presentation matches the approved information hierarchy.
10. **Given** English LTR and an independent Gregorian or Jalali calendar preference, **When** the queue renders, **Then** direction, digits, dates, and amounts follow the existing locale utilities.

---

### User Story 2 — A sheet review workspace scoped to the manager (Priority: P2)

A manager opens a sheet and sees the sheet identity, the manager's own review progress, the manager's authorized transactions, and an honest statement of sheet-wide Cost Center status. Review decisions are made on individual transactions. The workspace never offers a sheet-level decision and never shows a transaction outside the manager's scope.

**Why this priority**: This is the second visual milestone. It depends on the queue's navigation and must not be implemented before the first gate is approved.

**Independent Test**: Open a sheet from the queue, verify that only the manager's authorized transactions appear, that each transaction links to the existing review detail, that no sheet-level Approve/Reject exists, and that sheet-wide completion/eligibility is shown as unavailable when the backend cannot prove it.

**Acceptance Scenarios**:

1. **Given** a sheet with transactions in the manager's scope, **When** the workspace opens, **Then** it shows the sheet reference, the manager's pending count and amount, and only those transactions.
2. **Given** a sheet where the manager has completed their own assignments, **When** the workspace renders, **Then** it states that the manager's assignments are complete without claiming the sheet-wide Cost Center stage is complete.
3. **Given** a sheet whose backend cannot prove sheet-wide completion, **When** the workspace renders, **Then** Senior Review eligibility is shown as unavailable, not "Pending", "Complete", or "Eligible".
4. **Given** a transaction in the workspace, **When** the manager chooses a transaction, **Then** the existing transaction review detail opens and the existing Approve/Reject/Return actions remain the only decision actions.
5. **Given** the workspace at 390px in Persian RTL, **When** it renders, **Then** there is no horizontal scroll and exactly one dialog/workspace layer is present.
6. **Given** the workspace is open, **When** it closes, **Then** focus returns to the originating sheet row and the queue is unchanged.

---

### User Story 3 — Transaction decisions remain transaction-level (Priority: P3)

A manager reviews a transaction inside a sheet context and records Approve, Reject, or Return for Correction. The decision applies to that transaction only. The sheet context is navigation, not an approval unit.

**Why this priority**: It preserves the existing operational review functionality and the domain's decision unit while the presentation changes around it.

**Independent Test**: From a sheet, open a transaction, record each decision type, and verify the request targets that transaction only and the sheet has no decision endpoint or button.

**Acceptance Scenarios**:

1. **Given** a transaction in the manager's scope, **When** the manager records Approve, Reject, or Return, **Then** exactly one transaction decision request is issued.
2. **Given** a sheet workspace, **When** it renders, **Then** there is no sheet-level Approve, Reject, Return, or "decide all" control.
3. **Given** a rejected or returned transaction, **When** the manager returns to the queue, **Then** the queue reflects the server's current pending set on refetch and does not synthesize an outcome.
4. **Given** a decision is refused, **When** the error is shown, **Then** the existing error behavior is preserved and no local status is fabricated.

---

### User Story 4 — Stage completion and Senior Review eligibility are truthful (Priority: P4)

The interface explains the owner-approved stage rule and never claims a sheet is Senior-Review-eligible unless the backend can prove it. Until the required backend capability exists, the workspace shows the rule and an honest unavailable state.

**Why this priority**: The owner-approved rule is stricter than the current backend. Showing a false "Complete" or "Eligible" badge would be a financial-workflow misrepresentation.

**Independent Test**: Render the workspace for a sheet with pending, rejected, and returned transactions; verify that no combination of client-side transaction states produces an "Eligible for Senior Review" claim, and that the unavailable reason is visible.

**Acceptance Scenarios**:

1. **Given** a sheet with any transaction not fully processed at Cost Center, **When** eligibility renders, **Then** it does not read "Eligible for Senior Review".
2. **Given** a manager with no remaining assignments but other managers' status unknown, **When** the workspace renders, **Then** it states that sheet-wide completion is unknown/unavailable, not complete.
3. **Given** a rejected transaction, **When** it is shown in the manager's completed list, **Then** it is not labeled as advanced or senior-ready.
4. **Given** the backend later provides an authoritative eligibility flag, **When** the feature is reconnected, **Then** the workspace may display the flag without changing this information architecture.

---

### Edge Cases

- The manager's pending queue is larger than the safe single-response completeness threshold.
- The queue returns transactions from more than one cost center and the response is truncated.
- A sheet reference is empty or unresolvable in a summary.
- Pending transactions use different currencies.
- A sheet has one pending transaction and other transactions already decided by this or another manager.
- The manager's session scope is empty or the session fails to load.
- The queue request fails, times out, or returns a stale `total`.
- A sheet disappears between queue load and activation because another manager decided its last pending transaction.
- The active language is English LTR or Persian RTL; the calendar preference is Gregorian or Jalali independently.
- The viewport is 390px wide and the bottom navigation occupies the lower edge.
- Dark theme and forced-colours mode.

---

## Requirements *(mandatory)*

### Presentation and Information Architecture

- **FR-006-001**: The Cost Center Manager review entry point MUST be sheet-first. A manager initially sees sheets with transactions requiring their attention, not a flat transaction table as the primary surface.
- **FR-006-002**: The navigation hierarchy MUST be: Manager Queue → Sheet → Assigned Transactions → Transaction Details → Review Decision.
- **FR-006-003**: Selecting a sheet MUST open or hand off to a sheet review workspace. The queue MUST NOT be replaced by a sheet-level decision surface.
- **FR-006-004**: The workspace MUST show only transactions within the manager's authorized review scope. Transactions outside that scope MUST NOT be rendered, counted, or inferred.
- **FR-006-005**: There MUST be no sheet-level Approve, Reject, Return, or "decide all" action. Every review decision MUST apply to one transaction.
- **FR-006-006**: The existing transaction decision types MUST remain Approve, Reject, and Return for Correction, with their existing explanation requirements and permission boundaries.
- **FR-006-007**: The management interface MUST use the established Pettycash/Purchaser visual language: the Nextcloud shell, existing navigation, typography scale, colour tokens, status badges, cards, tables, financial summaries, modal/workspace patterns, responsive layout, and focus treatment. The Manus prototype's inconsistent typography and branding MUST NOT be reproduced.
- **FR-006-008**: Desktop and mobile presentations MUST follow the approved Manus management information hierarchy while remaining inside the existing Nextcloud application shell.
- **FR-006-009**: The interface MUST remain usable in English LTR and Persian RTL at the 390px floor, with no horizontal scroll, and MUST preserve the independent Gregorian/Jalali calendar preference through the existing locale utilities.
- **FR-006-010**: The interface MUST NOT introduce Vuetify, a second application shell, a parallel design system, or a new top-level page outside the existing router.

### Data Honesty and Capability Boundaries

- **FR-006-011**: The interface MUST NOT fabricate financial amounts, sheet titles, purchaser identities, review outcomes, submission dates, completion states, or eligibility states.
- **FR-006-012**: The interface MUST NOT perform client-side sheet grouping across an unproven paginated transaction set. Grouping is permitted only when the complete manager-scoped pending set is proven in one response.
- **FR-006-013**: A queue response is proven complete only when the response's total does not exceed the number of returned items and the returned count is below the declared completeness threshold. Otherwise the interface MUST show an honest unavailable state and MUST NOT present partial counts as complete.
- **FR-006-014**: Pending amounts MUST be summed only within a single currency. Multiple currencies MUST be shown separately; no cross-currency total is permitted.
- **FR-006-015**: Sheet title, purchaser display name, sheet total, all-transaction count, submitted date, reviewed-sheet history, sheet-wide completion, and Senior Review eligibility MUST be shown as unavailable when the backend does not provide them. They MUST NOT be approximated from unrelated transaction fields.
- **FR-006-016**: Unsupported filters, counts, and actions MUST be visibly disabled with an accessible explanation, or omitted when a disabled control would imply a capability that does not exist.
- **FR-006-017**: The existing operational transaction queue and transaction review actions MUST remain reachable until a safe sheet-first replacement is fully supported.

### Workflow Truthfulness

- **FR-006-018**: The interface MUST NOT state that a sheet is eligible for Senior Review unless an authoritative backend capability says so.
- **FR-006-019**: A manager completing their own assigned transactions MUST NOT be presented as completing the sheet-wide Cost Center Review stage.
- **FR-006-020**: A rejected transaction MUST NOT be presented as automatically advanced to Senior Review.
- **FR-006-021**: A returned-for-correction transaction MUST remain presented as unresolved until the correction workflow completes.
- **FR-006-022**: The workspace MUST explain the owner-approved stage rule in user-facing language and MUST distinguish "your assignments" from "sheet-wide Cost Center Review".
- **FR-006-023**: Until the backend provides sheet-wide eligibility, the workspace MUST show an honest unavailable state for Senior Review eligibility, with the reason, and MUST NOT substitute a client-derived badge.

### Visual-First Delivery

- **FR-006-024**: Phase 1 is the Cost Center Manager Sheets Queue, desktop and mobile. It MUST be rendered inside Nextcloud and captured with fresh screenshots from the running application.
- **FR-006-025**: Phase 1 MUST stop for explicit owner visual approval. Phase 2 MUST NOT start before that approval is recorded.
- **FR-006-026**: Phase 2 is the Sheet Review Workspace, desktop and mobile, and has its own owner visual gate.
- **FR-006-027**: Senior Manager screens, Finance screens, and transaction decision redesigns MUST NOT start in this feature without a separate authorization and their applicable gates.
- **FR-006-028**: Review screenshots MUST be stored under `artifacts/visual-review/<task-id>/<iteration>/` and cited by repository-relative path.
- **FR-006-029**: Browser evidence MUST use official Google Chrome through the Playwright `chrome` channel. A missing Chrome installation is a blocker to report, not a reason to substitute another engine.

### Scope Protection and Change Control

- **FR-006-030**: This feature MUST NOT modify backend PHP source, API contracts, database schema, migrations, authorization rules, financial rules, or workflow transitions in this phase.
- **FR-006-031**: This feature MUST NOT change the existing transaction decision semantics or renumber/reopen existing canonical tasks.
- **FR-006-032**: Existing working-tree changes, including unrelated user changes, MUST be preserved.
- **FR-006-033**: The feature MUST be implemented under `specs/006-management-review/` and MUST NOT be placed inside `005-purchaser-ui-redesign`.
- **FR-006-034**: Phase 1 MUST use the existing queue endpoint and existing session scope as its evidence boundary. Any development-only visual fixture MUST be explicitly labeled as simulated, isolated from normal operation, and MUST NOT be presented as real financial data.
- **FR-006-035**: A visual result without a recorded owner approval MUST NOT be reported as accepted or complete.

### Key Entities

- **Manager Sheet Queue Item**: A sheet reference with the manager's pending transaction count, pending amount by currency, the latest transaction date present in the queue, and a derived "awaiting your decision" state. It is a presentation projection, not a persisted entity.
- **Manager Review Scope**: The cost centers for which the current user holds an active Cost Center Manager assignment, as published by the session context and enforced by the server.
- **Pending Transaction Summary**: The existing `TransactionSummary` for a transaction at the Cost Center stage within the manager's scope.
- **Sheet Review Workspace (Phase 2)**: A presentation container for one sheet reference, the manager's authorized transactions, the manager's progress, and an honest sheet-wide status statement.
- **Stage Eligibility (unavailable)**: The owner-approved sheet-wide Cost Center completion and Senior Review eligibility rule, which has no backend representation in this phase.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-006-001**: A Cost Center Manager can identify the sheets requiring their attention and the number of pending transactions per sheet from the queue, without seeing a flat transaction table as the primary surface.
- **SC-006-002**: 100% of amounts, counts, and statuses rendered in the queue are traceable to the current API response or explicitly marked unavailable; no invented value is rendered.
- **SC-006-003**: A manager's pending set that cannot be proven complete produces an honest unavailable state in 100% of tested truncation cases, with no partial sheet count presented as complete.
- **SC-006-004**: The desktop and 390px mobile queue renders with no horizontal scroll in English LTR and Persian RTL, in official Google Chrome.
- **SC-006-005**: A manager can reach the existing transaction review action from a sheet in at most two activations, and no sheet-level decision control exists.
- **SC-006-006**: The workspace never claims Senior Review eligibility from client-derived transaction states in 100% of tested state combinations.
- **SC-006-007**: Phase 1 stops at the recorded owner visual gate; no Phase 2 implementation begins before approval.

## Assumptions

- The existing `GET /queues/cost-center` response remains the authoritative manager-scoped pending transaction source in this phase.
- The existing transaction detail and decision endpoints remain authoritative and are reused unchanged.
- The manager's session scope from `GET /me` is trustworthy for disclosure and is not the authorization boundary; the server remains authoritative.
- A complete pending queue is normally small enough to fit in one high-page-size response. The completeness guard exists because this is not guaranteed.
- The existing Purchaser UI design system is the visual baseline; the Manus management screenshots define information architecture and emphasis, not typography or brand marks.
- "Reviewed" sheets and sheet-wide completion require backend capabilities that are not authorized in this phase.
- No development-only fixture is required for Phase 1 if a real manager queue can be seeded through the existing development seed command. If a fixture is used, it must be explicitly labeled simulated.
- Git writes remain unauthorized; the working tree is preserved as found.
