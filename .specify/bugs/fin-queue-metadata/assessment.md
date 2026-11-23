# Bug Assessment: Finance queue missing purchaser/submission metadata

- **Slug**: fin-queue-metadata
- **Tracking ID**: BUG-FIN-QUEUE-METADATA
- **Created**: 2026-11-22
- **Verdict**: verified (fix implemented, tests pass, runtime verified)
- **Severity**: high
- **Fixed in**: PR/Commit for BUG-FIN-QUEUE-METADATA

## Report

The Finance queue table's Purchaser and Submitted cells render hardcoded `Purchaser unavailable` / `Date unavailable` fallbacks. No purchaser identity, sheet submission timestamp, or human-readable sheet title is available from the Finance queue API response. The owner rejects this as a legitimate business/data state: a sheet reaching Finance must already have both purchaser/owner identity and the original submission timestamp.

## Symptom

`src/views/reviews/FinanceQueueView.vue:397-404` renders `t('pettycash', 'reviews.sheetQueue.purchaserUnavailable')` and `t('pettycash', 'reviews.sheetQueue.submittedUnavailable')` unconditionally for every sheet row. No data field is consumed — the cells are static unavailable text.

## End-to-end data path

1. **Frontend view** — `src/views/reviews/FinanceQueueView.vue:343-404`: `sheetColumns()` declares `purchaser` and `submitted` columns; `sheetCell()` returns hardcoded unavailable strings for both keys (lines 397-404). The `Sheet` cell (lines 358-386) displays the full sheet UUID as a clickable title button (`data-testid="sheet-title"`) and then repeats the same identifier via `CompactIdentifier` (`data-testid="sheet-reference"`) beneath it — UUID duplication.
2. **Frontend composable** — `src/composables/useFinanceQueue.ts`: `FinanceSheetQueueItem` (lines 32-53) intentionally carries no purchaser/owner, submitted date, or sheet title. Comment at lines 28-30: "nothing here carries a sheet title, purchaser or submitted date, because the flat `GET /queues/finance` response does not expose them." `latestActivityAt` (line 41) is the newest `activeRevision.createdAt` among the sheet's transactions — a transaction revision time, not a sheet submission time.
3. **Store** — `src/stores/reviews.ts:285-299`: `fetchFinanceQueue()` → `reviewsApi.fetchAllFinanceQueues()` → `GET /queues/finance`. The response is `TransactionPage<TransactionSummary>`.
4. **Backend controller** — `lib/Controller/ReviewController.php:543-561`: `financeQueueItems()` maps each transaction through `$this->payloadFormatter->summary($transaction)` (TransactionPayloadFormatter) and stamps only `stageApprovedTotals` on top.
5. **Backend formatter** — `lib/Infrastructure/Http/TransactionPayloadFormatter.php:55-92`: `summary()` emits `id`, `sheetId`, `activeRevision`, `stage`, `status`, `finalOutcome`, `latestDecision`, `evidenceRequired`, `evidenceCount`, `version`. No purchaser, no `submittedAt`, no sheet title.
6. **Backend service** — `lib/Application/Workflow/ManagementReviewService.php:1109-1128`: `getFinanceQueue()` → `transactionRepository->findForFinanceQueue()`.

## Authoritative source contains the data

Yes — all three values exist in the domain/persistence layer:

| Value | Authoritative field | Source evidence |
|-------|---------------------|-----------------|
| Purchaser identity | `ExpenseSheet.ownerUid` → `ownerDisplayName` | `lib/Infrastructure/Persistence/Expense/DbExpenseSheetRepository.php:224,244` (stored as `owner_uid`); `SheetPayloadFormatter.php:52-77` emits `ownerId`, `ownerDisplayName` |
| Sheet submission timestamp | `ExpenseSheet.firstSubmittedAt` | `DbExpenseSheetRepository.php:224,244` (stored as `first_submitted_at`); `SheetPayloadFormatter.php:77` emits `submittedAt` from `firstSubmittedAt`; `ManagerSheetSummary.submittedAt` (`src/types/api.ts:267`) documented as "the genuine first-submission instant" |
| Sheet title | `ExpenseSheet.title` | `SheetPayloadFormatter.php:52`; `ManagerSheetSummary.title` (`src/types/api.ts:259`) |

The per-sheet manager read (`GET /queues/cost-center/sheets/{sheetId}`) already projects `title`, `ownerDisplayName`, and `submittedAt` via `SheetPayloadFormatter::summary()` / `ManagerSheetSummary`.

## Where values are lost

The Finance queue projection uses `TransactionPayloadFormatter::summary()` (transaction-scoped), which does not load or project the owning `ExpenseSheet`'s `ownerUid`, `ownerDisplayName`, `firstSubmittedAt`, or `title`. These fields exist on `ExpenseSheet` and are emitted by `SheetPayloadFormatter::summary()` for per-sheet reads, but are dropped by the Finance queue's transaction-scoped projection.

## Answers to assessment questions

1. **Does the domain/persistence layer contain purchaser identity?** Yes — `ExpenseSheet.ownerUid` (`owner_uid`), resolved to `ownerDisplayName` via `UserDirectory`.
2. **Does it contain the sheet submission timestamp?** Yes — `ExpenseSheet.firstSubmittedAt` (`first_submitted_at`), documented as "the genuine first-submission instant, not the sheet's creation time."
3. **Which authoritative fields represent them?** `ExpenseSheet::ownerUid`, `ExpenseSheet::firstSubmittedAt`, `ExpenseSheet::title`; exposed as `ownerDisplayName`, `submittedAt`, `title` on `ManagerSheetSummary`/`SheetSummary`.
4. **Are those fields present in an existing API response but dropped by the Finance frontend mapper/type?** The fields exist in `SheetPayloadFormatter::summary()` output (per-sheet reads) but are never in the Finance queue response. The Finance frontend mapper/type lacks them because the backend never sends them — not because the mapper drops an already-present field.
5. **Or are they omitted by the Finance backend projection/DTO?** Yes — omitted by `ReviewController::financeQueueItems()` → `TransactionPayloadFormatter::summary()`, which is transaction-scoped and does not join the owning sheet.
6. **Does the API contract already define them?** `TransactionSummary` (`src/types/api.ts:200-236`) does NOT define purchaser/submittedAt/title. Those are on `SheetSummary`/`ManagerSheetSummary`. The contract would need additive fields (or a finance-specific queue item shape).
7. **Is the missing human-readable sheet title caused by the same projection gap?** Yes — same root cause: the Finance queue projection is transaction-scoped and does not project `ExpenseSheet.title`.
8. **Is `latestActivityAt` currently being incorrectly substituted for `submittedAt`?** No incorrect substitution in the queue table: the `submitted` cell shows "Date unavailable" (hardcoded). In the Finance overview tab (`FinanceQueueView.vue:223-228`), `latestActivityAt` is labeled `reviews.financeSheet.latestActivity` — presented as latest activity, not as submitted date. `latestActivityAt` (newest transaction revision `createdAt`) is a different event from sheet submission and must not be relabeled as `submittedAt`.
9. **Can the correct values be restored without a database/schema change?** Yes — the values exist on `ExpenseSheet`; no schema or migration change is needed. The fix is a projection/API/type/frontend change.
10. **Which exact layer should own the eventual fix?** Backend projection layer first (`ReviewController::financeQueueItems` / `ManagementReviewService::getFinanceQueue` or a finance-aware sheet projection joining `ExpenseSheet`), then API contract/type (`src/types/api.ts` additive fields on `TransactionSummary` or a finance queue item type), then frontend mapper (`src/composables/useFinanceQueue.ts` `FinanceSheetQueueItem`) and view (`src/views/reviews/FinanceQueueView.vue` sheet cells).

## Bug classification

**Backend projection gap** — purchaser identity, sheet submission timestamp, and sheet title exist on `ExpenseSheet` and are projected by `SheetPayloadFormatter` for per-sheet reads, but the Finance queue's transaction-scoped projection (`TransactionPayloadFormatter::summary`) omits them. Not a persistence/schema defect; no database change required. The frontend lacks the fields because the backend never sends them.

## Proposed fix scope (assessment only — not implemented)

Smallest correct future fix:

- **Backend projection**: `ReviewController::financeQueueItems()` (`lib/Controller/ReviewController.php:543`) or `ManagementReviewService::getFinanceQueue()` (`lib/Application/Workflow/ManagementReviewService.php:1109`) to project purchaser (`ownerUid`/`ownerDisplayName`), submitted timestamp (`firstSubmittedAt`), and sheet title per finance queue item, using a batched sheet lookup over the page's distinct `sheetId` values (mirroring `stageApprovedTotalsForSheets` batched shape — no per-row N+1).
- **API contract/type**: additive fields on `TransactionSummary` (`src/types/api.ts:200-236`) or a finance-specific queue item type — `purchaser`/`ownerDisplayName`, `submittedAt`, `title` — with contract alignment in `specs/001-pettycash-v1-core/contracts/ocs-api.yaml` and `src/generated/api.ts` regeneration.
- **Frontend mapper**: `src/composables/useFinanceQueue.ts` `FinanceSheetQueueItem` gains `purchaser`, `submittedAt`, `title` fields; `buildFinanceSheetQueue` maps them from the API payload.
- **Frontend view**: `src/views/reviews/FinanceQueueView.vue` `sheetCell()` `purchaser`/`submitted` cases render real values instead of hardcoded unavailable strings; `reference` case shows human-readable title as primary text with compact reference as secondary metadata (no UUID duplication).

Likely files to change: `lib/Controller/ReviewController.php`, `lib/Application/Workflow/ManagementReviewService.php`, `lib/Infrastructure/Http/TransactionPayloadFormatter.php` (or a finance-specific projection), `specs/001-pettycash-v1-core/contracts/ocs-api.yaml`, `src/generated/api.ts`, `src/types/api.ts`, `src/composables/useFinanceQueue.ts`, `src/views/reviews/FinanceQueueView.vue`.

## Fix implemented (verified)

The fix was implemented end-to-end and verified through tests:

### Root cause confirmed
The Finance queue projection used `TransactionPayloadFormatter::summary()` (transaction-scoped), which omitted sheet-level fields (`title`, `ownerUid`, `ownerDisplayName`, `firstSubmittedAt`) that exist on `ExpenseSheet` and are projected by `SheetPayloadFormatter::managerSummary()` for per-sheet reads.

### Changes made

**Backend projection** (`ReviewController::financeQueueItems()`):
- `lib/Controller/ReviewController.php:543` — collects distinct sheet dbIds from the page's transactions, batch-loads sheet metadata via `SheetPayloadFormatter::financeSheetMetadataBySheets()` (bounded by distinct sheet count per page, not row count — no N+1), and attaches `sheetTitle`, `sheetOwnerId`, `sheetOwnerDisplayName`, `sheetSubmittedAt` to each item summary alongside the existing `stageApprovedTotals`.
- `lib/Infrastructure/Http/SheetPayloadFormatter.php` — added `financeSheetMetadataBySheets(array $sheetDbIds): array` method that loads sheets via `ExpenseSheetRepository::findById()` (bounded loop over distinct IDs) and projects metadata via `managerSummary()`; added `ExpenseSheetRepository` to the constructor.
- `lib/AppInfo/Application.php:360` — wired `ExpenseSheetRepository` into `SheetPayloadFormatter` DI registration.

**API contract**:
- `specs/001-pettycash-v1-core/contracts/ocs-api.yaml` — added optional `sheetTitle`, `sheetOwnerId`, `sheetOwnerDisplayName`, `sheetSubmittedAt` fields to `TransactionSummary`, documented as emitted ONLY on `GET /queues/finance` items (same pattern as `stageApprovedTotals`).
- `src/generated/api.ts` — regenerated via `scripts/validate-openapi.sh --write`; drift check passes.
- `src/types/api.ts` — added matching optional fields to hand-written `TransactionSummary`.

**Frontend**:
- `src/composables/useFinanceQueue.ts` — `FinanceSheetQueueItem` gains `sheetTitle`, `sheetOwnerId`, `sheetOwnerDisplayName`, `sheetSubmittedAt` fields; `buildFinanceSheetQueue` maps them from the first transaction of each sheet group (all items of a sheet carry identical server-projected metadata).
- `src/views/reviews/FinanceQueueView.vue` — `sheetCell()` renders `sheetTitle` as the Sheet cell's primary button text with `CompactIdentifier` as secondary reference (no UUID duplication); `sheetOwnerDisplayName` for Purchaser instead of "Purchaser unavailable"; `sheetSubmittedAt` formatted for Submitted instead of "Date unavailable"; `financeAdapter` identity carries title/owner/submitted. Empty-string metadata falls back to unavailable placeholders.

### Verification
- Backend: `tests/Contract/Controller/ManagementReviewControllerTest.php` — 23 tests pass, including new `testFinanceQueueItemsCarrySheetMetadata` asserting title/purchaser/submittedAt are present on finance queue items.
- Frontend: 1263 tests pass across 67 files, including FinanceQueue and FinanceSheetModal tests covering metadata rendering, unavailable fallbacks, and no UUID duplication.
- `scripts/validate-openapi.sh` — contract/types drift check passes.
- `npm run typecheck`, `npm run lint`, `npm run stylelint`, `npm run build` — all pass.

## Returned-transaction count feasibility (supplementary)

Partially derivable frontend-side from existing `TransactionSummary` data: a transaction back at the current stage whose `latestDecision?.stage === currentStage && latestDecision?.decision === 'RETURN'` identifies a returned transaction requiring a new decision. Superseded decisions are naturally excluded (only `latestDecision` is consulted). Caveats: `latestDecision` is optional in `TransactionSummary` and may not be populated in all queue summaries; the heuristic relies on `status` matching the current stage. No dedicated "returned count" field exists in any queue projection, and no server projection exposes a pre-computed returned count. The count can be computed frontend-side with the above heuristic, but reliability depends on `latestDecision` being present in queue responses. No new backend field is strictly required if `latestDecision` is reliably populated; otherwise an additive server-provided flag/count would be needed.
