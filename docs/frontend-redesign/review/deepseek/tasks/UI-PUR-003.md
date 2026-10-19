# UI-PUR-003 — Sheet list sorting: OPEN-first in either direction, correct across pages

- **Priority:** P1 · **Status:** BLOCKED_BY_BACKEND — D-09 is resolved (OPEN-first across pagination is required); the server half does not exist and `BE-CR-002` is unapproved. See the reconciliation update at the end of this card
- **Size:** M (frontend) + M (backend, separate) · **Risk:** medium (touches pagination semantics)
- **Baseline SHA:** `6006f28400e8962909afab0744460152353c12fe`
- **Audit links:** GAP-001, GAP-002, GAP-003 · **Backend impact:** **separately blocked — `BE-CR-002`**

## 1. User-visible outcome

The purchaser's OPEN (never-submitted) sheets always appear first, in whichever first-submission order the purchaser chooses, and they stay first while paging — not merely within the page that happens to be loaded.

## 2. Current state (verified)

| Fact | Evidence |
|---|---|
| `GET /sheets` accepts `status, ownerId, currency, from, to, page, pageSize` only | `specs/001-pettycash-v1-core/contracts/ocs-api.yaml:364-381`; handler `lib/Controller/SheetController.php:93-100` |
| No ordering clause exists anywhere in the contract | whole-document search for `sort|order by|ordering` in `contracts/ocs-api.yaml` returns nothing |
| Server orders by one key | `$qb->orderBy('s.created_at', 'DESC')` — `lib/Infrastructure/Persistence/Expense/DbExpenseSheetRepository.php:58` |
| Offset pagination | `offset = (page-1)*pageSize` — `lib/Application/Expense/SheetService.php:141-149`; `setFirstResult/setMaxResults` `DbExpenseSheetRepository.php:59-60` |
| Open region filters only the loaded page | `src/stores/sheets.ts:15,30`; `SheetListView.vue:521-536` |
| No sort control in the UI | `SheetListView.vue` (none); `PageSize` exists in the contract at `ocs-api.yaml:987` but is not exposed |

## 3. Authoritative references

- Accepted direction: "OPEN sheets always first in either ascending or descending first-submission date order across server pagination" — `handoff/design-notes/DECISION_ADDENDUM_DRAFT.md` (U-05, **unmerged**), restated in `handoff/manus/01_PROJECT_CONTRACT_EN.md`.
- **Not present in `specs/`**: FR-063 (`spec.md:1049-1057`) lists fields, FR-064 lists filters, and neither states an ordering. The list's ordering requirement therefore has **no Spec Kit anchor** — this is why `BE-CR-002` must carry a written contract addition rather than a "bug fix".
- `data-model.md:203` — `submitted_total_minor` is a first-submission snapshot; `firstSubmittedAt` is the timestamp this feature orders by (`SheetPayloadFormatter.php:76`).

## 4. Allowed scope / non-goals

**In scope (frontend)**
- A sort control with exactly two states: first-submission **ascending** / **descending**, defaulting per the owner's decision (recommend ascending, oldest first, so the longest-waiting OPEN sheet is at the top).
- Pass the chosen direction to the server (never sort a loaded page locally) and let the OPEN-first grouping come from the server.
- The "open sheets" region must be driven by a server result, not by `sheets.value.filter(...)`, so an OPEN sheet on page 3 appears in the region (GAP-002).
- Empty/loading/error states consistent with `UI-PUR-002`.

**Non-goals**
- No client-side sorting of the loaded page (that is precisely the defect).
- No new columns, no change to filters or page size (GAP-004 noted, not implemented).
- No change to the dashboard's ordering.
- No "sort by any column" feature — the accepted requirement is the two-direction first-submission order, and a general sort grid would be a new capability.
- No change to the delete/reload flow other than keeping the chosen sort applied after a delete (`src/stores/sheets.ts:117-123`).

## 5. Implementation behaviour

- Store: add `sortDirection: 'asc' | 'desc'` (default per D-09) to the list query state, sent on every `loadSheets` and preserved across page changes, filter changes and detail round-trips.
- The server is authoritative for membership: the client must never reorder rows within a page, and must never assume page 1 contains all OPEN sheets.
- The open-sheets region requests the OPEN set explicitly (e.g. via the server's ordering/filter support) with its own explicit page size and must not depend on the main table's current page.
- A filter change resets to page 1 (existing behaviour, `SheetListView.vue:97-104`) but must **not** reset the sort direction.
- Conflict/error: an unsupported direction value from a stale client must be tolerated (fall back to the server default) rather than producing an error page.

## 6. Acceptance criteria

1. With ≥3 pages of sheets and OPEN sheets distributed across pages 1–3, each page's first rows are OPEN sheets whenever OPEN sheets remain unrendered — i.e. no page contains a submitted sheet above an unrendered OPEN sheet.
2. Switching direction reverses the order of the OPEN group **and** of the submitted group, and the OPEN group stays above the submitted group in both directions.
3. Sorting survives paging, survives a filter apply (which resets the page but not the direction), and survives deleting a sheet.
4. With zero OPEN sheets the list is a plain first-submission-ordered list in the chosen direction — no empty region artefact, no duplicated row.
5. Negative case: a user who is not the owner never sees another purchaser's OPEN sheet in the region (the server's `ownerId` scope is not a predicate — `SheetController.php:103-108` returns 403 when another user is named; the UI must not send `ownerId` at all).
6. Deterministic pages: with two sheets created in the same second, paging never repeats or skips a row (this is the `BE-CR-002` tie-breaker assertion, exercised from the frontend test through the API).
7. `npm run check:openapi` passes after any contract change.

## 7. Tests

| Level | Command | Assertion |
|---|---|---|
| Component | `npx vitest run tests/Frontend/Component/PurchaserFlow.spec.ts` | direction control; both directions' requests carry the parameter; page changes preserve it; filter reset preserves it |
| Integration (PHP, container) | `scripts/dev/phpunit tests/Integration/Persistence/RepositoryRoundTripTest.php` + a new case | same total on every page (existing `:1033`), pages reach every sheet once (`:1059`), tie-breaker case |
| Contract (PHP, container) | `scripts/dev/phpunit tests/Contract/Controller/SheetListFilterContractTest.php` + new case | the new parameter's accepted values and refusal of unknown values |
| E2E | `npm run test:e2e` | page to the last page in descending order and confirm no OPEN sheet is stranded on a later page |
| Gate | `npm run check` (openapi + portability + typecheck + lint + stylelint + vitest + build + notices) | clean |

## 8. Dependencies / rollback

- **Depends on:** `BE-CR-002` (contract + query). Without it this task cannot meet criteria 1, 2, 5 or 6 — do not start it as a frontend-only change and call the result done.
- **Related:** `UI-PUR-004` (state preservation) shares the query-state object; land `UI-PUR-004` first if both are in the same batch to avoid a second refactor.
- **Rollback:** the sort direction defaults to the server's current effective order until the parameter is honoured, so a partial rollout degrades to today's behaviour rather than breaking.

## 9. Definition of Done

- [ ] `BE-CR-002` approved and merged (or the owner explicitly accepting a reduced form of this task).
- [ ] The OPEN region is server-driven and correct across pages.
- [ ] `npm run check` executed; contract test output pasted.
- [ ] Screenshots of both directions on a ≥3-page fixture for owner review.

## 10. Approval gate

**BLOCKED_OWNER_DECISION.** Two approvals are required: D-09 (is the OPEN-first ordering the product wants, given it has no Spec Kit anchor and needs a contract addition?) and the separate `BE-CR-002` authorization. This card must not be started on the strength of the risk-assessment clause "a narrowly scoped BE-CR-01 concept if necessary" alone — that clause authorizes a *candidate*, not an implementation.

---

## Reconciliation update — owner clarifications applied (2026-10-19)

- **Current status:** `BLOCKED_BY_BACKEND` (was `BLOCKED_OWNER_DECISION`). The product question is answered; the blocker is now the unapproved `BE-CR-002`.
- **D-09 resolved:** OPEN sheets must always appear first; submitted sheets follow the requested First Submitted direction; the behaviour **must hold across pagination**.
- **Why that still blocks:** `GET /sheets` is paged server-side and the contract has no ordering clause, so a client-side sort of the loaded page is not an acceptable substitute — it would make page boundaries lie (`GAP-005`).
- **Unblock condition:** `BE-CR-002` approved, **and** its intra-OPEN ordering question answered (an open sheet has no first-submission timestamp) plus a tie-breaker for equal submission instants.
- `GAP-005` remains `source-confirmed`, not runtime-reproduced; a live reproduction needs a dataset with identical sort keys.
