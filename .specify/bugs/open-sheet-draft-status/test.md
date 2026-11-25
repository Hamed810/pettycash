# Bug Verification: Editing a draft on an OPEN sheet no longer starts review

- **Slug**: open-sheet-draft-status
- **Tested**: 2026-11-24
- **Assessment**: ./assessment.md
- **Fix**: ./fix.md
- **Result**: partial

## Summary

The original symptom no longer reproduces through the automated reproduction: saving an existing draft on an `OPEN` sheet returns and persists `status = DRAFT`, `stage = DRAFT`, and leaves the sheet `OPEN`. All fix-related regression suites pass with no server-side or component-level regression. However, the assessment's manual browser walkthrough (five UI steps) was not repeated, so per the bug-test guardrail the result is `partial`, not `verified`. The full `tests/Integration/Expense/` directory run hit a pre-existing fatal error in an unrelated test file (`DeleteSheetOwnershipAndCascadeTest.php`) that is not caused by this fix.

## Checks Performed

| Check | Command / Action | Result | Notes |
|-------|------------------|--------|-------|
| Reproduction (post-fix) | `scripts/dev/phpunit .specify/bugs/open-sheet-draft-status/ReproTest.php` | pass | 1 test, 4 assertions. `returned: status=DRAFT stage=DRAFT | persisted: status=DRAFT stage=DRAFT | sheet=OPEN`. |
| New / updated integration tests | `scripts/dev/phpunit tests/Integration/Expense/TransactionServiceUpdateTest.php` | pass | 9 tests, 40 assertions. Includes added status/stage/version/sheet and evidence-preservation cases. |
| New / updated contract test | `scripts/dev/phpunit tests/Contract/Controller/PurchaserFlowControllerTest.php --filter testUpdateTransactionReturns200WithUpdatedTransactionDetail` | pass | 1 test, 16 assertions. OCS PATCH reports `DRAFT`, `activeRevision.active`, `canEdit`, `canDelete`. (Full file: 64 tests, 517 assertions.) |
| Regression, expense integration | `scripts/dev/phpunit tests/Integration/Expense/` | failed | Fatal error in unrelated `DeleteSheetOwnershipAndCascadeTest.php` (anonymous-class `ExpenseSheetRepository` mock missing `countByStatus`/`findByPublicIdCompleted`). Pre-existing, not caused by the fix; the fix-related file passes within it. |
| Regression, purchaser flow | `scripts/dev/phpunit tests/Contract/Controller/PurchaserFlowControllerTest.php` | pass | 64 tests, 517 assertions. |
| Regression, correction flow | `scripts/dev/phpunit tests/Contract/Controller/CorrectionControllerTest.php` | pass | 17 tests, 201 assertions. |
| Regression, payload formatter | `scripts/dev/phpunit tests/Unit/Infrastructure/Http/` | pass | 40 tests, 91 assertions, 2 pre-existing PHPUnit deprecations (not failures). |
| Component guard for the T075 edit surface | `npx vitest run tests/Frontend/Component/OpenSheetTransactionEdit.spec.ts` | pass | 6 tests. Form stays editable after a `DRAFT` response; transport mocked. |
| PHP syntax | `php -l` on three changed files via `scripts/dev/in-container` | pass | No syntax errors. |
| Manual browser reproduction | Assessment's five UI steps (sign in, open `OPEN` sheet, edit draft, Save/Update, re-open) | not-run | Not repeated; automated reproduction exercised instead (see residual risks). |
| Lint / type-check | — | not-run | Fix changes PHP only; syntax-checked. No frontend/type surface changed. |

## Output Excerpts

Pre-fix reproduction, recorded in `assessment.md`:

```text
OBSERVED  returned: status=AWAITING_COST_CENTER_REVIEW stage=COST_CENTER | persisted: status=AWAITING_COST_CENTER_REVIEW stage=COST_CENTER | sheet=OPEN
update must preserve DRAFT
```

Post-fix reproduction:

```text
OBSERVED  returned: status=DRAFT stage=DRAFT | persisted: status=DRAFT stage=DRAFT | sheet=OPEN
OK (1 test, 4 assertions)
```

Changed-area and regression runs:

```text
ReproTest: OK (1 test, 4 assertions)
TransactionServiceUpdateTest: OK (9 tests, 40 assertions)
PurchaserFlowControllerTest: OK (64 tests, 517 assertions)
CorrectionControllerTest: OK (17 tests, 201 assertions)
tests/Unit/Infrastructure/Http/: OK, but there were issues! Tests: 40, Assertions: 91, PHPUnit Deprecations: 2
OpenSheetTransactionEdit.spec.ts: Test Files 1 passed, Tests 6 passed
tests/Integration/Expense/: FATAL in DeleteSheetOwnershipAndCascadeTest.php (unrelated pre-existing mock issue)
```

## Residual Risks

- **Manual browser reproduction not exercised.** The assessment's five UI steps were not repeated. The automated reproduction covers the same service call that both buttons use, and the component guard confirms the form remains editable when the response is `DRAFT`, but neither is a real browser journey.
- **Existing corrupted rows are not repaired.** Transactions saved through the bug before this fix still hold `AWAITING_COST_CENTER_REVIEW` on `OPEN` sheets, and their draft revisions may carry an `activated_at` stamp. Detection and repair remain a separate decision, as documented in the assessment and `fix.md`.
- **Queue/decision hardening is out of scope.** Cost-center and senior queues still have no owning-sheet-status predicate. The fix removes new state drift from the edit path but does not add the defense-in-depth guard.
- **No real-database `activated_at IS NULL` assertion** was added for an edited draft revision.
- **Contract fixtures are not concurrency-safe.** Two contract PHPUnit processes must not run against the same lab at once.
- **Pre-existing unrelated suite failure.** The `tests/Integration/Expense/` directory run fails in `DeleteSheetOwnershipAndCascadeTest.php`, a repository-mock issue unrelated to this fix; it is not a regression introduced by the fix.

## Recommendation

The defect is fixed at the service and OCS layers, and no fix-related regression was found. The only unexercised check is the assessment's manual browser walkthrough; per the bug-test guardrail the result is therefore `partial`, not `verified`. If browser evidence is required before closing, run the five manual steps (or an equivalent Playwright journey: create an `OPEN` sheet, save a draft, reopen it, edit, save, and confirm the row still reads `DRAFT` and the form remains editable) and mark the bug verified. Otherwise the fix can be accepted as verified at the server/API layer; re-running `/speckit.bug.assess` is not warranted because the symptom does not reproduce and no regression appeared.
