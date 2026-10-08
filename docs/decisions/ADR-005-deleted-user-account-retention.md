# ADR-005: A deleted user's petty-cash account is deactivated, never deleted

- **Status**: Accepted (2026-10-07, T391)
- **Decides**: What happens to `pettycash_accounts` rows when the owning Nextcloud user is deleted
- **Traces to**: FR-054, FR-053, FR-057, SC-012, Constitution IV

## Context

`pettycash_accounts.user_uid` is a plain `string(64)`. `SHOW CREATE TABLE` lists only `PRIMARY KEY`, a
unique key on `public_id` and a plain index — **no foreign key constraint** — and until T391 nothing
registered a listener for `OCP\User\Events\UserDeletedEvent`. So when a Nextcloud user was deleted, its
account row simply stayed behind, `active`, carrying its balance.

Measured on the shared development instance before this decision was taken:

- `IUser::delete()` removed the `oc_users` row and left the `pettycash_accounts` and `pettycash_sheets`
  rows intact. Nothing cascaded, because nothing was declared to.
- `abababab-1111-4111-8111-111111111111` (`user_uid` `pettycash-somebody-else`, `active`, balance 5000)
  had **no owning Nextcloud user**. It was invisible to every API: `AccountService::listForUser()` and the
  accounts report both scope by a uid, and nobody can authenticate as a deleted uid.
- It was **not** invisible to the administrator dashboard: `DbAccountRepository::countAll()` counts every
  account row regardless of owner or `active`, so that row was inside `totalAccounts`.

The retention of *historical* records was never the open question. Constitution IV says "Historical actor
identity and role-at-event MUST survive account disablement or deletion", FR-054 says the same in
requirement terms, FR-053 makes business audit non-deletable and FR-057 forbids any retention policy from
rewriting business history. Deleting sheets, transactions, revisions, decisions or audit is therefore
forbidden, and this ADR does not touch them.

## Decision

**A deleted user's petty-cash accounts are deactivated. Nothing is deleted.**

`UserDeletedEvent` deactivates every account the deleted user held. The rows remain, readable, with their
balance and their full movement history.

Three consequences, each stated rather than left to be discovered:

1. **The account stops reading as spendable.** `PettyCashAccount::charge()` and `::consume()` both refuse
   an inactive account, and `findActiveByUserUid()` stops offering it, so FR-020's resolve branch cannot
   hand it to anyone. The row is history, not a live facility.
2. **The administrator's `totalAccounts` keeps counting it.** That is correct and deliberate: an account
   *does* exist, and a count that silently dropped deactivated rows would answer a different question
   under the same name — the reason T379 made this an exact `countAll()`. It is also the honest figure,
   because a deactivated account with a non-zero balance is still a liability someone may need to see.
3. **Sheets, transactions, revisions, decisions and audit are untouched.** This ADR has no opinion about
   orphaning *those* rows, because FR-054 forbids removing them and no requirement says what a reader
   should see for a deleted owner. That gap is recorded on T391 rather than answered here.

## Why not the alternatives

**Delete the account row.** Rejected: it destroys a balance that has movements attached, and FR-053/FR-057
do not forgive removing financial history. A balance of 5000 with a movement ledger is not garbage.

**Deactivate *and* exclude from `totalAccounts`.** Rejected: it makes the dashboard figure mean
"accounts in use" while its siblings `totalSheets` and `totalTransactions` mean "records that exist", and
that inconsistency across one card is worse than an inflated count a reader can reconcile against
`GET /accounts`.

**Change nothing; record only.** Rejected: the symptom is real and measured. An `active` account with no
owner is not merely untidy — it sits inside an administrator's headline figure and means something no
reader can resolve.

**Add a foreign key to `oc_users`.** Rejected, and this is the important one. Two existing suites depend on
`user_uid` naming no Nextcloud user being a **legal** value: `roundtrip-runner.php`'s probe owner and
`RepositoryRoundTripTest`'s leak post-condition, which relies on deletes not failing on dependents. An FK
would make those tests unable to plant the rows they exist to verify. Referential integrity here is
enforced at the event boundary, which is also where the decision belongs.

## Consequences

- `lib/Infrastructure/Nextcloud/DeletedUserAccountListener.php` (new) handles `UserDeletedEvent` and
  calls `AccountAdministrationService::deactivateAccountsOfDeletedUser()`.
- `PettyCashAccount::deactivateForDeletedOwner()` (new) is the only mutation of `active` on this path, and
  it refuses an already-inactive account rather than bumping `lockVersion` for no change.
- `data-model.md`'s claim that "Application repositories enforce referential integrity and prevent
  destructive cascades across historical entities" was over-stated: the repositories did neither. It now
  names where the enforcement actually lives.
- `tests/Contract/Controller/DeletedUserRetentionContractTest.php` (new) proves FR-054 and SC-012 against
  a **real** `IUser::delete()` — the only thing that turns the claim into evidence.

## What this ADR does not decide

Whether a *sheet* owned by a deleted user should still be listed to an administrator, and whether an
orphaned account's balance should be settled or written off. Both are open. The second is a finance
decision with no requirement behind it and is recorded on T391 rather than invented here.