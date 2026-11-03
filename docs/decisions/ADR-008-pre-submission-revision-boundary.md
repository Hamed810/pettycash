# ADR-008: Pre-submission revision and audit boundary

- **Status**: Proposed — **not approved, not implemented**.
- **Date**: 2026-10-24
- **Decides**: Whether a transaction that has never been submitted may accumulate official
  revisions and transaction-audit events before its first submission.
- **Traces to**: 001 FR-025, FR-048, FR-051, FR-053; 005 FR-005-043; 001
  `data-model.md` “Transaction Revision”; 001 `contracts/workflow.md` “Command Contract”.
- **Gated implementation**: 001 `T437` (unchecked). No migration, backfill, or deletion is
  proposed.

## Context

FR-051 says formal transaction audit **begins with sheet submission**. The 2026-10-08 FR-025
clarification says a draft edit carries “no official history, no revision”. The data model says
“Revision 1 may remain mutable only while its transaction is a draft in an open sheet” and “A
submitted revision is immutable” (`specs/001-pettycash-v1-core/data-model.md:264-265`). Those
statements describe one mutable working revision before submission, not a growing revision list.

The current implementation does something different:

1. `TransactionService::createTransaction()` creates revision 1 and immediately writes a
   `REVISION_CREATED` audit event at `WorkflowStage::Draft`
   (`lib/Application/Expense/TransactionService.php:188-212`).
2. `TransactionService::updateTransaction()` builds a **new** revision on every draft `PATCH`,
   with `revision_number = previous + 1`
   (`lib/Application/Expense/TransactionService.php:330-332`), then writes a
   `TRANSACTION_CHANGED` audit event at `WorkflowStage::Draft`
   (`lib/Application/Expense/TransactionService.php:442-475`).
3. `TransactionDetailView.vue` renders a revision selector, every revision as a tab, and a
   “prior revisions” section for any transaction, including one that has never been submitted
   (`src/views/transactions/TransactionDetailView.vue:55-58,270-350`).
4. `AuditEventType::RevisionCreated` is documented as “A new revision, on first submission or on
   resubmission” (`lib/Domain/Shared/Enum/AuditEventType.php:72-73`), but its producer runs at
   draft creation instead.

The purchaser therefore sees “Revision 1, 2, 3…” and a draft-stage audit stream for a record that
has no official history yet. A draft is working data: no reviewer has seen it, no decision exists,
and an abandoned draft has nothing to reconstruct. `lock_version` already protects a concurrent
edit under FR-025; official revisions and audit events are not needed to protect it.

## Why this is an ADR rather than a bug fix

Changing the producer is not a presentation-only change. It changes all of the following:

1. **Stored rows.** A never-submitted draft would have one revision row instead of one per save.
2. **API payload.** `GET /transactions/{id}/revisions` would return one revision for a new draft
   instead of the edit count.
3. **Audit stream.** New drafts would produce no `REVISION_CREATED` or `TRANSACTION_CHANGED`
   events before submission; the first official event would be `SUBMITTED`.
4. **Submission.** `ExpenseSheet::submit()` currently activates `$tx->revision()`
   (`lib/Domain/Expense/ExpenseSheet.php:110-113`); under the proposal that working revision
   becomes revision 1 at submission, so the submission transaction must also record its
   activation in the formal history.
5. **Existing data.** FR-053 makes business audit rows append-only. Any draft-stage revisions and
   audit events already stored cannot be deleted, rewritten, or backfilled. A cutoff rule is
   required.
6. **Tests and fixtures.** `AuditEventCoverageTest` and related suites currently assert
   `REVISION_CREATED` and `TRANSACTION_CHANGED` at draft stage. Those assertions must be changed
   deliberately, not silently.

Because items 4–6 are domain/storage compatibility decisions, they require explicit owner
approval. They are not authorized by the 2026-10-24 planning amendment.

## Decision (proposed)

For **new writes after approval**:

1. **Creation.** Creating a draft transaction creates exactly one working revision
   (`revision_number = 1`) and writes **no** transaction-audit event.
2. **Draft edit.** A `PATCH` on a transaction that has never been submitted updates that working
   revision in place and increments the transaction's `lock_version`. It creates no second
   revision row and writes no `TRANSACTION_CHANGED` event. A stale `expectedVersion` still returns
   `409` on FR-025's terms.
3. **First submission.** The working revision is activated as revision 1 and the formal record
   begins in the same database transaction. The submission writes `SUBMITTED`, the per-transaction
   `ROUTED` events, and a `REVISION_CREATED` event for revision 1 at the submission stage (not
   `DRAFT`). No draft-stage event precedes them.
4. **After submission.** The revision is immutable. Correction of a returned transaction continues
   to create a new revision under FR-048, with the existing correction/resubmission audit events.
   This ADR does **not** change correction semantics; a returned transaction has already been
   submitted.
5. **Existing rows.** Already-stored draft revisions and draft-stage audit events remain untouched.
   They are legacy pre-amendment data, not rewritten and not deleted. New code stops producing
   them. Read surfaces must not present them as official history (005 `T069`). No migration is
   proposed.
6. **No other behavior changes.** Authorization, financial totals, submission validation, receipt
   enforcement, workflow decisions, and evidence handling are unchanged.

## Alternatives considered

| Alternative | Why rejected |
|---|---|
| Keep current behavior and amend FR-051 to authorize draft-stage audit | Contradicts the 2026-10-08 clarification, makes an abandoned draft look like official history, and means the `SUBMITTED` event no longer begins the stream. |
| Delete existing draft revisions/audit rows in a migration | Violates FR-053's append-only rule and is destructive. Stopping production does not require erasing history. |
| Keep multiple draft revisions but hide them in the UI | This is the acceptable **interim** (005 `T069`), but it leaves the server producing official-looking rows and makes the API honest only when every consumer filters. The domain boundary remains wrong. |
| Make every draft revision immutable from creation | Then each draft edit must create a revision — the current behavior — and the pre-submission history remains. |
| Store no revision row until submission | The transaction needs a current revision for its fields and evidence relationships; removing it is a larger schema change with no benefit. |

## Consequences

- A new never-submitted draft has one revision row; draft edits are updates to it.
- `GET /transactions/{id}/revisions` returns one revision for a new draft. After submission the
  official history begins at revision 1; corrections append revision 2+ unchanged.
- Existing drafts that already carry multiple revisions keep their active revision and their
  stored rows. Their revision numbers are not renumbered. Prior draft revisions remain stored but
  are not presented as official history.
- `REVISION_CREATED` remains in the vocabulary and is written at first submission and at
  correction resubmission. `TRANSACTION_CHANGED` remains available for any post-submission
  transaction change that a future approved requirement authorizes; it is not written for draft
  saves.
- Tests must assert: no audit event on draft create, no second revision and no audit event on
  draft edit, one revision activated at submission, and unchanged correction revisions.
- The UI may hide draft history immediately under 005 `T069`; that interim does not depend on this
  ADR and does not alter stored data.

## Implementation impact (gated)

- **001 `T437`** implements this ADR only after explicit owner approval. It is unchecked and not
  started.
- Likely files: `lib/Application/Expense/TransactionService.php`,
  `lib/Domain/Expense/ExpenseTransaction.php`, `lib/Domain/Expense/TransactionRevision.php`,
  `lib/Application/Workflow/SubmitSheet.php`,
  `lib/Application/Workflow/ResubmitCorrection.php`, and the named integration/contract tests.
- **No migration, no backfill, no deletion** of stored rows.
- **005 `T069`** is the presentation-only interim and does not depend on this ADR.

## Approval

**Status: Proposed — not approved, not implemented.** Approval requires an explicit owner
statement naming this ADR and 001 `T437`. Nothing in the 2026-10-24 planning amendment grants that
approval.
