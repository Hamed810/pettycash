# BE-CR-002 — Sortable, deterministic, OPEN-first ordering for the purchaser sheet list

- **Type:** backend contract + persistence change request · **Status:** **BLOCKED_PENDING_OWNER_APPROVAL**
- **Priority:** P1 · **Size:** M · **Risk:** medium (pagination semantics; contract change)
- **Baseline SHA:** `6006f28400e8962909afab0744460152353c12fe`
- **Audit links:** GAP-001, GAP-005 · **Frontend consumer:** `UI-PUR-003`

> **Change request, not an implementation ticket.** It adds a published parameter and changes the default ordering of a list endpoint that a paginated UI depends on.

## 1. Why it is needed

The accepted purchaser direction is: *OPEN (never-submitted) sheets always first, in either ascending or descending first-submission order, **across server pagination***. The requirement has **no anchor in `specs/`** — FR-063 lists fields, FR-064 lists filters, and neither states an ordering (verified: no `sort`, `order`, or `ordering` clause anywhere in `contracts/ocs-api.yaml`). The endpoint accepts only `status, ownerId, currency, from, to, page, pageSize` (`contracts/ocs-api.yaml:364-381`; handler `lib/Controller/SheetController.php:93-100`), and the query orders by a single key:

```
$qb->orderBy('s.created_at', 'DESC');        lib/Infrastructure/Persistence/Expense/DbExpenseSheetRepository.php:58
```

Offset pagination follows (`lib/Application/Expense/SheetService.php:141-149`; `setFirstResult/setMaxResults` at `:59-60`), which makes the single-key ordering additionally unsafe: two sheets sharing `created_at` can be duplicated or skipped across pages (GAP-005).

## 2. Requested change

1. **Add a documented `sort` parameter** to `GET /sheets`, with exactly the ordering this feature needs and no more. Recommended minimal shape: `sort=firstSubmitted:asc|firstSubmitted:desc` (or two values of a single `order` parameter — the owner/API owner chooses the spelling; what matters is that the contract states the vocabulary and the default).
2. **Define the ordering rule in the contract**, not only in the query:
   - OPEN (never-submitted) sheets sort before all others, in **both** directions;
   - within each group, by first-submission timestamp in the requested direction, with never-submitted sheets ordered among themselves by a deterministic key (creation timestamp);
   - a stable tie-breaker key (public id) terminates every comparison.
3. **Make the ordering deterministic** regardless of the new parameter: append a unique tie-breaker to the existing default so the current endpoint stops being able to duplicate/skip a row.
4. **Document the default** so existing callers (dashboard, reports, other clients) keep their behaviour unless they ask otherwise.

## 3. What this change request explicitly does not do

- It does **not** add a general "sort by any column" facility.
- It does **not** change filtering, page size, or the `ownerId` scope semantics (naming another user is a `403`, not a predicate — `SheetController.php:103-108`).
- It does **not** introduce cursor pagination; offset stays, which is why the tie-breaker matters.
- It does **not** implement a second endpoint for the "open sheets" region. If the owner prefers not to change the main list's default ordering, the alternative is a documented `status=OPEN` request with the same sort parameter, used by the region only — a smaller behaviour change to the default list, at the cost of one extra request per list view. **This is a genuine either/or for the owner and must be decided explicitly** (recorded as D-09).

## 4. Endpoint / contract / test impact

| Item | Impact |
|---|---|
| `GET /api/v1/sheets` | new parameter; documented ordering and default |
| `contracts/ocs-api.yaml:364-381` | parameter added; the operation's description gains the ordering rule; `npm run check:openapi` must pass |
| `src/generated/api.ts` | regenerate via `openapi:write` (never hand-edit) |
| `src/services/sheetsApi.ts`, `src/stores/sheets.ts` | pass and preserve the parameter (frontend, in `UI-PUR-003`) |
| New tests (contract) | unknown sort value → named `422` (or documented fallback — decide and pin it); both directions return the expected ordering |
| New tests (integration) | with ≥3 pages and OPEN sheets distributed across them: no page shows a submitted sheet above an unrendered OPEN sheet; every sheet appears exactly once across the pages; two sheets with identical `created_at` are never duplicated or skipped |
| Existing tests | `tests/Integration/Persistence/RepositoryRoundTripTest.php:997,1033,1059` (total/page size/pages reach every sheet once) must keep passing; `tests/Contract/Controller/SheetListFilterContractTest.php` gains the new cases |
| Performance | the ordering must be indexable; note the existing N+1 totals (GAP-008) separately — do not fix it here |

## 5. Open questions for the owner (must be answered before implementation)

1. **Yes/no:** is OPEN-first ordering the product requirement, given it exists only in an unmerged addendum (`U-05`) and needs a contract addition? (D-09)
2. **Direction default:** ascending (longest-waiting OPEN sheet first) or descending (most recent first)?
3. **Exposure:** `sort` on the main list with OPEN-first as the default, or an explicit `status=OPEN` + `sort` region request leaving the default list untouched?
4. **First-submitted ordering of never-submitted sheets:** the rule as written orders by *first submission*, which never-submitted sheets do not have. They must therefore have their own deterministic key (creation time) — confirm this is the intended reading, because "OPEN first, ordered by first submission" is literally undefined for the group it names.

## 6. Acceptance criteria (for the future implementation)

1. The contract documents the parameter's vocabulary, the default, and the OPEN-first rule in the operation description.
2. Ascending and descending both keep every OPEN sheet above every submitted sheet, demonstrated at ≥3 pages.
3. Every sheet appears exactly once across a full page walk; no duplicates, no omissions, including the identical-timestamp fixture.
4. An unknown parameter value is either refused with a named error or documented as a fallback — one of the two, tested.
5. Existing list behaviour for callers that do not send the parameter is either unchanged or explicitly documented as changed.
6. `npm run check:openapi` passes; generated types regenerated.
7. The refusal/fallback behaviour is exercised by a contract test, not only by the integration test.

## 7. Rollback

The parameter is additive; removing it restores today's ordering. The tie-breaker (item 3 of §2) is independently revertable and should be considered the highest-value half of this change request, since it fixes a latent correctness bug on its own.

## 8. Approval gate

**BLOCKED_PENDING_OWNER_APPROVAL.** Requires: (a) the D-09 product decision; (b) authorization for a published-contract change; (c) a default-ordering decision (question 3). `UI-PUR-003` must not be started before these, and must not be "approximated" with client-side sorting — the client only ever holds one page (`src/stores/sheets.ts:30`).

---

## Reconciliation update — owner clarifications applied (2026-10-19)

- **Status unchanged:** `BLOCKED_PENDING_OWNER_APPROVAL`. No approval exists (see the governance citations in `BLOCKED_ITEMS.md` §3.1).
- **Product question answered (`D-09`):** OPEN sheets must always appear first, submitted sheets follow the requested First Submitted direction, and the behaviour must hold **across pagination**. This makes the request required rather than speculative.
- **Still unanswered, and required before implementation:** how the **OPEN group** is ordered among itself (an open sheet carries no first-submission timestamp), and the tie-breaker when several sheets share a First Submitted instant. `D-09` does not settle either.
- `GAP-005` (pagination tie behaviour) remains `source-confirmed`, not runtime-reproduced; the eventual test needs a dataset with identical sort keys.
- Frontend consumer `UI-PUR-003` is blocked on this request and on those two answers.
