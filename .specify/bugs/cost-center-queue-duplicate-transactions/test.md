# Bug Verification: Cost Center and Senior queue duplication

- **Slug**: cost-center-queue-duplicate-transactions
- **Tested**: 2026-11-04
- **Assessment**: ./assessment.md
- **Fix**: ./fix.md
- **Result**: verified

## Summary

The queue no longer duplicates transaction rows. Post-fix live OCS reads return `total=75`, 75 rows, and 75 unique IDs for the Cost Center queue, with three pages of 25 unique rows and no overlap. The Senior queue returns `total=67`, 67 rows, and 67 unique IDs. Targeted integration tests reproduce the overlapping-assignment and multi-cost-centre fixture and assert unique totals, complete pagination, stable ordering, no leakage, Senior correctness, and agreement with the manager-scoped sheet summary.

## Checks Performed

| Check | Command / Action | Result | Notes |
|-------|------------------|--------|-------|
| Reproduction (post-fix) | Live OCS `GET /queues/cost-center?page=N&pageSize=25` as the original `pettycash-e2e` fixture | pass | `total=75`, 75 rows, 75 unique IDs; pages returned 25/25/25 unique rows over three pages, no overlap, no gap. |
| Reproduction (post-fix) | Live OCS `GET /queues/senior?page=N&pageSize=25` as the same fixture | pass | `total=67`, 67 rows, 67 unique IDs over three pages. |
| New / updated tests | `scripts/dev/phpunit tests/Integration/Workflow/ManagementQueuePaginationTest.php tests/Integration/Workflow/ManagerSheetReviewTest.php tests/Integration/Workflow/ManagementDecisionTest.php` | pass | 22 tests, 107 assertions. Includes overlapping assignments, multiple managed centres, pagination, stable order, Senior queue, sheet-summary compatibility, and decision authorization. |
| Database/query path and contracts | `scripts/dev/phpunit tests/Contract/Controller/ManagementReviewControllerTest.php` | pass | 20 tests, 211 assertions; exercises the live database `findByCostCenterIds()` query and queue/sheet contracts. |
| Queue contract shape | `scripts/dev/phpunit tests/Contract/Controller/PurchaserFlowControllerTest.php --filter testQueueReturnsTransactionSummaryItems` | pass | 1 test, 14 assertions. |
| Regression suite | Not run in full | skipped | Only affected-area suites were run, per the batch instruction not to run unrelated comprehensive suites. |
| Lint / type-check | Not run | not-run | This change is PHP backend-only; the affected PHP tests were executed through the required container wrapper. |

## Output Excerpts

```text
OK (22 tests, 107 assertions)
OK (20 tests, 211 assertions)
OK (1 test, 14 assertions)
```

Post-fix live queue probe:

```json
{"stage":"cost-center","total":75,"pages":4,"rows":75,"uniqueAcrossPages":75}
{"stage":"senior","total":67,"pages":3,"rows":67,"uniqueAcrossPages":67}
```

The fourth Cost Center page is the empty terminator after 75 rows; it is not an extra data page. The assessment's pre-fix live probe recorded `total=144`, `items=144`, `uniqueIds=72`, with `page1` and `page2` containing only 13 unique IDs each and 46 of 72 unique transactions unreachable.

## Residual Risks

- The queue reads the full pending scope for the manager before applying the page in PHP. This is correct and well within the current lab's scale, but a future high-volume deployment may want SQL `COUNT` plus SQL pagination over the same de-duplicated scope.
- The batch was verified against the existing lab fixture and in-memory integration fixtures. No schema migration was introduced and no production-data repair was attempted; the original defect produced duplicate reads, not duplicate rows or decisions.
- The frontend still de-duplicates sheet transactions defensively. That is now redundant for this server defect, not evidence of a remaining backend duplication.

## Recommendation

Close the bug — verified end-to-end. The backend now returns each authorized transaction once, counts the unique set, paginates after de-duplication with a deterministic order, and applies the same correction to the Senior queue without changing decision or authorization semantics.
