<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Workflow\SheetStatus;

final class InMemoryExpenseSheetRepository implements ExpenseSheetRepository
{
    /**
     * @var array<int, ExpenseSheet>
     */
    private array $sheets = [];

    public function findById(int $id): ?ExpenseSheet
    {
        return $this->sheets[$id] ?? null;
    }

    public function findByPublicId(string $publicId): ?ExpenseSheet
    {
        foreach ($this->sheets as $sheet) {
            if ($sheet->publicId === $publicId) {
                return $sheet;
            }
        }
        return null;
    }

    /**
     * @return list<ExpenseSheet>
     */
    public function findByOwnerUid(string $ownerUid, ?\OCA\PettyCash\Domain\Workflow\SheetStatus $status = null, int $offset = 0, int $limit = 100): array
    {
        $result = array_filter($this->sheets, fn($s) => $s->ownerUid === $ownerUid && ($status === null || $s->status === $status));
        return array_slice(array_values($result), $offset, $limit);
    }

    /**
     * @return list<ExpenseSheet>
     */
    public function findByStatus(\OCA\PettyCash\Domain\Workflow\SheetStatus $status, int $offset = 0, int $limit = 100): array
    {
        $result = array_filter($this->sheets, fn($s) => $s->status === $status);
        return array_slice(array_values($result), $offset, $limit);
    }

    public function save(ExpenseSheet $sheet): void
    {
        if ($sheet->dbId === null) {
            $sheet->dbId = count($this->sheets) + 1;
        }
        $this->sheets[$sheet->dbId] = $sheet;
    }

    public function clear(): void
    {
        $this->sheets = [];
    }
}