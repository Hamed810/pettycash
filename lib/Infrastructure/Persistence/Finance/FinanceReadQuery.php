<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Persistence\Finance;

use DateTimeImmutable;
use OCA\PettyCash\Application\Finance\FinanceRead;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Feature 008 — set-based Finance read projections over the existing account tables.
 *
 * This is the infrastructure read component behind the holder directory, the holder-account finance
 * read, and the authoritative account ledger. Every method is a bounded set-based query over
 * `pettycash_accounts` / `pettycash_account_movements` (and, for the ledger provenance reads, the
 * existing revision/transaction/sheet chain); nothing here issues a query per holder, per account, or
 * per movement row, and nothing reaches the Nextcloud user directory.
 *
 * ## Why a dedicated query class rather than more methods on the account repository
 *
 * The holder directory is a grouped projection (`GROUP BY user_uid`) that the account repository's
 * existing read methods do not express, and the account activity read aggregates across accounts the
 * movement repository has no method for. Putting them in one Finance-scoped query class keeps the
 * account domain repositories focused on the aggregate they own and mirrors how `SheetSummaryQuery`
 * already hosts a cross-row projection in this codebase.
 *
 * ## Portability
 *
 * The active-account count is `SUM(CASE WHEN active THEN 1 ELSE 0 END)` rather than `SUM(active)`:
 * `pettycash_accounts.active` is a `Types::BOOLEAN` column, and PostgreSQL rejects `SUM(boolean)`.
 * The CASE expression is in the portable catalogue the project's static portability check enforces.
 * The grouped holder query selects the grouped column with the same qualification as its `GROUP BY`,
 * which is what keeps `ONLY_FULL_GROUP_BY` on MySQL/MariaDB satisfied.
 *
 * ## Final and the FinanceRead port
 *
 * This class is `final readonly` like `SheetSummaryQuery`. The Application-layer services depend on
 * the `FinanceRead` port it implements instead of this class, so they can be tested over an
 * in-memory double in the PHPUnit harness (which has no `IDBConnection`); the real SQL is what lives
 * here and is verified against a real database by the persistence round-trip
 * (`tests/Integration/Persistence/roundtrip-runner.php`).
 */
final readonly class FinanceReadQuery implements FinanceRead
{
    public function __construct(private IDBConnection $connection) {}

    /**
     * How many distinct petty-cash holders exist.
     *
     * A holder is a user owning at least one `pettycash_accounts` row, so this is the distinct
     * `user_uid` count. Counted as `COUNT(DISTINCT user_uid)`, never as `COUNT(*)` over the rows —
     * a user with an account per currency is one holder, not several.
     */
    public function holderCount(): int
    {
        $qb = $this->connection->getQueryBuilder();
        // `COUNT(DISTINCT ...)` is passed through `createFunction` rather than `func()->count()`,
        // because Nextcloud's `count()` helper would quote `DISTINCT a.user_uid` as a single
        // identifier (`` `DISTINCT a`.`user_uid` ``) and the query would fail. COUNT(DISTINCT x)
        // is portable across MySQL/MariaDB, PostgreSQL and SQLite.
        $qb->select($qb->createFunction('COUNT(DISTINCT a.user_uid)'))
            ->from('pettycash_accounts', 'a');

        return (int) $qb->executeQuery()->fetchOne();
    }

    /**
     * A page of holders, grouped and ordered, with total and active account counts.
     *
     * One grouped, bounded read used by the no-search holder directory: `LIMIT/OFFSET` pagination is
     * the server's, and the caller joins this with `holderCount()` for the authoritative `total`.
     *
     * @return list<array{userId: string, accountCount: int, activeAccountCount: int}>
     */
    public function holderPage(int $offset, int $limit): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('a.user_uid')
            ->selectAlias($qb->func()->count('a.user_uid'), 'account_count')
            ->selectAlias($qb->createFunction('SUM(CASE WHEN a.active THEN 1 ELSE 0 END)'), 'active_account_count')
            ->from('pettycash_accounts', 'a')
            ->groupBy('a.user_uid')
            ->orderBy('a.user_uid', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        return $this->mapHolderRows($qb->executeQuery()->fetchAllAssociative());
    }

    /**
     * The complete grouped holder population, ordered by uid.
     *
     * Used only by the holder-search path, which must match against the *whole* account-owning holder
     * set before paginating (never the loaded page). Resolving display names for those holder uids is
     * the caller's job; this returns the account-owned population only.
     *
     * @return list<array{userId: string, accountCount: int, activeAccountCount: int}>
     */
    public function allHolders(): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('a.user_uid')
            ->selectAlias($qb->func()->count('a.user_uid'), 'account_count')
            ->selectAlias($qb->createFunction('SUM(CASE WHEN a.active THEN 1 ELSE 0 END)'), 'active_account_count')
            ->from('pettycash_accounts', 'a')
            ->groupBy('a.user_uid')
            ->orderBy('a.user_uid', 'ASC');

        return $this->mapHolderRows($qb->executeQuery()->fetchAllAssociative());
    }

    /**
     * Account-activity facts for a set of **surrogate account ids**, in one grouped read.
     *
     * Returns, per account database id, its movement count and the timestamp of its most recent
     * movement — the two numbers a holder-account page shows. Loading these per account would be N+1,
     * and loading whole movement histories to derive two values would be unbounded; one grouped read
     * answers the whole page.
     *
     * An account with no movements is **absent** from the map; the caller renders `lastMovementAt =
     * null` and a zero count for it. An empty input returns an empty map without issuing a query.
     *
     * @param list<int> $dbIds surrogate `pettycash_accounts.id` values
     * @return array<int, array{movementCount: int, lastMovementAt: ?DateTimeImmutable}> keyed by account db id
     */
    public function accountActivityFor(array $dbIds): array
    {
        $dbIds = array_values(array_unique(array_filter(
            $dbIds,
            static fn ($id): bool => $id !== null
        )));

        if ($dbIds === []) {
            return [];
        }

        $qb = $this->connection->getQueryBuilder();
        $qb->select('m.account_id')
            ->selectAlias($qb->func()->count('m.id'), 'movement_count')
            ->selectAlias($qb->createFunction('MAX(m.occurred_at)'), 'last_movement_at')
            ->from('pettycash_account_movements', 'm')
            ->where($qb->expr()->in('m.account_id', $qb->createNamedParameter($dbIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->groupBy('m.account_id');

        $activity = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $last = $row['last_movement_at'] ?? null;
            $activity[(int) $row['account_id']] = [
                'movementCount' => (int) $row['movement_count'],
                'lastMovementAt' => $last instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($last)
                    : (is_string($last) && trim($last) !== '' ? new DateTimeImmutable($last) : null),
            ];
        }

        return $activity;
    }

    /**
     * The Cost Sheet behind each consumption revision, in one batched resolution.
     *
     * `INNER JOIN`s are deliberate: an unresolvable transaction or sheet is a broken provenance chain,
     * and the caller must see it as an absent entry (a data fault) rather than as a half-resolved row
     * with a null title. An empty input returns an empty map without issuing a query.
     *
     * The public UUID is emitted as both the sheet identifier and its reference because the schema has
     * no separate business-reference column; inventing a `PC-YYYY-NNN` sequence would fabricate a fact
     * the authoritative record does not hold.
     *
     * @param list<string> $sourceRevisionPublicIds
     * @return array<string, array{sheetId: string, sheetTitle: string}> keyed by revision public UUID
     */
    public function ledgerContextFor(array $sourceRevisionPublicIds): array
    {
        $sourceRevisionPublicIds = array_values(array_unique(array_filter(
            $sourceRevisionPublicIds,
            static fn (?string $id): bool => $id !== null && trim($id) !== ''
        )));

        if ($sourceRevisionPublicIds === []) {
            return [];
        }

        $qb = $this->connection->getQueryBuilder();
        $qb->selectAlias('r.public_id', 'revision_public_id')
            ->selectAlias('s.public_id', 'sheet_public_id')
            ->selectAlias('s.title', 'sheet_title')
            ->from('pettycash_transaction_revisions', 'r')
            ->join(
                'r',
                'pettycash_transactions',
                't',
                $qb->expr()->eq('r.transaction_id', 't.id')
            )
            ->join(
                't',
                'pettycash_sheets',
                's',
                $qb->expr()->eq('t.sheet_id', 's.id')
            )
            ->where($qb->expr()->in(
                'r.public_id',
                $qb->createNamedParameter($sourceRevisionPublicIds, IQueryBuilder::PARAM_STR_ARRAY)
            ));

        $context = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $context[(string) $row['revision_public_id']] = [
                'sheetId' => (string) $row['sheet_public_id'],
                'sheetTitle' => (string) $row['sheet_title'],
            ];
        }

        return $context;
    }

    /**
     * Approved/total transaction counts per sheet, in one grouped read over the authoritative column.
     *
     * `final_outcome` is the current sheet fact (`APPROVED` / `REJECTED` / null); counting it here is
     * not a snapshot at posting time and adds no stored duplicate. The CASE expression is the
     * portable form the static portability check enforces — `SUM(boolean)` would be rejected by
     * PostgreSQL — and grouping by the selected sheet public id keeps `ONLY_FULL_GROUP_BY` satisfied.
     *
     * @param list<string> $sheetPublicIds
     * @return array<string, array{approvedCount: int, totalCount: int}> keyed by sheet public UUID
     */
    public function ledgerApprovedCountsFor(array $sheetPublicIds): array
    {
        $sheetPublicIds = array_values(array_unique(array_filter(
            $sheetPublicIds,
            static fn (?string $id): bool => $id !== null && trim($id) !== ''
        )));

        if ($sheetPublicIds === []) {
            return [];
        }

        $qb = $this->connection->getQueryBuilder();
        // Bound on **this** builder: a placeholder minted by another builder has no value here, and
        // the statement would fail at execution instead of at review.
        $approved = $qb->createNamedParameter('APPROVED');

        $qb->selectAlias('s.public_id', 'sheet_public_id')
            ->selectAlias($qb->func()->count('t.id'), 'total_count')
            ->selectAlias(
                $qb->createFunction('SUM(CASE WHEN t.final_outcome = ' . $approved . ' THEN 1 ELSE 0 END)'),
                'approved_count'
            )
            ->from('pettycash_transactions', 't')
            ->join(
                't',
                'pettycash_sheets',
                's',
                $qb->expr()->eq('t.sheet_id', 's.id')
            )
            ->where($qb->expr()->in(
                's.public_id',
                $qb->createNamedParameter($sheetPublicIds, IQueryBuilder::PARAM_STR_ARRAY)
            ))
            ->groupBy('s.public_id');

        $counts = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $counts[(string) $row['sheet_public_id']] = [
                'approvedCount' => (int) $row['approved_count'],
                'totalCount' => (int) $row['total_count'],
            ];
        }

        return $counts;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{userId: string, accountCount: int, activeAccountCount: int}>
     */
    private function mapHolderRows(array $rows): array
    {
        return array_map(static function (array $row): array {
            return [
                'userId' => (string) $row['user_uid'],
                'accountCount' => (int) $row['account_count'],
                'activeAccountCount' => (int) $row['active_account_count'],
            ];
        }, $rows);
    }
}
