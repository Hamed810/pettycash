<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Account\Repository;

use DateTimeImmutable;
use OCA\PettyCash\Domain\Account\AccountMovement;

/** @extends \IteratorAggregate<int, AccountMovement> */
interface AccountMovementRepository
{
    public function findById(int $id): ?AccountMovement;
    public function findByCommandId(string $commandId): ?AccountMovement;
    public function findBySourceRevisionId(int $sourceRevisionId): ?AccountMovement;
    /** @return list<AccountMovement> */
    public function findByAccountId(int $accountId, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): array;
    /** @return list<AccountMovement> */
    public function findByAccountIdPaged(int $accountId, int $offset, int $limit): array;
    public function save(AccountMovement $movement): void;
}