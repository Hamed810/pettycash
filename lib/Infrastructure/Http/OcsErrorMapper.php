<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Http;

use InvalidArgumentException;
use LogicException;
use OCA\PettyCash\Application\Command\CommandConflict;
use OCP\AppFramework\Http;
use OCA\PettyCash\Infrastructure\Nextcloud\TechnicalLogger;
use OCP\AppFramework\Http\DataResponse;
use Throwable;

/**
 * Centralised OCS error mapping.
 *
 * Every controller failure path funnels through this class so that the response
 * body always matches the `Error` schema in `contracts/ocs-api.yaml`
 * (`code`, `message`, optional `fieldErrors`, optional `commandId`) and so that
 * `ocs.meta.statuscode` matches the HTTP status. The payload is nested under an
 * `error` key, which is the shape the typed frontend client reads.
 */
final class OcsErrorMapper
{
    public function __construct(
        private readonly TechnicalLogger $logger
    ) {}

    public function map(Throwable $error, ?string $commandId = null): DataResponse
    {
        [$status, $code] = match (true) {
            $error instanceof CommandConflict => [Http::STATUS_CONFLICT, 'IDEMPOTENCY_CONFLICT'],
            $error instanceof InvalidArgumentException => [Http::STATUS_UNPROCESSABLE_ENTITY, 'VALIDATION_FAILED'],
            $error instanceof LogicException => [Http::STATUS_FORBIDDEN, 'FORBIDDEN'],
            default => [Http::STATUS_INTERNAL_SERVER_ERROR, 'UNEXPECTED_FAILURE'],
        };

        if ($status === Http::STATUS_INTERNAL_SERVER_ERROR) {
            // Never surface an internal message to the caller, but always record it:
            // technical errors must go through standard Nextcloud logging.
            $this->logger->unexpected($error, $commandId);
            $message = 'pettycash.error.unexpected';
        } else {
            $message = $error->getMessage();
        }

        return $this->respond($status, $code, $message, $commandId);
    }

    /** @param array<string, list<string>> $fieldErrors */
    public function validation(
        string $message,
        ?string $commandId = null,
        array $fieldErrors = [],
    ): DataResponse {
        return $this->respond(
            Http::STATUS_UNPROCESSABLE_ENTITY,
            'VALIDATION_FAILED',
            $message,
            $commandId,
            $fieldErrors,
        );
    }

    public function notFound(string $message, ?string $commandId = null): DataResponse
    {
        return $this->respond(Http::STATUS_NOT_FOUND, 'NOT_FOUND', $message, $commandId);
    }

    public function forbidden(string $message, ?string $commandId = null): DataResponse
    {
        return $this->respond(Http::STATUS_FORBIDDEN, 'FORBIDDEN', $message, $commandId);
    }

    public function conflict(string $message, ?string $commandId = null): DataResponse
    {
        return $this->respond(Http::STATUS_CONFLICT, 'STATE_CONFLICT', $message, $commandId);
    }

    /**
     * Reject a payload that exceeds a documented upload limit, per FR-074.
     *
     * @param array<string, list<string>> $fieldErrors
     */
    public function payloadTooLarge(
        string $message,
        ?string $commandId = null,
        array $fieldErrors = [],
    ): DataResponse {
        return $this->respond(
            Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
            'PAYLOAD_TOO_LARGE',
            $message,
            $commandId,
            $fieldErrors,
        );
    }

    /**
     * @param array<string, list<string>> $fieldErrors
     */
    private function respond(
        int $status,
        string $code,
        string $message,
        ?string $commandId = null,
        array $fieldErrors = [],
    ): DataResponse {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if ($commandId !== null) {
            $error['commandId'] = $commandId;
        }
        if ($fieldErrors !== []) {
            $error['fieldErrors'] = $fieldErrors;
        }

        return new DataResponse(['error' => $error], $status);
    }
}
