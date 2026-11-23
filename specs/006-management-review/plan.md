# Implementation Plan: Sheet-First Management Review

**Branch**: `006-management-review` (logical feature directory; worktree branch unchanged) | **Date**: 2026-10-29 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/006-management-review/spec.md`

## Summary

Replace the Cost Center Manager's flat, paginated transaction queue with a **sheet-first queue** that derives its rows from the complete manager-scoped pending transaction set. The view is presentation-only: it reads the existing `GET /queues/cost-center` endpoint and the existing session scope, groups pending transactions by `sheetId` only when the response is provably complete, and labels every unsupported field honestly (sheet title, purchaser, reviewed history, sheet-wide completion, Senior Review eligibility).

Phase 1 covers the queue desktop + mobile and stops at an owner visual gate. No backend file, contract, migration, authorization rule, or 001 requirement is edited by Phase 1.

Phase 2 covers the **shared canonical Sheet Review Workspace** (§2) in bounded batches, including **Batch 1: the required manager-scoped backend capability** (no longer deferred) and **Batch 2: the queue duplicate/pagination defect moved to the dedicated bug workflow**. Manager-scoped reads reconnect the sheet title/purchaser/submitted-date and decided/returned surfaces once Batch 1 lands, without changing the information architecture. Phase 2 uses the shared frontend primitives `SheetDetailsModal`, `TransactionReviewCard`, `TransactionDetailDrawer`, `PriorDecisionHistory`, `EvidencePreview`, and `DecisionConfirmation` reused by Cost Center, Senior Manager, and Finance Manager — not stage-specific duplicate modal/card implementations.

### Canonical shared component architecture (aligned across 006 / 007 / 009)

One shared architecture is reused by all three review roles; no role builds an independent modal, card, or drawer:

- **`SheetDetailsModal`** — canonical modal shell over the mounted queue: header, sheet identity, status, owner where available, currency, first submitted date where available, transaction count, recorded amount, latest approved amount, tab navigation (Transactions, Approvals, Overview, History), focus trap, Escape, close, focus restoration, RTL/LTR, responsive layout. Stage-specific information is supplied through props/configuration/slots — never through a duplicated modal implementation.
- **`TransactionReviewCard`** (generalized `QuickReviewCard`) — compact transaction card: title/description, amount + currency, cost center where relevant, transaction state, evidence count/thumbnails, prior-decision status indicators (`✓ Approved`, `✕ Rejected`, `↩ Returned`, `• No decision yet`). Clicking the transaction title opens `TransactionDetailDrawer`.
- **`TransactionDetailDrawer`** (generalized `TransactionDetailsDrawer`) — one shared drawer: Transaction Details, Evidence, Previous Decisions (Cost Center / Senior Manager / Finance Manager shown separately, each with decision, actor, date/time, comment/explanation), Current Stage Actions. No hardcoded stage business logic; a thin stage adapter controls permitted actions.
- **`PriorDecisionHistory`** — transaction-level decision history, distinct from the sheet-level Approvals aggregate.
- **`EvidencePreview`** — shared evidence overlay (image/PDF/unknown, Previous/Next + `1/N`, inline-safe policy, download fallback).
- **`DecisionConfirmation`** (generalized `QuickDecisionModal`) — shared single-transaction decision confirmation with per-kind copy, mandatory comment for Reject/Return, submitting state, `FR-085` idempotency.

Sheet-level **Approvals** (approved monetary totals per workflow stage) and transaction-level **Previous Decisions** are explicitly different concepts and are never collapsed into one another.

## Technical Context

**Language/Version**: TypeScript 5.x, Vue 3.5, PHP 8.3+ (backend untouched)

**Primary Dependencies**: `@nextcloud/vue` 9.x, Pinia, Vue Router, `@nextcloud/l10n`, MDI icons, `@playwright/test`

**Storage**: N/A (read-only presentation of the existing queue; no new persistence)

**Testing**: Vitest + `@vue/test-utils` for unit/component behaviour; Playwright (official Google Chrome channel) for screenshots and viewport evidence

**Target Platform**: Nextcloud 34 application shell, desktop and 390px mobile, English LTR and Persian RTL, light/dark/forced-colours

**Project Type**: Web application (existing Nextcloud app)

**Performance Goals**: one queue request per refresh, no per-row network calls, no client-side pagination of a partial set presented as complete

**Constraints**: backend frozen in Phase 1 (presentation-only); Phase 2 Batch 1 is the single planned backend change (additive manager-scoped read, §2.3/§2.4, separately authorized); existing working-tree changes preserved; no Git writes; no sheet-level decisions; no fabricated aggregations

**Scale/Scope**: one queue view, one grouping module, catalogue keys, component tests, screenshot evidence

## Constitution Check

| Gate | Status | Evidence / action |
|---|---|---|
| Source-of-truth ordering | PASS | 001 spec and contracts remain authoritative; 006 only records the workflow conflict |
| Backend freeze | PASS (Phase 1) | No file under `lib/`, `appinfo/`, or `tests/Backend/` is changed by Phase 1; Phase 2 Batch 1 (B001–B005) is the documented, separately authorized exception |
| Visual evidence | PASS | Screenshots under `artifacts/visual-review/<task-id>/<iteration>/`; official Chrome channel |
| Owner approval gate | PASS | Phase 1 ends at a recorded request for approval; Phase 2 is not started |
| No fabricated data | PASS | completeness guard; unavailable states; per-currency sums |
| i18n / RTL / calendar | PASS | `direction`, `formatMoney`, `formatDate`, `formatNumber` utilities |
| Architecture layers | PASS | Presentation only; no business logic in the view |

## Project Structure

### Documentation (this feature)

```text
specs/006-management-review/
├── spec.md
├── plan.md
├── tasks.md
└── checklists/
    └── requirements.md        # generated during /speckit.analyze follow-up if needed
```

### Source (changed files)

```text
src/
├── views/reviews/CostCenterQueueView.vue          # sheet-first queue + transaction fallback
├── components/review/SheetQueueList.vue           # optional extracted presentation
├── composables/useSheetQueue.ts                   # grouping, completeness, filtering, sorting
└── ...
l10n/{en,fa}.{json,js}                             # new catalogue keys
tests/Frontend/Component/ManagementReview.spec.ts  # queue behaviour updated to the new IA
tests/Frontend/Composable/SheetQueue.spec.ts       # grouping/completeness unit tests (new)
```

## Key Design Decisions

### D1 — Complete-set grouping, not page grouping

`GET /queues/cost-center` is paginated and, because `ManagementReviewService::getCostCenterQueue` applies the offset per managed cost centre and merges the results, a response can also contain the same transaction more than once for a manager with duplicate/overlapping assignments. The plan therefore:

1. requests the largest safe page (`pageSize = 500`);
2. treats the response as **complete** only when `total <= items.length` and `items.length < 500`;
3. de-duplicates by transaction `id` before any count or sum;
4. shows an honest "sheet grouping unavailable" state otherwise, with a link to the transaction list.

Deduplication is not grouping across pages: it removes repeated copies of the *same* transaction id from one proven-complete response.

### D2 — Two presentations, sheets first

- **By sheet (default)**: derived sheet rows; default surface.
- **All transactions (fallback)**: the existing paginated `ResponsiveTable` queue, preserved unchanged behind a view switch. This keeps FR-006-017's operational fallback and every existing transaction-level entry point reachable.

Switching to the transaction view loads page 1 at 25 rows; switching back re-runs the completeness read. The view switch is not a filter and never mixes the two data sets.

### D3 — Honest fields only

| Manus field | Phase 1 rendering |
|---|---|
| Sheet title | not shown; sheet reference shown instead |
| Purchaser / owner | not shown; unavailable note in the focus banner |
| Your transactions | count of de-duplicated pending transactions in the sheet |
| Relevant amount | sum per currency (and per minor unit) of those transactions |
| Review status | derived, explicitly labelled "Awaiting your decision" |
| Submitted | "Latest activity" from the newest `activeRevision.createdAt` in the sheet, with a footnote that the sheet submission date is unavailable |
| Reviewed this period | disabled stat with an accessible reason |
| Reviewed filter | disabled tab with an accessible reason |
| Sheet-wide completion / Senior eligibility | not claimed in Phase 1; explained in the focus banner and reserved for Phase 2 |

### D4 — Row activation (shared modal, replaces inline expansion)

A sheet row opens the shared **`SheetDetailsModal`** over the mounted queue (no route navigation; the queue stays mounted behind). The modal renders the canonical tabs (Transactions, Approvals, Overview, History) and lists the sheet's pending transactions as `TransactionReviewCard`s. Clicking a transaction title opens the shared **`TransactionDetailDrawer`** one layer above the modal (single-overlay rule). There is no sheet-level Approve/Reject/Return and no "decide all".

This supersedes the former inline sheet-expansion disclosure (the previous D4 described an inline panel linking to `review-detail`). The inline-expansion path is removed as the canonical surface; `review-detail` deep links remain reachable where they do not contradict the sheet presentation.

The modal's Transactions tab shows only that manager's pending transactions in the sheet. Stage-specific information is provided through props/configuration/slots on `SheetDetailsModal` rather than a duplicated modal implementation.

### D5 — Visual language

Use the established Pettycash/Purchaser tokens: Nextcloud shell, `--font-size-*` scale, `--color-*` tokens, `StatusBadge`, card surfaces, tabular rows, and the approved pill palette. The Manus reference supplies hierarchy (eyebrow, title, scope banner, summary cards, sheet panel, search/filter/sort, rows), not typography or branding.

Mobile: one column, summary cards 2×2, filter tabs wrap, sheet rows become stacked cards with the disclosure control occupying the full row. No horizontal scroll at 390px.

## Data Derivation

```ts
interface SheetQueueItem {
  sheetId: string
  transactions: TransactionSummary[]     // de-duplicated pending transactions
  transactionCount: number
  totals: Array<{ currency: string; minorUnit: number; amountMinor: number }>
  latestActivityAt: string | null
  costCenters: string[]                  // resolved names present in the pending set
}
```

- Group key: `transaction.sheetId`; a blank/unresolvable `sheetId` becomes an explicit "unresolved sheet reference" row, never merged into another.
- Counts and sums are computed after de-duplication, over the complete response only.
- `latestActivityAt` is the maximum parseable `activeRevision.createdAt` in the group; unparsable dates do not fabricate a date.
- Sort is local and explicit: newest activity, oldest activity, most pending transactions, sheet reference.

## Testing Strategy

### Unit / component (Vitest)

- completeness guard: truncation, `total > returned`, exactly-at-threshold, duplicates;
- grouping: one row per sheet, duplicate ids counted once, blank sheet reference separated;
- multi-currency sums: no cross-currency total;
- search/sort/filter over the complete set only;
- unsupported controls disabled and explained;
- no sheet-level decision control in the rendered output;
- RTL direction, font floor, narrow-card/table agreement for the fallback table;
- empty, loading, error and unavailable states.

### Browser evidence (Playwright, Chrome channel)

- desktop 1440×900, English LTR;
- mobile 390×844, Persian RTL;
- dark theme spot-check;
- no horizontal scroll (`document.documentElement.scrollWidth <= clientWidth` and the app scroll container);
- screenshots saved under `artifacts/visual-review/T014/001/` (desktop) and `.../002/` (mobile).

### Existing tests

`tests/Frontend/Component/ManagementReview.spec.ts` queue assertions are updated to the new default sheet presentation, and the existing transaction-table assertions are retained against the transaction fallback (opening it explicitly). No test is deleted or skipped.

## Risks and Mitigations

| Risk | Mitigation |
|---|---|
| Queue response duplicated by per-cost-centre merge | de-duplicate by transaction id; test it |
| Response truncation presented as complete | hard completeness gate + unavailable state |
| Multi-currency totals summed incorrectly | group by currency + minor unit; render separately |
| Manus title/purchaser fields invite fabrication | omit; state unavailability |
| Transaction fallback drifts from the old queue | reuse `ResponsiveTable` + `queueColumns` unchanged |
| Phase 2 accidentally started | rows expand only to transaction links; workspace stays unimplemented |
| Visual acceptance assumed | stop at gate; report "awaiting owner approval" |

## Phase Plan

- **Phase 1 — Foundation**: catalogue keys, grouping/completeness module, unit tests.
- **Phase 2 — Queue UI**: desktop sheet list, summary cards, honest states.
- **Phase 3 — Mobile + RTL**: 390px layout, Persian direction, no horizontal scroll.
- **Phase 4 — Evidence**: build, deploy, screenshots, Manus comparison, owner gate.

## Complexity Tracking

No constitution violations. The only added complexity — a completeness guard and a transaction fallback — is required by the honesty and reachability requirements, not by a backend change.

---

## Phase 2 — Shared Sheet Review Workspace (canonical UX)

**Spec**: `spec.md` §"Phase 2 — Sheet Review Workspace (Canonical Sheet UX)". **Design authority**: `docs/frontend-redesign/proposals/006-management-review-quick-review/UX_SPEC.md` + `mockups/`. **Gate**: no implementation before `T022` and the Phase 2 visual gate. **Compatibility constraint**: the mockup is a static harness with invented data; its `mockup.js` renderers MUST NOT be copied into production. Production reuses the shared frontend primitives `SheetDetailsModal`, `TransactionReviewCard`, `TransactionDetailDrawer`, `PriorDecisionHistory`, `EvidencePreview`, and `DecisionConfirmation` (generalized from the existing `QuickReviewCard`, `TransactionDetailsDrawer`, `QuickDecisionModal`, `EvidencePreview`), the existing Vue components, `TransactionDetail` reads, the `DecisionForm` decision semantic, and the evidence preview overlay, adapting the design's interactions to the real data model and the honesty rules (`FR-006-011/015`, `FR-006-036…046`).

### 2.1 Data model and backend boundary (verified)

- `GET /queues/cost-center` returns only `WorkflowStage::CostCenter` transactions (`ManagementReviewService::getCostCenterQueue`), so decided/returned cards are not served today. The Phase 2 panel first shows the **connected** pending set with `useSheetQueue.buildSheetQueue` grouping; the decided/returned set and manager progress connect via planned Batch 1 (`B002`/`B003`).
- `GET /transactions/{id}` is a role-scoped read (`TransactionController::show` → `visibleTransaction`) returning `TransactionDetail` (`allowedActions`, `version`, `decisions[]` history, `evidence[]`). This backs the Full Details drawer (Description + History) and the attachments list. **The queue summary carries no `allowedActions`/`version`**, so inline decision actions require a **guarded per-transaction detail fetch** when a sheet is expanded — never a fabricated capability (`FR-006-039`).
- `GET /sheets/{id}` is owner-only (`SheetService::getSheet`), so a manager-scoped sheet read is a required backend capability (**Batch 1**, `B001`). The read reuses `ExpenseSheetRepository` (sheet aggregate fields) and the existing authorization source: only cost centers for which the user holds an active `CostCenterManager` assignment (`RoleAssignmentRepository::findActiveByUserAndRole`, same source as `getCostCenterQueue`). Real fields exist in the domain: `ExpenseSheet::title`, `ownerUid`, `firstSubmittedAt` (genuine submission date, set at submit in `ExpenseSheet::submit`, `ExpenseSheet.php:117`, persisted `first_submitted_at`, exposed by `SheetPayloadFormatter` `submittedAt`), `submittedTotalMinor`. Purchaser display name comes from the `UserDirectory` port. Until Batch 1 lands these render unavailable/absent.
- Merchant, paid-on, payment method and category are absent from `TransactionPayloadFormatter::summary`/`revisionFrom`; they remain unavailable placeholders (NOT in the required-capability scope, `spec.md` §2.4).

### 2.2 Reuse map (existing components → shared canonical surface)

| Approved surface | Reuse / adapt | Gap to build |
|---|---|---|
| Sheet Details Modal shell | new shared `SheetDetailsModal` over the mounted queue (replaces inline sheet expansion); header, identity, status, owner, currency, submitted date, transaction count, recorded amount, latest approved amount, tabs (Transactions/Approvals/Overview/History), focus trap, Escape, close, focus restoration, RTL/LTR, responsive | Stage config/slots per role; no per-role modal |
| Compact transaction card | existing `QuickReviewCard.vue` generalized → `TransactionReviewCard` (adds Finance stage via stage adapter; prior-decision indicators `✓/✕/↩/•`) | Card generalization + cost-center/evidence/prior-decision cells |
| Quick-review panel (expanded) | replaced by `SheetDetailsModal` Transactions tab; inline expansion removed | none (inline expansion deleted) |
| Compact tx card + action bar | `QuickReviewCard`-style renderer; decision semantic from `DecisionForm` | Card renderer; backend-required fields as unavailable; per-tx detail fetch for `allowedActions`/`version` |
| Decision confirmation modal | existing `QuickDecisionModal.vue` generalized → `DecisionConfirmation` (per-kind copy, `requiresExplanation` = REJECT‖RETURN, `expectedVersion`, `commandId`/FR-085 via `reviewsStore.recordDecision`) | Per-kind copy/warning, submitting/error toast flow (FR-006-043) |
| Full details drawer | existing `TransactionDetailsDrawer.vue` generalized → `TransactionDetailDrawer` (Transaction Details / Evidence / Previous Decisions / Current Stage Actions; stage adapter controls actions) | Drawer overlay + sections; stage adapter per role |
| Document thumbnails | `Evidence` type + `getEvidenceContent` + `isInlineSafeMediaType` | Thumbnail row + `+N more` tile (FR-006-040) |
| Preview overlay | existing `EvidencePreview.vue`, `EvidenceContentPolicy`, `FR-060`/`FR-005-042` | Add Previous/Next + `1 / N` counter + open-at-doc (FR-006-041) |
| Single-layer overlays, focus, RTL, mobile | `direction`, `formatMoney`, `formatSheetDate`/`formatNumber`, `EvidencePreview` focus trap/scrim | Overlay stack rules, RTL drawer mirror, mobile full-screen + expand-to-panel scroll (FR-006-037/044) |
| Sheet-level Approvals tab | new; approved monetary totals per workflow stage (CC / Senior / Finance) from authoritative stage totals; NOT per-transaction history | Approvals aggregate (sheet-level, distinct from Previous Decisions) |

### 2.3 Build sequence — five bounded batches

Phase 2 is organized into five bounded batches. **Backend batches are prerequisites for the fields/cards they connect; frontend batches are sized so the core surface works on connected data first** (`spec.md` §2.4). No batch starts before `T022` + the Phase 2 visual gate.

- **Batch 1 — Backend authorization + manager-scoped data capabilities** (backend; required product capability, no longer deferred). Backend B-tasks: `B001` manager-scoped sheet-summary read (title, purchaser identity/display name via `UserDirectory`, currency, submitted total, genuine `firstSubmittedAt` date), `B002` decided/returned transactions in the manager's scope, `B003` accurate manager progress with strict workflow separation (`FR-006-047/048` — never whole-sheet completion or Senior eligibility), `B004` API/route/contract + payload exposure, `B005` backend tests (authorization, no scope leak, workflow/date provenance). Reuses `ExpenseSheetRepository`, `RoleAssignmentRepository`, `UserDirectory`, `TransactionPayloadFormatter`/`SheetPayloadFormatter`; scope = managed cost centers only. Requires the separate backend-change authorization recorded in `spec.md` §2.4.
- **Batch 2 — Queue-defect correction (dedicated bug workflow — NOT a Feature 006 task).** The duplicate-transaction + pagination defect is backlogged to `.specify/bugs/cost-center-queue-duplicate-transactions/` and driven through the Spec Kit bug lifecycle (`/speckit-bug-assess → /speckit-bug-fix → /speckit-bug-test`), verified only when `verified`. Remediation (from its `assessment.md`): de-duplicate managed cost-center IDs/roles, fetch+merge each cost centre once, paginate once post-merge, add regression tests, cover `getSeniorQueue` too. It does not appear in the Feature 006 task ledger.
- **Batch 3 — Inline quick-review interface** (frontend). Core surface that works on connected data: panel shell, compact transaction cards (connected fields), per-card awaiting/decided presentation, single-overlay/expand-in-place, connected summary/tabs. **Backend-independent components** in this batch: T024, T025, T035 (see ledger). **Backend-connected** pieces reconnect once Batch 1 lands (sheet title, purchaser display name, submitted date on cards/panel; decided/returned cards and "X of Y decided" once `B002`/`B003` exist).
- **Batch 4 — Transaction details, evidence preview, and decision workflows** (frontend). Details drawer (Description + History), shared decision confirmation modal, attachments thumbnails + preview navigation. Mostly backend-independent (T026–T034, built on `TransactionDetail` + `decisions[]` + existing evidence policy); purchaser/sheet-title/submitted drawer fields reconnect with Batch 1.
- **Batch 5 — l10n + accessibility + targeted testing + visual verification.** Catalogue keys, responsive/390px/RTL/accessibility conformance, targeted Vitest, minimal gates, build/deploy, screenshots, mockup comparison, owner visual gate (T023, T036–T043). Note: evidence screenshots after Batch 1 (T040/T041/T042) show connected backend fields; pre-Batch-1 renders show unavailable states.

**Queue defect**: see Batch 2 — a dedicated bug-workflow item (`/speckit-bug-*`), never folded into a Feature 006 task.

### 2.4 Risks and mitigations

| Risk | Mitigation |
|---|---|
| Copying mockup JS/renderers into production | reuse existing Vue components and tokens; mockup is reference only |
| Inline decision without `allowedActions` | guarded `GET /transactions/{id}` fetch per expanded sheet; never enable an unproven action |
| Decided/returned cards + "X of Y decided" expected | Batch 1 (`B002`/`B003`) is the required capability; until it lands the panel shows the connected pending set (`FR-006-046`) |
| Manager progress conflated with sheet completion | `B003` keeps awaiting/decided/returned/manager-complete distinct and never infers whole-stage or Senior (`FR-006-047/048`) |
| Preview without navigation looked complete | add navigation only over a transaction's returned evidence; never fabricate a page |
| Honesty drift on summary/tabs | connect every stat to the complete set; remove invented/disabled placeholders (final decision #2) |
| Phase 2 starting before gates | plan stops at `T022` and the Phase 2 visual gate; no implementation task is marked runnable |

### 2.5 Follow-up technical approach — server-authoritative whole-sheet stage totals (authorized 2026-11-20, not yet implemented)

**Owner authorization**: `spec.md` §2.5 (`FR-006` aggregate disclosure for Cost Sheets already visible through the Cost Center review surface). **Contract**: additive `StageApprovedTotals` schema on `ManagerSheetSummary` (`specs/001-pettycash-v1-core/contracts/ocs-api.yaml`), already applied and generated (`UXC-7F-01`). **Tasks**: `UXC-7F-01/02` in `specs/009-finance-manager-expense-review/ux-convergence-tasks.md`.

- **No new endpoint.** `GET /queues/cost-center/sheets/{sheetId}` (`ReviewController::costCenterSheet`) gains one additive response member: `sheet.stageApprovedTotals`.
- **Backend reuse, no second rule**: `SheetPayloadFormatter` gains a small method wrapping the already-injected `SheetSummaryQuery::totalsForSheet(sheetDbId)`, which derives `SheetTotals` (`lib/Domain/Expense/SheetTotals.php::fromRows`) from the sheet's **complete** transaction set — the same source the owner-facing `GET /sheets/{id}` payload and the reports/exports already use. One Money per stage, in the sheet's own single-currency; a transaction counts toward a stage total only when that stage's authoritative decision on the **current active revision** is Approved.
- **Authorization unchanged**: the service's scope gates (`ManagementReviewService::getCostCenterSheetForManager`) are untouched; the aggregate rides a read the reviewer is already entitled to call, and discloses no out-of-scope transaction, evidence, actor, comment, or individual decision. Out-of-scope transactions remain absent from `transactions`.
- **Frontend consumption (UXC-7F-05)**: `CostCenterQueueView.vue` builds the Approvals tab's Cost Center/Senior/Finance states from `sheet.stageApprovedTotals` when present; `SheetApprovalsSummary.vue` is unchanged; the honest unavailable/known-zero fallback remains for missing/failed payloads; `src/utils/stageApprovals.ts` stays as the fallback/Finance path (no client-side whole-sheet derivation).
- **Testing (UXC-7F-02/06)**: backend — aggregate equals `SheetTotals` figures over an approve/reject/return/undecided mix, superseded-revision approval excluded, out-of-scope transactions contribute to the aggregate without appearing in `transactions`; frontend — authoritative source retargeted with the fallback preserved (EN/FA, RTL/LTR unchanged).
