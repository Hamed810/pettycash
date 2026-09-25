<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository;

final class InMemoryTransactionRevisionRepository implements \OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository
{
    /**
     * @var array<int, TransactionRevision>
     */
    private array $revisions = [];

    public function findById(int $id): ?TransactionRevision
    {
        return $this->revisions[$id] ?? null;
    }

    public function findByPublicId(string $publicId): ?TransactionRevision
    {
        foreach ($this->revisions as $rev) {
            if ($rev->publicId === $publicId) {
                return $rev;
            }
        }
        return null;
    }

    /**
     * @return list<TransactionRevision>
     */
    public function findByTransactionId(int $transactionId): array
    {
        $matching = array_filter(
            $this->revisions,
            static fn (TransactionRevision $rev): bool => $rev->transactionDbId === $transactionId
        );
        return array_values($matching);
    }

    public function findActiveByTransactionId(int $transactionId): ?TransactionRevision
    {
        foreach ($this->revisions as $rev) {
            if ($rev->transactionDbId === $transactionId && $rev->isActive()) {
                return $rev;
            }
        }
        return null;
    }

    public function save(TransactionRevision $revision): void
    {
        // Mirrors DbTransactionRevisionRepository: `transaction_id` is a NOT NULL
        // join key, so a revision without its owning transaction must never be
        // persisted (not even to a placeholder value).
        if ($revision->transactionDbId === null) {
            throw new \LogicException(
                'Cannot persist a transaction revision without its owning transaction id'
            );
        }

        if ($revision->dbId === null) {
            $revision->dbId = count($this->revisions) + 1;
        }
        $this->revisions[$revision->dbId] = $revision;
    }

    public function clear(): void
    {
        $this->revisions = [];
    }
}