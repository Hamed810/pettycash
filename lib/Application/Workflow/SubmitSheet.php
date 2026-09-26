<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Workflow;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\TransactionManager;
use OCA\PettyCash\Application\Security\AuthorizationService;
use OCA\PettyCash\Domain\Audit\AuditEvent;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;

final readonly class SubmitSheet
{
    public function __construct(
        private TransactionManager $transactionManager,
        private ExpenseSheetRepository $sheetRepository,
        private AuditWriter $auditWriter,
        private IdempotencyService $idempotencyService,
        private CurrentUser $currentUser,
        private CostCenterRepository $costCenterRepository,
        private ExpenseTransactionRepository $transactionRepository
    ) {}

    /**
     * Submit an open sheet for review
     *
     * @param string $sheetId
     * @param string $commandId
     * @param int $expectedVersion
     * @return ExpenseSheet
     */
    public function execute(string $sheetId, string $commandId, int $expectedVersion): ExpenseSheet
    {
        $ownerUid = $this->currentUser->uid();

        return $this->transactionManager->transactional(function () use ($sheetId, $ownerUid, $commandId, $expectedVersion): ExpenseSheet {
            // Check idempotency
            $payload = ['sheet_id' => $sheetId, 'command_id' => $commandId];
            $this->idempotencyService->execute($commandId, $payload, function () use ($sheetId, $ownerUid, $expectedVersion, $commandId): array {
                $sheet = $this->sheetRepository->findByPublicId($sheetId);

                if ($sheet === null || $sheet->ownerUid !== $ownerUid) {
                    throw new InvalidArgumentException('Sheet not found');
                }

                if ($sheet->lockVersion !== $expectedVersion) {
                    throw new InvalidArgumentException('Stale version');
                }

                if ($sheet->status !== \OCA\PettyCash\Domain\Workflow\SheetStatus::Open) {
                    throw new InvalidArgumentException('Only open sheets can be submitted');
                }

                // Attach the sheet's transactions before validating it.
                //
                // `ExpenseSheet` is a domain aggregate that owns its transactions, but
                // `DbExpenseSheetRepository` deliberately does not compose them, so a
                // sheet loaded from the database always carried an empty collection.
                // Submission then failed with "At least one transaction is required"
                // for every sheet that genuinely had one.
                if ($sheet->dbId !== null) {
                    foreach ($this->transactionRepository->findBySheetId($sheet->dbId) as $transaction) {
                        $sheet->addTransaction($transaction);
                    }
                }

                // Validate all transactions are complete
                if ($sheet->transactions() === []) {
                    throw new InvalidArgumentException('At least one transaction is required');
                }

                // Check each transaction's cost center
                foreach ($sheet->transactions() as $tx) {
                    $costCenter = $this->resolveCostCenter($tx->revision());
                    if ($costCenter === null) {
                        throw new InvalidArgumentException('Transaction missing cost center');
                    }
                }

                // Submit the sheet (domain logic)
                $submittedTotal = $sheet->submit();

                // Create audit event for submission.
                //
                // `AuditWriter::append()` reads snake_case keys; casting the AuditEvent
                // object yields camelCase properties, so `command_id` is never found
                // and the insert fails on the NOT NULL column.
                $this->auditWriter->append([
                    'id' => (string)\OCA\PettyCash\Domain\Shared\Uuid::random(),
                    'sheet_id' => $sheet->dbId,
                    'transaction_id' => null,
                    'revision_id' => null,
                    'stage' => WorkflowStage::CostCenter->value,
                    'decision_id' => null,
                    'entity_type' => 'sheet',
                    'entity_id' => $sheetId,
                    'event_type' => 'SUBMISSION',
                    'actor_uid' => $ownerUid,
                    'actor_role' => 'PURCHASER',
                    'explanation' => '',
                    'before' => [],
                    'after' => [
                        'sheet_id' => $sheetId,
                        'transaction_count' => count($sheet->transactions()),
                        'submitted_total_minor' => $submittedTotal,
                    ],
                    'command_id' => $commandId,
                ]);

                // Persist the sheet
                $this->sheetRepository->save($sheet);

                return ['sheet_id' => $sheetId, 'submitted_total' => $submittedTotal];
            });

            // Reload and return the sheet
            $sheet = $this->sheetRepository->findByPublicId($sheetId);
            if ($sheet === null) {
                throw new LogicException('Sheet not found after submission');
            }

            return $sheet;
        });
    }

    private function currentUserDisplayName(): string
    {
        return $this->currentUser->uid();
    }

    /**
     * Resolve the cost center a revision points at.
     *
     * `TransactionRevision::costCenterId()` is the cost center's public UUID, which
     * `findByCode()` can never match, so the numeric join key is tried first and the
     * submission-time code snapshot second.
     */
    private function resolveCostCenter(TransactionRevision $revision): ?\OCA\PettyCash\Domain\Configuration\CostCenter
    {
        if ($revision->costCenterDbId !== null) {
            $byId = $this->costCenterRepository->findById($revision->costCenterDbId);
            if ($byId !== null) {
                return $byId;
            }
        }

        if ($revision->costCenterId() !== null) {
            $byPublicId = $this->costCenterRepository->findByPublicId($revision->costCenterId());
            if ($byPublicId !== null) {
                return $byPublicId;
            }
        }

        if ($revision->costCenterCode !== null && $revision->costCenterCode !== '') {
            return $this->costCenterRepository->findByCode($revision->costCenterCode);
        }

        return null;
    }
}