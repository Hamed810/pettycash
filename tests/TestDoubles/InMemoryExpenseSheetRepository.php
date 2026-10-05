<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\SheetListFilter;
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
    public function findByOwnerUid(string $ownerUid, ?SheetListFilter $filter = null, int $offset = 0, int $limit = 100): array
    {
        return array_slice($this->ownedBy($ownerUid, $filter), $offset, $limit);
    }

    /**
     * T332: the same predicate as `findByOwnerUid()`, without the window.
     *
     * The filter is shared rather than written twice so the double cannot answer a count that disagrees
     * with the read it describes -- the whole point of the method, and the reason
     * `DbExpenseSheetRepository` extracts the same scope into one place.
     */
    public function countByOwnerUid(string $ownerUid, ?SheetListFilter $filter = null): int
    {
        return count($this->ownedBy($ownerUid, $filter));
    }

    /**
     * Every facet of {@see SheetListFilter}, matched the way the persistence layer matches it.
     *
     * The currency and the date bounds are compared the same way the SQL compares them -- string equality
     * on the code, inclusive bounds on `createdAt` -- so a unit test using this double and a contract test
     * running against MariaDB are asking the same question. A double that filtered more loosely would
     * let a filter bug pass the unit suite and fail only over HTTP.
     *
     * @return list<ExpenseSheet>
     */
    private function ownedBy(string $ownerUid, ?SheetListFilter $filter): array
    {
        return array_values(array_filter(
            $this->sheets,
            function (ExpenseSheet $sheet) use ($ownerUid, $filter): bool {
                if ($sheet->ownerUid !== $ownerUid) {
                    return false;
                }
                if ($filter === null) {
                    return true;
                }
                if ($filter->status !== null && $sheet->status !== $filter->status) {
                    return false;
                }
                if ($filter->currency !== null
                    && !$sheet->currency->equals($filter->currency)
                ) {
                    return false;
                }
                if ($filter->from !== null
                    && ($sheet->createdAt === null || $sheet->createdAt < $filter->from)
                ) {
                    return false;
                }
                if ($filter->to !== null
                    && ($sheet->createdAt === null || $sheet->createdAt > $filter->to)
                ) {
                    return false;
                }

                return true;
            }
        ));
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