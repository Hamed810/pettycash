# UI-PUR-014 — Stage summary inside the sheet workspace, fed only by server figures

- **Priority:** P2 · **Status:** BLOCKED_BY_BACKEND — D-06 is resolved (counts **and** amounts per stage, from actual decisions); `BE-CR-003` is now required, not conditional · **Size:** M · **Risk:** medium (a card that shows the wrong number is worse than no card)
- **Baseline SHA:** `6006f28400e8962909afab0744460152353c12fe`
- **Audit links:** GAP-018, GAP-020, GAP-047 (constraint), GAP-050 (constraint) · **Backend impact:** **required — `BE-CR-003`** (blocked pending owner approval)

## 1. User-visible outcome

Inside the sheet workspace the purchaser sees, at a glance, how their sheet is progressing: what is recorded, what is approved, and what each review stage has decided — with every number traceable to the server and no invented aggregate.

## 2. Current state (verified)

| Fact | Evidence |
|---|---|
| The detail view has a totals card and nothing stage-shaped | `src/views/sheets/SheetDetailView.vue:355-390` |
| Per-stage **amounts** exist for approvals only | `lib/Domain/Expense/SheetTotals.php:30-32`; surfaced as `costCenterApproved`, `seniorApproved`, `financeApproved` — `lib/Infrastructure/Http/SheetPayloadFormatter.php:246-261` |
| Headline figures and their exact semantics | `current` (all active revisions), `effectiveApproved/Rejected/Pending` (effective outcome, with a return staying pending) — `SheetTotals.php:38-84`; pinned by `tests/Unit/Domain/Expense/SheetTotalsTest.php:13-86` |
| Per-stage **counts** and per-stage rejected/pending/returned: NOT_FOUND | searches across `lib/` and `src/` |
| A client cannot reconstruct stage history from the sheet payload | `TransactionSummary` carries one `latestDecision` per transaction (`contracts/ocs-api.yaml`, `TransactionSummary`) |
| Stage populations exist only as dashboard queue counts | `lib/Controller/DashboardController.php:220-222` |
| No rolled-over or cross-sheet totals anywhere | verified; this is a **constraint**, not a gap |
| The prototype's stage buckets fold `returned` into `pending` and sum floats | `prototype/manus-purchaser-v1/app.js:145-150,139-142` |

## 3. Authoritative references

- Owner rule: "Stage-specific workflow statistics must show stage-specific approved/rejected/pending/returned decisions; do not invent rolled-over totals or confuse them with global sheet totals."
- FR-062 `spec.md:1046-1048` — the latest-effective outcome and the three stage-approved figures as separate figures (the spec's own statement of why they must not be merged).
- `contracts/workflow.md` — what each stage's decision means.
- Design reference: the workspace summary/stage cards in `references/desktop/`.

## 4. Allowed scope / non-goals

**In scope** — one of two shapes, chosen by the owner (D-06):

- **Shape 1 — available today, no backend change:** the card shows exactly the server's figures: Recorded (`totals.current`), Submitted, Latest Approved/Rejected/Pending (effective), and the three stage-approved amounts, each labelled with its stage. Per-stage *pending* is shown only as the transaction count still awaiting that stage **if** it can be derived honestly from each transaction's `stage` field (currently outstanding stage), clearly labelled as "awaiting", never as a decision count.
- **Shape 2 — needs `BE-CR-003`:** the card additionally shows per-stage approved/rejected/pending/returned **counts and amounts**, sourced from a server aggregation. Only implementable after the backend change is authorized.

**Non-goals**
- No rolled-over or cross-sheet totals; no "all sheets" summary on this surface.
- No merging of the latest-effective figure into a stage figure (the exact confusion FR-062 exists to prevent).
- No client-side summing of money that the server already sums (the `current`/`effective*` figures are authoritative).
- No `returned` counted as `pending` in a stage bucket (the prototype's simplification must not be ported) — a returned transaction is its own state, and if the available data cannot express it, the card must omit that bucket rather than mislabel it.
- No status derivation of the sheet itself: the card shows what the transaction data says; the sheet-level status display is `UI-PUR-005` and its correctness depends on `BE-CR-005`.
- No float arithmetic anywhere: all amounts come from the payload's minor units.

## 5. Implementation behaviour

- Every figure on the card is either a server field name verbatim or a count of transactions in a state the server reported; the completion report must list, per figure, the exact field it renders. An unattributable figure is a defect.
- Where a bucket cannot be sourced, the card omits it and says why (an empty bucket labelled "0" is a lie when the data does not exist).
- Loading/error/empty states use the shared idiom (`UI-PUR-002`).
- A sheet with zero transactions renders a genuine empty state.
- Amounts use the shared money helper with the sheet's own currency; dates (if shown) use the shared date helper.
- Theming/mobile: the grid collapses to one column at the project's narrow breakpoints (`src/styles/_responsive.scss`).

## 6. Acceptance criteria

1. Every figure on the card maps to a named server field or a documented count of server-reported states; the mapping is in the completion report.
2. With a fixture containing approved, rejected, pending and returned transactions, the card's numbers match a hand-computed expectation, and the returned transaction is **not** counted as pending anywhere on the card (negative case — asserted by name).
3. Recorded equals `totals.current` and Latest Approved equals `totals.effectiveApproved`, asserted with a fixture where the two differ.
4. No cross-sheet or rolled-over figure exists (negative assertion: the card renders no figure that spans sheets).
5. A sheet with no transactions renders the empty state, not zeros.
6. At 390px the card is readable with no horizontal scroll.
7. If Shape 2 is chosen and `BE-CR-003` is not yet authorized, the task is not started — recorded as blocked, not partially implemented with client-side guesses.

## 7. Tests

| Level | Command | Assertion |
|---|---|---|
| Unit | `npx vitest run tests/Frontend/Component/PurchaserFlow.spec.ts` | criteria 2, 3 with a four-state fixture |
| Component | same | empty state, 390px, no rolled-over figure |
| Unit (PHP, container) | `scripts/dev/phpunit tests/Unit/Domain/Expense/SheetTotalsTest.php` | the source semantics are unchanged |
| Gate | `npm run check` | clean |

## 8. Dependencies / rollback

- **Depends on:** `UI-PUR-005` (the workspace shell). **Blocked by:** D-06 if counts are wanted; then `BE-CR-003`.
- **Rollback:** remove the card; the totals card returns to being the only summary.

## 9. Definition of Done

- [ ] Card implemented in the chosen shape with a per-figure provenance list.
- [ ] Four-state fixture test passes, including the returned-is-not-pending negative case.
- [ ] If Shape 2: `BE-CR-003` authorized first, and the card consumes the new fields only.

## 10. Approval gate

**BLOCKED_OWNER_DECISION (D-06).** The owner must decide whether stage cards show counts (needing a backend aggregation) or only the figures the system already publishes. Implementing either shape without that decision risks presenting a derived number as a decided one.

---

## Reconciliation update — owner clarifications applied (2026-10-19)

- **Current status:** `BLOCKED_BY_BACKEND` (was `BLOCKED_OWNER_DECISION`). `BE-CR-003` is now **required**, not conditional.
- **D-06 resolved:** the stage statistics must be based on actual transaction decisions at each stage and must show **counts and amounts** for Approved, Rejected, Pending and (when applicable) Returned. Final finance totals are **not** an acceptable substitute.
- **De-risking evidence:** the data already exists — `SheetSummaryQuery::rowsForSheet()` selects `r.amount_minor`, `d.stage`, `d.decision` per active revision (`lib/Infrastructure/Persistence/Expense/SheetSummaryQuery.php:173-181`) and reduces them into manager/senior/finance slots (`:210-215`). The change is the aggregate plus its payload, not new persistence.
- **Before approval:** `REC-03`'s semantics must be stated in the contract — the row set covers **active** revisions only, so a Return superseded by a correction revision no longer counts as returned, and a resubmitted transaction re-enters as Pending at Cost Center.
- Deriving the counts client-side is not acceptable (it would re-implement a domain rule in the presentation layer).
