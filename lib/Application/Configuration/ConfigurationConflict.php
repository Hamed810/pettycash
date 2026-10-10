<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Configuration;

use OCA\PettyCash\Application\Conflict;
use RuntimeException;

/**
 * A well-formed configuration command that cannot be applied because the record moved or already exists.
 *
 * ## Why this is one class rather than one per resource
 *
 * `lib/Application/Account/` has a class per conflict — `AccountAlreadyExists`,
 * `StaleAccountVersion` — because those two arrived separately and each carries its own docblock
 * explaining why the caller's next action differs. Configuration administration has the same two
 * shapes for three resources, which would be six near-identical files whose only real content is a
 * string.
 *
 * What `OcsErrorMapper` needs is `errorCode()`, not the class: the `Conflict` interface exists
 * precisely so that "a client can branch on `ACCOUNT_VERSION_CONFLICT` without the mapper growing a
 * table of exception classes it should not know about". A code supplied at the throw site keeps that
 * property intact — the *code* stays as distinguishable as it was, and only the PHP type is shared.
 * The alternative, six files differing only in a string literal, trades a real distinction for a
 * cosmetic one.
 *
 * ## Why 409 rather than 422
 *
 * Both halves of this are the third answer `Conflict` documents: the request was correct, the thing
 * it addresses exists, and applying it now would be wrong. A duplicate title is not invalid input —
 * the title is well formed, it just names something already in force. A stale `expectedVersion` is
 * not invalid input either; it is correct input about a version that has moved on. FR-017's change
 * reason makes the caller's recovery obvious in both cases: reload and retry.
 */
final class ConfigurationConflict extends RuntimeException implements Conflict
{
    /**
     * Named `errorCode` and not `code` because `Exception` already declares an untyped `int $code`,
     * and PHP refuses to redeclare it — a typed `readonly string` over it is a fatal error. That was
     * found by running this class, not by reading it: the signature is legal-looking and the failure
     * only appears when the class is first loaded.
     *
     * @param string $errorCode  stable, client-facing; the contract's error code for this condition
     * @param string $message    caller-correctable sentence, never an internal detail
     */
    public function __construct(
        private readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public static function alreadyExists(string $resource, string $identity): self
    {
        return new self(
            'CONFIGURATION_' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $resource) ?? '') . '_ALREADY_EXISTS',
            sprintf('A %s identified by %s already exists', $resource, $identity)
        );
    }

    public static function staleVersion(string $resource, string $identity, int $expected, int $actual): self
    {
        return new self(
            'CONFIGURATION_' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $resource) ?? '') . '_VERSION_CONFLICT',
            sprintf(
                'The %s identified by %s is at version %d, not the expected %d; reload and retry',
                $resource,
                $identity,
                $actual,
                $expected
            )
        );
    }
}
