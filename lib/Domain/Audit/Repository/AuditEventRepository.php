<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Audit\Repository;

use DateTimeImmutable;
use OCA\PettyCash\Domain\Audit\AuditEvent;

/** @extends \IteratorAggregate<int, AuditEvent> */
interface AuditEventRepository
{
    public function findById(int $id): ?AuditEvent;
    public function findByPublicId(string $publicId): ?AuditEvent;
    /** @return list<AuditEvent> */
    public function findByTransactionId(int $transactionId, int $offset = 0, int $limit = 100): array;
    /** @return list<AuditEvent> */
    public function findBySheetId(int $sheetId, int $offset = 0, int $limit = 100): array;
    /** @return list<AuditEvent> */
    public function findByActorUid(string $actorUid, int $offset = 0, int $limit = 100): array;
    /** @return list<AuditEvent> */
    public function findByDateRange(DateTimeImmutable $from, DateTimeImmutable $to, int $offset = 0, int $limit = 100): array;
    public function save(AuditEvent $event): void;
}