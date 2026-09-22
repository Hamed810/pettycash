<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Audit;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Audit\AuditEvent;
use OCA\PettyCash\Domain\Audit\Repository\AuditEventRepository;
use OCA\PettyCash\Domain\Shared\ActorSnapshot;

final class AuditWriterService implements AuditWriter
{
    public function __construct(
        private readonly AuditEventRepository $auditRepository,
        private readonly CurrentUser $currentUser,
        private readonly UserDirectory $userDirectory
    ) {}

    /** @param array<string,mixed> $event */
    public function append(array $event): void
    {
        $actorUid = $this->currentUser->uid();
        $actorName = $this->userDirectory->displayName($actorUid);
        $actorRole = $event['actor_role'] ?? 'PURCHASER';
        
        $auditEvent = new AuditEvent(
            $event['id'] ?? \OCA\PettyCash\Domain\Shared\Uuid::random()->__toString(),
            $event['sheet_id'] ?? null,
            $event['transaction_id'] ?? null,
            $event['revision_id'] ?? null,
            $event['stage'] ?? 'DRAFT',
            $event['decision_id'] ?? null,
            $event['entity_type'] ?? '',
            $event['entity_id'] ?? null,
            $event['event_type'] ?? 'UNKNOWN',
            $actorUid,
            $actorName,
            $actorRole,
            $event['explanation'] ?? null,
            json_encode($event['before'] ?? []),
            json_encode($event['after'] ?? []),
            $event['command_id'] ?? null,
            new DateTimeImmutable('now', new DateTimeZone('UTC'))
        );

        $this->auditRepository->save($auditEvent);
    }

    public function appendTransaction(
        string $eventType,
        ActorSnapshot $actor,
        string $stage,
        ?int $sheetId = null,
        ?int $transactionId = null,
        ?int $revisionId = null,
        ?int $decisionId = null,
        ?string $explanation = null,
        array $before = [],
        array $after = [],
        ?string $commandId = null
    ): void {
        $auditEvent = new AuditEvent(
            \OCA\PettyCash\Domain\Shared\Uuid::random()->__toString(),
            $sheetId,
            $transactionId,
            $revisionId,
            $stage,
            $decisionId,
            'transaction',
            null,
            $eventType,
            $actor->uid,
            $actor->displayName,
            $actor->role->value,
            $explanation,
            json_encode($before),
            json_encode($after),
            $commandId,
            new DateTimeImmutable('now', new DateTimeZone('UTC'))
        );

        $this->auditRepository->save($auditEvent);
    }

    public function appendConfiguration(
        string $eventType,
        ActorSnapshot $actor,
        string $entityType,
        string $entityId,
        ?string $explanation = null,
        array $before = [],
        array $after = [],
        ?string $commandId = null
    ): void {
        $auditEvent = new AuditEvent(
            \OCA\PettyCash\Domain\Shared\Uuid::random()->__toString(),
            null,
            null,
            null,
            'CONFIGURATION',
            null,
            $entityType,
            $entityId,
            $eventType,
            $actor->uid,
            $actor->displayName,
            $actor->role->value,
            $explanation,
            json_encode($before),
            json_encode($after),
            $commandId,
            new DateTimeImmutable('now', new DateTimeZone('UTC'))
        );

        $this->auditRepository->save($auditEvent);
    }
}