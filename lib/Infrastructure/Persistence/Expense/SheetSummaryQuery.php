<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Persistence\Expense;

use OCA\PettyCash\Domain\Expense\SheetTotals;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The indexed sheet-summary projection behind FR-062.
 *
 * `data-model.md` requires the sheet totals to be *derived*: "Mutable totals are calculated by
 * role-scoped projections rather than stored as independent facts." A stored total is a second
 * source of truth that goes stale the instant a decision or a correction lands, and FR-050 makes a
 * correction a new active revision — so a figure cached against the old one would misstate the
 * sheet while looking authoritative.
 *
 * The query reads one flat row per (active revision, decision) pair and the domain reduces them.
 * That is deliberately not an aggregate: the reduction rules — the most-advanced-decision
 * precedence and the fact that a return makes a transaction pending — live in `SheetTotals`, and
 * restating them in SQL would give the same rule two implementations that drift. The join is still
 * index-served, which is what "indexed projection" has to mean here: `sheet_id` leads
 * `pettycash_transactions_sheet_state_idx`, and `revision_id` leads
 * `pettycash_workflow_decisions_revision_stage_uniq`. No aggregate is loaded, and no whole
 * `ExpenseTransaction` aggregate is composed to answer a number.
 *
 * Only decisions on the **active** revision are read. A decision recorded against a superseded
 * revision stays in the database as history — FR-050 requires it to — but it no longer describes
 * the transaction's current outcome.
 */
final readonly class SheetSummaryQuery
{
    public function __construct(private IDBConnection $connection) {}

    /**
     * The live figures for one sheet.
     *
     * A sheet with no database row — an aggregate built in memory and not yet saved — totals zero
     * rather than being an error, so a create response can report the same shape as a read.
     */
    public function totalsForSheet(?int $sheetDbId): SheetTotals
    {
        if ($sheetDbId === null) {
            return SheetTotals::fromRows([]);
        }

        return SheetTotals::fromRows($this->rowsForSheet($sheetDbId));
    }

    /**
     * The earliest and latest `expense_date` across each sheet's **active** revisions.
     *
     * `contracts/ocs-api.yaml` declares `SheetSummary.firstExpenseDate` and `lastExpenseDate`, and
     * nothing in `lib/` emitted either of them: `SheetPayloadFormatter::summary()` is a complete array
     * literal with one nested call, and neither property appeared in it or in `totals()`. So two
     * declared fields had no producer at all, and the only reader in the repository was a frontend
     * **fixture** — the trap T327 documented (T337).
     *
     * **Active revisions only, for the same reason the totals query reads only active revisions.** A
     * superseded revision keeps its `expense_date` as history — FR-050 requires it to — and a range
     * that included it would describe a transaction's past rather than the sheet's current contents.
     *
     * **Batched, because the caller is a list endpoint.** `GET /sheets` formats up to `pageSize`
     * sheets, and a per-sheet aggregate would make that N+1 — the trade T332 already recorded for
     * `countByOwnerUid()`. One grouped query answers the whole page, and `detail()` passes a
     * single-element list.
     *
     * **An aggregate, deliberately, unlike `totalsForSheet()`.** The totals reduction rules live in
     * `SheetTotals` and must not be restated in SQL, but "earliest and latest date" *is* the
     * aggregate: restating it in the domain would mean loading every active revision to compute two
     * numbers the database can return. `MIN`/`MAX`/`GROUP BY` are in the portable catalogue
     * `scripts/portability-check.js` enforces (FR-077), and `sheet_id` leads
     * `pettycash_transactions_sheet_state_idx`, so the join is index-served.
     *
     * A sheet with no dated active revision is **absent from the map** rather than present with
     * nulls. "No range" and "a range whose endpoints are unknown" are the same answer, and leaving
     * the key out means a caller cannot invent a distinction the query did not make.
     *
     * @param list<int> $sheetDbIds
     * @return array<int, array{first: string, last: string}> keyed by sheet database id
     */
    public function expenseDateRanges(array $sheetDbIds): array
    {
        $sheetDbIds = array_values(array_unique(array_filter(
            $sheetDbIds,
            static fn (?int $id): bool => $id !== null
        )));

        if ($sheetDbIds === []) {
            return [];
        }

        $qb = $this->connection->getQueryBuilder();
        $qb->select('t.sheet_id')
            ->selectAlias($qb->createFunction('MIN(r.expense_date)'), 'first_expense_date')
            ->selectAlias($qb->createFunction('MAX(r.expense_date)'), 'last_expense_date')
            ->from('pettycash_transactions', 't')
            ->join('t', 'pettycash_transaction_revisions', 'r', $qb->expr()->eq('r.id', 't.active_revision_id'))
            ->where($qb->expr()->in('t.sheet_id', $qb->createNamedParameter($sheetDbIds, IQueryBuilder::PARAM_INT_ARRAY)))
            // A revision with no date contributes to neither endpoint, and `MIN`/`MAX` ignore nulls
            // already — stated here because the reason the range is never wrong about a missing date
            // is the reader having to know that.
            ->andWhere($qb->expr()->isNotNull('r.expense_date'))
            ->groupBy('t.sheet_id');

        $ranges = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $first = $this->toDate($row['first_expense_date'] ?? null);
            $last = $this->toDate($row['last_expense_date'] ?? null);

            if ($first === null || $last === null) {
                // `MIN` and `MAX` are read from the same non-null rows, so both are present or
                // neither is. Skipping rather than emitting a half-answer keeps a caller from
                // rendering a start date with no end.
                continue;
            }

            $ranges[(int) $row['sheet_id']] = ['first' => $first, 'last' => $last];
        }

        return $ranges;
    }

    /**
     * The same range for one sheet, as the two nullable members `SheetSummary` declares.
     *
     * `null` for a sheet with no dated active revision, which is what `format: date, nullable: true`
     * means — and the honest answer for a sheet with nothing in it, rather than today's date.
     *
     * @return array{first: ?string, last: ?string}
     */
    public function expenseDateRangeForSheet(?int $sheetDbId): array
    {
        if ($sheetDbId === null) {
            return ['first' => null, 'last' => null];
        }

        return $this->expenseDateRanges([$sheetDbId])[$sheetDbId] ?? ['first' => null, 'last' => null];
    }

    /**
     * A `DATE_IMMUTABLE` column as `YYYY-MM-DD`, which is what `format: date` declares.
     *
     * Read through the connection's own type conversion rather than reformatted by hand, because the
     * three declared backends return a date column in three different PHP types and the contract asks
     * for one string. A value that cannot be read as a date is `null` rather than an empty string: an
     * absent date and a date that rendered as nothing are different claims.
     */
    private function toDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', substr($value, 0, 10));

        return $date === false ? null : $date->format('Y-m-d');
    }

    /**
     * One entry per active revision, in the shape `SheetTotals::fromRows` consumes.
     *
     * @return list<array{amount: int, latest: ?Decision, manager: ?Decision, senior: ?Decision, finance: ?Decision}>
     */
    private function rowsForSheet(int $sheetDbId): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('r.id', 'r.amount_minor', 'd.stage', 'd.decision')
            ->from('pettycash_transactions', 't')
            ->join('t', 'pettycash_transaction_revisions', 'r', $qb->expr()->eq('r.id', 't.active_revision_id'))
            ->leftJoin('r', 'pettycash_workflow_decisions', 'd', $qb->expr()->eq('d.revision_id', 'r.id'))
            ->where($qb->expr()->eq('t.sheet_id', $qb->createNamedParameter($sheetDbId)))
            ->orderBy('r.id');

        /** @var array<int, array{amount: int, latest: ?Decision, manager: ?Decision, senior: ?Decision, finance: ?Decision}> $byRevision */
        $byRevision = [];

        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $revisionId = (int) $row['id'];

            if (!isset($byRevision[$revisionId])) {
                $byRevision[$revisionId] = [
                    'amount' => (int) $row['amount_minor'],
                    'latest' => null,
                    'manager' => null,
                    'senior' => null,
                    'finance' => null,
                ];
            }

            $stage = $row['stage'] ?? null;
            $decision = $this->toDecision($row['decision'] ?? null);
            if ($stage === null || $decision === null) {
                // No decision recorded for this revision at this row: the LEFT JOIN produced a null
                // stage, which is the normal state of an undecided transaction.
                continue;
            }

            // The (revision_id, stage) unique index means at most one decision per stage. Anything
            // unrecognised is ignored rather than guessed at, so a new stage added to the enum cannot
            // silently be read as an approval.
            match ($stage) {
                WorkflowStage::CostCenter->value => $byRevision[$revisionId]['manager'] = $decision,
                WorkflowStage::Senior->value => $byRevision[$revisionId]['senior'] = $decision,
                WorkflowStage::Finance->value => $byRevision[$revisionId]['finance'] = $decision,
                default => null,
            };
        }

        foreach ($byRevision as $revisionId => $row) {
            $byRevision[$revisionId]['latest'] = SheetTotals::effectiveOutcome(
                $row['finance'],
                $row['senior'],
                $row['manager']
            );
        }

        return array_values($byRevision);
    }

    private function toDecision(mixed $value): ?Decision
    {
        if (!is_string($value)) {
            return null;
        }

        return Decision::tryFrom($value);
    }
}
