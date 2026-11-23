# Implementation Plan: Senior Manager Cost Sheet Review

**Branch**: `007-senior-manager-cost-sheet-review` (logical feature directory; worktree branch `main` unchanged — no Git writes authorized) | **Date**: 2026-11-07 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/007-senior-manager-cost-sheet-review/spec.md`, itself transcribed from the owner requirements. **Toolbar reconciliation (2026-11-15)**: the owner approved the Senior modal toolbar simplification — transaction search + expense-type filtering, transaction-date ascending default, click-active-criterion direction toggle with no separate direction control, and XLSX export beside the close button. Implementation evidence is recorded in `tasks.md`; owner visual acceptance (T037) remains pending.

**Decision status**: This document is a **plan**, not an authorization. Every section that requires a backend or contract change is marked **Backend-required**; that label records the planning-time gap, while the backend capabilities recorded as landed and verified are T024–T029 in `tasks.md`. The 001 `FR-037` visibility amendment was recorded on 2026-11-08 where noted (`spec.md` Workflow Reconciliation; `FR-007-013`).

---

## Summary

Replace the Senior Manager's flat, paginated transaction queue (`SeniorQueueView.vue` + `GET /queues/senior`) with a **Cost-Sheet-based queue**: the view groups the Senior-scoped pending transactions into one row per sheet, and opening a sheet shows its transactions in the shared **`SheetDetailsModal`** (Senior stage adapter) over the mounted queue — no route navigation. The modal reuses the shared transaction review surfaces (`TransactionReviewCard` generalized from `QuickReviewCard`, `TransactionDetailDrawer` generalized from `TransactionDetailsDrawer`, `DecisionConfirmation` generalized from `QuickDecisionModal`, `EvidencePreview`) so quick decision, full detail, and the attachment preview layer come from the shared components Feature 006 already ships. Approve/Reject/Return reuse the existing single-transaction `POST /transactions/{id}/decisions` path, preserving FR-085 idempotency, stage-scoped audit, and the mandatory-comment rule, so the Cost Center decision is never overwritten.

The product owner has resolved the planning-phase open decisions (2026-11-08). The confirmed rules this plan implements in its requirements are:

1. **Sheet-wide Senior queue eligibility.** A sheet becomes visible in the Senior Manager queue **only after every transaction in that sheet has a final Cost Center decision** (the owner's D1). The gate is sheet-wide, enforced by the backend, and MUST NOT surface a "Waiting for Cost Center Review" state in the Senior interface. A **Return for Correction is not final**: a returned transaction must complete correction and receive a subsequent final CC decision. A final **rejection** closes that transaction's Cost Center review and advances it per the existing workflow rules (001 `FR-036/037`, `WorkflowStateMachine::next`); rejection semantics are unchanged. This sheet-level visibility gate is the **narrow, recorded amendment to 001 `FR-037`** (recorded in Feature 001 on 2026-11-08 — see `spec.md` Workflow Reconciliation) — the plan never invents a rule.
2. **Two-level filter placement.** The main queue carries Cost Sheet search and filtering; the Cost Sheet modal carries transaction search (ID/description) and expense-type filtering, plus sorting (owner's D2). Currency, amount-range, and attachment filters are retired. Sorting defaults to transaction-date ascending; clicking the active criterion toggles direction and there is no separate direction control. Transaction-level controls are not duplicated in the main queue.
3. **XLSX-only export with an enforceable selection contract.** Export is XLSX only (D3). It carries sheet identification/metadata, one row per included transaction, separate Cost Center and Senior decision fields, exactly the transactions the active filters select, and the same ordering as the on-screen selection (D4). The selection is submitted as the filtered + sorted transaction IDs through the existing ID-selection mechanism (`ExportRequest.ids`), which the row sources resolve in the client-supplied order; the additive Senior-scoped row source MUST reproduce that ordering guarantee and validate each ID against the caller's Senior scope.
4. **Completed-sheet lifecycle.** After the final outstanding Senior decision the sheet stays in the Senior queue as **"Review Completed"**, read-only, with previous decisions and evidence accessible and no actionable transactions (D5).
5. **Vendor is out of scope.** No vendor filter, vendor column, vendor search placeholder, or disabled vendor control (D7).

Three capability families are **backend-required** and dominate the risk section:

1. **Sheet-wide Senior queue gate.** The owner rule "a sheet enters the Senior queue only after all its transactions have a final Cost Center decision" is the confirmed gate. There is no sheet-wide gate today (`ManagementReviewService::getSeniorQueue` returns senior-stage **transactions**, not sheets), and the rule narrows 001 `FR-037`'s "no transaction blocks another" sentence for Senior **queue visibility only** — the underlying per-transaction stage transitions and the senior→finance rule stay unchanged. Producing the gated sheet queue requires an additive backend batch; the narrow 001 `FR-037` visibility amendment it needs was **recorded in Feature 001 on 2026-11-08**.
2. **Senior-scoped sheet reads.** Only a CC-manager-scoped sheet read exists (`GET /queues/cost-center/sheets/{sheetId}`, B001–B005 of Feature 006). There is no Senior-scoped sheet-summary/sheet-detail read; a new one is additive and scoped to the caller's active `SENIOR_MANAGER` assignments.
3. **Senior-scoped XLSX export.** There is no Senior-scoped row source for `POST /exports` (the export pipeline's row sources are owner-scoped via `ReportScope::coversOwned`, which refuses a Senior Manager who is not the owner). The combined Cost-Sheet+Transaction XLSX export (`FR-007-043/044`) therefore needs a new additive, XLSX-only row source that accepts the UI's ordered `ids` selection; existing owner-scoped export resources are NOT widened.

Everything else is **supported reuse**: the transaction detail read, the decision command path, the decision history, the evidence preview, the completeness/grouping logic (`useSheetQueue`), the reports store mechanics, and the CostCenter visual language.

## Technical Context

**Language/Version**: TypeScript 5.x, Vue 3.5 (`<script setup lang="ts">`), PHP 8.2+ (compatibility floor per ACC-001 — Nextcloud 34 itself requires PHP 8.2; the earlier `8.3+` assertion was non-conformant and is corrected; only additive backend work when authorized)

**Primary Dependencies**: `@nextcloud/vue` 9.x, Pinia, Vue Router, `@nextcloud/l10n`, MDI icons, `@playwright/test` (official Google Chrome channel). No new dependencies.

**Storage**: N/A for the frontend (read-only presentation plus existing decision writes). One additive backend capability must not introduce persistence unless the 001 amendment requires a flag to materialize the sheet gate.

**Testing**: Vitest + `@vue/test-utils` for unit/component behaviour; Playwright (official Google Chrome channel) for screenshots and viewport evidence; `scripts/dev/phpunit` for any authorized backend change; `npm run check` (typecheck, lint, stylelint, build, e2e) at boundaries.

**Target Platform**: Nextcloud 34 application shell, desktop and 390px mobile, English LTR and Persian RTL, light/dark/forced-colours, Gregorian/Jalali independent calendar preference.

**Project Type**: Web application (existing Nextcloud app, `pettycash`).

**Performance Goals**: one queue request per refresh; no per-row network calls for the sheet list; filtered/sorted views operate on a proven-complete loaded set without per-action requests; per-transaction detail reads only when a card's actions/details are actually opened (guarded, cached).

**Constraints**: Cost Center workflow MUST NOT change (006 `FR-006-030/031`); no new dependencies; no Vuetify; backend/contract changes only through a separate authorization; no sheet-level decisions; no fabricated values; existing working-tree changes preserved; no Git writes; `docs/frontend-redesign/` has no Senior reference — the visual baseline is `CostCenterQueueView.vue`.

**Scale/Scope**: one review view (Senior) + one modal + shared review components + (authorized) additive backend capability + one additive export surface, catalogue keys, component tests, screenshot evidence.

## Constitution Check

| Gate | Status | Evidence / action |
|---|---|---|
| Compatibility baseline | PASS | PHP floor **8.2+** per the ACC-001 register (Nextcloud `~8.2||~8.3||~8.4||~8.5`; this plan no longer asserts `8.3+`). Database targets unchanged (`mysql`/`pgsql`/`sqlite`, ACC-002). TypeScript 5, Vue 3 unchanged. |
| Source-of-truth ordering | PASS | 001 spec/contracts remain authoritative; the sheet-wide Senior-queue gate uses the **narrow FR-037 amendment** recorded in 001 on 2026-11-08 (see `spec.md` Workflow Reconciliation), never redefined in code |
| Backend freeze | PASS (planning) | No file under `lib/`, `appinfo/`, or `tests/Backend/` changes at planning; the three additive backend capabilities (Senior sheet read, sheet gate, Senior XLSX export) are separately authorized |
| DDD boundaries | PASS | Domain unchanged; new backend capability is Application/Infrastructure reads + Presentation; no business rules move to views |
| Visual evidence | PASS | Screenshots under `artifacts/visual-review/<task-id>/<iteration>/`; official Chrome channel |
| Owner approval gate | PASS | Plan ends at a recorded request for approval; implementation batches are not started |
| No fabricated data | PASS | completeness guard; honest unavailable/error states (no incomplete flat fallback table); stage-tagged decisions; sheet eligibility only from the backend gate flag |
| i18n / RTL / calendar | PASS | `direction`, `formatMoney`, `formatSheetDate`, `formatNumber` utilities |
| Auditability | PASS | decisions stay append-only/stage-scoped via the existing command path (FR-085, FR-051) |
| Component reuse | PASS | sheet-queue grouping/completeness is **extended from** `useSheetQueue`, not re-implemented in parallel |

## Project Structure

### Documentation (this feature)

```text
specs/007-senior-manager-cost-sheet-review/
├── spec.md              # Feature specification (transcribed from owner requirements)
├── plan.md              # This file
├── research.md          # Phase 0 research notes (§evidence routes, field sources, gaps)
├── data-model.md        # Phase 1 data model / TS interfaces / state model
├── quickstart.md        # Phase 1 dev/run/gate quickstart
└── contracts/           # Phase 1 contracts & design notes (additive, not ratified)
    └── senior-review-api.md    # proposed additive Senior read/export surface + contract deltas
```

No `tasks.md` is created by this plan.

### Source Code (changed files — implementation projection, NOT changed by planning)

```text
src/
├── views/reviews/SeniorQueueView.vue            # sheet-first queue replacing the flat table (or a new sheet view)
├── composables/useSeniorQueue.ts                # Senior grouping/completeness/search/sort (new, modeled on useSheetQueue)
├── components/review/SheetDetailsModal.vue      # shared sheet modal shell; Senior stage via props/config/slots (replaces stage-specific SeniorSheetModal)
├── components/review/TransactionReviewCard.vue   # shared compact card (generalized QuickReviewCard; Senior variant via seniorState/seniorDecision)
├── components/review/TransactionDetailDrawer.vue # shared drawer (generalized TransactionDetailsDrawer; Senior via seniorState/seniorDecision)
├── components/review/PriorDecisionHistory.vue    # shared transaction decision history (reuse)
├── components/review/EvidencePreview.vue         # shared evidence overlay (reuse verbatim)
├── components/review/DecisionConfirmation.vue    # shared decision confirmation (generalized QuickDecisionModal; reuse)
├── stores/reviews.ts                            # seniorSheetQueues + senior sheet-review reads + export state (extend)
├── services/reviewsApi.ts                       # fetchSeniorSheet / fetchSeniorSheetQueue (extend or add)
├── types/api.ts                                 # ManagerSheetReview is CC-shaped; add Senior variants (extend, additive)
├── services/reportsApi.ts                       # senior-scoped export request helper (extend once backend lands)
└── stores/reports.ts                            # optional export reuse for the Senior queue
l10n/{en,fa}.{json,js}                           # new catalogue keys
tests/Frontend/Component/ManagementReview.spec.ts  # existing SeniorQueueView assertions are updated in place (the only existing senior surface coverage)
tests/Frontend/Component/SeniorReview.spec.ts    # queue/modal behaviour (new)
tests/Frontend/Component/SheetDetailsModal.spec.ts
tests/Frontend/Composable/SeniorSheetQueue.spec.ts
```
Note: no `SeniorQueue.spec.ts` exists anywhere; the real existing coverage is the `SeniorQueueView` describe block inside `tests/Frontend/Component/ManagementReview.spec.ts`.
```

Authorized backend projection (separate authorization; additive only):

```text
lib/Controller/ReviewController.php               # additive seniorSheet / seniorSheetQueue actions
lib/Application/Workflow/ManagementReviewService.php  # getSeniorSheetForManager + sheet-gate query
lib/Infrastructure/Http/SheetPayloadFormatter.php    # seniorSummary (or shared managerSummary)
appinfo/routes.php                                 # additive GET /queues/senior/sheets[/{sheetId}]
lib/Controller/ExportController.php                # additive senior-scoped row source (when authorized)
specs/001-pettycash-v1-core/contracts/ocs-api.yaml # additive schemas (when authorized, amended to 001)
```

## Key Design Decisions

### D1 — Backend-enforced sheet-wide gate; grouping only over the gated, proven-complete set

**The sheet-wide gate is a backend fact, not a client derivation, and not a transitional frontend state.** A sheet enters the Senior Manager queue only when **every transaction in it has a final Cost Center decision** (owner D1). "Final" means Approve, or Reject, or a Return that has been corrected and re-submitted and then decided; a Return for Correction alone is **not** final and does not satisfy the gate. A final rejection closes that transaction's Cost Center review and it advances per the existing rules (001 `FR-036/037`, `WorkflowStateMachine::next`) — rejection semantics are unchanged and never "block" the caller's sheet in a new way. The gate is a **narrow amendment to 001 `FR-037`'s "no transaction blocks another" sentence, scoped to Senior queue visibility only** (stage transitions stay per-transaction); that amendment was **recorded in Feature 001 on 2026-11-08**.

Until that backend capability lands (the narrow 001 `FR-037` visibility amendment was recorded on 2026-11-08), the Senior queue renders **only what the backend can prove**:
- the eligible, gate-satisfying senior-pending set, or
- an honest unavailable/error state with a retry — never a sheet the backend cannot prove eligible, never a "Waiting for Cost Center Review" label, and never a potentially incomplete flat transaction table as a fallback (owner D8).

The queue derives sheet rows from the **proven-complete** Senior-scoped pending set (`useSheetQueue.checkSheetQueueCompleteness`: `total <= items.length` and `items.length < 500`; the server already de-duplicates by transaction id in `paginateUniqueQueue`, so grouping is not compensating for duplicates). Grouping and completeness logic is **extended from `useSheetQueue` — never re-implemented in parallel** — so there is one guard, not two. Authorized `review-detail` deep links stay reachable wherever they do not contradict the gate (D8).

### D2 — Modal over the mounted queue (no route navigation)

Selecting a sheet opens a **modal** (single overlay layer) rendered by the queue view; the router is untouched for this flow (the existing `review-detail` route stays reachable for deep links, preserved as in 006 `FR-006-017`). The modal:

- shows the sheet identity (title/purchaser/currency/submitted where the backend proves it — else unavailable) and per-transaction cards;
- pulls the sheet's senior-pending transactions from the proven-complete queue set, and refreshes from the server after any decision;
- opens the shared preview layer and the shared full-details drawer **one layer at a time** (single-overlay rule, 006 `FR-006-037`);
- returns focus to the originating sheet row on close.

### D3 — Transaction-level review and decisions (reuse, unchanged)

Every action — quick decision on a card or full detail in the drawer — targets exactly one transaction through the existing single-transaction command path (`POST /transactions/{id}/decisions`, FR-085 first-write-wins, 001 `FR-033` single-decision rule). There is no sheet-level Approve/Reject/Return and no "decide all" (`FR-007-005`). CC decisions are rendered from `TransactionDetail.decisions[]` filtered to `stage === 'COST_CENTER'`, per transaction only (`FR-007-004`, 001 `FR-034`).

### D4 — Honest fields only

| Field | Rendering |
|---|---|
| Sheet title / purchaser / submitted date | from the authorized Senior sheet read when it lands (backend-required); otherwise unavailable (`FR-007-014`) |
| Your transactions | count/amount of de-duplicated senior-pending transactions in the sheet, grouped by currency |
| Review status | derived, labelled "Awaiting your decision" / your decision outcome; never a sheet verdict |
| CC decisions | per-transaction, stage-tagged, from the decision history |
| Sheet-wide CC completion / Senior eligibility | shown only from the backend gate flag; otherwise an honest unavailable/error state, never a client-derived claim (`FR-007-013`) |
| Vendor | **out of scope** (owner D7): no vendor filter, no vendor column, no vendor search placeholder, and no disabled vendor control |

### D5 — Visual language

Use the established Pettycash/Purchaser tokens and the 006 quick-review components as the baseline: Nextcloud shell, `--font-size-*`, `--color-*`, `StatusBadge`, card surfaces, `ResponsiveTable`, tabular rows, `EvidencePreview` overlay, focus treatment. Mobile: one column, full-screen overlays one at a time, `≥44px` touch targets, no horizontal scroll at 390px.

### D5b — Completed-sheet lifecycle (owner D5)

After the Senior Manager records the final outstanding decision in a sheet, the sheet **remains visible in the Senior queue** and its queue presentation status becomes **"Review Completed"** (a Senior-queue presentation value, distinct from the domain `SheetStatus` derivation, which is unchanged and remains 001's own). Rules:

- The sheet stays listed and is available for **read-only inspection**: previous decisions and evidence remain accessible through the same modal.
- Completed decisions cannot be **submitted again or modified** (FR-085 first-write-wins; append-only, stage-scoped history).
- A completed sheet carries **no actionable transactions** — no decision controls, no resubmission, no "decide all"; retaining the sheet means retaining the read-only record, never retaining actionable transactions.
- The additive Senior sheet-queue read supplies this presentation status (`REVIEW_COMPLETED`) only for sheets whose senior-pending transaction set is empty; it MUST NOT be derived from partial client data.

### D5c — Grouping failure and fallback (owner D8)

If the system cannot prove the Cost Sheet transaction set is complete (truncation, unresolved total, or the sheet-gate backend unavailable), the view:

- shows an honest **unavailable/error state** with a **retry** action;
- MUST NOT render a potentially incomplete flat transaction table as a fallback, and MUST NOT present partial counts or rows as the full sheet;
- MUST NOT infer sheet eligibility from incomplete client-side data;
- keeps authorized `review-detail` deep links reachable only where they do not contradict the sheet-wide eligibility gate.

## Review Workflow Summary (Senior stage, as-is and target)

Current (verified): 001 `FR-032` routes each transaction to the manager of its cost center at `COST_CENTER`; a Cost Center decision (Approve/Reject/Return) is recorded at `WorkflowStage::CostCenter` (`ManagementReviewService::recordDecision`), stored in the immutable `WorkflowDecisionRepository` with a stage tag, an audit event, and FR-085 first-write-wins. `WorkflowStateMachine::next` advances the decided transaction to `SENIOR` on Approve/Reject (Return → Correction). `getSeniorQueue` returns `WorkflowStage::Senior` transactions scoped to the manager's active `SENIOR_MANAGER` cost-center assignments. The Senior Manager records the final management decision (001 `FR-036`): Approve/Reject/Return, each on exactly one transaction, with explanation required for Reject/Return (001 `FR-033/035`); the decision advances to `FINANCE` (001 `FR-037`) and is never changed by finance (001 `FR-036`).

Target (this feature): the same transaction-level workflow is preserved byte-for-byte. The change is presentation (sheet-first + modal) and three **additive** backend capabilities — Senior-scoped sheet summary/detail reads, the **sheet-wide CC-completion Senior-queue gate** (owner-confirmed; Backend-required; the narrow 001 `FR-037` visibility amendment was recorded on 2026-11-08), and the **Senior-scoped XLSX export**. The Senior decision path, audit, and CC decision immutability are untouched.

## Cost Center vs Senior Manager Differences (as the surface must handle them)

| Dimension | Cost Center Manager (006, existing) | Senior Manager (this feature) |
|---|---|---|
| Queue unit | sheet row → expand to that manager's CC-scoped pending transactions | sheet row → **modal** with the sheet's senior-pending transactions; queue stays mounted behind |
| Decision weighting | advisory (001 `FR-034`); may be superseded by Senior independently | **final management decision** (001 `FR-036`) |
| Advisory decision visible | own decisions on cards ("by you") | CC decisions shown **per transaction** (`COST_CENTER`-tagged) |
| Completion meaning | "nothing awaits this manager" ≠ sheet-wide completion (006 `FR-006-047/048`) | sheet-wide "all CC decisions final" is the Senior-queue entry gate (owner-confirmed; Backend-required; the narrow 001 `FR-037` visibility amendment was recorded 2026-11-08) |
| Scope source | active `COST_CENTER_MANAGER` assignments | active `SENIOR_MANAGER` assignments (`RoleAssignmentRepository::findActiveByUserAndRole` with `Role::SeniorManager`, the same source `TransactionPayloadFormatter::canDecide` uses) |
| Entry gate | none (sheet grouped from manager's pending set) | owner rule: enter Senior only when **every** sheet transaction has a **final** CC decision; Return-for-correction is not final (Backend-required; the narrow 001 `FR-037` visibility amendment was recorded 2026-11-08; rejection semantics unchanged) |
| Row surface | `ManagerTransactionSummary` (`managerState`/`managerDecision` CC-shaped) from `getCostCenterSheetForManager` | needs a **Senior-shaped** variant from a new `getSeniorSheetForManager` (Backend-required) |
| Decision command | `POST /transactions/{id}/decisions` routed by stage | identical; `ReviewController::decide` routes by the transaction's stage so the same endpoint serves both |
| Export | owner-scoped exports exist (006 not exporting the queue) | **Senior-scoped XLSX-only** combined Cost Sheet + Transaction export, ordered `ids` selection — Backend-required ($"Reporting & Excel Export", owner D3/D4) |

## Reuse Map (existing assets → this feature)

| Need | Reuse / adapt | Gap to build |
|---|---|---|
| Sheet-first grouping + completeness + search + sort | `useSheetQueue.ts` (`SHEET_QUEUE_PAGE_SIZE=500`, `checkSheetQueueCompleteness`, `dedupeTransactions`, `buildSheetQueue`, `searchSheetQueue`/`sortSheetQueue` **sheet-row** search/sort) | Senior variant `useSeniorQueue.ts` **extending** the same guard/grouping with per-transaction search (ID/description) and expense-type filtering, plus transaction-level sort for the modal — extend, never copy, never parallel-implement the guard |
| Queue shell/visual language | `CostCenterQueueView.vue`, `ResponsiveTable`, `queueColumns.ts`, `StatusBadge` | Replace the flat senior table with the sheet presentation; keep `SeniorQueueView.vue`'s route/breadcrumb shell |
| Shared Sheet Details Modal shell | new shared `SheetDetailsModal.vue` (Senior stage adapter via props/config/slots: tabs Transactions/Approvals/Overview/History, sheet identity, status, currency, submitted, owner, transaction count, recorded amount, latest approved amount, focus trap, Escape, close, focus restoration, RTL/LTR, responsive) | Senior adapter config; no per-role modal |
| Compact transaction card + action bar | `QuickReviewCard.vue` (props: `transaction`, `managerState`, `managerDecision`, `evidence`, `allowedActions`, `busy`; emits `openReview/openDetails/openPreview/decide`) → generalized `TransactionReviewCard`; Senior `seniorState`/`seniorDecision` variant (stage-tagged `SENIOR`); card is already manager-state-agnostic | Card generalization; Senior presentation via additive props |
| Full details drawer | `TransactionDetailsDrawer.vue` (Description + History tabs, footer decide controls, read-only fields with unavailable states) → generalized `TransactionDetailDrawer`; wire with the Senior sheet header + stage-tagged decisions; field set unchanged | Drawer sections (Transaction Details / Evidence / Previous Decisions / Current Stage Actions); Senior stage adapter |
| Decision confirmation + mandatory comment | `QuickDecisionModal.vue` → generalized `DecisionConfirmation` (per-kind copy, `requiresExplanation`=REJECT‖RETURN, `expectedVersion`, submitting/error/toast, single transaction via `reviewsStore.recordDecision`) | Reuse unchanged (stage is resolved server-side) |
| Attachment thumbnails | `EvidenceCard.vue` / `QuickReviewCard` thumbnail strip (2 + `+N more`) | Reuse presentation; server evidence list unchanged |
| Preview layer (images/PDF/unknown) + prev/next + `1/N` | `EvidencePreview.vue` (inline-safe policy from `utils/evidencePreview.ts`, focus trap/scrim, Escape close, object-URL revocation) | Reuse verbatim (006 `FR-006-041`) |
| Per-transaction detail + capability gating | `reviewsStore.fetchManagerTransactionDetail` guarded channel (`ManagerTransactionReadState`: loading/loaded/forbidden/notFound/unavailable), `decisionAllowed`/`DECISION_CAPABILITY` | Reuse for the Senior modal cards |
| Export pipeline | `reportsApi.ts` (`requestExport`, `exportArtifactBlob`), `stores/reports.ts` (`DownloadableExport`, disclosure, filename) | Senior-scoped row source + resource/filter grammar additive (Backend-required) |
| Report/export filter vocabulary | `ReportFilterFactory::ACCEPTED_FILTERS` (closed: from/to/statuses/currencyCodes/costCenterIds/…/amountMinMinor/amountMaxMinor/period) | Senior queue sheet-search and modal transaction filters are client-side over the loaded set; the additive Senior export submits the filtered + sorted **transaction ids** (`ExportRequest.ids`), so no filter-vocabulary widening is required for export |

## Data Model / TS Interfaces

Frontend (extend `types/api.ts`, additive):

```ts
/** Stage tag for the Senior variant of the manager sheet review. */
export type SeniorManagerTransactionState =
  | 'AWAITING_SENIOR_DECISION'
  | 'DECIDED_BY_SENIOR'

/** Senior-shaped sheet header from the additive read (when it lands). */
export interface SeniorSheetSummary {
  id: string
  title: string
  ownerId: string
  ownerDisplayName: string
  currency: string
  submittedTotalMinor: number | null
  submittedTotal: Money | null
  submittedAt: string | null   // genuine first-submission instant
  version: number
  /**
   * Backend-computed sheet-wide Cost Center completion gate (owner D1). This is the only source
   * of Senior-queue eligibility; `true` only when EVERY transaction has a final CC decision
   * (Approve/Reject, or Return corrected and re-decided). `null` = backend cannot yet prove it
   * (gate capability pending) → the sheet is not shown, not labelled "waiting".
   */
  allTransactionsCostCenterDecided: boolean | null
  /** Senior-queue presentation status (owner D5). */
  reviewStatus: 'PENDING_REVIEW' | 'REVIEW_COMPLETED'
}

export interface SeniorManagerSheetReview {
  sheet: SeniorSheetSummary
  progress: { awaitingSenior: number; decidedBySenior: number; total: number }
  transactions: Array<TransactionSummary & {
    seniorState: SeniorManagerTransactionState
    /** This manager's Senior decision for the current revision; absent while awaiting. */
    seniorDecision: Decision | null
  }>
}

/** Filter state for the Senior modal (client-side over the proven-complete set). */
export interface SeniorFilterState {
  query?: string            // free-text search (id, description)
  description?: string
  expenseTypeIds?: string[]
}
```

Backend (authorized, additive): `SeniorSheetSummary`/`SeniorManagerTransactionReview` wire schemas added to `contracts/ocs-api.yaml` alongside `ManagerSheetSummary`/`ManagerSheetReview` (`ocs-api.yaml:1584/1619/1663`), plus an additive Senior-scoped export row source and the **required, backend-computed** `allTransactionsCostCenterDecided` gate flag (owner D1) and `reviewStatus` (owner D5). No new persistence unless the recorded 001 `FR-037` amendment (2026-11-08) materializes the gate flag.

## State Model

| State | Owned by | Values / transitions |
|---|---|---|
| Senior queue page | `reviewsStore.seniorQueue` (`TransactionPage`) | loading → loaded | error; proven-complete flag from `checkSheetQueueCompleteness` |
| Sheet list (presentation) | `useSeniorQueue` derived | one row per sheet; rows derived only when the set is complete; otherwise an honest unavailable/error state with retry — never a partial flat fallback table (owner D8) |
| Sheet review status | from the additive read `reviewStatus` | `PENDING_REVIEW` → `REVIEW_COMPLETED` once every transaction has a Senior decision; completed sheets remain listed, read-only and non-actionable (owner D5) |
| Open modal | queue view local (`openSheetId`) | sheet list → modal; modal → closed (focus restore) |
| Active overlay | queue view / modal local | none → modal → (details drawer | preview) → back; **one layer at a time** (006 `FR-006-037`) |
| Preview | `EvidencePreview.vue` local (`open`, `evidence`, `documents`, `index`) | closed → open at doc; next/prev (`navigate`); close (Escape/scrim/button) |
| Full detail | `TransactionDetailsDrawer.vue` local (`open`, `transaction`, `readState`) | readState loading/loaded/forbidden/notFound/unavailable (guarded read, cached) |
| Decision modal | `QuickDecisionModal.vue` local | closed → open(kind) → submitting → decided | error; close on success |
| Per-transaction detail cache | `reviewsStore.managerTransactionDetails` + `managerTransactionReads` | loading → loaded; cleared after a decision (stale) |
| Export | `reportsStore` / senior export state | idle → exporting → done (artifact downloaded) | error/fieldErrors | refusal (missing Senior capability) |

All derived statuses come only from the loaded response or explicit unavailable; no local status is fabricated after a refusal.

## Modal Open / Close Contract

- **Open**: the sheet row's activation control sets `openSheetId` and focuses the modal container (`role="dialog"`, `aria-modal="true"`, labelled by the sheet identity). The queue remains mounted and inert behind the scrim. No router call.
- **Sheet data**: the modal renders from the proven-complete queue set immediately; a guarded senior-scoped sheet read (Backend-required) may re-fill identity/purchaser/submitted fields when available. A failed sheet read leaves those fields unavailable, never blanking the queue.
- **Single overlay**: opening the modal closes nothing else; opening the details drawer or the preview pushes one layer above the modal; the queue and modal stay in the DOM (006 `FR-006-037`).
- **Close path**: Escape, scrim click (when safe — never during a submitting decision, 006 `FR-006-043`), close button, or after a successful decision toast. Focus returns to the originating sheet row control. A sheet whose last senior-pending transaction was decided elsewhere shows an honest refreshed state (server refetch), never a synthesized "empty".
- **Mobile**: full-screen overlays one at a time, `≥44px` targets, no horizontal scroll, RTL mirrored drawer.

## Attachment Preview Flow

1. The card shows the transaction's evidence metadata (`evidence[]` from the guarded detail read) as a thumbnail strip — first two documents plus a `+N more` tile (006 `FR-006-040` pattern).
2. Clicking any thumbnail opens `EvidencePreview.vue` at exactly that document with the transaction's document set for Previous/Next and a `1 / N` counter (006 `FR-006-041`).
3. Rendering policy is the existing inline-safe closed set (`utils/evidencePreview.ts` + 001 `FR-060`/005 `FR-005-042`): `application/pdf` → iframe viewer; PNG/JPEG/GIF/WebP/BMP → large image; `text/plain` → text; every other media type → metadata note + open/download only (never inline). Accessible dialog name = file name; Escape/scrim close; object-URL revocation on close/unmount.
4. Inactive evidence shows metadata only, no content (matches `EvidencePreview.vue` active check).

## Senior Decision Flow

1. A card action (or the drawer footer's "Decide this transaction") is enabled **only** on an explicit server-granted capability (`decisionAllowed(allowedActions, kind) === true`, from the guarded detail read) — never on presence of the button (006 `FR-006-039`, T026).
2. The shared `QuickDecisionModal` opens with per-kind copy, the transaction summary (id, title, sheet reference, amount, expense date), and `expectedVersion` from the detail read.
3. The comment rule: Approve — optional; Reject and Return — mandatory (`requiresExplanation`). Submitting disables controls, blocks scrim close, sends one `POST /transactions/{id}/decisions` (`DecisionWrite { decision, explanation, expectedVersion }`) with the `X-PettyCash-Command-ID` idempotency header.
4. Success (`DecisionResult`): the card re-renders from the server's `result.transaction`, the per-transaction detail cache is cleared as stale, a toast confirms, and the sheet's progress derives from the refetched set.
5. Failure: FR-085 `409`/`SUPERSEDED_ATTEMPT`, stale version (`fieldErrors.expectedVersion`), or an explanation-less reject/return (`fieldErrors.explanation`) are rendered distinctly; no local status is fabricated; the queue refetch reflects the server only.
6. The CC decision is never touched: the command path records a `SENIOR`-stage decision and appends to the same immutable history (verified in `ReviewController::decide` stage routing and `WorkflowDecisionRepository`).

## Mandatory-Comment Validation

- The rule lives in the backend (`ReviewController::decide` → `InvalidArgumentException`/`fieldErrorsFor` explanation branch; `DecisionOutcome`/`recordDecision` requires explanation for REJECT/RETURN) and is mirrored by the shared modal (`requiresExplanation` = REJECT ‖ RETURN, guard + inline error, `MAX_EXPLANATION_LENGTH`).
- Approve never requires a comment; no new rule is introduced for the Senior stage. The Senior surface reuses the same component, so the two stages cannot disagree.

## Filtering Model (two-level; client-side over the proven-complete set)

Owner decision D2 — two levels, never conflated:

- **Main queue (sheet level):** Cost Sheet search and filtering (sheet reference, sheet title where the backend supplies it, pending/eligibility status). No transaction-level controls on the main queue; transaction search/filter/sort is not duplicated there.
- **Cost Sheet modal (transaction level):** transaction search, filtering, and sorting over the sheet's proven-complete senior-pending set.

Every filter is a pure function over the complete loaded set; no network request per change.

Modal transaction filters:

| Filter | Source field | Notes |
|---|---|---|
| Free-text search | `id`, `activeRevision.description` (placeholder says "Search by transaction ID or description" — owner D7; vendor is never in scope) | case-insensitive; matches the 006 search convention |
| Expense type | `activeRevision.expenseType.name` / id | multi-select with counts derived from the loaded set with the other active filters applied |

Currency, amount-range, and attachment filters are not part of the modal toolbar. Currency remains a display and formatting concern (per-currency totals; no cross-currency comparison), never a filter.

**No vendor anywhere (D7):** no vendor filter, no vendor column, no vendor search placeholder, and no disabled vendor control appears in queue or modal.

The report/export receives the **same** filtered + sorted transaction set (as an ordered `ids` list) so a filtered export reflects the visible selection exactly — see §Reporting & Excel Export.

## Sorting Model (modal / transaction level, owner D2)

- Transaction sorting lives in the Cost Sheet modal (the sheet's transaction list); it is a local sort over the proven-complete set: **amount** (minor units within one currency, then by currency code), **transaction date** (`activeRevision.expenseDate`, ISO8601 string compare — locale-independent), **expense type** (name). The modal opens with **transaction date ascending** (oldest first) as the default.
- One active sort criterion at a time. Clicking the active criterion toggles ascending/descending; selecting a different criterion starts ascending. There is no separate sort-direction control. A secondary tiebreak (transaction id) keeps order stable; the sort never re-requests the network and never re-derives amounts.
- The report/export carries the **same ordered transaction list** so the artifact ordering matches the on-screen ordering (§Reporting & Excel Export).

## Reporting & Excel Export

**Requirement (owner D3/D4)**: the export is **XLSX only** — no CSV or PDF in this feature's scope — and contains **only** the filtered + sorted Senior selection and **only** the transactions selected by the active filters, with Cost Sheet identification/metadata plus, **one row per transaction** that carries **separate Cost Center and Senior decision fields** (`FR-007-043`). The ordering of the artifact rows MUST match the ordering shown in the interface. The XLSX export control sits in the modal header beside the close control. Unrelated existing export capabilities (CSV/PDF/XLSX for the other resources) are unchanged.

**Current pipeline (verified)**: `POST /api/v1/exports` (`ExportController::create`, route `appinfo/routes.php:86`; `ExportRequest`/`ExportResult` in `contracts/ocs-api.yaml:1922/1945`; `reportsApi.requestExport` + `exportArtifactBlob`; `stores/reports.ts`). Rows are resolved **per resource** in `rowsForSelection`:

- `transactions` → `transactionRows()` → `ExpenseTransactionRepository::findByPublicId` + `ReportScope::coversOwned($ownerUid)` — a Senior Manager is **not** the owner, so every Senior-scoped transaction export is refused (`UnsupportedSelection` naming the id).
- `sheet`/`sheets` → `sheetRows()` → `coversOwned($sheet->ownerUid)` — same refusal for a Senior Manager.
- The `ReportScope::forActor` cost-center scope (`costCenterDbIds`) is **never** consulted by the export row sources today — they are ownership-scoped, not manager-scoped.
- **Ordering fact (verified)**: `transactionRows()` iterates `foreach ($ids as $id)` and appends one row per id in the **client-supplied order**, so the existing ID-selection mechanism already guarantees row order equals the `ids` array order. No DB `WHERE IN` ordering ambiguity exists on this path.

**Selection contract (D4, preferred approach — implementable as-is)**: the modal submits the filtered + sorted transaction selection as an **ordered `ids` array in `ExportRequest.ids`** (the existing ID-selection member). The new **Senior-scoped XLSX row source** MUST:

1. resolve each id in the supplied order and emit one row per id — row order MUST equal the `ids` order (an explicit ordering guarantee on the new resource, mirroring the existing loop);
2. **validate the authorized selection**: each id that is not a real transaction, not at the Senior review stage in the caller's active `SENIOR_MANAGER` cost centers, or not within the caller's scope is refused with the existing `UnsupportedSelection` behavior naming the id — never a silent partial artifact;
3. apply **scope before** any row generation (same rule as `contracts/exports.md`), with authorization identical to `getSeniorQueue`'s active-assignment scope (never ownership; existing owner-scoped row sources are NOT widened) (`FR-007-056`);
4. emit the combined Cost Sheet + Transaction row shape: sheet id/title/currency/submitted total + transaction id/description/expense type/amount/date + **separate Cost Center decision and Senior decision fields** + status;
5. accept `fileFormat: xlsx` only for this resource (D3); the existing `fileFormat` enum for the other resources is unchanged.

**Gap (Backend-required)**: the additive Senior-scoped XLSX row source above (`resource: 'senior-queue'`, `fileFormat: ['xlsx']`), wired through `ExportController` with the ordering + scope-validation guarantees, plus the additive contract delta (`ExportRequest.resource` member + the ordering requirement) in the feature's contract notes and, when authorized, `contact` schemas. This is separate, additive, and never widens owner-scoped export resources.

**Measured-zero preserved (D4)**: an export of a legitimate empty filtered selection stays a real header-only XLSX artifact with `rowCount: 0` (the existing measured-zero behavior, `ExportController.php:480-502`), not a refusal. Refusal is reserved for unsupported resources/formats and out-of-scope/unresolvable ids — never produced for a legitimate zero selection.

**Guardrail when the capability is unavailable (`FR-007-044`)**: the export control exists but on click issues a guarded request and renders the server's refusal ("not supported yet", naming the missing Senior-scoped capability) — never an empty artifact presented as measured, and never a fallback that quietly exports the owner-scoped set.

**Frontend reuse, ready now**: `requestExport`/`exportArtifactBlob`/`DownloadableExport`, disclosure filename logic, and the `ExportControls` interaction can host the Senior export surface; only the row source/grammar is missing.

## API / Service / Store / Composable Requirements

| Asset | Change | Contract/tie |
|---|---|---|
| `reviewsApi.ts` | add `fetchSeniorSheet(sheetId)` and (when the sheet queue lands) `fetchSeniorSheetQueue(params)`; keep `fetchQueue('senior')` | additive; modeled on `fetchCostCenterSheet` (`reviewsApi.ts:34`) |
| `reviews.ts` store | add `seniorSheetReviews` cache (keyed by sheet id), `seniorTransactionDetails`/read-state reuse, senior export state; Senior queue refetch after decisions | reuse `fetchManagerTransactionDetail` guarded-channel pattern |
| `useSeniorQueue.ts` (new) | Senior grouping/completeness/search/filter/sort over the complete set | modeled on `useSheetQueue.ts`; add the per-transaction filter/sort model |
| `SeniorQueueView.vue` | sheet-first presentation + modal host; keep breadcrumb shell and `review-detail` deep-link | route `review-queue-senior` (`router/index.ts`) unchanged |
| `SheetDetailsModal.vue` (shared) | modal shell, single-overlay stack, sheet header (unavailable placeholders until backend), transaction cards; Senior stage via props/config/slots | 006 `FR-006-036/037` rules |
| `TransactionReviewCard` / `TransactionDetailDrawer` | wire Senior variant (`seniorState`/`seniorDecision`, stage-tagged decisions); adapt prop types | additive, CC surface unchanged |
| `reportsApi.ts` / `stores/reports.ts` | Senior export helper + state (when backend lands) | additive |
| Backend: `ReviewController` + `ManagementReviewService` | additive `GET /queues/senior/sheets/{sheetId}` and sheet-summary read; authorization = active `SENIOR_MANAGER` assignments only; unknown/out-of-scope → same `404` (`FR-007-056`) | Backend-required; mirrors `costCenterSheet` (B001–B004) |
| Backend: sheet gate | `allTransactionsCostCenterDecided` projection or queue filter | Backend-required + 001 `FR-037` amendment (recorded 2026-11-08) |
| Backend: export row source | Senior-scoped combined sheet+transaction rows | Backend-required + contract amendment |
| `appinfo/routes.php` | additive routes only (e.g. `review#seniorSheet`) | Backend-required |
| `types/api.ts` / `contracts/ocs-api.yaml` | additive `SeniorSheetSummary`, `SeniorManagerSheetReview`, senior export resource | additive schema; ratified with 001 |

## Loading / Empty / Error / Permission States

- **Loading**: queue-level `NcLoadingIcon` (first load) and per-card/per-sheet lightweight states; a sheet modal never blanks the mounted queue.
- **Empty**: honest empty state per sheet-quantity (no sheets) and per-modal (no senior-pending transactions in the sheet), with a link/button back to the queue; never a fabricated sheet.
- **Error**: queue error with retry (as `SeniorQueueView.vue`); guarded detail read failures classified `forbidden` / `notFound` / `unavailable` and rendered distinctly in the card/drawer; decision errors rendered in the modal with the server's `fieldErrors`; export refusals rendered with the capability reason.
- **Permission**: session scope (`GET /me` `costCenterCodes`) is disclosure only — the server remains the authorization boundary (`ReportScope`/`canDecide`). A Senior Manager with no active `SENIOR_MANAGER` assignments sees an honest empty/unavailable queue. Cards enable decisions only on explicit `allowedActions.canApprove/canReject/canReturn === true`.
- **Incomplete**: any unprovable/completeness-failed set renders the honest unavailable grouping state (006 `FR-006-013`), never partial counts.

## Concurrency & Audit

- **Decisions**: single-transaction only; FR-085 first-write-wins with `expectedVersion`; `SUPERSEDED_ATTEMPT` recorded separately; stale version → `409`/reload prompt; `commandId` replay safety (FR-084). The shared modal already implements submitting/disabled controls and no-scrim-close (006 `FR-006-043`).
- **CC decision immutability**: Senior decision appends a `SENIOR`-tagged immutable row; it never updates the `COST_CENTER` decision (verified in `WorkflowDecisionRepository` + `TransactionPayloadFormatter::decision` stage emission). Invariant asserted in the test strategy.
- **Sheet races**: a sheet whose last senior-pending transaction is decided by another manager between load and open refetches and shows the honest current set.
- **Audit**: per-transaction audit begins at submission (001 `FR-051`); decisions are stage-specific and actor-snapshotted (001 `FR-054`); the new surface adds no audit path of its own — it reads and records through the existing command path.

## Risks and Mitigations

### Technical Risks

| Risk | Likelihood/Impact | Mitigation |
|---|---|---|
| Sheet-wide Senior gate is confirmed but not yet implemented (its narrow 001 `FR-037` visibility amendment was recorded on 2026-11-08) | High (until backend) / High | Owner-confirmed rule recorded in `spec.md`; the interface never derives eligibility and never shows a sheet awaiting CC completion; the backend batch is separately authorized |
| No Senior-scoped sheet read (only `GET /queues/cost-center/sheets/{sheetId}`) — modal identity/purchaser/submitted unavailable | High (until backend) / Medium | Frontend renders placeholders (006 `FR-006-015` pattern); additive backend batch mirrors B001–B004 |
| Senior-scoped export refused by owner-scoped `coversOwned` row sources | High / High | `FR-007-044` refusal-with-reason until the additive Senior XLSX row source lands; never owner-scoped fallback |
| Residual vendor phrasing in requirement docs causes drift | Low / Low | vendor is out of scope (owner D7); the spec, plan, and contract notes carry no vendor filter/column/placeholder/disabled control |
| Senior-pending set larger than the safe single-response completeness threshold (pagination/completeness) | Medium / Medium | server `paginateUniqueQueue` already de-duplicates, so the residual risk is truncation only: reuse `checkSheetQueueCompleteness` + honest unavailable/retry state; never a partial flat fallback table (owner D8) |
| Multi-currency sums across currencies | Medium / High | group by currency + minor unit; no cross-currency total (006 `FR-006-014`) |
| Detail read without capabilities enables unproven actions | Low / High | guarded per-transaction detail + `decisionAllowed(...) === true` only (T026) |
| Mockup/design drift (no Senior reference exists) | Medium / Low | baseline is `CostCenterQueueView.vue` + existing components; no new design system |
| Modal nailed from the wrong overlay stack | Medium / Low | single-overlay rule + focus restore + tests (006 `FR-006-037/044`) |
| Backend batch accidentally widens scope or touches CC surfaces | Low / High | authorization identical to `getSeniorQueue`; additive routes only; CC surface frozen by `FR-007-051` |

### Business / Data-Integrity Risks

| Risk | Mitigation |
|---|---|
| A Senior decision could appear to overwrite the CC decision | decisions are immutable, stage-tagged, append-only; invariant tested; UI shows both stage decisions |
| Sheet-level eligibility misrepresented from partial data | `FR-007-013` — no client-derived eligibility; backend gate flag only |
| Filtered export silently diverges from the visible selection | the modal submits the exact filtered + sorted `ids`; the Senior row source preserves that order and refuses any out-of-scope id — never a partial artifact |
| Export order diverges from the on-screen order | ordering guarantee is contractual on the Senior row source (row order = `ids` order); asserted in backend tests |
| Retired toolbar filters reappear | the modal filter surface is limited to transaction search and expense type; currency/amount/attachment filters are retired, and focused tests assert their test ids are absent |
| Completed sheet misread as actionable | completed sheets render "Review Completed", read-only, non-actionable, and cannot be re-decided (owner D5) |
| Multi-currency or minor-unit errors in amounts | per-currency grouping; minor-unit integer arithmetic (`amountMinor`); reuse `formatMoney`/`formatNumber` |
| Corrupted history hidden by a fabricated latest state | every status renders from the loaded response or explicit unavailable; no local status after refusal |
| Mandatory-comment rule bypassed on the Senior path | validation is server-side (`recordDecision`) and shared-modal mirror; both stages use the same component |

## Implementation Sequence (bounded batches — plan only; task authorization and evidence live in `tasks.md`)

Ordered so the core surface works on connected data first, with backend batches as separate authorizations.

- **Stage 1 — Foundation (frontend)**: catalogue keys, `useSeniorQueue` (grouping/completeness/search/filter/sort — extends `useSheetQueue`, no parallel guard), unit tests. Works on `GET /queues/senior` connected data.
- **Stage 2 — Sheet queue UI (frontend)**: `SeniorQueueView` sheet presentation + honest empty/unavailable/error states with retry; no incomplete flat-table fallback (owner D8). Baseline visual gate against `CostCenterQueueView.vue`.
- **Stage 3 — Sheet modal + review surfaces (frontend)**: shared `SheetDetailsModal` shell (Senior stage adapter; queue stays mounted behind it), single-overlay stack, `TransactionReviewCard`s, shared `TransactionDetailDrawer`, shared `DecisionConfirmation`, thumbnails + preview reuse. Proof of CC-decision-at-transaction-level and no-overwrite invariants; modal close restores focus to the originating sheet row. The modal exposes the canonical tabs: Transactions, Approvals, Overview, History.
- **Stage 4 — Mobile + RTL**: 390px, Persian RTL, focus/`44px` targets, no horizontal scroll.
- **Stage 5 — Filter/sort/export surface (frontend)**: two-level placement (queue = sheet search/filter; modal = transaction search + expense-type filter + sort with transaction-date ascending default and click-to-toggle direction) + an XLSX export control beside the close button that renders the guarded refusal when the capability is unavailable.
- **Stage 6 — Backend capability (separately authorized)**: additive Senior sheet-summary/sheet-detail read + sheet-wide CC-completion gate (the narrow 001 `FR-037` visibility amendment was recorded 2026-11-08) + completed-sheet presentation status + backend tests; reconnect identity/submitted/eligibility surfaces.
- **Stage 7 — Backend export (separately authorized)**: additive Senior-scoped XLSX row source (ordered `ids`, scope validation) + contract amendment; reconnect the export control.
- **Stage 8 — Evidence & gates**: build, deploy, official-Chrome screenshots, l10n, accessibility, performance pass, owner visual gate per stage.

**Deferred technical debt (2026-11-15):** mobile-specific UX corrections for the reconciled toolbar remain deferred. Existing mobile requirements (390px/RTL/accessibility) and their recorded evidence are preserved; this reconciliation does not claim those corrections or owner visual acceptance.

Each stage ends at a recorded owner approval gate; no later stage starts before its gate (mirrors 006 `FR-006-025`).

## Testing Strategy

### Unit / composable (Vitest)

- completeness guard (truncation, `total > returned`, exact-threshold); no duplicate handling needed for correctness — the server `paginateUniqueQueue` already de-duplicates (asserted in backend tests);
- grouping: one row per sheet, duplicate ids counted once, blank sheet reference separated;
- eligibility honesty: no client-derived "all CC decided" claim in any state combination; no "Waiting for Cost Center Review" surface;
- completed sheet: presents "Review Completed", read-only, non-actionable, cannot re-decide (owner D5);
- CC decision rendered per transaction only (stage filter `COST_CENTER`), never rolled up;
- filter model: search / id / description / expense-type; currency, amount-range, and attachment filters are retired and their controls are absent; **no vendor filter, column, or placeholder appears** (owner D7);
- sort model: transaction-date ascending default; amount/date/expense-type; clicking the active criterion toggles direction; switching criteria starts ascending; no separate direction control; stable tiebreak;
- two-level placement: queue exposes sheet search/filter only; the modal owns transaction search/filter/sort (owner D2);
- grouping failure: honest unavailable/error state with retry; never a partial flat fallback table (owner D8);
- mandatory comment: reject/return without comment refused; approve optional; submit disabled during flight;
- no-overwrite: after a Senior decision result, the CC decision in `decisions[]` is byte-identical;
- single-overlay stack + focus restore; drawer read-state classification (forbidden/notFound/unavailable); modal close restores focus to the originating sheet row;
- preview policy reuse (inline-safe set, download-only fallback, `1/N` navigation);
- export: refusal path renders the missing-capability reason; no artifact presented as measured; export selection/order asserted against the filtered + sorted `ids`.

### Backend (when authorized, `scripts/dev/phpunit`)

- Senior sheet read: authorization = active `SENIOR_MANAGER` assignments only; no scope leak; unknown/out-of-scope → same `404` (`FR-007-056`); CC surfaces unchanged;
- sheet gate: true only when **every** sheet transaction has a **final** CC decision (Approve/Reject, or Return corrected and re-decided); a pending Return does not satisfy it; rejected transactions still advance per existing rules; never derived from one manager or from partial data;
- completed-sheet status: `REVIEW_COMPLETED` only when the senior-pending set is empty; read-only; no re-decision;
- Senior export: scope applied **before** generation (`FR-007-056`); **row order = `ids` order**; combined sheet+transaction row shape with **separate CC and Senior decision fields**; out-of-scope id → same `UnsupportedSelection` refusal; legitimate empty selection → measured-zero XLSX (`rowCount: 0`), not a refusal; xlsx-only.

### Existing tests

The real existing Senior surface coverage lives in **`tests/Frontend/Component/ManagementReview.spec.ts`** (the `SeniorQueueView` describe block, around lines 531–617). Its flat-table assertions are updated **in place** to the sheet presentation; there is no `SeniorQueue.spec.ts` anywhere (that reference is corrected here). No test is deleted or skipped; the CC workflow tests stay untouched. The new `SeniorReview`/`SeniorSheetModal`/`SeniorSheetQueue` spec files are additive coverage, not replacements. `npm run check`, targeted Vitest, and scoped lint at boundaries.

## Visual Regression Strategy

- Official Google Chrome via Playwright `chrome` channel only (006 `FR-006-029`); a missing Chrome install is a reported blocker, never a substitute engine.
- Screenshots under `artifacts/visual-review/<task-id>/<iteration>/`, cited by repository-relative path (006 `FR-006-028`).
- Viewports/scenarios: desktop 1440×900 English LTR; mobile 390×844 Persian RTL; dark theme spot-check; queue, modal, drawer, preview, decision modal, filtering/sorting, export refusal state.
- Honesty assertions in screenshots: no sheet-eligibility claim, no fabricated amounts, no horizontal scroll (`scrollWidth <= clientWidth`).
- Baseline comparison target: `CostCenterQueueView.vue` visual language.
- A visual result without a recorded owner approval is never reported as accepted (006 `FR-006-035`).

## Acceptance Criteria (mapped to the spec's success criteria and the owner-confirmed decisions)

1. **SC-007-001**: the Senior queue renders one sheet row per sheet from a proven-complete senior set; no flat table as the primary surface.
2. **SC-007-002**: every amount/count/status is traceable to the response or explicitly unavailable in every tested state.
3. **SC-007-003**: no state combination produces a sheet-wide CC-completion or Senior-eligibility claim without the backend gate flag.
4. **SC-007-004**: each decision = one transaction; reject/return without a comment refused in all tested cases; CC decision unchanged after a Senior decision; decisions stage-specific in history.
5. **SC-007-005**: no horizontal scroll at 390px in LTR/RTL; matches the `CostCenterQueueView.vue` visual language; official Chrome evidence present.
6. **SC-007-006**: a filtered + sorted selection exports exactly that selection with Cost Sheet + Transaction data (separate CC and Senior decision fields), **in XLSX only**, or an explicit refusal naming the missing Senior-scoped capability.

Owner-decision acceptance criteria (added 2026-11-08):

7. **No incomplete Cost Sheet enters the Senior queue**: a sheet with any transaction lacking a final CC decision never appears (gate enforced by the backend; D1).
8. **Returned-for-correction transactions do not satisfy the completion gate** (D1).
9. **The queue remains mounted behind the review modal**, and no route navigation occurs on open (D9, `FR-007-002`).
10. **Modal closure restores focus** to the originating sheet row (D9).
11. **Incomplete grouping never produces a misleading fallback table**: only an honest unavailable/error state with retry (D8).
12. **Exported transaction selection and ordering match the UI**: the additive row source emits one row per submitted id in `ids` order (D4).
13. **Completed sheets remain visible but non-actionable**: "Review Completed", read-only, cannot be re-decided (D5).
14. **No vendor filter or column appears** anywhere in queue or modal (D7).
15. **RTL/LTR behaviour remains consistent** at the 390px floor (D9, `FR-007-008`).
16. **Access controls and audit integrity remain intact**: Senior reads/exports stay scoped to active `SENIOR_MANAGER` assignments; decisions append-only stage-tagged; no owner-scoped export resource is widened.

## Open Questions — resolution status (owner decisions recorded 2026-11-08)

Resolved by the product owner and now embedded in `spec.md`/this plan:

1. **FR-037 / sheet gate (was Q1)** → **Resolved (D1)**: sheet-wide eligibility is the confirmed rule; the narrow **001 `FR-037` visibility amendment** was **recorded in Feature 001 on 2026-11-08** (see `spec.md` Workflow Reconciliation). Per-transaction stage transitions and the senior→finance rule stay unchanged; rejected transactions still advance per existing rules.
2. **Vendor (was Q2)** → **Resolved (D7)**: vendor is dropped from Feature 007 UI scope entirely (no filter, column, placeholder, or disabled control).
3. **Export format (was Q3)** → **Resolved (D3)**: **XLSX only** for this feature; existing CSV/PDF capabilities for other resources are unchanged.
4. **Filter/sort placement (was Q4)** → **Resolved (D2)**: two-level — main queue = Cost Sheet search/filter; modal = transaction search/filter/sort.
5. **Export content shape (was Q5)** → **Resolved (D4)**: sheet identification/metadata + **one row per transaction** with **separate Cost Center and Senior decision fields**; ordering = on-screen ordering; selection = submitted `ids`.
6. **Completed-sheet lifecycle (was Q6)** → **Resolved (D5)**: sheets stay visible as **"Review Completed"**, read-only, non-actionable; they do not disappear.
7. **Min/max amount semantics (was Q7)** → **Superseded (D6, retired 2026-11-15 for the modal toolbar)**: the owner-approved reconciliation removes the currency and amount-range filters; the former one-currency rule no longer applies.
8. **Returns/correction notice (was Q8)** → **Resolved (D1)**: no "Waiting for Cost Center Review" state is introduced in the Senior interface; a returned transaction simply does not satisfy the gate. No correction notice surface is added.

No remaining genuine ambiguity. The only previously open item — the FR-037 amendment mechanics — was resolved on 2026-11-08: the 001 `FR-037` sentence edit and its acceptance record were produced through the Spec Kit requirements-change process and **recorded in Feature 001 on 2026-11-08** ("Independent transaction progression and Senior Manager Cost Sheet queue visibility", `specs/001-pettycash-v1-core/spec.md`, `FR-037`), worded without touching unrelated 001 behavior. The only outstanding item is the separate backend authorization (recorded below, never silently applied here).

## Complexity Tracking

No constitution violations (compatibility baseline now states the registered PHP 8.2+ floor per ACC-001). The only added complexity — a completeness guard, a single-overlay modal, and three additive backend capabilities (Senior sheet reads, the sheet-wide gate, Senior XLSX export) — is required by honesty, scope, and the owner's confirmed workflow rule, not by gratuitous design. The gate, Senior reads, and export are separately authorized because they change 001 contracts/domain semantics; they are planned, never silently implemented.

---

## Confirmed Requirements

- Senior queue is **Cost-Sheet-based**; opening a sheet shows a **modal** (no route navigation; queue stays mounted and visible behind).
- Sheet enters Senior only after **every** transaction has a **final CC decision** (owner D1) — the gate is backend-enforced; **Return-for-correction is not final**; rejected transactions advance per existing rules; no "Waiting for Cost Center Review" state. The visibility rule is a **narrow 001 `FR-037` amendment**, recorded in Feature 001 on 2026-11-08, not silently applied.
- CC decisions shown at **transaction level only**; SM decision must not overwrite CC decision; decisions auditable and stage-specific.
- Modal lists the sheet's transactions with **quick decision + full detail**; attachment thumbnail strip per transaction; clicked thumbnail opens a **separate preview layer** (images large, PDFs in the existing viewer, unknown → metadata + open/download).
- Approve comment **optional**; reject & return comments **mandatory**.
- Filtering/sorting is **two-level** (owner D2): the main queue carries Cost Sheet search and filtering; the Cost Sheet modal carries transaction search (ID/description) and expense-type filtering plus sorting; transaction-date ascending is the default, clicking the active criterion toggles direction with no separate direction control, and **no vendor appears anywhere** (owner D7).
- Export is **XLSX only** (owner D3) and contains **only** the filtered + sorted transactions in the on-screen order, with **separate Cost Center and Senior decision fields** (owner D4, `FR-007-043`).
- Completed sheets remain **visible, read-only, non-actionable** as "Review Completed" (owner D5); incomplete grouping renders an **honest unavailable/error state with retry**, never a partial flat fallback table (owner D8).
- **CC Manager workflow must not change**; visual language consistent with `CostCenterQueueView.vue` (owner D9).

## Repository Findings

- 006 delivered the CC manager sheet-first queue + inline quick review; its `managerSummary`/`getCostCenterSheetForManager` and `ManagerSheetReview` types are the exact model to mirror for Senior; 006 explicitly excluded Senior screens (`FR-006-027`).
- `GET /queues/senior` + `ManagementReviewService::getSeniorQueue` return **senior-stage transactions** scoped to active `SENIOR_MANAGER` assignments, **de-duplicated server-side** (`paginateUniqueQueue` collapses by transaction id before ordering and pagination) — the residual data risk is **pagination/completeness only**, not duplicates; there is no sheet grouping and no sheet-wide gate.
- `POST /transactions/{id}/decisions` (`ReviewController::decide`) already routes by stage, enforces FR-085 first-write-wins, single-transaction-only, mandatory explanation for REJECT/RETURN, and append-only stage-tagged audit — **fully reusable**, and it guarantees the CC decision is not overwritten.
- `TransactionDetail.decisions[]` carries the `COST_CENTER`-tagged advisory decision (001 `FR-034`) for per-transaction rendering.
- `useSheetQueue.ts`, `QuickReviewCard.vue`, `QuickDecisionModal.vue`, `TransactionDetailsDrawer.vue`, `EvidencePreview.vue`, and `evidencePreview.ts` are all reusable for the Senior surface; `CostCenterQueueView.vue` is the visual baseline.
- `POST /exports` row sources (`transactionRows`/`sheetRows`) are **owner-scoped** (`ReportScope::coversOwned`) and will refuse a Senior Manager; `transactionRows` resolves ids in the **client-supplied order** (a per-id loop, not a `WHERE IN`), so the existing ID-selection mechanism already preserves selection order — only a Senior-scoped row source is missing.
- No vendor/merchant field exists anywhere in the domain or `data-model.md`; per owner **D7**, vendor is **out of scope** for Feature 007 (no filter, column, placeholder, or disabled control).
- No Senior design reference exists under `docs/frontend-redesign/`.

## Missing Capabilities

1. Senior-scoped **sheet-summary / sheet-detail** read (only CC-manager variant exists: `GET /queues/cost-center/sheets/{sheetId}`).
2. Sheet-wide **CC-completion / Senior-entry gate** (owner-confirmed D1; the narrow **001 `FR-037` visibility amendment** was recorded on 2026-11-08; a backend change is required; Return-for-correction is not final; rejection semantics unchanged).
3. **Completed-sheet presentation status** (`REVIEW_COMPLETED`) supplied by the Senior sheet-queue read (owner D5).
4. Senior-scoped **XLSX-only export row source** with combined Cost Sheet + Transaction rows, separate CC/Senior decision fields, ordered `ids` selection (additive resource + contract delta; owner D3/D4).
5. (Reuse, not missing) decision command, audit, evidence preview, completeness/grouping, export pipeline mechanics — and the server already de-duplicates the queues (`paginateUniqueQueue`).

## Technical Risks

- The sheet-wide gate is owner-confirmed but not yet implemented; the narrow **001 `FR-037` visibility amendment** it required was **recorded in Feature 001 on 2026-11-08**, and the backend batch remains separately authorized (never silently applied).
- Senior modal identity/purchaser/submitted fields unavailable until the additive Senior sheet read lands.
- Senior-scoped export refused by owner-scoped `coversOwned` row sources until the additive XLSX capability is authorized.
- Vendor is out of scope (D7); only residual doc drift is possible — no product risk.
- Senior-pending pagination/completeness (server de-duplication already exists in `paginateUniqueQueue`): the completeness guard + honest unavailable/retry state are required; no partial flat fallback table (D8).
- Multi-currency amounts must never be cross-summed; currency remains a display concern, not a modal filter (the D6 amount filter was retired 2026-11-15).

## Open Product Questions (all owner decisions recorded 2026-11-08)

- ~~Accept 001 `FR-037` amendment...~~ **Resolved (D1)** — narrow FR-037 visibility amendment required and **recorded in Feature 001 on 2026-11-08**.
- ~~Vendor field...~~ **Resolved (D7)** — vendor dropped from Feature 007 scope.
- ~~Export formats...~~ **Resolved (D3)** — XLSX only.
- ~~Filter/sort placement...~~ **Resolved (D2)** — two-level.
- ~~"Cost Sheet data" in export...~~ **Resolved (D4)** — sheet header/metadata + one row per transaction, separate CC/Senior decision fields.
- ~~Sheet lifecycle...~~ **Resolved (D5)** — stays visible, "Review Completed", read-only, non-actionable.
- ~~Min/max amount semantics...~~ **Retired (2026-11-15)** — the owner-approved toolbar reconciliation removes currency and amount-range filters; the former D6 rule no longer applies.
- ~~CC RETURNED notice...~~ **Resolved (D1)** — no "Waiting for Cost Center Review" surface; returned transactions just do not satisfy the gate.

- **UXC-V2-FU-FOLLOWUP (owner-approved addition)** — "All cost centers" control: always shown when the user has assigned cost centers; selected by default; clicking All cost centers clears `selectedCenterId` and restores all authorized sheets/counts/amounts without a page refresh; search, status filters, and sorting remain intact. Cost-center options are loaded independently in `SeniorQueueView.vue` via `configurationStore.loadCostCenters()` (`#[NoAdminRequired]`, caller-scoped), not dependent on visiting `/admin/configuration`.

Remaining: only the **separate backend authorization** for the three additive backend capabilities (Senior sheet reads, the sheet gate, Senior XLSX export). The FR-037 amendment wording and its acceptance record were **recorded in Feature 001 on 2026-11-08** and are no longer outstanding.

## Information Still Required Before Task Generation

- ~~Production of the narrow **001 `FR-037` visibility amendment** text and its acceptance record~~ → **Done 2026-11-08**: the amendment was delivered through the Spec Kit requirements-change process and **recorded in Feature 001 on 2026-11-08** ("Independent transaction progression and Senior Manager Cost Sheet queue visibility", `FR-037`). It is no longer required before task generation.
- The **separate backend authorization** for the three additive backend capabilities (Senior sheet reads, the sheet gate, Senior XLSX export).
- Consent to proceed from planning to **task generation** (`/speckit-tasks`) and to create `tasks.md` under this feature directory.
- Any Senior-specific design reference, or confirmation that `CostCenterQueueView.vue` is the agreed visual baseline (owner D9 preserves the approved modal-based, Cost-Sheet-first structure).
- Confirmation that the current working-tree user changes (modified review/evidence components) are to be preserved and reused as the baseline for the Senior surfaces.

## Follow-up — server-authoritative whole-sheet stage totals (owner-authorized 2026-11-20, not yet implemented)

**Owner authorization**: `spec.md` `FR-007-057` — aggregate whole-Cost-Sheet stage-approved monetary disclosure for Cost Sheets already legitimately visible through the Senior review surface. **Contract**: the per-sheet read `GET /queues/senior/sheets/{sheetId}` requires `sheet.stageApprovedTotals` via an allOf extension on `SeniorManagerSheetReview.sheet` (`specs/001-pettycash-v1-core/contracts/ocs-api.yaml`); the shared `SeniorSheetSummary` stays without the property so the queue list rows (`SeniorSheetQueueItem`) neither carry nor pay for a per-sheet aggregate on a list page. Contract alignment is done (`UXC-7F-01`); implementation is `UXC-7F-03` in `specs/009-finance-manager-expense-review/ux-convergence-tasks.md`.

- **No new endpoint; existing gates unchanged.** `ReviewController::seniorSheet` adds one response member. `ManagementReviewService::getSeniorSheetForManager`'s scope gates, the `allTransactionsCostCenterDecided` gate (owner D1), and `reviewStatus` (owner D5) are untouched; out-of-scope transactions remain absent from `transactions` and other seniors' individual decisions remain undisclosed.
- **Backend reuse, no second rule**: the aggregate is derived by `SheetSummaryQuery::totalsForSheet()` → `SheetTotals::fromRows` (`lib/Domain/Expense/SheetTotals.php`) over the sheet's complete transaction set — the same source the senior read already uses for the sheet-wide gate's whole-set iteration (`ManagementReviewService.php` T025 pattern). One Money per stage in the sheet's single currency; a transaction counts toward a stage total only when that stage's authoritative decision on the **current active revision** is Approved, so a Return corrected and re-decided contributes only through its current-revision decision (correction behavior is covered by `UXC-7F-03`'s tests).
- **Frontend consumption (UXC-7F-05)**: `SeniorQueueView.vue` builds the Approvals tab's stage states from `sheet.stageApprovedTotals` when present; `SheetApprovalsSummary.vue` unchanged; honest unavailable/known-zero fallback preserved; no client-side whole-sheet derivation.
- **Testing (UXC-7F-03/06)**: backend — aggregate correctness over approve/reject/return/undecided mixes and the correction/current-revision rule; scope unchanged (same 404 for unknown/out-of-scope sheets); frontend — authoritative source retargeted with the fallback preserved (EN/FA, RTL/LTR unchanged).

---

*Prepared by Spec Kit planning workflow. Planning phase only — no code, no `tasks.md`, no commits, no backend/contract changes. All backend-required work stays behind a separate owner authorization; the 001 `FR-037` visibility amendment this feature requires was recorded in Feature 001 on 2026-11-08.*
