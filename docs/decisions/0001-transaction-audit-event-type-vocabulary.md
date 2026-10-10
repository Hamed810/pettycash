# ADR: The transaction audit event-type vocabulary is derived from FR-051, and `AuditEventType` is its only source

- **Status:** Accepted
- **Date:** 2026-09-29
- **Task:** T213
- **Supersedes:** the previous contents of `lib/Domain/Shared/Enum/AuditEventType.php`

## Context

`AuditEventType` existed and was used by exactly one thing: `EnumColumnWidthTest`, which sizes the
`pettycash_transaction_audit.event_type` column from its values. No production code referenced it.

Meanwhile the services wrote five raw strings: `SUBMISSION`, `DECISION`, `ACCOUNT_CHARGED`,
`CORRECTION_RESUBMITTED` and `EVIDENCE_DEACTIVATED`. **One** of those five appeared in the enum.

So the column was being kept wide for a vocabulary nothing wrote, while the values actually stored were
invisible to every check that touched the enum. A raw string satisfies every other test in the suite, so
nothing detected the divergence. It surfaced only when T213 tried to add the event types FR-051 requires
and the two sources turned out to describe different things.

The enum was not simply a stale copy of the code either. Mapping FR-051's ten named transitions onto it
showed three gaps and one extra:

| FR-051 transition | enum | code wrote | problem |
|---|---|---|---|
| submissions | `Submitted` | `SUBMISSION` | rename only |
| routing | `Routed` | — | no writer |
| approvals | `Decided` | `DECISION` | **shared type with rejections** |
| rejections | `Decided` | `DECISION` | **shared type with approvals** |
| returns | `Returned` | `DECISION` | collapsed into the shared type |
| corrections | `Corrected` | `CORRECTION_RESUBMITTED` | rename only |
| revision creation | — | — | **no case, no writer** |
| transaction changes | — | — | **no case, no writer** |
| evidence additions | `EvidenceAdded` | — | no writer |
| evidence deactivations | `EvidenceDeactivated` | same | agreed |
| — | `Settled` | — | **not named by FR-051** |

Neither source could be declared authoritative on the strength of being already there. `Decided`
covering both approvals and rejections is the substantive disagreement: FR-051 names them separately, and
`Decision` is exactly `{APPROVE, REJECT, RETURN}`, so the domain corroborates three kinds rather than
two. `SETTLED` names nothing in FR-051 and duplicates a fact the FR-055 `ACCOUNT_CHARGED` configuration
event already records.

## Decision

1. **The vocabulary is derived from FR-051, not from either pre-existing source.** The ten cases are the
   ten transitions FR-051 names, in its order. Where the enum and the code disagreed with FR-051, FR-051
   won; where they disagreed with each other, neither was treated as a tie-breaker.

2. **`Decided` is split into `Approved`, `Rejected` and `Returned`.** An approval, a rejection and a
   return are three event types, so "every return this month" is answerable from the event stream rather
   than by scanning a JSON snapshot. The outcome is *also* retained in the `after` snapshot, because
   FR-052 requires the before-and-after values regardless of the type.

3. **`Settled` is removed.** Settlement is not an FR-051 transition. The charge is recorded as
   `ACCOUNT_CHARGED` in the configuration stream under FR-055, which is a different table with a
   different vocabulary.

4. **`RevisionCreated` and `TransactionChanged` are added.** FR-051 names both; the enum had no case for
   either, which is part of why nothing had ever written them.

5. **Producers pass `AuditEventType` cases, not literals.** This is the part that actually prevents
   recurrence. The previous state was not caused by carelessness so much as by there being no mechanism:
   a hardcoded string is valid PHP and fails no check. `AuditEventTypeConformanceTest` now scans `lib/`
   and fails on any hardcoded transaction `event_type`, so the type of the column and the values in it
   cannot drift apart again.

6. **The configuration stream is deliberately out of scope.** `ACCOUNT_CHARGED` and
   `RETENTION_POLICY_CHANGED` are FR-055. They live in `pettycash_configuration_audit` and are not
   modelled by this enum, and the conformance test names the two files allowed to hardcode them. Conflating
   the streams is what made the previous version of the enum misleading.

### Why this is code-only

Both audit tables were measured directly and hold **zero rows**
(`oc_pettycash_transaction_audit` and `oc_pettycash_configuration_audit`; the wrong prefixes error, so
their existence is not in doubt). Adopting the vocabulary therefore needs **no data migration and no
repair of existing rows**. An earlier draft of the T213 report claimed a migration would be required; that
was an assumption, and the measurement is what corrected it. Had the tables held rows, the same decision
would have needed an append-only correcting migration under FR-053, which is the more expensive path and
the reason the measurement was taken before choosing.

## Consequences

- Five producers changed the value they record. Because the tables are empty, nothing already written is
  invalidated and no consumer has data in the old vocabulary.
- `EnumColumnWidthTest` needed no change: it derives from the enum. A separate test now asserts that all
  ten values fit the `VARCHAR(48)` the migrations leave behind, so a longer future value fails a test
  rather than a workflow insert.
- The three decision-related values are now queryable by type, which is the behaviour FR-051 asked for and
  which no code path previously offered.
- `AuditEventType` is now load-bearing. Changing a case value is a data-affecting change, and
  `AuditEventTypeConformanceTest` will fail if producers and enum disagree — which is the point.

## Follow-ups this decision creates

- **`TransactionController::formatAuditEvent()` still reports `decision` as the literal string
  `'DECISION'`** whenever an event carries a decision id, so the API cannot yet distinguish the three
  outcomes even though the stored event type does. The outcome *is* now in the `after` snapshot, so the
  fix is to read it from there. It was left alone rather than shipped untested: the formatter is private,
  `TransactionController` extends `OCSController` which is not autoloaded in the unit or integration test
  bootstrap, and the contract fixture file that would have to seed a decision audit event deletes and
  re-seeds audit rows from several independent paths. That belongs with the work owning those fixtures.
- **`TransactionService::createTransaction()`, `updateTransaction()` and `EvidenceService::uploadEvidence()`
  are not command-wrapped**, so their events record a null `command_id`, which required
  `Version010400Date20260929000000` to make that column nullable. FR-052 does not list `command_id` among
  the fields an event must retain. Wrapping these transitions in `IdempotencyService` is **T317**, which is
  now done for the two `TransactionService` methods: their events carry a real command id. Only
  `EvidenceService::uploadEvidence()` still records null, and the nullable column remains correct for it. (First written as "T214", which was wrong: T214's four named handlers were already delivered by
  T149, and the two unwrapped handlers are in `TransactionService`, which T214 does not name. See
  `docs/reports/T214.md`.)
