# PettyCash Autopilot — Final Report (Cycles 1–5, stopped at safety limit)

**Feature**: PettyCash V1 Core Workflow (`specs/001-pettycash-v1-core`)
**Run date**: 2026-09-25
**Status**: **NOT converged** — stopped at the 5-implementation-cycle safety limit with work
remaining. This is not a completion stop; the authoritative remaining list is `tasks.md`.

## Why convergence was not reached

The cycle limit was reached, not a blocker. Every cycle made verified progress and the final
convergence pass still returned `tasks_appended`, so the loop stopped by rule rather than by
exhaustion of the work. The dominant reason the backlog is large is structural: **no test has ever
executed the real persistence layer** (open task T236, Constitution V). Every one of the 14
production repositories was substituted by an `InMemory*` double, so defects accumulated in
code that no test could reach. The T221 fixture seeding in cycle 3 is what finally exposed them.

## Task IDs completed during this run

| Task | Subject |
|---|---|
| T242 | Evidence upload: `getUploadedFile()` returns an array, not an object — production 500 on every upload |
| T243 | All 24 `@NoAdminRequired` annotations migrated to the Nextcloud 34 attribute form |
| T244 | Invalid RED state: `setFiles()` does not exist on `DOMWrapper` in `@vue/test-utils` 2.4.6 |
| T188 | `DbAccountRepository` malformed alias SQL, missing `public_id`, identity corruption; `DbAccountMovementRepository` hardcoded `account_id = 1` |
| T246 | Every inserted aggregate claimed primary key 1 (`executeStatement()` returns row count, not insert id) |
| T247 | Evidence checksum read-back inside the open transaction — upload could never succeed |
| T248 | Revision update wrote `updated_at`/`updated_by` columns the table does not have |

T221 remains **open** and is the single highest-leverage next task.

## Convergence tasks appended

- **Phase 23** — T242, T243, T244, T245
- **Phase 24** — T246, T247, T248
- **Phase 25** — T249

Append-only was respected: no existing task was rewritten, renumbered or deleted.

## Final test state

| Suite | Start | End |
|---|---|---|
| `scripts/dev/phpunit` | 140 tests, 291 assertions, **29 failures** | 140 tests, 413 assertions, **16 failures** |
| `npm run test:unit` | 76 tests, 17 passed, 59 failed | 76 tests, **18 passed**, 58 failed |
| `npm run typecheck` | 22 errors | **20 errors** |
| `npm run lint` / `stylelint` / `build` | pass | pass |
| `node scripts/portability-check.js` | OK | OK |

No test was suppressed, skipped or excluded to reach this state.

## Verification performed

All PHP/Nextcloud work ran through the approved wrappers (`scripts/dev/phpunit`,
`scripts/dev/in-container`); all frontend work ran on the host via the `package.json` scripts. No
host PHP was probed and no `docker exec` was hand-built.

Defects were confirmed against the **running container**, never inferred: a marked
`nextcloud.log` offset was taken, the suite re-run, and only the new entries inspected. A clean
full run ends with zero `pettycash` exceptions and zero annotation warnings.

## Task reports created

`docs/reports/T242.md`, `T188.md`, `T221.md`, `T246.md`

## Remaining work: 143 open tasks

**Blocking the current path:**

- **T249 (CRITICAL)** — `pc_transactions.current_status` is `VARCHAR(24)` but
  `TransactionStatus` defines `AWAITING_COST_CENTER_REVIEW` (27) and
  `AWAITING_PURCHASER_CORRECTION` (29). Seeding a submitted transaction fails with
  `1406 Data too long`. The two most important workflow states are unpersistable, so FR-020
  submission and FR-046 correction are unreachable regardless of application code. Needs a
  forward-only migration. Every other enum-valued column was audited and is fine.
- **T221** — finish the contract fixtures. The 16 remaining PHP failures are test-side: stale
  `expectedVersion` values, a missing cost-center-manager role assignment, and evidence tests that
  must capture a real evidence id from an upload.
- **T236 (CRITICAL)** — persistence integration tests against real MariaDB. This is the root cause
  of the whole class of defects fixed here.
- **T245** — the remaining 20 typecheck errors, all in the intentional US2 RED suite; closes with T181.
- **T072 / T200** — the last two `EvidenceService` placeholders (`originRevisionId` hardcoded to `1`,
  and `deleteDraftEvidence` with no transaction-state check, which physically deletes an AppData file
  even for submitted transactions).

**Warnings / limitations**

- The container runs `opcache` with `revalidate_freq => 60`; a PHP edit can take up to a minute to
  reach live HTTP. A single unchanged failure reading is not evidence on its own.
- `npm run check` still fails, at `typecheck` and `test:unit` only, both because of unbuilt US1/US2
  UI tracked under T164/T181.
- The 18 passing frontend tests do not imply the US1 UI is complete; the purchaser-flow suite is
  largely a RED suite awaiting T164.
