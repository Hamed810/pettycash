<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Domain\Account\AccountMovement;
use OCA\PettyCash\Domain\Account\Repository\AccountMovementRepository;

final class InMemoryAccountMovementRepository implements \OCA\PettyCash\Domain\Account\Repository\AccountMovementRepository
{
    /**
     * @var array<int, AccountMovement>
     */
    private array $movements = [];

    public function findById(int $id): ?AccountMovement
    {
        return $this->movements[$id] ?? null;
    }

    public function findByCommandId(string $commandId): ?AccountMovement
    {
        foreach ($this->movements as $movement) {
            if ($movement->commandId === $commandId) {
                return $movement;
            }
        }
        return null;
    }

    public function findBySourceRevisionId(int $sourceRevisionId): ?AccountMovement
    {
        foreach ($this->movements as $movement) {
            if ($movement->sourceRevisionId === $sourceRevisionId) {
                return $movement;
            }
        }
        return null;
    }

    /**
     * @return list<AccountMovement>
     */
    public function findByAccountId(int $accountId, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        return array_values(array_filter($this->movements, fn($m) => $m->id === (string)$accountId));
    }

    /**
     * @return list<AccountMovement>
     */
    public function findByAccountIdPaged(int $accountId, int $offset, int $limit): array
    {
        $result = array_filter($this->movements, fn($m) => $m->id === (string)$accountId);
        return array_slice(array_values($result), $offset, $limit);
    }

    public function save(AccountMovement $movement): void
    {
        $this->movements[(int)$movement->id] = $movement;
    }
}