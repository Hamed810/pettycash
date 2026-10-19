# UI-PUR-010 — Evidence can only be attached to a transaction that exists

- **Priority:** P1 · **Status:** PROPOSED · **Size:** S · **Risk:** low
- **Baseline SHA:** `6006f28400e8962909afab0744460152353c12fe`
- **Audit links:** GAP-025 · **Backend impact:** NONE

## 1. User-visible outcome

On the "new transaction" screen the purchaser is told plainly that the transaction must be saved before a receipt can be attached, and sees no upload control that cannot work. On an existing draft the uploader is present and functional, exactly as today.

## 2. The defect being fixed (verified)

| Step | Evidence |
|---|---|
| The uploader renders whenever the form is editable | `src/views/transactions/TransactionFormView.vue:400-403` (`v-if="editable"`) |
| …and `editable` is **true** when creating | `TransactionFormView.vue:71-77` (`if (!isEditing.value) return true`) |
| The id it receives is the route param, defaulted to empty | `TransactionFormView.vue:24` (`route.params.id ?? ''`), passed at `:402` |
| The client then POSTs to an id-less path | `src/services/evidenceApi.ts:25` (`/transactions/${transactionId}/evidence`) |
| The server requires an existing transaction owned by the caller, in a DRAFT or awaiting-correction state | `lib/Controller/EvidenceController.php:118-130`; route nested under a transaction: `appinfo/routes.php:35` |
| The existing test only asserts the area renders | `tests/Frontend/Component/PurchaserFlow.spec.ts:1876-1879` |

**Consequence:** the create screen advertises an attach action that either errors or silently loses the file; the comment above the uploader (`FR-025 allows evidence to be added and removed before submission`, `:398-399`) is true of a **saved draft**, not of a transaction that does not exist yet.

## 3. Authoritative references

- FR-025 `spec.md:832-833` — before submission the purchaser can add/remove evidence, in the context of an existing draft transaction.
- FR-029 `spec.md:852-854` — each transaction supports multiple evidence files.
- FR-074 `spec.md:1187` — the 100 MB per-file ceiling (unchanged; see GAP-033 for the verified implementation).
- The server's own rule (an upload requires an existing transaction) is the authority: `EvidenceController.php:118-130`.

## 4. Allowed scope / non-goals

**In scope**
- On the **create** route, replace the uploader with a clear, catalogued hint that evidence is attached after saving (the prototype already words this: "Save the transaction first; evidence can be added … after saving", `app.js:244`) and, ideally, keep the save flow pointed at the sheet detail where the transaction can then be opened.
- On the **edit** route, keep the uploader exactly as it is (it works; verified server contract).
- Make the uploader's transaction-id requirement impossible to satisfy with an empty string: type/validate the prop so an empty id cannot reach the request builder (fail closed, not with a malformed URL).
- Update the misleading comment above the uploader so it does not re-justify the wrong placement.

**Non-goals**
- No change to the upload transport, size ceiling, MIME policy or evidence listing.
- No client-side upload queue, no optimistic attachment, no "pending upload" state.
- No server change: the server's rule is correct.
- No new evidence states or lifecycle.

## 5. Implementation behaviour

- The create screen shows: the transaction fields, the save controls, and a hint that evidence follows the save. No file input, no attach button, no error path that can be triggered by the user.
- The edit screen shows the uploader for DRAFT and awaiting-correction transactions only (unchanged `editable` semantics).
- Guarding: if the uploader's component receives an empty transaction id, it renders a disabled state rather than issuing a request (defence in depth against a future re-introduction of this bug).
- Error handling unchanged: a too-large file (413) and a rejected state (409/403) keep their existing messages.
- Localization: the new hint is a catalog key; no literal text.
- The change must not alter the draft-save path: saving a draft still requires only date and amount (FR-022), and the purchaser still lands on the sheet detail (FR-088, `TransactionFormView.vue:212-216`).

## 6. Acceptance criteria

1. On `/sheets/:sheetId/transactions/create` there is no file input and no attach control; a catalogued hint explains that evidence is added after saving.
2. On `/transactions/:id/edit` for a DRAFT the uploader is present and a file selection issues exactly one `POST /api/v1/transactions/{id}/evidence` with a **non-empty** id (assert the URL).
3. Negative case: no request whose path contains `//` is ever issued from either screen (assert across the whole create-and-save journey).
4. The uploader given an empty id renders disabled and issues no request.
5. A 413 still names the 100 MB limit.
6. Saving a draft from the create screen still requires only date and amount and still ends on the sheet detail (FR-022 + FR-088 regression guard).
7. The existing assertion that the evidence area renders (`PurchaserFlow.spec.ts:1876-1879`) is replaced by the two route-specific assertions above, with the change called out.

## 7. Tests

| Level | Command | Assertion |
|---|---|---|
| Component | `npx vitest run tests/Frontend/Component/PurchaserFlow.spec.ts` | criteria 1, 4, 6; the updated evidence-area assertions |
| Component | `npx vitest run tests/Frontend/Component/AuditEvidence.spec.ts` | evidence attach on an existing draft unaffected |
| E2E | `npm run test:e2e` — extend `tests/E2E/audit-evidence.spec.ts` (TO_VERIFY: lab) | create → save → edit → attach → verify |
| Gate | `npm run typecheck && npm run lint` | clean |

## 8. Dependencies / rollback

- **Depends on:** nothing.
- **Rollback:** revert the form template region; no server or store change involved.

## 9. Definition of Done

- [ ] Create screen has no dead upload control and says why.
- [ ] Edit screen unchanged functionally; one extra defensive disabled state.
- [ ] No `//` request can be produced (test-pinned).
- [ ] Completion evidence includes the request log for one attach journey.

## 10. Approval gate

**PROPOSED.** Not approved. Small and self-contained, but it changes what the create screen shows, so the owner should confirm the wording of the hint (a one-line copy decision) during batch review.
