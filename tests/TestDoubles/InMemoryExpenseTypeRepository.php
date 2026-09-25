<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Domain\Configuration\ExpenseType;
use OCA\PettyCash\Domain\Configuration\Repository\ExpenseTypeRepository;

final class InMemoryExpenseTypeRepository implements ExpenseTypeRepository
{
    /**
     * @var array<int, ExpenseType>
     */
    private array $expenseTypes = [];

    public function __construct()
    {
        $et = ExpenseType::create('Office Supplies', 'General office supplies', true);
        $et->id = 1;
        $this->expenseTypes[1] = $et;
    }

    public function findById(int $id): ?ExpenseType
    {
        return $this->expenseTypes[$id] ?? null;
    }

    public function findByPublicId(string $publicId): ?ExpenseType
    {
        foreach ($this->expenseTypes as $et) {
            if ($et->publicId === $publicId) {
                return $et;
            }
        }
        return null;
    }

    public function findActiveByTitle(string $title): ?ExpenseType
    {
        foreach ($this->expenseTypes as $et) {
            if ($et->title === $title && $et->active) {
                return $et;
            }
        }
        return null;
    }

    /**
     * @return list<ExpenseType>
     */
    public function findAllActive(): array
    {
        return array_values(array_filter($this->expenseTypes, fn($et) => $et->active));
    }

    public function save(ExpenseType $expenseType): void
    {
        if ($expenseType->id === null) {
            $expenseType->id = count($this->expenseTypes) + 1;
        }
        $this->expenseTypes[$expenseType->id] = $expenseType;
    }
}