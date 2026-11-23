# Data Model: Finance Manager Expense Review (Feature 009)

**Feature**: `009-finance-manager-expense-review` | **Date**: 2026-11-19 | **Spec**: [spec.md](./spec.md) | **Plan**: [plan.md](./plan.md)

**Purpose**: Phase 1 data model — TypeScript interfaces and state model for the frontend-only adaptation. No database schema change (spec FR-009-026; no schema change required by this plan). All interfaces are additive extensions of `src/types/api.ts` unless noted.

---

## 1. Schema Change Decision

**No schema change.** The Cost Sheet queue row is a presentation projection over existing `TransactionSummary` data (`sheetId`, `activeRevision`, `stage`, `status`, `finalOutcome`, `latestDecision`, `evidenceCount`, `version`). Sheet identity fields (title/purchaser/submitted date) are intentionally unavailable from the flat `GET /queues/finance` response and render as honest unavailable placeholders until a separately-authorized backend capability provides them.

If a future planning cycle determines a schema change would be required, it is an owner-controlled architectural decision to report, not to silently include here.

---

## 2. Frontend TypeScript Interfaces (additive, extend `types/api.ts`)

### Finance Sheet Queue Item

A presentation projection over the proven-complete finance-scoped set, not a persisted entity.

```ts
/** Finance stage: a transaction at the Finance stage reachable by Finance under existing workflow rules. */
export type FinanceEligibleStatus =
  | 'SENIOR_APPROVED'
  | 'SENIOR_REJECTED'
  | 'AWAITING_FINANCE_REVIEW'

/** One currency's finance-eligible total inside a sheet. Amounts from different currencies never mix. */
export interface FinanceSheetAmountTotal {
  currency: string
  minorUnit: number
  amountMinor: number
}

/** A cost centre as it appears in the finance pending set. */
export interface FinanceSheetCostCenter {
  code: string
  name: string
}

/**
 * A Cost Sheet queue row: one row per Cost Sheet over the de-duplicated, proven-complete
 * finance-eligible set. Not a persisted entity; all members derived from the flat
 * `GET /queues/finance` response.
 */
export interface FinanceSheetQueueItem {
  /** The owning sheet's public uuid (always available from `TransactionSummary.sheetId`). */
  sheetId: string
  /** De-duplicated finance-eligible transactions, newest activity first. */
  transactions: TransactionSummary[]
  /** Count of de-duplicated finance-eligible transactions proven in the response. */
  transactionCount: number
  /** Per-currency totals (currency + minor unit; never a cross-currency total). */
  totals: FinanceSheetAmountTotal[]
  /** Newest parseable `activeRevision.createdAt`; `null` when none exists. */
  latestActivityAt: string | null
  /** Distinct cost centres present in the sheet's finance-eligible transactions. */
  costCenters: FinanceSheetCostCenter[]
  /** Review progress/status derived only from proven-complete data; `null` when completeness unproven. */
  reviewStatus: FinanceSheetReviewStatus | null
}

/** Sheet-level review progress over the finance-eligible set. */
export interface FinanceSheetReviewProgress {
  awaitingFinance: number   // transactions with no FINANCE decision yet
  decidedByFinance: number  // transactions carrying a FINANCE-stage decision
  total: number             // = awaitingFinance + decidedByFinance (de-duplicated finance-eligible set)
}

export type FinanceSheetReviewStatus =
  | 'PENDING_FINANCE_REVIEW'
  | 'FINANCE_REVIEW_COMPLETE'   // only when backend proves (future); null when unproven
```

### Completeness Guard Result

Modeled on `SheetQueueCompleteness` from `useSheetQueue.ts`, reused for Finance:

```ts
export interface FinanceQueueCompleteness {
  complete: boolean
  reason: 'complete' | 'empty' | 'at-threshold' | 'truncated' | 'no-total'
}
```

### Queue Sort (optional, deferred)

Queue-level sort over Cost Sheets (if later added per FR-009-005):

```ts
export type FinanceQueueSortKey = 'reference' | 'latestActivity' | 'transactionCount' | 'amount'

export interface FinanceQueueSort {
  key: FinanceQueueSortKey
  direction: 'asc' | 'desc'
}
```

### L10n Key Additions (additive to `l10n/{en,fa}.{json,js}`)

New catalogue keys under `reviews.financeSheet.*` (mirroring `reviews.seniorSheet.*` naming):

- `reviews.financeSheet.queue` — page title ("Finance expense review")
- `reviews.financeSheet.reference` — "Sheet"
- `reviews.financeSheet.pendingCount` — "Pending finance decisions"
- `reviews.financeSheet.amount` — "Amount"
- `reviews.financeSheet.latestActivity` — "Latest activity"
- `reviews.financeSheet.status` — "Review status"
- `reviews.financeSheet.searchLabel` / `reviews.financeSheet.searchPlaceholder` — sheet search
- `reviews.financeSheet.empty.title` / `reviews.financeSheet.empty.body` — empty state
- `reviews.financeSheet.unavailable.title` / `reviews.financeSheet.unavailable.body` — incomplete grouping
- `reviews.financeSheet.unavailable.action` — retry
- `reviews.financeSheet.modal.title` — "Sheet {reference}"
- `reviews.financeSheet.modal.titleUnavailable` — "Title unavailable"
- `reviews.financeSheet.modal.ownerUnavailable` — "Owner unavailable"
- `reviews.financeSheet.modal.transactions` — "Transactions"
- `reviews.financeSheet.modal.close` — "Close sheet"
- `reviews.financeSheet.modal.emptyTransactions` — no finance-eligible transactions in sheet
- `reviews.financeSheet.reviewCompleted` — (future; not claimed at this stage)

Existing finance keys reused unchanged: `finance.decision`, `finance.cannotApproveSeniorRejected`, `finance.managementDecision`, `reviews.advisoryDecision`, `reviews.decision.approve/reject/return`, `reviews.amount`, `reviews.costCenter`, `reviews.expenseDate`, `reviews.expenseType`, `reviews.evidence`.

---

## 3. State Model

| State | Owned by | Values / transitions |
|---|---|---|
| Finance queue page | `reviewsStore.financeQueue` (`TransactionPage<TransactionSummary>`) | loading → loaded \| error; completeness flag from `checkFinanceQueueCompleteness` |
| Sheet list (presentation) | `useFinanceQueue` derived | one row per sheet; rows derived only when set complete; otherwise honest unavailable/error + retry — never partial flat table |
| Open modal | queue view local (`openSheetId`) | sheet list → modal; modal → closed (focus restore to originating row) |
| Active overlay | queue/modal local | none → modal → (details drawer \| preview) → back; one layer at a time |
| Preview | `EvidencePreview.vue` local | closed → open at doc; next/prev (`navigate`); close (Escape/scrim/button) |
| Full detail | `TransactionDetailsDrawer.vue` local | readState loading/loaded/forbidden/notFound/unavailable |
| Decision modal | `QuickDecisionModal` / `DecisionForm` local | closed → open(kind) → submitting → decided \| error; close on success |
| Per-transaction detail cache | `reviewsStore.managerTransactionDetails` + `managerTransactionReads` | loading → loaded; cleared after decision (stale) |

All derived statuses come only from the loaded response or explicit unavailable; no local status fabricated after refusal.

---

## 4. No New Persistence

No new tables, columns, migrations, or domain entities. The frontend reads existing `TransactionSummary`/`TransactionDetail` payloads and the existing decision command path (`POST /transactions/{id}/decisions`) records Finance decisions unchanged. No `FinanceSheet` aggregate, no `sheetStatus` column, no new workflow stage.
