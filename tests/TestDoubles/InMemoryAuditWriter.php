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

    /**
     * @var array<int, array>
     */
    private array $configurationEvents = [];

    public function append(array $event): void
    {
        $this->events[] = $event;
    }

    public function appendConfiguration(array $event): void
    {
        $this->configurationEvents[] = $event;
    }

    public function getEvents(): array
    {
        return $this->events;
    }

    public function getConfigurationEvents(): array
    {
        return $this->configurationEvents;
    }

    public function clear(): void
    {
        $this->events = [];
        $this->configurationEvents = [];
    }
}