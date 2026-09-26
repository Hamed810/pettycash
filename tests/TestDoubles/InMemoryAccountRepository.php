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
        return $this->accounts[$id] ?? null;
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
     * @return list<PettyCashAccount>
     */
    public function findActiveByUserAndCurrency(string $userUid, CurrencyCode $currency): array
    {
        return array_values(array_filter($this->accounts, fn($a) => $a->userUid === $userUid && $a->active && $a->currencyCode()->equals($currency)));
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