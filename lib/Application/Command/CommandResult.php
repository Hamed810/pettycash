<?php
declare(strict_types=1);
namespace OCA\PettyCash\Application\Command;
final readonly class CommandResult {
    /**
     * @param array<string,mixed> $response
     * @param ?string $actorUid Null only for pre-T251 legacy rows, which carry
     * no actor/operation/target/status and replay hash-only.
     */
    public function __construct(
        public string $commandId,
        public string $payloadHash,
        public array $response,
        public ?string $actorUid = null,
        public ?string $operation = null,
        public ?string $targetPublicId = null,
        public ?int $resultStatus = null,
    ) {}
}
