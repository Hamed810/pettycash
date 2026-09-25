<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Workflow;

use Exception;
use InvalidArgumentException;
use LogicException;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Application\Port\TransactionManager;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Security\AuthorizationService;
use OCA\PettyCash\Application\Security\AuthorizationContext;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCA\PettyCash\Domain\Audit\AuditEvent;

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
        private AuthorizationService $authorizationService
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

                if ($transaction->lockVersion !== $expectedVersion) {
                    throw new InvalidArgumentException('Stale version');
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

                // Verify cost center exists
                $costCenterCode = $activeRevision->costCenterId();
                $costCenter = $this->costCenterRepository->findByCode($costCenterCode);
                if ($costCenter === null) {
                    throw new InvalidArgumentException("Cost center not found: {$costCenterCode}");
                }

                // Submit the correction (activate revision and route to cost-center review)
                $submittedTotal = $transaction->resubmitCorrection();

                // Create audit event for correction resubmission
                $auditEvent = new AuditEvent(
                    (string)Uuid::random(),
                    $transaction->dbId,
                    null,
                    $activeRevision->dbId,
                    WorkflowStage::CostCenter->value,
                    null,
                    'transaction',
                    $transactionPublicId,
                    'CORRECTION_RESUBMITTED',
                    $actorUid,
                    $this->userDirectory->displayName($actorUid),
                    'PURCHASER',
                    null,
                    '{}',
                    json_encode([
                        'transaction_id' => $transactionPublicId,
                        'revision_number' => $activeRevision->number(),
                        'resubmitted_total_minor' => $submittedTotal,
                    ], JSON_THROW_ON_ERROR),
                    $commandId,
                    new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
                );
                $this->auditWriter->append((array)$auditEvent);

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
}