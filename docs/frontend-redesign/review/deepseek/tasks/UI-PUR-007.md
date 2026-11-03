# UI-PUR-007 — Edit the sheet's title inline while OPEN, without ever changing its account by accident

- **Priority:** P1 · **Status:** BLOCKED_BY_BACKEND — D-05 is resolved 2026-10-27: the account is immutable after creation and only the title is editable while OPEN; the `BE-CR-001` backend delivery (`T438`–`T441`) is complete (2026-10-28), so the remaining gate is separate implementation authorization for this card · **Size:** M · **Risk:** medium — this card fixes a live correctness defect
- **Baseline SHA:** `6006f28400e8962909afab0744460152353c12fe`
- **Audit links:** GAP-011 (frontend half), GAP-012 · **Backend impact:** **separately blocked — `BE-CR-001`** (see §8 for the frontend-only fallback)

## 1. User-visible outcome

While a sheet is OPEN the purchaser can rename it from the sheet itself. The source account and currency are immutable after creation and are displayed read-only. Saving a rename changes the name and nothing else — it never moves the sheet to a different account and never changes the currency its transactions are denominated in.

## 2. The defect being fixed (verified)

| Step | Evidence |
|---|---|
| The edit form fills only the title | `src/views/sheets/SheetFormView.vue:120-127` |
| Then it defaults the account to the caller's **first** account | `loadAccounts()` → `form.value.accountId = accounts.value[0]?.id` — `SheetFormView.vue:113-118` |
| The payload cannot supply the real account: `SheetSummary` has no account member, and the formatter drops it deliberately | `lib/Infrastructure/Http/SheetPayloadFormatter.php:22-24`; `contracts/ocs-api.yaml` `SheetSummary` (no `accountId`) |
| PATCH requires `accountId` | `lib/Controller/SheetController.php:147-158` |
| The server assigns it with no existence/ownership/active/currency validation | `lib/Application/Expense/SheetService.php:180-186` |
| The sheet stores account and currency as independent facts | `lib/Domain/Expense/ExpenseSheet.php:23-24`; both persisted at `DbExpenseSheetRepository.php:177-178,196-197` |
| The account's own money rules require a matching currency | `lib/Domain/Account/PettyCashAccount.php:53,73` |
| An unresolvable sheet currency is already treated as a data fault | `tests/Contract/Controller/DataIntegritySheetCurrencyTest.php:14-38` |

**Consequence:** a purchaser renaming a sheet can repoint it to their first account — possibly denominated differently — while the sheet keeps its original currency. There is no confirmation, no warning and no undo.

## 3. Authoritative references

- Accepted direction: the sheet's title is editable only while OPEN and never after submission (server enforces OPEN today: `SheetService.php:180-182`), preferably inline. The source account and currency are immutable after creation and displayed read-only (owner decision 2026-10-27, 001 `FR-019`/`FR-021`).
- FR-020 `spec.md:773-778` — account selection rules and the zero-account provisioning case (which is why `SheetCreate.accountId` is optional: `contracts/ocs-api.yaml:1400-1410`).
- FR-025 `spec.md:832-841` — pre-submission editability of the sheet's own fields, with the 409/reload rule.
- Design reference: the sheet workspace's edit affordance in `references/desktop/` (3-dot overflow per the accepted direction — coordinated with `UI-PUR-008`).

## 4. Allowed scope / non-goals

**In scope**
- Inline title editing in the OPEN sheet workspace (single-field edit with explicit save/cancel), submitting through the existing `PATCH /sheets/{sheetId}` with `expectedVersion`.
- **Remove the account substitution:** the form must never send an account, must display the sheet's stored account and currency read-only, and a title-only PATCH omits `accountId` entirely.
- Respect the 3-dot overflow placement from `UI-PUR-008` for entering edit mode, and hide the affordance entirely once the sheet is submitted (existing behaviour it must preserve: `SheetDetailView.vue:250-253`).
- A clear, non-destructive 409 conflict path: refetch the sheet, show the stored values, explain, let the purchaser retry (FR-025's reload rule; shares the mechanism with `UI-PUR-012`).

**Superseded decision (2026-10-27) — the owner chose immutability (Option A).** The two options below are retained as the pre-decision analysis. The current requirement is: the account is immutable after creation and displayed read-only; the inline editor offers the title only; a title-only PATCH omits `accountId`; any supplied `accountId` is refused before persistence. See 001 `FR-019`/`FR-021` and `D-026`.

**Two solutions were analysed before the decision (D-05):**
- **(a) Expose and prefill (needs `BE-CR-001`):** the sheet payload gains the sheet's account id; the form displays or prefills it; the server validates any assignment (ownership, active, currency compatibility). **Not adopted:** the account is not assignable.
- **(b) Account becomes immutable (needed a backend change; delivered by `T440`):** the inline editor offers the title only. The client cannot compute the sheet's account and the controller required `accountId`, so a title-only update had to be accepted and any supplied account refused — a backend change delivered by `T440`. **This is the adopted direction.**

**Non-goals**
- No currency editing: the sheet's currency derives from the account at creation (`SheetCreate`, FR-020) and is never a free field.
- No editing of owner, status, totals, submitted timestamp — none of them exist as sheet-update fields.
- No editing after submission (guarded server-side; the UI must not offer it).
- No inline editing of transactions (that is the transaction form's job).
- No new endpoint and no account-selection field. The only contract addition is the read-only `SheetDetail.accountId` projection delivered by the immutable-account slice; `SheetUpdate` remains title-only.

## 5. Implementation behaviour

- Single source of truth for the sheet's account: display the authoritative `SheetDetail.accountId` / stored account and currency read-only; never derive them from `accounts[0]`, the loaded account list, or list order.
- Supplied-account feedback: the edit form never offers or sends an account. If another client supplies `accountId`, surface the server's `422 VALIDATION_FAILED`/`fieldErrors.accountId` verbatim; do not invent client-side rules that contradict it.
- Zero-account purchasers (FR-020's provisioning case) must not be broken by the read-only edit display: creating a first sheet with no account still works (`SheetCreate.accountId` omitted), which is the existing tested behaviour.
- Optimistic locking: send `expectedVersion` (already required) and handle 409 as above.
- Localization: new labels catalogued; direction inherited (`UI-PUR-001`).
- The rename result must be visible in the list on return (`UI-PUR-004`'s refetch rule).

## 6. Acceptance criteria

1. Renaming a sheet whose account is **not** the caller's first account sends a PATCH body containing only `title` and `expectedVersion` and leaves the sheet on its own account — the body contains no `accountId` at all (not even `accounts[0].id`).
2. The response's sheet shows the new title; the list shows it after returning.
3. A 409 shows the stored values and a retry path; a retry with the new version succeeds.
4. Attempting to edit a submitted sheet offers no affordance and, if forced through the API, is refused by the server with the existing error (`Only open sheets can be updated`) — the UI must render that message as a conflict, not as a crash.
5. A purchaser with no accounts can still create a sheet (FR-020 regression guard).
6. No caller can move a sheet to any account, including one they own, that is active, and that uses the sheet currency: the server refuses any supplied `accountId` with `fieldErrors.accountId`, and the read-only display never guesses or substitutes.
7. Inline edit is keyboard-operable, announced, and cancel restores the previous value with no request issued (negative case: cancel must not PATCH).

## 7. Tests

| Level | Command | Assertion |
|---|---|---|
| Component | `npx vitest run tests/Frontend/Component/PurchaserFlow.spec.ts` | read-only account/currency display, title-only payload, cancel issues no request, 409 path, no affordance when submitted |
| Component | `npx vitest run tests/Frontend/Component/SheetDeletion.spec.ts` | unchanged destruction affordances (shared 3-dot menu from UI-PUR-008) |
| Contract (PHP, container) | `scripts/dev/phpunit tests/Contract/Controller/PurchaserFlowControllerTest.php` (+ the new supplied-account refusal case after `BE-CR-001`) | update returns 200 for a title-only body; refuses any supplied `accountId` with `fieldErrors.accountId` |
| Gate | `npm run check` | clean |

## 8. Dependencies / rollback

- **Depends on:** `BE-CR-001` backend delivery (001 `T438`–`T441`, all delivered and verified 2026-10-28) plus implementation authorization for this card; `UI-PUR-005` and `UI-PUR-008` for placement.
- **If `BE-CR-001` is declined:** no safe substitute exists. Do not ship a rename that still moves the account, and do not send a guessed account; leave the surface unimplemented and record the defect.
- **Rollback:** the change is local to the form and the editor; revert restores today's (defective) behaviour, which is why the defect should be recorded in the completion report.

## 9. Definition of Done

- [ ] The account substitution is impossible from the UI; the PATCH body contains only `title` and `expectedVersion`, and a test asserts `accountId` is never sent.
- [ ] The stored account and currency are displayed read-only and accurately, including when the account is inactive.
- [ ] Inline editing works for OPEN sheets only, keyboard-operable, cancel-safe.
- [ ] Completion report states what was delivered and what remains blocked.

## 10. Approval gate

**BLOCKED — separate implementation authorization for this card.** D-05 is answered: the account is immutable after creation, only the title is editable while OPEN, and any supplied `accountId` is refused. The `BE-CR-001` backend delivery is complete (001 `T438`–`T441`, `DONE_VERIFIED` 2026-10-28; `T041` closed), so the controller now accepts only a title-only PATCH. The remaining gate is explicit frontend implementation authorization for `T039`/`T080`. No implementation before that.

---

## Reconciliation update — owner clarifications applied (2026-10-19; D-05 superseded 2026-10-27)

- **Current status:** `BLOCKED_BY_BACKEND` (was `BLOCKED_OWNER_DECISION`). The product question is answered; the blocker is now separate frontend implementation authorization after the completed `BE-CR-001` delivery.
- **D-05 resolved (2026-10-27, Option A):** the **title only** is editable while the sheet is OPEN; the source account and currency are immutable after creation and displayed read-only, using the approved inline-editing interaction — not a separate Edit Sheet workflow. The 2026-10-19 "title and account editable" reading is superseded.
- **Why it was blocked at that date — no contractually-correct frontend-only path existed:**
  - the controller **required** `accountId` on update and refused its absence with a field-level 422 (`lib/Controller/SheetController.php:147-158`);
  - the contract required only `title` and states `accountId` "cannot be required" (`specs/001-pettycash-v1-core/contracts/ocs-api.yaml:1396-1410`), so even a title-only PATCH was implementation-refused;
  - the sheet's account appeared in **no** response (`SheetPayloadFormatter.php:22-24` dropped it), so even a read-only display could not be rendered;
  - substituting `accounts[0]` is exactly `GAP-011` (silent repointing, no currency reconciliation).
- **Answers recorded 2026-10-27:** there is no differently-denominated account adoption (currency immutable) and no target-account permission reading (no account is supplied); the stored account is preserved and displayed read-only. See `BLOCKED_ITEMS.md` §3.3.
