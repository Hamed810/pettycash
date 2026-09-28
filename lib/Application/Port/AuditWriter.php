<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Port;

interface AuditWriter
{
    /**
     * Append an event to the transaction audit stream.
     *
     * @param array<string,mixed> $event
     */
    public function append(array $event): void;

    /**
     * Append an event to the configuration audit stream (FR-055).
     *
     * Configuration events identify their subject by entity_type/entity_id, never by
     * sheet/transaction, so they cannot be appended through the transaction stream.
     *
     * @param array<string,mixed> $event
     */
    public function appendConfiguration(array $event): void;
}
