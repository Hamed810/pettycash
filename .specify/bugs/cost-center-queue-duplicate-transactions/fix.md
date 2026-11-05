# Bug Fix: Cost Center and Senior queues return duplicate transactions

- **Slug**: cost-center-queue-duplicate-transactions
- **Fixed**: 2026-11-04
- **Assessment**: ./assessment.md
- **Status**: applied

## Summary

The manager queues now resolve each managed cost-centre scope once, read the authorized transactions once with a deterministic global order, de-duplicate by transaction ID, and apply the requested page after that. Total counts come from the unique set. The Senior queue had the same role-loop/merge shape and was corrected in the same change.

## Changes

| File | Change | Notes |
|------|--------|-------|
| `lib/Application/Workflow/ManagementReviewService.php` | modified | Added `managedCostCenterIds()` (one assignment read, de-duplicated IDs), `paginateUniqueQueue()` (ID de-duplication, stable ordering, one global page), and used them in both `getCostCenterQueue()` and `getSeniorQueue()`. `getUserRoles()` now returns one entry per role instead of one per assignment. |
| `lib/Domain/Expense/Repository/ExpenseTransactionRepository.php` | modified | Added `findByCostCenterIds()` so the authorized scope is read once instead of once per assignment/cost centre. |
| `lib/Infrastructure/Persistence/Expense/DbExpenseTransactionRepository.php` | modified | Implemented `findByCostCenterIds()` with a single `IN` query, `created_at DESC`, and `id DESC` tiebreak. Added the same tiebreak to `findByCostCenterId()`. |
| `tests/TestDoubles/InMemoryExpenseTransactionRepository.php` | modified | Implemented `findByCostCenterIds()`, fixed cost-centre matching to use the numeric `cost_center_id` join key, honored the offset, and mirrored the production ordering. |
| `tests/Integration/Workflow/ManagementQueuePaginationTest.php` | added | Regression coverage for overlapping assignments, multiple managed cost centres, unique totals, complete pagination, stable ordering, no cross-scope leakage, Senior queue correctness, and agreement with the manager-scoped sheet summary. |

## Diff Highlights

The queue no longer loops roles or cost centres per assignment:

```php
$managedCostCenterIds = $this->managedCostCenterIds($actorUid, Role::CostCenterManager);

return $this->paginateUniqueQueue(
    $this->transactionRepository->findByCostCenterIds(
        $managedCostCenterIds,
        WorkflowStage::CostCenter
    ),
    $page,
    $pageSize
);
```

The shared pagination pass de-duplicates first, then orders, then slices:

```php
$unique = [];
foreach ($transactions as $transaction) {
    $unique[$transaction->id] ??= $transaction;
}

$ordered = array_values($unique);
// createdAt DESC, then dbId DESC as the deterministic page-boundary tiebreaker.

return [
    'items' => array_slice($ordered, max(0, ($page - 1) * $pageSize), $pageSize),
    'total' => count($ordered),
];
```

`DbExpenseTransactionRepository::findByCostCenterIds()` applies the same order in SQL:

```php
->where($qb->expr()->in(
    'r.cost_center_id',
    $qb->createNamedParameter($costCenterIds, IQueryBuilder::PARAM_INT_ARRAY)
))
// ...
->orderBy('t.created_at', 'DESC')
->addOrderBy('t.id', 'DESC');
```

## Tests Added or Updated

- `tests/Integration/Workflow/ManagementQueuePaginationTest.php::testCostCenterQueueReturnsEachTransactionOnceWithOverlappingAssignments` — two active assignments to the same cost centre plus a second managed centre; asserts unique IDs, unique total, and no out-of-scope transaction.
- `tests/Integration/Workflow/ManagementQueuePaginationTest.php::testCostCenterQueuePaginatesTheUniqueSetWithoutGapsOrOverlap` — seven transactions across two managed centres, pages of three; asserts a stable total on every page, no overlap, no missing transaction, deterministic same-second ordering, and repeatable page reads.
- `tests/Integration/Workflow/ManagementQueuePaginationTest.php::testSeniorQueueUsesTheSameUniqueScopedPagination` — overlapping Senior assignments and multiple managed centres; asserts unique totals, complete pages, and no leakage from an unassigned centre.
- `tests/Integration/Workflow/ManagementQueuePaginationTest.php::testManagerScopedSheetSummaryAgreesWithTheDeduplicatedQueue` — overlapping assignments; asserts the Batch 1 manager-scoped sheet summary and the queue expose the same two authorized transactions and totals, with the out-of-scope transaction absent from both.
- `tests/Integration/Workflow/ManagerSheetReviewTest.php` — existing Batch 1 manager-scoped sheet coverage re-run unchanged.

## Local Verification

- `scripts/dev/phpunit tests/Integration/Workflow/ManagementQueuePaginationTest.php tests/Integration/Workflow/ManagerSheetReviewTest.php` → OK (11 tests, 76 assertions).
- `scripts/dev/phpunit tests/Integration/Workflow/ManagementDecisionTest.php` → OK (11 tests, 31 assertions); confirms the `getUserRoles()` de-duplication did not alter decision authorization.
- `scripts/dev/phpunit tests/Contract/Controller/ManagementReviewControllerTest.php` → OK (20 tests, 211 assertions); exercises the live database query path and the queue/sheet contracts.
- `scripts/dev/phpunit tests/Contract/Controller/PurchaserFlowControllerTest.php --filter testQueueReturnsTransactionSummaryItems` → OK (1 test, 14 assertions).
- Live OCS probe against the lab as the original `pettycash-e2e` fixture: Cost Center queue `total=75`, `rows=75`, `unique=75`; pages of 25 returned `25/25/25` unique rows with `total=75` on every page and `75` unique across all pages. Senior queue `total=67`, `rows=67`, `unique=67`.
- Pre-fix evidence remains the assessment's live probe: `total=144`, `items=144`, `uniqueIds=72`.

## Deviations from Assessment

- The assessment proposed fetching each managed cost centre once and merging the results in the service. The fix instead adds one repository read over the de-duplicated cost-centre set and de-duplicates in the service as a defensive guard. This keeps the same correctness properties while avoiding N cost-centre queries; the service still applies ordering, de-duplication, total counting, and pagination after retrieval.
- The test double's `findByCostCenterId()` compared the revision's public code to the numeric join key and ignored its offset. That was not production code, but it would have hidden this defect from every queue test, so it was corrected to mirror the database query.
- `getUserRoles()` was de-duplicated as well as the cost-centre ID resolution. It only affects callers that use membership checks, not authorization semantics.

## Follow-ups

- The queue now reads the full pending scope before slicing in PHP. If a deployment grows large enough for that to matter, the same de-duplicated `IN` scope can be paired with a `COUNT` query and SQL pagination; correctness should not depend on that optimization.
- Frontend de-duplication in `useSheetQueue` can remain as a defensive measure, but it is no longer masking this server contract defect.
