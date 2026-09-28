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
    /**
     * The distinct subjects an event type has been recorded against, as their `entity_id` values.
     *
     * FR-016 makes a charge an instance-wide fact — every finance officer shares one queue — so
     * "which accounts have been charged" is a question about the whole stream rather than about
     * one actor. No existing read answers it: the actor reads are scoped to a single uid, and the
     * date range returns events rather than subjects.
     *
     * Only the configuration stream defines `entity_id`; on the transaction stream this is
     * undefined rather than empty, and an implementation must refuse it instead of returning
     * nothing, because "no entity ids" and "wrong table" lead to opposite conclusions.
     *
     * @return list<string>
     */
    public function findEntityIdsByEventType(string $eventType, int $offset = 0, int $limit = 1000): array;
    public function save(AuditEvent $event): void;
}