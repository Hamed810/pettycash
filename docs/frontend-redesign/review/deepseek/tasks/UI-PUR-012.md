# UI-PUR-012 — The unsaved-changes guard (save / discard / stay), and the 409 reload rule

- **Priority:** P1 · **Status:** PROPOSED · **Size:** M · **Risk:** medium (touches every form and the router)
- **Baseline SHA:** `6006f28400e8962909afab0744460152353c12fe`
- **Audit links:** GAP-054, GAP-029, GAP-056 (explicit non-goal) · **Backend impact:** NONE

## 1. User-visible outcome

A purchaser who has typed something and then closes, presses Escape, or navigates back is asked what to do — save, discard, or stay — and only loses work deliberately. If a save is refused because someone else changed the record first, the screen reloads the stored values and explains, instead of leaving a stale editor.

## 2. Current state (verified)

| Fact | Evidence |
|---|---|
| No guard exists anywhere | searches over `src/` for `beforeunload`, `onBeforeRouteLeave`, `isDirty` return **0** hits; `src/router/index.ts` registers no guards |
| Cancel navigates immediately | `src/views/sheets/SheetFormView.vue:265`; `src/views/transactions/TransactionFormView.vue:411-413` |
| Escape only closes existing `NcDialog`s, with no dirty check | `src/components/shared/DeleteSheetDialog.vue:91`; `src/views/transactions/TransactionDetailView.vue:404-408` |
| Optimistic locking is correctly detected server-side | `lib/Application/Expense/SheetService.php:171-178`; `DeleteSheet.php:137-144`; `SubmitSheet.php:67-74`; `TransactionService.php:525-532` |
| The transaction edit path sends the version | `TransactionFormView.vue:204-208`; `src/services/transactionsApi.ts:23-30` |
| FR-025 requires the client to reload on 409 | `spec.md:832-841` — "Where a save carries a version that is no longer current, the application MUST refuse it with `409` … and the client MUST reload before saving again" |
| The owner-accepted dialog exists as a design reference | `docs/frontend-redesign/references/dialogs/unsaved-changes-en-desktop-v1.png` |
| The prototype implements the guard | `prototype/manus-purchaser-v1/app.js:275-279` (dialog + `allowSave`), `:301-305` (discard/save), `:354` (`beforeunload`), `:355` (`popstate`) |

## 3. Authoritative references

- Owner-accepted interaction: the Unsaved Changes dialog (save / discard / stay) — approval status is one of the open questions (D-01), and this card must not treat the reference as approved on its own authority.
- FR-025 `spec.md:832-841` — the concurrent-edit rule quoted above.
- FR-088 `spec.md` — where a save must leave the purchaser (the guarded flow must still end on the sheet detail).
- Design reference: `references/dialogs/unsaved-changes-en-desktop-v1.png`.

## 4. Allowed scope / non-goals

**In scope**
- A dirty-form guard on the two editable purchaser forms: the sheet form (`/sheets/create`, `/sheets/:id/edit`) and the transaction form (create/edit/correction).
- Three outcomes — **save**, **discard**, **stay** — where "save" is offered only when the form is actually savable; otherwise the offered actions are discard and stay (the prototype models this with `allowSave`, `app.js:277`).
- Cover **all** exit paths: explicit cancel/close, Escape, in-app navigation (router guard), and browser back/forward; plus `beforeunload` for a tab close/reload.
- The 409 recovery: on a conflict, refetch the record, present the stored values, explain what happened, and let the purchaser save again with the fresh version. Applies to the sheet form and the transaction form; the delete/submit flows keep their existing error surfacing but must not silently discard.
- Use the existing `NcDialog`-based pattern (`DeleteSheetDialog.vue` is the in-repo precedent for a focus-managed confirmation).

**Non-goals**
- No draft autosave, no localStorage recovery of unsaved edits (GAP-056 — a new capability, not this card).
- No change to `beforeunload` semantics for pages without forms.
- No change to the server's version checks or error codes.
- No field-level merge on conflict: FR-025 explicitly forbids field-merging ("MUST NOT be field-merged with one") — the only correct answer is reload-and-let-the-user-decide.
- No guard on the read-only views.

## 5. Implementation behaviour

- Dirty = any user-modified value differing from the loaded record, including a cleared field; an untouched form is clean and closes without a prompt (negative case).
- Save from the dialog: performs the same save as the form's own save button, including its validation and its FR-088 destination; if the save fails, the dialog closes and the error is shown on the form — the purchaser stays on the form, never navigates away.
- Discard: navigates and does not issue a request (assert the request count is 0).
- Stay: closes the dialog, keeps the form and its values, returns focus to a sensible control.
- `beforeunload`: registered only while dirty (a permanent handler trains users to dismiss it).
- Router guard: applies to intra-app navigation away from a dirty form; must not fire for same-form navigation (e.g. a validation-driven rerender).
- Nested case: the workspace overlay (`UI-PUR-005`) closing with a dirty inner form delegates to this dialog instead of closing.
- Accessibility: focus moves into the dialog, is contained, returns to the invoking control; Escape maps to **stay** (the safe choice) rather than to discard.
- Localization: all three action labels and the message are catalogued.

## 6. Acceptance criteria

1. Touching any field then pressing Cancel, Escape, back, or an in-app link produces the dialog; an untouched form does not (negative case).
2. "Stay" keeps every entered value intact and returns focus inside the form.
3. "Discard" navigates away and issues **no** save request.
4. "Save" from the dialog performs the same write as the form's save button, including the version, and lands on the sheet detail (FR-088).
5. "Save" is not offered for a form that cannot be saved (e.g. a draft missing its amount): assert the control is absent or disabled rather than failing on click (negative case).
6. Tab-closing/reloading a dirty form triggers the browser's confirmation (`beforeunload` registered only while dirty — assert the handler's presence and absence).
7. Conflict path: with a stale version, a save returns 409, the form refetches, shows the stored values and the new version, and a second save succeeds. Assert exactly one refetch and that no field merge occurs.
8. Escape during the guard maps to "stay".
9. Focus returns to the invoking control after every outcome.
10. The guard does not fire on read-only views, nor on a clean form.

## 7. Tests

| Level | Command | Assertion |
|---|---|---|
| Component | new spec + `npx vitest run tests/Frontend/Component/PurchaserFlow.spec.ts` | criteria 1–5, 7–9 through both forms |
| Unit | within the same spec | dirty computation (including cleared fields and a reverting edit → clean) |
| E2E | `npm run test:e2e` — extend `tests/E2E/keyboard-accessibility.spec.ts` (TO_VERIFY: lab) | back-button and tab-close journeys; the 409 journey with a seeded concurrent edit |
| Gate | `npm run check` | clean |

## 8. Dependencies / rollback

- **Depends on:** nothing hard. **Interacts with:** `UI-PUR-005` (overlay close path) and `UI-PUR-007` (inline sheet edit — dirty tracking must cover it).
- **Rollback:** remove the guard registration and the dialog; the forms behave as today.

## 9. Definition of Done

- [ ] All exit paths guarded on both forms; dialog accessible; 409 reload implemented.
- [ ] Test output pasted, including the negative cases (clean form, no-request discard).
- [ ] A short note recording that no field-merging was implemented (FR-025 compliance).

## 10. Approval gate

**PROPOSED.** Not approved. This card implements a dialog whose approval status is itself unresolved (D-01). The owner should confirm the dialog's approval and the "Escape = stay" choice before implementation.

---

## Reconciliation update — owner clarifications applied (2026-10-19)

- **Current status:** `READY_FOR_OWNER_APPROVAL` (the header's `PROPOSED` means exactly this; nothing here is approved).
- **D-01 resolved:** the Unsaved Changes design is **approved** (reference: `docs/frontend-redesign/references/dialogs/unsaved-changes-en-desktop-v1.png`) and is not awaiting redesign. This card was gated on that clarity and is now unblocked.
- **Test note (verified):** no existing spec references `beforeunload`, `onBeforeRouteLeave`, `isDirty`, or an unsaved-changes dialog anywhere under `tests/Frontend/`. This card brings its own coverage, including a keyboard-path assertion.
- Explicit non-goal preserved: no global "Save sheet".
