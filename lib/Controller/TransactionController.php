<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use Exception;
use InvalidArgumentException;
use LogicException;
use OCA\PettyCash\Application\Expense\TransactionService;
use OCA\PettyCash\Application\Workflow\ManagementReviewService;
use OCA\PettyCash\Application\Expense\CreateCorrectionDraft;
use OCA\PettyCash\Application\Workflow\ResubmitCorrection;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository;
use OCA\PettyCash\Domain\Audit\Repository\AuditEventRepository;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Infrastructure\Http\OcsErrorMapper;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Audit\AuditEvent;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;

class TransactionController extends OCSController
{
    /** @var array<string, int> Currency code to minor unit mapping */
    private const CURRENCY_MINOR_UNITS = [
        'IRR' => 0,
        'USD' => 2,
        'EUR' => 2,
        'GBP' => 2,
    ];

    public function __construct(
        \OCP\IRequest $request,
        private TransactionService $transactionService,
        private ManagementReviewService $managementReviewService,
        private CreateCorrectionDraft $createCorrectionDraft,
        private ResubmitCorrection $resubmitCorrection,
        private TransactionRevisionRepository $revisionRepository,
        private AuditEventRepository $auditRepository,
        private CurrentUser $currentUser,
        private OcsErrorMapper $ocsErrorMapper
    ) {
        parent::__construct('pettycash', $request);
    }

    /**
     * @param string $sheetId
     * @param string $expenseDate
     * @param int $amountMinor
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function create(string $sheetId, ?string $expenseDate = null, ?int $amountMinor = null): DataResponse
    {
        $commandId = $this->request->getHeader('X-PettyCash-Command-ID');
        $fieldErrors = [];
        if ($expenseDate === null || trim($expenseDate) === '') {
            $fieldErrors['expenseDate'] = ['expenseDate is required'];
        }
        if ($amountMinor === null) {
            $fieldErrors['amountMinor'] = ['amountMinor is required'];
        }
        if ($fieldErrors !== []) {
            return $this->ocsErrorMapper->validation('Required fields are missing', $commandId, $fieldErrors);
        }

        try {
            $transaction = $this->transactionService->createTransaction($sheetId, $expenseDate, $amountMinor);
            return new DataResponse($this->formatTransaction($transaction), 201);
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e, $commandId);
        }
    }

    /**
     * @param string $transactionId
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function show(string $transactionId): DataResponse
    {
        try {
            $transaction = $this->transactionService->getTransaction($transactionId);
            if ($transaction === null) {
                return $this->ocsErrorMapper->notFound('Transaction not found');
            }
            return new DataResponse($this->formatTransaction($transaction));
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e);
        }
    }

    /**
     * @param string $transactionId
     * @param string $expenseDate
     * @param int $amountMinor
     * @param string|null $description
     * @param string|null $costCenterId
     * @param string|null $expenseTypeId
     * @param int $expectedVersion
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function update(
        string $transactionId,
        ?string $expenseDate = null,
        ?int $amountMinor = null,
        ?string $description = null,
        ?string $costCenterId = null,
        ?string $expenseTypeId = null,
        ?int $expectedVersion = null
    ): DataResponse {
        $commandId = $this->request->getHeader('X-PettyCash-Command-ID');
        $fieldErrors = [];
        if ($expenseDate === null || trim($expenseDate) === '') {
            $fieldErrors['expenseDate'] = ['expenseDate is required'];
        }
        if ($amountMinor === null) {
            $fieldErrors['amountMinor'] = ['amountMinor is required'];
        }
        if ($expectedVersion === null) {
            $fieldErrors['expectedVersion'] = ['expectedVersion is required'];
        }
        if ($fieldErrors !== []) {
            return $this->ocsErrorMapper->validation('Required fields are missing', $commandId, $fieldErrors);
        }

        try {
            $transaction = $this->transactionService->updateTransaction(
                $transactionId,
                $expenseDate,
                $amountMinor,
                $description,
                $costCenterId,
                $expenseTypeId,
                $expectedVersion
            );
            return new DataResponse($this->formatTransaction($transaction));
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e, $commandId);
        }
    }

    #[NoAdminRequired]
    public function destroy(string $transactionId, ?int $expectedVersion = null): DataResponse
    {
        $commandId = $this->request->getHeader('X-PettyCash-Command-ID');
        if ($expectedVersion === null) {
            return $this->ocsErrorMapper->validation(
                'Required fields are missing',
                $commandId,
                ['expectedVersion' => ['expectedVersion is required']]
            );
        }

        try {
            $this->transactionService->deleteTransaction($transactionId, $expectedVersion);
            return new DataResponse(null, 204);
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e, $commandId);
        }
    }

    /**
     * @param string $transactionId
     * @param string $decision
     * @param string $explanation
     * @param int $expectedVersion
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function decide(
        string $transactionId,
        ?string $decision = null,
        ?string $explanation = null,
        ?int $expectedVersion = null
    ): DataResponse {
        $fieldErrors = [];
        if ($decision === null || trim($decision) === '') {
            $fieldErrors['decision'] = ['decision is required'];
        }
        if ($expectedVersion === null) {
            $fieldErrors['expectedVersion'] = ['expectedVersion is required'];
        }
        if ($fieldErrors !== []) {
            return $this->ocsErrorMapper->validation(
                'Required fields are missing',
                $this->request->getHeader('X-PettyCash-Command-ID'),
                $fieldErrors
            );
        }

        try {
            $workflowDecision = Decision::from($decision);
            $commandId = $this->request->getHeader('X-PettyCash-Command-ID') ?? (string)Uuid::random();
            
            // Determine the current stage of the transaction
            $transaction = $this->managementReviewService->getTransactionForDecision($transactionId);
            if ($transaction === null) {
                return $this->ocsErrorMapper->notFound('Transaction not found', $commandId);
            }
            
            $stage = $transaction->stage;
            
            $updatedTransaction = $this->managementReviewService->recordDecision(
                $transactionId,
                $stage,
                $workflowDecision,
                $explanation,
                $commandId,
                $expectedVersion
            );
            
            return new DataResponse($this->formatTransaction($updatedTransaction), 201);
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e, $commandId);
        }
    }

    /**
     * @param string $transactionId
     * @param int $expectedVersion
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function correction(string $transactionId, ?int $expectedVersion = null): DataResponse
    {
        $commandId = $this->request->getHeader('X-PettyCash-Command-ID') ?? (string)Uuid::random();
        if ($expectedVersion === null) {
            return $this->ocsErrorMapper->validation(
                'Required fields are missing',
                $commandId,
                ['expectedVersion' => ['expectedVersion is required']]
            );
        }

        try {
            $revision = $this->createCorrectionDraft->execute($transactionId, $commandId, $expectedVersion);
            return new DataResponse($this->formatRevision($revision), 201);
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e, $commandId);
        }
    }

    /**
     * @param string $transactionId
     * @param int $expectedVersion
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function resubmit(string $transactionId, ?int $expectedVersion = null): DataResponse
    {
        $commandId = $this->request->getHeader('X-PettyCash-Command-ID') ?? (string)Uuid::random();
        if ($expectedVersion === null) {
            return $this->ocsErrorMapper->validation(
                'Required fields are missing',
                $commandId,
                ['expectedVersion' => ['expectedVersion is required']]
            );
        }

        try {
            $this->resubmitCorrection->execute($transactionId, $commandId, $expectedVersion);
            
            $transaction = $this->transactionService->getTransaction($transactionId);
            return new DataResponse($this->formatTransaction($transaction), 200);
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e, $commandId);
        }
    }

    /**
     * @param string $transactionId
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function revisions(string $transactionId): DataResponse
    {
        try {
            $transaction = $this->transactionService->getTransaction($transactionId);
            if ($transaction === null) {
                return $this->ocsErrorMapper->notFound('Transaction not found');
            }

            $revisions = $this->revisionRepository->findByTransactionId($transaction->dbId ?? 0);
            $formatted = array_map([$this, 'formatRevision'], $revisions);
            return new DataResponse($formatted);
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e);
        }
    }

    /**
     * @param string $transactionId
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function audit(string $transactionId): DataResponse
    {
        try {
            $transaction = $this->transactionService->getTransaction($transactionId);
            if ($transaction === null) {
                return $this->ocsErrorMapper->notFound('Transaction not found');
            }

            $auditEvents = $this->auditRepository->findByTransactionId($transaction->dbId ?? 0);
            $formatted = array_map([$this, 'formatAuditEvent'], $auditEvents);
            return new DataResponse($formatted);
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e);
        }
    }

    private function formatTransaction(object $transaction): array
    {
        $rev = $transaction->revision();
        return [
            'id' => $transaction->publicId ?? $transaction->id,
            'ownerId' => $transaction->ownerUid,
            'sheetId' => $transaction->sheetId,
            'stage' => $transaction->stage->value,
            'status' => $transaction->status->value,
            'finalOutcome' => $transaction->finalOutcome,
            'revision' => $this->formatRevision($rev),
            'version' => $transaction->lockVersion,
        ];
    }

    private function formatRevision(TransactionRevision $rev): array
    {
        $currencyCode = (string)$rev->amount()->currency();
        return [
            'id' => $rev->id(),
            'number' => $rev->number(),
            'expenseDate' => $rev->expenseDate()->format('Y-m-d'),
            'amount' => [
                'amountMinor' => $rev->amount()->minorUnits(),
                'currency' => $currencyCode,
                'minorUnit' => self::CURRENCY_MINOR_UNITS[$currencyCode] ?? 2,
            ],
            'description' => $rev->description(),
            'costCenter' => [
                'id' => $rev->costCenterId(),
                'code' => $rev->costCenterCode ?? '',
                'name' => $rev->costCenterName ?? '',
            ],
            'expenseType' => [
                'id' => $rev->expenseTypeTitle(),
                'name' => $rev->expenseTypeTitle(),
            ],
            'createdBy' => $rev->createdBy ?? '',
            'createdAt' => $rev->createdAt?->format(\DateTimeInterface::ATOM),
            'active' => $rev->isActive(),
        ];
    }

    private function formatAuditEvent(AuditEvent $event): array
    {
        return [
            'id' => $event->id,
            'eventType' => $event->eventType,
            'stage' => $event->stage,
            'decision' => $event->decisionId ? 'DECISION' : null,
            'explanation' => $event->explanation,
            'actor' => [
                'userId' => $event->actorUid,
                'displayName' => $event->actorName,
                'role' => $event->actorRole,
            ],
            'before' => json_decode($event->beforeJson, true),
            'after' => json_decode($event->afterJson, true),
            'occurredAt' => $event->occurredAt->format(\DateTimeInterface::ATOM),
        ];
    }
}