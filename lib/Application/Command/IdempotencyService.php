<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Command;

final readonly class IdempotencyService
{
    public function __construct(private CommandResultRepository $repository) {}

    /**
     * @param array<string,mixed> $payload
     * @param callable():array<string,mixed> $run
     * @return array<string,mixed>
     *
     * T251 replay rule: the same command ID, actor, operation, target and payload
     * returns the original result; reusing the command ID with different content
     * is a conflict. Rows written before the actor/operation/target columns
     * existed carry NULL there and replay hash-only, exactly as before, so
     * pre-migration rows keep their behavior verbatim.
     */
    public function execute(
        string $commandId,
        string $actorUid,
        string $operation,
        ?string $targetPublicId,
        array $payload,
        callable $run,
        int $resultStatus
    ): array {
        $hash = hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $existing = $this->repository->find($commandId);
        if ($existing !== null) {
            if ($existing->actorUid === null) {
                if (!hash_equals($existing->payloadHash, $hash)) { throw new CommandConflict('Command ID was already used with a different payload'); }
                return $existing->response;
            }
            if ($existing->actorUid !== $actorUid
                || $existing->operation !== $operation
                || $existing->targetPublicId !== $targetPublicId
                || !hash_equals($existing->payloadHash, $hash)
            ) { throw new CommandConflict('Command ID was already used with different content'); }
            return $existing->response;
        }
        $response = $run();
        $this->repository->save(new CommandResult($commandId, $hash, $response, $actorUid, $operation, $targetPublicId, $resultStatus));
        return $response;
    }

    /** @return mixed */
    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) { return $value; }
        if (!array_is_list($value)) { ksort($value, SORT_STRING); }
        foreach ($value as $key => $item) { $value[$key] = $this->canonicalize($item); }
        return $value;
    }
}
