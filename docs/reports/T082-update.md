# T082 (reopened scope) — `CostCenterService::update()`

**Status:** complete. `specs/001-pettycash-v1-core/tasks.md`. Closes the scope reopened 2026-10-01.
Report for the original delivery is in the task entry itself.

## Why this task was reopened

`FR-010` requires administrators to "create, **update**, activate, and deactivate" cost centers,
`contracts/ocs-api.yaml` declares `PATCH /configuration/cost-centers/{costCenterId}`, and
`configuration#costCenterUpdate` had been a registered route since before T082 landed — with no
service method behind it. T081 and T083 each delivered `update` from the same "administration" wording
in their task text; T082 did not.

Activation and deactivation were **not** separately missing: `CostCenterWrite` carries `active` and the
contract has no second route, so one write expresses FR-010's remaining three verbs.

## Files changed

| File | Change |
|---|---|
| `lib/Application/Configuration/CostCenterService.php` | **`update()` added**; `create()`'s audit subject changed to the public id |
| `lib/Application/Audit/ConfigurationEventType.php` | `CostCenterUpdated` case |
| `lib/Infrastructure/Persistence/Configuration/DbCostCenterRepository.php` | `save()` now writes `code` on update and publishes the advanced `lock_version` |
| `tests/TestDoubles/InMemoryCostCenterRepository.php` | mints `public_id`; advances `lock_version` |
| `tests/TestDoubles/InMemoryTransactionManager.php` | `isTransactionOpen()` |
| `tests/Unit/Application/Configuration/CostCenterServiceUpdateTest.php` | **new** — 31 tests / 61 assertions |
| `tests/Integration/Audit/ConfigurationMutationAuditTest.php` | cost-centre subject assertions |
| `tests/Integration/Persistence/roundtrip-runner.php` | update observations + unwritable cost-centre service |
| `tests/Integration/Persistence/RepositoryRoundTripTest.php` | 5 real-MariaDB assertions |

No migration. The delivered `create()` / `transferManager()` / `listValidOnForCaller()` /
`currentManagersOn()` are untouched; `CostCenterServiceTest` was not modified at all.

## Update semantics delivered

- **Keyed by `costCenterId` (uuid)** via `findByPublicId()` — the contract's own identity. Deliberately
  unlike `CurrencyService::update()` (`code`) and `ExpenseTypeService::updateByTitle()` (`title`), which
  key by natural key because neither resource has a mutable one.
- **`expectedVersion` required**, checked against `lockVersion`, refused via
  `ConfigurationConflict::staleVersion()`. A stale write is never applied to a moved-on row and emits no
  event.
- **FR-010's mutable fields**: `code`, `name`, `description`, `validFrom`, `validTo`, `active`,
  `managerUserId`, `seniorManagerUserId`.
- **Administrator-only**, via the class's existing `requireAdministrator()`.
- **Domain validation retained** — the update builds through `CostCenter::create()`, so a blank name or
  inverted window is refused by the domain rather than by a copy of its rules.
- **Manager changes are transfers.** Delegated to `transferManager()`, closing the outgoing assignment at
  `$validFrom - 1 second`. Resending the current holder is treated as *unchanged*, because
  `CostCenterWrite` requires both fields and treating a resend as a transfer would churn the history
  FR-011 rests on on every rename.
- **`changeReason`** propagated; one `COST_CENTER_UPDATED` event through
  `ConfigurationAuditService::record()`. A manager change also emits the existing
  `COST_CENTER_MANAGER_TRANSFERRED` event — one event per changed subject, which is why that case is two
  events and a plain update is one.

## Audit `entity_id` decision

**The uuid, not the code.** Evidence:

| Consideration | Finding |
|---|---|
| Is `code` stable? | No — FR-010 names it updatable, `CostCenterWrite` carries it |
| What does the contract use? | `costCenterId: { type: string, format: uuid }` |
| Does the audit model permit a uuid? | Yes — `entity_id` is `string(64)`, and `ACCOUNT_CHARGED` already stores a uuid |
| Does `data-model.md` dictate a value? | No — only the `entity_type`/`entity_id` pair, never the value |
| Does anything require `code`? | No artifact. T086's use of `code` was **my** choice, not a requirement |

So this was a correction, not a conflict, and `create()` was changed to match so both event types name the
same centre the same way. Seven `cost_center` audit rows keyed by code remain in the table — residue from
T086's own round-trip runs, whose cleanup did not track cost-centre audit rows. They are **not** rewritten:
FR-053 makes audit rows append-only, and hand-deleting them is not this task's to do.

## Two persistence defects the rename required

Neither was visible to any test beforehand.

1. **`save()` never wrote `code` on update.** A rename reported success, advanced `lock_version`, and left
   the row keyed by the old code — the new code resolved to nothing while the old one still resolved to
   the centre just renamed. The in-memory double accepts the aggregate with any code, so only the real
   database could show it. This is the same family of defect as the `manager_uid` columns T082 was
   originally reopened over.
2. **`save()` never published the advanced `lock_version`.** The contract's `CostCenter` response must
   carry `version`; returning the superseded one means a client echoing it straight back into
   `expectedVersion` is refused for the write it just made.

## Two more defects, in the test doubles

- The double minted no `public_id` where the adapter does.
- **The double never advanced `lock_version`.** More serious: an update left the version at 0, so the
  `expectedVersion` guard could never fire and a stale write silently overwrote the winner. Every
  optimistic-concurrency test for this resource would have passed against a repository that does not
  behave like the one production uses.

This is the third time in this work that a double diverged from its adapter (role-assignment sparse
arrays, cost-centre `public_id`, now `lock_version`). The pattern is consistent enough to be worth a
standing check.

## Transaction / rollback evidence

- Unit: the audit append is asserted to occur **while the transaction is open**, not merely that an event
  exists.
- Real MariaDB, real `NextcloudTransactionManager`, `FailingAuditWriter` around the real
  `AuditWriterService`: a refused audit leaves **no** committed cost-centre row.
- Nested transactions were verified before relying on them: a nested commit persists both writes, and a
  nested failure propagates the original exception unmasked while discarding both.

## Mutation results — 8 of 8 killed

| # | Mutation | Result |
|---|---|---|
| M1 | remove the stale-version guard | **3 failures** |
| M2 | key the lookup by code instead of the uuid | **24 errors + 2 failures** |
| M3 | skip the duplicate-code check | **1 failure** |
| M4 | skip the senior-manager reassignment | **1 failure** |
| M5 | drop `changeReason` | **1 failure** |
| M6 | remove the audit append | **26 failures** |
| M7 | move the audit **outside** the transaction | **1 failure** |
| M8 | swallow the audit failure | **2 failures** |

**M7 survived the first version of these tests.** The original assertions proved a transaction was
*opened* and that an event *existed*; an audit appended after the commit satisfies both while committing
the change unrecorded — precisely what FR-017 forbids. `InMemoryTransactionManager::isTransactionOpen()`
was added and the test rewritten to assert the append's position rather than its existence. The same
weakness was in `ConfigurationAuditServiceTest`, whose ordering assertion covered the snapshot callbacks
but not the append.

## Full PHP suite

```
Tests: 1052, Assertions: 4426, Failures: 1, Deprecations: 5
```

The one failure is the **pre-existing T275** one, unchanged
(`application/octet-stream` vs `application/pdf`). 1052 − 1015 = 37 = 31 unit + 5 real-database + 1 audit
subject. **No new failures.**

## Ledger

304 task lines, 304 unique ids, no duplicates. **29 checked, 70 unchecked.** Phase 7: T081–T084 and
T086 `[X]`; T077–T080, T085, T087 `[ ]`.

## Is T087 now fully unblocked?

**Yes.** All eleven configuration routes now have a real application service behind them —
`costCenterUpdate` was the last one without. T087's prerequisites read T081–T084 `[X]`, T085 `[ ]`
(ordering only, unrelated to the routes), T086 `[X]`. The controller is no longer at risk of being
hollow, and nothing outside T087 remains outstanding for the configuration surface.

Boundaries held: no `ConfigurationController`, `KNOWN_UNRESOLVED` untouched, T085 not implemented, the
T084 uniqueness gap not touched, no migration, no commit.