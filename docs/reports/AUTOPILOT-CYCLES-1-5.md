# Autopilot Cycle 1–5 Implementation Report

**Feature**: PettyCash V1 Core Workflow (`specs/001-pettycash-v1-core`)
**Date**: 2026-09-25
**Outcome**: stopped at the 5-cycle safety limit (not `converged`)
**Tasks completed**: T225–T234 (10 tasks across Phases 15–19); T235 appended, open

## Cycle trace

| Cycle | Convergence | Implementation |
|-------|-------------|----------------|
| 1 | `tasks_appended` → Phase 15 (T225–T228) | T225, T226, T227, T228 |
| 2 | `tasks_appended` → Phase 16 (T229, T230) | T229, T230 |
| 3 | `tasks_appended` → Phase 17 (T231, T232) | T231, T232 |
| 4 | `tasks_appended` → Phase 18 (T233) | T233 |
| 5 | `tasks_appended` → Phase 20 (T235) | **safety limit — stopped** |

## Tasks completed

| Task | What changed |
|------|--------------|
| T225 | Body/header params on `SheetController`/`TransactionController`/`EvidenceController` made nullable with explicit validation, so the AppFramework `Dispatcher` no longer throws before the action body |
| T226 | `TransactionController::revisions()`/`audit()` resolve the public UUID via `TransactionService::getTransaction()` and pass the internal join key |
| T227 | `OcsErrorMapper` wired into all three controllers; added `validation()`/`notFound()`/`forbidden()`/`conflict()`; `LogicException`→403; payload matches the contract `Error` schema; unexpected failures now go through `TechnicalLogger` |
| T228 | `*.bak` / `opencode.local.json` ignored; the backup contains an internal LAN endpoint and must never be committed |
| T229 | `DbTransactionRevisionRepository::save()` uses the public `expenseDate()` accessor instead of the private promoted property |
| T230 | `TransactionRevision::$transactionDbId` added; `save()` fails fast instead of writing a placeholder; `TransactionService` writes transaction → revision → back-link in one DB transaction |
| T231 | New `SessionController` (`me()`, `dashboard()`); `sessionApi.ts` added; `auth.ts` wired to `SessionContext` |
| T232 | `DbWorkflowDecisionRepository` resolves the transaction via the revision's own `transaction_id`, not `active_revision_id` |
| T233 | `contracts/ocs-api.yaml` `SessionContext.roles` enum corrected `ADMIN` → `ADMINISTRATOR` |
| T234 | `DbWorkflowDecisionRepository::mapRow()` hydrates real `transaction_id` / `revision_id` UUIDs |

## Recorded deviation (T226)

T226 as written said to change the repository signatures to accept UUIDs. That misread
`data-model.md` line 8 (*"numeric `id` values remain internal join keys"*), so the signature change was
implemented and then reverted; the fix was applied at the transport layer instead. Recorded per
constitution Governance ("reconcile the conflict in the authoritative artifact rather than choosing
silently"). See `docs/reports/T225.md`.

## Verification

**Live authenticated probes** (Nextcloud 34, `docker-nextcloud-1`, user `employee1`):

| Probe | Before | After |
|---|---|---|
| `POST /sheets` missing `accountId` | `400`, **empty body** | `422` + `{code, message, commandId, fieldErrors}` |
| `POST /transactions/{id}/correction` missing `expectedVersion` | `400`, **empty body** | `422` + envelope |
| `GET /transactions/{id}/revisions` | `500` (TypeError) | `404` `NOT_FOUND` |
| `GET /transactions/{id}/audit` | `500` (TypeError) | `404` `NOT_FOUND` |
| `GET /me` | `500` (unresolved controller) | `200` `SessionContext` |
| `GET /dashboard` | `500` (unresolved controller) | `200` role-scoped |
| Dispatcher `TypeError`s in log | 6 distinct | **0** |

**New test** `tests/Integration/Workflow/TransactionRevisionPersistenceTest.php` (3 tests, 6 assertions)
proven load-bearing: reverting T230 turns it RED with
`LogicException: Cannot persist a transaction revision without its owning transaction id`;
restoring it turns it GREEN.

An earlier "verification" of T230 was hollow — the 35 workflow tests passed even with the fix
reverted because they never call `createTransaction`. That was detected and corrected with a
targeted test rather than reported as a pass.

**Gate state (unchanged from cycle 1 baseline — no regressions):**

| Command | Result |
|---|---|
| `scripts/dev/phpunit` | 140 tests, 291 assertions, **29 failures** |
| `npm run typecheck` | 22 errors, all in `PurchaserFlow.spec.ts` / `ManagementReview.spec.ts` |
| `npm run lint` | PASS |
| `npm run stylelint` | PASS |
| `npm run build` | PASS |

## Why convergence was not reached

Autopilot stopped at the 5-cycle safety limit, not at `converged`. Each cycle closed the
defects that authenticated traffic and the residue sweep could prove, but the feature still has
**138 open tasks** — the bulk of US2–US8 backend/frontend, reporting/export, cross-device UI, and
the Phase 11 release gates. The remaining known defects are all covered by those open tasks, so
convergence will keep returning `tasks_appended` until implementation works through them.

Outstanding defects deliberately **not** re-appended because open tasks already own them:
`DbAccountMovementRepository` hardcoded `$accountId = 1` (T188, financial integrity),
`DbAccountRepository` malformed currency join causing the sheet-create 500 (T188),
`/queues/{stage}` 500 (T178), `ReviewController` ad-hoc error payloads (T179/T191).

Non-blocking limitations: the 29 PHP failures and 22 typecheck errors are unchanged from the
pre-autopilot baseline; `DbTransactionRevisionRepository:100`'s former LSP error is resolved.
