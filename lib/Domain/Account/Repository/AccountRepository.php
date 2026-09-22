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
    public function save(PettyCashAccount $account): void;
}