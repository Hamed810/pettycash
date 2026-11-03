# 005 — Receipt/creation slice verification

**Date:** 2026-10-24  
**Authority:** owner directive 2026-10-23 (`D-022`, `ADR-007`), authorizing 001 `T431`–`T435` and 005 `T053`–`T063` only  
**Status:** implemented and technically verified; owner visual acceptance (`T051`), the full PHP suite (`T049`), the final traceability audit (`T052`), and all Git writes remain open  
**Branch:** `feat/pettycash-purchaser-ui-batch-a` (working tree; no staging, commit, or push)

## Scope delivered

- Saving an editable transaction requires only the FR-022 minimum (expense date and a positive amount); description, cost center, and expense type are accepted in the same save and no receipt is required.
- The create form stages one or more receipts before the transaction exists, creates first, then uploads each staged file to the existing evidence endpoint with the returned non-empty transaction id. A failed upload leaves the transaction saved and names the failed file.
- Sheet submission and correction resubmission enforce the active expense type's current `evidence_required` rule per transaction. A refusal aggregates every missing receipt as `transactions.<transactionId>.evidence` and maps to `422 VALIDATION_FAILED`.
- `TransactionSummary` exposes server-derived `evidenceRequired`; the readiness surface and the `422` name the same offending transactions.
- A debug-gated development fixture creates sheets with exactly 25, 30, 35, and 40 transactions, with a namespace-scoped cleanup path.

The server half (001 `T431`–`T435`) is recorded in `specs/001-pettycash-v1-core/tasks.md`. This report records the 005 half (`T044`, `T053`–`T063`).

## T060 — development fixtures

Commands used:

```bash
scripts/dev/seed-test-sheets --owner pettycash-e2e
scripts/dev/seed-test-sheets --cleanup --owner pettycash-e2e
```

- The command is `pettycash:dev:seed-sheets`, hard-gated on Nextcloud `debug`.
- Seeding first removes the `DEV T060 fixture` namespace, so a rerun replaces rather than appends.
- Business writes use the application services; direct reference-data writes and the optional `IDBConnection` cleanup of transaction audit / `transaction.create` rows are documented in the command.
- Verified fixture shape: **4 sheets / 130 transactions / 66 receipts**, transaction counts **25/30/35/40**, missing-required counts **1/5/1/5**, attached-exempt counts **2/3/2/5**, and correction-returned counts **0/0/2/0**.
- Cleanup verified: `4 sheet(s), 130 transaction(s), 66 evidence row(s), 66 file(s)` removed; reseeding replaced the exact fixture set.
- Targeted command test: `scripts/dev/phpunit tests/Contract/Command/DevSeedSheetsCommandTest.php` → **OK (3 tests, 468 assertions)**.

## T061/T071 — browser evidence matrix and fixture restoration

The evidence runner is `tests/E2E/t061-receipt-slice-evidence.mjs`, with shared fixture helpers in `tests/E2E/t060-fixtures.mjs`. Each run reseeds the T060 fixtures for `pettycash-e2e` **before** the journey, switches the E2E user's `core lang` with `occ`, drives the real lab at `http://localhost:8081`, and reseeds **again in a `finally`** so the run cannot leave its own residue.

| Run | Direction | Viewport | Checks | Screenshots | Console errors | Page errors | Baseline | Restored after run |
|---|---|---|---|---:|---:|---:|---:|---|---|
| `fa-desktop` | RTL | 1440×900 | 49/49 | 11 | 1 expected `422` | 0 | 130 tx / 66 receipts | 130 tx / 66 receipts |
| `en-desktop` | LTR | 1440×900 | 49/49 | 11 | 1 expected `422` | 0 | 130 tx / 66 receipts | 130 tx / 66 receipts |
| `fa-mobile` | RTL | 390×844 | 49/49 | 11 | 1 expected `422` | 0 | 130 tx / 66 receipts | 130 tx / 66 receipts |
| `en-mobile` | LTR | 390×844 | 49/49 | 11 | 1 expected `422` | 0 | 130 tx / 66 receipts | 130 tx / 66 receipts |

The table's 49-check result is the T071-final runner; the original 46-check runs predate the reseed assertions and the T066 surface adaptation. The T071 acceptance was additionally proven by two consecutive `en-desktop` runs on the final build: both started at `{transactions: 130, receipts: 66}`, both finished at `{transactions: 130, receipts: 66}` with `{25: 25, 30: 30, 35: 35, 40: 40}`, and both recorded 49/49 checks with a reseed before and after. Saved records: `/tmp/opencode/t071/run-1.json` and `/tmp/opencode/t071/run-2.json`.

All four runs passed. The single console `422` in each run is the intentional submission refusal (the server response the journey asserts); page errors stayed zero. Screenshots and the full JSON record are under `/tmp/opencode/t061/<run>/` (`00-sheet-list.png` through `10-sheet35-after-exempt-correction.png`, with `evidence.json`).

Journeys covered in every run:

1. Baseline shape: 25/30/35/40 transactions; 130 transactions and 66 receipts; missing-required 1/5/1/5; attached-exempt 2/3/2/5; correction-returned 0/0/2/0.
2. Readiness names the missing required receipt on sheet 25, and Submit remains reachable.
3. Create with the FR-022 minimum and no receipt; the created row has zero evidence and no evidence upload is issued.
4. Create with every optional field and two staged receipts; the create request precedes both uploads, both uploads address the returned non-empty transaction id, and the receipts keep their names.
5. Submission refusal on sheet 30 names all five missing-required transactions; focus moves to the named summary; the sheet stays `OPEN`.
6. Attach the missing receipt on sheet 25; readiness clears; submission succeeds and the sheet leaves `OPEN` at `COST_CENTER` with a `submittedAt`.
7. Correction refusal for the receipt-required returned transaction on sheet 35: no resubmit request, and the transaction stays `AWAITING_PURCHASER_CORRECTION`.
8. Correction resubmission for the receipt-exempt returned transaction succeeds without evidence and restarts at `AWAITING_COST_CENTER_REVIEW`.
9. Final shape: 25/31/35/41 transactions, 132 total, 69 receipts. Final sheet states: sheet 25 `COST_CENTER`, sheet 30 `OPEN`, sheet 35 `CORRECTION` with one correction-required transaction, sheet 40 `OPEN`.

The final shape is consistent with adding exactly two transactions: one minimum-save row on sheet 40, one all-optional row with two receipts on sheet 30, plus one receipt attached to the existing sheet-25 transaction.

## T062 — targeted and aggregate verification

### Targeted Vitest

Command:

```bash
npx vitest run \
  tests/Frontend/Component/PurchaserFlow.spec.ts \
  tests/Frontend/Component/StagedReceipts.spec.ts \
  tests/Frontend/Component/SheetWorkspace.spec.ts \
  tests/Frontend/Component/TransactionCorrection.spec.ts
```

Result: **4 files / 190 tests passed**.

| Spec | Tests |
|---|---:|
| `PurchaserFlow.spec.ts` | 147 |
| `TransactionCorrection.spec.ts` | 22 |
| `SheetWorkspace.spec.ts` | 16 |
| `StagedReceipts.spec.ts` | 5 |

### Targeted container PHPUnit

All commands ran through `scripts/dev/phpunit`; no host PHP.

| File | Result |
|---|---|
| `tests/Unit/Domain/Expense/SheetSubmissionPolicyTest.php` | OK (16 tests, 33 assertions) |
| `tests/Integration/Workflow/SheetSubmissionTest.php` | OK (11 tests, 56 assertions) |
| `tests/Contract/Controller/CorrectionControllerTest.php` | OK (17 tests, 201 assertions) |
| `tests/Contract/Command/DevSeedSheetsCommandTest.php` | OK (3 tests, 468 assertions) |
| `tests/Unit/Infrastructure/Http/TransactionPayloadFormatterEvidenceTest.php` | OK (4 tests, 7 assertions) |

Total: **51 tests / 765 assertions**.

Environment note: the first `CorrectionControllerTest` run returned `401 Current user is not logged in` after its first assertions. The local `.env.testing` credential was stale; resetting `employee1` to the password recorded in that ignored local file and minting a fresh `pettycash-contract-tests` app token restored the live-HTTP suite. This changed local lab state only, not a repository file. The rerun passed 17/17.

### Aggregate frontend gate

Command: `npm run check` at the milestone boundary.

Result: **passed**.

| Stage | Result |
|---|---|
| `check:openapi` | passed |
| `check:portability` | passed |
| `check:error-rate` | self-test OK (13 classification cases, 8 aggregation cases) |
| `typecheck` (`vue-tsc --noEmit`) | passed |
| `lint` | 0 errors, 261 warnings |
| `stylelint` | passed |
| `test` (Vitest) | **26 files / 536 tests passed** |
| `build` | passed (`✓ built in 19.50s`) |
| `check:notices` | OK — 29 PHP + 47 npm packages |

The 261 lint warnings are pre-existing warning-tier output; the gate reports zero errors. The build regenerated hashed `js/`/`css/` assets in the working tree; they are not part of this slice's source change and were not staged.
