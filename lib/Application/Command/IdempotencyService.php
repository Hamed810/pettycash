<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Command;

final readonly class IdempotencyService
{
    public function __construct(private CommandResultRepository $repository) {}

    /** @param array<string,mixed> $payload @param callable():array<string,mixed> $operation @return array<string,mixed> */
    public function execute(string $commandId, array $payload, callable $operation): array
    {
        $hash = hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $existing = $this->repository->find($commandId);
        if ($existing !== null) {
            if (!hash_equals($existing->payloadHash, $hash)) { throw new CommandConflict('Command ID was already used with a different payload'); }
            return $existing->response;
        }
        $response = $operation();
        $this->repository->save(new CommandResult($commandId, $hash, $response));
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
