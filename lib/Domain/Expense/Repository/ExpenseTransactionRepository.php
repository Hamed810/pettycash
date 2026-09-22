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
    /** @return list<ExpenseTransaction> */
    public function findByOwnerUid(string $ownerUid, ?WorkflowStage $stage = null, ?TransactionStatus $status = null, int $offset = 0, int $limit = 100): array;
    /** @return list<ExpenseTransaction> */
    public function findByCostCenterId(int $costCenterId, ?WorkflowStage $stage = null, ?TransactionStatus $status = null, int $offset = 0, int $limit = 100): array;
    /** @return list<ExpenseTransaction> */
    public function findByStageAndStatus(WorkflowStage $stage, ?TransactionStatus $status = null, int $offset = 0, int $limit = 100): array;
    /** @return list<ExpenseTransaction> */
    public function findForFinanceQueue(int $offset = 0, int $limit = 100): array;
    public function save(ExpenseTransaction $transaction): void;
}