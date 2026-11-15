# Research Notes — Senior Manager Cost Sheet Review (Phase 0)

**Feature**: `specs/007-senior-manager-cost-sheet-review/` | **Date**: 2026-11-07 | **Status**: research complete; planning phase only.

## Evidence route used

Per `AGENTS.md` §2, evidence was classified as follows (no forced discovery calls for known paths/symbols):

- **Serena / OCI / CGC**: not required. Every target was a known path, literal, or authoritative source already located; direct reads and exact-text searches were the §2 table's "Known path/route/ID/literal text" route.
- **Source read (authoritative)**: backend and frontend files cited below.
- **Exact text search**: `vendor`/`merchant` across `specs/001-pettycash-v1-core/data-model.md`, `lib/Domain/Expense/TransactionRevision.php`, `lib/Domain/Expense/ExpenseTransaction.php` → **no matches** (no vendor field exists).
- **Tests**: none executed (planning only; no implementation to verify).

## Key verified sources (repository-relative, with line anchors)

| Claim | Evidence |
|---|---|
| Senior queue returns senior-stage **transactions** via `GET /queues/senior` | `lib/Controller/ReviewController.php:61-95` (slug → `WorkflowStage::Senior`, `getSeniorQueue`), route `appinfo/routes.php:55` |
| Senior decisions single-transaction, FR-085, stage-routed, explained-reject/return | `ReviewController::decide` (`:162-304`), `refuseBulkDecision` (`:334-357`), `fieldErrorsFor` explanation branch (`:396-406`); `ManagementReviewService::recordDecision` |
| CC decision immutability / stage-tagged history | `TransactionPayloadFormatter::decision` (`lib/Infrastructure/Http/TransactionPayloadFormatter.php:441-455`); `TransactionDetail.decisions[]` (`:117-131`) |
| `managerSummary` CC-shaped (mirror target) | `SheetPayloadFormatter::managerSummary`; `ManagerSheetSummary`/`ManagerSheetReview` in `src/types/api.ts:226-262`; wire schemas `specs/001-pettycash-v1-core/contracts/ocs-api.yaml:1584,1619,1663` |
| B001–B004 CC-only sheet read | `ReviewController::costCenterSheet` (`:111-131`), route `appinfo/routes.php:54`, contract `ocs-api.yaml:710-732` |
| Export row sources owner-scoped → refuses a Senior Manager | `ExportController::transactionRows`/`sheetRows` (`:584-614`, `:518-554`) via `ReportScope::coversOwned` (`lib/Application/Reporting/ReportScope.php:190-200`); closed vocab `ReportFilterFactory::ACCEPTED_FILTERS` (`lib/Application/Reporting/ReportFilterFactory.php:56-66`); `ExportRequest.resource` enum `src/types/api.ts:626-632` |
| Completeness/grouping/search/sort reusable | `src/composables/useSheetQueue.ts` (`SHEET_QUEUE_PAGE_SIZE=500`, `checkSheetQueueCompleteness`, `dedupeTransactions`, `buildSheetQueue`, `searchSheetQueue`, `sortSheetQueue`) |
| Review workspace components reusable | `QuickReviewCard.vue`, `QuickDecisionModal.vue`, `TransactionDetailsDrawer.vue`, `EvidenceCard.vue`, `DecisionForm.vue`, `EvidencePreview.vue`, `utils/evidencePreview.ts` |
| 006 governed CC surface, excluded Senior | `specs/006-management-review/spec.md` `FR-006-027`; `ManagerTransactionState`/`ManagerSheetReview` types |
| 001 workflow rules | `specs/001-pettycash-v1-core/spec.md` `FR-032`–`FR-037`, `FR-043`, `FR-085`; per-transaction advancement contradicts the sheet gate (conflict recorded in 007 spec) |

## Gap analysis (summary)

1. **Sheet-wide Senior gate** — not implemented; conflicts with 001 `FR-037` (see 007 spec Workflow Reconciliation).
2. **Senior sheet read** — only the CC manager variant exists; no `GET /queues/senior/sheets[/{sheetId}]`.
3. **Senior-scoped export** — `coversOwned` refuses the SM; resource/filter vocabularies closed.
4. **Vendor filter** — no vendor/merchant field in the data model.

## Open decisions deferred to the plan

Open decisions deferred to the plan, all resolved by the owner on 2026-11-08 (see `plan.md` §Open Questions): 001 `FR-037` amendment semantics — resolved 2026-11-08, the narrow visibility amendment is **recorded in Feature 001**; vendor (D7); export formats/scope (D3/D4); filter placement (D2); "Cost Sheet data" shape (D4); sheet lifecycle (D5); min/max amount across currencies (D6); returned-transaction notice (D1).
