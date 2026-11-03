# Implementation Plan: Sheet-First Management Review

**Branch**: `006-management-review` (logical feature directory; worktree branch unchanged) | **Date**: 2026-10-29 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/006-management-review/spec.md`

## Summary

Replace the Cost Center Manager's flat, paginated transaction queue with a **sheet-first queue** that derives its rows from the complete manager-scoped pending transaction set. The view is presentation-only: it reads the existing `GET /queues/cost-center` endpoint and the existing session scope, groups pending transactions by `sheetId` only when the response is provably complete, and labels every unsupported field honestly (sheet title, purchaser, reviewed history, sheet-wide completion, Senior Review eligibility).

Phase 1 covers the queue desktop + mobile and stops at an owner visual gate. No backend file, contract, migration, authorization rule, or 001 requirement is edited.

## Technical Context

**Language/Version**: TypeScript 5.x, Vue 3.5, PHP 8.3+ (backend untouched)

**Primary Dependencies**: `@nextcloud/vue` 9.x, Pinia, Vue Router, `@nextcloud/l10n`, MDI icons, `@playwright/test`

**Storage**: N/A (read-only presentation of the existing queue; no new persistence)

**Testing**: Vitest + `@vue/test-utils` for unit/component behaviour; Playwright (official Google Chrome channel) for screenshots and viewport evidence

**Target Platform**: Nextcloud 34 application shell, desktop and 390px mobile, English LTR and Persian RTL, light/dark/forced-colours

**Project Type**: Web application (existing Nextcloud app)

**Performance Goals**: one queue request per refresh, no per-row network calls, no client-side pagination of a partial set presented as complete

**Constraints**: backend frozen; existing working-tree changes preserved; no Git writes; no sheet-level decisions; no fabricated aggregations

**Scale/Scope**: one queue view, one grouping module, catalogue keys, component tests, screenshot evidence

## Constitution Check

| Gate | Status | Evidence / action |
|---|---|---|
| Source-of-truth ordering | PASS | 001 spec and contracts remain authoritative; 006 only records the workflow conflict |
| Backend freeze | PASS | No file under `lib/`, `appinfo/`, or `tests/Backend/` is changed |
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

### D4 — Row activation

A sheet row expands inline (disclosure) to list **only that manager's pending transactions** in the sheet. Each transaction links to the existing `review-detail` route with `stage: 'cost-center'`. There is no sheet-level Approve/Reject/Return and no "decide all". The expanded panel states that a sheet review workspace is the next milestone.

This satisfies US1 #8 without starting Phase 2: the manager reaches a real transaction-level review path in two activations.

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
