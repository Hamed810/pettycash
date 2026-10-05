# Data Model: PettyCash V1 Core Workflow

**Date**: 2026-09-20

## Modeling Conventions

- Database tables use the logical app-scoped `pettycash_` prefix. The Nextcloud database abstraction
  adds its configured instance prefix separately; it MUST NOT be hardcoded as `oc_` or any other
  instance value.
- Indexes, unique constraints, foreign keys, and check constraints are named
  `pettycash_<table>_<subject>_<kind>`, where kind is `idx`, `uniq`, `fk`, or `chk`, so no schema
  object can collide with another application in the shared database.
- The `pc_*` tables already created by the current development migrations are provisional V1
  development schema, not legacy data. They are renamed into the `pettycash_*` namespace by
  rewriting the baseline migrations in place, and their data is discarded by design.
- Every externally addressable aggregate uses an immutable UUID `public_id`; numeric `id` values
  remain internal join keys.
- Amounts use signed 64-bit integer minor units plus a currency code. Floating-point money is
  prohibited.
- Business timestamps are stored in UTC. Expense dates remain calendar dates without a time zone.
- User references store the host user UID. Audit and decision records also snapshot display name
  and role so history survives later account or assignment changes.
- Mutable aggregates include `lock_version` for optimistic checks; finance settlement also uses
  database row locks and uniqueness constraints.
- Historical and audit records have no update or delete application operation.
- Legacy database import, schema migration, table preservation, and data compatibility are outside
  V1 scope. Prototype tables that may remain in a shared development database are unmanaged
  artifacts: this model never reads, writes, migrates, renames, or drops them.

## Entity Overview

| Entity | Table | Purpose |
|--------|-------|---------|
| Currency | `pettycash_currencies` | Supported currency and minor-unit configuration |
| Cost Center | `pettycash_cost_centers` | Organizational routing and reporting scope |
| Expense Type | `pettycash_expense_types` | Expense classification and evidence rule |
| Role Assignment | `pettycash_role_assignments` | Effective-dated manager, senior, and finance roles |
| Petty-Cash Account | `pettycash_accounts` | User balance for one currency |
| Account Movement | `pettycash_account_movements` | Immutable charge or approved consumption |
| Expense Sheet | `pettycash_sheets` | Purchaser grouping and aggregate workflow projection |
| Expense Transaction | `pettycash_transactions` | Smallest decision unit and current-state projection |
| Transaction Revision | `pettycash_transaction_revisions` | Immutable business-data version |
| Workflow Decision | `pettycash_workflow_decisions` | Immutable stage opinion or return request |
| Evidence | `pettycash_evidence` | Evidence metadata and private AppData reference |
| Evidence Public Link | `pettycash_evidence_links` | Optional administrator-managed public access token |
| Transaction Audit Event | `pettycash_transaction_audit` | Append-only official business history |
| Configuration Audit Event | `pettycash_configuration_audit` | Append-only administrative history |
| Command Result | `pettycash_command_results` | Idempotency key, payload hash, and replay result |

## Currency

| Field | Type | Rules |
|-------|------|-------|
| `id` | bigint | Internal primary key |
| `code` | string(3) | Unique uppercase code |
| `name` | string(100) | Display label |
| `minor_unit` | small integer | Valid range 0–4 |
| `active` | boolean | Inactive currencies remain historical references |
| `created_at`, `updated_at` | UTC datetime | Required |
| `created_by`, `updated_by` | user UID | Required |
| `lock_version` | integer | Increment on update |

**Constraints and indexes**:

- Unique `code`.
- Active status affects new selection only; referenced records remain valid.
- Rial is seeded or established as the required default currency during configuration setup.

## Cost Center

| Field | Type | Rules |
|-------|------|-------|
| `id` | bigint | Internal primary key |
| `public_id` | UUID string | Unique external identifier |
| `code` | string(64) | Unique business code |
| `name` | string(255) | Required |
| `description` | text | Optional |
| `valid_from`, `valid_to` | date | `valid_to` optional and not before `valid_from` |
| `active` | boolean | Controls new assignment and transaction selection |
| audit columns | datetime/user UID | Created and updated context |
| `lock_version` | integer | Increment on update |

**Relationships**:

- Has effective-dated cost-center-manager and senior-manager role assignments.
- Referenced by transaction revisions; revisions also snapshot code and name.

**Constraints and indexes**:

- Unique `public_id` and `code`.
- Index active validity range.
- Exactly one effective manager and one effective senior manager are required when the center is
  selectable for a new transaction.

## Expense Type

| Field | Type | Rules |
|-------|------|-------|
| `id` | bigint | Internal primary key |
| `public_id` | UUID string | Unique external identifier |
| `title` | string(255) | Required |
| `description` | text | Optional |
| `evidence_required` | boolean | At least one active item required at submission when true |
| `active` | boolean | Controls new selection only |
| audit columns | datetime/user UID | Required |
| `lock_version` | integer | Increment on update |

## Role Assignment

| Field | Type | Rules |
|-------|------|-------|
| `id` | bigint | Internal primary key |
| `public_id` | UUID string | Unique external identifier |
| `user_uid` | string(64) | Host user ID |
| `role` | enum string | `COST_CENTER_MANAGER`, `SENIOR_MANAGER`, or `FINANCE` |
| `cost_center_id` | bigint nullable | Required for manager roles; null for shared finance role |
| `valid_from`, `valid_to` | date | Effective range |
| `active` | boolean | Assignment availability |
| audit columns | datetime/user UID | Required |

**Rules**:

- Purchaser capability is implicit for authenticated app users.
- Administrator capability comes from the host administrator check and does not imply finance.
- V1 finance assignments have no cost-center scope; all active finance officers share one queue.
- Multi-role users, including transaction owners, may act when role, scope, stage, and state allow.
- Assignment changes govern future routing. Existing decisions keep actor snapshots; active queue
  ownership is re-evaluated from current assignment policy without rewriting history.

## Petty-Cash Account

| Field | Type | Rules |
|-------|------|-------|
| `id` | bigint | Internal primary key |
| `public_id` | UUID string | Unique external identifier |
| `user_uid` | string(64) | Account owner |
| `currency_id` | bigint | Account currency |
| `balance_minor` | bigint | May be negative |
| `active` | boolean | Controls new sheet selection and charges |
| audit columns | datetime/user UID | Required |
| `lock_version` | integer | Concurrency control |

**Constraints and indexes**:

- Unique user and currency pair.
- Every app user must have one active rial account before creating a sheet.
- Balance equals the latest movement's `balance_after_minor`; a reconciliation query verifies the
  projection against movement sums.

## Account Movement

| Field | Type | Rules |
|-------|------|-------|
| `id` | bigint | Internal primary key |
| `public_id` | UUID string | Unique external identifier |
| `account_id` | bigint | Required |
| `movement_type` | enum string | `CHARGE` or `CONSUMPTION` |
| `amount_minor` | bigint | Positive magnitude; type determines sign |
| `balance_after_minor` | bigint | Exact resulting balance |
| `description` | string(1000) | Required for charges; generated or supplied for consumption |
| `source_revision_id` | bigint nullable | Required for consumption |
| `source_decision_id` | bigint nullable | Required for consumption |
| `command_id` | UUID string | Required idempotency reference |
| actor snapshot | UID/name/role | Required |
| `occurred_at` | UTC datetime | Required |

**Constraints and indexes**:

- Unique `public_id` and `command_id`.
- Unique consumption `source_revision_id` so a revision cannot consume twice.
- Immutable after insert.

## Expense Sheet

| Field | Type | Rules |
|-------|------|-------|
| `id` | bigint | Internal primary key |
| `public_id` | UUID string | Unique external identifier |
| `title` | string(255) | Required |
| `owner_uid` | string(64) | Purchaser |
| `account_id` | bigint | Selected source account |
| `currency_id` | bigint | Must equal account currency |
| `status` | enum string | Derived projection described below |
| `submitted_total_minor` | bigint nullable | Snapshot at first submission |
| `created_at` | UTC datetime | Required |
| `first_submitted_at` | UTC datetime nullable | Set once |
| `management_completed_at` | UTC datetime nullable | Latest relevant completion |
| `finance_started_at` | UTC datetime nullable | Set when first finance item is available |
| `completed_at` | UTC datetime nullable | Set at terminal sheet state |
| `lock_version` | integer | Concurrency control |

**Derived values**:

- Current total: sum of active revision amounts.
- Approved/rejected/pending headline totals: sum by latest effective decision.
- Stage totals: independent sums of manager, senior, and finance approvals.
- First/last expense date: min/max active revision expense dates.

Mutable totals are calculated by role-scoped projections rather than stored as independent facts.

## Expense Transaction

| Field | Type | Rules |
|-------|------|-------|
| `id` | bigint | Internal primary key |
| `public_id` | UUID string | Unique external identifier |
| `sheet_id` | bigint | Required parent |
| `owner_uid` | string(64) | Snapshot of purchaser UID |
| `active_revision_id` | bigint | Current business-data revision |
| `current_stage` | enum string | `DRAFT`, `COST_CENTER`, `CORRECTION`, `SENIOR`, `FINANCE`, `FINAL` |
| `current_status` | enum string | State-machine projection |
| `final_outcome` | enum nullable | `APPROVED` or `REJECTED` only at terminal state |
| `created_at`, `updated_at` | UTC datetime | Required |
| `lock_version` | integer | Concurrency control |

**Rules**:

- Current information is always read through `active_revision_id`.
- No new transaction may be created after sheet submission.
- Terminal transactions never reopen in V1.
- Historical decisions are not overwritten when current state advances.

## Transaction Revision

| Field | Type | Rules |
|-------|------|-------|
| `id` | bigint | Internal primary key |
| `public_id` | UUID string | Unique external identifier |
| `transaction_id` | bigint | Required |
| `revision_number` | integer | Starts at 1; unique per transaction |
| `expense_date` | date | Required at draft save |
| `amount_minor` | bigint | Must be greater than zero |
| `description` | text | Required at submission |
| `cost_center_id` | bigint | Required at submission |
| `cost_center_code`, `cost_center_name` | snapshot strings | Frozen for historical display |
| `expense_type_id` | bigint | Required at submission |
| `expense_type_title` | snapshot string | Frozen for historical display |
| manager UID snapshots | string(64) | Current manager and senior at revision activation |
| `created_at`, `created_by` | UTC datetime/user UID | Required |
| `activated_at` | UTC datetime nullable | When made active |
| `supersedes_revision_id` | bigint nullable | Prior revision |

**Rules**:

- Revision 1 may remain mutable only while its transaction is a draft in an open sheet.
- A submitted revision is immutable.
- Correction is authored as a new revision and becomes active atomically on resubmission.
- Required evidence is validated against active evidence related to the revision/transaction.

## Workflow Decision

| Field | Type | Rules |
|-------|------|-------|
| `id` | bigint | Internal primary key |
| `public_id` | UUID string | Unique external identifier |
| `transaction_id`, `revision_id` | bigint | Required |
| `stage` | enum string | `COST_CENTER`, `SENIOR`, or `FINANCE` |
| `decision` | enum string | `APPROVE`, `REJECT`, or `RETURN` |
| `explanation` | text nullable | Required for reject and return |
| actor snapshot | UID/name/role | Required |
| `command_id` | UUID string | Required |
| `decided_at` | UTC datetime | Required |

**Constraints**:

- One effective decision per revision and stage; history is preserved by revisions rather than
  updating a decision.
- Cost-center rejection continues to senior review.
- Senior rejection continues to finance but prevents finance approval.
- Return is unresolved, creates purchaser work, and is superseded only through a new revision.

## Evidence

| Field | Type | Rules |
|-------|------|-------|
| `id` | bigint | Internal primary key |
| `public_id` | UUID string | Unique external identifier |
| `transaction_id` | bigint | Required |
| `origin_revision_id` | bigint | Revision at upload |
| `appdata_key` | string(1024) | Opaque private storage key |
| `original_name` | string(255) | Required; never used as storage path |
| `media_type` | string(255) | Required |
| `size_bytes` | bigint | Host-enforced size limit |
| `checksum` | string(128) | Integrity and duplicate diagnostics |
| `active` | boolean | False after post-submit deactivation |
| upload actor/time | UID/datetime | Required |
| deactivation actor/time/reason | nullable fields | Required when inactive |

**File lifecycle**:

- Draft removal deletes metadata and binary only before formal submission.
- After submission, deactivate metadata and retain the binary.
- Files are streamed only after transaction authorization.
- Database failure after an AppData write triggers compensation; reconciliation detects orphans.

## Evidence Public Link

| Field | Type | Rules |
|-------|------|-------|
| `id` | bigint | Internal primary key |
| `public_id` | UUID string | Administrative identifier |
| `evidence_id` | bigint | Required active evidence |
| `token_hash` | string(128) | Unique; raw token is never stored |
| `active` | boolean | Disabled by default until explicitly enabled |
| `expires_at` | UTC datetime nullable | Optional |
| creator/deactivator context | UID/time | Audited |

## Transaction Audit Event

| Field | Type | Rules |
|-------|------|-------|
| `id` | bigint | Monotonic internal key |
| `public_id` | UUID string | Unique |
| `sheet_id`, `transaction_id`, `revision_id` | bigint nullable | Event context |
| `event_type` | enum string | Submission, routing, decision, return, correction, evidence, etc. |
| `stage` | enum nullable | Workflow stage when applicable |
| `decision_id` | bigint nullable | Related immutable decision |
| actor snapshot | UID/name/role | Required |
| `explanation` | text nullable | Event context |
| `before_json`, `after_json` | canonical JSON nullable | Changed business values only |
| `command_id` | UUID string | Correlation/idempotency reference |
| `occurred_at` | UTC datetime | Required |

**Rules**:

- First formal transaction event is sheet submission.
- Insert-only application API; no update or delete route, service, or repository method.
- Business events are distinct from technical logs.

## Configuration Audit Event

Same append-only conventions as transaction audit, with `entity_type`, `entity_id`,
`event_type`, actor snapshot, explanation, before/after JSON, command ID, and occurrence time.
The subject is named `entity_type`/`entity_id` -- not `subject_type`/`subject_public_id` -- because
that is what `Version010200Date20260920000000.php` creates and what the `AuditEvent` domain entity
already calls `entityType`/`entityId`. The earlier names in this file were a design-time sketch that
the shipped schema superseded while the table was still empty, so nothing had to be migrated.
A finance officer's charged accounts are the distinct `entity_id` values where `entity_type` is
`account` across that officer's own configuration audit events (FR-055).

## Command Result

| Field | Type | Rules |
|-------|------|-------|
| `id` | bigint | Internal primary key |
| `command_id` | UUID string | Unique client-provided idempotency key |
| `actor_uid` | string(64) | Required |
| `operation` | string(100) | Required |
| `target_public_id` | UUID string nullable | Command target |
| `payload_hash` | string(128) | Canonical request hash |
| `result_status` | integer | Original HTTP-equivalent result |
| `result_json` | canonical JSON | Safe response replay data |
| `created_at` | UTC datetime | Required |

**Replay behavior**:

- Same command ID, actor, operation, target, and payload returns the original result.
- Reusing the command ID with different content is a conflict.

## Relationships

```text
Currency 1 ── * Petty-Cash Account 1 ── * Account Movement
       │                 │
       │                 └── * Expense Sheet 1 ── * Expense Transaction
       │                                           │
       │                                           ├── * Transaction Revision
       │                                           ├── * Workflow Decision
       │                                           ├── * Evidence ── * Evidence Public Link
       │                                           └── * Transaction Audit Event
       │
Cost Center 1 ── * Role Assignment
       └── * Transaction Revision

Expense Type 1 ── * Transaction Revision

Command Result 1 ── 0..* Decision, Movement, Audit Event, or State Mutation
```

Application repositories enforce referential integrity and prevent destructive cascades across
historical entities. Database indexes support joins and scope filters without relying on deletion.

## Workflow State Machine

### Transaction Flow

```text
DRAFT
  └── submit ──> AWAITING_COST_CENTER_REVIEW

AWAITING_COST_CENTER_REVIEW
  ├── approve ──> COST_CENTER_APPROVED ──> AWAITING_SENIOR_REVIEW
  ├── reject  ──> COST_CENTER_REJECTED  ──> AWAITING_SENIOR_REVIEW
  └── return  ──> AWAITING_PURCHASER_CORRECTION

AWAITING_SENIOR_REVIEW
  ├── approve ──> SENIOR_APPROVED ──> AWAITING_FINANCE_REVIEW
  ├── reject  ──> SENIOR_REJECTED  ──> AWAITING_FINANCE_REVIEW
  └── return  ──> AWAITING_PURCHASER_CORRECTION

AWAITING_FINANCE_REVIEW with senior approval
  ├── approve ──> COMPLETED_APPROVED
  ├── reject  ──> COMPLETED_REJECTED
  └── return  ──> AWAITING_PURCHASER_CORRECTION

AWAITING_FINANCE_REVIEW with senior rejection
  ├── reject  ──> COMPLETED_REJECTED
  └── return  ──> AWAITING_PURCHASER_CORRECTION

AWAITING_PURCHASER_CORRECTION
  └── activate new revision ──> AWAITING_COST_CENTER_REVIEW
```

Approval or rejection at a management stage is an immutable stage result. The transaction may
enter its next queue immediately, while the sheet remains at the earliest outstanding stage until
all active nonterminal revisions have progressed beyond it.

### Sheet Status Derivation

The sheet's status is the **earliest stage still outstanding** across its active nonterminal
revisions. It is a derivation, not a gate: a transaction enters and leaves its queue on its own
decision, and no transaction blocks another. The statuses below are `SheetStatus` values as
declared in `lib/Domain/Workflow/SheetStatus.php` — the `AWAITING_*` names in the transaction
state machine above are `TransactionStatus` values and are **not** sheet statuses.

1. `OPEN`: sheet has never been submitted, or a revision is still a draft.
2. `COST_CENTER`: at least one active nonterminal revision is at the cost center stage, including a
   freshly corrected revision that has restarted review there.
3. `CORRECTION`: no revision is at cost center, and at least one is awaiting purchaser correction.
4. `SENIOR`: every active nonterminal revision has a cost center decision and at least one lacks a
   senior decision.
5. `FINANCE`: every active nonterminal revision has a senior decision and at least one lacks a
   finance decision.
6. `COMPLETED`: every transaction is terminal and none is finally rejected.
7. `COMPLETED_WITH_REJECTIONS`: every transaction is terminal and at least one is finally rejected.

The stage ranking behind the derivation is `Draft 0, CostCenter 1, Correction 2, Senior 3,
Finance 4, Final 5`, and the sheet takes the minimum. `CORRECTION` therefore outranks `SENIOR` and
`FINANCE`: a sheet with one transaction awaiting purchaser correction displays `CORRECTION` **even
when another transaction is already at finance**. That is intended, and it is what
`tests/Integration/Workflow/TransactionCorrectionTest.php` asserts.

Both a senior approval and a senior rejection count as a senior decision for rule 5, because a
senior-rejected transaction still enters the finance queue (FR-039 forbids finance from approving
it, not from seeing it). Completion under rules 6 and 7 is FR-043's rule and is the only true gate.

`SUBMITTED` is the atomic milestone and first audit event between `OPEN` and initial routing, not a
long-lived sheet status. Returned transactions are surfaced separately in purchaser work queues;
the sheet status reflects the earliest stage needed by any active revision.

## Latest Effective Outcome and Totals

For each active revision, the effective outcome is the most advanced recorded decision:

1. Finance decision if present.
2. Otherwise senior decision if present.
3. Otherwise cost center decision if present.
4. Otherwise pending.

A return decision makes the transaction pending until a corrected revision progresses again.
Headline totals group active revision amounts by effective approve, reject, or pending. Separate
stage totals sum approvals at each stage regardless of later disagreement. Reports and UI label the
effective stage to avoid presenting a managerial approval as final financial settlement.

## Transaction Boundaries and Locking

The following changes occur within one database transaction and create their audit records before
commit:

- Sheet submission and initial routing.
- Each workflow decision and state projection update.
- Correction activation and active-revision switch.
- Account charge and balance update.
- Finance decision, unique consumption movement, balance update, transaction finalization, sheet
  status update, and audit insertion.

The fixed lock order is sheet, transaction, active revision, then account. File writes occur outside
database transactions and use compensation plus reconciliation because MariaDB and AppData cannot
share one atomic transaction.
