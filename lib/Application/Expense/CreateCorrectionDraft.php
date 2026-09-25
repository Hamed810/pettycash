<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Expense;

use Exception;
use InvalidArgumentException;
use LogicException;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository;
use OCA\PettyCash\Application\Port\TransactionManager;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Shared\Uuid;

class CreateCorrectionDraft
{
    public function __construct(
        private CurrentUser $currentUser,
        private ExpenseTransactionRepository $transactionRepository,
        private TransactionRevisionRepository $revisionRepository,
        private TransactionManager $transactionManager
    ) {}

    /**
     * Create a correction draft from the active returned revision
     *
     * @param string $transactionPublicId
     * @param string $commandId
     * @param int $expectedVersion
     * @return TransactionRevision The new draft revision
     */
    public function execute(string $transactionPublicId, string $commandId, int $expectedVersion): TransactionRevision
    {
        $actorUid = $this->currentUser->uid();

        return $this->transactionManager->transactional(function () use ($transactionPublicId, $actorUid, $commandId, $expectedVersion): TransactionRevision {
            $transaction = $this->transactionRepository->findByPublicId($transactionPublicId);

            if ($transaction === null) {
                throw new InvalidArgumentException('Transaction not found');
            }

            if ($transaction->lockVersion !== $expectedVersion) {
                throw new InvalidArgumentException('Stale version');
            }

            // Verify transaction is in a state that allows correction
            if ($transaction->status !== TransactionStatus::AwaitingPurchaserCorrection) {
                throw new LogicException('Transaction is not awaiting purchaser correction');
            }

            // Verify ownership
            if ($transaction->ownerUid !== $actorUid) {
                throw new LogicException('Only the transaction owner can create a correction');
            }

            // Get the active revision to correct
            $activeRevision = $transaction->revision();

            // Create new draft revision by cloning the active one
            $newRevision = $transaction->createCorrectionRevision((string)Uuid::random());

            // Save the new revision
            $this->revisionRepository->save($newRevision);

            // Update transaction to point to new active revision (as draft)
            // The revision is created as a draft (not activated)
            // We don't call activate() here - the draft remains editable until resubmit

            // Save transaction (lock version will be incremented)
            $this->transactionRepository->save($transaction);

            return $newRevision;
        });
    }
}