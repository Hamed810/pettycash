<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use InvalidArgumentException;
use LogicException;
use OCA\PettyCash\Application\Workflow\FinanceQueue;
use OCA\PettyCash\Application\Workflow\FinanceSettlementService;
use OCA\PettyCash\Application\Workflow\ManagementReviewService;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Infrastructure\Http\OcsErrorMapper;
use OCA\PettyCash\Infrastructure\Http\TransactionPayloadFormatter;
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
        private OcsErrorMapper $ocsErrorMapper,
        private TransactionPayloadFormatter $payloadFormatter
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
                'items' => array_map([$this, 'formatSummary'], $result['items']),
                'total' => $result['total'],
                'page' => $page,
                'pageSize' => $pageSize,
            ]);
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e);
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
                'items' => array_map([$this, 'formatSummary'], $result['items']),
                'total' => $result['total'],
                'page' => $page,
                'pageSize' => $pageSize,
            ]);
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e);
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
        // The caller's command id is the idempotency key. Minting a fresh UUID here
        // would make every retry look like a new command, so a replayed decision
        // would be recorded a second time with a second immutable WorkflowDecision and
        // a second business audit event — and on the finance path this endpoint is the
        // entry point to settlement.
        $commandId = $this->request->getHeader('X-PettyCash-Command-ID');
        if ($commandId === null || trim($commandId) === '') {
            return $this->ocsErrorMapper->badRequest(
                'Required fields are missing',
                null,
                ['commandId' => ['commandId is required']]
            );
        }

        $fieldErrors = [];
        if ($decision === null || trim($decision) === '') {
            $fieldErrors['decision'] = ['decision is required'];
        }
        if ($fieldErrors !== []) {
            return $this->ocsErrorMapper->validation(
                'Required fields are missing',
                $commandId,
                $fieldErrors
            );
        }

        try {
            // `Decision::from()` is a backed-enum lookup that raises \ValueError for an
            // unknown value, and \ValueError extends \Error rather than \Exception, so
            // it has to be turned into a validation failure explicitly.
            $workflowDecision = Decision::from($decision);
        } catch (\ValueError) {
            return $this->ocsErrorMapper->validation(
                'The decision value is not valid',
                $commandId,
                ['decision' => ['decision must be APPROVE, REJECT, or RETURN']]
            );
        }

        try {
            // Determine which service to use based on transaction stage
            $transaction = $this->managementReviewService->getTransactionForDecision($transactionId);
            if ($transaction === null) {
                return $this->ocsErrorMapper->notFound('Transaction not found', $commandId);
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
            // A missing explanation on REJECT/RETURN, a stale version, and an
            // out-of-state decision are all caller-correctable validation failures,
            // so they answer 422 with a stable code and field errors rather than a
            // bare 400 body.
            return $this->ocsErrorMapper->validation($e->getMessage(), $commandId, $this->fieldErrorsFor($e));
        } catch (LogicException $e) {
            return $this->ocsErrorMapper->forbidden($e->getMessage(), $commandId);
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e, $commandId);
        }
    }

    /**
     * Attach the offending field where the message identifies one, so the caller can
     * highlight it instead of only reading a sentence.
     *
     * @return array<string, list<string>>
     */
    private function fieldErrorsFor(\Throwable $e): array
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'explanation') => ['explanation' => ['explanation is required for reject or return']],
            str_contains($message, 'version') => ['expectedVersion' => ['the transaction has changed, reload and retry']],
            str_contains($message, 'stage') => ['stage' => ['the transaction is not at this review stage']],
            default => [],
        };
    }

    /** The contract's `TransactionSummary`; the queues carry no decisions or evidence. */
    private function formatSummary(\OCA\PettyCash\Domain\Expense\ExpenseTransaction $transaction): array
    {
        return $this->payloadFormatter->summary($transaction);
    }

    /** The contract's `TransactionDetail`, which the review views and decision form consume. */
    private function formatTransaction(\OCA\PettyCash\Domain\Expense\ExpenseTransaction $transaction): array
    {
        return $this->payloadFormatter->detail($transaction);
    }

}