<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use OCA\PettyCash\Application\Expense\SheetService;
use OCA\PettyCash\Application\Expense\TransactionService;
use OCA\PettyCash\Application\Evidence\EvidenceService;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Workflow\SubmitSheet;
use OCA\PettyCash\Infrastructure\Http\OcsErrorMapper;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;

class SheetController extends OCSController
{
    public function __construct(
        \OCP\IRequest $request,
        private SheetService $sheetService,
        private TransactionService $transactionService,
        private SubmitSheet $submitSheet,
        private EvidenceService $evidenceService,
        private CurrentUser $currentUser,
        private OcsErrorMapper $ocsErrorMapper
    ) {
        parent::__construct('pettycash', $request);
    }

    #[NoAdminRequired]
    public function create(?string $title = null, ?string $accountId = null): DataResponse
    {
        $fieldErrors = [];
        if ($title === null || trim($title) === '') {
            $fieldErrors['title'] = ['title is required'];
        }
        if ($accountId === null || trim($accountId) === '') {
            $fieldErrors['accountId'] = ['accountId is required'];
        }
        if ($fieldErrors !== []) {
            return $this->ocsErrorMapper->validation(
                'Required fields are missing',
                $this->request->getHeader('X-PettyCash-Command-ID'),
                $fieldErrors
            );
        }

        try {
            $sheet = $this->sheetService->createSheet($title, $accountId);
            return new DataResponse($this->formatSheet($sheet), 201);
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e, $this->request->getHeader('X-PettyCash-Command-ID'));
        }
    }

    #[NoAdminRequired]
    public function index(int $page = 1, int $pageSize = 25): DataResponse
    {
        try {
            $result = $this->sheetService->listSheets($page, $pageSize);
            return new DataResponse([
                'items' => array_map([$this, 'formatSheet'], $result['items']),
                'total' => $result['total'],
                'page' => $page,
                'pageSize' => $pageSize,
            ]);
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e);
        }
    }

    #[NoAdminRequired]
    public function show(string $sheetId): DataResponse
    {
        try {
            $sheet = $this->sheetService->getSheet($sheetId);
            if ($sheet === null) {
                return $this->ocsErrorMapper->notFound('Sheet not found');
            }
            return new DataResponse($this->formatSheet($sheet));
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e);
        }
    }

    #[NoAdminRequired]
    public function update(
        string $sheetId,
        ?string $title = null,
        ?string $accountId = null,
        ?int $expectedVersion = null
    ): DataResponse {
        $commandId = $this->request->getHeader('X-PettyCash-Command-ID');

        $fieldErrors = [];
        if ($title === null || trim($title) === '') {
            $fieldErrors['title'] = ['title is required'];
        }
        if ($accountId === null || trim($accountId) === '') {
            $fieldErrors['accountId'] = ['accountId is required'];
        }
        if ($expectedVersion === null) {
            $fieldErrors['expectedVersion'] = ['expectedVersion is required'];
        }
        if ($fieldErrors !== []) {
            return $this->ocsErrorMapper->validation('Required fields are missing', $commandId, $fieldErrors);
        }

        try {
            $sheet = $this->sheetService->updateSheet($sheetId, $title, $accountId, $expectedVersion);
            return new DataResponse($this->formatSheet($sheet));
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e, $commandId);
        }
    }

    #[NoAdminRequired]
    public function submit(string $sheetId, ?string $commandId = null, ?int $expectedVersion = null): DataResponse
    {
        $commandId = $commandId ?? $this->request->getHeader('X-PettyCash-Command-ID');

        $fieldErrors = [];
        if ($commandId === null || trim($commandId) === '') {
            $fieldErrors['commandId'] = ['commandId is required'];
        }
        if ($expectedVersion === null) {
            $fieldErrors['expectedVersion'] = ['expectedVersion is required'];
        }
        if ($fieldErrors !== []) {
            return $this->ocsErrorMapper->validation('Required fields are missing', $commandId, $fieldErrors);
        }

        try {
            $sheet = $this->submitSheet->execute($sheetId, $commandId, $expectedVersion);
            return new DataResponse($this->formatSheet($sheet), 200);
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e, $commandId);
        }
    }

    private function formatSheet(object $sheet): array
    {
        return [
            'id' => $sheet->publicId ?? $sheet->id,
            'title' => $sheet->title,
            'ownerId' => $sheet->ownerUid,
            'accountId' => $sheet->accountId,
            'currency' => (string)$sheet->currency,
            'status' => $sheet->status->value,
            'transactionCount' => count($sheet->transactions()),
            'createdAt' => $sheet->createdAt?->format(\DateTimeInterface::ATOM),
            'submittedAt' => $sheet->firstSubmittedAt?->format(\DateTimeInterface::ATOM),
            'version' => $sheet->lockVersion,
        ];
    }
}
