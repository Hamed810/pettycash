<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Account\Repository;

use DateTimeImmutable;
use OCA\PettyCash\Domain\Account\AccountMovement;

/** @extends \IteratorAggregate<int, AccountMovement> */
interface AccountMovementRepository
{
    public function findById(int $id): ?AccountMovement;
    public function findByCommandId(string $commandId): ?AccountMovement;

    /**
     * The one consumption recorded against a revision, if there is one.
     *
     * Declared `int|string` because the two forms are both genuinely in use and the previous
     * signature pretended otherwise. The table's `source_revision_id` is a surrogate key and the
     * MariaDB adapter queries it as one; the aggregate carries the revision's **public** UUID, and
     * so does `contracts/ocs-api.yaml`, which declares `sourceRevisionId` as `format: uuid`. A
     * signature that admitted only the surrogate form therefore could not be called correctly
     * with the value the domain holds.
     *
     * This lookup is what FR-040's uniqueness rests on, together with the unique index on
     * `source_revision_id`, so an implementation that cannot match what it is given reports
     * "no consumption" for a consumption that exists — the one answer that hides a double charge.
     */
    public function findBySourceRevisionId(int|string $sourceRevisionId): ?AccountMovement;

    /** @return list<AccountMovement> */
    public function findByAccountId(int $accountId, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): array;
    /** @return list<AccountMovement> */
    public function findByAccountIdPaged(int $accountId, int $offset, int $limit): array;
    public function save(AccountMovement $movement): void;
}