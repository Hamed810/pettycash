<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use OCA\PettyCash\Application\Workflow\ManagementReviewService;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;

class QueueController extends OCSController
{
    public function __construct(
        \OCP\IRequest $request,
        private ManagementReviewService $managementReviewService,
        private CurrentUser $currentUser
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
        try {
            $workflowStage = \OCA\PettyCash\Domain\Workflow\WorkflowStage::from($stage);
        } catch (\ValueError|\TypeError) {
            // `WorkflowStage::from()` is a backed-enum lookup, so an unknown stage
            // raises \ValueError, which extends \Error rather than \Exception. A
            // handler for `Exception` never sees it and the caller gets a 500.
            return new DataResponse(['error' => 'Invalid stage'], 400);
        }

        try {
            $result = match ($workflowStage) {
                \OCA\PettyCash\Domain\Workflow\WorkflowStage::CostCenter => 
                    $this->managementReviewService->getCostCenterQueue($page, $pageSize),
                \OCA\PettyCash\Domain\Workflow\WorkflowStage::Senior => 
                    $this->managementReviewService->getSeniorQueue($page, $pageSize),
                \OCA\PettyCash\Domain\Workflow\WorkflowStage::Finance => 
                    $this->managementReviewService->getFinanceQueue($page, $pageSize),
                default => ['items' => [], 'total' => 0],
            };

            return new DataResponse(['data' => $result]);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => $e->getMessage()], 500);
        }
    }
}