<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Domain\Account\PettyCashAccount;
use OCA\PettyCash\Domain\Account\Repository\AccountRepository;
use OCA\PettyCash\Domain\Shared\CurrencyCode;

final class InMemoryAccountRepository implements \OCA\PettyCash\Domain\Account\Repository\AccountRepository
{
    /**
     * @var array<int, PettyCashAccount>
     */
    private array $accounts = [];

    public function findById(int $id): ?PettyCashAccount
    {
        // Scans rather than indexing, because `save()` keys by public id. Indexing here would look
        // up an int against string keys and always answer null, so any test covering a code path
        // that resolves an account by surrogate key would see "no such account" for a fixture it
        // had just stored. That is a defect in the double, not the behaviour under test.
        foreach ($this->accounts as $account) {
            if ($account->dbId === $id) {
                return $account;
            }
        }
        return null;
    }

    public function findByPublicId(string $publicId): ?PettyCashAccount
    {
        foreach ($this->accounts as $account) {
            if ($account->id === $publicId) {
                return $account;
            }
        }
        return null;
    }

    /**
     * @return list<PettyCashAccount>
     */
    public function findActiveByUserUid(string $userUid): array
    {
        return array_values(array_filter($this->accounts, fn($a) => $a->userUid === $userUid && $a->active));
    }

    /**
     * T391: every account the uid holds, active or not.
     *
     * The `active` filter is absent on purpose and is the only difference from
     * `findActiveByUserUid()`. A test double that quietly applied the same filter would make the
     * listener look correct while proving nothing — the already-deactivated account it must skip would
     * simply never be handed to it, and the "refuses a no-op" behaviour would never run.
     *
     * @return list<PettyCashAccount>
     */
    public function findAllByUserUid(string $userUid): array
    {
        return array_values(array_filter($this->accounts, fn($a) => $a->userUid === $userUid));
    }

    /**
     * @return list<PettyCashAccount>
     */
    public function findActiveByUserAndCurrency(string $userUid, CurrencyCode $currency): array
    {
        return array_values(array_filter($this->accounts, fn($a) => $a->userUid === $userUid && $a->active && $a->currencyCode()->equals($currency)));
    }

    /**
     * Every account the double holds, active and inactive alike (T379).
     *
     * Deliberately not filtered on `active`, so the double cannot agree with a count that silently
     * dropped deactivated accounts.
     */
    public function countAll(): int
    {
        return count($this->accounts);
    }

    /**
     * T422. Deliberately not filtered on `active`, for the same reason `countAll()` is not: a
     * **deactivated** account still holds its balance, and a scale change would still reinterpret it — so
     * counting only active accounts here would let the double agree with a guard the database refuses.
     */
    public function countByCurrencyId(int $currencyId): int
    {
        return count(array_filter(
            $this->accounts,
            static fn(PettyCashAccount $a): bool => $a->currency->id === $currencyId
        ));
    }

    public function save(PettyCashAccount $account): void
    {
        $this->accounts[$account->id] = $account;
    }

    /** Test isolation: the double is a container singleton shared across a test process. */
    public function clear(): void
    {
        $this->accounts = [];
    }
}