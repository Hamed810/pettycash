<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Application\StaleRecordVersion;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\SheetListFilter;
use OCA\PettyCash\Domain\Workflow\SheetStatus;

final class InMemoryExpenseSheetRepository implements ExpenseSheetRepository
{
    /**
     * How often the page-hydration entry point ran, for the senior-list bounded-load regression.
     *
     * @var array{findById: int}
     */
    public array $readProbes = ['findById' => 0];

    public function resetReadProbes(): void
    {
        $this->readProbes = ['findById' => 0];
    }

    /**
     * @var array<int, ExpenseSheet>
     */
    private array $sheets = [];

    public function findById(int $id): ?ExpenseSheet
    {
        $this->readProbes['findById']++;
        return $this->sheets[$id] ?? null;
    }

    /**
     * The sheet instants the senior-list projection folds, deliberately not routed through
     * {@see findById()} so the bounded-load regression can separate the projection's own
     * per-candidate reads (uncounted) from the service's page hydration (which does call
     * `findById()` and must stay page-bound).
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable, 2: ?\DateTimeImmutable} created, first submitted, completed
     */
    public function sheetInstantsForProjection(int $id): array
    {
        $sheet = $this->sheets[$id] ?? null;
        return [$sheet?->createdAt, $sheet?->firstSubmittedAt, $sheet?->completedAt];
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
     * Every sheet the double holds, across every status (T379).
     *
     * Every stored row, which is the property a suite needs in order to be able to *catch* the defect:
     * a double that returned only open sheets would satisfy a controller still reading
     * `findByStatus(Open, ...)` under the name `totalSheets`, and the T379 regression test would pass
     * against a double that agreed with the bug.
     */
    public function countAll(): int
    {
        return count($this->sheets);
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

    /**
     * T417 / FR-086. Mirrors `DbExpenseSheetRepository::delete()`, including its `lock_version`
     * predicate: a delete that does not match the version removes nothing and reports a conflict.
     *
     * The predicate is reproduced rather than simplified because a double that deleted unconditionally
     * would make `DeleteSheet`'s own version guard look sufficient — the guard under test would pass
     * while the database-level safety net went untested.
     */
    public function delete(ExpenseSheet $sheet): void
    {
        if ($sheet->dbId === null) {
            throw new \RuntimeException('Cannot delete a sheet that was never persisted');
        }

        $stored = $this->sheets[$sheet->dbId] ?? null;
        if ($stored === null || $stored->lockVersion !== $sheet->lockVersion) {
            throw StaleRecordVersion::movedDuringWrite('sheet', $sheet->id);
        }

        unset($this->sheets[$sheet->dbId]);
    }

    public function clear(): void
    {
        $this->sheets = [];
    }
}