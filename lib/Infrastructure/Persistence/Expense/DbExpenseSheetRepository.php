<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Persistence\Expense;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\DataIntegrity\UnreadableRecordData;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\SheetListFilter;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class DbExpenseSheetRepository implements ExpenseSheetRepository
{
    public function __construct(
        private readonly IDBConnection $connection,
        private readonly UserDirectory $userDirectory,
        private readonly CurrentUser $currentUser
    ) {}

    public function findById(int $id): ?ExpenseSheet
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_sheets')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findByPublicId(string $publicId): ?ExpenseSheet
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_sheets')
            ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    /** @return list<ExpenseSheet> */
    public function findByOwnerUid(string $ownerUid, ?SheetListFilter $filter = null, int $offset = 0, int $limit = 100): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('s.*')
            ->from('pettycash_sheets', 's');
        $this->applyListScope($qb, $ownerUid, $filter);
        $qb->orderBy('s.created_at', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /**
     * T332: the owner's sheet count, from a `COUNT` rather than from the length of a page.
     *
     * T333 widened the predicate rather than the number: the count answers the *filtered* question, so
     * a caller asking for the open sheets is told how many open sheets there are and not how many sheets
     * they own. Filter-then-count is the only order that makes `total` describe the rows printed next to
     * it, and it is why both methods build their predicate from {@see self::applyListScope()} -- the same
     * method -- so a count that is subtly wider or narrower than its read is not expressible.
     *
     * `fetchOne()` returns the aggregate as the driver gives it -- `COUNT(*)` is `BIGINT`, which arrives
     * as a string on some drivers and an int on others -- so it is cast rather than trusted.
     */
    public function countByOwnerUid(string $ownerUid, ?SheetListFilter $filter = null): int
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select($qb->func()->count('s.id', 'pettycash_sheet_count'))
            ->from('pettycash_sheets', 's');
        $this->applyListScope($qb, $ownerUid, $filter);

        return (int) $qb->executeQuery()->fetchOne();
    }

    /**
     * The owner scope and the FR-064 facets both `findByOwnerUid()` and `countByOwnerUid()` filter by.
     *
     * Extracted so the two cannot drift; there is deliberately no second copy of the `status`,
     * date-range or currency comparison anywhere in this class.
     *
     * ## Why the currency facet is a join and not a resolved surrogate key
     *
     * `T329` resolved a public id to a surrogate key in the application layer, because the key was
     * already named on the request and resolving it needed a lookup anyway. A currency *code* is a value,
     * not an identifier, and resolving it here would mean a second query inside a query builder -- which
     * is why the sheet list joins `pettycash_currencies` and compares `c.code`. The join is added only
     * when the facet is set, so the unfiltered read keeps the single-table plan the index serves.
     *
     * The alias is `s`, not `*`, because a joined `SELECT *` is ambiguous on every backend and the query
     * builder's own `selectAlias()` cannot disambiguate a bare star.
     */
    private function applyListScope(IQueryBuilder $qb, string $ownerUid, ?SheetListFilter $filter): void
    {
        $qb->where($qb->expr()->eq('s.owner_uid', $qb->createNamedParameter($ownerUid)));

        if ($filter === null) {
            return;
        }

        if ($filter->status !== null) {
            $qb->andWhere($qb->expr()->eq('s.status', $qb->createNamedParameter($filter->status->value)));
        }

        if ($filter->currency !== null) {
            $qb->join('s', 'pettycash_currencies', 'c', $qb->expr()->eq('s.currency_id', 'c.id'))
                ->andWhere($qb->expr()->eq('c.code', $qb->createNamedParameter((string) $filter->currency)));
        }

        if ($filter->from !== null) {
            $qb->andWhere($qb->expr()->gte(
                's.created_at',
                $qb->createNamedParameter($filter->from, IQueryBuilder::PARAM_DATETIME_IMMUTABLE)
            ));
        }

        if ($filter->to !== null) {
            $qb->andWhere($qb->expr()->lte(
                's.created_at',
                $qb->createNamedParameter($filter->to, IQueryBuilder::PARAM_DATETIME_IMMUTABLE)
            ));
        }
    }

    /** @return list<ExpenseSheet> */
    public function findByStatus(SheetStatus $status, int $offset = 0, int $limit = 100): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_sheets')
            ->where($qb->expr()->eq('status', $qb->createNamedParameter($status->value)))
            ->orderBy('created_at', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    public function save(ExpenseSheet $sheet): void
    {
        $qb = $this->connection->getQueryBuilder();
        $existing = $sheet->dbId ? $this->findById($sheet->dbId) : $this->findByPublicId($sheet->id);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $currentUser = $this->currentUser->uid();

        if ($existing) {
            $qb->update('pettycash_sheets')
                ->set('title', $qb->createNamedParameter($sheet->title ?? ''))
                ->set('owner_uid', $qb->createNamedParameter($sheet->ownerUid))
                ->set('account_id', $qb->createNamedParameter($this->getAccountDbId($sheet->accountId)))
                ->set('currency_id', $qb->createNamedParameter($this->getCurrencyDbId($sheet->currency)))
                ->set('status', $qb->createNamedParameter($sheet->status->value))
                ->set('submitted_total_minor', $sheet->submittedTotalMinor ? $qb->createNamedParameter($sheet->submittedTotalMinor) : $qb->createNamedParameter(null))
                ->set('first_submitted_at', $sheet->firstSubmittedAt ? $qb->createNamedParameter($sheet->firstSubmittedAt->format('Y-m-d H:i:s')) : $qb->createNamedParameter(null))
                ->set('management_completed_at', $sheet->managementCompletedAt ? $qb->createNamedParameter($sheet->managementCompletedAt->format('Y-m-d H:i:s')) : $qb->createNamedParameter(null))
                ->set('finance_started_at', $sheet->financeStartedAt ? $qb->createNamedParameter($sheet->financeStartedAt->format('Y-m-d H:i:s')) : $qb->createNamedParameter(null))
                ->set('completed_at', $sheet->completedAt ? $qb->createNamedParameter($sheet->completedAt->format('Y-m-d H:i:s')) : $qb->createNamedParameter(null))
                ->set('lock_version', $qb->createNamedParameter($existing->lockVersion + 1))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($existing->dbId)))
                ->andWhere($qb->expr()->eq('lock_version', $qb->createNamedParameter($existing->lockVersion)))
                ->executeStatement();
            $sheet->lockVersion = $existing->lockVersion + 1;
        } else {
            $qb->insert('pettycash_sheets')
                ->values([
                    'public_id' => $qb->createNamedParameter($sheet->id),
                    'title' => $qb->createNamedParameter($sheet->title ?? ''),
                    'owner_uid' => $qb->createNamedParameter($sheet->ownerUid),
                    'account_id' => $qb->createNamedParameter($this->getAccountDbId($sheet->accountId)),
                    'currency_id' => $qb->createNamedParameter($this->getCurrencyDbId($sheet->currency)),
                    'status' => $qb->createNamedParameter($sheet->status->value),
                    'submitted_total_minor' => $sheet->submittedTotalMinor ? $qb->createNamedParameter($sheet->submittedTotalMinor) : $qb->createNamedParameter(null),
                    'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE),
                    'first_submitted_at' => $sheet->firstSubmittedAt ? $qb->createNamedParameter($sheet->firstSubmittedAt->format('Y-m-d H:i:s')) : $qb->createNamedParameter(null),
                    'management_completed_at' => $qb->createNamedParameter(null),
                    'finance_started_at' => $qb->createNamedParameter(null),
                    'completed_at' => $qb->createNamedParameter(null),
                    'lock_version' => $qb->createNamedParameter(0),
                ])
                ->executeStatement();
            
            $sheet->dbId = (int) $qb->getLastInsertId();
            // The insert writes `public_id` from the aggregate's own `id`, but never assigned it
            // back to the `publicId` mirror, so an aggregate that had just been saved reported
            // `publicId === null` and every `findByPublicId()` on it could not resolve the row it
            // had written. `mapRow()` sets the mirror on the way back in; this sets it on the way
            // out. Found by T236's real-database round trip.
            $sheet->publicId = $sheet->id;
            $sheet->lockVersion = 0;
        }
    }

    private function getAccountDbId(string $publicId): int
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('id')
            ->from('pettycash_accounts')
            ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? (int) $row['id'] : 0;
    }

    private function getCurrencyDbId(\OCA\PettyCash\Domain\Shared\CurrencyCode $currencyCode): int
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('id')
            ->from('pettycash_currencies')
            ->where($qb->expr()->eq('code', $qb->createNamedParameter((string) $currencyCode)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? (int) $row['id'] : 0;
    }

    private function mapRow(array $row): ExpenseSheet
    {
        // The sheet's public id is passed to the currency lookup so a failure can name the record an
        // operator has to go and look at. The surrogate `currency_id` alone would not be enough: it is
        // the sheet that is broken, and there may be many rows pointing at the same missing currency.
        $currencyCode = \OCA\PettyCash\Domain\Shared\CurrencyCode::fromString(
            $this->getCurrencyCode((int) $row['currency_id'], (string) $row['public_id'])
        );
        $sheet = ExpenseSheet::open(
            $row['public_id'],
            $row['owner_uid'],
            $this->getAccountPublicId($row['account_id']),
            $currencyCode
        );
        $sheet->title = $row['title'] ?? '';
        $sheet->status = SheetStatus::from($row['status']);
        $sheet->submittedTotalMinor = $row['submitted_total_minor'] ? (int) $row['submitted_total_minor'] : null;
        $sheet->firstSubmittedAt = $row['first_submitted_at'] ? new DateTimeImmutable($row['first_submitted_at']) : null;
        $sheet->managementCompletedAt = $row['management_completed_at'] ? new DateTimeImmutable($row['management_completed_at']) : null;
        $sheet->financeStartedAt = $row['finance_started_at'] ? new DateTimeImmutable($row['finance_started_at']) : null;
        $sheet->completedAt = $row['completed_at'] ? new DateTimeImmutable($row['completed_at']) : null;
        $sheet->createdAt = $row['created_at'] ? new DateTimeImmutable($row['created_at']) : null;
        $sheet->lockVersion = (int) ($row['lock_version'] ?? 0);
        $sheet->dbId = (int) $row['id'];
        $sheet->publicId = $row['public_id'];
        return $sheet;
    }

    /**
     * The code of a currency the sheet points at, or a loud failure.
     *
     * ## Why there is no fallback
     *
     * This returned the literal `'IRR'` when the lookup matched no row. That is the worst defect in
     * this repository, and it is worth being precise about why rather than simply calling it a
     * fallback. `amount_minor` is only meaningful beside its currency: `100000` is 1,000,000 rials or
     * 1,000 dollars, and the two differ by four orders of magnitude. Substituting a currency therefore
     * does not make an unknown value harmless — it makes it a *specific wrong number*, on a sheet
     * whose current total, submitted total, effective-outcome column and every figure derived from
     * them are all now in the wrong unit. And it does so while answering `200`, so nothing in the
     * response distinguishes it from a sheet that really is denominated in rials.
     *
     * FR-021 makes the sheet the authority for its currency precisely because a transaction inherits
     * it; if the authority cannot be read there is no number to report, and the honest answer is to
     * say so.
     *
     * ## Why this exception type
     *
     * `UnreadableRecordData` is what `DbTransactionRevisionRepository` already throws for the same
     * fault, so this is now one rule and not two. It extends `LogicException`, which is deliberate:
     * `ReportController` translates it into a 422 that explains itself and withholds the message,
     * while every other caller keeps the classification `OcsErrorMapper` already gives an
     * unrecognised fault — 500, message withheld, full detail logged. Loud everywhere, and explained
     * where an explanation is available. The alternative to either is the answer that was here.
     *
     * The message names both surrogate ids because that is what an operator needs to find the row,
     * and it is never sent to a caller: `ReportController` builds a different string, and
     * `OcsErrorMapper` withholds this one.
     *
     * @throws UnreadableRecordData
     */
    private function getCurrencyCode(int $currencyId, string $sheetPublicId): string
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('code')
            ->from('pettycash_currencies')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($currencyId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();

        if (!$row) {
            throw new UnreadableRecordData(sprintf(
                'Cannot resolve the currency of sheet %s: currency_id %d matches no row in '
                . 'pettycash_currencies. The amount_minor values on this sheet cannot be read, because '
                . 'an amount is meaningless without its currency.',
                $sheetPublicId,
                $currencyId
            ));
        }

        return (string) $row['code'];
    }

    private function getAccountPublicId(int $accountId): string
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('public_id')
            ->from('pettycash_accounts')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($accountId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $row['public_id'] : '';
    }
}