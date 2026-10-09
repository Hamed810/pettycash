<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application;

use RuntimeException;

/**
 * The record moved on between the caller reading it and writing to it.
 *
 * ## Why this answers 409 and not 422
 *
 * This class exists because eight guards were raising `InvalidArgumentException('Stale version')`,
 * and `OcsErrorMapper` maps that class to `422 VALIDATION_FAILED` — so a stale write and a malformed
 * field were indistinguishable to a client, and `contracts/ocs-api.yaml:958-962` declares a `Conflict`
 * response whose own description opens with the words *"Stale version, duplicate/conflicting command,
 * invalid state, or concurrency conflict"*. The contract therefore already promised a 409 for this
 * condition, and the four endpoints below contradicted their own published contract. That is a
 * Constitution II public-contract violation, not a stylistic disagreement.
 *
 * The distinction is the third answer the `Conflict` interface documents: the request was **correct
 ***, the record it addresses **exists**, and applying it now would be **wrong**. Nothing about
 * `$expectedVersion` being stale makes the caller's input malformed — it makes their view of the world
 * out of date, and the fix is to reload. Answering 422 told the caller their input was at fault,
 * which is false, and gave them nothing to act on.
 *
 * ## Why one shared class rather than one per domain
 *
 * `ConfigurationConflict` already made this argument for configuration administration ("six
 * near-identical files differing only in a string literal trades a real distinction for a cosmetic
 * one"), and the same reasoning applies here across `Application\Expense` and `Application\Workflow`.
 * It lives beside the `Conflict` interface it implements rather than inside either domain, because both
 * domains raise it and neither owns it. What `OcsErrorMapper` needs is `errorCode()`, not the PHP
 * type, and the code is what keeps stale version distinguishable from the *other* 409s this app
 * raises — `ACCOUNT_VERSION_CONFLICT`, `SUPERSEDED_ATTEMPT`, `*_ALREADY_EXISTS`.
 *
 * ## Why the message leads with "Stale version"
 *
 * `expectExceptionMessage()` matches by **substring**, and `SheetSubmissionTest` asserts the literal
 * fragment `'Stale version'`. Keeping that fragment as the leading clause therefore preserves an
 * existing assertion rather than weakening it, while the rest of the sentence carries the two facts
 * FR-025 requires: which version is current, and that the caller's next action is to reload. The
 * message is a caller-correctable sentence and never an internal detail.
 */
final class StaleRecordVersion extends RuntimeException implements Conflict
{
    private function __construct(
        private readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @param string $resource  human-readable resource noun, e.g. 'transaction' or 'sheet'
     * @param string $identity  the record's public identifier, so the caller can reload *that* record
     */
    public static function forResource(string $resource, string $identity, int $expected, int $actual): self
    {
        return new self(
            strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', '_', $resource)) . '_VERSION_CONFLICT',
            sprintf(
                'Stale version: the %s identified by %s is at version %d, not the expected %d; reload and retry',
                $resource,
                $identity,
                $actual,
                $expected
            )
        );
    }

    /**
     * The record moved between the caller reading it and the write being applied.
     *
     * Separate from `forResource()` because that one is raised where **both** versions are known — a
     * service reads the live record and compares it against what the caller sent, so it can name both
     * numbers. A repository that guards its `UPDATE`/`DELETE` on `lock_version` and matches zero rows
     * does **not** have that: it knows the value it sent and nothing about the value now stored.
     *
     * So this message does not quote a version. Quoting `$sheet->lockVersion` as both the actual and
     * the expected — the obvious shortcut — produces "is at version 3, not the expected 3", which is
     * self-contradictory and tells a reader nothing. Saying the record changed under the operation is
     * true, actionable, and says everything this layer actually knows.
     */
    public static function movedDuringWrite(string $resource, string $identity): self
    {
        return new self(
            strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', '_', $resource)) . '_VERSION_CONFLICT',
            sprintf(
                'Stale version: the %s identified by %s changed while this %s was being written; reload and retry',
                $resource,
                $identity,
                $resource
            )
        );
    }
}