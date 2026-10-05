<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use OCA\PettyCash\Application\Expense\SheetService;
use OCA\PettyCash\Application\Expense\SheetListFilterFactory;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Application\Expense\TransactionService;
use OCA\PettyCash\Application\Evidence\EvidenceService;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Workflow\SubmitSheet;
use OCA\PettyCash\Infrastructure\Http\OcsErrorMapper;
use OCA\PettyCash\Infrastructure\Http\SheetPayloadFormatter;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;

class SheetController extends OCSController
{
    public function __construct(
        \OCP\IRequest $request,
        private SheetService $sheetService,
        private SheetListFilterFactory $filterFactory,
        private TransactionService $transactionService,
        private SubmitSheet $submitSheet,
        private EvidenceService $evidenceService,
        private CurrentUser $currentUser,
        private OcsErrorMapper $ocsErrorMapper,
        private ExpenseTransactionRepository $transactionRepository,
        private SheetPayloadFormatter $payloadFormatter
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
            return new DataResponse($this->formatSheetDetail($sheet), 201);
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e, $this->request->getHeader('X-PettyCash-Command-ID'));
        }
    }

    /**
     * The caller's own sheets, narrowed by the four facets `contracts/ocs-api.yaml` declares.
     *
     * `contracts/ocs-api.yaml` declares `status`, `ownerId`, `currency`, `from` and `to` on this action
     * and the signature bound only `page`/`pageSize`, so every filter the UI can produce was discarded
     * before any rule ran: FR-064's status, date, currency and creator filtering had no implementation
     * at all (T333). The coercion lives in `SheetListFilterFactory` rather than here, for the same reason
     * `ReportFilterFactory` does: an unrecognised value is refused with a 422 naming the accepted set
     * (FR-064's "MUST NOT be ignored"), and one vocabulary has one home.
     *
     * ## Why `ownerId` is refused rather than honoured
     *
     * FR-064 resolves "creator" to the sheet's owner, which makes it the *scope* of this list rather than
     * one of its predicates: a purchaser sees their own sheets and nothing else. So `ownerId` is accepted
     * only when it names the caller, and naming anybody else is a 403 — never a silent widening of the
     * scope. `AccountController::index` makes the same call for `GET /accounts`' `userId`, and it is the
     * reason the two endpoints cannot be used to discover another user's records: a refused answer and an
     * empty page are distinguishable, an ignored filter is not.
     */
    #[NoAdminRequired]
    public function index(
        int $page = 1,
        int $pageSize = 25,
        ?string $status = null,
        ?string $ownerId = null,
        ?string $currency = null,
        ?string $from = null,
        ?string $to = null
    ): DataResponse {
        try {
            $uid = $this->currentUser->uid();
            if ($ownerId !== null && trim($ownerId) !== '' && trim($ownerId) !== $uid) {
                return $this->ocsErrorMapper->forbidden(
                    'Sheets may only be listed for the authenticated user'
                );
            }

            $filter = $this->filterFactory->fromQuery($status, $currency, $from, $to);
            $result = $this->sheetService->listSheets($page, $pageSize, $filter);
            return new DataResponse([
                'items' => $this->payloadFormatter->summaries($result['items']),
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
            return new DataResponse($this->formatSheetDetail($sheet));
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
            return new DataResponse($this->formatSheetDetail($sheet));
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
            return new DataResponse($this->formatSheetDetail($sheet), 200);
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e, $commandId);
        }
    }

    /**
     * The contract's `SheetDetail` shape, for every single-sheet response.
     *
     * `contracts/ocs-api.yaml` declares `SheetDetail` — not `SheetSummary` — for all four of them:
     * `POST /sheets` (201, `:368`), `GET /sheets/{sheetId}` (200, `:381`), `PATCH /sheets/{sheetId}`
     * (200, `:405`) and `POST /sheets/{sheetId}/submit` (200, `:424`). `SheetSummary` appears only
     * inside `SheetPage` (`:902`). Returning the summary from any of the four is therefore a contract
     * violation, not a simplification, and it is the same violation in all four: `transactions` and
     * `allowedActions` missing, which left `canSubmit` permanently false in the store.
     *
     * The actor's uid is passed rather than read from a session inside the formatter, so what the
     * capability set is derived from is visible at the call site.
     */
    private function formatSheetDetail(ExpenseSheet $sheet): array
    {
        return $this->payloadFormatter->detail($sheet, $this->currentUser->uid());
    }
}
