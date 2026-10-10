<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Expense\Repository;

use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;

/** @extends \IteratorAggregate<int, ExpenseTransaction> */
interface ExpenseTransactionRepository
{
    public function findById(int $id): ?ExpenseTransaction;
    public function findByPublicId(string $publicId): ?ExpenseTransaction;
    /** @return list<ExpenseTransaction> */
    public function findBySheetId(int $sheetId): array;
    /**
     * How many transactions a sheet holds.
     *
     * `ExpenseSheet` is an aggregate that owns its transactions, but
     * `DbExpenseSheetRepository` does not compose them, so a sheet loaded from the database
     * reports an empty collection. A response that counts from that aggregate therefore
     * answers `transactionCount: 0` for a sheet that plainly has transactions, which
     * contradicts the `status` it reports in the same object. Counting through the
     * repository keeps the read model honest without loading every revision.
     */
    public function countBySheetId(int $sheetId): int;
    /** @return list<ExpenseTransaction> */
    public function findByOwnerUid(string $ownerUid, ?WorkflowStage $stage = null, ?TransactionStatus $status = null, int $offset = 0, int $limit = 100): array;
    /** @return list<ExpenseTransaction> */
    public function findByCostCenterId(int $costCenterId, ?WorkflowStage $stage = null, ?TransactionStatus $status = null, int $offset = 0, int $limit = 100): array;
    /** @return list<ExpenseTransaction> */
    public function findByStageAndStatus(WorkflowStage $stage, ?TransactionStatus $status = null, int $offset = 0, int $limit = 100): array;
    /** @return list<ExpenseTransaction> */
    public function findForFinanceQueue(int $offset = 0, int $limit = 100): array;

    /**
     * How many transactions exist instance-wide, at every stage and in every status.
     *
     * Added for T379. `DashboardController` published `count(findForFinanceQueue(...))` under the
     * key `totalTransactions`, so the administrator's dashboard reported the depth of the finance
     * queue while calling it the instance's transaction total. On a healthy instance the queue is
     * near empty, so the figure read as "this installation has processed almost nothing" to an
     * administrator looking at a system with ten thousand expense records in it.
     *
     * Zero, not an exception, when there are none.
     */
    public function countAll(): int;

    public function save(ExpenseTransaction $transaction): void;
    /**
     * Remove a transaction and its revisions outright.
     *
     * Only ever valid for a draft that was never submitted: FR-025 lets a purchaser
     * delete a transaction before submission, and FR-058 makes that the point after
     * which records stop being removable. A submitted transaction is retained as
     * business history under Constitution IV, so a caller reaching this with one has
     * a defect upstream rather than a permission to erase it.
     */
    public function delete(ExpenseTransaction $transaction): void;
}