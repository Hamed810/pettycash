<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Finance;

use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;

/**
 * Feature 008 — the single reusable "completed-history" scope predicate.
 *
 * Decides whether a transaction belongs to a Cost Sheet eligible for Feature 008 completed-history
 * exposure. This exact predicate is shared by the two future consumers — the completed Cost Sheet
 * detail read and the Finance evidence-content branch — so neither can develop its own
 * interpretation of "finished". If they agreed to differ, evidence could be exposed for a sheet the
 * detail read would refuse, or vice versa.
 *
 * ## What makes a transaction exposed
 *
 * Every condition below must hold; `isExposedByCompletedHistory()` returns `false` (never throws)
 * for any out-of-scope input:
 *
 * 1. the transaction has a **terminal Finance outcome** — `COMPLETED_APPROVED` or
 *    `COMPLETED_REJECTED` (the existing completed-approved / completed-rejected semantics), and it is
 *    at the **final workflow stage** (`WorkflowStage::Final`);
 * 2. its Cost Sheet is in one of the terminal completed statuses — `COMPLETED` or
 *    `COMPLETED_WITH_REJECTIONS`;
 * 3. **every** transaction belonging to that sheet also satisfies the terminality requirement. If any
 *    transaction is still pending/non-terminal, the whole sheet is not exposed, which matches the
 *    lifecycle rule that a sheet only completes once every transaction has a terminal outcome.
 *
 * ## Reuse, not duplication
 *
 * The terminal terminality statuses and stage are read straight from the existing domain enums
 * (`TransactionStatus::CompletedApproved|CompletedRejected`, `WorkflowStage::Final`,
 * `SheetStatus::Completed|CompletedWithRejections`) — the same values
 * `ExpenseSheet::recalculateStatusFrom()` already uses to derive sheet completion. No new status
 * name or lifecycle constant is invented here.
 *
 * The sheet's transaction set is read through the existing repository
 * (`ExpenseTransactionRepository::findBySheetId()`), because an `ExpenseSheet` loaded from storage
 * carries an empty collection (`DbExpenseSheetRepository` does not hydrate it) — see
 * `ExpenseSheet::recalculateStatusFrom()`.
 *
 * ## Read-only
 *
 * This predicate performs no write: it does not mutate the sheet, the transaction, any decision,
 * workflow state, evidence, or account data. Loading a record through a repository is a read.
 */
final readonly class CompletedSheetScope
{
    public function __construct(
        private ExpenseSheetRepository $sheets,
        private ExpenseTransactionRepository $transactions
    ) {}

    /**
     * Whether `$transaction` is exposed by Feature 008 completed history.
     */
    public function isExposedByCompletedHistory(ExpenseTransaction $transaction): bool
    {
        // The target transaction itself must be terminal at the final stage.
        if ($transaction->stage !== WorkflowStage::Final) {
            return false;
        }
        if (!in_array(
            $transaction->status,
            [TransactionStatus::CompletedApproved, TransactionStatus::CompletedRejected],
            true
        )) {
            return false;
        }

        $sheetDbId = $transaction->sheetId;
        if ($sheetDbId === null) {
            return false;
        }

        $sheet = $this->sheets->findById($sheetDbId);
        if ($sheet === null) {
            return false;
        }

        if (!in_array(
            $sheet->status,
            [SheetStatus::Completed, SheetStatus::CompletedWithRejections],
            true
        )) {
            return false;
        }

        // Every transaction of the sheet must be terminal too — one pending transaction makes the
        // sheet ineligible for completed history.
        foreach ($this->transactions->findBySheetId($sheetDbId) as $sibling) {
            if ($sibling->stage !== WorkflowStage::Final) {
                return false;
            }
            if (!in_array(
                $sibling->status,
                [TransactionStatus::CompletedApproved, TransactionStatus::CompletedRejected],
                true
            )) {
                return false;
            }
        }

        return true;
    }
}
