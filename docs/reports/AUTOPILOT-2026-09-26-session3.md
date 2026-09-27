# Autopilot run — 2026-09-26 session 3

**Feature**: PettyCash V1 Core Workflow (`specs/001-pettycash-v1-core`)
**Status**: 4 implementation passes + 1 early convergence
**Implementation passes executed**: 4 of 50
**Convergence passes executed**: 2 (the initial one in session 2, plus an early one here)
**Predecessors**: `docs/reports/AUTOPILOT-2026-09-26.md` (passes 1–3), `AUTOPILOT-2026-09-26-session2.md` (passes 1–2)

## Controller state

```text
implementation_total = 4
implementation_since_convergence = 0
last_convergence_outcome = tasks_appended
```

## Why convergence ran early (after pass 4, not pass 5)

The scheduled interval was one pass away. The early-convergence condition that applied is
"implementation reports that no actionable task can be selected even though convergence said work
remains", and it is worth stating precisely, because *not every remaining task is blocked* — only that
none of them is safely actionable without a decision this agent must not make:

| Task | Why it is not actionable |
|---|---|
| **T060** | Needs a maintainer to say what "reconciliation" means. `AccountRepository` declares no reconciliation method and neither adapter has one, so there is nothing to implement against. |
| **T065, T171 and the E2E chain** | Blocked on the browser-login credential, already recorded and unchanged. |
| **T087, T096, T106–T112** | Large multi-endpoint admin/reporting surfaces, each with a design question (validation rules, FR-017's configuration audit on every change) that the artifacts do not settle. Building them on a guess is what produces a second `TransactionPayloadFormatter`. |
| **T235, T236, T237, T134, T273** | Repository-wide reconciliation in nature — convergence's work, and now measured by it. |

Convergence confirmed this quantitatively and appended only what was unowned, so the outcome is
`tasks_appended`, not `converged`.

## Convergence measurement worth carrying forward

**15 of the 41 declared routes in `appinfo/routes.php` resolve to a method that does not exist and
answer HTTP 500**: `evidence#links`, `evidence#deactivateLink`, all 11 `configuration#*` (no
`ConfigurationController` exists), `report#show` and `export#create`. Every one is already owned by an
open task, so nothing was appended for it — but this is the **third independent instance of one defect
class** (`account#create`/`account#charge` in T063, the finance decision path in T057), and the reason
is always the same: a declared route that answers 500 is invisible until something calls it. Both new
test files therefore begin with a `…IsReachable` assertion. A reachability assertion per remaining
declared route is cheap and would have caught all 15.

Also confirmed: **no other controller mis-orders its `catch` chain**. The five that catch only
`\Throwable` delegate to `OcsErrorMapper::map()`, which resolves the same `InvalidArgumentException`
before `LogicException` precedence correctly. The T063 catch-order defect was local to the chain that
pass introduced.

## The two passes in this session

### Pass 3 — T057, complete (`docs/reports/T057.md`)

21 contract tests over the shared finance queue and the finance decision responses. The headline is
not the test count: **the finance approval path had never worked over HTTP**, and three defects stood
in the way of it.

1. **No workflow decision was ever persisted.** Both review services carried a
   `// Persist transaction and decision` comment above a call that writes only the transaction row.
   `DbWorkflowDecisionRepository` was registered, injected for reads, and never called for a write.
2. **A finance approval answered 422 for every input** — the consumption resolved its
   `source_decision_id` before the decision row existed, and the movement's public id was
   `<account>-<command>`, 73 characters in a 36-character column.
3. **The decision response was a bare `TransactionDetail`**, not the contract's `DecisionResult`, so
   the decision taken and the money moved were both absent — and `src/stores/reviews.ts:93` reads
   `result.transaction`, so the finance view showed `undefined` after every decision.

Plus: a replayed decision was answered **409** (the idempotency payload carried a stage the first
attempt had advanced), the missing-explanation field error never attached (`str_contains` against a
capitalised message), and a settled transaction reported `stage: FINANCE` beside
`status: COMPLETED_APPROVED`.

### Pass 4 — T063, complete (`docs/reports/T063.md`)

15 contract tests over `POST /accounts` and `POST /accounts/{id}/charges`, which had answered 500
because the routes were declared and the methods were not. FR-015's finance gate and FR-013/014's
create rule are in the service, not the controller. A charge records a movement with the caller's
description and `occurredAt`, runs the command id through the idempotency service so a replay is
answered from the stored result, and checks `expectedVersion`.

**Its own first test failed for an instructive reason**: the acting user already held IRR and USD
accounts, and FR-014 allows one per currency, so *every* create collided with the seed. A 409 was the
first thing the suite saw, and reading that as "creation is broken" would have been the wrong
conclusion — a third currency was the fix.

## Two honest gaps this run produced, both recorded rather than worked around

- **T291** — the FR-015 finance gate is implemented and **removing it fails no test**, because the only
  fixture user of those tests holds `FINANCE`. The charge tests *have* to prepare the `finance` state
  precisely because the rule is real, so no HTTP request in the file can be a refusal. This is the
  single-identity limit seen from a second direction (T171 is the first), and it is a harness gap.
- **T289** — the account/movement projection now exists in `AccountService` and in
  `AccountPayloadFormatter`. T057 could not make the service delegate without inverting the layer
  dependency, and the formatter's docblock says so rather than leaving the duplication looking
  accidental.
- **T290** — a replayed finance decision reports the decision and the transaction but not
  `accountMutation`, because the stored command result predates the member. T063's charge does *not*
  have this problem: it stores the rendered mutation, which is the pattern T290 should adopt.
- **T293** — `T184` is still `[ ]` while its file exists and its child `T056` is complete. The last of
  that shape, after `T185` was corrected this run.

## Gate movement across the run

| Gate | Session 2 end | After pass 3 (T057) | After pass 4 (T063) |
|---|---|---|---|
| `scripts/dev/phpunit` | 234 / 1170 / 1 failure (T275) | 255 / 1367 / 1 failure | **270 / 1435 / 1 failure (T275)** |
| `FinanceReviewControllerTest.php` | absent | 21 / 196 | 21 / 196 |
| `AccountWriteControllerTest.php` | absent | — | 15 / 68 |
| `npx vitest run` | 214 / 214 | 214 / 214 | 214 / 214 |
| `npm run typecheck` / `lint` | clean | clean | clean |

The single failure is **T275** throughout — the known contract-versus-FR-060 contradiction on the
evidence content type, deliberately unresolved and awaiting maintainer input. It reproduces in
isolation against code neither pass touched.

## Verification discipline

Twelve mutations across the two passes, each applied, **verified to have applied before its result was
read**, run, and reverted. Three survived and are recorded as gaps rather than papered over: the FR-015
gate (T291), and the two mutually redundant duplicate-account guards in T063, where removing either
alone leaves the 409 intact and only removing both breaks it.

Two mistakes this run made and corrected, recorded because both nearly produced false evidence:

- A revert loop restored the wrong backup, leaving four mutations applied while the "measurement"
  showed 14 failures. It was caught by asserting on the restored file's markers and re-running, and
  every number above was taken after that.
- A seeder failure is **silent**: the `finance` state exited 0 with no manifest, which
  `ContractFixtures` reports as "seeding produced no output" rather than as a schema error. The cause
  was a `created_by` column on `pettycash_sheets` that the table does not have.

## Environment

`docker-nextcloud-1` and `docker-db-1` were **up** at session start (54 minutes). No compose file,
volume, or container was created, recreated, or modified. `scripts/dev/phpunit` settled the opcode
cache before every run and no result in this report was read without it.

One operational note for whoever reads the log next: `/var/www/html/data/nextcloud.log` is **1.2 GB**
and still being appended to. It was not truncated — that is shared state and not this repository's to
trim — but reading it means `tail -c`, never `cat`.

## Next pass

**T292** — give `OcsErrorMapper` the vocabulary for a plain conflict, or record the rule that a new
conflict endpoint hand-rolls its own catch chain. Small, safe, and dependency-free.

After it, **T293** (bookkeeping). Then the run needs a maintainer decision before it can go further:
**T060**'s reconciliation semantics, and a second acting identity for the harness (**T171**/**T291**).
The configuration, evidence-link, and report/export surfaces (T087, T096, T106–T112) are buildable but
each carries a design question the artifacts do not settle, and are better started as an explicit,
scoped decision than as an implementation pass.
