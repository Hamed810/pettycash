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
use OCA\PettyCash\Domain\Workflow\WorkflowStage;

final readonly class SubmitSheet
{
    public function __construct(
        private TransactionManager $transactionManager,
        private ExpenseSheetRepository $sheetRepository,
        private AuditWriter $auditWriter,
        private IdempotencyService $idempotencyService,
        private CurrentUser $currentUser,
        private CostCenterRepository $costCenterRepository
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

                // Validate all transactions are complete
                if ($sheet->transactions() === []) {
                    throw new InvalidArgumentException('At least one transaction is required');
                }

                // Check each transaction's cost center
                foreach ($sheet->transactions() as $tx) {
                    $costCenterCode = $tx->revision()->costCenterId();
                    if ($costCenterCode === null) {
                        throw new InvalidArgumentException('Transaction missing cost center');
                    }

                    $costCenter = $this->costCenterRepository->findByCode($costCenterCode);
                    if ($costCenter === null) {
                        throw new InvalidArgumentException("Cost center not found: {$costCenterCode}");
                    }
                }

                // Submit the sheet (domain logic)
                $submittedTotal = $sheet->submit();

                // Create audit event for submission
                $auditEvent = new AuditEvent(
                    (string)\OCA\PettyCash\Domain\Shared\Uuid::random(),
                    $sheet->dbId,
                    null,
                    null,
                    WorkflowStage::CostCenter->value,
                    null,
                    'sheet',
                    $sheetId,
                    'SUBMISSION',
                    $ownerUid,
                    $this->currentUserDisplayName(),
                    'PURCHASER',
                    null,
                    '{}',
                    json_encode([
                        'sheet_id' => $sheetId,
                        'transaction_count' => count($sheet->transactions()),
                        'submitted_total_minor' => $submittedTotal,
                    ], JSON_THROW_ON_ERROR),
                    $commandId,
                    new DateTimeImmutable('now', new DateTimeZone('UTC'))
                );
                $this->auditWriter->append((array)$auditEvent);

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
}