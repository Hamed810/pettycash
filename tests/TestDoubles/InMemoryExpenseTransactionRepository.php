<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;

final class InMemoryExpenseTransactionRepository implements \OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository
{
    /**
     * @var array<int, ExpenseTransaction>
     */
    private array $transactions = [];

    public function findById(int $id): ?ExpenseTransaction
    {
        return $this->transactions[$id] ?? null;
    }

    public function findByPublicId(string $publicId): ?ExpenseTransaction
    {
        foreach ($this->transactions as $tx) {
            if ($tx->id === $publicId) {
                return $tx;
            }
        }
        return null;
    }

    /**
     * @return list<ExpenseTransaction>
     */
    public function findBySheetId(int $sheetId): array
    {
        return $this->transactionsForSheet($sheetId);
    }

    /** @return list<ExpenseTransaction> */
    private function transactionsForSheet(int $sheetId): array
    {
        return array_values(array_filter($this->transactions, fn($t) => $t->sheetId === $sheetId));
    }

    public function countBySheetId(int $sheetId): int
    {
        return count($this->transactionsForSheet($sheetId));
    }

    /**
     * @return list<ExpenseTransaction>
     */
    public function findByOwnerUid(string $ownerUid, ?WorkflowStage $stage = null, ?TransactionStatus $status = null, int $offset = 0, int $limit = 100): array
    {
        $result = array_filter($this->transactions, function ($t) use ($ownerUid, $stage, $status) {
            if ($t->ownerUid !== $ownerUid) {
                return false;
            }
            if ($stage !== null && $t->stage !== $stage) {
                return false;
            }
            if ($status !== null && $t->status !== $status) {
                return false;
            }
            return true;
        });
        return array_slice(array_values($result), 0, $limit);
    }

    /**
     * @return list<ExpenseTransaction>
     */
    public function findByCostCenterId(int $costCenterId, ?WorkflowStage $stage = null, ?TransactionStatus $status = null, int $offset = 0, int $limit = 100): array
    {
        $result = array_filter($this->transactions, function ($t) use ($costCenterId, $stage, $status) {
            if ($t->revision()->costCenterId() !== $costCenterId) {
                return false;
            }
            if ($stage !== null && $t->stage !== $stage) {
                return false;
            }
            if ($status !== null && $t->status !== $status) {
                return false;
            }
            return true;
        });
        return array_slice(array_values($result), 0, $limit);
    }

    /**
     * @return list<ExpenseTransaction>
     */
    public function findByStageAndStatus(WorkflowStage $stage, ?TransactionStatus $status = null, int $offset = 0, int $limit = 100): array
    {
        $result = array_filter($this->transactions, function ($t) use ($stage, $status) {
            if ($t->stage !== $stage) {
                return false;
            }
            if ($status !== null && $t->status !== $status) {
                return false;
            }
            return true;
        });
        return array_slice(array_values($result), 0, $limit);
    }

    /**
     * @return list<ExpenseTransaction>
     */
    public function findForFinanceQueue(int $offset = 0, int $limit = 100): array
    {
        $result = array_filter($this->transactions, fn($t) => $t->stage === \OCA\PettyCash\Domain\Workflow\WorkflowStage::Finance);
        return array_slice(array_values($result), $offset, $limit);
    }

    /**
     * Every transaction the double holds, at every stage and in every status (T379).
     *
     * Wider than `findForFinanceQueue()` on purpose, so a controller still publishing that read's
     * length under the name `totalTransactions` cannot satisfy the T379 regression test.
     */
    public function countAll(): int
    {
        return count($this->transactions);
    }

    public function save(ExpenseTransaction $transaction): void
    {
        if ($transaction->dbId === null) {
            $transaction->dbId = count($this->transactions) + 1;
        }
        // `publicId` is assigned from the aggregate's own `id`, mirroring
        // `DbExpenseTransactionRepository::save()` (line 237) and `DbEvidenceRepository::save()`. The
        // real insert writes the `id` into the `public_id` column, so the two are the same value in
        // production -- but the property was left null here, which meant `findByPublicId()` could never
        // find anything a test had just saved, and a test could pass while production could not resolve
        // the id it had been given. Any double that does not mirror this hides that difference.
        if ($transaction->publicId === null) {
            $transaction->publicId = $transaction->id;
        }
        $this->transactions[$transaction->dbId] = $transaction;
    }

    public function delete(ExpenseTransaction $transaction): void
    {
        if ($transaction->dbId !== null) {
            unset($this->transactions[$transaction->dbId]);
        }
    }

    public function clear(): void
    {
        $this->transactions = [];
    }
}