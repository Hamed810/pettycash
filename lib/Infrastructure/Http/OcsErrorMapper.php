<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Http;

use InvalidArgumentException;
use OCA\PettyCash\Application\Command\CommandConflict;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http;
use Throwable;

final class OcsErrorMapper
{
    public function map(Throwable $error, ?string $commandId = null): DataResponse
    {
        [$status, $code] = match (true) {
            $error instanceof CommandConflict => [Http::STATUS_CONFLICT, 'IDEMPOTENCY_CONFLICT'],
            $error instanceof InvalidArgumentException => [Http::STATUS_UNPROCESSABLE_ENTITY, 'VALIDATION_FAILED'],
            default => [Http::STATUS_INTERNAL_SERVER_ERROR, 'UNEXPECTED_FAILURE'],
        };
        return new DataResponse(['error' => ['code' => $code, 'message' => $status === 500 ? 'pettycash.error.unexpected' : $error->getMessage(), 'commandId' => $commandId]], $status);
    }
}
