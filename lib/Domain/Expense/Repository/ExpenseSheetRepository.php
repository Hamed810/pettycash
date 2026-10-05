<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Expense\Repository;

use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\SheetListFilter;
use OCA\PettyCash\Domain\Workflow\SheetStatus;

/** @extends \IteratorAggregate<int, ExpenseSheet> */
interface ExpenseSheetRepository
{
    public function findById(int $id): ?ExpenseSheet;
    public function findByPublicId(string $publicId): ?ExpenseSheet;
    /** @return list<ExpenseSheet> */
    public function findByOwnerUid(string $ownerUid, ?SheetListFilter $filter = null, int $offset = 0, int $limit = 100): array;
    /**
     * How many sheets this owner holds, ignoring any window but honouring the same filter as the read
     * it is reported beside.
     *
     * Added for T332. `GET /sheets` answers with `PageMeta` (`contracts/ocs-api.yaml:930-936`),
     * whose `total` is the count of records in scope rather than the length of the page beside it --
     * the same rule `countByTransactionId()` states for the audit page and `AccountService::listMovements()`
     * documents for the account page ("total counts the ledger, not the page").
     *
     * It could not be read off `findByOwnerUid()`, which is bounded by `$limit`: a caller with 40
     * sheets and a page size of 25 was told it held 25, so the response contradicted itself and any
     * client deriving a page count from `total` stopped after the first page. A count taken from a
     * paged read is that defect wearing a plausible number, which is why it needs its own read.
     *
     * `$filter` is the *whole* predicate rather than a status, because T333 added the remaining
     * FR-064 facets. Filter-then-count is the only order in which `total` means "matching records": a
     * count taken before the narrowing answers "how many sheets do you own" beside a page of sheets the
     * caller filtered, and the two numbers then describe different sets. The two methods therefore take
     * one filter object, which is what stops an implementation building the read from one predicate and
     * the count from another. Zero, not an exception, when nothing matches.
     */
    public function countByOwnerUid(string $ownerUid, ?SheetListFilter $filter = null): int;
    /** @return list<ExpenseSheet> */
    public function findByStatus(SheetStatus $status, int $offset = 0, int $limit = 100): array;
    public function save(ExpenseSheet $sheet): void;
}