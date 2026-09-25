<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use Exception;
use InvalidArgumentException;
use LogicException;
use OCA\PettyCash\Application\Workflow\FinanceQueue;
use OCA\PettyCash\Application\Workflow\FinanceSettlementService;
use OCA\PettyCash\Application\Workflow\ManagementReviewService;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCA\PettyCash\Infrastructure\Http\OcsErrorMapper;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;

class ReviewController extends OCSController
{
    public function __construct(
        \OCP\IRequest $request,
        private ManagementReviewService $managementReviewService,
        private FinanceQueue $financeQueue,
        private FinanceSettlementService $financeSettlementService,
        private CurrentUser $currentUser,
        private OcsErrorMapper $ocsErrorMapper
    ) {
        parent::__construct('pettycash', $request);
    }

    /**
     * @param string $stage
     * @param int $page
     * @param int $pageSize
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function index(string $stage, int $page = 1, int $pageSize = 25): DataResponse
    {
        // `contracts/ocs-api.yaml` defines the path parameter as the kebab-case
        // slug `[cost-center, senior, finance]`, while the domain enum is backed
        // by SCREAMING_SNAKE_CASE, so the slug has to be translated rather than
        // passed through — otherwise every queue request is a 500.
        $workflowStage = match (strtolower(trim($stage))) {
            'cost-center' => WorkflowStage::CostCenter,
            'senior' => WorkflowStage::Senior,
            'finance' => WorkflowStage::Finance,
            default => null,
        };

        if ($workflowStage === null) {
            return $this->ocsErrorMapper->notFound('No such queue stage');
        }

        try {
            $result = match ($workflowStage) {
                WorkflowStage::CostCenter => $this->managementReviewService->getCostCenterQueue($page, $pageSize),
                WorkflowStage::Senior => $this->managementReviewService->getSeniorQueue($page, $pageSize),
                WorkflowStage::Finance => $this->financeQueue->getQueue($page, $pageSize),
                default => ['items' => [], 'total' => 0],
            };

            return new DataResponse([
                'items' => array_map([$this, 'formatTransaction'], $result['items']),
                'total' => $result['total'],
                'page' => $page,
                'pageSize' => $pageSize,
            ]);
        } catch (Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * @param int $page
     * @param int $pageSize
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function returns(int $page = 1, int $pageSize = 25): DataResponse
    {
        try {
            $result = $this->managementReviewService->getReturnsQueue($page, $pageSize);

            return new DataResponse([
                'items' => array_map([$this, 'formatTransaction'], $result['items']),
                'total' => $result['total'],
                'page' => $page,
                'pageSize' => $pageSize,
            ]);
        } catch (Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * @param string $transactionId
     * @param string $decision
     * @param string|null $explanation
     * @param int $expectedVersion
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function decide(
        string $transactionId,
        string $decision,
        ?string $explanation,
        int $expectedVersion
    ): DataResponse {
        try {
            $workflowDecision = Decision::from($decision);
            $commandId = (string)Uuid::random();

            // Determine which service to use based on transaction stage
            $transaction = $this->managementReviewService->getTransactionForDecision($transactionId);
            if ($transaction === null) {
                return new DataResponse(['error' => 'Transaction not found'], 404);
            }

            $stage = $transaction->stage;

            if ($stage === WorkflowStage::Finance) {
                $updatedTransaction = $this->financeSettlementService->recordDecision(
                    $transactionId,
                    $workflowDecision,
                    $explanation ?? '',
                    $commandId,
                    $expectedVersion
                );
            } else {
                $updatedTransaction = $this->managementReviewService->recordDecision(
                    $transactionId,
                    $stage,
                    $workflowDecision,
                    $explanation ?? '',
                    $commandId,
                    $expectedVersion
                );
            }

            return new DataResponse($this->formatTransaction($updatedTransaction), 201);
        } catch (InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], 400);
        } catch (LogicException $e) {
            return new DataResponse(['error' => $e->getMessage()], 403);
        } catch (Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], 500);
        }
    }

    private static function getMinorUnit(\OCA\PettyCash\Domain\Shared\CurrencyCode $currency): int
    {
        return match ((string)$currency) {
            'IRR' => 0,
            'USD', 'EUR', 'GBP' => 2,
            default => 0,
        };
    }

    private function formatTransaction(\OCA\PettyCash\Domain\Expense\ExpenseTransaction $transaction): array
    {
        return [
            'id' => $transaction->publicId ?? $transaction->id,
            'ownerId' => $transaction->ownerUid,
            'sheetId' => $transaction->sheetId,
            'stage' => $transaction->stage->value,
            'status' => $transaction->status->value,
            'finalOutcome' => $transaction->finalOutcome,
            'revision' => [
                'id' => $transaction->revision()->id(),
                'number' => $transaction->revision()->number(),
                'expenseDate' => $transaction->revision()->expenseDate()->format('Y-m-d'),
                'amount' => [
                    'amountMinor' => $transaction->revision()->amount()->minorUnits(),
                    'currency' => (string)$transaction->revision()->amount()->currency(),
                    'minorUnit' => self::getMinorUnit($transaction->revision()->amount()->currency()),
                ],
                'description' => $transaction->revision()->description(),
                'costCenterId' => $transaction->revision()->costCenterId(),
                'expenseTypeTitle' => $transaction->revision()->expenseTypeTitle(),
                'hasEvidence' => $transaction->revision()->hasEvidence(),
                'createdAt' => $transaction->revision()->createdAt?->format(\DateTimeInterface::ATOM),
                'active' => $transaction->revision()->isActive(),
            ],
            'version' => $transaction->lockVersion,
        ];
    }
}