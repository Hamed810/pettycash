# UI-PUR-013 — Returned transactions must go through the correction entry point

- **Priority:** P2 · **Status:** PROPOSED · **Size:** S · **Risk:** low–medium (revision semantics)
- **Baseline SHA:** `6006f28400e8962909afab0744460152353c12fe`
- **Audit links:** GAP-028 · **Backend impact:** NONE (both endpoints already exist)

## 1. User-visible outcome

When a transaction is returned for correction, the purchaser's correction begins the way the domain defines it, so the revision history and the audit record show a correction rather than an ordinary edit — and the "create correction" request is well-formed.

## 2. Current state (verified)

| Fact | Evidence |
|---|---|
| The correction endpoint exists and is routed | `POST /api/v1/transactions/{transactionId}/correction` — `appinfo/routes.php:29` |
| Its server guards: version 409, status must be awaiting-correction 409, owner 403 | `lib/Application/Expense/CreateCorrectionDraft.php:50-57,67-69,76-78` |
| It clones the active revision as a working revision without activating it; stage/status unchanged | `CreateCorrectionDraft.php:84,106`; `lib/Domain/Expense/ExpenseTransaction.php:260-265` |
| The client sends **no** `expectedVersion` | `src/services/transactionsApi.ts:58-60` (`mutate(..., 'POST', {}, ...)`) |
| …while the controller requires it | `lib/Controller/TransactionController.php:261-267` |
| **Nothing calls the client function** | no `createCorrection` caller under `src/`; the UI's "Create correction" control routes to the edit form instead — `src/views/transactions/TransactionDetailView.vue:109` |
| The edit form's PATCH path also admits the awaiting-correction state and attaches a working revision | `lib/Application/Expense/TransactionService.php:309-312,426-431` |
| Resubmission is separate and correct: validates the full dataset, activates the revision, restarts at Cost Center | `lib/Application/Workflow/ResubmitCorrection.php:90-143`; `ExpenseTransaction.php:42-48,271-279` |

**Consequence:** the correction flow works today through PATCH, but the dedicated endpoint is dead code with a latent `422`. The two paths differ in how the working revision is created, which affects what the revision history (and therefore the workspace History tab) displays.

## 3. Authoritative references

- `specs/001-pettycash-v1-core/contracts/workflow.md:67-75` — the correction contract (return → correction revision → resubmit restarts at Cost Center).
- FR-047 / FR-044 — the correction and resubmission rules (returned transactions require corrections; after submission the purchaser cannot edit arbitrary fields).
- FR-051/FR-052 `spec.md:992-998` — every revision and event is retained, which is why "how the revision was created" is a user-visible fact and not an internal detail.

## 4. Allowed scope / non-goals

**In scope**
- Decide and implement **one** canonical path for starting a correction, and make the UI use it:
  - **Option A (recommended):** the returned transaction's "correct" action calls `POST /transactions/{id}/correction` with the current `expectedVersion`, receives the working revision, and opens the transaction form against it; resubmission stays on `POST /resubmit`.
  - **Option B:** retire the client function and the endpoint's use from the UI, and document that PATCH is the correction entry point.
- Either way, fix the client signature so a version is always sent (`createCorrection(transactionId, expectedVersion, options)`), or delete the function.
- Update the client type if Option A is taken (the endpoint returns the working revision — `contracts/ocs-api.yaml` correction operation).

**Non-goals**
- No change to the server's correction, resubmission or revision rules — both endpoints stay as they are.
- No change to how a correction is displayed beyond what follows from the canonical path.
- No change to the resubmit validation (the evidence over-requirement is `BE-CR-004`, a separate blocked item).
- No new endpoint, no new revision state.

## 5. Implementation behaviour

- The returned transaction's affordance is unambiguous: one "create correction" action, which either creates the working revision first (A) or enters the existing edit path (B) — with the choice recorded in this repository, not in a comment.
- `expectedVersion` is always sent; a 409 is handled by `UI-PUR-012`'s reload path.
- A 403/409 from the correction endpoint is surfaced verbatim (the server's messages name the state).
- The purchaser must not be able to start a second correction while one is open (the server refuses; the UI should not offer it).
- After the working revision exists, the form binds to it, and resubmission restarts review at Cost Center — already correct, must not regress (`tests/Frontend/Component/TransactionCorrection.spec.ts:279-715`).

## 6. Acceptance criteria

1. Exactly one code path starts a correction from the returned transaction; the other is removed or explicitly documented as unused with a reason.
2. Whichever path is chosen, the request that starts it carries a non-zero `expectedVersion` (assert the body).
3. A 409/403 is displayed to the purchaser rather than swallowed.
4. The full journey still works end to end: return → start correction → edit → resubmit → review restarts at Cost Center (existing assertions in `TransactionCorrection.spec.ts` pass unmodified).
5. The revision list after the correction shows the correction revision with its own number and the prior revision retained (assert both are present).
6. No change to the PATCH/revision semantics for ordinary drafts.

## 7. Tests

| Level | Command | Assertion |
|---|---|---|
| Component | `npx vitest run tests/Frontend/Component/TransactionCorrection.spec.ts` | criteria 1–5, journey unchanged |
| Contract (PHP, container) | `scripts/dev/phpunit tests/Contract/Controller/CorrectionControllerTest.php` | existing correction contract passes unchanged |
| Integration (PHP, container) | `scripts/dev/phpunit tests/Integration/Workflow/TransactionCorrectionTest.php` | revision creation unchanged |
| Gate | `npm run check` | clean |

## 8. Dependencies / rollback

- **Depends on:** nothing. **Interacts with:** `UI-PUR-012` (conflict handling), `UI-PUR-006` (history display).
- **Rollback:** revert to the current PATCH-driven path (Option B is the trivial revert).

## 9. Definition of Done

- [ ] One canonical correction entry point, implemented and documented in the repository (an ADR or a docblock at the call site, not a chat message).
- [ ] Version always sent; tests pin it.
- [ ] The correction journey's existing tests pass unchanged.

## 10. Approval gate

**PROPOSED.** Not approved. The owner should confirm Option A or B: this decides which server endpoint is the canonical correction entry point, which is a small architectural decision rather than a UI preference.

---

## Reconciliation update — owner clarifications applied (2026-10-19)

- **Current status:** `READY_FOR_OWNER_APPROVAL` (the header's `PROPOSED` means exactly this; nothing here is approved).
- **Canonical path resolved (`REC-04`):** keep the **existing PATCH-based correction flow** as the single path. It is already pinned by passing behaviour, and it produces fewer revisions than creating an explicit correction draft first.
- **Required action:** remove (or repair to the contract) the dead `POST /transactions/{id}/correction` client wrapper that omits `expectedVersion` and has no caller — that latent defect is `GAP-028`.
- **Do not** adopt `POST /transactions/{transactionId}/correction` as the entry point unilaterally: it would add an initial revision to the transaction's history. The alternative is recorded for the owner in `OWNER_DECISION_RECONCILIATION.md` §4/§7 and is not adopted.
- The pinning suites (`tests/Frontend/Component/TransactionCorrection.spec.ts`, `tests/E2E/transaction-correction.spec.ts`) must stay green: the revision sequence must not change.
