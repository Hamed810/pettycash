# Implementation Plan: Finance Manager Expense Review

**Branch**: `009-finance-manager-expense-review` (logical feature directory; worktree branch `main` unchanged — no Git writes authorized) | **Date**: 2026-11-19 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/009-finance-manager-expense-review/spec.md`, itself codified from the owner-approved Finance Manager Expense Review UX/UI handoff. This plan is **planning only** — no code, no `tasks.md`, no commits, no backend/contract changes.

---

## Summary

Replace the Finance Manager's current transaction-first queue (`FinanceQueueView.vue` → flat `GET /api/v1/queues/finance` → route navigation to `/reviews/finance/:transactionId`) with a **Cost-Sheet-based queue**: the view groups the Finance-scoped pending transactions into one row per Cost Sheet, and opening a sheet shows its finance-eligible transactions in the shared **`SheetDetailsModal`** (Finance stage adapter) over the mounted queue — no route navigation as the primary flow. The modal reuses the shared transaction review surfaces (`TransactionReviewCard` generalized from `QuickReviewCard`, `TransactionDetailDrawer` generalized from `TransactionDetailsDrawer`, `DecisionConfirmation` generalized from `QuickDecisionModal`, `EvidencePreview`) so per-transaction decision, full detail, and the attachment preview layer come from the shared components Feature 006/007 already ship. Approve/Reject/Return reuse the existing single-transaction `POST /transactions/{id}/decisions` path, preserving FR-084 replay safety, FR-085 first-write-wins, stage-scoped audit, the mandatory-comment rule, and FR-039 (Finance cannot approve a senior-rejected transaction). The Cost Center and Senior Manager decisions remain visible per transaction, stage-labelled, and never overwritten by finance.

Per the specification, **no new backend endpoint is required at this stage**. Sheet-level identity fields (title, purchaser, submitted date) that the flat `GET /queues/finance` response does not carry render as an honest unavailable state until a planning-decided additive backend capability exists under separate authorization. The Senior Manager sheet-wide visibility gate (001 `FR-037` amendment) is scoped to the Senior queue and MUST NOT be extended to Finance — Finance uses independent per-transaction progression per 001 `FR-037`.

Everything is **frontend adaptation** over existing supported capabilities: the transaction detail read, the decision command path, the decision history, the evidence preview, the completeness/grouping logic (`useSheetQueue`), the reports store mechanics, and the Pettycash management-review visual language.

---

## Primary Planning Decision: Option A — Frontend grouping over existing Finance queue API

**Selected: Option A.** Rationale:

1. **Specification mandate.** `spec.md` explicitly states: "This feature MUST NOT require a new backend endpoint at this stage" (FR-009-026) and sheet identity fields "MUST render as an honest unavailable state until a planning-decided additive backend capability exists" (FR-009-011). Option A satisfies this directly; Option B would contradict the mandate.
2. **Existing data is sufficient.** `GET /api/v1/queues/finance` returns `TransactionSummary[]`, and every `TransactionSummary` carries `sheetId` (`src/types/api.ts:185`), enabling client-side grouping by Cost Sheet over a proven-complete set — the same approach Feature 006 uses for the Cost Center queue via `useSheetQueue.ts`.
3. **No correctness loss for the required journey.** The approved journey groups *finance-eligible* transactions per sheet (transactions at the Finance stage: senior-approved or senior-rejected per FR-037/FR-039). The flat queue already returns exactly that set, both senior outcomes (`DbExpenseTransactionRepository::findForFinanceQueue` includes `SENIOR_APPROVED` and `SENIOR_REJECTED`). Sheet-wide completion is not claimed; the row represents only its finance-eligible transactions.
4. **Precedent.** Feature 007's Senior queue began with frontend grouping over `GET /queues/senior` (flat) before its additive sheet read landed as a later authorized batch. Feature 009 follows the same proven pattern: connect the surface to connected data first, defer additive backend work.

**Trade-offs accepted:**
- Sheet title/purchaser/submitted date are unavailable from the flat queue → honest unavailable placeholders (FR-009-004, FR-009-011). This is the correct, honest behavior per the spec, not a defect.
- All-pages fetch of `GET /queues/finance` to prove completeness adds request volume vs. a hypothetical single sheet-scoped endpoint, but stays bounded and acceptable at the documented V1 scale (<100 active users, <10,000 transactions/year per constitution). A server-side Finance sheet endpoint is the documented future scaling trigger.

**Shared component architecture:** Finance uses the shared `SheetDetailsModal` (Finance stage adapter), `TransactionReviewCard` (generalized `QuickReviewCard`), `TransactionDetailDrawer` (generalized `TransactionDetailsDrawer`), `DecisionConfirmation` (generalized `QuickDecisionModal`), and `EvidencePreview` — reused by Cost Center, Senior Manager, and Finance Manager. Finance-specific behavior (allowedActions gating, Senior-rejected Approve restriction, independent per-transaction decisions, no bulk Finance action) is expressed through a thin Finance stage adapter, not a stage-specific modal or card.

Option B (additive Finance Cost Sheet read model) is **not chosen** at this stage. It remains a future, separately-authorized planning decision if the product later requires sheet-level identity fields or a sheet-wide Finance gate (which the spec does not require and FR-037's scope prohibits extending to Finance).

---

## Technical Context

**Language/Version**: TypeScript 5.x, Vue 3.5 (`<script setup lang="ts">`), PHP 8.2+ (compatibility floor per ACC-001). Frontend only — read-only presentation plus existing decision writes via unchanged endpoints.

**Primary Dependencies**: `@nextcloud/vue` 9.x, Pinia, Vue Router, `@nextcloud/l10n`, MDI icons, `@playwright/test` (official Google Chrome channel). No new dependencies.

**Storage**: N/A for the frontend (read-only presentation plus existing decision writes). No persistence changes.

**Testing**: Vitest + `@vue/test-utils` for unit/component behaviour; Playwright (official Google Chrome channel) for screenshots and viewport evidence; `scripts/dev/phpunit` is not needed (no backend change); `npm run check` (typecheck, lint, stylelint, build, e2e) at boundaries.

**Target Platform**: Nextcloud 34 application shell, desktop and 390px mobile, English LTR and Persian RTL, light/dark/forced-colours, Gregorian/Jalali independent calendar preference.

**Project Type**: Web application (existing Nextcloud app, `pettycash`).

**Performance Goals**: bounded all-pages fetch of `GET /queues/finance` per refresh; no per-row network calls for the sheet list; filtered/sorted views operate on a proven-complete loaded set without per-action requests; per-transaction detail reads only when a card's actions/details are opened (guarded, cached).

**Constraints**: Cost Center and Senior Manager workflows MUST NOT change (006 `FR-006-030/031`, 007 `FR-007-051`); no new dependencies; no Vuetify; no backend/contract changes at this stage; no sheet-level decisions; no fabricated values; existing working-tree changes preserved; no Git writes; the visual baseline is `CostCenterQueueView.vue` and `FinanceReviewView.vue`.

**Scale/Scope**: one review view (Finance) + one modal + shared review components + (no backend at this stage) + l10n keys + component tests + screenshot evidence.

---

## Constitution Check

| Gate | Status | Evidence / action |
|---|---|---|
| Compatibility baseline | PASS | PHP floor 8.2+ per ACC-001; database targets unchanged (`mysql`/`pgsql`/`sqlite`, ACC-002); TypeScript 5, Vue 3 unchanged. |
| Source-of-truth ordering | PASS | 001 spec/contracts remain authoritative; no workflow or domain rule redefined; FR-039 senior-rejected restriction preserved via server-side `allowedActions`. |
| Backend freeze | PASS | No file under `lib/`, `appinfo/`, or `tests/Backend/` changes at planning; frontend-only adaptation over existing endpoints. |
| DDD boundaries | PASS | Domain unchanged; new frontend code is Presentation only; no business rules move to views; server-side `allowedActions`/`FinanceDecisionPolicy` remain authoritative. |
| Visual evidence | PASS | Screenshots under `artifacts/visual-review/<task-id>/<iteration>/`; official Chrome channel. |
| Owner approval gate | PASS | Plan ends at a recorded request for approval; implementation batches are not started. |
| No fabricated data | PASS | completeness guard; honest unavailable/error states (no incomplete flat fallback table); stage-tagged decisions; sheet identity fields unavailable until backend proves them. |
| i18n / RTL / calendar | PASS | `direction`, `formatMoney`, `formatSheetDateTime`, `formatNumber` utilities; existing en/fa catalogues extended additively. |
| Auditability | PASS | decisions stay append-only/stage-scoped via the existing command path (FR-085, FR-051). |
| Component reuse | PASS | sheet-queue grouping/completeness is **extended from** `useSheetQueue`, not re-implemented in parallel; `SeniorSheetModal.vue`/`FinanceReviewView.vue` patterns reused, not copied. |

---

## Project Structure

### Documentation (this feature)

```
specs/009-finance-manager-expense-review/
├── spec.md            # Feature specification (transcribed from owner-approved UX handoff)
├── plan.md            # This file
├── research.md        # Phase 0 research notes (evidence routes, field sources, gaps)
├── data-model.md      # Phase 1 data model / TS interfaces / state model
├── quickstart.md      # Phase 1 dev/run/gate quickstart
└── contracts/         # Not created — no contract change at this stage (Option A)
```

No `tasks.md` is created by this plan (per mission).

### Source Code (changed files — implementation projection, NOT changed by planning)

```
src/
├── views/reviews/FinanceQueueView.vue        # sheet-first queue replacing the flat transaction table
├── composables/useFinanceQueue.ts            # Finance grouping/completeness/search/sort (new, extends useSheetQueue)
├── components/review/SheetDetailsModal.vue   # shared sheet modal shell; Finance stage via props/config/slots (replaces stage-specific FinanceSheetModal)
├── components/review/TransactionReviewCard.vue # shared compact card (generalized QuickReviewCard; Finance via allowedActions adapter)
├── components/review/TransactionDetailDrawer.vue # shared drawer (generalized TransactionDetailsDrawer; Finance read-only actions via adapter)
├── components/review/PriorDecisionHistory.vue   # shared transaction decision history (reuse)
├── components/review/EvidencePreview.vue        # shared evidence overlay (reuse verbatim)
├── components/review/DecisionConfirmation.vue   # shared decision confirmation (generalized QuickDecisionModal; reuse)
├── stores/reviews.ts                         # finance queue sheet view + guarded per-transaction reads (extend)
├── services/reviewsApi.ts                    # fetchFinanceQueue all-pages helper (extend)
├── types/api.ts                              # Finance sheet queue item / modal entry types (extend, additive)
├── l10n/{en,fa}.{json,js}                    # new Finance sheet catalogue keys (additive)
tests/Frontend/Component/FinanceQueue.spec.ts          # queue grouping/completeness (new)
tests/Frontend/Component/SheetDetailsModal.spec.ts     # shared modal behaviour (new)
tests/Frontend/Composable/FinanceSheetQueue.spec.ts    # useFinanceQueue pure functions (new)
tests/E2E/FinanceReview.spec.ts                        # sheet-row → modal → card → decision E2E (new/update)
```

### Authorized backend projection (separate authorization; additive only — NOT created by this plan)

None required at this stage per FR-009-026. Future possibility (not part of this plan): `GET /queues/finance/sheets/{sheetId}` additive read for sheet title/purchaser/submitted date.

---

## Key Design Decisions

### D1 — Option A: frontend grouping over existing Finance queue API (no new backend endpoint)

The Finance Cost-Sheet-first queue is derived entirely from the existing `GET /api/v1/queues/finance` flat transaction collection:

1. Fetch every page of `GET /queues/finance` (bounded all-pages loop, modeled on `fetchAllSeniorSheets`).
2. Deduplicate by transaction id (first copy wins, order preserved — `dedupeTransactions` from `useSheetQueue`).
3. Verify completeness over the accumulated set (all-rows-cover-total guard; short page or page cap → truncated → unavailable).
4. Group de-duplicated transactions by `sheetId` → one presentation row per sheet (`buildSheetQueue` from `useSheetQueue`).
5. Compute per-currency totals (currency + minor unit; never cross-currency sum — `collectTotals` from `useSheetQueue`).
6. Render one row per sheet; if completeness cannot be proven, render an honest unavailable state with retry (never a partial flat table).

This mirrors Feature 006's Cost Center approach exactly: frontend grouping over a flat paginated queue with a completeness guard. The finance-eligible set is already what the endpoint returns (both `SENIOR_APPROVED` and `SENIOR_REJECTED`, per `findForFinanceQueue`), so no server-side filtering or gate is needed.

### D2 — Modal over the mounted queue (no route navigation)

Selecting a Cost Sheet row opens the shared **`SheetDetailsModal`** (Finance stage adapter) over the mounted queue; the router is **untouched** for this primary flow (the existing `finance-review` route at `/reviews/finance/:transactionId` stays reachable for deep links but is not the primary entry). The modal:

- shows the sheet reference (always available), title/purchaser/submitted date as unavailable placeholders until a backend capability proves them (FR-009-004, FR-009-011);
- exposes the canonical tabs: Transactions, Approvals, Overview, History;
- pulls the sheet's finance-eligible transactions from the proven-complete queue set as `TransactionReviewCard`s;
- opens the shared preview layer and full-details drawer **one layer at a time** (single-overlay rule, 006 `FR-006-037`);
- returns focus to the originating Cost Sheet row on close (FR-009-009 / AC-12).

### D3 — Transaction-level review and decisions (reuse, unchanged)

Every action — quick decision on a card or full detail in the drawer — targets exactly one transaction through the existing single-transaction command path (`POST /transactions/{id}/decisions`, FR-085 first-write-wins, FR-084 replay safety, 001 `FR-033` single-decision rule). There is no sheet-level Approve/Reject/Return and no "decide all" (FR-009-012). Prior Cost Center and Senior decisions are rendered from `TransactionDetail.decisions[]` filtered to `stage === 'COST_CENTER'` / `'SENIOR'`, per transaction only (FR-009-019, 001 `FR-034`). The Finance decision being recorded is shown distinctly (FR-009-019).

Approach: decisions occur **inline inside the modal** (not via route navigation). The modal's per-transaction `TransactionReviewCard`s expose decision controls gated by server-granted `allowedActions` (`decisionAllowed(...) === true`, T026). A shared confirmation `DecisionConfirmation` (generalized `QuickDecisionModal`) opens per transaction with per-kind copy, mandatory comment for Reject/Return, optional comment for Approve, `expectedVersion`, submitting/disabled state, and no-scrim-close during submission. This is the smallest architecture consistent with the approved UX: no new route, no new backend command, reuse of `DecisionForm`-semantics. Finance-specific behavior (Senior-rejected Approve restriction, independent per-transaction decisions, no bulk Finance action) is expressed through the Finance stage adapter, not a stage-specific card or modal.

### D4 — Honest fields only

| Field | Rendering |
|---|---|
| Sheet reference (`sheetId`) | always available from the flat queue |
| Sheet title / purchaser / submitted date | **unavailable** until a planning-decided additive backend read exists (FR-009-011); honest placeholder, never fabricated |
| Finance-eligible transactions | count + per-currency totals of de-duplicated finance-stage transactions in the sheet |
| Review status | per-sheet finance progress (awaiting / decided counts), derived only from proven-complete data; unavailable otherwise |
| CC / Senior / Finance decisions | per-transaction, stage-tagged, from `TransactionDetail.decisions[]` |
| Sheet-wide Finance gate / completion | **not claimed** — Finance uses independent per-transaction progression (001 `FR-037`); the Senior sheet-wide gate (D1 there) is scoped to Senior and NOT extended to Finance |

### D5 — Visual language

Use the established Pettycash/Purchaser tokens and the 006/007 quick-review components as the baseline: Nextcloud shell, `--font-size-*`, `--color-*`, `StatusBadge`, card surfaces, `ResponsiveTable`, tabular rows, `EvidencePreview` overlay, focus treatment. Desktop primary; mobile: one column, full-screen overlays one at a time, `≥44px` touch targets, no horizontal scroll at 390px. Baseline visual comparison: `CostCenterQueueView.vue` + `FinanceReviewView.vue`.

### D6 — No transaction-level filter/sort at this stage

The spec permits transaction-level search/filter/sort inside the modal "where required" (FR-009-005) but does not require it. The smallest architecture omits it initially: the modal lists all finance-eligible transactions of the sheet (order: newest activity first, deterministic). Queue-level filtering/search/sorting, if later added, acts on Cost Sheets only (never transaction-level duplication at the queue). This avoids copying unrelated Senior controls (007's two-level toolbar, expense-type facets, XLSX export) that Finance does not need.

---

## Cost Sheet Queue Model

The queue row projection is derived only from reliable data:

- **Cost Sheet public reference**: `sheetId` from each `TransactionSummary` — always available.
- **Transaction count**: number of de-duplicated finance-eligible transactions proven in the response for that sheet (never approximated — FR-009-009).
- **Review progress/status**: count of finance-eligible transactions awaiting decision vs. total finance-eligible count in the sheet, derived only from proven-complete data; unavailable if completeness not proven.
- **Currency-separated monetary summary**: per-currency totals (group by `currency` + `minorUnit`) over the sheet's finance-eligible transactions; multiple currencies shown separately; no cross-currency sum (FR-009-010).
- **Available dates**: latest activity instant (max of `activeRevision.createdAt`) where parseable; `null` → unavailable marker.
- **Title / purchaser / submitted date**: NOT available from the flat queue → honest unavailable placeholders (FR-009-004, FR-009-011); never fabricated.

If title/purchaser/submitted date are unavailable from the chosen architecture: the row renders an honest unavailable state for those fields only (e.g., "Title unavailable", "Owner unavailable" per the 006/007 pattern), while the sheet reference, transaction count, per-currency totals, and activity date remain shown. The sheet is still actionable (transactions visible in the modal) — missing identity fields do not block the journey.

---

## Complete Grouping

Completeness is established exactly as follows (modeled on 006/007, adapted to Finance):

1. **Page traversal**: `fetchAllFinanceQueues(pageSize)` loops `GET /queues/finance?page=N&pageSize=P` following the server's `total`, accumulating rows until `items.length >= total`, a short page arrives, or a page cap is hit. Bounded page size (proposed `FINANCE_SHEET_PAGE_SIZE = 100`, matching `SENIOR_SHEET_PAGE_SIZE`) bounds round trips while covering every finance-eligible transaction in scope.
2. **Page-size contract limit**: `PageSize` maximum per contract (100); the loop never requests above it.
3. **Deduplication**: `dedupeTransactions` by transaction id; first copy wins; order preserved. The server already de-duplicates (`paginateUniqueQueue` pattern noted in 007), but client-side dedupe is the safety net for overlapping finance assignments.
4. **Duplicate IDs**: counted once per sheet; `transactionCount` reflects de-duplicated unique transactions.
5. **Inconsistent totals**: if accumulated `items.length < server.total` → truncated → honesty guard fails → unavailable state.
6. **Missing pages**: if a page fetch fails mid-loop → error state with retry; no partial grouping presented as complete.
7. **Concurrent queue changes during fetch**: after any recorded decision, the queue refetches and the modal refreshes from the server (decision flow, §Finance Decision Flow). Between queue load and modal open, if the sheet's last finance-eligible transaction is decided elsewhere, the modal renders an honest refreshed state (server refetch), never a synthesized "empty".
8. **Partial fetch failure**: queue-level error with retry action (modeled on `SeniorQueueView.vue`).
9. **Stable ordering**: within a sheet, newest activity first; across sheets, deterministic (newest activity desc, tiebreak by sheet reference). Local sort over the proven-complete set; no per-sort network request.

**Fallback/unavailable state**: if completeness cannot be proven (truncation, unresolved total, page cap, fetch failure), the view renders an honest **unavailable/error state with a retry** action. It MUST NOT render a potentially incomplete flat transaction table as a fallback, MUST NOT present partial counts or rows as the full sheet, and MUST NOT infer completeness from incomplete client-side data.

---

## Finance Sheet Review Modal

A reusable Finance modal based on established management-review patterns:

**Decision: use the shared `SheetDetailsModal`** (Finance stage adapter), modeled on the management-review modal pattern (queue mounted behind, single overlay, focus restore to originating row). Reuse shared primitives where they do not distort Finance behavior:

| Need | Reuse / adapt | Gap to build |
|---|---|---|
| Sheet-first modal shell + focus restore | shared `SheetDetailsModal.vue` (Finance stage adapter; queue mounted behind, single overlay, focus restore to originating row) | Finance adapter config; no per-role modal |
| Compact transaction card + action bar | `QuickReviewCard.vue` → generalized `TransactionReviewCard` (props: `transaction`, evidence, `allowedActions`, `busy`; emits `openReview/openDetails/openPreview/decide`) | Finance-stage variant wired to finance `allowedActions` (canApprove gated by `SENIOR_APPROVED` only, per `FinanceDecisionPolicy`); no bulk Finance action |
| Full details drawer | `TransactionDetailsDrawer.vue` → generalized `TransactionDetailDrawer` (Description + History tabs, footer decide controls, read-only fields with unavailable states) | Wire with Finance sheet header + stage-tagged decisions; Finance actions via stage adapter |
| Decision confirmation + mandatory comment | `QuickDecisionModal.vue` → generalized `DecisionConfirmation` (per-kind copy, `requiresExplanation`=REJECT‖RETURN, `expectedVersion`, submitting/error/toast, single transaction via `reviewsStore.recordDecision`) | Reuse unchanged (stage resolved server-side; Senior-rejected Approve restriction preserved via `allowedActions`) |
| Attachment thumbnails | `EvidenceCard.vue` / `QuickReviewCard` thumbnail strip (2 + `+N more`) | Reuse presentation |
| Preview layer (images/PDF/unknown) + prev/next + `1/N` | `EvidencePreview.vue` (inline-safe policy, focus trap/scrim, Escape close, object-URL revocation) | Reuse verbatim |
| Per-transaction detail + capability gating | `reviewsStore.fetchManagerTransactionDetail` guarded channel (`ManagerTransactionReadState`), `decisionAllowed`/`DECISION_CAPABILITY` | Reuse for Finance modal cards |

Required modal behavior:
- queue remains mounted behind modal (no route navigation as primary flow);
- exposes canonical tabs: Transactions, Approvals, Overview, History;
- one transaction card per finance-eligible transaction;
- evidence attached to correct transaction (each card shows only its own `evidence[]`);
- prior Cost Center decision visible (stage `COST_CENTER`, advisory per FR-034);
- prior Senior decision visible (stage `SENIOR`, final management per FR-036);
- existing Finance decision visible where applicable (stage `FINANCE`);
- transaction-specific action entry via `allowedActions`;
- independent decisions (one `POST /transactions/{id}/decisions` per action);
- no bulk action;
- closing returns focus to originating Cost Sheet row;
- accessible: keyboard-operable, focus trap, `aria-modal`, role=dialog, LTR/RTL-safe, 390px floor.

---

## Transaction Decision Flow

Decisions occur **inline inside the modal** (D3), preserving `FinanceReviewView.vue` and `DecisionForm` semantics:

1. A card action (or drawer footer "Decide this transaction") is enabled **only** on an explicit server-granted capability (`decisionAllowed(allowedActions, kind) === true`, from the guarded detail read) — never on button presence alone (T026).
2. The confirmation modal opens with per-kind copy, the transaction summary (id, title/description, sheet reference, amount, expense date), and `expectedVersion` from the detail read.
3. Comment rule: Approve optional; Reject and Return mandatory (`requiresExplanation`). Submitting disables controls, blocks scrim close, sends one `POST /transactions/{id}/decisions` (`DecisionWrite { decision, explanation, expectedVersion }`) with `X-PettyCash-Command-ID` idempotency header.
4. Success (`DecisionResult`): the card re-renders from the server's `result.transaction`; the per-transaction detail cache is cleared as stale; a toast confirms; the sheet's progress derives from the refetched queue set.
5. Failure: FR-085 `409`/`SUPERSEDED_ATTEMPT`, stale version (`fieldErrors.expectedVersion`), or explanation-less reject/return (`fieldErrors.explanation`) rendered distinctly; no local status fabricated; queue refetch reflects server only.
6. Cost Center and Senior decisions are never overwritten: the command path records a `FINANCE`-stage decision and appends to the same immutable history (verified in `ReviewController::decide` stage routing and `WorkflowDecisionRepository`).

The existing `FinanceReviewView.vue` route (`/reviews/finance/:transactionId`) is preserved for deep links but is no longer the primary entry; the modal is the primary flow per the approved journey.

---

## Evidence Integration

Reuse the existing evidence viewer; no redesign (FR-009-022):

- Evidence metadata per transaction: each card renders its own `evidence[]` from the guarded detail read as thumbnails/metadata.
- Preview: clicking a thumbnail opens `EvidencePreview.vue` at exactly that document with the transaction's document set for Previous/Next and `1/N` counter.
- Image/PDF rendering: inline-safe closed set (`utils/evidencePreview.ts` + 001 `FR-060`/005 `FR-005-042`): `application/pdf` → existing viewer; PNG/JPEG/GIF/WebP/BMP → large image; `text/plain` → text; every other media type → metadata note + open/download only (never inline).
- Download fallback: download control for non-inline or deactivated evidence.
- Return to context: closing the preview overlay returns to the same transaction card in the modal.
- No evidence mutation: no upload/replace/delete controls.

---

## Search / Filter / Sort

- **Queue level**: not required at this stage. If later added, acts on Cost Sheets only (sheet reference search; sort by reference/activity/count) — never transaction-level duplication at the queue (FR-009-005).
- **Modal level (transaction)**: not required at this stage (D6). The modal lists all finance-eligible transactions in deterministic order (newest activity first). Transaction-level search/filter/sort inside the modal is a deferred planning decision ("where required" per FR-009-005), not copied automatically from Senior.
- No currency, amount-range, attachment, or vendor filters (Senior's retired controls not copied).

---

## RTL / LTR / Accessibility

- **Localization**: English primary, Persian required. Every user-facing label/message/status/empty/error/financial caption localizable. New catalogue keys under `reviews.financeSheet.*` (and any `finance.*` additions) added to `l10n/{en,fa}.{json,js}`.
- **RTL/LTR**: `direction` utility + `:dir="direction"` binding (as `SeniorQueueView.vue` correctly does, avoiding the hardcoded-`direction: rtl` bug fixed in `FinanceQueueView.vue`/`CostCenterQueueView.vue`). UUIDs and currency codes direction-isolated (LTR) even inside RTL pages (`<bdi dir="ltr">` per `SeniorSheetModal.vue` reference pattern).
- **Calendar**: Gregorian/Jalali independent preference via existing `formatSheetDateTime`/locale utilities.
- **Keyboard**: every queue row and action keyboard-operable with visible focus indicator (constitution V).
- **Modal focus trap**: `role="dialog"`, `aria-modal="true"`, labelled by sheet identity; Escape/scrim/close-button close; focus returns to originating Cost Sheet row on close.
- **Accessible evidence/decision controls**: preview dialog name = file name; decision confirmation accessible; `≥44px` touch targets at 390px.
- **Desktop primary**: 1440×900 English LTR baseline; 390×844 Persian RTL mobile; dark theme/forced-colours spot-check.

---

## Test Strategy

Targeted test layers (no `tasks.md`; test planning only):

### Unit / composable (Vitest)

1. **N transactions in one sheet → one row**: grouping produces exactly one `FinanceSheetQueueItem` per unique `sheetId`.
2. **Two sheets → two rows**: distinct `sheetId`s produce distinct rows.
3. **Complete all-page grouping**: completeness guard returns `complete: true` when accumulated items cover `total`.
4. **Incomplete grouping → unavailable state**: truncation/unresolved-total/page-cap returns `complete: false` with reason; UI renders unavailable + retry, never partial flat table.
5. **Duplicate transaction suppression**: `dedupeTransactions` counts one transaction once (first copy wins).
6. **Currency-separated totals**: `collectTotals` groups by `currency`+`minorUnit`; no cross-currency sum.
7. **Modal transaction-card count**: modal renders one card per finance-eligible transaction in the sheet.
8. **Correct evidence-to-transaction association**: each card's evidence bound to its transaction id only.
9. **One Finance decision affects only one transaction**: `recordDecision` posts exactly one transaction id; sibling entries unchanged.
10. **Senior-rejected restriction preserved**: `canApprove` false for `SENIOR_REJECTED`; Approve control absent per `decisionAllowed`.
11. **Modal close restores originating queue context**: focus returns to the activating sheet row control.
12. **Persian/RTL**: LTR/RTL layout correct at 390px; UUIDs/currency codes direction-isolated.
13. **390px behavior**: no horizontal scroll (`scrollWidth <= clientWidth`); `≥44px` touch targets.

### Frontend component (Vitest + @vue/test-utils)

- `FinanceQueueView`: sheet-first presentation, honest empty/unavailable/error states with retry, no incomplete flat-table fallback.
- `FinanceSheetModal`: queue stays mounted behind, no route navigation on open, single-overlay stack, cards/drawer/preview one layer at a time, focus restore on close.

### E2E (Playwright, official Chrome channel)

- Sheet-row → modal → card → decision entry path (replaces the old transaction-row → `/reviews/finance/:id` route entry).
- Preserve existing settlement/idempotency/negative-balance assertions (FR-040, FR-042, FR-084, FR-085).

### Existing tests

- `FinanceQueueView`/`FinanceReviewView` coverage updated in place to the sheet presentation; no test deleted or skipped; CC/Senior workflow tests untouched. `npm run check`, targeted Vitest, scoped lint at boundaries.

---

## Performance

**Chosen design: frontend all-page aggregation over `GET /queues/finance`.**

- **Maximum expected request pattern**: one bounded all-pages fetch (`GET /queues/finance?page=N&pageSize=100`, up to ~100 pages = 10,000 transactions cap) per queue refresh, plus guarded per-transaction detail reads only when a card is opened.
- **Why acceptable**: V1 scale is <100 active users and <10,000 transactions/year (constitution). A Finance Manager's pending finance-eligible set is a small subset. The all-pages loop is bounded and stops at a short page or cap.
- **Future scaling trigger**: if the finance-eligible set routinely exceeds a single complete response or per-refresh latency becomes unacceptable, introduce a server-side `GET /queues/finance/sheets` additive endpoint (separately authorized — not part of this plan).
- No premature optimization: no backend work, no caching layer, no schema change at this stage.

---

## Contract Impact

**No new endpoint is needed (Option A).** The canonical OpenAPI contract `specs/001-pettycash-v1-core/contracts/ocs-api.yaml` remains **unchanged** at this stage:

- `GET /api/v1/queues/{stage}` already covers `stage: finance` (`appinfo/routes.php:61`, `review#index`).
- `GET /api/v1/transactions/{id}` and `POST /api/v1/transactions/{id}/decisions` already exist and are reused unchanged.
- No schema additions, no new route, no contract amendment required for the frontend-only adaptation.

**Contract artifact**: not created (no contract change). If a future planning cycle chooses Option B (additive Finance sheet read), the contract delta would be defined then and ratified with 001.

Generated types (`src/types/api.ts`, `src/generated/api.ts`): extended additively only for frontend presentation types (Finance sheet queue item, modal entry) — no contract-driven regeneration required at this stage.

---

## Data Model

**No schema change.** Prefer no database schema change (spec FR-009-026; mission data-model rule). The queue row is a presentation projection over existing `TransactionSummary` data (`sheetId`, `activeRevision`, `stage`, `status`, `finalOutcome`, `latestDecision`, `evidenceCount`, `version`). Sheet identity fields (title/purchaser/submitted date) are intentionally unavailable from the flat queue and render as honest placeholders until a separately-authorized backend capability provides them.

If planning determines a schema change would be required, stop and report it as an owner-controlled architectural decision rather than silently including it. **No schema change is required by this plan.**

---

## Reuse Map (existing assets → this feature)

| Need | Reuse / adapt | Gap to build |
|---|---|---|
| Sheet-first grouping + completeness + search + sort | `useSheetQueue.ts` (`SHEET_QUEUE_PAGE_SIZE`, `checkSheetQueueCompleteness`, `dedupeTransactions`, `buildSheetQueue`, `collectTotals`) | `useFinanceQueue.ts` — Finance all-pages fetch + grouping, extending the same guard (no parallel guard implementation) |
| Queue shell/visual language | `CostCenterQueueView.vue`, `SeniorQueueView.vue`, `ResponsiveTable`, `queueColumns.ts`, `StatusBadge` | `FinanceQueueView.vue` — sheet presentation replacing flat table |
| Shared Sheet Details Modal shell | shared `SheetDetailsModal.vue` (Finance stage adapter via props/config/slots: tabs Transactions/Approvals/Overview/History, sheet identity, status, currency, submitted, owner, transaction count, recorded amount, latest approved amount, focus trap, Escape, close, focus restoration, RTL/LTR, responsive) | Finance adapter config; no per-role modal |
| Compact transaction card + action bar | `QuickReviewCard.vue` → generalized `TransactionReviewCard`; Finance-stage variant wired to finance `allowedActions` (canApprove gated by `SENIOR_APPROVED` only, per `FinanceDecisionPolicy`); no bulk Finance action | Card generalization; Finance allowedActions adapter |
| Full details drawer | `TransactionDetailsDrawer.vue` → generalized `TransactionDetailDrawer`; wire with Finance sheet header + stage-tagged decisions; Finance actions via stage adapter (Approve/Reject/Return gated by `allowedActions`; no sheet-level decide) | Drawer sections (Transaction Details / Evidence / Previous Decisions / Current Stage Actions); Finance stage adapter |
| Decision confirmation + mandatory comment | `QuickDecisionModal.vue` → generalized `DecisionConfirmation` (per-kind copy, `requiresExplanation`=REJECT‖RETURN, `expectedVersion`, submitting/error/toast, single transaction via `reviewsStore.recordDecision`) | Reuse unchanged (stage resolved server-side; Senior-rejected Approve restriction preserved via `allowedActions`) |
| Attachment thumbnails | `EvidenceCard.vue` / `QuickReviewCard` thumbnail strip (2 + `+N more`) | Reuse presentation |
| Preview layer | `EvidencePreview.vue` | Reuse verbatim |
| Per-transaction detail + capability gating | `reviewsStore.fetchManagerTransactionDetail` + `decisionAllowed` | Reuse for Finance modal cards |
| All-pages queue fetch | `reviewsApi.fetchAllSeniorSheets` pattern | `reviewsApi.fetchAllFinanceQueues` (new helper, modeled on senior) |
| Store | `useReviewsStore` (`financeQueue`, `fetchFinanceQueue`) | Extend with finance sheet-view state + guarded detail reads (reuse existing patterns) |
| L10n | `l10n/en.json`, `l10n/fa.json` | Additive `reviews.financeSheet.*` keys |

---

## State Model

| State | Owned by | Values / transitions |
|---|---|---|
| Finance queue page | `reviewsStore.financeQueue` (`TransactionPage`) | loading → loaded | error; proven-complete flag from completeness guard |
| Sheet list (presentation) | `useFinanceQueue` derived | one row per sheet; rows derived only when set complete; otherwise honest unavailable/error + retry — never partial flat table |
| Open modal | queue view local (`openSheetId`) | sheet list → modal; modal → closed (focus restore) |
| Active overlay | queue/modal local | none → modal → (details drawer \| preview) → back; one layer at a time |
| Preview | `EvidencePreview.vue` local | closed → open at doc; next/prev; close (Escape/scrim/button) |
| Full detail | `TransactionDetailsDrawer.vue` local | readState loading/loaded/forbidden/notFound/unavailable |
| Decision modal | `QuickDecisionModal`/`DecisionForm` local | closed → open(kind) → submitting → decided \| error; close on success |
| Per-transaction detail cache | `reviewsStore.managerTransactionDetails` + `managerTransactionReads` | loading → loaded; cleared after decision (stale) |

All derived statuses come only from the loaded response or explicit unavailable; no local status fabricated after refusal.

---

## Modal Open / Close Contract

- **Open**: the sheet row's activation control sets `openSheetId` and focuses the modal container (`role="dialog"`, `aria-modal="true"`, labelled by sheet reference). The queue remains mounted and inert behind the scrim. No router call.
- **Sheet data**: modal renders from the proven-complete queue set immediately (reference, count, per-currency totals, activity date shown; title/purchaser/submitted as unavailable placeholders). No per-sheet detail read exists at this stage (Option A).
- **Single overlay**: opening the modal closes nothing else; opening details drawer or preview pushes one layer above; queue and modal stay in DOM.
- **Close path**: Escape, scrim click (never during submitting decision), close button, or after successful-decision toast. Focus returns to originating sheet row control. A sheet whose last finance-eligible transaction was decided elsewhere shows honest refreshed state (server refetch), never synthesized "empty".
- **Mobile**: full-screen overlays one at a time, `≥44px` targets, no horizontal scroll, RTL mirrored.

---

## Finance Decision Flow

1. Card action opens confirmation modal enabled only on explicit `allowedActions` capability (`decisionAllowed === true`).
2. Confirmation: per-kind copy, transaction summary, `expectedVersion`; Approve comment optional, Reject/Return comment mandatory.
3. Submit: one `POST /transactions/{id}/decisions` with `X-PettyCash-Command-ID`; controls disabled, scrim blocked during flight.
4. Success: card re-renders from `result.transaction`; detail cache cleared; toast; sheet progress from refetched queue.
5. Failure: FR-085/084/`fieldErrors` rendered distinctly; no fabricated status; queue refetch reflects server.
6. CC/Senior decisions never overwritten: immutable, stage-tagged, append-only.

---

## Risks and Mitigations

### Technical Risks

| Risk | Likelihood/Impact | Mitigation |
|---|---|---|
| Finance-eligible set larger than safe single-response completeness threshold (pagination/completeness) | Medium / Medium | Bounded all-pages loop + honesty guard + unavailable/retry state; never partial flat fallback (D1, spec FR-009-007/008) |
| Sheet identity fields (title/purchaser/submitted) unavailable from flat queue | High (until backend) / Low | Honest unavailable placeholders (spec FR-009-011); journey remains actionable via reference + transactions |
| Duplicate transaction ids from overlapping finance assignments | Medium / Low | Client-side `dedupeTransactions` first-copy-wins (server `paginateUniqueQueue` also de-duplicates) |
| Multi-currency cross-summing | Medium / High | Per-currency grouping by `currency`+`minorUnit`; `formatMoney` reuse; no cross-currency total (spec FR-009-010) |
| Detail read without capabilities enables unproven actions | Low / High | Guarded per-transaction detail + `decisionAllowed(...) === true` only (T026) |
| Modal opened from wrong overlay stack | Medium / Low | Single-overlay rule + focus restore + tests (006 `FR-006-037`) |
| Route deep-link (`/reviews/finance/:id`) conflicts with modal primary flow | Low / Low | Route preserved for deep links; modal is primary entry per approved journey |
| Mockup/design drift (no Finance-specific reference) | Medium / Low | Baseline is `CostCenterQueueView.vue` + `FinanceReviewView.vue` + existing components; no new design system |

### Business / Data-Integrity Risks

| Risk | Mitigation |
|---|---|
| Finance decision appears to overwrite CC/Senior decision | Decisions immutable, stage-tagged, append-only; invariant tested; UI shows both stage decisions |
| Sheet-wide Finance gate misapplied | Finance uses independent per-transaction progression (001 `FR-037`); Senior sheet-wide gate scoped to Senior only, NOT extended |
| Partial membership presented as complete | Completeness gate + honest unavailable state; never partial flat fallback (spec FR-009-007/008) |
| Approve offered on senior-rejected transaction | `FinanceDecisionPolicy::canApprove` false for `SENIOR_REJECTED`; `decisionAllowed` gates UI (spec FR-009-014, AC-9) |
| Mandatory-comment rule bypassed | Server-side `recordDecision` + shared-modal mirror (spec FR-009-015) |
| Account-management controls leak into Finance module | Scope guard in spec FR-009-024; Feature 008 surfaces excluded; focused tests assert absence |
| Fabricated sheet identity/amounts | Honesty rule: unavailable fields marked, never fabricated (spec FR-009-006) |

---

## Implementation Sequence (bounded batches — plan only; task authorization and evidence live in a future `tasks.md`)

Ordered so the core surface works on connected data first, with no backend batches at this stage.

- **Stage 1 — Foundation (frontend)**: catalogue keys (`reviews.financeSheet.*` en/fa), `useFinanceQueue` (all-pages fetch, completeness guard, dedupe, grouping, per-currency totals — extends `useSheetQueue`, no parallel guard), unit tests. Works on `GET /queues/finance` connected data.
- **Stage 2 — Sheet queue UI (frontend)**: `FinanceQueueView` sheet presentation + honest empty/unavailable/error states with retry; no incomplete flat-table fallback (spec FR-009-007/008). Baseline visual gate against `CostCenterQueueView.vue`.
- **Stage 3 — Sheet modal + review surfaces (frontend)**: shared `SheetDetailsModal` shell (Finance stage adapter; queue stays mounted behind), single-overlay stack, `TransactionReviewCard`s, shared `TransactionDetailDrawer`, shared `DecisionConfirmation`, thumbnails + preview reuse. Proof of per-transaction decision independence and CC/Senior non-overwrite invariants; modal close restores focus to originating row. Modal exposes canonical tabs: Transactions, Approvals, Overview, History.
- **Stage 4 — Mobile + RTL + l10n**: 390px, Persian RTL, focus/`44px` targets, no horizontal scroll; en/fa strings; UUID/currency direction isolation.
- **Stage 5 — E2E + verification**: sheet-row → modal → card → decision path; settlement/idempotency/negative-balance assertions preserved; `npm run check` (typecheck, lint, stylelint, build, e2e); official-Chrome screenshots under `artifacts/visual-review/`.
- **Stage 6 — Owner visual gate**: recorded owner approval per stage; no later stage starts before its gate (mirrors 006 `FR-006-025`).

**Deferred (not part of this plan)**: transaction-level filter/sort inside modal (D6); additive Finance sheet read for title/purchaser/submitted date (Option B, separately authorized); Finance-scoped XLSX export (out of scope per spec).

Each stage ends at a recorded owner approval gate; no later stage starts before its gate.

---

## Acceptance Criteria (mapped to the spec's success criteria)

1. **SC-009-001**: Finance Manager identifies Cost Sheets requiring finance attention and finance-eligible transaction count per sheet, without flat table as primary surface.
2. **SC-009-002**: 100% of amounts/counts/statuses traceable to response or explicitly unavailable in every tested state.
3. **SC-009-003**: In 100% of tested truncation/incompleteness cases, honest unavailable state with retry — never partial membership as complete.
4. **SC-009-004**: Every decision = exactly one transaction; reject/return without comment refused in all tested cases; prior CC/Senior decisions unchanged after Finance decision.
5. **SC-009-005**: Senior-rejected transaction never offered Approve by Finance in 100% of tested cases; Reject/Return offered per `allowedActions`.
6. **SC-009-006**: Desktop and 390px queue/modal render with no horizontal scroll in English LTR and Persian RTL, official Chrome, reusing Pettycash management-review visual language.
7. **SC-009-007**: Closing modal returns focus to originating Cost Sheet row in 100% of tested cases.
8. **SC-009-008**: Evidence bound to correct transaction in 100% of tested cases; preview overlay returns to same transaction context.
9. **SC-009-009**: No account-management/recharge controls appear in Finance Expense Review module.
10. **SC-009-010**: Existing Finance authorization unchanged; unauthorized access denied.

Plus the 16 explicit acceptance criteria (AC-1–AC-16) from `spec.md`.

---

## Open Questions — resolution status

No genuine open questions requiring owner decision were identified. The specification was codified from the approved UX handoff and all product decisions are settled. The following are **planning notes, not open questions**:

1. **Option A vs Option B** → **Resolved (D1)**: Option A (frontend grouping over existing API) chosen; no new backend endpoint required at this stage per spec FR-009-026.
2. **Transaction-level filter/sort in modal** → **Deferred (D6)**: permitted "where required" (spec FR-009-005) but not required; omitted from this plan's smallest architecture.
3. **Sheet identity fields (title/purchaser/submitted)** → **Resolved (FR-009-011)**: honest unavailable until a planning-decided additive backend capability exists.
4. **Route deep-link handling** → **Planning decision**: `finance-review` route preserved for deep links; modal is primary entry per approved journey (spec does not require removal).
5. **Backend authorization** → None required at this stage (frontend-only); future additive backend work would be separately authorized.

No remaining genuine ambiguity. The only outstanding item before implementation is task-generation authorization (creation of `tasks.md`), which is outside this mission.

---

## Information Still Required Before Task Generation

- Consent to proceed from planning to task generation (`/speckit-tasks`) and to create `tasks.md` under this feature directory.
- Confirmation that the current working-tree user changes (modified review/evidence components) are to be preserved and reused as the baseline for the Finance surfaces.
- Confirmation that `CostCenterQueueView.vue` + `FinanceReviewView.vue` are the agreed visual baseline.

## Follow-up — server-authoritative whole-sheet stage totals on the Finance queue (owner-authorized 2026-11-20, not yet implemented)

**Owner authorization**: `spec.md` `FR-009-028` — aggregate whole-Cost-Sheet stage-approved monetary disclosure for Cost Sheets already legitimately represented in the authorized Finance review queue. **Contract**: the additive optional `stageApprovedTotals` on `TransactionSummary` (`specs/001-pettycash-v1-core/contracts/ocs-api.yaml`), emitted only on `GET /queues/finance` items. **No new Finance endpoint** — the existing queue projection gains the field, keeping `FR-009-026` intact. Tasks: `UXC-7F-01/04` in `specs/009-finance-manager-expense-review/ux-convergence-tasks.md`.

- **Per-item sheet-level aggregate**: each Finance queue item carries the owning Cost Sheet's whole-sheet approved totals (Cost Center / Senior / Finance, one `Money` per stage in the sheet's single currency). Every item of the same Sheet exposes the identical value; the frontend grouping (`useFinanceQueue.buildSheetQueue` → `FinanceSheetQueueItem`) consumes one consistent value per Sheet and MUST NOT sum the finance-eligible transaction subset client-side.
- **Efficient per-distinct-Sheet derivation (required by the owner decision)**: the queue projection must compute the aggregate once per **distinct** Sheet on the page — e.g., a batched/`IN`-set read over the distinct `sheet_id` values of the page (mirroring `SheetSummaryQuery::postedAmountsFor(sheetDbIds)`'s batched per-sheet shape) reusing `SheetTotals::fromRows` — not one query per row. Performance MUST NOT be solved through a schema or persistence change; if implementation inspection proves the additive queue projection technically unsafe, stop and report rather than redesigning the contract.
- **Authorization unchanged**: Finance queue eligibility and scope gates stay exactly as they are (`FR-009-023`); the aggregate widens no transaction, evidence, actor, comment, or individual-decision visibility, and adds no Feature 008 account-management control (`FR-009-024`).
- **Frontend consumption (UXC-7F-05)**: `FinanceQueueView.vue` builds the Approvals tab's stage states from the server aggregate when present (via the grouped `FinanceSheetQueueItem`); `SheetApprovalsSummary.vue` unchanged; the honest unavailable/known-zero fallback remains for missing/failed payloads; `src/utils/stageApprovals.ts` stays as the fallback path only.
- **Testing (UXC-7F-04/06)**: backend — same aggregate value for all items sharing a Sheet; approve/reject/return/undecided and superseded-revision rules; no hidden transaction/detail disclosure; batched derivation (no per-row N+1); frontend — grouped consumption with the fallback preserved (EN/FA, RTL/LTR unchanged).

---

*Prepared by Spec Kit planning workflow. Planning phase only — no code, no `tasks.md`, no commits, no backend/contract changes. All work stays frontend-only over existing endpoints; no new backend endpoint is required at this stage per spec FR-009-026.*
