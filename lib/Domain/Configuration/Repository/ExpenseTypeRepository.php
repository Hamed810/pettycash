<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Configuration\Repository;

use OCA\PettyCash\Domain\Configuration\ExpenseType;

/** @extends \IteratorAggregate<int, ExpenseType> */
interface ExpenseTypeRepository
{
    public function findById(int $id): ?ExpenseType;
    public function findByPublicId(string $publicId): ?ExpenseType;
    public function findActiveByTitle(string $title): ?ExpenseType;
    /** @return list<ExpenseType> */
    public function findAllActive(): array;
    public function save(ExpenseType $expenseType): void;
}