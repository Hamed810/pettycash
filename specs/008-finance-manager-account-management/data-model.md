# Data Model — Finance Manager Account Management (Phase 1)

**Feature**: `specs/008-finance-manager-account-management/` | **Status**: design note; **no persisted entity, table, column, index, or migration is introduced**.

## Principle

This feature is a **read projection plus one reused write**. The authoritative records already exist:

- `pettycash_accounts` — ownership, currency, balance, active flag, version;
- `pettycash_account_movements` — recharges and consumptions, posting timestamp, amount, resulting balance, source revision/decision;
- `pettycash_sheets` — title, owner, account, status, completion timestamps;
- `pettycash_transactions` — owner, active revision, stage, status, final outcome;
- `pettycash_transaction_revisions` — expense date, amount, description, cost center/expense type snapshots;
- `pettycash_workflow_decisions` — immutable stage decisions;
- `pettycash_evidence` — evidence metadata and stored content reference;
- `pettycash_currencies` — configured minor unit.

Every field below names its authoritative source. No projection stores a second copy of a balance, count, decision, or financial value.

## Source relationships

```text
Currency 1 ── * PettyCashAccount 1 ── * AccountMovement
                                      │
                                      └── source_revision_id ── 1 TransactionRevision
                                                                     │
                                                                     └── transaction_id ── 1 ExpenseTransaction
                                                                                              │
                                                                                              └── sheet_id ── 1 ExpenseSheet
PettyCashAccount * ── 1 (owner) user_uid ── holder identity (not a table)
ExpenseTransaction 1 ── * WorkflowDecision (per revision/stage)
ExpenseTransaction 1 ── * Evidence (transaction_id)
```

The holder is not a stored entity. It is the distinct `pettycash_accounts.user_uid` set, grouped for the directory. This is why the module cannot enumerate non-holders.

## Projection: HolderSummary

| Field | Type | Source / rule |
|---|---|---|
| `userId` | string | `pettycash_accounts.user_uid` |
| `displayName` | string | `UserDirectory::displayName(userId)` resolved only for account-owning uids; page-bounded without a search term, holder-population-bounded with one; falls back to uid |
| `accountCount` | integer ≥ 1 | `COUNT(*)` grouped by `user_uid` |
| `activeAccountCount` | integer 0…accountCount | `SUM(active)` grouped by `user_uid` |
| `status` | `ACTIVE` \| `INACTIVE` \| `MIXED` | Derived: 0 active → `INACTIVE`; all active → `ACTIVE`; otherwise `MIXED` |

**Validation/invariants**

- A holder appears once per page; grouping is by `user_uid`.
- A user with no `pettycash_accounts` row never appears.
- `accountCount ≥ 1`, `activeAccountCount ≤ accountCount`.
- Display-name resolution never adds or removes a holder.
- `search` is a case-insensitive substring over `userId` or `displayName`; it is applied to the complete grouped holder population and then paginated, never to only the loaded page. The candidate set is always `pettycash_accounts.user_uid`; no Nextcloud directory listing is performed.

## Projection: HolderAccountSummary

| Field | Type | Source / rule |
|---|---|---|
| `id` | uuid | `pettycash_accounts.public_id` |
| `userId` | string | `user_uid` |
| `currency` | string | joined `pettycash_currencies.code` |
| `minorUnit` | integer 0…4 | joined currency `minor_unit` |
| `balance` | `Money` | `balance_minor` + currency + minor unit |
| `active` | boolean | `active` |
| `version` | integer ≥ 0 | `lock_version` |
| `movementCount` | integer ≥ 0 | batched `COUNT(*)` per account |
| `lastMovementAt` | date-time \| null | batched `MAX(occurred_at)` per account; null when no movements |

**Validation/invariants**

- Inactive accounts are included (inspection is read-only and historical).
- `balance` may be negative; the UI presents a negative as an amount owed.
- `version` is the optimistic-lock value the recharge must send; it is not a display-only number.
- `movementCount`/`lastMovementAt` are presentation facts from the movement table, not cached on the account.

## Projection: LedgerRow

| Field | Type | Source / rule |
|---|---|---|
| `id` | uuid | `pettycash_account_movements.public_id` |
| `type` | `CHARGE` \| `CONSUMPTION` | `movement_type` |
| `occurredAt` | date-time | `occurred_at` — **the ledger Date** |
| `description` | string | charge: stored `description`; consumption: resolved sheet title, falling back to the stored description only if no sheet is resolvable (a data fault — see below) |
| `sheet` | `{ id, reference, title }` \| null | resolved revision → transaction → sheet; `reference` is the sheet public UUID |
| `approved` | `{ approvedCount, totalCount }` \| null | batched count per sheet: `final_outcome='APPROVED'` vs all transactions; null for a charge |
| `recharge` | `Money` \| null | `amount` when `type=CHARGE`; null otherwise |
| `expense` | `Money` \| null | `amount` when `type=CONSUMPTION`; null otherwise |
| `balanceAfter` | `Money` | `balance_after_minor` + account currency/minor unit |
| `sourceRevisionId` | uuid \| null | joined revision public id; null for a charge |

**Validation/invariants**

- Exactly one of `recharge`/`expense` is non-null for every row.
- A `CHARGE` has `sheet = null`, `approved = null`, `expense = null`.
- A `CONSUMPTION` has `sheet != null`, `approved != null`, `recharge = null`. A consumption whose revision resolves but whose sheet does not is a **data fault**, not a row with `approved: null`; the read raises `UnreadableRecordData` so the ledger cannot silently misrepresent a posting.
- `approvedCount ≤ totalCount` and both are ≥ 0.
- `occurredAt` is the movement's persisted timestamp; it is never derived from the sheet, revision, or a mockup.
- `balanceAfter` is the persisted resulting balance; it is never recomputed from the page or the account's current balance.
- Rows are ordered `occurred_at DESC, id DESC`; `total` is a separate count of all movements for the account, not the page length.
- No row exposes a mutation action, and the projection has no rejected-count field.

## Projection: LedgerPage

| Field | Type | Source / rule |
|---|---|---|
| `account` | `HolderAccountSummary` | the selected account, including current balance/version/activity |
| `items` | list of `LedgerRow` | page slice |
| `page` | integer ≥ 1 | request |
| `pageSize` | integer 1…100 | request |
| `total` | integer ≥ 0 | count of all movements for the account |

## Projection: CompletedSheetSummary

| Field | Type | Source / rule |
|---|---|---|
| `id` | uuid | `pettycash_sheets.public_id` |
| `reference` | string | the sheet public UUID (authoritative; no business-reference column exists) |
| `title` | string | `title` |
| `purchaser` | `{ userId, displayName }` | `owner_uid` + bounded directory resolution |
| `totalTransactionCount` | integer ≥ 0 | batched count of the sheet's transactions |
| `approvedTransactionCount` | integer ≥ 0 | batched count where `final_outcome='APPROVED'` |
| `rejectedTransactionCount` | integer ≥ 0 | batched count where `final_outcome='REJECTED'` |
| `postedAmount` | `Money` | sum of consumption movement amounts linked to the sheet; currency/minor unit from the sheet/account |
| `postingCount` | integer ≥ 0 | count of those consumption movements |
| `completedAt` | date-time | `completed_at` |
| `status` | `COMPLETED` \| `COMPLETED_WITH_REJECTIONS` | `status` |

**Validation/invariants**

- Only terminal statuses are listed; a `FINANCE`/`SENIOR`/`COST_CENTER`/`CORRECTION`/`OPEN` sheet is absent.
- `approved + rejected = total` for a completed sheet.
- `postedAmount` is derived from actual movements, never from “approved amount × count” or the sheet's effective-approved projection.
- `postingCount` may be less than `approvedTransactionCount` only if the authoritative data is inconsistent; the projection reports the actual postings and does not fabricate the missing ones.
- A sheet with zero approved transactions is valid: posted amount 0, posting count 0, rejected count = total.

## Projection: CompletedSheetDetail

Extends `CompletedSheetSummary` with:

| Field | Type | Source / rule |
|---|---|---|
| `accountId` | uuid | the sheet's persisted account public id |
| `currency` | string | sheet currency |
| `minorUnit` | integer 0…4 | configured currency |
| `createdAt` | date-time | `created_at` |
| `firstSubmittedAt` | date-time \| null | `first_submitted_at` |
| `financeStartedAt` | date-time \| null | `finance_started_at` |
| `transactions` | list of `CompletedTransaction` | all transactions of the sheet |

**Validation/invariants**

- The detail read refuses (404) unless the sheet is terminal **and** every transaction is `CompletedApproved`/`CompletedRejected`.
- Every transaction in the list has a terminal Finance decision; no `AWAITING_FINANCE_REVIEW` state can appear.
- The response contains no capability/action field that could enable a decision; the read-only guarantee is structural, not a hidden button.

## Projection: CompletedTransaction

| Field | Type | Source / rule |
|---|---|---|
| `id` | uuid | `pettycash_transactions.public_id` |
| `reference` | string | the transaction public UUID |
| `revisionNumber` | integer ≥ 1 | active revision number |
| `description` | string | active revision description |
| `expenseDate` | date | active revision `expense_date` — labelled as the expense date, not the posting date |
| `amount` | `Money` | active revision amount + sheet currency/minor unit |
| `finalOutcome` | `APPROVED` \| `REJECTED` | `final_outcome` |
| `status` | `COMPLETED_APPROVED` \| `COMPLETED_REJECTED` | `current_status` |
| `decisions` | `{ costCenter, senior, finance }` | latest decision per stage across the transaction's decision history; each is `Decision \| null` |
| `decisionHistory` | list of `Decision` | full chronological history, for audit-grade display |
| `evidence` | list of `EvidenceMetadata` | batched metadata; content is read through the existing endpoint under the read-only completed-history rule |
| `posting` | `{ occurredAt, amount, balanceAfter }` \| null | the consumption movement linked to this transaction's revision; null for a rejected transaction |

## Projection: Decision

| Field | Type | Source / rule |
|---|---|---|
| `id` | uuid | `WorkflowDecision.public_id` |
| `stage` | `COST_CENTER` \| `SENIOR` \| `FINANCE` | `stage` |
| `decision` | `APPROVE` \| `REJECT` \| `RETURN` | `decision` |
| `explanation` | string \| null | `explanation` |
| `actor` | `{ userId, displayName, role }` | immutable actor snapshot |
| `decidedAt` | date-time | `decided_at` |

## Projection: EvidenceMetadata

| Field | Type | Source / rule |
|---|---|---|
| `id` | uuid | evidence public id |
| `name` | string | original name |
| `mediaType` | string | media type |
| `sizeBytes` | integer ≥ 0 | size |
| `active` | boolean | active flag |
| `uploadedBy` | string | uploader uid |
| `uploadedAt` | date-time | upload timestamp |

No content, URL, or preview token is embedded. Content access remains `GET /evidence/{evidenceId}/content`, using the existing delivery path.

**Read-only completed-history rule**

- An actor admitted by the existing `EvidenceVisibility::mayRead()` rule is unchanged.
- Additionally, an actor with an effective `FINANCE` assignment may read content for evidence on a transaction that is terminal (`COMPLETED_APPROVED`/`COMPLETED_REJECTED` at `WorkflowStage::Final`) in a sheet that is `COMPLETED`/`COMPLETED_WITH_REJECTIONS` and whose every transaction is terminal — the same scope the completed-history detail read exposes.
- This branch is composed only for content; it grants no upload, replacement, deletion, deactivation, link creation, or any other mutation, and it does not change audit/revision visibility or any existing purchaser/Cost Center/Senior result.
- Out-of-scope evidence is not disclosed; the existing 404/403 answers remain.

## Client-only state: RechargeDraft

This is not persisted and is not a domain entity. It exists only while the recharge dialog is open.

| Field | Rule |
|---|---|
| `accountId` | immutable selection from the holder account page |
| `holderUserId` / `holderDisplayName` | display only; from the selected holder |
| `currency` / `minorUnit` | from the destination account; read-only |
| `currentBalance` | from the freshly loaded account; displayed in review |
| `expectedVersion` | from the same account payload; sent to the server |
| `amountInput` | positive major-unit input; at most `minorUnit` fraction digits |
| `amountMinor` | integer ≥ 1 after conversion; must be a safe integer |
| `description` | required, 1–1000 characters |
| `occurredAt` | ISO timestamp taken at confirmation |
| `commandId` | one UUID per intended recharge; reused on an unknown-result retry |
| `step` | `amount` → `review` → `submitting` → `success` \| `failure` \| `unknown` |
| `error` | server message / field errors for failure; null otherwise |

**State transitions**

```text
amount ──validate──> review ──confirm──> submitting ──201──> success
  ^                    │                     │
  │                    │                     ├──4xx/definitive──> failure ──edit──> amount (new commandId)
  │                    │                     └──network/5xx/timeout──> unknown ──retry(same commandId)──> submitting
  └────────cancel──────┘
```

**Validation rules**

- Non-positive, non-numeric, over-precise, or unsafe amounts cannot reach `review`.
- An inactive account cannot enter `review`; the action is disabled with the reason.
- The currency is never editable and no conversion is offered.
- A successful response is the only path to `success`; a locally computed balance is never shown.
- A retry after `unknown` reuses `commandId`; a new intended recharge after `failure` uses a new one.
- The submit control is disabled while `submitting`, preventing a second click from issuing a second request.

## State transitions owned by existing code (not changed)

- A transaction at Finance receives a final `Approved`/`Rejected` decision; on approval, exactly one consumption movement is recorded; the transaction moves to `Final` with `finalOutcome`.
- A sheet is recalculated from its transactions and reaches `COMPLETED` or `COMPLETED_WITH_REJECTIONS` only when every transaction is terminal.
- An account charge records one movement, updates the balance, and writes the configuration audit event inside the idempotent command.

This feature only reads those states and reuses the charge write. It does not add a transition, alter a guard, or defer a posting.

## Pagination and filter rules

| Surface | Parameters | Order | Scope |
|---|---|---|---|
| Holder directory | `page`, `pageSize`, `search` (case-insensitive display-name or uid substring, ≤64) | `user_uid ASC` | users with ≥1 account; search over the complete holder population before pagination |
| Holder accounts | `page`, `pageSize` | `currency ASC, id ASC` | all accounts of the holder, active or not |
| Ledger | `page`, `pageSize` | `occurred_at DESC, id DESC` | all movements of the selected account |
| Completed list | `page`, `pageSize` | `completed_at DESC, id DESC` | statuses `COMPLETED`, `COMPLETED_WITH_REJECTIONS` |

`page` defaults to 1 and must be ≥ 1. `pageSize` defaults to 25 and must be 1–100. Invalid values are 422 with field errors. An empty result is a 200 with `items: []` and `total: 0`.

## Explicit non-goals

- No stored holder entity, ledger table, history snapshot, or posted-total column.
- No second copy of a balance, count, or decision.
- No migration, backfill, or schema change.
- No rejected-count column in the ledger.
- No mutation of a completed sheet, decision, movement, or transaction.
- No evidence upload, replacement, deletion, deactivation, link creation, or other mutation through the completed-history read path.
- No parallel evidence storage or download mechanism; no unrestricted Nextcloud directory search or enumeration.
