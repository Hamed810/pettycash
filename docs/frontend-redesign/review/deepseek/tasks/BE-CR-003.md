# BE-CR-003 — Per-stage decision counts and amounts for a sheet (required by D-06)

- **Type:** backend aggregation change request · **Status:** **BLOCKED_PENDING_OWNER_APPROVAL**
- **Priority:** P2 · **Size:** M · **Risk:** medium (a new derived aggregation invites the exact confusion FR-062 guards against)
- **Baseline SHA:** `6006f28400e8962909afab0744460152353c12fe`
- **Audit links:** GAP-020 · **Frontend consumer:** `UI-PUR-014` (Shape 2 only)

> **Conditional change request.** It exists only if the owner wants stage cards showing counts. If `UI-PUR-014` is implemented in Shape 1, this request is **withdrawn whole** and should be marked so rather than left open.

## 1. Why it is needed

The redesign's stage summary shows approved/rejected/pending/returned per review stage. The system publishes per-stage **approval amounts** only:

- `costCenterApproved`, `seniorApproved`, `financeApproved` — `lib/Domain/Expense/SheetTotals.php:30-32`, surfaced at `lib/Infrastructure/Http/SheetPayloadFormatter.php:246-261`.

Searches across `lib/` and `src/` find **no** per-stage counts and no per-stage rejected/pending/returned figures. The client cannot reconstruct them either: `SheetDetail.transactions[]` is `TransactionSummary`, which carries one `latestDecision` per transaction (`contracts/ocs-api.yaml`, `TransactionSummary`), so earlier-stage outcomes are not present in the payload. Stage populations exist only as dashboard queue counts (`lib/Controller/DashboardController.php:220-222`), which are instance-wide and not per sheet.

## 2. Requested change (only if wanted)

Extend the sheet payload with a per-stage decision summary for that sheet, containing, for each of the three decision stages (cost center, senior, finance):

- counts of approved / rejected / returned / pending **as decided at that stage**, and
- the corresponding amounts in the sheet's currency (minor units).

Two admissible shapes; the API owner picks:

- **A — additive members** on the existing `SheetTotals`: e.g. `stageCounts: { costCenter: {approved, rejected, returned, pending}, ... }`.
- **B — a separate `stageSummary` object** on `SheetSummary`, leaving `SheetTotals` untouched (recommended: it keeps the money semantics of `SheetTotals` — `current` and the effective figures — free of unrelated counting, which is the exact separation FR-062 argues for).

## 3. Rules the aggregation must obey (non-negotiable)

1. **Stage-specific, not rolled-up.** A decision recorded at the cost-center stage counts as a cost-center decision even if the same transaction was later decided by senior and finance. This is the same rule the existing stage-approved amounts already follow (`SheetTotals.php:38-84`, pinned by `tests/Unit/Domain/Expense/SheetTotalsTest.php:13-86` for the effective side).
2. **Returned is its own state.** A returned transaction must never be presented as pending. (The prototype folds it into pending — `docs/frontend-redesign/prototype/manus-purchaser-v1/app.js:148-150` — and must not be the reference.)
3. **Active revisions only**, matching how the current totals are computed (`lib/Infrastructure/Persistence/Expense/SheetSummaryQuery.php:173-227`, joining `t.active_revision_id`).
4. **Minor units, integer sums**, no floats.
5. **No cross-sheet totals.** The aggregation is per sheet; nothing in this change request authorizes instance-wide sums.
6. **The effective figures stay as they are.** `effectiveApproved/Rejected/Pending` keep their current meaning; the new counts must not be substituted for them.

## 4. Endpoint / contract / test impact

| Item | Impact |
|---|---|
| `GET /api/v1/sheets/{sheetId}` (and the list, if the owner wants the counts there — **recommended: detail only**, to avoid paying for it on every list row) | payload gains the counts |
| `contracts/ocs-api.yaml` | `SheetTotals` gains members (A) or `SheetSummary` gains a sibling object (B); `npm run check:openapi` must pass |
| `src/generated/api.ts` | regenerate via `openapi:write` |
| Query | extend `SheetSummaryQuery` — one additional grouped query for the sheet, not an N+1 per transaction |
| New tests (unit) | a fixture with a transaction approved at cost center, returned at senior, rejected at finance: assert each stage's counts independently, and that returned never appears as pending |
| New tests (contract) | the payload shape and the values for a multi-stage fixture; a mixed fixture where a rolled-up implementation would produce a different (wrong) number |
| Existing tests | `tests/Unit/Domain/Expense/SheetTotalsTest.php` must remain green unchanged |

## 5. Open questions for the owner (must be answered before implementation)

1. Are per-stage **counts** actually wanted, or is `UI-PUR-014` Shape 1 (existing figures, labelled by stage) sufficient? (D-06)
2. Detail-only or list too?
3. Should the counts include the "awaiting" population (transactions currently outstanding at that stage) — which is derivable from `stage` today — or only decided outcomes?

## 6. Acceptance criteria (for the future implementation)

1. For each stage, approved/rejected/returned/pending counts are independently correct on a fixture where the stages disagree.
2. A returned transaction appears in returned, never in pending — asserted by name.
3. Amounts match the counts × their revisions in minor units, with no float arithmetic anywhere.
4. Only active revisions are counted.
5. The existing `SheetTotals` semantics are unchanged (existing unit test passes unmodified).
6. No per-transaction query is issued per row: the aggregation is one grouped query per sheet (assert the query count or the shape of the query in the test).
7. The payload is documented in the contract with the stage vocabulary spelled out.

## 7. Rollback

Purely additive: removing the members restores the current payload. `UI-PUR-014` must therefore be implemented so that a missing summary degrades to Shape 1 rather than rendering zeros.

## 8. Approval gate

**BLOCKED_PENDING_OWNER_APPROVAL**, and **conditional on D-06**. If the owner chooses Shape 1, this document must be marked withdrawn in `PROPOSED_TASK_BACKLOG.md` rather than left in the backlog as an unfinished backend change.

---

## Reconciliation update — owner clarifications applied (2026-10-19)

- **Status unchanged:** `BLOCKED_PENDING_OWNER_APPROVAL`. No approval exists.
- **No longer conditional (`D-06` resolved):** the stage statistics must be based on actual transaction decisions at each stage and must show **counts and amounts** for Approved, Rejected, Pending and (when applicable) Returned. Final finance totals are **not** an acceptable substitute, so the frontend cannot satisfy the requirement from the currently published figures.
- **Scope of the change is small and evidence-backed:** `SheetSummaryQuery::rowsForSheet()` already selects `r.amount_minor`, `d.stage`, `d.decision` per active revision (`lib/Infrastructure/Persistence/Expense/SheetSummaryQuery.php:173-181`) and reduces them into manager/senior/finance slots (`:210-215`). The delta is the aggregate plus its payload — no new persistence, no new table.
- **Must be stated in the contract before approval (`REC-03`):** the row set covers **active** revisions only, so a Return that has since been superseded by a correction revision no longer counts as returned, and a resubmitted transaction re-enters as Pending at Cost Center.
- Frontend consumer `UI-PUR-014` is blocked on this request and on the `REC-03` confirmation.
