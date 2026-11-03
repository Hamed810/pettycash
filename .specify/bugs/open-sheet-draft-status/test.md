# Bug Verification: Editing a draft on an OPEN sheet no longer starts review

- **Slug**: open-sheet-draft-status
- **Tested**: 2026-10-25
- **Assessment**: ./assessment.md
- **Fix**: ./fix.md
- **Result**: partial

## Summary

The original symptom no longer reproduces through the automated reproduction or the live HTTP
contract path: saving an existing draft on an `OPEN` sheet returns and persists `status = DRAFT`,
leaves the sheet `OPEN`, and keeps the revision editable. The targeted integration, contract,
formatter, and correction suites all pass. The manual browser walkthrough listed in the assessment
was not repeated, so the result is **partial** under this command's guardrail rather than
`verified`, even though no server-side or component-level regression was found.

## Checks Performed

| Check | Command / Action | Result | Notes |
|-------|------------------|--------|-------|
| Reproduction (post-fix) | `scripts/dev/phpunit .specify/bugs/open-sheet-draft-status/ReproTest.php` | pass | 1 test, 4 assertions. `status=DRAFT stage=DRAFT` for both returned and persisted state; sheet `OPEN`. |
| New / updated integration tests | `scripts/dev/phpunit tests/Integration/Expense/TransactionServiceUpdateTest.php` | pass | 9 tests, 40 assertions. Includes the added status/stage/version/sheet and evidence-preservation cases. |
| New / updated contract test | `scripts/dev/phpunit tests/Contract/Controller/PurchaserFlowControllerTest.php --filter testUpdateTransactionReturns200WithUpdatedTransactionDetail` | pass | 1 test, 16 assertions. OCS PATCH reports `DRAFT`, `activeRevision.active`, `canEdit`, `canDelete`. |
| Regression, expense integration | `scripts/dev/phpunit tests/Integration/Expense/` | pass | 26 tests, 71 assertions. |
| Regression, purchaser flow | `scripts/dev/phpunit tests/Contract/Controller/PurchaserFlowControllerTest.php` | pass | 63 tests, 487 assertions. |
| Regression, correction flow | `scripts/dev/phpunit tests/Contract/Controller/CorrectionControllerTest.php` | pass | 17 tests, 201 assertions. Run serially; see residual risk about concurrent contract processes. |
| Regression, payload formatter | `scripts/dev/phpunit tests/Unit/Infrastructure/Http/` | pass | 34 tests, 65 assertions, 2 pre-existing PHPUnit deprecations. |
| Component guard for the T075 edit surface | `npx vitest run tests/Frontend/Component/OpenSheetTransactionEdit.spec.ts` | pass | 6 tests. The form stays editable after a `DRAFT` response; the transport is mocked. |
| PHP syntax | `php -l` on the five changed files via `scripts/dev/in-container` | pass | No syntax errors. |
| Manual browser reproduction | Assessment's five UI steps (sign in, open `OPEN` sheet, edit draft, Save draft / Update, re-open) | not-run | The assessment's automated reproduction was exercised instead; see residual risks. |
| Lint / type-check | — | not-run | The fix changes PHP only; no frontend or type surface changed, and the PHP files were syntax-checked. |

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
TransactionServiceUpdateTest: OK (9 tests, 40 assertions)
tests/Integration/Expense/:   OK (26 tests, 71 assertions)
PurchaserFlowControllerTest:  OK (63 tests, 487 assertions)
CorrectionControllerTest:     OK (17 tests, 201 assertions)
tests/Unit/Infrastructure/Http/: OK, but there were issues!
                              Tests: 34, Assertions: 65, PHPUnit Deprecations: 2.
OpenSheetTransactionEdit.spec.ts: Test Files 1 passed, Tests 6 passed
```

## Residual Risks

- **Manual browser reproduction not exercised.** The assessment's five UI steps were not repeated.
  The automated reproduction covers the same service call that both buttons use, and the component
  guard confirms the form remains editable when the response is `DRAFT`, but neither is a real
  browser journey. The assessment's layer verdict says the frontend renders the server response
  without deriving status itself, which limits the risk but does not remove it.
- **Existing corrupted rows are not repaired.** Transactions saved through the bug before this fix
  still hold `AWAITING_COST_CENTER_REVIEW` on `OPEN` sheets, and their draft revisions may carry an
  `activated_at` stamp. Detection and repair remain a separate decision, as documented in the
  assessment and `fix.md`.
- **Queue/decision hardening is out of scope.** Cost-center and senior queues still have no
  owning-sheet-status predicate. The fix removes new state drift from the edit path but does not
  add the defense-in-depth guard.
- **No real-database `activated_at IS NULL` assertion** was added for an edited draft revision.
  `attachWorkingRevision()` leaves it null by construction, but only the real-DB round-trip harness
  could prove the written column.
- **Contract fixtures are not concurrency-safe.** Two contract PHPUnit processes must not run
  against the same lab at once. During verification, two concurrent invocations produced transient
  `active_revision_id` and `TRANSACTION_VERSION_CONFLICT` failures; the same tests pass in a single
  sequential run (including the full 17-test correction file). This is a test-environment hazard,
  not a product regression.

## Recommendation

The defect is fixed at the service and OCS layers, and no regression was found in the changed or
adjacent areas. The only unexercised check is the assessment's manual browser walkthrough; per the
bug-test guardrail the result is therefore `partial`, not `verified`. If browser evidence is
required before closing, run the five manual steps (or an equivalent Playwright journey: create an
`OPEN` sheet, save a draft, reopen it through the eye action, edit, save, and confirm the row still
reads `DRAFT` and the form remains editable) and mark the bug verified. Otherwise the fix can be
accepted as verified at the server/API layer; re-running `/speckit.bug.assess` is not warranted
because the symptom does not reproduce and no regression appeared.
