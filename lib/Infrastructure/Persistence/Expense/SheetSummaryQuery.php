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
