# Bug Fix: Editing a draft on an OPEN sheet no longer starts review

- **Slug**: open-sheet-draft-status
- **Fixed**: 2026-10-25
- **Assessment**: ./assessment.md
- **Status**: applied

## Summary

`TransactionService::updateTransaction()` no longer calls `ExpenseTransaction::activate()` for an
ordinary draft. Every edit now attaches the new revision with `attachWorkingRevision()`, which makes
it the current revision and increments the lock version without moving the transaction into
`COST_CENTER` / `AWAITING_COST_CENTER_REVIEW`. `activate()` remains reserved for sheet submission and
correction resubmission. The PATCH payload also reports the attached revision as active by definition,
and regression tests now pin status, stage, version, sheet state, and evidence association.

## Changes

| File | Change | Notes |
|------|--------|-------|
| `lib/Application/Expense/TransactionService.php` | modified | The edit path now calls `attachWorkingRevision()` unconditionally; the obsolete correction/ordinary branch and its misleading "harmless" comment are gone. |
| `lib/Domain/Expense/ExpenseTransaction.php` | modified | `attachWorkingRevision()` documentation now states that it is the edit path for every draft, not only corrections. No behavior change. |
| `lib/Infrastructure/Http/TransactionPayloadFormatter.php` | modified | `revisionFrom()` reports the transaction's current revision as `active` by definition, so an attached revision is not misreported while the aggregate is still in memory. |
| `tests/Integration/Expense/TransactionServiceUpdateTest.php` | modified | Added two regression tests and exposed the evidence repository to assert attachment preservation. |
| `tests/Contract/Controller/PurchaserFlowControllerTest.php` | modified | The PATCH contract test now asserts `DRAFT`, `activeRevision.active`, `canEdit`, and `canDelete`. |

## Diff Highlights

The core change:

```php
// An edit must never activate the transaction.
//
// ... activate() is the submission transition, and workflow.md's Correction Contract
// clause 5 puts it at resubmission. ...
$transaction->attachWorkingRevision($revision);
```

The response projection:

```php
private function revisionFrom(ExpenseTransaction $transaction): array
{
    // `revision()` describes the transaction's current revision, so its `active` member is true
    // by definition.
    return array_merge($this->revision($transaction->revision()), ['active' => true]);
}
```

`attachWorkingRevision()` already increments `lockVersion`; the former `else` branch's separate
increment was removed with the branch, so the version is bumped exactly once.

## Tests Added or Updated

- `tests/Integration/Expense/TransactionServiceUpdateTest.php::testUpdatingADraftOnAnOpenSheetPreservesDraftStatus`
  — pins returned and persisted `status = DRAFT`, `stage = DRAFT`, owning sheet `OPEN`, one version
  bump, and the new revision attached.
- `tests/Integration/Expense/TransactionServiceUpdateTest.php::testUpdatingADraftPreservesItsEvidence`
  — pins that the active attachment remains associated after the revision change.
- `tests/Contract/Controller/PurchaserFlowControllerTest.php::testUpdateTransactionReturns200WithUpdatedTransactionDetail`
  — extended to assert the OCS PATCH response keeps `status = DRAFT`, reports the new revision as
  active, and still offers `canEdit` / `canDelete`.
- Existing correction and formatter coverage was rerun unchanged.

## Local Verification

- `scripts/dev/phpunit .specify/bugs/open-sheet-draft-status/ReproTest.php` → **OK (1 test, 4 assertions)**. Observed:
  `returned: status=DRAFT stage=DRAFT | persisted: status=DRAFT stage=DRAFT | sheet=OPEN`.
- `scripts/dev/phpunit tests/Integration/Expense/TransactionServiceUpdateTest.php` → **OK (9 tests, 40 assertions)**.
- `scripts/dev/phpunit tests/Integration/Expense/` → **OK (26 tests, 71 assertions)**.
- `scripts/dev/phpunit tests/Contract/Controller/PurchaserFlowControllerTest.php --filter UpdateTransaction` → **OK (3 tests, 23 assertions)**.
- `scripts/dev/phpunit tests/Contract/Controller/PurchaserFlowControllerTest.php --filter testUpdateTransactionReturns200WithUpdatedTransactionDetail` → **OK (1 test, 16 assertions)**.
- `scripts/dev/phpunit tests/Unit/Infrastructure/Http/` → **OK (34 tests, 65 assertions, 2 PHPUnit deprecations)**.
- `scripts/dev/phpunit tests/Contract/Controller/CorrectionControllerTest.php --filter 'testACorrectedTransactionResubmitsWithItsChanges|testResubmissionActivatesTheDraftAndLeavesEveryOtherRevisionInactive'` → **OK (2 tests, 33 assertions)**.

The PHP wrapper settled the opcode cache before every run; the contract runs also settled routes.

## Deviations from Assessment

1. **The contract test does not assert `stage = DRAFT`.** The `draft` contract fixture seeds
   `current_stage = 'COST_CENTER'` with `current_status = 'DRAFT'` (see
   `tests/Contract/Fixture/seed-contract-fixtures.php`). The fix preserves the stage it loaded, so the
   endpoint test asserts the status and capabilities instead. Stage preservation from a clean `Draft`
   is covered by the integration test, where the aggregate actually starts at `Draft`.
2. **The frontend test was not extended.** `OpenSheetTransactionEdit.spec.ts` mocks
   `updateTransaction()` to return a `DRAFT` detail, so it cannot detect a server-side status
   regression; adding an assertion against that mock would pass with or without this fix. The
   integration service test and the OCS contract test are the effective regression barriers.
3. **No real-database `activated_at` assertion was added.** The fix removes the only edit-path call to
   `activate()`, so `attachWorkingRevision()` leaves `activatedAt` null by construction. Adding a new
   subprocess DB round-trip scenario for that one column was disproportionate; the existing
   `RepositoryRoundTripTest` harness is the place for it if the project wants the assertion.
4. **Backend scope was applied under this explicit fix invocation.** The assessment noted that `T075`
   is frontend-only and Phase 13 disclaims backend changes. This command is the authorization for the
   assessed remediation; no separate Spec Kit task was created. The queue/decision hardening and the
   repair of already-corrupted rows were deliberately not implemented because the assessment scoped
   them separately.

## Follow-ups

- **Existing corrupt rows**: detect transactions on `OPEN`, never-submitted sheets whose
  `current_status` is not `DRAFT`, and decide the repair policy. Do not reset rows blindly: check for
  pre-submission decisions and audit events first, and note that the bug also wrote `activated_at` on
  the draft revision.
- **Queue/decision guard**: separately authorize the defense-in-depth check that cost-center/senior
  queues and decisions require a non-`OPEN` owning sheet. This would prevent any future state drift
  from exposing an unsubmitted transaction.
- **Ledger bookkeeping**: record the server-side fix in the 005/001 task ledger if task-level
  traceability is required. `T075` itself remains frontend-only and was not redefined.
- **Optional DB assertion**: add `activated_at IS NULL` for an edited draft revision to the real-DB
  round-trip harness.
