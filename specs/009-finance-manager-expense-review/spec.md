# Feature Specification: Finance Manager Expense Review

**Feature Branch**: `009-finance-manager-expense-review` (logical feature directory; no branch operation is authorized by this specification)

**Created**: 2026-11-19

**Status**: Draft — requirements codified 2026-11-19 from the approved Finance Manager Expense Review UX handoff. Nothing in this specification authorizes planning, task generation, contract work, implementation, or tests.

**Owner-approved presentation baseline (2026-11-21; presentation only):** inside the Finance Sheet Details Modal the canonical transaction-detail interaction is the **Inline Expanded Transaction** (`FR-009-003`/`FR-009-011b`), superseding the Transaction Detail Drawer; the transaction card's review-progress presentation is the compact **stage identity + state marker** contract (`FR-009-011a`); and the shared presentation contract — stable modal geometry, persistent card-level Finance actions, the readable typography floor, and the expansion focus lifecycle — is adopted by reference (`FR-009-029`). A same-day v3 alignment (`Review Ledger` presentation) was **superseded by this owner decision before any implementation** — no v3 requirement was ever implemented. Presentation only — no workflow, authorization, backend, contract, or decision-semantics change; implementation is planned as the `UXC-V2-*` phase in this feature's cross-feature convergence ledger (`ux-convergence-tasks.md`).

**Input**: Owner-approved Finance Manager Expense Review UX/UI handoff (product baseline). This feature codifies Module A (Expense Review); Feature 008 implements Module B (Petty Cash Account Management) only. This is requirements codification, not product discovery.

---

## Source of Truth and Scope

1. `.specify/memory/constitution.md` governs delivery, verification, and visual evidence.
2. `specs/001-pettycash-v1-core/spec.md` and its approved contracts (`contracts/ocs-api.yaml`, `contracts/workflow.md`) govern domain, authorization, financial, lifecycle, evidence, currency, revision, and workflow behavior. This feature does not restate, redefine, or weaken those rules.
3. `specs/007-senior-manager-cost-sheet-review/spec.md` governs the Senior Manager sheet-first queue + modal presentation. The approved Form and interaction patterns preserved here are consistent with Feature 007's Cost-Sheet-first structure (modal with the queue mounted behind it, no route navigation, per-transaction decision surface, focus restoration), adapted to the Finance stage.
4. `specs/008-finance-manager-account-management/spec.md` governs Module B (holder directory, account ledger, recharge, completed Cost Sheet history). Feature 009 MUST NOT expand into any of those surfaces.
5. This specification governs the Finance Manager Expense Review information architecture, presentation, interaction, capability classification, and acceptance. It does not restate domain workflow rules.

Existing requirements, canonical task IDs, and working functionality MUST be preserved. This feature MUST NOT be folded into `006-management-review`, `007-senior-manager-cost-sheet-review`, or `008-finance-manager-account-management`, and MUST NOT change the Cost Center Manager or Senior Manager workflows in any way.

---

## Approved Product Journey

The required Finance Expense Review journey is:

```text
Cost Sheet Queue
→ Sheet Details Modal
→ Transactions Tab
→ Compact Transaction Cards (stage identity + state marker + permitted Finance actions)
→ Activate Transaction
→ Inline Expanded Transaction Details
```

This interaction model applies to the Finance Manager stage. The base UX and information hierarchy remain consistent with the Cost Center and Senior Manager surfaces.

The main queue MUST be Cost-Sheet-first. Transactions MUST NOT be the primary queue/navigation surface.

One visible queue row represents one Cost Sheet. Selecting a Cost Sheet opens its Sheet Details Modal while the queue remains mounted behind it. The modal exposes the canonical tabs: Transactions, Approvals, Overview, History. The Transactions tab renders compact transaction cards; activating a transaction opens its Inline Expanded Transaction in place inside the modal, preserving Sheet context (owner-approved UX v3, 2026-11-21 — supersedes the Transaction Detail Drawer interaction).

---

## Current Implementation Reality (not a requirement)

The current route is `#/reviews/finance`. The current implementation is transaction-first:

```
src/views/reviews/FinanceQueueView.vue
→ useReviewsStore.fetchFinanceQueue()
→ reviewsApi.fetchQueue
→ GET /api/v1/queues/finance
```

The endpoint currently returns a paginated flat transaction collection. The current implementation is NOT the desired product UX. Do not treat the existing transaction-first UI as a requirement. This specification codifies the approved Cost-Sheet-first UX.

---

## Reusable Existing Patterns

These serve as technical/product precedents only when writing the specification:

- `src/composables/useSheetQueue.ts` — completeness guard, safe deduplication, deterministic grouping, per-currency totals.
- `src/composables/useSeniorQueue.ts` — sheet-level filter/sort over a proven-complete set, currency-safe amount ordering.
- `src/views/reviews/SeniorQueueView.vue` — sheet-first queue, Sheet Details Modal over mounted queue, focus restoration to originating row.
- `src/components/review/SeniorSheetModal.vue` — modal presentation, per-transaction decision surface, evidence preview overlay.
- `src/views/reviews/FinanceReviewView.vue` — per-transaction Finance decision surface, prior-decision history, senior-rejected approval restriction (`approveForbiddenReason`).
- `src/components/review/DecisionForm.vue` — single-transaction decision with mandatory comment for reject/return.
- Existing evidence components (`EvidencePreview.vue`, `EvidenceList.vue`, etc.) — image preview, PDF preview, separate preview overlay, download fallback.

The canonical Sheet Details Modal, Transactions tab with compact transaction cards, Inline Expanded Transaction, compact stage identity + state marker review-progress presentation, and Sheet-level Approvals tab vs transaction-level Previous Decisions are defined by the shared management-review UX (006 v2 baseline) and apply to Finance identically to Cost Center and Senior Manager. Do not prescribe exact implementation choices in the specification unless required for product correctness. Implementation architecture is a planning decision.

---

## Workflow Reconciliation

The owner-approved rules below are explicit. They relate current implementation behavior to the authorized workflow rules. Conflicts are recorded here so they are not silently preserved or silently redefined.

| Owner-approved rule | Current behavior / artifact | Disposition in this specification |
|---|---|---|
| The Finance queue MUST be Cost-Sheet-first: one visible row represents one Cost Sheet; transactions MUST NOT be the primary navigation surface. | `FinanceQueueView.vue` renders a flat transaction table from `GET /api/v1/queues/finance`; selecting a row navigates to `/reviews/finance/:transactionId` (`FinanceReviewView.vue`). | **Implementation gap.** The current transaction-first UI does not satisfy the approved journey. The specification requires Cost-Sheet-first grouping and a modal-over-mounted-queue presentation. No new backend endpoint is required at this stage (see Grouping Completeness below). |
| Selecting a Cost Sheet opens its review modal while the queue remains mounted behind it; avoid unnecessary route navigation. | Current selection performs route navigation to a dedicated transaction review page. | **Required change at product level.** Modal-over-mounted-queue replaces route navigation as the primary flow. Route-based deep-link handling is a planning decision; this specification does not require removal of the existing `finance-review` route. |
| Finance decisions remain independent per transaction; no bulk Finance approval. Actions are limited to Approve, Reject, Return for Correction where authorized. | `DecisionForm.vue` enforces single-transaction decisions; `FinanceDecisionPolicy` enforces FR-039 (Approve only on senior-approved; Reject/Return on senior-approved, senior-rejected, or awaiting finance review). | **Supported.** Reuse unchanged; server-side enforcement remains authoritative. |
| Distinguish Cost Center decision, Senior Manager decision, and Finance Manager decision; do not collapse into one generic status. | `TransactionDetail.decisions[]` carries stage-tagged decisions; `FinanceReviewView.vue` renders `managementDecisions` including stage labels. | **Supported.** Render prior decisions per transaction at stage level inside the modal. |
| Senior-rejected transaction may reach Finance but Finance must not be given an action that authoritative workflow rules prohibit (FR-039: Finance MUST NOT approve a senior-rejected transaction). | `FinanceReviewView.vue` already enforces `approveForbiddenReason` for `SENIOR_REJECTED`. | **Supported.** Reuse unchanged; server-side `allowedActions` drives the decision surface. |
| Evidence interaction pattern preserved: transaction-level evidence, thumbnails/metadata, image/PDF preview, separate preview overlay, download fallback, return to same transaction context. | `EvidencePreview.vue` and related components exist and are reused by `SeniorSheetModal.vue`. | **Supported.** Reuse unchanged; do not redesign the evidence viewer. |
| Never numerically aggregate different currencies. | `useSheetQueue.ts` collects totals per currency and never sums across currencies. | **Required.** Currency-separated information or honest non-aggregated state only. |
| Grouping completeness: complete transaction membership, safe deduplication, correct transaction count, deterministic grouping, explicit unavailable/incomplete state. | The current flat `GET /queues/finance` response is paginated and transaction-scoped; no sheet-scoped finance read exists. | **Requirement.** Grouping is permitted only when the complete finance-scoped pending set is proven in one response (completeness guard, as in 006 `FR-006-012/013` and 007 `FR-007-012/016`). An explicit unavailable state renders otherwise. |
| The specification MUST NOT require a new backend endpoint at this stage. | No `GET /queues/finance/sheets` or per-sheet finance read exists. | **Deferred.** Sheet-level identity fields (title, purchaser, submitted date) not provided by the flat queue render as an honest unavailable state until a planning-decided additive backend capability exists. |

No backend behavior is changed without separate authorization. The specification codifies required product behavior; the implementation architecture (including whether and how an additive finance-scoped sheet read is introduced) is a planning decision.

---

## Capability Classification

Every required capability is classified as **supported**, **frontend adaptation**, or **backend change required / deferred**. This table records the codification-time classification; implementation status and any landed backend evidence are recorded in a future plan.

| Capability | Classification | Evidence / Rationale |
|---|---|---|
| Cost-Sheet-first Finance queue (one row per Cost Sheet) | **Frontend adaptation** | Derived from the existing `GET /api/v1/queues/finance` flat transaction collection grouped by `sheetId` over a proven-complete set (mirrors 006 `useSheetQueue.ts`). |
| Queue completeness guard + honest unavailable state | **Frontend adaptation** | Same guard as 006 FR-006-012/013 and 007 FR-007-012/016; no backend change required at this stage. |
| Review modal over mounted queue; focus restoration to originating row | **Frontend adaptation** | Mirrors 007 `SeniorQueueView.vue` / `SeniorSheetModal.vue` (FR-007-002, FR-007-009); no backend change required. |
| Per-transaction Finance decision surface inside the modal | **Supported** | `GET /transactions/{id}` → `TransactionDetail` (`allowedActions`, `decisions[]`, `evidence[]`); `POST /transactions/{id}/decisions` (`ReviewController::decide`). |
| Approve / Reject / Return for Comment with mandatory explain for reject/return | **Supported** | `DecisionForm.vue`; `FinanceDecisionPolicy` enforces FR-033/FR-039 server-side. |
| Distinguish Cost Center / Senior / Finance Manager decisions per transaction | **Supported** | `TransactionDetail.decisions[]` carries stage-tagged decisions (`COST_CENTER`, `SENIOR`, `FINANCE`); `PriorDecisionHistory` renders them. |
| Senior-rejected restriction (Finance cannot approve senior-rejected) | **Supported** | `FinanceDecisionPolicy::canApprove` returns false for `SENIOR_REJECTED`; `approveForbiddenReason` in `FinanceReviewView.vue`. |
| Evidence interaction (thumbnails, image/PDF preview, overlay, download fallback, return to transaction context) | **Supported** | Existing `EvidencePreview.vue` and related components; FR-060 inline-safe policy. |
| Finance-scoped sheet read (`GET /queues/finance/sheets[/{sheetId}]`) carrying sheet title/purchaser/submitted date | **Backend change required / deferred** | Does not exist; NOT required by this specification at this stage. Sheet identity fields render as honest unavailable until a planning-decided additive backend capability exists. |
| Finance-scoped XLSX export | **Backend change required / deferred** | Not part of the approved Finance Expense Review journey; out of scope unless separately authorized. |
| Holder directory, account ledger, recharge, completed Cost Sheet history | **Out of scope** | Module B, implemented in Feature 008; MUST NOT appear in Feature 009. |

The implementation MUST connect only the supported and safely adaptable capabilities. Unsupported controls MUST be replaced by an honest unavailable/error state — never fabricated data.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 — A Cost-Sheet-first Finance queue (Priority: P1)

A Finance Manager opens the Finance Expense Review queue and sees Cost Sheets, not a flat transaction table. Each visible row represents one Cost Sheet and summarizes its finance-eligible transactions. The queue operates on Cost Sheets for filtering/search/sorting where applicable. The queue is honest about what the backend can prove: it groups only over a proven-complete finance-scoped set and renders an explicit unavailable state otherwise.

**Why this priority**: This is the entry point to the Finance Expense Review journey and the first visible milestone. The entire rest of the feature hangs off it.

**Independent Test**: With a Finance Manager holding finance assignments and a finance-eligible set that fits in a single complete response, the queue renders one row per Cost Sheet with the finance-eligible transaction count, per-currency totals, and review status; no transaction row is shown outside the Finance queue surface as a primary surface. With a set that cannot be proven complete, it renders an honest unavailable state with a retry — never a partial flat transaction table.

**Acceptance Scenarios**:

1. **Given** multiple eligible Finance transactions belonging to one Cost Sheet, **When** the Finance queue loads, **Then** they render as exactly one queue row for that Cost Sheet (AC-1).
2. **Given** two distinct Cost Sheets, **When** the Finance queue loads, **Then** two queue rows render, one per Cost Sheet (AC-2).
3. **Given** a Cost Sheet with eligible Finance transactions, **When** it renders in the queue, **Then** the row shows the Cost Sheet reference, title where available, submitter where available, relevant dates where available, the finance-eligible transaction count, monetary information with currency per currency, and review status where available (AC-3 partial).
4. **Given** the finance-scoped response is truncated or cannot be proven complete, **When** the queue renders, **Then** it shows an honest unavailable state with a retry, does not invent counts, and never presents a partial flat table as complete (AC-4, AC-10).
5. **Given** multiple currencies among a Cost Sheet's finance-eligible transactions, **When** totals render, **Then** each currency is shown separately and no cross-currency numerical sum is presented (AC-11).
6. **Given** a Finance Manager has no finance-eligible transactions, **When** the queue loads, **Then** an honest empty state is shown, not a fabricated sheet.
7. **Given** queue-level filtering/search/sorting, **When** applied, **Then** it acts on Cost Sheets, not individual transactions (queue behavior rule).

### User Story 2 — Sheet Details Modal with per-transaction Finance decisions (Priority: P1)

From a Cost Sheet row, the Finance Manager opens the Sheet Details Modal while the queue remains mounted behind it (no route navigation). The modal's Transactions tab lists the Cost Sheet's finance-eligible transactions as **compact transaction cards**, each exposing transaction title/description, amount + currency, transaction date, cost center where relevant, transaction state, evidence summary where available, the compact stage identity + state marker review-progress presentation for Cost Center / Senior Manager / Finance Manager, and its server-permitted Finance actions. Activating a transaction opens the **Inline Expanded Transaction** in place within the tab preserving Sheet context: Transaction Details, Evidence, Previous Decisions, and Current Stage Actions; exactly one transaction is expanded at a time. Finance decision controls are gated by server-granted `allowedActions`. Every decision applies to exactly one transaction; no bulk Finance approval exists.

**Why this priority**: This is the core decision surface; without it the Cost Sheet list has no purpose.

**Independent Test**: Open a Cost Sheet modal, verify each transaction exposes its compact card with its review-progress presentation, activate a transaction to open its Inline Expanded Transaction, record each decision type, and verify exactly one transaction decision request is issued per action, prior decisions per stage remain visible and unmodified, and no sibling transaction is affected.

**Acceptance Scenarios**:

1. **Given** a Cost Sheet row, **When** selected, **Then** a Sheet Details Modal opens with the queue mounted behind it and no route navigation occurs (journey requirement).
2. **Given** a Cost Sheet with eligible Finance transactions, **When** the modal opens, **Then** its Transactions tab exposes all of its eligible finance transactions as compact cards, one per transaction, and no sibling transaction is silently omitted (AC-3).
3. **Given** a transaction in the modal, **When** its prior decisions render, **Then** the Cost Center, Senior Manager, and Finance Manager decision states are each shown distinctly with stage labels and never collapsed into one generic status (AC-5).
4. **Given** a transaction in the modal, **When** the Finance Manager records Approve/Reject/Return, **Then** exactly one transaction decision request is issued for that transaction, gated by `allowedActions`, and no sibling transaction receives the decision (AC-7, AC-8).
5. **Given** a senior-rejected transaction, **When** Finance decision controls render, **Then** Approve is not offered; Reject and/or Return may be offered per `allowedActions` (AC-9).
6. **Given** a Reject/Return is submitted without a comment, **When** the confirmation is attempted, **Then** it refuses with a field error and no decision is issued (mirroring `DecisionForm` mandatory-explain rule).
7. **Given** an Approve is submitted, **When** a comment is optional, **Then** it may proceed without a comment (FR-033 pattern).
8. **Given** a decision is recorded, **When** the server responds, **Then** the card re-renders from the server's `result.transaction` and prior decisions remain visible and unmodified (FR-084/085 first-write-wins; no fabricated local status).

### User Story 3 — Evidence remains attached to the correct transaction (Priority: P1)

Each transaction in the modal shows its own evidence: thumbnails/metadata where applicable, with image preview, PDF preview, a separate preview overlay, a download fallback, and return to the same transaction context after closing the preview.

**Why this priority**: Review decisions routinely hinge on receipts; evidence must stay bound to the correct transaction and the approved viewer pattern must not be redesigned.

**Independent Test**: From a transaction with several attachments (image, PDF, unknown type), open each thumbnail and verify the correct preview or fallback and navigation back to the same transaction context.

**Acceptance Scenarios**:

1. **Given** a transaction with evidence, **When** its thumbnail strip renders in the modal, **Then** each thumbnail is bound to that transaction only (AC-6).
2. **Given** an image attachment, **When** its thumbnail is clicked, **Then** an image preview renders in a separate preview overlay.
3. **Given** a PDF attachment, **When** its thumbnail is clicked, **Then** the PDF opens in the project's existing viewer.
4. **Given** an unknown media type, **When** its thumbnail is clicked, **Then** metadata is shown with open/download controls and no inline render.
5. **Given** multiple documents, **When** navigating the preview overlay, **Then** Previous/Next and a `1 / N` counter are shown and disable at the ends (reuse existing evidence pattern).
6. **Given** a preview overlay closes, **When** it closes, **Then** the user returns to the same transaction context in the modal (return-to-context rule).

### User Story 4 — Modal lifecycle and accessibility (Priority: P2)

The modal is accessible: keyboard-operable queue rows and actions, focus trap where applicable, focus restoration to the originating Cost Sheet row after modal close, evidence preview accessibility, English LTR and Persian RTL, readable UUIDs and currency codes, 390px desktop floor with no horizontal scroll.

**Why this priority**: The surface must be usable by the approved user population without regressing the established application experience.

**Independent Test**: Switching the user language between English and Persian renders every module string in the selected language with correct LTR/RTL layout; keyboard-only navigation completes the queue → modal → decision → close journey; closing the modal restores focus to the originating Cost Sheet row.

**Acceptance Scenarios**:

1. **Given** the modal closes, **When** it closes, **Then** focus returns to the originating Cost Sheet row in the queue (AC-12).
2. **Given** English/LTR, **When** the queue and modal render, **Then** all strings are English and layout is left-to-right (AC-13).
3. **Given** Persian/RTL, **When** the queue and modal render, **Then** all strings are Persian and layout is right-to-left (AC-14).
4. **Given** keyboard operation, **When** traversing the queue and modal actions, **Then** every queue row and action is keyboard-operable with visible focus.
5. **Given** evidence preview accessibility, **When** the preview overlay is open, **Then** it is keyboard-operable and focus-managed.
6. **Given** the 390px desktop floor, **When** rendering, **Then** there is no horizontal scroll.

### User Story 5 — Authorization and separation from account management (Priority: P2)

Finance Expense Review is focused on eligible expense review and Finance decisions only. Existing Finance authorization remains unchanged; no account-management/recharge controls appear in this module.

**Why this priority**: Module B (account management) is owned by Feature 008 and must not leak into the Expense Review surface.

**Independent Test**: Verify no holder directory, account balance inspection, ledger, holder management, or recharge controls appear in the Finance Expense Review module, and Finance authorization semantics are unchanged.

**Acceptance Scenarios**:

1. **Given** the Finance Expense Review module, **When** rendered, **Then** no account-management/recharge controls appear (AC-16).
2. **Given** a user without Finance authorization, **When** attempting to reach the Finance Expense Review queue or modal, **Then** access is denied and no finance transaction data is shown (AC-15).
3. **Given** existing Finance authorization, **When** compared to the pre-feature state, **Then** it is unchanged (AC-15).

---

## Edge Cases

- A Cost Sheet with some transactions at Finance stage and others at earlier/later stages — the row represents only its finance-eligible transactions; sheet-wide completion is not claimed.
- A sheet reference empty or unresolvable in a queue row — rendered as an honest unavailable reference, never fabricated.
- A Cost Sheet disappears between queue load and modal open (another actor decided the last pending transaction) — the modal renders an honest unavailable/refreshed state, never a synthesized "empty".
- The per-sheet finance-eligible transaction count cannot be proven complete — explicit unavailable state, never partial membership.
- A transaction's detail read is refused (403), absent (404), or a network fault — rendered distinctly; no local status fabricated.
- A transaction's evidence set is empty, has one item, contains unknown types, or contains inactive evidence.
- Reject/Return without a comment; Approve with an optional comment; network failure mid-submit (FR-084 replay safety, FR-085 first-write-wins).
- Search with no matches at Cost Sheet level; empty legitimate filtered set.
- English LTR and Persian RTL; Gregorian and Jalali calendar preference; 390px viewport; dark theme and forced-colours.

---

## Requirements *(mandatory)*

### Presentation and Information Architecture

- **FR-009-001**: The Finance Expense Review queue MUST be Cost-Sheet-first: the Finance Manager initially sees Cost Sheets containing finance-eligible transactions, not a flat transaction table as the primary surface. One visible queue row represents one Cost Sheet.
- **FR-009-002**: Selecting a Cost Sheet MUST open a **Sheet Details Modal**; there MUST be no route navigation as the primary flow, and the original queue MUST remain mounted and visible behind the modal. The modal MUST expose the canonical tabs: Transactions, Approvals, Overview, History.
- **FR-009-003**: The modal's Transactions tab MUST list the Cost Sheet's finance-eligible transactions as **compact transaction cards** exposing transaction title/description, amount + currency, **transaction date** (the existing authoritative `TransactionSummary.activeRevision.expenseDate` — mandatory first-view information; no new field is invented), cost center where relevant, transaction state, evidence summary where available, the compact stage identity + state marker review-progress presentation for Cost Center / Senior Manager / Finance Manager (`FR-009-011a`), and all server-permitted current-stage Finance actions (`FR-009-029`). Activating a transaction MUST open the **Inline Expanded Transaction** in place inside the Sheet modal, preserving Sheet context, with canonical sections: Transaction Details, Evidence, Previous Decisions, and Current Stage Actions; exactly one transaction is expanded at a time. One transaction card/review surface per transaction reuses the existing transaction review components.
- **FR-009-004**: Each Cost Sheet row MUST show, where available from the queue read model: Cost Sheet reference, title, submitter, relevant dates, finance-eligible transaction count, monetary information with currency per currency, and review status. Fields not provided by the read model MUST be shown as unavailable, never fabricated.
- **FR-009-005**: Queue-level filtering/search/sorting, where provided, MUST act on Cost Sheets. Transaction-level search/filter/sort belongs inside the opened Cost Sheet review surface where required; it MUST NOT be duplicated at the queue level.

### Data Honesty and Capability Boundaries

- **FR-009-006**: The interface MUST NOT fabricate financial amounts, Cost Sheet identities, purchaser identities, review outcomes, submission dates, completion states, or eligibility states.
- **FR-009-007**: Cost Sheet grouping in the Finance queue is permitted only when the complete finance-scoped pending set is proven in one response (completeness guard, as in 006 `FR-006-012/013` and 007 `FR-007-012/016`). De-duplication MUST be by transaction id; first copy wins; order preserved.
- **FR-009-008**: The interface MUST NOT state that grouping is complete or that a Cost Sheet's finance-eligible membership is complete unless the authoritative completeness guard proves it. An explicit unavailable/incomplete state MUST render otherwise; the interface MUST NOT present partial membership as if it were complete.
- **FR-009-009**: Transaction count shown on a row MUST equal the number of de-duplicated finance-eligible transactions proven in the response; it MUST NOT be approximated.
- **FR-009-010**: Currency rule — amounts MUST be summed only within a single currency. Multiple currencies MUST be shown separately; no cross-currency numerical total or comparison is permitted (006 `FR-006-014`, 007 currency safety).
- **FR-009-011**: An additive finance-scoped sheet read (sheet title/purchaser/submitted date) is NOT required by this specification at this stage. Until such a backend capability exists under separate authorization, those fields MUST render as an honest unavailable state.
- **FR-009-011a** (owner-approved Management Review v2 baseline, 2026-11-21 — supersedes the same-day icon-only chip refinement and the briefly aligned v3 Review Ledger, neither of which was implemented): Each compact transaction card MUST present its review progress as the compact **stage identity + state marker** presentation: exactly the three workflow stages Cost Center → Senior Manager → Finance Manager in fixed workflow order, one entry per stage, where each stage has (a) a restrained **stage identity** — identity icon/mark plus stage label, visible on desktop without hover — and (b) a **separate state marker**: decision state is represented independently of and visually distinct from stage identity. Each state marker MUST have sufficient visual prominence for fast transaction scanning. For Approved in particular: the check glyph MUST be clearly visible, and its foreground, background, and border contrast MUST be stronger than the current implementation while the marker remains compact — the stage row MUST NOT grow into a large panel. Meaning MUST NOT depend on color alone, and the accessibility requirements (text labels, focus treatment, RTL, forced-colours) remain applicable to all states: Approved, Rejected, Returned, and No decision yet. A generic traffic-light visual treatment MUST NOT be introduced. The states remain Approved, Rejected, Returned, and No decision yet, and no state may be hidden because it is undecided. The presentation MUST be compact and visually light — substantially less vertical space than the superseded v3 ledger treatment — and compact enough for scanning many transaction rows; large equal-height stage panels MUST NOT be prescribed. On narrow viewports the stage identity may compact, but understanding a stage MUST NOT depend on hover: accessible labels remain available through text and focus treatment. Meaning MUST NOT depend on color alone; semantic color MUST be restrained; a generic traffic-light appearance MUST NOT be used. The presentation is informational and read-only — NOT checkboxes, NOT buttons, NOT actionable — and MUST work in RTL. The same compact review-progress component is used in both the collapsed and the expanded transaction states. Full per-stage decision detail (stage, decision, actor, timestamp, comment/explanation) remains in the Inline Expanded Transaction (`FR-009-011b`).
- **FR-009-011b** (content preserved from the former drawer requirement; presentation moved to the inline expansion, 2026-11-21): Activating a transaction MUST open its **Inline Expanded Transaction** in place inside the Sheet modal, preserving Sheet context — the canonical interaction `TransactionReviewCard → Inline Expanded Transaction` replaces the former Transaction Detail Drawer; exactly one transaction is expanded at a time, and opening another collapses the previous expansion. The expansion MUST contain: Transaction Details (description/title, expense date, amount, currency, expense type, cost center, transaction reference, current transaction status, other existing authoritative fields); Evidence (thumbnails/list, image preview, PDF preview, metadata, download fallback — reusing `FR-060`/`FR-005-042` verbatim); Previous Decisions (Cost Center / Senior Manager / Finance Manager shown separately, each with decision, actor, date/time, comment/explanation; `No decision yet` when none exists; never collapsed into one generic approval status); and Current Stage Actions (only actions permitted to the current user/stage). Information already visible in the collapsed transaction card MUST NOT be repeated in the expanded transaction detail. The card owns, as first-view information: transaction title; amount and currency; expense date; lifecycle/stage presentation; transaction identifier; cost center; expense type; review stage markers; evidence summary; and reviewer actions. The expanded detail MUST primarily contain complementary information not already visible above — where supplied by the server: purchaser; submitted timestamp; merchant; paid-on value; payment method; detailed evidence; previous decisions; and additional contextual fields not already visible in the card. A second amount/status summary block restating the card MUST NOT be introduced. Transaction ID, expense type, date, and other first-view metadata MUST NOT be repeated unless a concrete accessibility or contextual requirement makes repetition necessary. The expanded surface MUST be materially lighter and shorter than the card's first view while preserving the mobile semantic flow details → evidence → history → actions. The expansion MUST NOT navigate away from the Sheet and MUST NOT open a second full modal over the Sheet modal; Evidence Preview and Decision Confirmation remain lightweight overlays above the Sheet modal.
- **FR-009-011c**: The modal's Approvals tab MUST show the approved monetary amount at each workflow stage (Cost Center approved amount, Senior Manager approved amount, Finance Manager approved amount) using authoritative stage totals. It MUST NOT duplicate detailed per-transaction decision history; per-transaction decision history lives in Previous Decisions inside the transaction's Inline Expanded Transaction.

### Decision Workflow

- **FR-009-012**: Finance decisions MUST remain independent per transaction; every decision applies to exactly one transaction. There MUST be no sheet-level Approve/Reject/Return or "decide all" action (001 `FR-033`, `FR-070`).
- **FR-009-013**: Finance decision actions MUST be limited to Approve, Reject, and Return for Correction where authorized by the authoritative workflow rules. No new decision types or financial rules are introduced (001 `FR-038`, `FR-039`).
- **FR-009-014**: Approve MUST be gated by server-granted `allowedActions.canApprove`; Reject by `canReject`; Return by `canReturn`. A senior-rejected transaction MUST NOT be offered Approve (001 `FR-039`; `FinanceDecisionPolicy`).
- **FR-009-015**: Reject and Return comments MUST be mandatory; Approve comment optional, using the existing explanation rule (001 `FR-033`; `DecisionForm.vue`).
- **FR-009-016**: Recording a Finance decision MUST use the existing `POST /transactions/{id}/decisions` path with replay safety (001 `FR-084`) and first-write-wins (001 `FR-085`), evaluated independently per transaction. The client MUST NOT retry automatically (001 `FR-075`).
- **FR-009-017**: A Finance decision MUST NOT modify or overwrite the Cost Center or Senior Manager decision; all decisions remain in the immutable, stage-scoped decision history (001 `FR-036`: senior decision is final management decision and unchanged by finance).
- **FR-009-018**: Decisions MUST remain auditable and stage-specific, rendered from the server's own decision history.

### Prior Decisions and Audit Context

- **FR-009-019**: The transaction's Inline Expanded Transaction MUST distinguish Cost Center decision, Senior Manager decision, and Finance Manager decision per transaction; they MUST NOT be collapsed into one generic status (001 `FR-034`, `FR-036`). Each stage is shown separately with decision, actor, date/time, comment/explanation; `No decision yet` when none exists.
- **FR-009-020**: Prior decisions and relevant audit context MUST remain visible per transaction inside the transaction's Inline Expanded Transaction.
- **FR-009-020a**: Sheet-level Approvals (approved monetary amount at each stage) and transaction-level Previous Decisions (detailed per-stage decision history) are distinct: Approvals is a Sheet-level aggregate of approved amounts per stage; Previous Decisions is transaction-level detail inside the transaction's Inline Expanded Transaction. They MUST NOT be conflated.

### Evidence

- **FR-009-021**: Evidence MUST remain attached to the correct transaction; each transaction's evidence is shown only within that transaction's surface (AC-6).
- **FR-009-022**: The evidence interaction pattern MUST be preserved unchanged: transaction-level evidence, thumbnails/metadata where applicable, image preview, PDF preview, separate preview overlay, download fallback, and return to the same transaction context after closing the preview. The existing `EvidencePreview.vue` pattern MUST be reused; the evidence viewer MUST NOT be redesigned. Evidence is rendered inside the Inline Expanded Transaction's Evidence section; the collapsed card shows the evidence summary (evidence count, up to two real image thumbnails when available, a PDF/file representation when applicable, and `+N` for additional evidence). No evidence upload/replace/delete control appears on any reviewer surface.

### Authorization and Scope Protection

- **FR-009-023**: Existing Finance authorization MUST remain unchanged; the module MUST NOT broaden, narrow, or replace existing Finance role/responsibility scope.
- **FR-009-024**: The module MUST NOT provide holder directory, account balance inspection, account ledger, holder management, recharge, or completed Cost Sheet account-history browsing. Those belong to Feature 008 (Module B).
- **FR-009-028** (owner decision, recorded 2026-11-20): For an in-progress Cost Sheet already legitimately represented in the authorized Finance review queue (`GET /queues/finance` under its existing eligibility and authorization), Finance is authorized to see three **whole-Cost-Sheet** aggregate monetary totals — Cost Center approved, Senior Manager approved, Finance Manager approved — per the sheet's own single currency, carried by an additive `stageApprovedTotals` field on the Finance queue item contract (`specs/001-pettycash-v1-core/contracts/ocs-api.yaml`). This authorizes the **aggregate only**:
  - No new transaction visibility, no new evidence visibility, no new individual decision-history visibility (no actors, comments, or per-stage decision records beyond what the transaction's own detail read already grants), and no Feature 008 account-management controls (`FR-009-024` continues to apply).
  - The totals are computed **server-side from the sheet's complete transaction set** by the existing domain computation (`lib/Domain/Expense/SheetTotals.php` over `SheetSummaryQuery::totalsForSheet()`); a transaction contributes to a stage total only when that stage's authoritative decision on the current active revision is Approved — Rejected, Returned, undecided, and superseded-revision approvals do not contribute. No second aggregation rule exists, and the frontend MUST NOT compute whole-sheet totals from the finance-eligible transaction subset.
  - Every queue item belonging to the same Cost Sheet carries the identical aggregate value; the Finance surface consumes one consistent value per Sheet through its existing grouping. The value is presentation only: no change to decision workflow, posting, settlement, account balances, persistence, or Finance queue eligibility (`FR-009-023` unchanged; no new endpoint — the existing queue projection gains the field).
  - The modal's **Approvals** tab renders the server-authoritative totals when present, with honest unavailable/known-zero states otherwise; transaction-level **Previous Decisions** remain inside the transaction's Inline Expanded Transaction and strictly separate from the sheet-level Approvals tab.

- **FR-009-029** (owner-approved, 2026-11-21; retained under the approved Manus v2 baseline): The Finance Sheet Details Modal and its transaction surfaces MUST implement the shared v2 presentation contract defined by 006 (`FR-006-038` and `FR-006-049`–`FR-006-052`): stable modal geometry across tabs (stable width, stable usable content height, stable header/summary area/tab bar, only the tab-content region scrolls, sparse tabs retain intentional whitespace — also at 390px); the card first-view content contract (`FR-006-038`), including the mandatory transaction date from `TransactionSummary.activeRevision.expenseDate` and the collapsed-card evidence summary; persistent card-level Finance actions (`FR-006-050`: Approve/Return/Reject directly visible when `allowedActions` permits — Approve primary / Return warning / Reject restrained destructive — never behind an overflow menu, preserving the senior-rejected Approve restriction `FR-009-014`); the readable typography floor (`FR-006-051`); and the inline-expansion focus lifecycle (`FR-006-052`). Finance decision semantics, `allowedActions` gating, settlement behavior, and authorization are unchanged (`FR-009-012`–`FR-009-018`, `FR-009-023`).
- **FR-009-030** (owner refinement UXC-V2-FU-03): Long sheet and transaction identifiers MUST use compact visual presentation, with the preferred truncated form `25ccedd9…145b` (or an equivalent repository-consistent truncation). The displayed value MUST remain recognizably derived from the true identifier; the underlying identifier is unchanged. The full value MUST remain available through an accessible disclosure mechanism — a tooltip or equivalent accessible compact-detail mechanism is acceptable — but hover MUST NOT be the only access path: keyboard focus MUST expose the complete value, and screen-reader users MUST have access to the complete identifier. Identifiers MUST remain direction-isolated in RTL, and the complete value MUST remain available for operational and debugging use. The same compact-identifier contract applies consistently to Management Review queue tables, transaction cards, inline details where an identifier is genuinely required, and modal headers/metadata where appropriate. A new dependency MUST NOT be introduced solely for this behavior. Sheet title and sheet reference remain distinct concepts and MUST NOT be visually concatenated into one identifier string.
- **FR-009-031** (owner refinement UXC-V2-FU-05): The Finance Manager queue table converges with the Cost Center queue table on the shared base column model (Sheet, Purchaser, Transactions, Amount, Status, Submitted) and the shared visual structure defined in FR-006-054. Finance uses the same base vocabulary without `Your` qualifiers where values represent the complete sheet/stage scope; Cost Center may retain `Your transactions` / `Your relevant amount` because its figures represent only the current manager's assigned scope. Header typography, sortable-column treatment, cell spacing, sheet-title/reference hierarchy, purchaser avatar treatment, amount alignment, status badge placement, submitted/date presentation, and compact identifier treatment (FR-009-030) are aligned so the three queue pages feel like stages of one workflow.
- **FR-009-032** (owner refinement UXC-V2-FU-06): The Finance queue page establishes a table-first review workspace per FR-006-055: compact header region (breadcrumb, compact eyebrow/stage identity, page title, primary action such as Refresh, export where role-specific), explanatory paragraphs shortened to one concise line or removed, no large permanent instructional panels, compact statistics instead of large KPI cards, and the table beginning materially higher so several rows are visible without initial page scrolling. Search, filter, sort, and role-state controls remain accessible and compact. Finance's comparable vertical rhythm, table start position, search/filter density, and shared table vocabulary match Cost Center and Senior; role-specific actions and status filters remain, and controls are not forced identical where role workflows genuinely differ.

- **FR-009-033** (UXC-V2-FU-07 — canonical queue status contract): For the current reviewer, a sheet queue item's Status column MUST use exactly two primary values: `Awaiting your decision` (when at least one current active transaction still requires a decision from this reviewer) and `Review completed` (when every current active transaction revision has a terminal Approved or Rejected decision for this required stage, i.e. the stage is fully resolved under the existing workflow rules). The Status column MUST NOT encode the role/stage name, transaction counts, percentage/progress, generic phrases such as `Needs attention`, compound phrases such as `N of N awaiting your decision` or `All decided`, or role-specific status synonyms such as `In progress`, `Senior Review`, or `Finance Review`. `Finance Review` MUST NOT be used as a primary Status-column value. Localization MUST support EN and FA.

- **FR-009-034** (UXC-V2-FU-07 — returned-transaction secondary context): When one or more returned-for-correction transactions are currently back at this reviewer and require a decision, display secondary context `1 returned transaction` (count = 1) or `N returned transactions` (count > 1) alongside the primary status. Hide this indicator when the count is 0. It never replaces the primary status and is never a third primary sheet status. Localization MUST support EN and FA.

- **FR-009-035** (UXC-V2-FU-08-B — Finance unavailable purchaser/submitted is a defect, not accepted UX): The `Purchaser unavailable` / `Date unavailable` presentation is NOT an accepted final product state. A sheet cannot legitimately reach Finance without purchaser identity and submission information. The missing values are treated as a defect pending `BUG-FIN-QUEUE-METADATA`; they MUST NOT be encoded as desired UX behaviour or as an acceptable unavailable fallback.

- **FR-009-036** (UXC-V2-FU-08-C — Finance sheet identity hierarchy, no UUID duplication): The Sheet cell MUST present the human-readable sheet title as primary text when available, with the compact reference as secondary metadata. A full UUID MUST NOT be displayed and then repeated in compact form beneath it. The full UUID MUST NOT be used as the primary visual label merely because another field is absent. If a human-readable title is missing from the Finance queue data, that is treated as a projection/mapping omission to be resolved during the `BUG-FIN-QUEUE-METADATA` assessment, not as justification for UUID-as-title.

- **FR-009-037** (UXC-V2-FU-08-D — compact summary counter non-wrapping): Summary/KPI numbers MUST never wrap digit-by-digit. Compact summary counters MUST render as one uninterrupted numeric value. This is a presentation requirement, not a change to calculations.

### Change Control

- **FR-009-025**: This feature MUST NOT change the Cost Center Manager workflow, the Senior Manager workflow, or any existing public contract except under separate authorization.
- **FR-009-026**: This feature MUST NOT require a new backend endpoint at this stage; implementation architecture is a planning decision.
- **FR-009-027**: Existing working-tree changes, including unrelated user changes, MUST be preserved; no Git writes are authorized by this specification.

### Key Entities

- **Finance Sheet Queue Item**: a Cost Sheet reference with the finance-eligible transaction count, per-currency totals, and review status where available. It is a presentation projection over the proven-complete finance-scoped set, not a persisted entity. Per the 2026-11-20 owner authorization (`FR-009-028`), each queue item additionally carries the owning Cost Sheet's server-computed whole-sheet `stageApprovedTotals` aggregate, identical for all items of one Sheet.
- **Finance-Eligible Transaction**: a transaction at the Finance stage reachable by Finance under existing workflow rules (senior-approved or senior-rejected per FR-037/FR-039), within the Finance Manager's role/scope.
- **Finance Transaction Card**: one compact review surface per finance-eligible transaction inside the Sheet Details Modal's Transactions tab, carrying prior Cost Center / Senior / Finance decision states as the compact stage identity + state marker presentation and Finance decision controls gated by `allowedActions`. Activating the transaction opens the Inline Expanded Transaction.
- **Inline Expanded Transaction**: a transaction detail region opened in place inside the Transactions tab on transaction activation (UX v3, 2026-11-21 — supersedes the former Transaction Detail Drawer entity), preserving Sheet context, with canonical sections: Transaction Details, Evidence, Previous Decisions, and Current Stage Actions. It never navigates away from the Sheet, never opens a second full modal over the Sheet modal, and exactly one transaction is expanded at a time.
- **Evidence Surface**: transaction-level evidence bound to exactly one transaction, rendered in the Inline Expanded Transaction's Evidence section (collapsed-card evidence summary per `FR-009-022`), reusing `EvidencePreview.vue` (thumbnails, image/PDF preview, overlay, download fallback, return-to-context).

---

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-009-001**: A Finance Manager can identify the Cost Sheets requiring finance attention and the number of finance-eligible transactions per sheet from the queue, without a flat transaction table as the primary surface.
- **SC-009-002**: 100% of amounts, counts, and statuses rendered in the Finance queue and modal are traceable to the current API response or explicitly marked unavailable; no invented value is rendered in any tested state.
- **SC-009-003**: In 100% of tested truncation/incompleteness cases, the interface never presents partial membership as complete; it renders an honest unavailable state with a retry.
- **SC-009-004**: Every decision issued records exactly one transaction; reject/return without a comment is refused in 100% of tested cases; prior Cost Center/Senior decisions remain unchanged after a Finance decision.
- **SC-009-005**: A senior-rejected transaction is never offered Approve by Finance in 100% of tested cases; Reject/Return remain offered per `allowedActions`.
- **SC-009-006**: The desktop and 390px queue/modal render with no horizontal scroll in English LTR and Persian RTL, in official Google Chrome, reusing the Pettycash management-review visual language.
- **SC-009-007**: Closing the modal returns focus to the originating Cost Sheet row in 100% of tested cases.
- **SC-009-008**: Evidence rendered in the modal is bound to the correct transaction in 100% of tested cases; preview overlay returns to the same transaction context.
- **SC-009-009**: No account-management/recharge controls appear in the Finance Expense Review module.
- **SC-009-010**: Existing Finance authorization is unchanged; unauthorized access is denied.

### Explicit Acceptance Criteria (mapped to the required scenarios)

1. **AC-1** (queue row = one Cost Sheet): Multiple eligible Finance transactions belonging to one Cost Sheet render as exactly one queue row.
2. **AC-2** (two sheets = two rows): Two distinct Cost Sheets render as two queue rows.
3. **AC-3** (modal exposes all eligible transactions): Opening a Cost Sheet exposes all of its eligible finance transactions.
4. **AC-4** (no silent omission): No sibling transaction is silently omitted from an apparently complete sheet; incomplete grouping yields an explicit unavailable state.
5. **AC-5** (prior decisions distinct): Each transaction exposes its own prior Cost Center/Senior/Finance decision state, stage-labeled and not collapsed.
6. **AC-6** (evidence bound to transaction): Evidence remains attached to the correct transaction.
7. **AC-7** (independent decisions): Finance actions remain independent per transaction.
8. **AC-8** (decision does not apply to siblings): A decision on one transaction does not apply to sibling transactions.
9. **AC-9** (senior-rejected restriction): Existing restrictions for Senior-rejected transactions remain enforced (Finance cannot approve).
10. **AC-10** (incomplete grouping → unavailable): Incomplete grouping produces an explicit unavailable state rather than misleading partial data.
11. **AC-11** (currency rule): Mixed currencies are never numerically summed.
12. **AC-12** (focus restoration): Closing the modal returns the user to the originating Cost Sheet context.
13. **AC-13** (English/LTR): English/LTR behavior works.
14. **AC-14** (Persian/RTL): Persian/RTL behavior works.
15. **AC-15** (authorization unchanged): Existing Finance authorization remains unchanged.
16. **AC-16** (no account-management controls): Account-management/recharge controls do not appear in this review module.

---

## Dependencies

- The existing Finance role authorization and role/responsibility scope model.
- The existing Cost Sheet and transaction lifecycle, stage-decision history, evidence visibility, and financial posting workflow (001 spec).
- The existing `GET /api/v1/queues/finance` flat transaction collection and `GET /api/v1/transactions/{id}` / `POST /api/v1/transactions/{id}/decisions` endpoints.
- The existing evidence content delivery path and inline-safe policy (001 `FR-060`).
- The existing English/Persian localization mechanism and Nextcloud application shell.
- The existing `@nextcloud/vue` 9.x UI library and Pettycash visual language.

## Assumptions

- The Finance Manager role and its base authorization already exist and are not redefined here.
- The finance-eligible set is defined by existing workflow rules (senior-approved or senior-rejected transactions routed to Finance per FR-037/FR-039), not by a new sheet-wide gate.
- The Senior Manager sheet-wide visibility gate (007 `FR-007-013`, 001 `FR-037` amendment) is scoped to the Senior queue and MUST NOT be extended to the Finance queue.
- A complete finance-scoped queue response is normally small enough to fit in one high-page-size response; the completeness guard exists because this is not guaranteed.
- Sheet identity fields (title/purchaser/submitted date) are not provided by the current flat finance queue and render as unavailable until a planning-decided additive backend capability exists.
- Desktop is the priority surface; the module must still behave correctly at the 390px floor in English LTR and Persian RTL.
- The module does not change financial decision semantics, posting semantics, currency rules, or audit rules.
- No Git writes are authorized; the working tree is preserved as found.

## Out of Scope

- Holder directory, account balance inspection, account ledger, holder management, recharge (Feature 008, Module B).
- Completed Cost Sheet account-history browsing (Feature 008).
- Database schema changes, migrations, or new backend endpoints at this stage.
- Finance authorization redesign or broadening/narrowing of Finance scope.
- Financial settlement-rule changes or new decision types.
- Correction/resubmission redesign.
- Cost Center Manager redesign or Senior Manager redesign.
- Bulk Finance approval.
- Finance-scoped XLSX export (not part of the approved journey).
- Mobile-specific redesign (desktop primary; 390px floor required).
- Planning, task generation, contract changes, implementation, and tests, which require separate authorization.

## Localization Requirements

- Primary language: English. Required localization: Persian.
- Every user-facing label, message, status, empty state, error state, and financial caption MUST be localizable.
- Persian presentation MUST be right-to-left; English presentation MUST be left-to-right.
- UUIDs and currency codes MUST remain readable and direction-isolated (LTR) even inside RTL pages.
- The module MUST render correctly in English LTR and Persian RTL at the 390px floor with no horizontal scroll.

## Versioning

- This is version 1 of the specification.
- Future changes require explicit authorization and must preserve the approved feature boundary and existing financial and authorization rules.
