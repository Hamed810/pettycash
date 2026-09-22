<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Expense\Repository;

use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Workflow\SheetStatus;

/** @extends \IteratorAggregate<int, ExpenseSheet> */
interface ExpenseSheetRepository
{
    public function findById(int $id): ?ExpenseSheet;
    public function findByPublicId(string $publicId): ?ExpenseSheet;
    /** @return list<ExpenseSheet> */
    public function findByOwnerUid(string $ownerUid, ?SheetStatus $status = null, int $offset = 0, int $limit = 100): array;
    /** @return list<ExpenseSheet> */
    public function findByStatus(SheetStatus $status, int $offset = 0, int $limit = 100): array;
    public function save(ExpenseSheet $sheet): void;
}