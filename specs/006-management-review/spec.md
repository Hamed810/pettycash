# Feature Specification: Sheet-First Management Review

**Feature Branch**: `006-management-review` (logical feature directory; the current worktree branch is `feat/pettycash-purchaser-ui-batch-a`, and no branch operation is authorized by this specification)

**Created**: 2026-10-29

**Status**: Phase 1 (Cost Center Manager Sheets Queue) delivered and awaiting owner visual approval (`T022`). Phase 2 (Sheet Review Workspace) is **planned** as the **inline quick review** approved by the owner on 2026-11-02 (`docs/frontend-redesign/proposals/006-management-review-quick-review/UX_SPEC.md`) and specified in the Phase 2 section below. Phase 2 MUST NOT be implemented until the Phase 1 gate (`T022`) and the Phase 2 visual gate are recorded. The manager-scoped backend capability (`P4`) is a **required product capability**, not an optional enhancement: it is planned as the Phase 2 backend batch (Batch 1) with its own backend tasks, and is NO LONGER deferred. Because it changes backend/API-contract/authorization surfaces, it remains subject to a separate backend-change authorization before implementation (see §2.4); it is no longer described as "not part of Phase 2". The known Cost Center queue duplicate/pagination defect is tracked as a **separate bug-workflow item** (`/speckit-bug-*`) and is not a Feature 006 task.

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

**Phase 2 (next, after Phase 1 approval): Cost Center Manager — Sheet Review Workspace.** Planned in this specification and task ledger, but not implemented before the Phase 1 gate is recorded. The approved form of the workspace is the **inline quick review** (`UX_SPEC.md`) — adoption of the proposed P1/P2 refinement (`FR-006-002/003`): the Phase 1 queue is retained as the persistent shell and a sheet expands **in place** into a decision surface (Queue → Sheet (expand) → Transaction Card → [Preview | Full details | Decision]). The preserved `review-detail` route stays reachable for deep links (`FR-006-017`). Full detail in the Phase 2 section below.

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
- "Reviewed" sheets and sheet-wide completion require backend capabilities that are not authorized in Phase 1. In Phase 2, the **manager-scoped** read (title/purchaser/submitted/decided-set/manager-progress) is planned as Batch 1 (`§2.4`); sheet-wide completion / Senior eligibility remain unauthorized there too.
- No development-only fixture is required for Phase 1 if a real manager queue can be seeded through the existing development seed command. If a fixture is used, it must be explicitly labeled simulated.
- Git writes remain unauthorized; the working tree is preserved as found.

---

## Phase 2 — Inline Quick Review (Sheet Review Workspace)

**Status**: Planned. The visual form and its decisions are the approved design reference recorded in `docs/frontend-redesign/proposals/006-management-review-quick-review/UX_SPEC.md` (owner approval 2026-11-02) and its mockup `mockups/{index.html,mockup.js,mockup.css}`. This section makes those decisions explicit as Phase 2 requirements and capability boundaries. No Phase 2 task is authorized to start before the Phase 1 gate (`T022`) and the Phase 2 visual gate are recorded (`FR-006-025/026`).

### 2.1 Adopted design decisions carried from the approved design reference

1. **Workspace form — inline quick review (adopts P1/P2).** `FR-006-002/003` are refined so the sheet workspace is delivered as an inline expansion of the Phase 1 queue, never as a separate page replacing the queue; the queue stays mounted. Navigation hierarchy: Manager Queue → Sheet (expand) → Transaction Card → [Preview | Full details | Decision]. The preserved `review-detail` route remains reachable (`FR-006-017`).
2. **Decision actions live in the full-details drawer footer only** (`UX_SPEC` §0.5.1). They appear once, in the drawer footer behind the "Decide this transaction" label; the drawer body never duplicates them.
3. **Unavailable statistics and unsupported history counts are removed from the UI entirely** (`UX_SPEC` §0.5.2): no `Decisions this week`, no `Load per cost center`, no `Done`/`Reviewed` count. The summary shows only the two connected stats; the tabs only the three derived sets (`All sheets / Awaiting decision / In progress`).
4. **Distinct purple Return-for-correction confirmation is kept** (`UX_SPEC` §0.5.3), with a required explanation matching `FR-033` and `DecisionForm.vue` (`requiresExplanation` = REJECT || RETURN). Shipped copy and mockups cite `FR-006-011/FR-006-015` for the unavailable-field rule (`P7`).
5. **Preview reuse** (`P5`): document preview MUST reuse `001` `FR-060` + `005` `FR-005-042` verbatim — same inline-safe closed set (`application/pdf`, PNG, JPEG, GIF, WebP, BMP, `text/plain`), same download-only fallback and copy, same accessible dialog name = file name, Escape close, scrim close, object-URL revocation, single dialog. The mockup adds cross-attachment Previous/Next with a `1 / N` counter; that is a Phase 2 surface addition, not a policy loosening.
6. **Round-trip of every decision is transaction-level** (`FR-006-005`, `FR-033`). One decision per post; no sheet-level action.

### 2.2 Phase 2 capability classification (verified against current backend)

| Approved surface | Classification | Evidence (backend source of truth) | Phase 2 rendering |
|---|---|---|---|
| Inline sheet expansion, per-sheet pending set | **Frontend adaptation** | `GET /queues/cost-center` returns `TransactionSummary[]` with `sheetId`; grouping `useSheetQueue.buildSheetQueue` | Expand in place |
| Compact tx card connected fields (id, amount, currency, status, cost center, description, evidence) | **Supported** | `TransactionPayloadFormatter::summary` (`lib/Infrastructure/Http/TransactionPayloadFormatter.php:49-91`) | Render |
| Card meta merchant / paid-on / payment method / category | **Backend change required** (NOT in Batch 1 scope) | Not in `Revision` payload (`TransactionPayloadFormatter::revisionFrom`, `:156-166`) | Unavailable placeholder (§12); not part of the required-capability batches |
| Decided / returned cards inside the panel, "1 of 2 decided" aggregate | **Required backend capability** (planned, B002/B003) | `getCostCenterQueue` filters `WorkflowStage::CostCenter` (`ManagementReviewService.php:364-415`); `WorkflowStateMachine::next` maps Approve/Reject → Senior, Return → Correction (`lib/Domain/Workflow/WorkflowStateMachine.php:4`) — decided transactions are **not** returned | Pending-only until Batch 1 lands; decided/returned cards + "X of Y decided" connect once B002/B003 exist |
| Details drawer / inventory (Description tab: sheet, purchaser, merchant, paid-on, method, category fields) | **Partial**: connected (amount, sheet ref, cost center, status, description, attachments) vs required-backend (merchant/paid-on/method/category) and required capability (purchaser, sheet title/submitted once B001/B004 land) | `GET /transactions/{id}` role-scoped read `TransactionController::show` via `visibleTransaction` (`TransactionController.php:107-127`) | Connected fields real; merchant/paid-on/method/category placeholders; purchaser/sheet-title/submitted reconnect with Batch 1 |
| Details History tab (timeline) | **Supported** | `TransactionDetail.decisions[]` from `POST/resubmit` responses and `GET /transactions/{id}`; `TransactionPayloadFormatter::decision` (`:430-454`) | Timeline from `decisions[]` |
| Decision actions on cards and in drawer footer | **Frontend adaptation** | `allowedActions` + `version` only in `TransactionDetail`; queue summary carries neither (`TransactionPayloadFormatter::summary`). `review-detail` DecisionForm reads them | Guarded per-transaction detail fetch; never fabricated |
| Approve / Reject / Return for Correction decisions | **Supported** | `POST /transactions/{id}/decisions` (`reviewsApi.recordDecision`), first-write-wins FR-085 | Same single-transaction decision; confirmation modal |
| Document thumbnails + preview overlay with cross-doc nav | **Partial reuse** | `GET /evidence/{id}/content` inline-safe policy (`EvidenceContentPolicy`, contract `:762-817`); frontend `EvidencePreview.vue` overlay exists but has no prev/next nav | Reuse overlay; add navigation |
| Sheet title, purchaser, sheet total, submitted date | **Required backend capability** (planned, B001/B004) | `SheetService::getSheet` is owner-scoped (`SheetService.php:101-117`) — manager has no sheet read; `ExpenseSheet` carries `title`, `ownerUid`, `firstSubmittedAt`, `submittedTotalMinor` (`ExpenseSheet.php:20-37`, `:117`) | Unavailable until Batch 1; connect once B001/B004 land |
| Sheet-wide completion, Senior eligibility | **Backend change required** (separately gated; NOT produced by Batch 1) | No sheet-wide gate exists (`WorkflowStateMachine.php`) | Unavailable — explained; Batch 1 must NOT derive it from one manager's decisions |
| Whole-sheet or bulk decisions | **Unavailable by policy** | `ReviewController::refuseBulkDecision` (`ReviewController.php:278-318`) | Never rendered (`FR-006-005`) |

### 2.3 Phase 2 requirements (new, additive — existing FR-006-001…035 unchanged)

- **FR-006-036**: A sheet row in the Phase 1 queue MUST expand **in place** into the inline quick review panel; the queue must remain mounted behind every overlay.
- **FR-006-037**: Preview, full details, and decision confirmation MUST render as a single overlay layer at a time; opening one closes/replaces none and never unmounts the panel; closing returns focus to the originating control.
- **FR-006-038**: Each relevant transaction MUST render as a compact card with connected id, amount, currency, status, cost-centre scope and description; merchant, paid-on, payment method and category remain unavailable placeholders (not in the required-capability scope). Sheet title, purchaser identity/display name, and submitted date connect once the Batch 1 backend capability (B001/B004) lands. Cards MUST NOT invent a value.
- **FR-006-039**: Awaiting cards MUST expose Approve / Reject / Return-for-correction and Full details; decided cards MUST show their current state with no action buttons. No card action may be enabled without a server-granted `allowedActions` value (refuse-don't-ignore).
- **FR-006-040**: Attachment thumbnails show the first two documents of a transaction, plus a `+N more` tile when more exist; every thumbnail opens the preview overlay at exactly that document.
- **FR-006-041**: The preview overlay MUST reuse `001` `FR-060` and `005` `FR-005-042` verbatim (inline-safe set, download-only fallback, accessible dialog name, Escape/scrim close, object-URL revocation) and add Previous/Next navigation across the transaction's documents with a `1 / N` counter, disabling at the ends.
- **FR-006-042**: `Full details` MUST open a read-only drawer with a Description panel (hero amount, detail grid, description, attachments) and a History timeline panel; the drawer's only writes are the decision actions in its footer (final decision #1).
- **FR-006-043**: Every decision — from a card or the drawer — MUST open the same confirmation modal with per-kind copy/warning, per-kind note rules (Reject and Return require an explanation, Approve never does, `FR-033`), a submitting state (controls disabled, scrim no-close, first-write-wins `FR-085`), an error state for a reject/return without a note, and a success path that re-renders the card in its decided state plus a toast. Both entry points share one workflow (`UX_SPEC` §7.5/§8).
- **FR-006-044**: On mobile the panel, drawer, and decision modal MUST honour the 390px floor, full-screen overlays one at a time, expand-to-panel scroll, `≥44px` touch targets, and the RTL-mirrored drawer (`UX_SPEC` §7.4), with no horizontal scroll, in English LTR and Persian RTL.
- **FR-006-045**: The focused summary, scope banner, and tabs MUST show only connected data (`Sheets awaiting decisions`, `Transactions pending decision`) and the three derived tabs (`All sheets / Awaiting decision / In progress`); unavailable stats and unsupported counts are absent from the UI entirely — no invented number and no disabled placeholder for a capability that does not exist (final decision #2).
- **FR-006-046**: The panel MUST NOT render a decided/returned card, a sheet title, purchaser, sheet total, submitted date, or merchant/paid-on/method/category as real — every not-yet-connected field shows its unavailable state or is absent — until the Batch 1 backend capability connects it; once B001/B002/B003/B004 land those surfaces connect (`FR-011/015` honesty applies to every state). `FR-006-046` is satisfied only when the phase renders `0%` fabricated values in every state.
- **FR-006-047**: The interface and its backend MUST keep distinct transactions awaiting the current manager's decision, transactions already decided by that manager, transactions returned for correction, completion of the manager's own responsibilities, completion of the entire Cost Center stage, and Senior Review eligibility; none may be conflated or presented as another.
- **FR-006-048**: The interface and its backend MUST NOT infer whole-sheet Cost Center completion or Senior Review eligibility from one manager's decisions, and MUST NOT invent history records or derive totals from pending-only data; every count/status traces to the manager's authorized scope and the server's proven state.

### 2.4 Required manager-scoped backend capability (`P4` — planned, Batch 1)

The owner directive (`UX_SPEC` §13.P4) is adopted as a **required product capability** and planned as the Phase 2 backend batch (Batch 1). It is no longer deferred. Until it is implemented and its separate backend-change authorization is recorded, the affected surfaces render unavailable/absent (`FR-006-011/015`); when it lands they connect without changing the information architecture.

**In scope (required)**: real sheet titles and purchaser identity/display name, genuine submission dates, authorized manager-scoped sheet metadata, previously-decided transactions belonging to the manager's review scope, and accurate manager-specific review progress — reusing existing services (`ExpenseSheetRepository`, `RoleAssignmentRepository`, `UserDirectory`, audit events, `TransactionPayloadFormatter`/`SheetPayloadFormatter`).

**Authorization rule (mandatory, `FR-006-004`/`FR-006-011`)**: manager-scoped reads are authorized **only** to the cost centers for which the current user holds an active Cost Center Manager assignment (`RoleAssignmentRepository::findActiveByUserAndRole`, same source as `ManagementReviewService::getCostCenterQueue`). This never broadens manager entitlement, never exposes a transaction or sheet outside the managed scope, and never crosses into Senior/Finance surfaces.

**Workflow-correctness rule (mandatory)**: the backend MUST keep distinct (a) transactions awaiting this manager's decision, (b) transactions already decided by this manager, (c) transactions returned for correction, (d) completion of this manager's own responsibilities, (e) completion of the entire Cost Center stage, (f) Senior Review eligibility. It MUST NOT infer (e) or (f) from (d) or from pending-only data, and MUST NOT invent history records or derive totals from an incomplete set.

**Explicitly out of scope (unchanged)**: merchant/paid-on/payment-method/category fields, the sheet-wide-completion/Senior-eligibility gate (remains a separately gated backend change — see Workflow Reconciliation §), and any change to decision semantics, financial rules, or public contract beyond the additive manager-scoped read surface.
