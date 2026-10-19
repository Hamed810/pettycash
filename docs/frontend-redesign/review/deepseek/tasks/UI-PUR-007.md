# UI-PUR-007 — Edit the sheet's title inline while OPEN, without ever changing its account by accident

- **Priority:** P1 · **Status:** BLOCKED_BY_BACKEND — D-05 is resolved (title and account are editable while OPEN, subject to real permissions); blocking is now `BE-CR-001` alone · **Size:** M · **Risk:** medium — this card fixes a live correctness defect
- **Baseline SHA:** `6006f28400e8962909afab0744460152353c12fe`
- **Audit links:** GAP-011 (frontend half), GAP-012 · **Backend impact:** **separately blocked — `BE-CR-001`** (see §8 for the frontend-only fallback)

## 1. User-visible outcome

While a sheet is OPEN the purchaser can rename it (and, if the backend exposes the value, change its account) from the sheet itself. Saving a rename changes the name and nothing else — it never moves the sheet to a different account and never changes the currency its transactions are denominated in.

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

- Accepted direction: sheet title/account are editable only while OPEN and never after submission (server enforces OPEN today: `SheetService.php:180-182`), preferably inline.
- FR-020 `spec.md:773-778` — account selection rules and the zero-account provisioning case (which is why `SheetCreate.accountId` is optional: `contracts/ocs-api.yaml:1400-1410`).
- FR-025 `spec.md:832-841` — pre-submission editability of the sheet's own fields, with the 409/reload rule.
- Design reference: the sheet workspace's edit affordance in `references/desktop/` (3-dot overflow per the accepted direction — coordinated with `UI-PUR-008`).

## 4. Allowed scope / non-goals

**In scope**
- Inline title editing in the OPEN sheet workspace (single-field edit with explicit save/cancel, or an edit mode that includes the account), submitting through the existing `PATCH /sheets/{sheetId}` with `expectedVersion`.
- **Fix the account substitution:** the form must send the sheet's own account, never a defaulted first account.
- Respect the 3-dot overflow placement from `UI-PUR-008` for entering edit mode, and hide the affordance entirely once the sheet is submitted (existing behaviour it must preserve: `SheetDetailView.vue:250-253`).
- A clear, non-destructive 409 conflict path: refetch the sheet, show the stored values, explain, let the purchaser retry (FR-025's reload rule; shares the mechanism with `UI-PUR-012`).

**Two admissible solutions — the owner picks one (D-05):**
- **(a) Expose and prefill (needs `BE-CR-001`):** `SheetSummary`/`SheetDetail` gains the sheet's account id; the form prefills it; the server validates any assignment (ownership, active, currency compatibility).
- **(b) Account becomes immutable (frontend-only, no backend change):** the inline editor offers the title only. Practically, the client must still send `accountId` because the controller requires it, so the **sheet's current account id must reach the client somehow** — which is why (b) is only truly frontend-only if the controller is relaxed to accept a title-only update. Record this honestly: option (b) still needs a small backend accommodation (`accountId` optional on PATCH, or the sheet payload exposing it). The cheapest correct answer is therefore (a).

**Non-goals**
- No currency editing: the sheet's currency derives from the account at creation (`SheetCreate`, FR-020) and is never a free field.
- No editing of owner, status, totals, submitted timestamp — none of them exist as sheet-update fields.
- No editing after submission (guarded server-side; the UI must not offer it).
- No inline editing of transactions (that is the transaction form's job).
- No new endpoint, no new contract field beyond `accountId` in the sheet payload if (a) is chosen.

## 5. Implementation behaviour

- Single source of truth for the sheet's account: the prefill must come from the server payload, never from `accounts[0]`, and never from the loaded account list's ordering.
- Validation feedback: if the chosen account is not the sheet's own and the server refuses (post-`BE-CR-001`), surface the server's message verbatim; do not invent client-side rules that contradict it.
- Zero-account purchasers (FR-020's provisioning case) must not be broken by the prefill change: creating a first sheet with no account still works (`SheetCreate.accountId` omitted), which is the existing tested behaviour.
- Optimistic locking: send `expectedVersion` (already required) and handle 409 as above.
- Localization: new labels catalogued; direction inherited (`UI-PUR-001`).
- The rename result must be visible in the list on return (`UI-PUR-004`'s refetch rule).

## 6. Acceptance criteria

1. Renaming a sheet whose account is **not** the caller's first account leaves the sheet on its own account — asserted by inspecting the PATCH body (negative case: the body must not contain `accounts[0].id`).
2. The response's sheet shows the new title; the list shows it after returning.
3. A 409 shows the stored values and a retry path; a retry with the new version succeeds.
4. Attempting to edit a submitted sheet offers no affordance and, if forced through the API, is refused by the server with the existing error (`Only open sheets can be updated`) — the UI must render that message as a conflict, not as a crash.
5. A purchaser with no accounts can still create a sheet (FR-020 regression guard).
6. A sheet whose account the caller does not own cannot be moved to one (post-`BE-CR-001` server test; before it, the frontend must at minimum not change it).
7. Inline edit is keyboard-operable, announced, and cancel restores the previous value with no request issued (negative case: cancel must not PATCH).

## 7. Tests

| Level | Command | Assertion |
|---|---|---|
| Component | `npx vitest run tests/Frontend/Component/PurchaserFlow.spec.ts` | prefill fidelity, cancel issues no request, 409 path, no affordance when submitted |
| Component | `npx vitest run tests/Frontend/Component/SheetDeletion.spec.ts` | unchanged destruction affordances (shared 3-dot menu from UI-PUR-008) |
| Contract (PHP, container) | `scripts/dev/phpunit tests/Contract/Controller/PurchaserFlowControllerTest.php` (+ a new case for the account guard after `BE-CR-001`) | update returns 200 for the owner; refuses a foreign/inactive account |
| Gate | `npm run check` | clean |

## 8. Dependencies / rollback

- **Depends on:** `BE-CR-001` for option (a); `UI-PUR-005` and `UI-PUR-008` for placement.
- **If `BE-CR-001` is declined:** implement the *narrow* half only — stop the silent substitution by refusing to save a title-only edit rather than sending a guessed account, and tell the purchaser plainly that the account cannot be shown (a stop-gap, not a fix). Do not ship a rename that still moves the account.
- **Rollback:** the change is local to the form and the editor; revert restores today's (defective) behaviour, which is why the defect should be recorded in the completion report.

## 9. Definition of Done

- [ ] The account substitution is impossible from the UI; a test asserts the PATCH body carries the sheet's own account.
- [ ] Inline editing works for OPEN sheets only, keyboard-operable, cancel-safe.
- [ ] Completion report states explicitly whether option (a) or (b) was taken and what remains blocked.

## 10. Approval gate

**BLOCKED_OWNER_DECISION (D-05).** The product owner must choose between exposing the account on the sheet payload (correct, needs `BE-CR-001`) and freezing the account (cheaper, still needs a small backend accommodation). No implementation before that choice.

---

## Reconciliation update — owner clarifications applied (2026-10-19)

- **Current status:** `BLOCKED_BY_BACKEND` (was `BLOCKED_OWNER_DECISION`). The product question is answered; the blocker is now the unapproved `BE-CR-001`.
- **D-05 resolved:** the title **and** the account are editable while the sheet is OPEN, subject to real permissions, using the approved inline-editing interaction — not a separate Edit Sheet workflow.
- **Why it is still blocked — no contractually-correct frontend-only path exists:**
  - the controller **requires** `accountId` on update and refuses its absence with a field-level 422 (`lib/Controller/SheetController.php:147-158`);
  - the contract requires only `title` and states `accountId` "cannot be required" (`specs/001-pettycash-v1-core/contracts/ocs-api.yaml:1396-1410`), so even a title-only PATCH is implementation-refused;
  - the sheet's account appears in **no** response (`SheetPayloadFormatter.php:22-24` drops it), and several accounts may share a currency, so the client cannot compute it;
  - substituting `accounts[0]` is exactly `GAP-011` (silent repointing, no currency reconciliation).
- **Before approval:** the currency rule (adopting a differently-denominated account) and the permission reading must be answered — see `BLOCKED_ITEMS.md` §3.3.
