<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Command\Repository;

use OCA\PettyCash\Application\Command\CommandResult;

/** @extends \IteratorAggregate<int, CommandResult> */
interface CommandResultRepository
{
    public function find(string $commandId): ?CommandResult;
    public function save(CommandResult $result): void;
}