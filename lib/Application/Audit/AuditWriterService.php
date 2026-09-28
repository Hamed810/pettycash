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

final class AuditWriterService implements AuditWriter
{
    public function __construct(
        private readonly AuditEventRepository $auditRepository,
        private readonly CurrentUser $currentUser,
        private readonly UserDirectory $userDirectory,
        private readonly AuditEventRepository $configurationAuditRepository
    ) {}

    /** @param array<string,mixed> $event */
    public function append(array $event): void
    {
        $this->auditRepository->save($this->toEvent($event));
    }

    /**
     * FR-055: configuration changes are audited in their own stream, addressed by
     * entity_type/entity_id. Settlement writes one of these naming the account it charges.
     *
     * @param array<string,mixed> $event
     */
    public function appendConfiguration(array $event): void
    {
        if (($event['entity_type'] ?? '') === '') {
            throw new \InvalidArgumentException(
                'A configuration audit event must name its subject with entity_type.'
            );
        }
        $this->configurationAuditRepository->save($this->toEvent($event));
    }

    /** @param array<string,mixed> $event */
    private function toEvent(array $event): AuditEvent
    {
        $actorUid = $this->currentUser->uid();
        $actorName = $this->userDirectory->displayName($actorUid);
        $actorRole = $event['actor_role'] ?? 'PURCHASER';

        return new AuditEvent(
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
    }
}
