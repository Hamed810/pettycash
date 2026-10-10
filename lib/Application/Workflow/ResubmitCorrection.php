<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Workflow;

use Exception;
use InvalidArgumentException;
use LogicException;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Configuration\CostCenter;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Application\Port\TransactionManager;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Security\AuthorizationService;
use OCA\PettyCash\Application\Security\AuthorizationContext;
use OCA\PettyCash\Application\StaleRecordVersion;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCA\PettyCash\Domain\Audit\AuditEvent;
use OCA\PettyCash\Domain\Shared\Enum\AuditEventType;

class ResubmitCorrection
{
    public function __construct(
        private CurrentUser $currentUser,
        private ExpenseTransactionRepository $transactionRepository,
        private CostCenterRepository $costCenterRepository,
        private UserDirectory $userDirectory,
        private AuditWriter $auditWriter,
        private TransactionManager $transactionManager,
        private IdempotencyService $idempotencyService,
        private AuthorizationService $authorizationService,
        private TransactionRevisionRepository $revisionRepository
    ) {}

    /**
     * Validate and activate a correction revision, routing it to cost-center review
     *
     * @param string $transactionPublicId
     * @param string $commandId
     * @param int $expectedVersion
     * @return array Updated transaction data
     */
    public function execute(string $transactionPublicId, string $commandId, int $expectedVersion): array
    {
        $actorUid = $this->currentUser->uid();

        return $this->transactionManager->transactional(function () use ($transactionPublicId, $actorUid, $commandId, $expectedVersion): array {
            // Check idempotency
            $payload = [
                'transaction_id' => $transactionPublicId,
                'command_id' => $commandId,
                'action' => 'resubmit_correction'
            ];

            return $this->idempotencyService->execute($commandId, $payload, function () use ($transactionPublicId, $actorUid, $commandId, $expectedVersion): array {
                $transaction = $this->transactionRepository->findByPublicId($transactionPublicId);

                if ($transaction === null) {
                    throw new InvalidArgumentException('Transaction not found');
                }

                // A `Conflict`, matching the draft-edit path. FR-025 covers the owner's own pre-submission
                // edits, and a resubmission is one; answering 422 here while `PATCH
                // /transactions/{id}` answered 409 would make the same purchaser action differ by
                // which screen it was taken from.
                if ($transaction->lockVersion !== $expectedVersion) {
                    throw StaleRecordVersion::forResource(
                        'transaction',
                        $transactionPublicId,
                        $expectedVersion,
                        $transaction->lockVersion
                    );
                }

                // Verify transaction is in correction state
                if ($transaction->status !== TransactionStatus::AwaitingPurchaserCorrection) {
                    throw new LogicException('Transaction is not awaiting purchaser correction');
                }

                // Verify ownership
                if ($transaction->ownerUid !== $actorUid) {
                    throw new LogicException('Only the transaction owner can resubmit a correction');
                }

                // Get the active revision (which should be the correction draft)
                $activeRevision = $transaction->revision();

                // Validate the correction draft has all required fields for submission
                if ($activeRevision->costCenterId() === null) {
                    throw new InvalidArgumentException('Transaction missing cost center');
                }
                if ($activeRevision->expenseTypeTitle() === null) {
                    throw new InvalidArgumentException('Transaction missing expense type');
                }
                if ($activeRevision->description() === null || trim($activeRevision->description()) === '') {
                    throw new InvalidArgumentException('Transaction missing description');
                }
                if (!$activeRevision->hasEvidence()) {
                    throw new InvalidArgumentException('Transaction missing required evidence');
                }

                // Verify the cost center still exists.
                //
                // `TransactionRevision::costCenterId()` is the cost center's public
                // UUID, but `findByCode()` looks up a business code such as `CC-100`,
                // so passing one to the other always misses and every resubmission
                // failed with "Cost center not found: 22222222-…". The numeric join key
                // resolves it directly, with the code snapshot as the fallback.
                $costCenter = $this->resolveCostCenter($activeRevision);
                if ($costCenter === null) {
                    throw new InvalidArgumentException('Cost center not found on the submitted revision');
                }
                $costCenterCode = $costCenter->code();

                // Submit the correction (activate revision and route to cost-center review)
                $submittedTotal = $transaction->resubmitCorrection();

                // Persist the revision that was just activated.
                //
                // `resubmitCorrection()` calls `activate()`, which sets `active` and `activated_at` on
                // the revision **in memory**, but nothing here wrote the row — only the transaction
                // was saved, and its `active_revision_id` points *at* the revision rather than carrying
                // its state. So the activation was silently discarded and the next read loaded the
                // revision still inactive. Measured: the resubmission answered `200` with
                // `activeRevision.amountMinor: 24680` and `activeRevision.active: false`, while the
                // revision history still showed the superseded revision 1 as the only active one — so
                // FR-050's "the active revision determines current information" was decided by a
                // revision the database still considered superseded.
                //
                // Saving the revision is also what makes `workflow.md`'s clause 5 durable: activation
                // is meant to be the moment the new revision becomes current, and it has to survive
                // the request that caused it.
                $this->revisionRepository->save($transaction->revision());

                // Create audit event for correction resubmission.
                //
                // `AuditWriter::append()` reads snake_case keys, so the payload has to be
                // an array with those names. Casting the AuditEvent object instead yields
                // camelCase properties, `command_id` is never found, and the insert fails
                // on the NOT NULL `command_id` column — which is the HTTP 500 a
                // resubmission produced.
                $this->auditWriter->append([
                    'id' => (string)Uuid::random(),
                    'sheet_id' => $transaction->sheetId,
                    'transaction_id' => $transaction->dbId,
                    'revision_id' => $activeRevision->dbId,
                    'stage' => WorkflowStage::CostCenter->value,
                    'decision_id' => null,
                    'entity_type' => 'transaction',
                    'entity_id' => $transactionPublicId,
                    'event_type' => AuditEventType::Corrected->value,
                    'actor_uid' => $actorUid,
                    'actor_role' => 'PURCHASER',
                    'explanation' => $explanation ?? '',
                    'before' => [],
                    'after' => [
                        'transaction_id' => $transactionPublicId,
                        'revision_number' => $activeRevision->number(),
                        'resubmitted_total_minor' => $submittedTotal,
                    ],
                    'command_id' => $commandId,
                ]);

                // Persist the transaction
                $this->transactionRepository->save($transaction);

                return [
                    'transaction_id' => $transactionPublicId,
                    'submitted_total' => $submittedTotal,
                    'revision_number' => $activeRevision->number(),
                ];
            });
        });
    }

    /**
     * Resolve the cost center a revision points at.
     *
     * The revision carries three representations of the same cost center: the public
     * UUID in `costCenterId()`, the numeric join key in `costCenterDbId`, and the
     * submission-time code snapshot in `costCenterCode`. Only the numeric key and the
     * code are usable as lookups, and they are tried in that order because the numeric
     * key is the one the repository indexes.
     */
    private function resolveCostCenter(TransactionRevision $revision): ?CostCenter
    {
        if ($revision->costCenterDbId !== null) {
            $byId = $this->costCenterRepository->findById($revision->costCenterDbId);
            if ($byId !== null) {
                return $byId;
            }
        }

        if ($revision->costCenterCode !== null && $revision->costCenterCode !== '') {
            return $this->costCenterRepository->findByCode($revision->costCenterCode);
        }

        return null;
    }
}