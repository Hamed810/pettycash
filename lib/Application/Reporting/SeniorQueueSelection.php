<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Reporting;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Workflow\Repository\WorkflowDecisionRepository;
use OCA\PettyCash\Domain\Workflow\WorkflowDecision;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;

/**
 * The Feature 007 `senior-queue` export row source (T028).
 *
 * The client submits the exact filtered + sorted transaction `ids` in on-screen order (T022, owner
 * D4). This service resolves each id **in the submitted order** and scope-validates every id against
 * the caller's active `SENIOR_MANAGER` assignments **before** any file generation — the same source
 * `ManagementReviewService::getSeniorQueue` reads, so an export and the interactive queue cannot
 * disagree about scope (`contracts/exports.md`; FR-007-056).
 *
 * ## A selection is honoured in full or refused
 *
 * An id that does not exist and an id outside the caller's senior scope produce the same refusal via
 * `UnsupportedSelection`, so the endpoint cannot confirm the existence of records the caller may not
 * see (FR-003). Nothing is generated for a refused selection, and no owner-scoped fallback is ever
 * applied.
 *
 * ## A legitimate empty selection is a measured zero
 *
 * `ids: []` (the client filtered to nothing in the modal) resolves zero rows and is answered with a
 * header-only XLSX (`rowCount: 0`) exactly like any other resource's true-negative result — it is a
 * measurement, not "no selection".
 */
final class SeniorQueueSelection
{
    public function __construct(
        private ExpenseTransactionRepository $transactions,
        private ExpenseSheetRepository $sheets,
        private WorkflowDecisionRepository $decisions,
        private RoleAssignmentRepository $roleAssignments,
        private CostCenterRepository $costCenters
    ) {}

    /**
     * Resolve the selected transaction ids to combined senior-queue rows, in the submitted order.
     *
     * @param list<string> $transactionIds
     * @return list<array<string, mixed>>
     * @throws UnsupportedSelection when any id cannot be resolved inside the caller's senior scope
     */
    public function rowsInOrder(string $actorUid, array $transactionIds): array
    {
        $scope = $this->seniorManagedScope($actorUid);

        $rows = [];
        foreach ($transactionIds as $id) {
            $transaction = $this->transactions->findByPublicId($id);
            if ($transaction === null || $transaction->dbId === null) {
                throw new UnsupportedSelection(
                    "No transaction is available to you with id '{$id}'. A selection is honoured in "
                    . 'full or refused; an export that silently dropped it would report a row count '
                    . 'that looks measured.'
                );
            }

            if (!$this->inSeniorScope($transaction, $scope)) {
                throw new UnsupportedSelection(
                    "No transaction is available to you with id '{$id}'. A selection is honoured in "
                    . 'full or refused; an export that silently dropped it would report a row count '
                    . 'that looks measured.'
                );
            }

            $rows[] = ReportProjection::seniorQueueRow(
                $transaction->sheetId !== null ? $this->sheets->findById($transaction->sheetId) : null,
                $transaction,
                $this->currentDecisionForStage($transaction, WorkflowStage::CostCenter),
                $this->currentDecisionForStage($transaction, WorkflowStage::Senior),
            );
        }

        return $rows;
    }

    /**
     * The cost centres the actor is actively assigned to as a senior manager.
     *
     * @return array{ids: array<int, true>, codes: array<string, true>}
     */
    private function seniorManagedScope(string $actorUid): array
    {
        $ids = [];
        $codes = [];
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        foreach ($this->roleAssignments->findActiveByUserAndRole($actorUid, Role::SeniorManager, $now) as $assignment) {
            if ($assignment->costCenterId === null) {
                continue;
            }
            $ids[$assignment->costCenterId] = true;
            $code = $this->costCenters->findById($assignment->costCenterId)?->code();
            if ($code !== null && $code !== '') {
                $codes[$code] = true;
            }
        }

        return ['ids' => $ids, 'codes' => $codes];
    }

    /**
     * Whether the transaction's current-revision cost centre is inside the caller's senior scope.
     *
     * Same scope rule as the interactive senior queue: the revision carries the code as a
     * submission-time snapshot, with a fallback to the numeric join key so a revision whose snapshot
     * is missing is still scoped rather than silently treated as "no scope required".
     *
     * @param array{ids: array<int, true>, codes: array<string, true>} $scope
     */
    private function inSeniorScope(ExpenseTransaction $transaction, array $scope): bool
    {
        $revision = $transaction->revision();
        $costCenterDbId = $revision->costCenterDbId;

        if ($costCenterDbId !== null && isset($scope['ids'][$costCenterDbId])) {
            return true;
        }

        $code = $revision->costCenterCode;
        if (($code === null || $code === '') && $costCenterDbId !== null) {
            $code = $this->costCenters->findById($costCenterDbId)?->code();
        }

        return $code !== null && $code !== '' && isset($scope['codes'][$code]);
    }

    /**
     * The decision recorded against this transaction's **current** revision at one stage.
     *
     * The unique index on `(revision, stage)` admits one decision per revision per stage, so the
     * last match ordered by `decided_at ASC` is the decision in force — the same read the
     * management review service uses for its sheet projections.
     */
    private function currentDecisionForStage(ExpenseTransaction $transaction, WorkflowStage $stage): ?WorkflowDecision
    {
        if ($transaction->dbId === null) {
            return null;
        }

        $currentRevisionId = $transaction->revision()->id();
        $current = null;

        foreach ($this->decisions->findByTransactionId($transaction->dbId) as $decision) {
            if ($decision->stage !== $stage) {
                continue;
            }
            if ($decision->revisionId !== $currentRevisionId) {
                continue;
            }
            $current = $decision;
        }

        return $current;
    }
}
