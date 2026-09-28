# T299 / T112 accounts scope — "accounts charged" is derivable

**Status:** both complete
**Suite at completion:** 648 tests, 2872 assertions, 1 known baseline failure (T275), no new failures

## What was blocked

FR-055 requires configuration audit to be visible to finance officers "for accounts they charge", and
no part of the schema or any port could answer that question. T106 hit it and stopped rather than
inventing a way, because the one row holding the answer was
`pettycash_command_results.actor_uid`, reachable one command at a time, and deriving from there would
have coupled `IdempotencyService`'s port to a reporting concern.

T299 offered two routes and said the choice belonged to the schema, not to a report:

1. add `charged_by` to the movements table — a migration, therefore an approval-gated baseline reset
   on shared infrastructure;
2. enumerate the command log — no migration, but a reporting concern on idempotency's port.

## Neither was needed

T294 landed first and wrote a real `ACCOUNT_CHARGED` event to the configuration audit stream naming the
account in `entity_id` — the record FR-017 asks for anyway. So the derivation reads **the audit trail
itself**: no migration, no approval-gated reset, and `IdempotencyService`'s port untouched. The trap
T106 stopped short of is gone rather than worked around. T299's own closing paragraph anticipated
exactly this: T294 "decides whether there is anything for this to scope over".

## What landed

**`AuditEventRepository::findEntityIdsByEventType()`** — the distinct subjects an event type has been
recorded against. A read, in the same family as the other six. On the transaction stream it **throws**
rather than returning nothing, because "no entity ids" and "wrong table" are opposite conclusions and
only one is safe to act on.

`RetentionPolicyTest::testTheAuditPortOffersOnlyReadsAndAnAppend()` asserts the port's exact method
list as data, so it was updated to include the new read. The invariant it protects is unchanged: the
port gains reads and one append, and still offers no way to delete or rewrite an event.

**`ChargedAccountScope`** (`lib/Application/Reporting/`) — the business rule, in the application layer
rather than the port. Turns entity ids into account surrogate keys. It deliberately has no notion of a
"current officer": FR-016 puts every finance officer on one shared queue, so a charge is an
instance-wide fact and an officer may read configuration audit for accounts charged *at all*.

A charge naming an account that no longer resolves is **dropped**, not reported:
`ReportQuery::configurationAuditScope()` widens from the ids it is handed, so a fabricated id would
widen the visible set to an account nobody can name. An instance with no charges yields an empty list,
never "everything" — the direction that has to fail safe.

**`ReportController::accountsReport()`** now applies the clarified rule: own accounts; finance
additionally sees charged accounts; administrator sees all. Two deliberate details:

- an account that is both the caller's own and charged is listed **once** — a row listed twice would
  double both the count and the balance;
- the `t299: pending` marker is **gone**. It said the scope was unresolved; leaving a pending marker
  on a resolved rule would let a caller mistake one for the other.

The summary now reports `scope`, `chargedAccountsIncluded` and `chargedAccountCount`.

## Verification

The contract test proves the real path end to end: a finance approval in `FinanceReviewControllerTest`
writes a charge against the shared database, and `ReportAndExportControllerTest` then finds that exact
account in the officer's accounts report — reachable only through this derivation, and only because
T294 recorded the charge.

That test is residue- and order-dependent by nature, so `ChargedAccountScopeTest` (7 tests) covers the
derivation deterministically, including what a happy path hides: a charge by another officer still
counts (FR-016), repeated charges yield one id, an unresolvable account is dropped, an empty instance
is not "all", another event type on the same account is not a charge, and an entity-idless event names
no account.

| Mutation | Caught by |
|---|---|
| unresolvable charge becomes a scope member | `testAChargeNamingAnUnresolvableAccountIsDropped` |
| real-DB read filters the wrong event type | `testTheAccountsReportIncludesTheAccountsAChargeHasNamed` |
| report stops including charged accounts | `testTheAccountsReportIncludesTheAccountsAChargeHasNamed` |

The contract test asserts the **effect**, not the label. It locates the settled account inside the
returned rows and cross-checks that row's balance against the accounts endpoint, because a summary
reading `own_and_charged_accounts` while returning only the caller's own rows would satisfy a string
comparison and still fail FR-016.

## Defect fixed in passing

`InMemoryAccountRepository::save()` keyed by public id while `findById()` indexed by surrogate key, so
`findById()` answered null for a fixture just stored. A defect in the double rather than the behaviour
under test, but it made the new by-surrogate-key path untestable.

## A process note

A mutation run exceeded its timeout and the command was killed **before** its restore step, leaving the
mutation live in the source. `git diff --stat` could not reveal it because the backup had been taken
after the same batch's additions, so the line count was identical. It was caught only by re-grepping
for the mutated token. A mutation harness that times out can leave the tree dirty in a way a diff will
not show, so the restore is worth asserting explicitly rather than assuming.

## Files changed

- `lib/Domain/Audit/Repository/AuditEventRepository.php` — the new read
- `lib/Infrastructure/Persistence/Audit/DbAuditEventRepository.php` — implementation, stream-refusing
- `lib/Application/Reporting/ChargedAccountScope.php` — new; the derivation
- `lib/Controller/ReportController.php` — accounts scope, dedup, truthful summary
- `lib/AppInfo/Application.php` — registrations
- tests: `ChargedAccountScopeTest` (new), `ReportAndExportControllerTest`,
  `InMemoryAccountRepository`, `InMemoryAuditEventRepository`, `RetentionPolicyTest`

## Known limitations

- The contract test depends on charge rows in the shared database, by nature. It fails closed if no
  charge exists, but it is not self-contained; `ChargedAccountScopeTest` is the deterministic cover.
- `findEntityIdsByEventType` pages at 1000 by default. A deployment with more distinct charged
  accounts than that would need the caller to page; no caller needs to yet.
- Configuration audit is still not exposed as a report subject — that is T093, which builds on this.

## Next

T093 (role-scoped configuration-audit query) now has the data it was waiting on. T086 (before/after on
every configuration mutation) also remains open and unblocked.
