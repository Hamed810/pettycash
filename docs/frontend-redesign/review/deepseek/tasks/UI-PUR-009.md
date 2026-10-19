# UI-PUR-009 — Preselect the cost center from the last saved transaction in the sheet

- **Priority:** P1 · **Status:** PROPOSED · **Size:** S · **Risk:** low
- **Baseline SHA:** `6006f28400e8962909afab0744460152353c12fe`
- **Audit links:** GAP-024 · **Backend impact:** NONE

## 1. User-visible outcome

When the purchaser adds the next transaction to a sheet, the cost center is already filled with the one they used for the most recently **saved** transaction in that same sheet — as long as it is still valid — and they can change it. When no saved transaction exists, or the previous center is no longer available, the field is left blank rather than guessing.

## 2. Current state (verified)

| Fact | Evidence |
|---|---|
| The draft always starts with an empty center | `src/views/transactions/TransactionFormView.vue:26-32` (`costCenterId: ''`) |
| The edit path copies the center from the payload only | `TransactionFormView.vue:187-200` |
| No such tracking exists anywhere in the store, the API client or the server | searches over `src/stores/transactions.ts`, `src/services/transactionsApi.ts`, `lib/Application/Expense/TransactionService.php`, `lib/Infrastructure/Http/SheetPayloadFormatter.php` for `lastCreated|preselect|prefill|default.*cost` return 0 implementation hits |
| The concept exists only in the prototype | `prototype/manus-purchaser-v1/app.js:98` (`lastCreatedTransactionId`), used at `:282` |
| Cost center is optional on a draft and required at submission | `lib/Application/Expense/TransactionService.php:133-140` (optional at create); `lib/Application/Workflow/SubmitSheet.php:106-116` (required, and each must resolve) |
| Available centers come from configuration | `GET /api/v1/configuration/cost-centers` (`appinfo/routes.php`) |

## 3. Authoritative references

- Owner rule (accepted in conversation, unmerged addendum): "For a new transaction, preselect cost center from the most recently **created and saved** transaction within that same sheet, only if still valid in the current cost-center configuration. Never derive it from the last displayed/sorted row. Otherwise leave blank; user can override." — `handoff/design-notes/DECISION_ADDENDUM_DRAFT.md` (U-11).
- FR-022 `spec.md:794-795` — a draft's minimum information is date and amount, so the preselect must remain optional and never block saving.
- FR-023 `spec.md:796-797` — "exactly one cost center" is required at submission, not at draft time.
- FR-028 `spec.md:850-851` — a sheet may contain transactions for multiple cost centers (so a preselect is a convenience, never an invariant).

## 4. Allowed scope / non-goals

**In scope**
- Determine the source transaction as the most recently **created and saved** transaction in the sheet (by server ordering of creation/save time, not by the table's current sort, and not by the client's display order).
- Read its cost center from the sheet's transaction data (the sheet payload already carries each transaction's active revision, including `costCenter` — `lib/Infrastructure/Http/TransactionPayloadFormatter.php:156-171`), so **no new endpoint is required**.
- Validate against the loaded configuration: preselect only if the center is still present and active; otherwise leave blank. No silent substitution of a "closest" center.
- The user's own choice always wins: once the purchaser picks a center, nothing overrides it (including a later background refresh).
- Apply the same behaviour after a correction draft is created, since it is a new draft for the same sheet.

**Non-goals**
- No server-side default, no new "last used cost center" field in the contract, no per-user preference.
- No preselect on the **edit** path: an existing transaction shows its own value and must never be overwritten.
- No preselect of expense type (not part of the accepted rule) and no preselect of amount or date beyond what exists today.
- No change to the validation that requires a center at submission.

## 5. Implementation behaviour

- Source selection rule, precisely: take the sheet's transactions, restrict to those that exist server-side as saved transactions, order by their creation/save timestamp descending, take the first, and use its cost center. If the payload exposes no reliable timestamp, stop and record the blocker rather than approximating with array order — approximating is exactly the failure the owner's rule forbids.
- Validity check: the center must appear in the current configuration response; an inactive/deleted center yields a blank field, with no error shown (this is not a user-visible failure).
- Persistence of the choice: the field stays a normal form value; saving uses it; nothing is cached outside the form's lifetime unless the sheet payload itself provides it.
- Error/empty handling: if the configuration request fails, the field is blank and the form still saves a draft (FR-022) — a failed convenience must not block work.
- Localization/theming: no new visible copy beyond the existing field label; nothing to add.

## 6. Acceptance criteria

1. Save transaction A with center X, then open a new transaction in the same sheet → center X is preselected.
2. Save transaction B with center Y after A → a new transaction preselects Y (the most recent saved one, not the first).
3. Re-sort the sheet's transaction table (or reverse the list order) and then open a new transaction → the preselect is unchanged (negative case: display order must not influence it).
4. Deactivate center Y in configuration → a new transaction's center is blank (not X, not Y).
5. Open an existing transaction for editing → the field shows that transaction's own center, never a preselected one (negative case).
6. Change the preselected value and save → the saved transaction carries the purchaser's choice.
7. With the configuration request failing, a draft still saves with only date and amount (FR-022 regression guard).
8. A sheet with exactly one saved transaction preselects its center; a sheet with no saved transactions leaves the field blank.

## 7. Tests

| Level | Command | Assertion |
|---|---|---|
| Unit | add to `npx vitest run tests/Frontend/Component/PurchaserFlow.spec.ts` or a new spec | the source-selection rule in isolation: most-recently-saved, not first/last-displayed |
| Component | same suite | criteria 1–8 through the form |
| Gate | `npm run typecheck && npm run lint && npm run stylelint` | clean |

## 8. Dependencies / rollback

- **Depends on:** the transaction payload exposing a reliable creation/save ordering signal (verify first; today the sheet payload returns transactions with active revisions — check whether a timestamp usable for this exists, and raise it in the completion report if not).
- **Blocks:** nothing.
- **Rollback:** revert the form's initialisation logic; the field returns to blank.

## 9. Definition of Done

- [ ] Preselect works from a saved transaction, survives re-sorting, and degrades to blank when the center is unavailable.
- [ ] The ordering source is named in the completion report (which field, which endpoint) so a reviewer can check the "never from the displayed row" rule was honoured.
- [ ] Tests executed with pasted output.

## 10. Approval gate

**PROPOSED.** Not approved. The rule is owner-agreed but lives only in an unmerged addendum; the owner should confirm it (and the ordering source) before implementation, since a wrong ordering source would produce confidently wrong defaults.

---

## Reconciliation update — owner clarifications applied (2026-10-19)

- **Current status:** `READY_FOR_OWNER_APPROVAL` (the header's `PROPOSED` means exactly this; nothing here is approved).
- **Dependency resolved (`REC-05`):** the ordering source for "most recently created and saved transaction" is `TransactionSummary.activeRevision.createdAt` — every transaction save creates a new revision with `number + 1` (`lib/Application/Expense/TransactionService.php:330-332`), so this is a real last-save timestamp. **No backend change is needed.**
- **Do not** use the array order, the displayed order, or the expense date as the ordering source.
- Fallback when the sheet has no saved transaction must stay honest (existing default).
