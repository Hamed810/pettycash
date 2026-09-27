# Audit Retention

**Requirement**: FR-057, and the append-only and non-deletable guarantees of Constitution IV.
**Status**: Implemented. T237 (the subject), T138 (the verification), this document.
**Last reviewed**: 2026-09-27

## What this document is for

FR-057 requires that any configurable audit retention policy preserve the non-deletable historical
record for a minimum of seven years, and that the policy "may govern archival or availability but MUST
NOT rewrite business history". This document states what that means operationally, what is actually
enforced in code, and — just as importantly — what is **not** enforced, so that nobody later reads a
green test suite as a stronger guarantee than it provides.

## The one-paragraph answer

PettyCash keeps business audit records for **at least seven years and indefinitely thereafter**. The
retention period is configurable upward and cannot be configured below seven years. Nothing in
PettyCash archives, deletes, truncates, or rewrites a business audit record — at any age, by any role,
including an administrator. The period governs only how long a record must remain served from primary
storage; past that point it *may* be moved to cold storage, and even that is not implemented in V1.
Seven years is therefore a **floor on how long history is preserved**, never a countdown to its
deletion.

## What "business audit" covers

FR-056 requires two **separate, append-only** record streams, distinguishable from each other and from
technical logs. Both tables exist in the schema. Only one of the two is actually written, and the
distinction is **not yet implemented**:

| Stream | Table | Required records (FR-017, FR-051) | Current state |
|---|---|---|---|
| Transaction audit | `pettycash_transaction_audit` | Submission, routing, decisions, returns, corrections, revision creation, transaction changes, evidence additions and deactivations (FR-051) | **Written.** `DbAuditEventRepository` targets this table. |
| Configuration audit | `pettycash_configuration_audit` | Sensitive changes to assignments, expense types, currencies, access assignments and control settings (FR-017) | **Not written.** Nothing in the application writes this table. `AuditWriterService::appendConfiguration()` exists but is never called, and because `DbAuditEventRepository::save()` hardcodes `pettycash_transaction_audit`, calling it would put a configuration event into the *transaction* stream. |

**This does not weaken the retention guarantee.** The records that do exist are retained on exactly the
terms described here, and the guarantees in the next section are verified against the real schema and
the real repository. What is missing is the *separation* FR-056 requires, so configuration audit
events are not yet written to their own stream at all. The table is created, empty, and covered by the
same schema-level guarantees as its sibling. Tracked by T086 (write), T093 (query), T253 (subject
identity), and T139 (verification).

Each event retains event time, actor identifier, actor display name **at the time**, actor role **at
the time**, workflow stage, event type, decision, explanation, and applicable before-and-after values
(FR-052). Disabling or removing a user does not alter any of it (FR-054, SC-012).

**Technical logs are not business audit.** Nextcloud's own logging is the record of technical errors
and is not covered by this policy, is not append-only in the business sense, and must never be used as
the historical record. A business event that fails to write is a defect to be fixed, not something to
compensate for with a log line.

## How the policy is configured

Through `RetentionPolicyService`, which is the only supported entry point:

```php
$service->configure(15, 'Regulatory hold for FY2026');
```

Two things are worth knowing before calling it:

**A period below seven years is refused, not clamped.** `AuditRetentionPolicy::of()` throws
`InvalidArgumentException` rather than quietly substituting seven. A caller that asked for three years
has made a mistake that ought to be visible; silently substituting a different period would put a
change into the configuration audit that the administrator never asked for.

**Every accepted change is a control-setting change and is audited.** FR-017 names "control settings"
explicitly, so each write appends a configuration audit event carrying the actor, the time, the
explanation, the previous period and the new one. The value is written first and the audit event
second, in that order, so the recorded history can never claim a change that did not take effect.

Policy metadata is stored in Nextcloud application configuration under the single key
`audit_retention_policy`, as one JSON document. It is one document rather than three keys so a partial
write cannot leave a policy whose `updatedAt` no longer describes its `retentionYears`.

## What is enforced, and where

| Guarantee | Enforced by | Verified by |
|---|---|---|
| The floor is 7 years and is not relaxable | `AuditRetentionPolicy::of()` | `tests/Unit/Domain/Audit/AuditRetentionPolicyTest.php` |
| The period in force is never below the floor | `RetentionPolicyService::currentPolicy()` | `tests/Integration/Audit/RetentionPolicyServiceTest.php` |
| A stored value below the floor is detected, not assumed away | `RetentionPolicyService::complianceReport()` | `testComplianceReportsASubFloorStoredValueAsNonCompliant` |
| No policy permits deletion | `AuditRetentionPolicy::permitsDeletion()` | `testNoPolicyPermitsDeletion` |
| No service, port or repository offers a removal path | absence of such a method, asserted by reflection | `testNoPublicMethodCanRemoveOrRewriteAnAuditRecord` |
| The schema has nowhere to record an archival | absence of an archive/deletion column in the real schema | `testTheAuditTablesHaveNoArchiveOrDeletionColumn` |
| Both audit tables retain the full actor and time context | column presence asserted against the real schema | `testBothAuditTablesRetainTheFullActorAndTimeContext` |
| A record inside the window is protected | `AuditRetentionPolicy::isProtected()` | `testAYoungAuditRecordIsProtectedFromArchival` |
| Evaluating the policy removes nothing | row counts taken *after* evaluation | `testConsultingThePolicyRemovesNothing` |
| A record past the window is archivable, not deletable | `isArchivable()` and `survived` asserted together | `testARecordOlderThanSevenYearsIsArchivable` |

## What is **not** enforced

This section is the point of the document. Read it before relying on anything above.

**MariaDB is not append-only.** Anyone with SQL access to the shared database can `DELETE` from
`pettycash_transaction_audit`, and nothing in the schema prevents it. FR-053 and Constitution IV are
guarantees about the **application boundary** — no service, port, controller or repository in PettyCash
exposes a path that does it — not about the database engine. Restricting direct SQL access is a
Nextcloud instance and host-administration concern, outside this application's scope.

**There is no archival in V1.** `AuditRetentionPolicy::isArchivable()` answers a question; no code acts
on the answer. No V1 process moves audit records to cold storage, so in practice every record is served
from primary storage forever. A future archival implementation would have to preserve the row and its
bytes and keep it queryable within the same role and scope restrictions (FR-055), and it would need its
own approved specification.

**The compliance report is a diagnostic, not an enforcement mechanism.**
`RetentionPolicyService::complianceReport()` reports what is configured and whether it honours the floor.
Nothing calls it automatically, and it does not correct a non-compliant stored value on its own — the
service *applies* the safe default when reading, so a sub-floor stored value is neutralised on every
read, but the stored value itself stays as it is until an administrator changes it. The report exists so
a discrepancy is detectable rather than invisible.

**Retention does not govern visibility.** How long a record is kept and who may read it are different
questions. Audit visibility follows the same role and scope restrictions as the related transaction
(FR-055), and configuration audit visibility follows its own role scope: administrators see all;
finance officers see configuration audit for accounts they charge; cost-center and senior managers see
it for their assigned cost centers. A record inside its retention window can still be invisible to a
given user, and a record past its window is still subject to the same rules.

## Operational notes

- **Changing the policy is auditable and should be rare.** There is no supported bulk-edit, no scheduled
  change, and no automatic adjustment. Each call to `configure()` is one deliberate administrator action
  with one configuration audit event.
- **To check current compliance**, call `RetentionPolicyService::complianceReport()`. Read
  `honorsMinimum` for whether the *stored* configuration is compliant and `effectiveYears` for what will
  actually be enforced. They can differ, and the difference is the interesting part — see
  `docs/reports/T237.md` for why those are two separate fields rather than one.
- **The default is safe.** With nothing configured, the policy in force is exactly seven years. An
  absent, unparsable or corrupt setting resolves to the floor rather than to a shorter period, because
  the safe direction is to keep history longer.

## Related requirements and artifacts

- `spec.md` — FR-017, FR-051–FR-057, FR-052, FR-053, FR-054, FR-055, FR-056, SC-006, SC-012
- `plan.md` — "Audit Retention Policy (FR-057)" in Complexity Tracking
- `constitution.md` — Principle IV (Domain Integrity, Security, and Auditability), Principle V
- `docs/reports/T237.md` — the subject, and the compliance-signal design decision
- `docs/reports/T138.md` — the verification, including what it deliberately does not claim
- `tests/Integration/Audit/RetentionPolicyServiceTest.php` — service-level contract
- `tests/Unit/Domain/Audit/AuditRetentionPolicyTest.php` — the floor, both sides of the boundary
- `tests/Integration/Audit/RetentionPolicyTest.php` — real-database and structural guarantees

## Open items

- **Configuration audit is not written to its own stream.** FR-056 requires the two audit streams to be
  separately identifiable; today only `pettycash_transaction_audit` is written, and
  `AuditWriterService::appendConfiguration()` is dead code that would route into the transaction table
  if it were called. Task T028 is marked complete on a claim that is not yet true. This does not affect
  the retention guarantees above, which apply to whatever records exist. See the state table near the
  top of this document.
- **T139** (`tests/Integration/Audit/ConfigAuditScopeTest.php`) is not yet written. It covers the
  per-role configuration audit visibility described above under FR-055/FR-056, which this document
  describes but does not itself verify. It also cannot be written until the separation above exists,
  because there is presently nothing for a role-scoped configuration audit query to be scoped over.
- **T253** records the `subject_type`/`subject_public_id` versus `entity_type`/`entity_id` naming
  disagreement between `data-model.md` and the shipped schema. It should be settled before the
  configuration audit query in T093 lands, so the query is built on the names that will actually exist.
- **The `command_id` nullability mismatch** recorded in `docs/reports/T138.md` affects
  `pettycash_transaction_audit.command_id` and the `AuditEvent` domain type. It does not affect
  retention.
