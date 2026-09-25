<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Application\Command\CommandResultRepository as PortCommandResultRepository;
use OCA\PettyCash\Application\Command\CommandResult;

final class InMemoryCommandResultRepository implements PortCommandResultRepository
{
    /**
     * @var array<string, CommandResult>
     */
    private array $results = [];

    public function find(string $commandId): ?CommandResult
    {
        return $this->results[$commandId] ?? null;
    }

    public function save(CommandResult $result): void
    {
        $this->results[$result->commandId] = $result;
    }

    public function clear(): void
    {
        $this->results = [];
    }
}