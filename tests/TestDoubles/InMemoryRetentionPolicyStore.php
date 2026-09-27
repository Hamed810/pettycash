<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Application\Port\RetentionPolicyStore;

/**
 * In-memory retention policy store.
 *
 * `loadRaw()` is deliberate: `RetentionPolicyService` is required to survive a stored value that
 * records fewer than seven years, and the only honest way to set that up is to write it directly
 * rather than through `save()`, which the service itself refuses to do. A double whose only write
 * path is the one the code under test validates cannot express the invalid state.
 */
final class InMemoryRetentionPolicyStore implements RetentionPolicyStore
{
    /** @var array{retentionYears: int, updatedAt: string, updatedBy: string}|null */
    private ?array $record = null;

    private int $saveCount = 0;

    public function load(): ?array
    {
        return $this->record;
    }

    public function save(array $record): void
    {
        $this->record = $record;
        $this->saveCount++;
    }

    /** @param array{retentionYears: int, updatedAt: string, updatedBy: string}|null $record */
    public function loadRaw(?array $record): void
    {
        $this->record = $record;
    }

    public function saveCount(): int
    {
        return $this->saveCount;
    }
}
