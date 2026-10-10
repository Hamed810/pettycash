<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use OCA\PettyCash\Infrastructure\Http\CommandId;
use Exception;
use InvalidArgumentException;
use LogicException;
use OCA\PettyCash\Application\Expense\TransactionService;
use OCA\PettyCash\Application\Expense\CreateCorrectionDraft;
use OCA\PettyCash\Application\Workflow\ResubmitCorrection;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository;
use OCA\PettyCash\Domain\Audit\Repository\AuditEventRepository;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\PageBounds;
use OCA\PettyCash\Infrastructure\Http\OcsErrorMapper;
use OCA\PettyCash\Infrastructure\Http\TransactionPayloadFormatter;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Audit\AuditEvent;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;

class TransactionController extends OCSController
{
    /** @var array<string, int> Currency code to minor unit mapping */
    public function __construct(
        \OCP\IRequest $request,
        private TransactionService $transactionService,
        private CreateCorrectionDraft $createCorrectionDraft,
        private ResubmitCorrection $resubmitCorrection,
        private TransactionRevisionRepository $revisionRepository,
        private AuditEventRepository $auditRepository,
        private CurrentUser $currentUser,
        private OcsErrorMapper $ocsErrorMapper,
        private TransactionPayloadFormatter $payloadFormatter
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
        // `contracts/ocs-api.yaml:436` declares this header `required: true`. It is refused with 400, the
        // status `ReviewController` already returns for the identical condition, because a missing
        // required *parameter* is a malformed request rather than an invalid body field -- the 422 that
        // `validation()` emits stays with `expenseDate` and `amountMinor` below.
        if ($commandId === null || trim($commandId) === '') {
            return $this->ocsErrorMapper->badRequest(
                'Required fields are missing',
                null,
                ['commandId' => ['commandId is required']]
            );
        }

        // T415: present but unstoreable. `pettycash_command_results.command_id` is
        // `string(36)` and the contract declares `format: uuid`, so an over-long
        // value reaches MySQL and answers 500. A malformed request is not a server
        // fault. **400, matching this method's own absent case** rather than 422:
        // both are the same class of fault, a request the caller must change.
        try {
            $commandId = CommandId::fromString((string) $commandId)->__toString();
        } catch (\InvalidArgumentException $malformed) {
            return $this->ocsErrorMapper->badRequest(
                'Malformed command ID',
                null,
                ['commandId' => [$malformed->getMessage()]]
            );
        }

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
            $transaction = $this->transactionService->createTransaction($sheetId, $expenseDate, $amountMinor, $commandId);
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
            return $this->ocsErrorMapper->map($e, null, ['publicId' => $transactionId]);
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
        // As in `create()`: the contract declares this header required on PATCH too
        // (`contracts/ocs-api.yaml:466`), and a missing required parameter is a 400 rather than a 422.
        if ($commandId === null || trim($commandId) === '') {
            return $this->ocsErrorMapper->badRequest(
                'Required fields are missing',
                null,
                ['commandId' => ['commandId is required']]
            );
        }

        // T415: present but unstoreable. `pettycash_command_results.command_id` is
        // `string(36)` and the contract declares `format: uuid`, so an over-long
        // value reaches MySQL and answers 500. A malformed request is not a server
        // fault. **400, matching this method's own absent case** rather than 422:
        // both are the same class of fault, a request the caller must change.
        try {
            $commandId = CommandId::fromString((string) $commandId)->__toString();
        } catch (\InvalidArgumentException $malformed) {
            return $this->ocsErrorMapper->badRequest(
                'Malformed command ID',
                null,
                ['commandId' => [$malformed->getMessage()]]
            );
        }

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
                $expectedVersion,
                $commandId
            );
            return new DataResponse($this->formatTransaction($transaction));
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e, $commandId, ['publicId' => $transactionId]);
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
            return $this->ocsErrorMapper->map($e, $commandId, ['publicId' => $transactionId]);
        }
    }

    /**
     * @param string $transactionId
    /**
     * `contracts/ocs-api.yaml:519-531` declares `X-PettyCash-Command-ID` **required** here, and
     * unlike the two DELETEs settled in T318 this is a non-repeatable command under
     * `workflow.md`'s own definition — so the header is load-bearing and a missing one must be
     * refused rather than invented.
     *
     * It used to fall back to a server-generated `Uuid::random()`, which is the worst of both: the
     * request was accepted, and because a replay then arrived under a *different* command id the
     * idempotency wrapper had nothing to match. A double-clicked "create correction" produced two
     * drafts, neither of which the client could recognise as the same command. Measured: the endpoint
     * answered `201` with the header absent.
     *
     * The status is **400**, matching `resubmit()` below, `ReviewController`, and
     * `ManagementReviewControllerTest::testRecordDecisionMissingCommandIdReturns400`: a missing
     * required *parameter* is structurally invalid, which is distinct from the 422 the mapper's
     * `validation()` emits for an unacceptable body field.
     *
     * @param int $expectedVersion
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function correction(string $transactionId, ?int $expectedVersion = null): DataResponse
    {
        $commandId = trim((string) $this->request->getHeader('X-PettyCash-Command-ID'));
        if ($commandId === '') {
            return $this->ocsErrorMapper->badRequest(
                'Required fields are missing',
                null,
                ['commandId' => ['commandId is required']]
            );
        }

        if ($expectedVersion === null) {
            return $this->ocsErrorMapper->validation(
                'Required fields are missing',
                $commandId,
                ['expectedVersion' => ['expectedVersion is required']]
            );
        }

        // T415: present but unstoreable. `pettycash_command_results.command_id` is `string(36)` and the
        // contract declares `format: uuid`, so an over-long value reaches MySQL and answers 500. A
        // malformed request is not a server fault. **400, matching this method's own absent case.**
        try {
            $commandId = CommandId::fromString($commandId)->__toString();
        } catch (\InvalidArgumentException $malformed) {
            return $this->ocsErrorMapper->badRequest(
                'Malformed command ID',
                null,
                ['commandId' => [$malformed->getMessage()]]
            );
        }

        try {
            $revision = $this->createCorrectionDraft->execute($transactionId, $commandId, $expectedVersion);
            return new DataResponse($this->formatRevision($revision), 201);
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e, $commandId, ['publicId' => $transactionId]);
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
        // Required for the same reason as on `correction()` above, and the same fallback used to
        // defeat it: a server-generated command id meant a replayed resubmission arrived under a new
        // key, so `ResubmitCorrection`'s `IdempotencyService` could not recognise it and the
        // revision would be activated twice. FR-049 restarts review once.
        $commandId = trim((string) $this->request->getHeader('X-PettyCash-Command-ID'));
        if ($commandId === '') {
            return $this->ocsErrorMapper->badRequest(
                'Required fields are missing',
                null,
                ['commandId' => ['commandId is required']]
            );
        }

        // T415: present but unstoreable. `pettycash_command_results.command_id` is `string(36)` and the
        // contract declares `format: uuid`, so an over-long value reaches MySQL and answers 500. A
        // malformed request is not a server fault. **400, matching this method's own absent case.**
        try {
            $commandId = CommandId::fromString($commandId)->__toString();
        } catch (\InvalidArgumentException $malformed) {
            return $this->ocsErrorMapper->badRequest(
                'Malformed command ID',
                null,
                ['commandId' => [$malformed->getMessage()]]
            );
        }

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
            return $this->ocsErrorMapper->map($e, $commandId, ['publicId' => $transactionId]);
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
            return $this->ocsErrorMapper->map($e, null, ['publicId' => $transactionId]);
        }
    }

    /**
     * The contract's `AuditPage`.
     *
     * `contracts/ocs-api.yaml:581` declares this operation's `200` as `$ref:
     * '#/components/responses/AuditPage'`, which at `:913-922` is `PageMeta` — `page`, `pageSize`,
     * `total`, all `required` — plus `items`. The endpoint previously returned `array_map(...)`
     * directly, so `ocs.data` was a JSON list and every one of those keys was absent, while
     * `:574-575` declared `Page` and `PageSize` that nothing read.
     *
     * `page`/`pageSize` are nullable rather than typed `int` because of T225: the AppFramework
     * `Dispatcher` coerces an absent query parameter to `null` and would throw an unhandled
     * `TypeError` on an `int` signature *before* this method body runs — returning a `400` with an
     * empty body and no `ocs` envelope, which is the failure T225 existed to remove from this very
     * controller. The absence is therefore resolved inside the body, where `PageBounds` can apply
     * the contract's own defaults.
     *
     * The bounds live in `PageBounds` rather than here so `AuditQueryService` (T093) can reuse the
     * same rule as `ReportFilter` instead of a third copy. What this method owns is the assembly:
     * it is the only place that knows the response is an `AuditPage`.
     *
     * **Authorization is not this method's, and is still T093's.** It reaches
     * `TransactionService::getTransaction()`, which is owner-only, so a manager, finance officer, or
     * administrator is answered 404 today. Correcting that is `AuditQueryService`'s work; widening
     * the gate here would put a second, divergent authorization decision in the controller — which
     * is how the two would drift apart.
     */
    /**
     * @param string $transactionId
     * @param int|null $page
     * @param int|null $pageSize
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function audit(string $transactionId, ?int $page = null, ?int $pageSize = null): DataResponse
    {
        try {
            $bounds = new PageBounds($page, $pageSize);

            $transaction = $this->transactionService->getTransaction($transactionId);
            if ($transaction === null) {
                return $this->ocsErrorMapper->notFound('Transaction not found');
            }

            $transactionDbId = $transaction->dbId ?? 0;
            $auditEvents = $this->auditRepository->findByTransactionId($transactionDbId, $bounds->offset, $bounds->pageSize);

            return new DataResponse(
                $bounds->pageMeta($this->auditRepository->countByTransactionId($transactionDbId)) + [
                    'items' => array_map([$this, 'formatAuditEvent'], $auditEvents),
                ]
            );
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e, null, ['publicId' => $transactionId]);
        }
    }

    /**
     * The contract's `TransactionDetail`.
     *
     * Formatting lives in `TransactionPayloadFormatter` because `ReviewController` used to
     * carry a second, differently drifted copy of it; see that class for the schemas.
     */
    private function formatTransaction(object $transaction): array
    {
        return $this->payloadFormatter->detail($transaction);
    }

    /** The contract's `Revision`, for the bare-revision endpoints. */
    private function formatRevision(TransactionRevision $rev): array
    {
        return $this->payloadFormatter->revision($rev);
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