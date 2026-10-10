<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Port;

/**
 * Storage for the audit retention policy's configuration metadata.
 *
 * `plan.md` puts retention policy metadata in application configuration rather than in a table,
 * because the policy is a single system-wide control setting with no per-record rows of its own and
 * no history beyond the configuration audit events written whenever it changes. Keeping it behind a
 * port is what lets the domain and application layers stay free of any Nextcloud dependency.
 *
 * The record is a single associative array rather than a set of scalar accessors, so that the
 * adapter stores one value and the service reads it as one unit. `load()` returns null when nothing
 * has ever been configured, which is a state the service must handle and not treat as an error.
 */
interface RetentionPolicyStore
{
    /**
     * @return array{retentionYears: int, updatedAt: string, updatedBy: string}|null
     */
    public function load(): ?array;

    /**
     * @param array{retentionYears: int, updatedAt: string, updatedBy: string} $record
     */
    public function save(array $record): void;
}
