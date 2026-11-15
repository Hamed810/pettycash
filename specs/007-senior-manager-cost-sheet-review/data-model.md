# Data Model — Senior Manager Cost Sheet Review (Phase 1)

**Feature**: `specs/007-senior-manager-cost-sheet-review/` | **Status**: design note reconciled 2026-11-15 to the owner-approved Senior modal toolbar; no persisted entity or schema is introduced.

## Principle

No persisted entity is introduced by the frontend. The Senior surface is a presentation projection over existing data plus additive backend reads. Any new persistence (e.g., a materialized sheet-gate flag) is out of scope unless the recorded 001 `FR-037` amendment (2026-11-08) authorizes it, and belongs to a separate backend batch.

## Source aggregates (reused as-is)

- `ExpenseTransaction` / `TransactionRevision`: amount (Money), `expenseDate`, `description`, `costCenter`, `expenseType`, `createdBy/At`, `stage`, `status`, `lockVersion` (`TransactionPayloadFormatter::summary/revision`).
- `WorkflowDecision`: immutable, stage-tagged (`COST_CENTER`/`SENIOR`/`FINANCE`), actor snapshot, explanation, `decidedAt` (`TransactionPayloadFormatter::decision`).
- `Evidence`: id, transactionId, revisionId, name, mediaType, sizeBytes, active, uploadedBy/At, deactivationReason (`TransactionPayloadFormatter::evidence`).
- `ExpenseSheet`: title, ownerUid, currency, `firstSubmittedAt`, submittedTotalMinor (used by the authorized Senior sheet read when it lands; owner-only read path `SheetService::getSheet`).
- `RoleAssignment`: active `SENIOR_MANAGER` assignments give the manager scope (`RoleAssignmentRepository::findActiveByUserAndRole`, `ReportScope::forActor`).

## Frontend TS interfaces (additive, `src/types/api.ts`)

```ts
export type SeniorManagerTransactionState =
  | 'AWAITING_SENIOR_DECISION'
  | 'DECIDED_BY_SENIOR'

export interface SeniorSheetSummary {
  id: string
  title: string
  ownerId: string
  ownerDisplayName: string
  currency: string
  submittedTotalMinor: number | null
  submittedTotal: Money | null
  submittedAt: string | null
  version: number
  /** Authoritative only when the backend proves the sheet gate; null = cannot prove (this phase). */
  allTransactionsCostCenterDecided: boolean | null
}

export interface SeniorManagerSheetReview {
  sheet: SeniorSheetSummary
  progress: { awaitingSenior: number; decidedBySenior: number; total: number }
  transactions: Array<TransactionSummary & {
    seniorState: SeniorManagerTransactionState
    seniorDecision: Decision | null
  }>
}

export interface SeniorFilterState {
  query?: string
  description?: string
  expenseTypeIds?: string[]
}

export type SeniorSortKey = 'amount' | 'expenseDate' | 'expenseType'
export type SeniorSortDirection = 'asc' | 'desc'

/**
 * Default: expenseDate ascending (oldest first). Clicking the active criterion toggles
 * direction; selecting a different criterion starts ascending. No separate direction control.
 */
export interface SeniorSort {
  key: SeniorSortKey
  direction: SeniorSortDirection
}

/** Presentation sheet-row (derived, never persisted). */
export interface SeniorSheetQueueItem {
  sheetId: string
  transactions: TransactionSummary[]        // de-duplicated senior-pending transactions
  transactionCount: number
  totals: Array<{ currency: string; minorUnit: number; amountMinor: number }>
  latestActivityAt: string | null
  costCenters: Array<{ id: string; name: string }>
}
```

## Wire schemas (proposed, additive to `contracts/ocs-api.yaml`)

Additive `SeniorSheetSummary`, `SeniorManagerSheetReview`, and a Senior export resource (`senior-queue`) mirroring the CC-manager shapes at `ocs-api.yaml:1584/1619/1663` and the `ExportRequest.resource` vocabulary. Not ratified by this plan.

## Derived invariants

- Counts and sums are computed **after** de-duplication, over a proven-complete set only.
- Amounts group by `currency` + `minorUnit`; never a cross-currency total (006 `FR-006-014`).
- `seniorState`/`seniorDecision` come from the server's Senior-scoped read only; CC decisions render from `decisions[]` filtered to `stage === 'COST_CENTER'`, never rolled up.
- No client-derived sheet-wide completion or eligibility claim (`FR-007-013`).
