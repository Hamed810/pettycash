# UI-PUR-011 — Delete a draft transaction (FR-025)

- **Priority:** P1 · **Status:** PROPOSED · **Size:** S · **Risk:** low
- **Baseline SHA:** `6006f28400e8962909afab0744460152353c12fe`
- **Audit links:** GAP-023 · **Backend impact:** NONE (the server path exists and is guarded)

## 1. User-visible outcome

Before the sheet is submitted, the purchaser can remove a transaction they created by mistake — from the transaction itself or from the sheet workspace's row menu — after a confirmation that names the transaction.

## 2. Current state (verified)

| Fact | Evidence |
|---|---|
| The requirement exists and is unconditional | FR-025: "Before submission, the purchaser MUST be able to add, edit, and delete transactions" — `spec.md:832-833` |
| The API client exists | `src/services/transactionsApi.ts:32-34` (`deleteTransaction(id, expectedVersion, options)`) |
| The store action exists and is exported | `src/stores/transactions.ts:117-130,215` (`deleteExistingTransaction`) |
| **No view or component calls either** | searches over `src/views`, `src/components`, `src/composables` for `deleteExistingTransaction(`/`deleteTransaction(` return nothing |
| A test currently pins the absence of a delete control | `tests/Frontend/Component/TransactionCorrection.spec.ts:735` |
| Server guards are correct and complete: owner, OPEN sheet, DRAFT transaction, stale version → 409 | `lib/Application/Expense/TransactionService.php:514-539` |
| Confirmation pattern already exists for the analogous sheet case | `src/components/shared/DeleteSheetDialog.vue:86-121` |

## 3. Authoritative references

- FR-025 `spec.md:832-841` — deletion before submission, and the 409/reload rule for concurrent edits.
- FR-026 `spec.md:846-847` — after submission the purchaser must not edit or remove transactions: **the affordance must disappear, not merely fail.**
- FR-088 `spec.md` — a save returns to the sheet detail; deletion should leave the purchaser in the same place with the same reachability.

## 4. Allowed scope / non-goals

**In scope**
- A delete affordance for DRAFT transactions only, reachable from the transaction's own screen and/or the workspace row menu (coordinate with `UI-PUR-006`/`UI-PUR-008` so there is one action surface per row, not three).
- A confirmation step that names the transaction (amount and date at minimum, description if present) — the sheet-deletion precedent at `spec.md:201` explains why a confirmation is required for irreversible actions, and a draft transaction's evidence attachments are part of the cascade.
- Wiring to the existing store action with the transaction's `expectedVersion`; a 409 is surfaced as a conflict with the reload path from `UI-PUR-012`, not as a silent failure.
- After deletion: return to the sheet detail (FR-088's reachability principle), with the sheet reloaded so the totals and transaction count are current.

**Non-goals**
- No deletion of submitted or in-review transactions (server refuses; the UI must not offer).
- No bulk delete, no multi-select, no undo/trash feature.
- No change to the deletion semantics or to the sheet-deletion flow.
- No new endpoint.
- Evidence handling: deleting the transaction deletes its evidence rows/files as the server already decides — do not add a client-side pre-cleanup.

## 5. Implementation behaviour

- Availability rule: status `DRAFT` and the caller is the owner (both are already `editable`-equivalent conditions in the form, `TransactionFormView.vue:71-77`).
- Confirmation copy must state that attachments are removed too if the server cascades them — verify the cascade in `TransactionService` before writing the sentence; if the cascade is not certain, do **not** claim it.
- The control must be keyboard reachable, labelled with the transaction's identity, and must not be the visually dominant action (destructive styling, secondary placement).
- Failure paths: 409 (stale) → conflict message + refetch; 403 → surfaced server message; network error → retryable message. No silent no-op in any case.
- After success the transaction list/sheet detail is refetched; if the deleted transaction was the sheet's only one, the sheet returns to the "no transactions" state and Submit is no longer offered (`src/stores/sheets.ts:14`).

## 6. Acceptance criteria

1. A DRAFT transaction exposes exactly one delete affordance; choosing it shows a confirmation naming the transaction.
2. Cancelling issues no request (negative case: request count 0).
3. Confirming issues exactly one `DELETE /api/v1/transactions/{id}` carrying the current `expectedVersion`, and the purchaser lands on the sheet detail with a refreshed list and totals.
4. A transaction in any non-DRAFT status offers no delete control (negative case, per status).
5. Deleting the sheet's last transaction leaves the sheet OPEN with zero transactions and no Submit affordance.
6. A 409 response leaves the transaction in place and shows a conflict message with a way to continue (shared with `UI-PUR-012`).
7. The existing assertion that no delete control exists (`TransactionCorrection.spec.ts:735`) is replaced with the status-matrix assertion above, and the change is called out in the completion report.
8. Keyboard-only deletion is possible.

## 7. Tests

| Level | Command | Assertion |
|---|---|---|
| Component | `npx vitest run tests/Frontend/Component/TransactionCorrection.spec.ts` | status matrix, cancel = no request, 409 path |
| Component | `npx vitest run tests/Frontend/Component/PurchaserFlow.spec.ts` | last-transaction case, return to sheet detail, refreshed totals |
| Contract (PHP, container) | `scripts/dev/phpunit tests/Integration/Expense/TransactionServiceUpdateTest.php` (or the existing deletion coverage) | the guards already hold — confirm no test changes are needed |
| E2E | `npm run test:e2e` (TO_VERIFY: lab) | create → delete → sheet state |
| Gate | `npm run check` | clean |

## 8. Dependencies / rollback

- **Depends on:** nothing. **Coordinated with:** `UI-PUR-006`/`UI-PUR-008` if the row menu is landed in the same batch (the delete item belongs in that menu).
- **Rollback:** remove the affordance; the store action and the server remain untouched, so a revert leaves the API usable.

## 9. Definition of Done

- [ ] Delete available for drafts only, with confirmation, correct version, correct landing.
- [ ] Status matrix tested; the old "no delete button" assertion replaced deliberately.
- [ ] Completion evidence includes the DELETE request log and the post-delete sheet state.

## 10. Approval gate

**PROPOSED.** Not approved. This is a requirement violation rather than a design preference, so it is a strong candidate for the first implementation batch — but it still needs owner approval like every other card, and the confirmation copy must be reviewed with the owner.
