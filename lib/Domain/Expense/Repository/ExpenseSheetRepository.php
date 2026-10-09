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

    /**
     * How many sheets exist instance-wide, across every status.
     *
     * Added for T379. `DashboardController` published `count(findByStatus(Open, ...))` under the
     * key `totalSheets`, so the administrator's dashboard reported how many sheets were *open* while
     * naming them a total — a figure that was wrong in the one direction an administrator cannot
     * detect, because an instance with nothing open and an instance with fifty completed sheets both
     * answered `0`.
     *
     * It is a count rather than an unbounded read for the same reason `countByOwnerUid()` is: a
     * dashboard figure derived from a bounded read is a window size wearing a total's name, which is
     * exactly what `figuresBoundedBy` cannot catch because the *bound* would be honest while the
     * *predicate* was not.
     *
     * Zero, not an exception, when there are none.
     */
    public function countAll(): int;

    public function save(ExpenseSheet $sheet): void;

    /**
     * Remove a sheet row.
     *
     * **Added by T417 for FR-086** (owner deletion of a never-submitted draft sheet), which this
     * interface could not previously express: it had `save()` and no `delete()`, so an abandoned sheet
     * could not be removed through any layer.
     *
     * ## What this method deliberately does NOT do
     *
     * It removes the **sheet row only**. It does not cascade to transactions, revisions, or evidence,
     * and it cannot reach the AppData binaries — a repository has no file store. Cascade is the
     * application layer's job (`DeleteSheet`), which is the layer that knows both the ordering
     * requirements and the storage port.
     *
     * That division is the reason it is safe for this method to be dumb: every child row is removed by
     * `ExpenseTransactionRepository::delete()`, which already deletes that transaction's revisions and
     * evidence rows, and the file bytes are removed while their keys are still readable. A cascading
     * delete here would duplicate those three responsibilities and reach for a port this interface does
     * not have.
     */
    public function delete(ExpenseSheet $sheet): void;
}