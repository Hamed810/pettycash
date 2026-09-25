<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use Exception;
use OCA\PettyCash\Application\Workflow\ManagementReviewService;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;

class ReturnsController extends OCSController
{
    public function __construct(
        \OCP\IRequest $request,
        private ManagementReviewService $managementReviewService,
        private CurrentUser $currentUser
    ) {
        parent::__construct('pettycash', $request);
    }

    /**
     * @param int $page
     * @param int $pageSize
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function index(int $page = 1, int $pageSize = 25): DataResponse
    {
        try {
            $result = $this->managementReviewService->getReturnsQueue($page, $pageSize);
            return new DataResponse(['data' => $result]);
        } catch (Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], 500);
        }
    }
}