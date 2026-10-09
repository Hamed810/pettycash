<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Account\Repository;

use OCA\PettyCash\Domain\Account\PettyCashAccount;
use OCA\PettyCash\Domain\Shared\CurrencyCode;

/** @extends \IteratorAggregate<int, PettyCashAccount> */
interface AccountRepository
{
    public function findById(int $id): ?PettyCashAccount;
    public function findByPublicId(string $publicId): ?PettyCashAccount;
    /** @return list<PettyCashAccount> */
    public function findActiveByUserUid(string $userUid): array;
    /** @return list<PettyCashAccount> */
    public function findActiveByUserAndCurrency(string $userUid, CurrencyCode $currency): array;

    /**
     * Every account the given uid holds, **active or not**, so a deletion event can close them all.
     *
     * Added for T391. `findActiveByUserUid()` is the wrong read for this and cannot be substituted: it
     * filters on `active`, so an account deactivated by an earlier event would be invisible here and
     * would never be visited. The listener's job is to reach *all* of a deleted user's accounts, so the
     * read must not pre-filter them away.
     *
     * @return list<PettyCashAccount>
     */
    public function findAllByUserUid(string $userUid): array;

    /**
     * How many accounts exist instance-wide, active and inactive alike.
     *
     * Added for T379. Every read this interface offers is scoped — by user, by currency, or by the
     * word *active* — so the administrator dashboard had no figure to publish and passed a literal
     * `0` for `totalAccounts` instead. That number was wrong on every instance, always, and it was
     * rendered as a headline metric beside two real counts.
     *
     * It counts **all** accounts rather than only active ones, because that is what the name says.
     * A caller wanting the active subset should say so; see `findActiveByUserUid()`.
     *
     * Zero, not an exception, when there are none.
     */
    public function countAll(): int;

    /**
     * How many accounts exist in a currency, by surrogate id.
     *
     * **Added by T422** so FR-023's rule — a currency's decimal scale is refused while amounts exist in
     * it — can be enforced at all. Nothing could ask the question before this method existed, so the rule
     * had no way to be true.
     *
     * **Why "an account exists" is the test for "an amount exists", and why that is a safe superset.**
     * Every amount in the system reaches a balance through an account: a sheet's currency is its account's
     * currency (FR-020, `SheetService`), and a transaction's amount is denominated in that same currency. So a
     * currency with **no** account has no balance, no movement, and no transaction — and its scale may be set
     * freely. A currency with **any** account may hold amounts, and counting accounts is a superset of
     * "amounts exist". Over-refusing is the acceptable direction: an administrator whose scale change is
     * refused can still deactivate the currency, whereas under-refusing would silently reinterpret balances
     * with no way to notice afterwards.
     *
     * @param int $currencyId surrogate `pettycash_currencies.id`, not the code
     */
    public function countByCurrencyId(int $currencyId): int;

    public function save(PettyCashAccount $account): void;
}