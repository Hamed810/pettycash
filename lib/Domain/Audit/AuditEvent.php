<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Audit;

use DateTimeImmutable;

final class AuditEvent
{
    public function __construct(
        public string $id,
        public ?int $sheetId,
        public ?int $transactionId,
        public ?int $revisionId,
        public string $stage,
        public ?int $decisionId,
        public string $entityType,
        public ?string $entityId,
        public string $eventType,
        public string $actorUid,
        public string $actorName,
        public string $actorRole,
        public ?string $explanation,
        public string $beforeJson,
        public string $afterJson,
        public ?string $commandId,
        public DateTimeImmutable $occurredAt,
        public ?int $dbId = null,
        public ?string $publicId = null
    ) {}
}