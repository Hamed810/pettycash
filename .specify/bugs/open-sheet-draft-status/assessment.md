# Bug Assessment: Editing a draft on an OPEN sheet starts review

- **Slug**: open-sheet-draft-status
- **Created**: 2026-10-25
- **Source**: pasted text (no URL supplied; URL trust policy did not apply, nothing fetched)
- **Verdict**: valid
- **Severity**: critical

**Severity rationale**: every save of an ordinary draft on an `OPEN` sheet triggers it; the owner
then loses edit and delete on their own unsubmitted transaction; and the transaction becomes visible
to cost-center reviewers, who can record an immutable decision before the sheet is submitted. The
submission gate is a core financial control, so this is a workflow-integrity failure, not a display
defect.

## Report (verbatim or summarized)

> For an existing DRAFT transaction belonging to an OPEN, never-submitted sheet, clicking either
> "Save draft" or "Update" incorrectly changes the displayed transaction status to "Awaiting cost
> center review".
>
> Expected behavior:
>
> - Both operations preserve DRAFT.
> - The owning sheet remains OPEN.
> - No review or approval workflow begins.
> - Existing attachments remain correctly associated.
> - Only explicit sheet submission can initiate review.

No URL was supplied, so no host was parsed and no fetch was attempted. The report was ingested as
pasted text from the invoking user.

## Symptom

Saving an existing `DRAFT` transaction on an `OPEN`, never-submitted sheet changes the transaction's
persisted status to `AWAITING_COST_CENTER_REVIEW` and its stage to `COST_CENTER`, while the owning
sheet stays `OPEN`. The server returns that new status, the frontend renders it, and the transaction
becomes read-only to its owner. The defect affects both entry points because "Save draft" and
"Update" converge on the same `PATCH /transactions/{id}` path.

## Reproduction

### Minimal automated reproduction

A single-test reproduction was placed inside this bug directory only:

- `.specify/bugs/open-sheet-draft-status/ReproTest.php`

It drives the exact production application path used by both buttons,
`TransactionService::updateTransaction()`, with the repository's existing in-memory collaborators and
a transaction whose defaults are `stage=DRAFT`, `status=DRAFT` on an `OPEN` sheet. It asserts the
returned status, the stored status, and the owning sheet status.

Command (repository PHP wrapper, which settles the opcode cache first):

```bash
scripts/dev/phpunit .specify/bugs/open-sheet-draft-status/ReproTest.php
```

Observed:

```text
OBSERVED  returned: status=AWAITING_COST_CENTER_REVIEW stage=COST_CENTER | persisted: status=AWAITING_COST_CENTER_REVIEW stage=COST_CENTER | sheet=OPEN

1) ...OpenSheetDraftStatusReproTest::testUpdatingAnOpenSheetDraftPreservesDraftStatus
update must preserve DRAFT
Failed asserting that two variables reference the same object.
- TransactionStatus Enum (Draft, 'DRAFT')
+ TransactionStatus Enum (AwaitingCostCenterReview, 'AWAITING_COST_CENTER_REVIEW')
```

The expected-DRAFT assertion fails; the returned status, the stored status, and the sheet observation
are all printed before the assertion. Persistence of `current_stage` / `current_status` is
independently confirmed in `DbExpenseTransactionRepository::save()` (see below); the in-memory
repository does not invent those fields.

### Manual UI steps

1. Sign in as the purchaser who owns an `OPEN` sheet that has never been submitted.
2. Open the sheet workspace and edit an existing `DRAFT` transaction through the eye icon.
3. Change any field and click either **Save draft** or **Update**.
4. Observe the transaction status become "Cost center" in the workspace table and "Awaiting cost center
   review" in the shared status badge; the sheet header remains `OPEN`.
5. Re-open the transaction: the surface is now read-only, and the owner can no longer edit or delete it.

Both buttons are confirmed to call the same `persist()` function, so step 3 does not need to be
performed twice to demonstrate two different causes.

## Suspected Code Paths

All of the following were read directly or at the symbol level; they are confirmed paths, not guesses.

### Frontend

- `src/views/transactions/TransactionFormView.vue:410` — `persist()` calls
  `transactionStore.updateExistingTransaction()` for an edited transaction. `handleSaveDraft()`
  (`:508`) and `handleSubmit()` (`:519`) both call `saveAndReturnToSheet()` (`:482`), which awaits
  `persist()`; neither button has a separate write path.
- `src/stores/transactions.ts:171-178` — replaces `currentTransaction` with the API response; there
  is no independent frontend status derivation.
- `src/views/transactions/TransactionFormView.vue:130-134` — `editable` accepts only `DRAFT` (or a
  correction), so the returned status immediately disables the save controls.
- `src/views/sheets/SheetDetailView.vue:121` — maps `AWAITING_COST_CENTER_REVIEW` to the compact
  workspace label "Cost center".
- `src/components/shared/StatusBadge.vue:91` — maps the same status to the full label
  "Awaiting cost center review" on shared surfaces.

### API and application

- `lib/Controller/TransactionController.php:129` — `update()` validates the command id and delegates
  to `TransactionService::updateTransaction()`.
- `lib/Application/Expense/TransactionService.php:256` — `updateTransaction()`; lines `309-312`
  refuse edits only when the sheet is not `OPEN` and the transaction is not a correction.
- `lib/Application/Expense/TransactionService.php:413-431` — the defect: a non-correction edit calls
  `$transaction->activate($revision)`.
- `lib/Infrastructure/Http/TransactionPayloadFormatter.php:55-85` — `summary()` publishes the
  transaction's current `stage` and `status` unchanged in the API response.

### Domain and persistence

- `lib/Domain/Expense/ExpenseTransaction.php:42-47` — `activate()` marks the revision active and sets
  `stage = CostCenter`, `status = AwaitingCostCenterReview`.
- `lib/Infrastructure/Persistence/Expense/DbExpenseTransactionRepository.php:216` — `save()` writes
  `current_stage` and `current_status` from the aggregate.

### Blast radius into review

- `lib/Application/Workflow/ManagementReviewService.php:372` — `getCostCenterQueue()` reads through
  `findByCostCenterId()` with `WorkflowStage::CostCenter` and no sheet-status predicate.
- `lib/Infrastructure/Persistence/Expense/DbExpenseTransactionRepository.php:98-117` —
  `findByCostCenterId()` filters on the revision's cost center and, optionally, the transaction stage;
  it never joins the owning sheet.
- `lib/Application/Workflow/ManagementReviewService.php:112` — `recordDecisionInTransaction()` has no
  owning-sheet status guard. Authorization is resolved from the transaction's stage and the actor's
  role, so a cost-center manager holding the queue row can decide a transaction whose sheet is still
  `OPEN`.
- `lib/Domain/Expense/ExpenseSheet.php:75-119` — `submit()` re-activates every transaction
  unconditionally, so a decision recorded before submission would be overwritten by the later
  submission while the immutable decision row remains. Because `submit()` re-activates the same
  revision, `refuseIfAlreadyDecided()` would then reject a second cost-center decision on it, leaving
  the transaction stuck. This is a source-derived downstream risk, not required to reproduce the
  reported symptom, and it needs verification before any repair is attempted.

## Root Cause Hypothesis

**Confirmed. Confidence: high.**

`TransactionService::updateTransaction()` uses `ExpenseTransaction::activate()` as the edit path for
every draft that is not a correction. `activate()` is the submission primitive: it switches the
active revision and routes the transaction to cost-center review. On an edit it therefore starts the
workflow that only explicit sheet submission is allowed to start, and
`DbExpenseTransactionRepository::save()` persists the resulting `COST_CENTER` /
`AWAITING_COST_CENTER_REVIEW` state.

The source comment at `TransactionService.php:413-425` states that activation on an ordinary draft is
"harmless, because nothing has been submitted yet" and that `TransactionServiceUpdateTest` pins the
behavior. The first claim is contradicted by the queue read above. The second is inaccurate:
`tests/Integration/Expense/TransactionServiceUpdateTest.php` asserts revision content and numbering,
but never asserts `status` or `stage`, so the activation is not pinned by a test.

Layer verdict:

- **Persisted state**: defective. The stored transaction row holds the review status while the sheet
  remains `OPEN`.
- **API response**: a faithful carrier. `TransactionPayloadFormatter::summary()` reads the aggregate;
  it does not introduce the wrong value.
- **Frontend presentation**: correct. The store replaces `currentTransaction` with the response and
  the badges render what the server returned; there is no separate client-side status rule.

The reported "Awaiting cost center review" label is the shared full-vocabulary label. The workspace
table itself prints the compact "Cost center" chip for the same value, so the two surfaces are
consistent with each other and both reflect the corrupt persisted status.

## Contract Alignment

- **Feature 005, `FR-005-046`**: saving through the OPEN-sheet edit experience "MUST ... preserve
  `DRAFT` status ... Saving MUST NOT submit the transaction or sheet, MUST NOT start an approval
  workflow". The observed behavior violates both clauses.
- **Feature 005, `contracts/purchaser-ui-contract.md` C-20 clause 6**: the fix target is stated
  explicitly — "persists through the existing update path, preserves `DRAFT` ... Save MUST NOT submit
  the transaction or sheet, start approval, or require a receipt".
- **Feature 001, `contracts/workflow.md` Correction Contract clause 5**: "Activation atomically
  switches the active revision and routes it to cost-center review" is a *resubmission* rule.
  Activation belongs to submission/resubmission, not to a save.
- **Feature 001 Decision Guards**: cost-center, senior, and finance decisions are defined over
  transactions in the review stages reached through submission. Exposing an `OPEN`-sheet draft to
  that path bypasses the submission gate.
- **Freeze boundaries**: `FR-005-045` / `C-19` freeze the owner-approved list and workspace
  presentation. C-19 clause 4 says the freeze is scope protection, not a correctness exemption, and
  "MUST NOT be used to ... refuse an explicitly authorized defect fix". The preferred fix is
  server-side and does not alter any frozen UI surface, so it does not require re-baselining the
  freeze. `T074` is documentation-only and is not a blocker.
- **Scope authority**: `T075` is explicitly frontend-only and records "no backend task", and Phase 13
  says it "does not authorize ... any backend/API/authorization change". The confirmed fix therefore
  needs a separately authorized backend task before `/speckit.bug.fix` implements it. This assessment
  does not grant that authorization.

## Proposed Remediation

**Preferred**: make every edit attach the new revision without activating it.

In `lib/Application/Expense/TransactionService.php`, replace the current conditional with an
unconditional attach:

```php
$transaction->attachWorkingRevision($revision);
```

`ExpenseTransaction::attachWorkingRevision()` already does the core of what an edit needs: it sets the
new revision as the transaction's active revision, increments `lockVersion`, and updates `updatedAt`,
while leaving `stage` and `status` untouched. It is currently used for corrections, which is why that
path does not exhibit the regression. Using it for ordinary drafts makes the two edit paths consistent
and avoids the double increment: `attachWorkingRevision()` already increments `lockVersion`, so the
current `else` branch's separate increment must not be retained.

One detail must be handled deliberately. `attachWorkingRevision()` does not set the revision's
in-memory `active` flag and does not set `activatedAt`; `activate()` sets both. Leaving `activatedAt`
null is correct for an unsubmitted draft and avoids stamping a false submission time.
`TransactionPayloadFormatter::revision()` emits `active` from `TransactionRevision::isActive()`, so the
immediate PATCH response would otherwise report `activeRevision.active = false` even though the
revision is now the current one — while a re-read derives it from the transaction's current revision
and reports `true`. The fix must keep those two consistent, preferably by deriving the flag in
`revisionFrom()` from the transaction's current revision rather than from the revision's own mutable
flag, or by adding a non-activating "mark current" operation. Either way, do not call `activate()`
merely to set the flag, and pin the response field with a contract assertion.

`activate()` should remain reserved for `ExpenseSheet::submit()` and
`ExpenseTransaction::resubmitCorrection()`, which are the two places the workflow contract permits
activation. Generalize the `attachWorkingRevision()` docblock from its correction-specific framing to
"attach a revision being edited", and rewrite the misleading `updateTransaction()` comment: it should
state that no edit activates, because submission/resubmission is the only gate.

**Defense in depth (recommended as a separate, separately authorized change)**: make
`getCostCenterQueue()` / `getSeniorQueue()` and the decision path require the owning sheet to be
non-`OPEN`, or join the sheet status into those reads. This is not needed to fix the reported symptom
once edit no longer activates, but it ensures a future state drift cannot expose an unsubmitted
transaction to a reviewer. It touches application and persistence code beyond the minimal fix and
should be scoped explicitly.

**Alternative (not preferred)**: call `activate()` and then force `stage`/`status` back to draft.
Rejected: it activates the revision's `active` flag and then relies on undoing part of the effect,
uses mutable public properties to hide the inconsistency, and leaves a future caller with the same
hazard. It is a symptom patch, not a correction of which operation owns activation.

**Files likely to change**:

- `lib/Application/Expense/TransactionService.php` — the edit path and its comment.
- `lib/Domain/Expense/ExpenseTransaction.php` — docblock only, if `attachWorkingRevision()` is
  generalized.
- `tests/Integration/Expense/TransactionServiceUpdateTest.php` — pin status, stage, version, and the
  unchanged sheet.
- `tests/Contract/Controller/PurchaserFlowControllerTest.php` — assert `status === 'DRAFT'` and the
  editable capability in the PATCH response.
- `tests/Integration/Workflow/ManagementWorkflowTest.php` or a focused queue test — only if the
  defense-in-depth guard is authorized.
- `tests/Frontend/Component/OpenSheetTransactionEdit.spec.ts` — assert the form remains editable after
  a `DRAFT` response; this documents the UI contract but is not a substitute for the server assertion.

**Tests to add or update**:

- `TransactionServiceUpdateTest`: after updating a `DRAFT` on an `OPEN` sheet, assert the returned and
  reloaded `status` is `Draft`, `stage` is `Draft`, the owning sheet is still `Open`, `lockVersion`
  increased by exactly one, the new revision is active, and the prior revision is intact. At the
  database level, assert the new revision's `activated_at` stays `NULL`, since a draft edit is not a
  submission.
- `PurchaserFlowControllerTest::testUpdateTransactionReturns200WithUpdatedTransactionDetail`: assert
  the OCS payload's `status` is `DRAFT`, `activeRevision.active` is `true`, and
  `allowedActions.canEdit` is `true`.
- Evidence preservation: update a draft that has an active attachment and assert the attachment count
  and identity survive. Evidence reads are transaction-scoped (`findByTransactionId()`), and the edit
  creates a new revision without touching evidence rows, so this should pass; the assertion locks the
  report's attachment expectation in place.
- If the queue guard is authorized: assert a draft on an `OPEN` sheet is absent from
  `getCostCenterQueue()` and that a decision against it is refused without writing a decision row.
- Frontend: keep the existing OpenSheetTransactionEdit coverage and add a post-save assertion that the
  surface is still the editable form, not the read-only inspection surface.

## Risks & Considerations

- **Existing corrupt rows are not repaired by the code fix.** Transactions already saved through the
  bug hold `AWAITING_COST_CENTER_REVIEW` while their sheets are `OPEN`. The bug also stamps
  `activated_at` on the new revision through `TransactionRevision::activate()`, even though the sheet
  was never submitted. A fix only prevents new occurrences. A one-off detection/repair decision is
  needed; do not silently reset rows without checking whether any reviewer decision or audit event was
  recorded against them.
- **Decisions recorded before submission are immutable.** If a manager acted on an exposed row,
  `pettycash_workflow_decisions` and the audit trail now contain a decision that the submission gate
  should have prevented. FR-053 forbids editing or deleting decisions, so repair needs an explicit
  owner-approved policy, not a status rewrite.
- **No API shape or migration change** is required for the preferred fix. `status` and `stage` stay in
  the payload; only their values change for an edit.
- **Lock version**: `attachWorkingRevision()` increments `lockVersion`; removing the current `else`
  branch avoids a double increment and preserves optimistic concurrency for both edit paths.
- **Correction behavior must remain identical.** The correction path already uses
  `attachWorkingRevision()`; the preferred change generalizes that call rather than altering it.
- **Why it slipped through**: update integration and contract tests assert field snapshots, revision
  numbering, and response shape, but not workflow status. Adding the status assertion is the
  regression barrier the current suite lacks.
- **Freeze/authorization**: the preferred change is in `lib/`, not in `src/`, so it does not modify a
  frozen presentation surface. It still needs a new backend authorization because `T075` says "no
  backend task" and Phase 13 disclaims backend changes.

## Open Questions

- [NEEDS CLARIFICATION: Which task/authorization covers the server-side correction, given `T075` is
  explicitly frontend-only and Phase 13 disclaims backend changes?]
- [NEEDS CLARIFICATION: Should already-corrupted rows on `OPEN` sheets be detected and repaired in the
  same change, and if any pre-submission decisions exist, what is the approved handling?]
- [NEEDS CLARIFICATION: Is the queue/decision sheet-status guard authorized as part of this fix, or
  should it remain a separate hardening task?]
