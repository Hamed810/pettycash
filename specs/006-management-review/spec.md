# Feature Specification: Sheet-First Management Review

**Feature Branch**: `006-management-review` (logical feature directory; the current worktree branch is `feat/pettycash-purchaser-ui-batch-a`, and no branch operation is authorized by this specification)

**Created**: 2026-10-29

**Status**: Phase 1 (Cost Center Manager Sheets Queue) delivered and awaiting owner visual approval (`T022`). Phase 2 (Sheet Review Workspace) is **planned** as the canonical Sheet review interaction — Sheet Details Modal over the mounted queue, Transactions tab with compact transaction cards, Transaction Detail Drawer on transaction-title click, compact previous-decision indicators, and a shared decision confirmation pattern — specified in the Phase 2 section below. Phase 2 MUST NOT be implemented until the Phase 1 gate (`T022`) and the Phase 2 visual gate are recorded. The manager-scoped backend capability (`P4`) is a **required product capability**, not an optional enhancement: it is planned as the Phase 2 backend batch (Batch 1) with its own backend tasks, and is NO LONGER deferred. Because it changes backend/API-contract/authorization surfaces, it remains subject to a separate backend-change authorization before implementation (see §2.4); it is no longer described as "not part of Phase 2". The known Cost Center queue duplicate/pagination defect is tracked as a **separate bug-workflow item** (`/speckit-bug-*`) and is not a Feature 006 task.

**Owner-approved Management Review v2 baseline (2026-11-21; Manus Management Review v2 — `docs/frontend-redesign/proposals/management-review-v2/`, visual references only; these specifications remain the authoritative executable requirements):** inside the Sheet Details Modal the canonical transaction-detail interaction is the **Inline Expanded Transaction** in the Transactions tab, superseding the Transaction Detail Drawer (`FR-006-040`/`FR-006-041`); the transaction card's review-progress presentation is the compact **stage identity + state marker** contract (`FR-006-039`); stable modal geometry (`FR-006-049`), persistent card-level reviewer actions (`FR-006-050`), the readable typography floor (`FR-006-051`), and the expansion focus lifecycle (`FR-006-052`) apply. A same-day v3 alignment (`Review Ledger` presentation) was **superseded by this owner decision before any implementation** — no v3 requirement was ever implemented. This is a frontend presentation requirement change only — no workflow, authorization, backend, API, contract, persistence, or financial-semantics change. Implementation is planned as the `UXC-V2-*` phase in `specs/009-finance-manager-expense-review/ux-convergence-tasks.md`; no implementation task is authorized or complete.

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

**Phase 2 (next, after Phase 1 approval): Cost Center Manager — Sheet Review Workspace.** Planned in this specification and task ledger, but not implemented before the Phase 1 gate is recorded. The approved form of the workspace is the **canonical Sheet review interaction**: selecting a sheet opens the Sheet Details Modal over the mounted queue; the modal exposes a **Transactions** tab rendering **compact transaction cards**; activating a transaction opens the **Inline Expanded Transaction** in place inside the modal (no route navigation, no second full modal; exactly one transaction expanded at a time); each card shows the compact **stage identity + state marker** review-progress presentation for Cost Center, Senior Manager, and Finance Manager plus its server-permitted review actions; and the modal's **Approvals** tab shows sheet-level approved amounts per stage — per the 2026-11-20 owner authorization (`§2.5`), these are the server-computed whole-Cost-Sheet stage-approved totals when the payload carries them, with honest unavailable states otherwise. The preserved `review-detail` route stays reachable for deep links (`FR-006-017`). Full detail in the Phase 2 section below.

**Out of scope for both phases:** Senior Manager screens, Finance screens, transaction decision redesign, sheet-level approval, new workflow actions, and any change to an existing public contract. **Backend changes** are out of scope for Phase 1; for Phase 2 the only planned backend change is the **additive manager-scoped read capability** (`§2.4`, Batch 1), which remains separately authorized. The manager-scoped capability is not broader than the authorization boundaries described in `§2.4`.

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

**No backend behavior is changed without separate authorization.** The conflicts are recorded here so they are not silently preserved or silently redefined in code. Phase 2 Batch 1 plans the manager-scoped read capability (`§2.4`) without resolving these sheet-wide/senior-eligibility conflicts; resolving those still requires a separate owner authorization, an amendment to 001, and separate backend work. In particular, Batch 1 MUST NOT derive whole-sheet Cost Center completion or Senior eligibility from a manager's own decisions (`FR-006-047/048`).

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

A manager opens a sheet and sees the sheet identity, the manager's own review progress, the manager's authorized transactions, and an honest statement of sheet-wide Cost Center status. Selecting a sheet opens the **Sheet Details Modal** over the mounted queue (no route navigation). The modal's **Transactions** tab renders **compact transaction cards** for the manager's authorized transactions. Activating a transaction's expansion control opens the **Inline Expanded Transaction** in place within the Transactions tab, preserving Sheet context: Transaction Details, Evidence, Previous Decisions (Cost Center / Senior Manager / Finance Manager), and Current Stage Actions. Only one transaction is expanded at a time; opening another transaction collapses the previously expanded one. Review decisions are made on individual transactions. The workspace never offers a sheet-level decision and never shows a transaction outside the manager's scope.

**Why this priority**: This is the second visual milestone. It depends on the queue's navigation and must not be implemented before the first gate is approved.

**Independent Test**: Open a sheet from the queue, verify the Sheet Details Modal opens over the mounted queue, that only the manager's authorized transactions appear as compact cards, that each transaction expands in place into the Inline Expanded Transaction, that no sheet-level Approve/Reject exists, and that sheet-wide completion/eligibility is shown as unavailable when the backend cannot prove it.

**Acceptance Scenarios**:

1. **Given** a sheet with transactions in the manager's scope, **When** the workspace opens, **Then** it shows the Sheet Details Modal over the mounted queue, the sheet reference, the manager's pending count and amount, and only those transactions as compact cards.
2. **Given** a sheet where the manager has completed their own assignments, **When** the workspace renders, **Then** it states that the manager's assignments are complete without claiming the sheet-wide Cost Center stage is complete.
3. **Given** a sheet whose backend cannot prove sheet-wide completion, **When** the workspace renders, **Then** Senior Review eligibility is shown as unavailable, not "Pending", "Complete", or "Eligible".
4. **Given** a transaction in the workspace, **When** the manager activates the transaction's expansion control, **Then** the Inline Expanded Transaction opens in place within the Transactions tab preserving Sheet context, showing Transaction Details, Evidence, Previous Decisions, and Current Stage Actions; the existing transaction review decision actions remain the only decision actions; and opening another transaction collapses this expansion.
5. **Given** the workspace at 390px in Persian RTL, **When** it renders, **Then** there is no horizontal scroll and exactly one dialog layer is present.
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
- **FR-006-002**: The navigation hierarchy MUST be: Manager Queue → Sheet Details Modal → Transactions Tab → Compact Transaction Cards → Inline Expanded Transaction → Review Decision. Selecting a sheet MUST open the Sheet Details Modal over the mounted queue; the queue MUST NOT be replaced by a sheet-level decision surface.
- **FR-006-003**: Selecting a sheet MUST open the Sheet Details Modal over the mounted queue. The modal MUST expose the canonical tabs: Transactions, Approvals, Overview, History. The queue MUST NOT be replaced by a sheet-level decision surface.
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

- **FR-006-030**: In Phase 1, this feature MUST NOT modify backend PHP source, API contracts, database schema, migrations, authorization rules, financial rules, or workflow transitions. In Phase 2, the only permitted backend change is the additive manager-scoped read capability (`§2.4`, Batch 1): no new schema/migration, no change to financial rules, decision semantics, or workflow transitions, and no broadening of authorization.
- **FR-006-031**: This feature MUST NOT change the existing transaction decision semantics or renumber/reopen existing canonical tasks.
- **FR-006-032**: Existing working-tree changes, including unrelated user changes, MUST be preserved.
- **FR-006-033**: The feature MUST be implemented under `specs/006-management-review/` and MUST NOT be placed inside `005-purchaser-ui-redesign`.
- **FR-006-034**: Phase 1 MUST use the existing queue endpoint and existing session scope as its evidence boundary. Any development-only visual fixture MUST be explicitly labeled as simulated, isolated from normal operation, and MUST NOT be presented as real financial data.
- **FR-006-035**: A visual result without a recorded owner approval MUST NOT be reported as accepted or complete.

### Key Entities

- **Manager Sheet Queue Item**: A sheet reference with the manager's pending transaction count, pending amount by currency, the latest transaction date present in the queue, and a derived "awaiting your decision" state. It is a presentation projection, not a persisted entity.
- **Manager Review Scope**: The cost centers for which the current user holds an active Cost Center Manager assignment, as published by the session context and enforced by the server.
- **Pending Transaction Summary**: The existing `TransactionSummary` for a transaction at the Cost Center stage within the manager's scope.
- **Sheet Review Workspace (Phase 2)**: A presentation container opened as the Sheet Details Modal over the mounted queue, exposing the canonical tabs (Transactions, Approvals, Overview, History), the manager's authorized transactions as compact cards, the Inline Expanded Transaction on transaction activation, the compact stage identity + state marker review-progress presentation, persistent card-level reviewer actions, sheet-level Approvals, and an honest sheet-wide status statement.
- **Inline Expanded Transaction**: A transaction detail region opened in place inside the Transactions tab on transaction activation (UX v3, 2026-11-21 — supersedes the former Transaction Detail Drawer entity), preserving Sheet context, with canonical sections: Transaction Details, Evidence, Previous Decisions, and Current Stage Actions. It never navigates away from the Sheet, never opens a second full modal over the Sheet modal, exactly one transaction is expanded at a time, and Evidence Preview and Decision Confirmation remain lightweight overlays above the Sheet modal. The `TransactionDetailDrawer.vue` implementation is retired only after the migration proves no remaining dependency (convergence ledger `UXC-V3-13`).
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
- "Reviewed" sheets and sheet-wide completion require backend capabilities that are not authorized in Phase 1. In Phase 2, the **manager-scoped** read (title/purchaser/submitted/decided-set/manager-progress) is planned as Batch 1 (`§2.4`); sheet-wide completion / Senior eligibility remain unauthorized there too.
- No development-only fixture is required for Phase 1 if a real manager queue can be seeded through the existing development seed command. If a fixture is used, it must be explicitly labeled simulated.
- Git writes remain unauthorized; the working tree is preserved as found.

---

## Phase 2 — Sheet Review Workspace (Canonical Sheet UX)

**Status**: Planned. The Sheet review interaction is the canonical shared management-review UX: Sheet Details Modal over the mounted queue, Transactions tab with compact transaction cards, the Inline Expanded Transaction on transaction activation (2026-11-21 — supersedes the former Transaction Detail Drawer interaction), the compact stage identity + state marker review-progress presentation, a Sheet-level Approvals tab showing approved amounts per stage, and a shared decision confirmation pattern. No Phase 2 task is authorized to start before the Phase 1 gate (`T022`) and the Phase 2 visual gate are recorded (`FR-006-025/026`).

### 2.1 Canonical Sheet review interaction

The Phase 2 workspace is the Sheet Details Modal opened over the mounted queue. The queue stays mounted behind the modal; opening the modal does not navigate away from the queue.

1. **Sheet Details Modal.** Selecting a sheet opens the modal over the mounted queue. The modal preserves the established Sheet structure: title/reference, status, owner where available, currency, first submitted date where available, transaction count, recorded amount, latest approved amount or equivalent authoritative summary, and tabs.
2. **Canonical tabs:** Transactions, Approvals, Overview, History.
3. **Transactions tab — Compact Transaction Cards.** Each authorized transaction renders as a compact card exposing, without expansion: transaction title/description, amount + currency, **transaction date** (from the existing authoritative expense-date source, `TransactionSummary.activeRevision.expenseDate` — no new field), transaction lifecycle status, compact metadata, cost center where relevant, evidence summary, the compact **stage identity + state marker** review-progress presentation (`FR-006-039`, owner-approved v2 baseline), and all server-permitted current-stage review actions in place (`FR-006-050`). The review-progress presentation covers exactly the three workflow stages Cost Center → Senior Manager → Finance Manager, each with a restrained stage identity and a separate decision-state marker; states remain Approved / Rejected / Returned / No decision yet; it is informational and read-only, never colour-alone, never generic traffic-light semantics, and never hover-dependent. A workflow stage is NOT hidden merely because it has no decision yet. Activating a transaction opens its Inline Expanded Transaction in place (`FR-006-040`).
4. **Inline Expanded Transaction.** Activating a transaction opens its expanded details in place within the Transactions tab (v2 baseline, 2026-11-21): no route navigation away from the Sheet and no second full modal over the Sheet modal; exactly one transaction expanded at a time; the expanded region stays visually connected to the surrounding sheet transaction list. The expansion preserves Sheet context with canonical sections: Transaction Details (description/title, expense date, amount, currency, expense type, cost center, transaction reference, current transaction status, other existing authoritative fields), Evidence (thumbnails/list, image preview, PDF preview, metadata, download fallback — reusing the established evidence interaction, no new mutation capability), Previous Decisions (Cost Center / Senior Manager / Finance Manager shown separately, each with decision, actor, date/time, comment/explanation; `No decision yet` when none exists; decisions are NOT collapsed into one generic approval status), and Current Stage Actions (only actions permitted to the current user/stage; the shared UX does not widen permitted actions). Evidence Preview and Decision Confirmation remain lightweight overlays above the Sheet modal. On desktop the expansion may use a two-column internal arrangement where appropriate; at 390px it uses the deliberate vertical flow details → evidence → history → actions, without unnecessary nested panels.
5. **Approvals tab — Sheet-level aggregate.** Shows the approved monetary amount at each workflow stage (Cost Center approved amount, Senior Manager approved amount, Finance Manager approved amount) using authoritative stage totals. It does not duplicate detailed per-transaction decision history here; per-transaction decision history lives in Previous Decisions inside the transaction's Inline Expanded Transaction.
6. **Decision confirmation.** Every decision opens the same confirmation modal with per-kind copy/warning, per-kind note rules (Reject and Return require an explanation, Approve never does, `FR-033`), a submitting state (controls disabled, scrim no-close, first-write-wins `FR-085`), an error state for a reject/return without a note, and a success path that re-renders the card in its decided state plus a toast.
7. **Evidence reuse.** Document preview reuses `001` `FR-060` + `005` `FR-005-042` verbatim (inline-safe closed set, download-only fallback, accessible dialog name = file name, Escape close, scrim close, object-URL revocation, single dialog) and adds Previous/Next navigation across the transaction's documents with a `1 / N` counter, disabling at the ends.
8. **Round-trip of every decision is transaction-level** (`FR-006-005`, `FR-033`). One decision per post; no sheet-level action; no sheet-level Approve/Reject/Return or "decide all".

### 2.2 Phase 2 capability classification (verified against current backend)

| Approved surface | Classification | Evidence (backend source of truth) | Phase 2 rendering |
|---|---|---|---|
| Sheet Details Modal over mounted queue | **Frontend adaptation** | `GET /queues/cost-center` returns `TransactionSummary[]` with `sheetId`; grouping `useSheetQueue.buildSheetQueue` | Modal over mounted queue |
| Compact transaction card connected fields (id, amount, currency, status, cost center, description, evidence) | **Supported** | `TransactionPayloadFormatter::summary` (`lib/Infrastructure/Http/TransactionPayloadFormatter.php:49-91`) | Render |
| Card meta merchant / paid-on / payment method / category | **Backend change required** (NOT in Batch 1 scope) | Not in `Revision` payload (`TransactionPayloadFormatter::revisionFrom`, `:156-166`) | Unavailable placeholder; not part of the required-capability batches |
| Compact previous-decision indicators (Cost Center / Senior Manager / Finance Manager) | **Supported** | `TransactionDetail.decisions[]` carries stage-tagged decisions; `TransactionPayloadFormatter::decision` (`:430-454`) | Render per card (collapsed and expanded) as the compact stage identity + state marker presentation |
| Transaction detail surface (Inline Expanded Transaction, v2 baseline): Transaction Details / Evidence / Previous Decisions / Current Stage Actions | **Partial**: connected (amount, sheet ref, cost center, status, description, attachments) vs required-backend (merchant/paid-on/method/category) and required capability (purchaser, sheet title/submitted once B001/B004 land) | `GET /transactions/{id}` role-scoped read `TransactionController::show` via `visibleTransaction` (`TransactionController.php:107-127`) | Connected fields real; merchant/paid-on/method/category placeholders; purchaser/sheet-title/submitted reconnect with Batch 1 |
| Sheet-level Approvals tab (approved amount per stage) | **Required backend capability** (planned, B001/B004) | `SheetService::getSheet` is owner-scoped (`SheetService.php:101-117`) — manager has no sheet read; `ExpenseSheet` carries `title`, `ownerUid`, `firstSubmittedAt`, `submittedTotalMinor` (`ExpenseSheet.php:20-37`, `:117`) | Unavailable until Batch 1; connect once B001/B004 land |
| Decided / returned cards inside the Transactions tab | **Required backend capability** (planned, B002/B003) | `getCostCenterQueue` filters `WorkflowStage::CostCenter` (`ManagementReviewService.php:364-415`); `WorkflowStateMachine::next` maps Approve/Reject → Senior, Return → Correction (`lib/Domain/Workflow/WorkflowStateMachine.php:4`) — decided transactions are **not** returned | Pending-only until Batch 1 lands; decided/returned cards connect once B002/B003 exist |
| Document thumbnails + preview overlay with cross-doc nav | **Partial reuse** | `GET /evidence/{id}/content` inline-safe policy (`EvidenceContentPolicy`, contract `:762-817`); frontend `EvidencePreview.vue` overlay exists | Reuse overlay; add Previous/Next + `1 / N` |
| Decision confirmation modal | **Supported** | `POST /transactions/{id}/decisions` (`reviewsApi.recordDecision`), first-write-wins FR-085; `DecisionForm.vue` `requiresExplanation` | Shared confirmation interaction |
| Sheet title, purchaser, sheet total, submitted date | **Required backend capability** (planned, B001/B004) | `SheetService::getSheet` is owner-scoped (`SheetService.php:101-117`) — manager has no sheet read; `ExpenseSheet` carries `title`, `ownerUid`, `firstSubmittedAt`, `submittedTotalMinor` (`ExpenseSheet.php:20-37`, `:117`) | Unavailable until Batch 1; connect once B001/B004 land |
| Sheet-wide completion, Senior eligibility | **Backend change required** (separately gated; NOT produced by Batch 1) | No sheet-wide gate exists (`WorkflowStateMachine.php`) | Unavailable — explained; Batch 1 must NOT derive it from one manager's decisions |
| Whole-sheet or bulk decisions | **Unavailable by policy** | `ReviewController::refuseBulkDecision` (`ReviewController.php:278-318`) | Never rendered (`FR-006-005`) |

### 2.3 Phase 2 requirements (new, additive — existing FR-006-001…035 unchanged)

- **FR-006-036**: Selecting a sheet row in the Phase 1 queue MUST open the Sheet Details Modal over the mounted queue; the queue must remain mounted behind the modal and MUST NOT be unmounted or replaced by a sheet-level decision surface.
- **FR-006-037**: The Sheet Details Modal MUST expose the canonical tabs: Transactions, Approvals, Overview, History. Not every role needs mutation capability in every tab.
- **FR-006-038**: The Transactions tab MUST render each authorized transaction as a compact card with connected id, amount, currency, **transaction date** (the existing authoritative `TransactionSummary.activeRevision.expenseDate` — mandatory first-view information; no new field is invented), status, cost-centre scope and description; merchant, paid-on, payment method and category remain unavailable placeholders (not in the required-capability scope). Sheet title, purchaser identity/display name, and submitted date connect once the Batch 1 backend capability (B001/B004) lands. Cards MUST NOT invent a value. The collapsed card's evidence summary shows the evidence count, one or two real image thumbnails when available, a PDF/file representation when applicable, and `+N` for additional evidence; richer evidence information and the existing preview/download behavior live in the Inline Expanded Transaction (`FR-006-041`/`FR-006-042`); no evidence upload/replace/delete control appears on any reviewer surface.
- **FR-006-039** (owner-approved Management Review v2 baseline, 2026-11-21 — supersedes the same-day icon-only chip refinement and the briefly aligned v3 Review Ledger, neither of which was implemented): Each compact transaction card MUST present its review progress as the compact **stage identity + state marker** presentation: exactly the three workflow stages Cost Center → Senior Manager → Finance Manager in fixed workflow order, one entry per stage, where each stage has (a) a restrained **stage identity** — identity icon/mark plus stage label, visible on desktop without hover — and (b) a **separate state marker**: decision state is represented independently of and visually distinct from stage identity. Each state marker MUST have sufficient visual prominence for fast transaction scanning. For Approved in particular: the check glyph MUST be clearly visible, and its foreground, background, and border contrast MUST be stronger than the current implementation while the marker remains compact — the stage row MUST NOT grow into a large panel. Meaning MUST NOT depend on color alone, and the accessibility requirements (text labels, focus treatment, RTL, forced-colours) remain applicable to all states: Approved, Rejected, Returned, and No decision yet. A generic traffic-light visual treatment MUST NOT be introduced. The states remain Approved, Rejected, Returned, and No decision yet, and no state may be hidden because it is undecided. The presentation MUST be compact and visually light — substantially less vertical space than the superseded v3 ledger treatment — and compact enough for scanning many transaction rows; large equal-height stage panels MUST NOT be prescribed. On narrow viewports the stage identity may compact, but understanding a stage MUST NOT depend on hover: accessible labels remain available through text and focus treatment. Meaning MUST NOT depend on color alone; semantic color MUST be restrained; a generic traffic-light appearance MUST NOT be used. The presentation is informational and read-only — NOT checkboxes, NOT buttons, NOT actionable — and MUST work in RTL. The same compact review-progress component is used in both the collapsed and the expanded transaction states. Full per-stage decision detail (stage, decision, actor, timestamp, comment/explanation) remains in the Inline Expanded Transaction (`FR-006-041`).
- **FR-006-040** (interaction reworded 2026-11-21, owner-approved; retained under the Manus v2 baseline): Activating a transaction MUST open its **Inline Expanded Transaction** in place within the Transactions tab — the canonical interaction is `TransactionReviewCard → Inline Expanded Transaction`, replacing the former Transaction Detail Drawer. The expansion MUST NOT navigate away from the Sheet, MUST NOT open a second full modal over the Sheet modal, MUST stay visually connected to the surrounding sheet transaction list, and exactly one transaction MAY be expanded at a time: opening another transaction collapses the previously expanded one. Evidence Preview and Decision Confirmation remain lightweight overlays above the Sheet modal.
- **FR-006-041** (content preserved from the former drawer requirement; presentation moved to the inline expansion, 2026-11-21): The Inline Expanded Transaction MUST contain the canonical sections formerly specified for the Transaction Detail Drawer: Transaction Details (description/title, expense date, amount, currency, expense type, cost center, transaction reference, current transaction status, other existing authoritative fields), Evidence (thumbnails/list, image preview, PDF preview, metadata, download fallback — reusing `001` `FR-060` + `005` `FR-005-042` verbatim plus Previous/Next with a `1 / N` counter), Previous Decisions (Cost Center / Senior Manager / Finance Manager shown separately, each with decision, actor, date/time, comment/explanation; `No decision yet` when none exists; never collapsed into one generic approval status), and Current Stage Actions (only actions permitted to the current user/stage). Information already visible in the collapsed transaction card MUST NOT be repeated in the expanded transaction detail. The card owns, as first-view information: transaction title; amount and currency; expense date; lifecycle/stage presentation; transaction identifier; cost center; expense type; review stage markers; evidence summary; and reviewer actions. The expanded detail MUST primarily contain complementary information not already visible above — where supplied by the server: purchaser; submitted timestamp; merchant; paid-on value; payment method; detailed evidence; previous decisions; and additional contextual fields not already visible in the card. A second amount/status summary block restating the card MUST NOT be introduced. Transaction ID, expense type, date, and other first-view metadata MUST NOT be repeated unless a concrete accessibility or contextual requirement makes repetition necessary. The expanded surface MUST be materially lighter and shorter than the card's first view while preserving the mobile semantic flow details → evidence → history → actions. On desktop the expansion MAY use a two-column internal arrangement where appropriate; at 390px it MUST follow the deliberate vertical flow details → evidence → history → actions, and unnecessary nested panels MUST be avoided.
- **FR-006-042**: Evidence rendering reuses `001` `FR-060` and `005` `FR-005-042` verbatim (inline-safe set, download-only fallback, accessible dialog name, Escape/scrim close, object-URL revocation) and adds Previous/Next navigation across the transaction's documents with a `1 / N` counter, disabling at the ends. No new evidence mutation capability.
- **FR-006-043**: The Approvals tab MUST show the approved monetary amount at each workflow stage (Cost Center approved amount, Senior Manager approved amount, Finance Manager approved amount) using authoritative stage totals. It MUST NOT duplicate detailed per-transaction decision history; per-transaction decision history lives in Previous Decisions inside the transaction's Inline Expanded Transaction.
- **FR-006-044**: Every decision — from a transaction card (collapsed or expanded) — MUST open the same confirmation modal with per-kind copy/warning, per-kind note rules (Reject and Return require an explanation, Approve never does, `FR-033`), a submitting state (controls disabled, scrim no-close, first-write-wins `FR-085`), an error state for a reject/return without a note, and a success path that re-renders the card in its decided state plus a toast. Both presentation surfaces share one workflow.
- **FR-006-045**: On mobile the modal, the inline expanded transaction, and decision confirmation MUST honour the 390px floor, full-screen overlays one at a time, `≥44px` touch targets, and RTL-mirrored layout, with no horizontal scroll, in English LTR and Persian RTL. Layout MUST use logical CSS properties; identifiers and currency codes MUST remain direction-isolated where currently required; and the canonical workflow order (Cost Center → Senior Manager → Finance Manager) MUST remain preserved semantically.
- **FR-006-046**: The modal MUST NOT render a sheet title, purchaser, sheet total, submitted date, or merchant/paid-on/method/category as real — every not-yet-connected field shows its unavailable state or is absent — until the Batch 1 backend capability connects it; once B001/B002/B003/B004 land those surfaces connect (`FR-011/015` honesty applies to every state). `FR-006-046` is satisfied only when the phase renders `0%` fabricated values in every state.
- **FR-006-047**: The interface and its backend MUST keep distinct transactions awaiting the current manager's decision, transactions already decided by that manager, transactions returned for correction, completion of the manager's own responsibilities, completion of the entire Cost Center stage, and Senior Review eligibility; none may be conflated or presented as another.
- **FR-006-048**: The interface and its backend MUST NOT infer whole-sheet Cost Center completion or Senior Review eligibility from one manager's decisions, and MUST NOT invent history records or derive totals from pending-only data; every count/status traces to the manager's authorized scope and the server's proven state.
- **FR-006-049** (owner-approved, 2026-11-21; retained under the approved Manus v2 baseline): The Sheet Details Modal MUST present **stable geometry** across tabs and tab switching: stable modal width; stable usable content height; stable header; stable summary/header area; stable tab bar. Only the internal tab-content region scrolls; a sparse tab MUST retain intentional whitespace instead of collapsing or resizing the modal. The same geometry stability applies at the 390px viewport.
- **FR-006-050** (owner-approved, 2026-11-21; retained under the approved Manus v2 baseline): All three review actions — Approve, Return, Reject — MUST be directly visible on the collapsed transaction card whenever permitted by the server-authoritative capability rules (`allowedActions`/stage capability), and MUST NOT be hidden behind expansion or an overflow menu. Visual hierarchy: Approve is primary; Return is warning/correction; Reject is restrained destructive. Permitted-action gating, per-role restrictions (e.g. the Finance senior-rejected Approve restriction), decision types, and the Decision Confirmation behavior and explanation rules are unchanged (`FR-006-005`/`FR-006-006`/`FR-006-044`).
- **FR-006-051** (owner-approved, 2026-11-21; retained under the approved Manus v2 baseline): User-facing content on these surfaces MUST NOT depend on sub-16px text for density, preserving the enforced readable typography floor (`001` `FR-071`, including the established `--font-size-floor` token usage). Target hierarchy: base body/metadata ≥16px; transaction title ≈18px; amount ≈18–20px; sheet title ≈22–24px; button text ≥16px; state/status text ≥16px. Density MUST be achieved through spacing, grouping, hierarchy, and reduced visual chrome — not smaller text. The v2 mockup's small illustrative text MUST NOT be reproduced as sub-floor production typography (the design files are visual references only; this floor and `001` `FR-071` remain authoritative).
- **FR-006-052** (owner-approved, 2026-11-21; retained under the approved Manus v2 baseline): The inline expansion MUST keep an accessible disclosure lifecycle: the expand/collapse control exposes its state (`aria-expanded`/`aria-controls` semantics); expanding moves focus sensibly into the expanded region, collapsing returns focus to the expansion control, and the Evidence Preview and Decision Confirmation overlays restore focus into the expanded transaction on close. The Sheet Details Modal's own focus lifecycle (trap, Escape, close, focus restoration to the originating sheet row) is unchanged.
- **FR-006-053** (owner refinement UXC-V2-FU-03): Long sheet and transaction identifiers MUST use compact visual presentation, with the preferred truncated form `25ccedd9…145b` (or an equivalent repository-consistent truncation). The displayed value MUST remain recognizably derived from the true identifier; the underlying identifier is unchanged. The full value MUST remain available through an accessible disclosure mechanism — a tooltip or equivalent accessible compact-detail mechanism is acceptable — but hover MUST NOT be the only access path: keyboard focus MUST expose the complete value, and screen-reader users MUST have access to the complete identifier. Identifiers MUST remain direction-isolated in RTL, and the complete value MUST remain available for operational and debugging use. The same compact-identifier contract applies consistently to Management Review queue tables, transaction cards, inline details where an identifier is genuinely required, and modal headers/metadata where appropriate. A new dependency MUST NOT be introduced solely for this behavior. Sheet title and sheet reference remain distinct concepts and MUST NOT be visually concatenated into one identifier string.
- **FR-006-054** (owner refinement UXC-V2-FU-05): Management Review queue tables across Cost Center, Senior Manager, and Finance Manager converge on a shared base column model. The shared base columns are: Sheet, Purchaser, Transactions, Amount, Status, Submitted. Equivalent terminology MUST be used across the three roles; unnecessary role-specific synonyms such as `Pending`, `Latest activity`, and `Review status` MUST NOT be used when the underlying concept corresponds to one of the shared base columns. Business meaning MUST NOT be falsified merely to make labels identical: where scope differs by role, Cost Center MAY retain scope qualifiers such as `Your transactions` or `Your relevant amount` (its figures represent only the current manager's assigned scope), while Senior Manager and Finance Manager use the same base vocabulary without `Your` where values represent the complete sheet/stage scope. Same concept → same label; different semantics → minimal, explicit qualifier. The shared visual structure aligns header typography, sortable-column treatment, cell spacing, sheet-title/reference hierarchy, purchaser presentation/avatar treatment, amount alignment, status badge placement, submitted/date presentation, and compact identifier treatment (FR-006-053). No new backend fields are required: if Purchaser or Submitted data already exists in the current sheet representation used by a role, expose it consistently; if an exact field is genuinely unavailable from the existing API, document that fact in the implementation task rather than changing backend scope.
- **FR-006-055** (owner refinement UXC-V2-FU-06): Management Review queue pages establish a table-first review workspace for all three roles. The header region keeps essential context only — breadcrumb, compact eyebrow/stage identity where useful, page title, a primary page action such as Refresh, and an export action where role-specific and required — and explanatory paragraphs are shortened to one concise line or removed when the page title/table state already communicates the same meaning; workflow documentation MUST NOT be repeated in the main working viewport. Large instructional panels such as `Your responsibility in this stage` or `Focused on your responsibility` MUST NOT consume substantial permanent vertical space when their message is already obvious from page context; they are removed, reduced to a compact inline notice, or collapsed into a short contextual sentence. Large KPI cards MUST NOT dominate the queue page; statistics such as sheets awaiting decision, transactions in queue, sheets needing attention, and transactions to review MAY be retained but presented compactly — compact inline counters, small summary chips, one low-height summary row, or counts integrated near queue filters/tabs — not two large dashboard-style cards above the table. At normal desktop viewport heights, the queue header and controls MUST be compact enough that several table rows are visible without initial page scrolling, and the table MUST begin materially higher than in the current Senior implementation. Search, filter, sort, and role-state controls remain accessible and compact, without reducing usability or minimum control sizes merely to save space. Cost Center, Senior Manager, and Finance Manager queue pages share comparable vertical rhythm, comparable table start position, comparable search/filter density, and the shared table vocabulary (FR-006-054); role-specific actions and status filters MAY remain, and controls are not forced to be identical where role workflows genuinely differ.

- **FR-006-056** (UXC-V2-FU-07 — canonical queue status contract): For the current reviewer, a sheet queue item's Status column MUST use exactly two primary values: `Awaiting your decision` (when at least one current active transaction still requires a decision from this reviewer) and `Review completed` (when every current active transaction revision has a terminal Approved or Rejected decision for this required stage, i.e. the stage is fully resolved under the existing workflow rules). The Status column MUST NOT encode the role/stage name, transaction counts, percentage/progress, generic phrases such as `Needs attention`, compound phrases such as `N of N awaiting your decision`, or role-specific status synonyms such as `In progress`, `Senior Review`, or `Finance Review`. Localization MUST support EN and FA.

- **FR-006-057** (UXC-V2-FU-07 — returned-transaction secondary context): When one or more returned-for-correction transactions are currently back at this reviewer and require a decision, display secondary context `1 returned transaction` (count = 1) or `N returned transactions` (count > 1) alongside the primary status. Hide this indicator when the count is 0. It never replaces the primary status and is never a third primary sheet status. Localization MUST support EN and FA.

- **FR-006-058** (UXC-V2-FU-08-D — compact summary counter non-wrapping): Summary/KPI numbers MUST never wrap digit-by-digit. Compact summary counters MUST render as one uninterrupted numeric value. This is a presentation requirement, not a change to calculations.

### 2.4 Required manager-scoped backend capability (`P4` — planned, Batch 1)

The owner directive is adopted as a **required product capability** and planned as the Phase 2 backend batch (Batch 1). It is no longer deferred. Until it is implemented and its separate backend-change authorization is recorded, the affected surfaces render unavailable/absent (`FR-006-011/015`); when it lands they connect without changing the information architecture.

**In scope (required)**: real sheet titles and purchaser identity/display name, genuine submission dates, authorized manager-scoped sheet metadata, previously-decided transactions belonging to the manager's review scope, and accurate manager-specific review progress — reusing existing services (`ExpenseSheetRepository`, `RoleAssignmentRepository`, `UserDirectory`, audit events, `TransactionPayloadFormatter`/`SheetPayloadFormatter`).

**Authorization rule (mandatory, `FR-006-004`/`FR-006-011`)**: manager-scoped reads are authorized **only** to the cost centers for which the current user holds an active Cost Center Manager assignment (`RoleAssignmentRepository::findActiveByUserAndRole`, same source as `ManagementReviewService::getCostCenterQueue`). This never broadens manager entitlement, never exposes a transaction or sheet outside the managed scope, and never crosses into Senior/Finance surfaces.

**Workflow-correctness rule (mandatory)**: the backend MUST keep distinct (a) transactions awaiting this manager's decision, (b) transactions already decided by this manager, (c) transactions returned for correction, (d) completion of this manager's own responsibilities, (e) completion of the entire Cost Center stage, (f) Senior Review eligibility. It MUST NOT infer (e) or (f) from (d) or from pending-only data, and MUST NOT invent history records or derive totals from an incomplete set.

**Explicitly out of scope (unchanged)**: merchant/paid-on/payment-method/category fields, the sheet-wide-completion/Senior-eligibility gate (remains a separately gated backend change — see Workflow Reconciliation §), and any change to decision semantics, financial rules, or public contract beyond the additive manager-scoped read surface.

### 2.5 Owner authorization (2026-11-20): whole-sheet stage-approved aggregate disclosure

**Owner decision, recorded 2026-11-20.** For a Cost Sheet already legitimately visible through the Cost Center review surface (i.e., served by `GET /queues/cost-center/sheets/{sheetId}` under its existing scope gates), the reviewer is authorized to see three **whole-Cost-Sheet** aggregate monetary totals — Cost Center approved, Senior Manager approved, Finance Manager approved — per the sheet's own single currency. The authorization permits **aggregate monetary disclosure only**:

- It does **not** authorize rendering or exposing out-of-scope transactions, their evidence, actors, comments, or individual decision histories. `FR-006-004` continues to govern the transaction list, and `FR-006-047/048` continue to govern completion/eligibility claims.
- The aggregates are computed **server-side from the sheet's complete transaction set** by the existing domain computation (`lib/Domain/Expense/SheetTotals.php` over `lib/Infrastructure/Persistence/Expense/SheetSummaryQuery::totalsForSheet()`). A transaction contributes to a stage total only when that stage's authoritative decision on the **current active revision** is Approved; Rejected, Returned, undecided, and superseded-revision approvals do not contribute. Reuse is mandatory — no second aggregation rule, and no client-side derivation from actor-scoped transaction collections.
- The canonical **Approvals** tab uses these server-authoritative whole-sheet totals when the payload carries them; when the field is absent or the read failed, the tab keeps its honest unavailable/known-zero states. Aggregate amounts are presentation only: they never alter decision workflow, authorization, posting, settlement, account balances, or persistence.
- Transaction-level **Previous Decisions** remain inside the transaction's Inline Expanded Transaction, scoped to the transactions the reviewer may already read, stage-separated, and strictly separate from the sheet-level Approvals tab.
- Contract vehicle: the additive `StageApprovedTotals` schema on `ManagerSheetSummary` (`specs/001-pettycash-v1-core/contracts/ocs-api.yaml`), applied to `GET /queues/cost-center/sheets/{sheetId}`. Implementation is planned as follow-up task `UXC-7F-02` in `specs/009-finance-manager-expense-review/ux-convergence-tasks.md` (contract alignment `UXC-7F-01` precedes it).
