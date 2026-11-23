<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Finance;

use DateTimeImmutable;

/**
 * Feature 008 — port for the set-based Finance read projections.
 *
 * The holder-directory and holder-account reads in the Application layer depend on this port rather
 * than on the Infrastructure SQL class directly. That keeps the services exercisable over an
 * in-memory double in the PHPUnit harness (which has no `IDBConnection`), while the real SQL
 * implementation is verified against MariaDB through the persistence round-trip
 * (`tests/Integration/Persistence/roundtrip-runner.php`). This mirrors how `UserDirectory` and
 * `AccountRepository` give the Application layer its seams.
 *
 * Every method is a bounded set-based projection; nothing here implies a per-holder or per-account
 * query, and nothing reaches the Nextcloud user directory.
 */
interface FinanceRead
{
    /** How many distinct petty-cash holders exist (distinct account-owning `user_uid` count). */
    public function holderCount(): int;

    /**
     * A page of holders, grouped and ordered, with total and active account counts.
     *
     * @return list<array{userId: string, accountCount: int, activeAccountCount: int}>
     */
    public function holderPage(int $offset, int $limit): array;

    /**
     * The complete grouped holder population, ordered by uid.
     *
     * @return list<array{userId: string, accountCount: int, activeAccountCount: int}>
     */
    public function allHolders(): array;

    /**
     * Account-activity facts for a set of surrogate account ids, in one grouped read.
     *
     * @param list<int> $dbIds surrogate `pettycash_accounts.id` values
     * @return array<int, array{movementCount: int, lastMovementAt: ?DateTimeImmutable}> keyed by account db id
     */
    public function accountActivityFor(array $dbIds): array;

    /**
     * The Cost Sheet behind each consumption movement's revision, in one batched resolution.
     *
     * The chain is `movement.source_revision_id` → revision → transaction → sheet. The key is the
     * revision's **public UUID** because that is what the movement aggregate carries; the value names
     * the sheet's public UUID (the authoritative reference — the schema has no separate business
     * reference column) and its title.
     *
     * A revision with no resolvable transaction or sheet is **absent** from the map rather than
     * present with nulls: the caller must treat a consumption with no entry as a data fault, never as
     * an `approved: null` row that would misrepresent a posting.
     *
     * @param list<string> $sourceRevisionPublicIds
     * @return array<string, array{sheetId: string, sheetTitle: string}> keyed by revision public UUID
     */
    public function ledgerContextFor(array $sourceRevisionPublicIds): array;

    /**
     * Approved/total transaction counts for a set of sheets, in one grouped read.
     *
     * `approvedCount` is the current count of transactions whose authoritative `final_outcome` is
     * `APPROVED`; `totalCount` is every transaction of the sheet. This is the current sheet fact the
     * approved plan defines, not a posting-time snapshot, and it is never stored as a second aggregate.
     *
     * A sheet with no rows is absent from the map; the caller must not invent a count for it.
     *
     * @param list<string> $sheetPublicIds
     * @return array<string, array{approvedCount: int, totalCount: int}> keyed by sheet public UUID
     */
    public function ledgerApprovedCountsFor(array $sheetPublicIds): array;
}
