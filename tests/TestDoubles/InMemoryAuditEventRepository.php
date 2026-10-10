<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use DateTimeImmutable;
use OCA\PettyCash\Domain\Audit\AuditEvent;
use OCA\PettyCash\Domain\Audit\Repository\AuditEventRepository;

final class InMemoryAuditEventRepository implements \OCA\PettyCash\Domain\Audit\Repository\AuditEventRepository
{
    /**
     * @var array<int, AuditEvent>
     */
    private array $events = [];

    public function findById(int $id): ?AuditEvent
    {
        return $this->events[$id] ?? null;
    }

    public function findByPublicId(string $publicId): ?AuditEvent
    {
        foreach ($this->events as $event) {
            if ($event->publicId === $publicId) {
                return $event;
            }
        }
        return null;
    }

    /**
     * @return list<AuditEvent>
     */
    public function findByTransactionId(int $transactionId, int $offset = 0, int $limit = 100): array
    {
        $result = array_filter($this->events, fn($e) => $e->transactionId === $transactionId);
        return array_slice(array_values($result), $offset, $limit);
    }

    /**
     * Counts the whole set rather than a window, mirroring
     * `DbAuditEventRepository::countByTransactionId()`.
     *
     * Faithful in the property T319 depends on: the predicate matches this class's
     * `findByTransactionId()`'s exactly, so the endpoint can never answer 200 with a `total` that
     * disagrees with the rows it was counted beside.
     *
     * **Deliberately narrower than production on one rule, and T321 is why.** The real repository
     * widens the transaction timeline to include the sheet's sheet-scoped events
     * (`transaction_id IS NULL`) so that FR-051's "formal transaction audit MUST begin with sheet
     * submission" holds. This double filters on `transactionId` alone and cannot do otherwise: folding
     * in a sheet-scoped event needs a transaction -> sheet lookup, and this double holds audit events
     * with no transaction table behind it. Inventing one would be a fixture that could disagree with
     * the database, which is the failure mode doubles exist to avoid.
     *
     * So this double mirrors count/read *agreement* and not the *inclusion* rule. The inclusion rule is
     * pinned by `TransactionTimelineSubmissionTest`, which goes through the real repository over live
     * HTTP — a double could not detect a regression there anyway, since it is the thing being replaced.
     */
    public function countByTransactionId(int $transactionId): int
    {
        return count(array_filter($this->events, fn($e) => $e->transactionId === $transactionId));
    }

    /**
     * @return list<AuditEvent>
     */
    public function findBySheetId(int $sheetId, int $offset = 0, int $limit = 100): array
    {
        $result = array_filter($this->events, fn($e) => $e->sheetId === $sheetId);
        return array_slice(array_values($result), $offset, $limit);
    }

    /**
     * @return list<AuditEvent>
     */
    public function findByActorUid(string $actorUid, int $offset = 0, int $limit = 100): array
    {
        $result = array_filter($this->events, fn($e) => $e->actorUid === $actorUid);
        return array_slice(array_values($result), $offset, $limit);
    }

    /**
     * @return list<AuditEvent>
     */
    public function findByDateRange(DateTimeImmutable $from, DateTimeImmutable $to, int $offset = 0, int $limit = 100): array
    {
        $result = array_filter($this->events, fn($e) => $e->occurredAt >= $from && $e->occurredAt <= $to);
        return array_slice(array_values($result), $offset, $limit);
    }

    public function findEntityIdsByEventType(string $eventType, int $offset = 0, int $limit = 1000): array
    {
        $ids = [];
        foreach ($this->events as $event) {
            if ($event->eventType === $eventType && $event->entityId !== null && $event->entityId !== '') {
                $ids[$event->entityId] = true;
            }
        }
        $ids = array_keys($ids);
        sort($ids);

        return array_slice($ids, $offset, $limit);
    }

    public function save(AuditEvent $event): void
    {
        if ($event->dbId === null) {
            $event->dbId = count($this->events) + 1;
        }
        $this->events[$event->dbId] = $event;
    }

    public function clear(): void
    {
        $this->events = [];
    }

    /**
     * @return list<AuditEvent>
     */
    public function getEvents(): array
    {
        return array_values($this->events);
    }
}