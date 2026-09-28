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