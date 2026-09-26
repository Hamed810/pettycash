# Autopilot run — 2026-09-26 (session 2)

**Feature**: PettyCash V1 Core Workflow (`specs/001-pettycash-v1-core`)
**Status**: `in_progress` — converged-with-known-blocker, then 2 implementation passes completed
**Implementation passes executed**: 2 of 50
**Convergence passes executed**: 1 (initial)
**Predecessor**: `docs/reports/AUTOPILOT-2026-09-26.md` (passes 1–3, stopped on context)

## Controller state

```text
implementation_total = 2
implementation_since_convergence = 2
last_convergence_outcome = existing_tasks_remaining
```

## Environment note for the next session

`docker-nextcloud-1` and `docker-db-1` were **stopped** (exited cleanly ~20 min before the session
started, consistent with a host stop). Every PHP command failed with
`opcache-settle: could not read opcache settings from the container`. Recovery was the documented one
in `AGENTS.md` — `docker ps -a` to inspect, then `docker start docker-db-1 docker-nextcloud-1` on the
**existing** containers. No compose file, volume, or container was created, recreated, or modified,
and `scripts/dev/occ` was used read-only (`user:list`, `security:bruteforce:attempts`).

**If the next session finds the container stopped again, `docker start` is the fix.** Do not reach
for host PHP, and do not run compose from this repository.

## Initial convergence — outcome `existing_tasks_remaining`

**`tasks.md` was left byte-for-byte unchanged.** No `## Phase 38` was appended, because every
finding was already owned by an open task and appending would have duplicated T087, T096, T112,
T235, T236, T237 and the open US3–US8 phases.

| ID | Gap | Severity | Source | Owned by |
|---|---|---|---|---|
| F1 | T275 contract-vs-FR-060 contradiction | CRITICAL | FR-060 | T275 (must not be resolved) |
| F2 | 14 repositories have no real-MariaDB test | CRITICAL | FR-077 | T236 |
| F3 | 11 `configuration#*` routes, no controller | HIGH | FR-010–017 | T087 |
| F4 | no `ReportController`/`ExportController`/`Export/` | HIGH | FR-065–069 | T106–T112, T207 |
| F5 | `account#create`/`charge` → absent actions | HIGH | FR-015/040 | T087 |
| F6 | `evidence#links`/`deactivateLink` absent | MEDIUM | FR-031 | T096 |
| F7 | unrouted duplicate controllers | MEDIUM | Constitution III | T235 |
| F8 | `AuditRetentionPolicy` classes absent | MEDIUM | FR-057 | T237 |
| F9 | only one E2E spec exists | LOW | FR-071/072 | T054, T076, T089, T099, T114, T122, T171 |
| F10 | traceability matrix absent | MEDIUM | SC-001–014 | T134, T273 |

**Task state is honest.** A basename audit of all **150 checked** tasks found **0** false-checked:
the only two apparent misses resolve to T125's `scripts/security-check.sh` and T100's
`docs/audit-retention.md`, both open. `contracts/ocs-api.yaml` and `appinfo/routes.php` path surfaces
align 1:1 (only the `/api/v1` base-path prefix differs). The unused
`lib/Application/Port/Exporter.php` is **plan-mandated** (`plan.md:147` declares the export port), so
it is not `unrequested` — it is the expected state of open T107–T111.

## Blocker recorded, not worked around: the whole Playwright E2E chain

`.env.testing`'s `PETTYCASH_TEST_PASSWORD` is an **app-password token**
(`occ user:auth-tokens:list employee1` → `pc-ct-4`, `permanent`, scope `filesystem`, 72 chars
alphanumeric). It returns **200** from the OCS `me` endpoint, so it is valid — and the web login form
returns *"Wrong login or password."* while the server logs `Login failed: employee1`. That is the
designed behaviour: an app token authenticates Basic-auth/OCS callers and is never accepted by the
login form. Basic auth is not a way around it either — `GET /apps/pettycash/` answers **403**.

Two plausible causes were **ruled out by measurement**, so the next session does not repeat them:
bruteforce throttling (`occ security:bruteforce:attempts 172.18.0.1` read `attempts: 0, delay: 0`
after decay and the login still failed) and form truncation (the DOM value, the post-`fill()` value
and the `password=` field of the actual POST body are all byte-identical to the token, and the body
carries a `requesttoken`).

Blocked: **T171, T053** (its three browser-measured clauses), **T054, T066, T076, T089, T099, T114,
T122**. Unblocking needs a real browser-login credential — a credential mutation on the shared
environment, which an unattended agent must not perform. Full detail is recorded in T171's entry.

Also recorded there, so it is not rediscovered as a broken selector: **8 of the 26 `data-test`
selectors `purchaser-submit.spec.ts` uses do not exist in `src/`**, and `audit-event` is a **product
gap** — `src/views/sheets/SheetDetailView.vue:258` renders the audit table body as `<tr v-if="false">`,
so sheet-detail audit history renders a header and no events, against FR-051/FR-052/SC-006. That
render belongs to T098.

## Implementation pass 1 — T056 complete, T060 and T061 partly advanced

Full detail in **`docs/reports/T056.md`**. The headline: **`TransactionStatus::SeniorApproved` had no
producer anywhere in the codebase**, so every senior-approved transaction reached finance in a state
nobody could approve, and FR-038/FR-040/FR-043 were unreachable. Reverting that one line fails
**17 of 24** new tests.

Also fixed: FR-039 was broken in two halves (`assertCanApprove()` ran unconditionally, and the
capability endpoint offered an Approve button the command path refused); FR-040's consumption
movement was constructed and discarded; the MariaDB adapter cast a UUID to `0` and hard-coded
`source_decision_id` null; `lockInSettlementOrder()` existed but was absent from the port and called
nowhere; and `InMemoryAccountMovementRepository` collapsed every movement onto key `0`.

**Six mutations, each verified to have applied before its result was read.** Two of my own claims
were wrong and are corrected in the report — the over-strict aggregate invariant that broke 9 green
contract tests, and a capability key built as `'can' . $decision->value` where the enum value is
`APPROVE`.

### Gate movement

| Gate | Start of session | Now |
|---|---|---|
| `scripts/dev/phpunit` | 202 / 1006 / 1 failure (container down) | **229 / 1137 / 1 failure (T275 only)** |
| `FinanceSettlementTest.php` | absent | 24 tests / 116 assertions |
| `npx vitest run` | 214 / 214 | 214 / 214 |
| `npm run check` | exit 0 | exit 0 |

## Implementation pass 2 — T061 complete

Report: **`docs/reports/T061.md`**. T061's seventh effect, **sheet final state**, was missing:
`sheetRepository` was injected into the settlement and used only by the lock, so FR-043 never fired.

**The obvious fix would have been a silent no-op, and that is the finding.** A sheet loaded from
either repository carries **no transactions** — neither `DbExpenseSheetRepository` nor the in-memory
double hydrates the collection — and `recalculateStatus()` returns immediately on an empty list. The
method exists, looks correct, and cannot work for any caller that did not build the sheet in memory.
Reusing `addTransaction()` is unavailable because it refuses a sheet that is not open, and by
settlement time the sheet is long submitted. `ExpenseSheet::recalculateStatusFrom(array)` was added
so the caller states which transactions it means; the settlement loads them and recalculates after
the transaction is saved, inside the same transaction.

**A mutation found a real gap in my own work and it is recorded rather than smoothed over.**
Removing the `recalculateSheet()` call fails 3 tests, as expected. Removing the **empty-set guard**
failed *nothing* — 28/28 still green. Walking an empty set never clears `$allCompleted`, so the sheet
is declared `COMPLETED` from nothing. The settlement cannot reach that branch (the transaction it
settles necessarily carries the sheet id it looks up), so the guard is pinned by a direct test on the
aggregate, and the report says the settlement suite does not cover it.

Two of my own fixtures were wrong before the tests were: each transaction first got its own sheet,
which made FR-043 unobservable (a one-transaction sheet always completes), and the fixture attached
transactions with `addTransaction()`, which correctly refused the sheet once a settlement had
recalculated it.

## Task state

| Task | State |
|---|---|
| T056 | **complete** — all six named cases, six mutations |
| T061 | **complete** — all seven named effects, two more mutations |
| T060 | **open** — locking + movement insertion done and verified; the **reconciliation repository** has no port method and neither adapter has one, so it is a design decision rather than a missing class |
| T171 | **open, blocked** — recorded above |

## Gate movement across the run

| Gate | Start of session | After pass 1 | After pass 2 |
|---|---|---|---|
| `scripts/dev/phpunit` | 202 / 1006 / 1 (container down) | 229 / 1137 / 1 | **234 / 1170 / 1 (T275 only)** |
| `FinanceSettlementTest.php` | absent | 24 / 116 | **29 / 149** |
| `npx vitest run` | 214 / 214 | 214 / 214 | 214 / 214 |
| `npm run check` | exit 0 | exit 0 | exit 0 |

## Next pass

**T057** — `[US3]` contract tests for the shared finance queue and finance decision responses in
`tests/Contract/Controller/FinanceReviewControllerTest.php`. It needs a new `'finance'` state in
`seed-contract-fixtures.php` (a transaction at stage `FINANCE` / status `SENIOR_APPROVED` with
cost-centre and senior decision rows) **and** a `FINANCE` role for the contract user, whose current
roles are `PURCHASER`, `COST_CENTER_MANAGER`, `SENIOR_MANAGER`. Both are deliberate fixture changes
and must be checked against the other suites' baselines: the sheet-totals assertion depends on the
`'draft'` state having **no** decisions, so the finance decision rows must be seeded only for the
new state. T287 already asserted the shared-queue request shape on the Vue side, so T057's
server-side counterpart can reuse that framing.

Convergence is due at `implementation_since_convergence >= 5`, i.e. after pass 5. Do not converge
before then unless a material reconciliation need appears.
