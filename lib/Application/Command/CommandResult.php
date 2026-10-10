<?php
declare(strict_types=1);
namespace OCA\PettyCash\Application\Command;
final readonly class CommandResult {
    /** @param array<string,mixed> $response */
    public function __construct(public string $commandId, public string $payloadHash, public array $response) {}
}
