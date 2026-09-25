<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Domain\Audit\AuditEvent;

final class InMemoryAuditWriter implements AuditWriter
{
    /**
     * @var array<int, array>
     */
    private array $events = [];

    public function append(array $event): void
    {
        $this->events[] = $event;
    }

    public function getEvents(): array
    {
        return $this->events;
    }

    public function clear(): void
    {
        $this->events = [];
    }
}