<?php
declare(strict_types=1);
namespace OCA\PettyCash\Application\Command;
interface CommandResultRepository { public function find(string $id): ?CommandResult; public function save(CommandResult $result): void; }
