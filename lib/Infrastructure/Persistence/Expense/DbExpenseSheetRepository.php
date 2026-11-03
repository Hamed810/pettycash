<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Persistence\Expense;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\DataIntegrity\UnreadableRecordData;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Application\StaleRecordVersion;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use RuntimeException;
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

    /**
     * Every sheet on the instance, across every status.
     *
     * No `WHERE` on purpose: the whole point of this count is that it is not the open-sheet read
     * wearing a total's name (T379). `COUNT(*)` arrives as a `BIGINT`, which some drivers hand back
     * as a string, so it is cast rather than trusted -- the same rule `countByOwnerUid()` states.
     */
    public function countAll(): int
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select($qb->func()->count('id', 'pettycash_sheet_count'))
            ->from('pettycash_sheets');

        return (int) $qb->executeQuery()->fetchOne();
    }

    public function save(ExpenseSheet $sheet): void
    {
        $qb = $this->connection->getQueryBuilder();
        $existing = $sheet->dbId ? $this->findById($sheet->dbId) : $this->findByPublicId($sheet->id);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $currentUser = $this->currentUser->uid();

        // Resolve the account before either branch builds a write. This used to happen inside the
        // `set()`/`values()` call and returned `0` when the public id matched no row, so an unknown
        // account was written as `account_id = 0`: a new dangling reference, created by the very
        // method that is supposed to persist a valid sheet. Resolving once, ahead of the statement,
        // also means the refusal happens before any SQL is sent, not after a partial write.
        $accountDbId = $this->getAccountDbId($sheet->accountId, $sheet->id, $sheet->dbId);

        if ($existing) {
            $qb->update('pettycash_sheets')
                ->set('title', $qb->createNamedParameter($sheet->title ?? ''))
                ->set('owner_uid', $qb->createNamedParameter($sheet->ownerUid))
                ->set('account_id', $qb->createNamedParameter($accountDbId))
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
                    'account_id' => $qb->createNamedParameter($accountDbId),
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

    /**
     * The surrogate id of a public account id, or a loud refusal before a write.
     *
     * ## Why there is no `0` fallback
     *
     * This returned `0` when the lookup matched no row, and every `save()` then wrote that value into
     * `pettycash_sheets.account_id`. A sheet so written is not a sheet with an unknown account: it is
     * a sheet whose account reference points at a row that cannot exist, and the read path is right to
     * refuse it. Creating that fault while persisting a financial record is exactly the silent repair
     * the account policy forbids, so an unresolved public id now raises the account-reference variant
     * of `UnreadableRecordData` before the statement is built.
     *
     * The message names the sheet's public id and both surrogate ids so an operator can find the row
     * that was about to be written. It never reaches a caller: `OcsErrorMapper` logs it in full and
     * substitutes its canonical data-fault text.
     *
     * @throws UnreadableRecordData
     */
    private function getAccountDbId(string $publicId, string $sheetPublicId, ?int $sheetDbId): int
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('id')
            ->from('pettycash_accounts')
            ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();

        if (!$row) {
            throw UnreadableRecordData::accountReference(sprintf(
                'Cannot resolve the account of sheet %s (sheet id %s): the public account id %s '
                . 'matches no row in pettycash_accounts. Refusing to write account_id = 0.',
                $sheetPublicId,
                $sheetDbId === null ? 'not yet persisted' : (string) $sheetDbId,
                $publicId
            ));
        }

        return (int) $row['id'];
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
        $sheetPublicId = (string) $row['public_id'];
        $sheetDbId = (int) $row['id'];
        $sheetCurrencyId = (int) $row['currency_id'];

        // The sheet's currency resolves **first**, and the order is load-bearing.
        //
        // A missing currency row and an account whose currency disagrees with the sheet are two
        // different faults that must stay distinguishable. If the account were resolved first, a
        // sheet with a dangling `currency_id` would look exactly like an account/currency mismatch --
        // the account's real currency would differ from the dangling value -- and a currency fault
        // would be reported as an account fault. Resolving the sheet's own currency first establishes
        // that the authority exists; only then can an account disagreement be an account fault.
        $currencyCode = \OCA\PettyCash\Domain\Shared\CurrencyCode::fromString(
            $this->getCurrencyCode($sheetCurrencyId, $sheetPublicId, $sheetDbId)
        );
        $accountPublicId = $this->getAccountReference(
            (int) $row['account_id'],
            $sheetCurrencyId,
            $sheetPublicId,
            $sheetDbId
        );

        $sheet = ExpenseSheet::open(
            $row['public_id'],
            $row['owner_uid'],
            $accountPublicId,
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
     * fault, so this is now one rule and not two. It extends `LogicException`, and that is deliberate:
     * it is not the caller's fault, so it must not be answered as a validation failure. `OcsErrorMapper`
     * classifies it once, ahead of the `InvalidArgumentException` arm, and every endpoint on the path
     * then answers 422 with the same explanation — sheets, sheet detail, dashboard and the reports.
     * That single answer is FR-083's requirement ("a client writes one handler rather than one per
     * endpoint") and it was not available while the translation lived in `ReportController` alone.
     *
     * The message names both surrogate ids because that is what an operator needs to find the row, and
     * it is never sent to a caller: `OcsErrorMapper` logs this one in full and substitutes its own
     * canonical text for the body.
     *
     * @throws UnreadableRecordData
     */
    private function getCurrencyCode(int $currencyId, string $sheetPublicId, int $sheetDbId): string
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('code')
            ->from('pettycash_currencies')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($currencyId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();

        if (!$row) {
            throw new UnreadableRecordData(sprintf(
                'Cannot resolve the currency of sheet %s (sheet id %d): currency_id %d matches no '
                . 'row in pettycash_currencies. The amount_minor values on this sheet cannot be read, '
                . 'because an amount is meaningless without its currency.',
                $sheetPublicId,
                $sheetDbId,
                $currencyId
            ));
        }

        return (string) $row['code'];
    }

    /**
     * The public id of the account a sheet stores, or a loud refusal.
     *
     * ## Why the currency is read here too
     *
     * `ExpenseSheet` can only be built from a public account id and a currency. The old method returned
     * `''` when the stored `account_id` matched no row, which produced an aggregate with an empty
     * account id: a sheet that looks readable, serialises an empty reference, and cannot be used for
     * anything that later needs the account. Returning the public id alone would leave the second,
     * quieter fault in place as well -- an account denominated in a different currency than the sheet,
     * which is not a sheet the application can legitimately produce and must not be presented as one.
     *
     * ## Why this is the account-reference variant
     *
     * Both faults here are about the sheet's account reference rather than the sheet's own currency,
     * and the caller can tell them apart from the exception structurally through
     * {@see UnreadableRecordData::isAccountReference()}. A missing sheet-currency row is raised by
     * {@see self::getCurrencyCode()} before this method runs, so it keeps the general variant and is
     * never reclassified as an account error.
     *
     * An inactive account is **not** a fault. The lookup deliberately does not filter on `active`:
     * history has to remain readable after an account is deactivated, and the account's currency is
     * still a fact.
     *
     * @throws UnreadableRecordData
     */
    private function getAccountReference(
        int $accountId,
        int $sheetCurrencyId,
        string $sheetPublicId,
        int $sheetDbId
    ): string {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('public_id')
            ->addSelect('currency_id')
            ->from('pettycash_accounts')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($accountId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();

        if (!$row) {
            throw UnreadableRecordData::accountReference(sprintf(
                'Cannot resolve the account of sheet %s (sheet id %d): account_id %d matches no row '
                . 'in pettycash_accounts. The sheet cannot be presented without inventing an account.',
                $sheetPublicId,
                $sheetDbId,
                $accountId
            ));
        }

        $accountCurrencyId = (int) $row['currency_id'];
        if ($accountCurrencyId !== $sheetCurrencyId) {
            throw UnreadableRecordData::accountReference(sprintf(
                'Cannot read sheet %s (sheet id %d): its account %s (account id %d) is denominated in '
                . 'currency_id %d, but the sheet stores currency_id %d. The amounts cannot be '
                . 'reinterpreted in another currency.',
                $sheetPublicId,
                $sheetDbId,
                (string) $row['public_id'],
                $accountId,
                $accountCurrencyId,
                $sheetCurrencyId
            ));
        }

        return (string) $row['public_id'];
    }

    /**
     * T417 / FR-086: remove the sheet row, and nothing else.
     *
     * The cascade to transactions, revisions and evidence is **not** here on purpose.
     * `DbExpenseTransactionRepository::delete()` already removes a transaction's revisions and evidence
     * rows, and the AppData binaries are removed by the application layer while their keys are still
     * readable — neither of which this class could do, since it has no file store. Duplicating that
     * here would give two places responsible for a child's removal and one place unable to finish it.
     *
     * Guarded on `lock_version` for the same reason `save()` is: FR-086 refuses a stale delete the way
     * FR-025 refuses a stale edit, and this is the last moment that can be enforced for a row that is
     * about to stop existing. A delete that matched nothing because the version moved is reported by
     * zero affected rows rather than silently succeeding.
     */
    public function delete(ExpenseSheet $sheet): void
    {
        if ($sheet->dbId === null) {
            throw new RuntimeException('Cannot delete a sheet that was never persisted');
        }

        $qb = $this->connection->getQueryBuilder();
        $affected = $qb->delete('pettycash_sheets')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($sheet->dbId)))
            ->andWhere($qb->expr()->eq('lock_version', $qb->createNamedParameter($sheet->lockVersion)))
            ->executeStatement();

        if ($affected === 0) {
            // Zero rows means the `lock_version` predicate did not match, i.e. the row moved after the
            // caller read it. Both versions are not available here, so `movedDuringWrite()` says what
            // this layer actually knows rather than quoting a number it would have to invent.
            throw StaleRecordVersion::movedDuringWrite('sheet', $sheet->id);
        }
    }
}