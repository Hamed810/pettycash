<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Expense\Repository;

use OCA\PettyCash\Domain\Expense\TransactionRevision;

/** @extends \IteratorAggregate<int, TransactionRevision> */
interface TransactionRevisionRepository
{
    public function findById(int $id): ?TransactionRevision;
    public function findByPublicId(string $publicId): ?TransactionRevision;
    /** @return list<TransactionRevision> */
    public function findByTransactionId(int $transactionId): array;
    public function findActiveByTransactionId(int $transactionId): ?TransactionRevision;
    public function save(TransactionRevision $revision): void;
}