<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Persistence\Expense;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use OCA\PettyCash\Application\DataIntegrity\UnreadableRecordData;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class DbExpenseTransactionRepository implements ExpenseTransactionRepository
{
    public function __construct(
        private readonly IDBConnection $connection,
        private readonly UserDirectory $userDirectory,
        private readonly CurrentUser $currentUser,
        private readonly DbTransactionRevisionRepository $revisionRepository
    ) {}

    public function findById(int $id): ?ExpenseTransaction
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_transactions')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findByPublicId(string $publicId): ?ExpenseTransaction
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_transactions')
            ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    /** @return list<ExpenseTransaction> */
    public function findBySheetId(int $sheetId): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_transactions')
            ->where($qb->expr()->eq('sheet_id', $qb->createNamedParameter($sheetId)))
            // `id` is the tiebreaker, not decoration: `created_at` has second precision, so two
            // transactions drafted in the same second came back in whatever order the storage engine
            // produced. A purchaser's transaction table that reorders itself between two reloads of
            // the same unchanged sheet reads as an edit nobody made.
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC');
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    public function countBySheetId(int $sheetId): int
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select($qb->func()->count('id', 'transaction_count'))
            ->from('pettycash_transactions')
            ->where($qb->expr()->eq('sheet_id', $qb->createNamedParameter($sheetId)));

        $row = $qb->executeQuery()->fetchAssociative();
        return $row === false ? 0 : (int) ($row['transaction_count'] ?? 0);
    }

    /** @return list<ExpenseTransaction> */
    public function findByOwnerUid(string $ownerUid, ?WorkflowStage $stage = null, ?TransactionStatus $status = null, int $offset = 0, int $limit = 100): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_transactions')
            ->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)));
        if ($stage) {
            $qb->andWhere($qb->expr()->eq('current_stage', $qb->createNamedParameter($stage->value)));
        }
        if ($status) {
            $qb->andWhere($qb->expr()->eq('current_status', $qb->createNamedParameter($status->value)));
        }
        $qb->orderBy('created_at', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /** @return list<ExpenseTransaction> */
    public function findByCostCenterId(int $costCenterId, ?WorkflowStage $stage = null, ?TransactionStatus $status = null, int $offset = 0, int $limit = 100): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('t.*')
            ->from('pettycash_transactions', 't')
            ->join('t', 'pettycash_transaction_revisions', 'r', $qb->expr()->eq('t.active_revision_id', 'r.id'))
            ->where($qb->expr()->eq('r.cost_center_id', $qb->createNamedParameter($costCenterId)));
        if ($stage) {
            $qb->andWhere($qb->expr()->eq('t.current_stage', $qb->createNamedParameter($stage->value)));
        }
        if ($status) {
            $qb->andWhere($qb->expr()->eq('t.current_status', $qb->createNamedParameter($status->value)));
        }
        $qb->orderBy('t.created_at', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /** @return list<ExpenseTransaction> */
    public function findByCostCenterIds(array $costCenterIds, ?WorkflowStage $stage = null, ?TransactionStatus $status = null): array
    {
        $costCenterIds = array_values(array_unique(array_map('intval', $costCenterIds)));
        if ($costCenterIds === []) {
            return [];
        }

        $qb = $this->connection->getQueryBuilder();
        $qb->select('t.*')
            ->from('pettycash_transactions', 't')
            ->join('t', 'pettycash_transaction_revisions', 'r', $qb->expr()->eq('t.active_revision_id', 'r.id'))
            ->where($qb->expr()->in(
                'r.cost_center_id',
                $qb->createNamedParameter($costCenterIds, IQueryBuilder::PARAM_INT_ARRAY)
            ));
        if ($stage) {
            $qb->andWhere($qb->expr()->eq('t.current_stage', $qb->createNamedParameter($stage->value)));
        }
        if ($status) {
            $qb->andWhere($qb->expr()->eq('t.current_status', $qb->createNamedParameter($status->value)));
        }
        // A page boundary needs a total order. `created_at` has second precision, so the surrogate
        // key is the tiebreaker that keeps page N and page N+1 disjoint.
        $qb->orderBy('t.created_at', 'DESC')
            ->addOrderBy('t.id', 'DESC');

        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /**
     * Compact per-sheet projection for the Senior Manager Cost Sheet list.
     *
     * Replaces for the list path the previous `findByCostCenterIds()` bulk load (every historical
     * scoped transaction, hydrated as a full aggregate) plus a `findBySheetId()` read per sheet and
     * a `findByTransactionId()` reads per transaction. That path's per-row counts and the sheet-wide
     * gate are now computed entirely in the database, and the rows returned are one compact scalar
     * row **per candidate sheet** — bounded by the number of sheets in scope, while historical
     * transaction volume only ever widens the in-database GROUP BY, never PHP memory.
     *
     * Two grouped queries, both index-served (`r.cost_center_id` through the revision join, and
     * `t.sheet_id`/`t.active_revision_id` through the existing transaction indexes):
     *
     * 1. **Scoped counts**: one row per sheet holding at least one transaction whose active revision
     *    is in a managed cost centre; awaiting/decided-by-this-senior counts, the returned-by-this-
     *    senior flag, and the activity maxima, joined to the decision table only on the active
     *    revision (`ds.stage = 'SENIOR'`) — the `(revision_id, stage)` unique index admits at most
     *    one such decision per transaction. Sheet instants come from the same query so `latestActivityAt`
     *    can be folded before sorting with no further reads.
     * 2. **Whole-sheet gate**: over **every** transaction of the candidate sheets (including those in
     *    cost centres outside this senior's scope, exactly like the previous per-sheet
     *    `findBySheetId()` read), the number that fail the Cost Center completion rule — no final CC
     *    decision, or a pending Return, or an awaiting-purchaser-correction state.
     *
     * `SUM(CASE WHEN …)` / `MAX(CASE WHEN …)` are used because conditional aggregation is the one
     * portable way to count subsets of a group on MySQL/MariaDB, PostgreSQL and SQLite; they are
     * standard SQL in the declared-backend catalogue. The values compared are the same enum backing
     * strings the domain persists, and the only user-derived value (the actor uid) is a bound
     * parameter.
     *
     * @param string $actorUid the acting senior manager's uid
     * @param list<int> $costCenterIds the managed cost-centre ids (active-revision membership)
     * @return array<int, array{
     *   awaitingSenior: int,
     *   decidedBySenior: int,
     *   returnedByThisSenior: bool,
     *   ccGateUnresolved: int,
     *   activityCreated: ?DateTimeImmutable,
     *   activityUpdated: ?DateTimeImmutable,
     *   activityDecided: ?DateTimeImmutable,
     *   sheetCreated: ?DateTimeImmutable,
     *   sheetFirstSubmitted: ?DateTimeImmutable,
     *   sheetCompleted: ?DateTimeImmutable,
     * }> keyed by the sheet's database id
     */
    public function seniorSheetListRows(string $actorUid, array $costCenterIds): array
    {
        $costCenterIds = array_values(array_unique(array_map('intval', $costCenterIds)));
        if ($costCenterIds === []) {
            return [];
        }

        $seniorStage = WorkflowStage::Senior->value;
        $returnValue = Decision::Return->value;

        // Candidate rows: one compact row per sheet with at least one scoped transaction.
        $qb = $this->connection->getQueryBuilder();
        $actor = (string) $qb->createNamedParameter($actorUid);
        $seniorParam = (string) $qb->createNamedParameter($seniorStage);
        $returnParam = (string) $qb->createNamedParameter($returnValue);

        // A scoped transaction contributes to the lists' activity only when it is what makes the
        // sheet eligible: awaiting any senior at the Senior stage, or carrying a current-revision
        // decision by *this* actor. Another senior's decision is neither counted nor disclosed.
        $included = '(t.current_stage = ' . $seniorParam . ' AND ds.id IS NULL)'
            . ' OR (ds.id IS NOT NULL AND ds.actor_uid = ' . $actor . ')';

        $qb->select('t.sheet_id')
            ->selectAlias($qb->createFunction('SUM(CASE WHEN t.current_stage = ' . $seniorParam . ' AND ds.id IS NULL THEN 1 ELSE 0 END)'), 'awaiting_senior')
            ->selectAlias($qb->createFunction('SUM(CASE WHEN ds.id IS NOT NULL AND ds.actor_uid = ' . $actor . ' THEN 1 ELSE 0 END)'), 'decided_by_senior')
            ->selectAlias($qb->createFunction('SUM(CASE WHEN ds.id IS NOT NULL AND ds.actor_uid = ' . $actor . ' AND ds.decision = ' . $returnParam . ' THEN 1 ELSE 0 END)'), 'returned_by_senior')
            ->selectAlias($qb->createFunction('MAX(CASE WHEN ' . $included . ' THEN t.created_at END)'), 'activity_created')
            ->selectAlias($qb->createFunction('MAX(CASE WHEN ' . $included . ' THEN t.updated_at END)'), 'activity_updated')
            ->selectAlias($qb->createFunction('MAX(CASE WHEN ' . $included . ' THEN ds.decided_at END)'), 'activity_decided')
            ->selectAlias($qb->createFunction('MAX(s.created_at)'), 'sheet_created')
            ->selectAlias($qb->createFunction('MAX(s.first_submitted_at)'), 'sheet_first_submitted')
            ->selectAlias($qb->createFunction('MAX(s.completed_at)'), 'sheet_completed')
            ->from('pettycash_transactions', 't')
            ->join('t', 'pettycash_transaction_revisions', 'r', $qb->expr()->eq('r.id', 't.active_revision_id'))
            ->join('t', 'pettycash_sheets', 's', $qb->expr()->eq('s.id', 't.sheet_id'))
            ->leftJoin('t', 'pettycash_workflow_decisions', 'ds', $qb->expr()->andX(
                $qb->expr()->eq('ds.revision_id', 'r.id'),
                $qb->expr()->eq('ds.stage', $qb->createNamedParameter($seniorStage))
            ))
            ->where($qb->expr()->in(
                'r.cost_center_id',
                $qb->createNamedParameter($costCenterIds, IQueryBuilder::PARAM_INT_ARRAY)
            ))
            ->groupBy('t.sheet_id');

        $rows = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $sheetDbId = (int) $row['sheet_id'];
            $rows[$sheetDbId] = [
                'awaitingSenior' => (int) ($row['awaiting_senior'] ?? 0),
                'decidedBySenior' => (int) ($row['decided_by_senior'] ?? 0),
                'returnedByThisSenior' => ((int) ($row['returned_by_senior'] ?? 0)) > 0,
                // Filled from the gate query below; defaults to "decided" only if the sheet somehow
                // misses that query, which the candidate join makes impossible.
                'ccGateUnresolved' => 0,
                'activityCreated' => $this->toDateTime($row['activity_created'] ?? null),
                'activityUpdated' => $this->toDateTime($row['activity_updated'] ?? null),
                'activityDecided' => $this->toDateTime($row['activity_decided'] ?? null),
                'sheetCreated' => $this->toDateTime($row['sheet_created'] ?? null),
                'sheetFirstSubmitted' => $this->toDateTime($row['sheet_first_submitted'] ?? null),
                'sheetCompleted' => $this->toDateTime($row['sheet_completed'] ?? null),
            ];
        }

        if ($rows === []) {
            return [];
        }

        // Whole-sheet gate: every transaction of the candidate sheets, scoped or not, matching the
        // previous `findBySheetId()` + `hasFinalCostCenterDecision()` verdict exactly.
        $gateQb = $this->connection->getQueryBuilder();
        $gateReturnParam = (string) $gateQb->createNamedParameter($returnValue);
        $correctionParam = (string) $gateQb->createNamedParameter(WorkflowStage::Correction->value);
        $awaitingCorrectionParam = (string) $gateQb->createNamedParameter(TransactionStatus::AwaitingPurchaserCorrection->value);

        $gateQb->select('t.sheet_id')
            ->selectAlias(
                $gateQb->createFunction('SUM(CASE WHEN dc.id IS NULL OR dc.decision = ' . $gateReturnParam
                    . ' OR (t.current_stage = ' . $correctionParam . ' AND t.current_status = ' . $awaitingCorrectionParam . ')'
                    . ' THEN 1 ELSE 0 END)'),
                'cc_gate_unresolved'
            )
            ->from('pettycash_transactions', 't')
            ->join('t', 'pettycash_transaction_revisions', 'r', $gateQb->expr()->eq('r.id', 't.active_revision_id'))
            ->leftJoin('t', 'pettycash_workflow_decisions', 'dc', $gateQb->expr()->andX(
                $gateQb->expr()->eq('dc.revision_id', 'r.id'),
                $gateQb->expr()->eq('dc.stage', $gateQb->createNamedParameter(WorkflowStage::CostCenter->value))
            ))
            ->where($gateQb->expr()->in(
                't.sheet_id',
                $gateQb->createNamedParameter(array_keys($rows), IQueryBuilder::PARAM_INT_ARRAY)
            ))
            ->groupBy('t.sheet_id');

        foreach ($gateQb->executeQuery()->fetchAllAssociative() as $row) {
            $sheetDbId = (int) $row['sheet_id'];
            if (isset($rows[$sheetDbId])) {
                $rows[$sheetDbId]['ccGateUnresolved'] = (int) ($row['cc_gate_unresolved'] ?? 0);
            }
        }

        return $rows;
    }

    /**
     * A stored datetime column as a `DateTimeImmutable`, exactly as `mapRow()` reads them.
     */
    private function toDateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        return new DateTimeImmutable($value);
    }

    /** @return list<ExpenseTransaction> */
    public function findByStageAndStatus(WorkflowStage $stage, ?TransactionStatus $status = null, int $offset = 0, int $limit = 100): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_transactions')
            ->where($qb->expr()->eq('current_stage', $qb->createNamedParameter($stage->value)));
        if ($status) {
            $qb->andWhere($qb->expr()->eq('current_status', $qb->createNamedParameter($status->value)));
        }
        $qb->orderBy('created_at', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /** @return list<ExpenseTransaction> */
    public function findForFinanceQueue(int $offset = 0, int $limit = 100): array
    {
        // Both senior outcomes, not just one.
        //
        // `contracts/workflow.md` gives finance two rows — "Finance after senior approval"
        // (approve, reject, return) and "Finance after senior rejection" (reject, return;
        // approval forbidden) — and FR-037 routes *approved or rejected* transactions to
        // finance. Selecting only `AWAITING_FINANCE_REVIEW` therefore hid senior-rejected
        // transactions from the queue, so FR-039's "finance may reject it or return it" had
        // nothing to act on: the only finance-reachable state was one no code path produces,
        // because a senior approval records `SENIOR_APPROVED`.
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_transactions')
            ->where($qb->expr()->eq('current_stage', $qb->createNamedParameter(WorkflowStage::Finance->value)))
            ->andWhere($qb->expr()->in(
                'current_status',
                $qb->createNamedParameter([
                    TransactionStatus::SeniorApproved->value,
                    TransactionStatus::SeniorRejected->value,
                ], IQueryBuilder::PARAM_STR_ARRAY)
            ))
            ->orderBy('created_at', 'ASC')
            // Deterministic tiebreak: rows seeded in the same second share a
            // created_at, and without it page N and page N+1 overlap.
            ->addOrderBy('id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /**
     * Whether a row already exists for this aggregate, and its current lock version.
     *
     * This deliberately does not hydrate the aggregate: during creation the
     * transaction row is written first with a null `active_revision_id` and is only
     * linked to its revision by the follow-up save, so loading the full aggregate
     * would demand an active revision that does not exist yet. The update branch
     * needs nothing but the lock version.
     *
     * @return int|null current lock version, or null when no row exists
     */
    private function findLockVersion(ExpenseTransaction $transaction): ?int
    {
        $qb = $this->connection->getQueryBuilder();
        if ($transaction->dbId !== null) {
            $qb->select('lock_version')
                ->from('pettycash_transactions')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($transaction->dbId)))
                ->setMaxResults(1);
        } else {
            $qb->select('lock_version')
                ->from('pettycash_transactions')
                ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($transaction->id)))
                ->setMaxResults(1);
        }

        $row = $qb->executeQuery()->fetchAssociative();

        return $row === false ? null : (int) $row['lock_version'];
    }

    /**
     * Every transaction on the instance, at every stage and in every status.
     *
     * Deliberately wider than `findForFinanceQueue()`, which is the read T379 found publishing this
     * figure under the name `totalTransactions`. A `COUNT` rather than a read, so the answer is the
     * instance's size and not the depth of whatever window was opened to learn it.
     */
    public function countAll(): int
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select($qb->func()->count('id', 'pettycash_transaction_count'))
            ->from('pettycash_transactions');

        return (int) $qb->executeQuery()->fetchOne();
    }

    /**
     * Every transaction of a set of sheets, in one batched read (T016/T018).
     *
     * `CompletedSheetHistoryService` loads the transactions of a completed sheet to validate
     * terminality and to render the detail table; a per-sheet `findBySheetId()` read per listed
     * sheet would make the list endpoint N+1. The rows are ordered by sheet id and then by the
     * same tiebreaker `findBySheetId()` uses (`created_at`, then `id`) so a caller can page or
     * sort without a second read, and an empty input returns an empty list without issuing a
     * query.
     *
     * @param list<int> $sheetDbIds surrogate `pettycash_sheets.id` values
     * @return list<ExpenseTransaction>
     */
    public function findBySheetIds(array $sheetDbIds): array
    {
        $sheetDbIds = array_values(array_unique(array_filter(
            $sheetDbIds,
            static fn (?int $id): bool => $id !== null
        )));

        if ($sheetDbIds === []) {
            return [];
        }

        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_transactions')
            ->where($qb->expr()->in('sheet_id', $qb->createNamedParameter($sheetDbIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->orderBy('sheet_id', 'ASC')
            ->addOrderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC');

        return array_map([$this, 'mapRow'], $qb->executeQuery()->fetchAllAssociative());
    }

    /**
     * The transaction-status projection for a set of sheets, in one grouped read (T016/T017).
     *
     * The completed list needs, per sheet, the total, approved and rejected transaction counts —
     * and `approved + rejected = total` is an invariant the list must hold, so all three come from
     * one grouped read over `final_outcome` rather than from three separate predicates that could
     * drift. The approved count is the current count of transactions whose authoritative
     * `final_outcome` is `APPROVED`; it is the sheet fact, not a posting-time snapshot, and is
     * never stored as a second aggregate. The CASE expressions are the portable form the project's
     * static portability check enforces — `SUM(boolean)` would be rejected by PostgreSQL — and
     * grouping by the selected sheet id keeps `ONLY_FULL_GROUP_BY` satisfied.
     *
     * A sheet with no rows is absent from the map; the caller must not invent a count for it.
     *
     * @param list<int> $sheetDbIds
     * @return array<int, array{total: int, approved: int, rejected: int}>
     */
    public function transactionStatusProjectionFor(array $sheetDbIds): array
    {
        $sheetDbIds = array_values(array_unique(array_filter(
            $sheetDbIds,
            static fn (?int $id): bool => $id !== null
        )));

        if ($sheetDbIds === []) {
            return [];
        }

        $qb = $this->connection->getQueryBuilder();
        $approvedParam = (string) $qb->createNamedParameter('APPROVED');
        $rejectedParam = (string) $qb->createNamedParameter('REJECTED');

        $qb->selectAlias('t.sheet_id', 'sheet_id')
            ->selectAlias($qb->func()->count('t.id'), 'total_count')
            ->selectAlias(
                $qb->createFunction('SUM(CASE WHEN t.final_outcome = ' . $approvedParam . ' THEN 1 ELSE 0 END)'),
                'approved_count'
            )
            ->selectAlias(
                $qb->createFunction('SUM(CASE WHEN t.final_outcome = ' . $rejectedParam . ' THEN 1 ELSE 0 END)'),
                'rejected_count'
            )
            ->from('pettycash_transactions', 't')
            ->where($qb->expr()->in('t.sheet_id', $qb->createNamedParameter($sheetDbIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->groupBy('t.sheet_id');

        $projection = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $projection[(int) $row['sheet_id']] = [
                'total' => (int) $row['total_count'],
                'approved' => (int) $row['approved_count'],
                'rejected' => (int) $row['rejected_count'],
            ];
        }

        return $projection;
    }

    public function save(ExpenseTransaction $transaction): void
    {
        $qb = $this->connection->getQueryBuilder();
        $existingLockVersion = $this->findLockVersion($transaction);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $currentUser = $this->currentUser->uid();

        $revisionDbId = $transaction->revision()->dbId ?? null;

        if ($existingLockVersion !== null) {
            $qb->update('pettycash_transactions')
                ->set('sheet_id', $transaction->sheetId ? $qb->createNamedParameter($transaction->sheetId) : $qb->createNamedParameter(null))
                ->set('owner_uid', $qb->createNamedParameter($transaction->ownerUid))
                ->set('active_revision_id', $revisionDbId ? $qb->createNamedParameter($revisionDbId) : $qb->createNamedParameter(null))
                ->set('current_stage', $qb->createNamedParameter($transaction->stage->value))
                ->set('current_status', $qb->createNamedParameter($transaction->status->value))
                ->set('final_outcome', $transaction->finalOutcome ? $qb->createNamedParameter($transaction->finalOutcome) : $qb->createNamedParameter(null))
                ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE))
                ->set('lock_version', $qb->createNamedParameter($existingLockVersion + 1))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($transaction->dbId)))
                ->andWhere($qb->expr()->eq('lock_version', $qb->createNamedParameter($existingLockVersion)))
                ->executeStatement();
            $transaction->lockVersion = $existingLockVersion + 1;
        } else {
            $qb->insert('pettycash_transactions')
                ->values([
                    'public_id' => $qb->createNamedParameter($transaction->id),
                    'sheet_id' => $transaction->sheetId ? $qb->createNamedParameter($transaction->sheetId) : $qb->createNamedParameter(null),
                    'owner_uid' => $qb->createNamedParameter($transaction->ownerUid),
                    'active_revision_id' => $revisionDbId ? $qb->createNamedParameter($revisionDbId) : $qb->createNamedParameter(null),
                    'current_stage' => $qb->createNamedParameter($transaction->stage->value),
                    'current_status' => $qb->createNamedParameter($transaction->status->value),
                    'final_outcome' => $transaction->finalOutcome ? $qb->createNamedParameter($transaction->finalOutcome) : $qb->createNamedParameter(null),
                    'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE),
                    'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE),
                    'lock_version' => $qb->createNamedParameter(0),
                ])
                ->executeStatement();
            
            $transaction->dbId = (int) $qb->getLastInsertId();
            // The insert writes `public_id` from the aggregate's own `id`, but never assigned it
            // back to the `publicId` mirror, so an aggregate that had just been saved reported
            // `publicId === null` and every `findByPublicId()` on it could not resolve the row it
            // had written. `mapRow()` sets the mirror on the way back in; this sets it on the way
            // out. Found by T236's real-database round trip.
            $transaction->publicId = $transaction->id;
            $transaction->lockVersion = 0;
        }
    }

    /**
     * Delete a never-submitted transaction and the revisions that belong to it.
     *
     * The revision rows go first because `pettycash_transaction_revisions.transaction_id`
     * points at the transaction being removed, and leaving them behind would strand
     * revisions no query would ever reach.
     */
    public function delete(ExpenseTransaction $transaction): void
    {
        $transactionDbId = $transaction->dbId;
        if ($transactionDbId === null) {
            throw new RuntimeException(
                'Cannot delete a transaction that was never persisted'
            );
        }

        $qb = $this->connection->getQueryBuilder();

        $qb->delete('pettycash_transaction_revisions')
            ->where($qb->expr()->eq('transaction_id', $qb->createNamedParameter($transactionDbId)))
            ->executeStatement();

        $qb->delete('pettycash_evidence')
            ->where($qb->expr()->eq('transaction_id', $qb->createNamedParameter($transactionDbId)))
            ->executeStatement();

        $qb->delete('pettycash_transactions')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($transactionDbId)))
            ->executeStatement();
    }

    private function mapRow(array $row): ExpenseTransaction
    {
        $transactionDbId = (int) $row['id'];

        // The active revision carries the amount, currency and expense data, and
        // its domain invariant rejects revision numbers below one — so a
        // placeholder revision cannot be substituted here. The real one has to be
        // loaded or the transaction cannot be represented at all.
        //
        // An `UnreadableRecordData`, not a bare `RuntimeException`: a stored
        // row whose revision never persisted (creation interrupted between the
        // transaction insert and the revision insert) is a data fault, and
        // FR-083 answers data faults with 422 naming the fault rather than a
        // 500. The message names surrogate ids for the log; `OcsErrorMapper`
        // withholds it from callers (T029).
        $activeRevision = $this->revisionRepository->findActiveByTransactionId($transactionDbId);
        if ($activeRevision === null) {
            throw new UnreadableRecordData(
                'Transaction ' . $row['public_id'] . ' has no active revision'
            );
        }

        $transaction = new ExpenseTransaction(
            $row['public_id'],
            $row['owner_uid'],
            $activeRevision
        );
        $transaction->sheetId = $row['sheet_id'] ? (int) $row['sheet_id'] : null;
        $transaction->stage = WorkflowStage::from($row['current_stage']);
        $transaction->status = TransactionStatus::from($row['current_status']);
        $transaction->finalOutcome = $row['final_outcome'] ?? null;
        $transaction->createdAt = $row['created_at'] ? new DateTimeImmutable($row['created_at']) : null;
        $transaction->updatedAt = $row['updated_at'] ? new DateTimeImmutable($row['updated_at']) : null;
        $transaction->lockVersion = (int) ($row['lock_version'] ?? 0);
        $transaction->dbId = $transactionDbId;
        $transaction->publicId = $row['public_id'];
        return $transaction;
    }
}