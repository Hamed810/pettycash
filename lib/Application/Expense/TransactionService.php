<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Expense;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\TransactionManager;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Configuration\Repository\ExpenseTypeRepository;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Shared\Uuid;

final readonly class TransactionService
{
    public function __construct(
        private ExpenseSheetRepository $sheetRepository,
        private ExpenseTransactionRepository $transactionRepository,
        private TransactionRevisionRepository $revisionRepository,
        private CostCenterRepository $costCenterRepository,
        private ExpenseTypeRepository $expenseTypeRepository,
        private CurrentUser $currentUser,
        private TransactionManager $transactionManager
    ) {}

    /**
     * Create a draft transaction in an open sheet
     *
     * @param string $sheetId
     * @param string $expenseDate
     * @param int $amountMinor
     * @return ExpenseTransaction
     */
    public function createTransaction(string $sheetId, string $expenseDate, int $amountMinor): ExpenseTransaction
    {
        $ownerUid = $this->currentUser->uid();
        $sheet = $this->sheetRepository->findByPublicId($sheetId);

        if ($sheet === null || $sheet->ownerUid !== $ownerUid) {
            throw new InvalidArgumentException('Sheet not found');
        }

        if ($sheet->status !== \OCA\PettyCash\Domain\Workflow\SheetStatus::Open) {
            throw new InvalidArgumentException('Cannot add transactions to a submitted sheet');
        }

        $date = new DateTimeImmutable($expenseDate);
        $currency = $sheet->currency;
        $amount = Money::positive($amountMinor, $currency);

        $transactionId = (string)Uuid::random();
        $revisionId = (string)Uuid::random();

        $revision = TransactionRevision::draft($revisionId, 1, $date, $amount);
        $transaction = new ExpenseTransaction($transactionId, $ownerUid, $revision);
        $transaction->sheetId = $sheet->dbId;

        return $this->transactionManager->transactional(function () use ($sheet, $transaction, $revision): ExpenseTransaction {
            // `pettycash_transaction_revisions.transaction_id` is a NOT NULL join key onto
            // `pettycash_transactions.id`, and `pettycash_transactions.active_revision_id` points
            // back at the revision, so the two rows have to be written in three
            // steps inside one transaction: the transaction first (leaving
            // active_revision_id null), then the revision, then the back-link.
            $this->transactionRepository->save($transaction);

            $revision->transactionDbId = $transaction->dbId;
            $this->revisionRepository->save($revision);

            $this->transactionRepository->save($transaction);

            $sheet->addTransaction($transaction);
            $this->sheetRepository->save($sheet);
            return $transaction;
        });
    }

    /**
     * Update a draft transaction
     *
     * @param string $transactionId
     * @param string $expenseDate
     * @param int $amountMinor
     * @param string|null $description
     * @param string|null $costCenterId
     * @param string|null $expenseTypeTitle
     * @param int $expectedVersion
     * @return ExpenseTransaction
     */
    public function updateTransaction(
        string $transactionId,
        string $expenseDate,
        int $amountMinor,
        ?string $description,
        ?string $costCenterId,
        ?string $expenseTypeTitle,
        int $expectedVersion
    ): ExpenseTransaction {
        $ownerUid = $this->currentUser->uid();
        $transaction = $this->transactionRepository->findByPublicId($transactionId);

        if ($transaction === null || $transaction->ownerUid !== $ownerUid) {
            throw new InvalidArgumentException('Transaction not found');
        }

        $sheet = $this->sheetRepository->findById($transaction->sheetId ?? 0);
        if ($sheet === null || $sheet->status !== \OCA\PettyCash\Domain\Workflow\SheetStatus::Open) {
            throw new InvalidArgumentException('Cannot update transactions in a submitted sheet');
        }

        if ($transaction->lockVersion !== $expectedVersion) {
            throw new InvalidArgumentException('Stale version');
        }

        $date = new DateTimeImmutable($expenseDate);
        $currency = $sheet->currency;
        $amount = Money::positive($amountMinor, $currency);

        // Create a new revision for the update
        $revisionId = (string)Uuid::random();
        $revision = TransactionRevision::draft($revisionId, $transaction->revision()->number() + 1, $date, $amount, $transaction->revision()->id());

        if ($description !== null && $costCenterId !== null && $expenseTypeTitle !== null) {
            // Look up CostCenter by public ID (UUID) to get snapshot data
            $costCenter = $this->costCenterRepository->findByPublicId($costCenterId);
            if ($costCenter === null) {
                throw new InvalidArgumentException("Cost center not found: {$costCenterId}");
            }

            // Look up ExpenseType by public ID (UUID) to get snapshot data
            $expenseType = $this->expenseTypeRepository->findByPublicId($expenseTypeTitle);
            if ($expenseType === null) {
                throw new InvalidArgumentException("Expense type not found: {$expenseTypeTitle}");
            }
            if (!$expenseType->active) {
                throw new InvalidArgumentException("Expense type is inactive: {$expenseTypeTitle}");
            }

            $hasEvidence = $transaction->revision()->hasEvidence();
            $revision->complete(
                $description,
                $costCenter->publicId,
                $costCenter->code(),
                $costCenter->name,
                $costCenter->id ?? 0,
                $expenseType->title,
                $expenseType->id ?? 0,
                $hasEvidence
            );
        }

        $transaction->activate($revision);
        $transaction->lockVersion++;

        return $this->transactionManager->transactional(function () use ($transaction, $revision): ExpenseTransaction {
            // See createTransaction(): the revision needs the transaction's internal
            // id, and the transaction's active_revision_id needs the revision's.
            $this->transactionRepository->save($transaction);

            $revision->transactionDbId = $transaction->dbId;
            $this->revisionRepository->save($revision);

            $this->transactionRepository->save($transaction);
            return $transaction;
        });
    }

    /**
     * Delete a draft transaction from an open sheet
     *
     * @param string $transactionId
     * @param int $expectedVersion
     */
    public function deleteTransaction(string $transactionId, int $expectedVersion): void
    {
        $ownerUid = $this->currentUser->uid();
        $transaction = $this->transactionRepository->findByPublicId($transactionId);

        if ($transaction === null || $transaction->ownerUid !== $ownerUid) {
            throw new InvalidArgumentException('Transaction not found');
        }

        $sheet = $this->sheetRepository->findById($transaction->sheetId ?? 0);
        if ($sheet === null || $sheet->status !== \OCA\PettyCash\Domain\Workflow\SheetStatus::Open) {
            throw new InvalidArgumentException('Cannot delete transactions from a submitted sheet');
        }

        if ($transaction->lockVersion !== $expectedVersion) {
            throw new InvalidArgumentException('Stale version');
        }

        $this->transactionManager->transactional(function () use ($sheet, $transaction): void {
            $sheet->removeTransaction($transaction->id);
            $this->transactionRepository->save($transaction);
            $this->sheetRepository->save($sheet);
        });
    }

    /**
     * Get a transaction by ID with owner-scoped access
     *
     * @param string $transactionId
     * @return ExpenseTransaction|null
     */
    public function getTransaction(string $transactionId): ?ExpenseTransaction
    {
        $ownerUid = $this->currentUser->uid();
        $transaction = $this->transactionRepository->findByPublicId($transactionId);

        if ($transaction === null || $transaction->ownerUid !== $ownerUid) {
            return null;
        }

        return $transaction;
    }
}