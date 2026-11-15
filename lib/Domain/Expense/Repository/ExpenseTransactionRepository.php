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
    /**
     * Every transaction whose current revision belongs to one of these cost centres.
     *
     * Used by the manager queues: one read over the de-duplicated authorized scope, ordered
     * deterministically so the caller can merge, de-duplicate, and paginate once. Asking per
     * cost centre instead let overlapping assignments fetch the same rows repeatedly.
     *
     * @param list<int> $costCenterIds
     * @return list<ExpenseTransaction>
     */
    public function findByCostCenterIds(array $costCenterIds, ?WorkflowStage $stage = null, ?TransactionStatus $status = null): array;

    /**
     * Compact, database-side projection for `ManagementReviewService::getSeniorSheetsForManager()`.
     *
     * Answers one scalar row per candidate sheet — a sheet that has at least one transaction whose
     * **active** revision sits in a managed cost centre — so the list path never hydrates historical
     * transactions as full aggregates before pagination and never issues per-sheet or per-transaction
     * reads. The row carries the senior-scoped counts, the Return flag, the whole-sheet Cost Center
     * completion-gate result (evaluated over **every** transaction of the sheet, including those
     * outside the given scope) and the activity components the caller folds into `latestActivityAt`.
     *
     * @param string $actorUid the acting senior manager's uid, whose own decisions are disclosed
     * @param list<int> $costCenterIds managed cost-centre scope (matched on the active revision)
     * @return array<int, array{
     *   awaitingSenior: int,
     *   decidedBySenior: int,
     *   returnedByThisSenior: bool,
     *   ccGateUnresolved: int,
     *   activityCreated: ?\DateTimeImmutable,
     *   activityUpdated: ?\DateTimeImmutable,
     *   activityDecided: ?\DateTimeImmutable,
     *   sheetCreated: ?\DateTimeImmutable,
     *   sheetFirstSubmitted: ?\DateTimeImmutable,
     *   sheetCompleted: ?\DateTimeImmutable,
     * }> keyed by the sheet's database id
     */
    public function seniorSheetListRows(string $actorUid, array $costCenterIds): array;
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