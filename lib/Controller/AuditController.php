<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use OCA\PettyCash\Application\Audit\AuditQueryService;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Audit\AuditEvent;
use OCA\PettyCash\Domain\Shared\PageBounds;
use OCA\PettyCash\Infrastructure\Http\OcsErrorMapper;
use OCA\PettyCash\Infrastructure\Http\TransactionPayloadFormatter;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * T096: the revision-history and transaction-audit reads.
 *
 * Thin by direction: both actions resolve visibility and paging through
 * `AuditQueryService` and formatting through `TransactionPayloadFormatter`, so
 * this class carries no rule of its own. It exists because these two reads
 * lived on `TransactionController` only for want of a home — a write-oriented
 * controller answering history reads is how the owner-only gate survived
 * unnoticed, with reviewers answered 404 for history they were entitled to.
 *
 * The URLs are unchanged from the `transaction#*` routes this replaces; only
 * the route names move, and those are internal.
 */
class AuditController extends OCSController
{
    public function __construct(
        IRequest $request,
        private AuditQueryService $auditQueries,
        private CurrentUser $currentUser,
        private OcsErrorMapper $ocsErrorMapper,
        private TransactionPayloadFormatter $payloadFormatter
    ) {
        parent::__construct('pettycash', $request);
    }

    /**
     * List a transaction's immutable revisions, oldest first.
     */
    #[NoAdminRequired]
    public function revisions(string $transactionId): DataResponse
    {
        try {
            // Role-scoped like audit below: the owner, an in-scope reviewer,
            // finance at its stage, or an administrator. Anything else hides.
            $transaction = $this->auditQueries->visibleTransaction($this->currentUser->uid(), $transactionId);
            if ($transaction === null) {
                return $this->ocsErrorMapper->notFound('Transaction not found');
            }

            $revisions = $this->auditQueries->readRevisions($transaction);
            $formatted = array_map([$this, 'formatRevision'], $revisions);
            return new DataResponse($formatted);
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e, null, ['publicId' => $transactionId]);
        }
    }

    /**
     * The contract's `AuditPage` for one transaction.
     *
     * `contracts/ocs-api.yaml:581` declares this operation's `200` as an
     * `AuditPage`: `PageMeta` — `page`, `pageSize`, `total`, all `required` —
     * plus `items`. `page`/`pageSize` stay nullable in the signature because of
     * T225 (an absent query parameter coerces to `null`, and an `int` signature
     * would throw before this body runs); `PageBounds` applies the defaults.
     */
    #[NoAdminRequired]
    public function timeline(string $transactionId, ?int $page = null, ?int $pageSize = null): DataResponse
    {
        try {
            $bounds = new PageBounds($page, $pageSize);

            // Role-scoped (FR-051/FR-055): the owner, an in-scope reviewer,
            // finance at its stage, or an administrator. An out-of-scope
            // caller gets the same 404 as for a missing record, so the
            // endpoint cannot confirm a record they may not see.
            $transaction = $this->auditQueries->visibleTransaction($this->currentUser->uid(), $transactionId);
            if ($transaction === null) {
                return $this->ocsErrorMapper->notFound('Transaction not found');
            }

            $read = $this->auditQueries->readTransactionAudit($transaction, $bounds->offset, $bounds->pageSize);
            $auditEvents = $read['items'];

            return new DataResponse(
                $bounds->pageMeta($read['total']) + [
                    'items' => array_map([$this, 'formatAuditEvent'], $auditEvents),
                ]
            );
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e, null, ['publicId' => $transactionId]);
        }
    }

    /** The contract's `Revision`, formatted by the single emitter. */
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
