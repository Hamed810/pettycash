# Bug Assessment: Cost Center queue returns each transaction twice

- **Slug**: cost-center-queue-duplicate-transactions
- **Created**: 2026-10-30
- **Source**: pasted report plus live OCS response inspection in the pettycash lab
- **Verdict**: valid
- **Severity**: high

**Severity rationale**: the manager's legacy transaction queue reports 144 pending rows for 72
unique transactions and a pending total that is twice the real amount. The sheet view hides the
duplication by de-duplicating client-side, but the transaction fallback, its pagination, and any
consumer that trusts `total` still see the inflated set. No data corruption or duplicate decision
was observed.

## Report (summarized)

The previous visual review showed repeated transaction identifiers in the Cost Center Manager's
legacy transaction queue. The report asked whether the duplication comes from the backend
response, frontend rendering, pagination, data transformation, or legitimate multiple review
assignments.

No URL was supplied, so the URL trust policy did not apply and nothing was fetched.

## Observed evidence

Browser probe against the live lab (`http://localhost:8081`), authenticated as
`pettycash-e2e`, requesting:

```text
GET /ocs/v2.php/apps/pettycash/api/v1/me
GET /ocs/v2.php/apps/pettycash/api/v1/queues/cost-center?page=1&pageSize=500
```

Session context:

```json
{
  "roles": ["PURCHASER", "COST_CENTER_MANAGER", "FINANCE", "SENIOR_MANAGER"],
  "costCenterCodes": ["DEV-T060", "E2E-CC-1"]
}
```

Queue response:

```json
{
  "total": 144,
  "items": 144,
  "uniqueIds": 72,
  "duplicateIdGroups": 72,
  "duplicateMultiplicities": [2],
  "uniqueSheets": 72,
  "duplicateSheetIds": 0,
  "duplicateCostCenters": 0
}
```

Every duplicate pair shares the same transaction ID, sheet ID, cost-centre code (`E2E-CC-1`),
stage (`COST_CENTER`), and status (`AWAITING_COST_CENTER_REVIEW`). This is not a case of one
sheet legitimately contributing two distinct transactions: the same row is returned twice. The
only cost centre with pending work in this fixture is `E2E-CC-1`; `DEV-T060` contributes none.

Pagination was probed separately because the transaction fallback requests 25 rows per page:

```json
{
  "page1": { "total": 50, "length": 25, "uniqueIds": 13 },
  "page2": { "total": 50, "length": 25, "uniqueIds": 13 },
  "overlapBetweenPages": 0,
  "all": { "total": 144, "length": 144, "uniqueIds": 72 }
}
```

The transaction view therefore advertises two pages (50 rows) and can reach only 26 unique
transactions across them, while 46 of the 72 unique pending transactions never appear. Each page
also contains duplicates internally (25 rows, 13 unique IDs). This is a consequence of the same
per-assignment duplication plus the offset being applied per cost centre before the global slice.

## Root cause

The duplication is produced by the backend response, not by Vue rendering.

1. `ManagementReviewService::getUserRoles()` (`lib/Application/Workflow/ManagementReviewService.php:582-597`)
   appends one role entry per active assignment. It does not de-duplicate roles.
2. `getCostCenterQueue()` (`:372-416`) iterates that role list. For **every** `CostCenterManager`
   entry it calls `findActiveByUserAndRole()`, which returns **all** active assignments for the
   user, and appends every managed cost-centre ID again.
3. With two active `CostCenterManager` assignments, the ID list becomes
   `[DEV-T060, E2E-CC-1, DEV-T060, E2E-CC-1]`.
4. The method then calls `findByCostCenterId()` once per list entry and merges the results
   (`:397-406`), so each cost centre's transactions are fetched twice.
5. `total` is `count($allTransactions)` after that merge (`:413`), and the global page slice is
   applied afterwards (`:412`). The response therefore advertises the duplicated count.

The same pattern exists for the Senior queue at `:425-...`; this assessment is scoped to the Cost
Center queue because that is where it was observed.

Pagination compounds the defect: the offset is applied **per cost centre** inside
`findByCostCenterId()` and again as a global `array_slice()`. A paginated transaction view can
therefore repeat or skip rows as well as double the count. The sheet view avoids double-counting
only because `useSheetQueue.dedupeTransactions()` removes repeated IDs before grouping; the
fallback transaction table renders the server response as received.

## Existing frontend behaviour

- The sheet view groups only a response it can prove complete
  (`useSheetQueue.checkSheetQueueCompleteness()`), then de-duplicates by transaction ID.
- The transaction fallback intentionally renders the server's rows unchanged; it is the surface
  that still shows 144 rows.
- No frontend transformation invents the duplicate: removing the client-side de-duplication would
  make the duplication visible in the sheet view too, which is why it is written down here rather
  than treated as a rendering quirk.

## Remediation proposal (not applied)

Backend authorization is required; no production PHP was changed during this assessment.

1. Resolve the manager's managed cost-centre IDs once, as a set, instead of once per role entry.
   Either de-duplicate `getUserRoles()` or collapse `$managedCostCenterIds` with
   `array_unique()` before querying.
2. Fetch each managed cost centre once and merge the result. De-duplicate merged transactions by
   ID as a defensive guard.
3. Apply the requested page/offset once, after the merge, so `total` and page contents agree.
4. Add a regression test with two active `CostCenterManager` assignments to the same or different
   cost centres, asserting that each transaction appears once and `total` equals the unique count.

The same review should cover `getSeniorQueue()` because it uses the same role-loop pattern.

## Impact

- The Cost Center transaction fallback shows twice the pending rows and twice the pending amount.
- With the fallback's 25-row page size, each page contains duplicates (25 rows, 13 unique IDs),
  only two pages are reachable, and 46 of the 72 unique pending transactions never appear.
- A manager can see the same transaction twice on one page or miss rows entirely between pages.
- The sheet view's manager-specific counts and amounts remain correct because it de-duplicates, but
  it is masking a server contract defect rather than fixing it.
- No unauthorized transaction is exposed; both copies are within the manager's existing scope.

## Verification performed

- Live OCS response: 144 rows, 72 unique IDs, 72 duplicate groups of size 2.
- Source inspection of `getUserRoles()`, `getCostCenterQueue()`, and the repository query path.
- No production code, API contract, permission, or financial rule was modified.
