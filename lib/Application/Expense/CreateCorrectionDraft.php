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
use OCA\PettyCash\Application\StaleRecordVersion;
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

            // A `Conflict`, like the `NotAwaitingCorrection` refusal below it — for the same reason and on the
            // same endpoint. Both are "the world moved, reload"; answering this one 422 would have
            // split one endpoint's two correct refusals across two statuses.
            if ($transaction->lockVersion !== $expectedVersion) {
                throw StaleRecordVersion::forResource(
                    'transaction',
                    $transactionPublicId,
                    $expectedVersion,
                    $transaction->lockVersion
                );
            }

            // Verify transaction is in a state that allows correction
            //
            // **This was a bare `LogicException`, so it answered 500.** `OcsErrorMapper` deliberately has
            // no `LogicException` arm — the right decision for a fault nobody can act on — and the default
            // arm is `500 UNEXPECTED_FAILURE` with the message withheld. A caller whose view of the
            // transaction was simply out of date was therefore told *the server had a fault*, and told
            // nothing about the state that caused it. `NotAwaitingCorrection` carries a `409`, which is
            // the answer that matches: the request is right and the world moved.
            if ($transaction->status !== TransactionStatus::AwaitingPurchaserCorrection) {
                throw new NotAwaitingCorrection($transactionPublicId, $transaction->status->value);
            }

            // Verify ownership
            //
            // **Also a bare `LogicException`, also a 500.** This one is a permission refusal, so it is a
            // `403`: see `NotTransactionOwner` for why this path does not use the `404` treatment that
            // evidence does, and why that difference matters to a caller trying to act.
            if ($transaction->ownerUid !== $actorUid) {
                throw new NotTransactionOwner();
            }

            // Get the active revision to correct
            $activeRevision = $transaction->revision();

            // Create new draft revision by cloning the active one
            $newRevision = $transaction->createCorrectionRevision((string)Uuid::random());

            // `TransactionRevision::correction()` clones the business fields but builds a
            // fresh aggregate, so the owning-transaction join key has to be carried
            // over. `pettycash_transaction_revisions.transaction_id` is NOT NULL, and
            // without it the save is refused with "Cannot persist a transaction
            // revision without its owning transaction id" and the endpoint answers
            // 403 for a correction the caller is entitled to make.
            $newRevision->transactionDbId = $transaction->dbId;

            // Save the new revision
            $this->revisionRepository->save($newRevision);

            // Attach it to the transaction.
            //
            // This was missing, and it made the whole correction path inert: the draft was an orphan
            // row that nothing referenced, so every later read re-loaded the revision being
            // corrected and `resubmitCorrection()`'s `activate($this->activeRevision)` activated
            // **that** one. The purchaser's corrections were stored and then thrown away on
            // resubmission. `attachWorkingRevision()` moves the working pointer and deliberately
            // leaves the draft inactive, the stage at `CORRECTION`, and the status awaiting the
            // purchaser — `workflow.md`'s clause 5 puts activation at resubmission, not here.
            $transaction->attachWorkingRevision($newRevision);

            // Save transaction (lock version will be incremented)
            $this->transactionRepository->save($transaction);

            return $newRevision;
        });
    }
}