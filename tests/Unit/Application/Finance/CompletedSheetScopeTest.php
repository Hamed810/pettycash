<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Application\Finance;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Finance\CompletedSheetScope;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseSheetRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseTransactionRepository;
use PHPUnit\Framework\TestCase;

/**
 * T005 — `CompletedSheetScope`, the single shared Feature 008 completed-history predicate.
 *
 * `isExposedByCompletedHistory($transaction)` is the one interpretation of "finished" shared by the
 * completed-history detail read and the Finance evidence-content branch. Four conditions must all
 * hold, and each is pinned by at least one test:
 *
 * - the transaction itself is terminal (`COMPLETED_APPROVED`/`COMPLETED_REJECTED`) at `FINAL`;
 * - its Cost Sheet is `COMPLETED` or `COMPLETED_WITH_REJECTIONS`;
 * - every transaction of that sheet is terminal too — one pending sibling excludes the whole sheet;
 * - the evaluation performs no write (the non-mutation test asserts no state changed).
 *
 * The statuses and stage come straight from the existing domain enums, the same values
 * `ExpenseSheet::recalculateStatusFrom()` uses; no new status name or lifecycle constant is
 * invented, and out-of-scope input returns `false` rather than throwing.
 */
final class CompletedSheetScopeTest extends TestCase
{
    private InMemoryExpenseSheetRepository $sheets;

    private InMemoryExpenseTransactionRepository $transactions;

    private CompletedSheetScope $scope;

    protected function setUp(): void
    {
        $this->sheets = new InMemoryExpenseSheetRepository();
        $this->transactions = new InMemoryExpenseTransactionRepository();
        $this->scope = new CompletedSheetScope($this->sheets, $this->transactions);
    }

    private function makeRevision(string $id = 'rev-1'): TransactionRevision
    {
        $revision = TransactionRevision::draft(
            $id,
            1,
            new DateTimeImmutable('2026-09-20', new DateTimeZone('UTC')),
            Money::positive(50000, CurrencyCode::fromString('IRR'))
        );
        $revision->complete('Description', 'CC001', 'CC001', 'Engineering', 1, 'Office Supplies', 1, true);

        return $revision;
    }

    /**
     * Build a persisted terminal sheet and its transactions, returning the target transaction.
     *
     * `ExpenseSheetRepository::findById()` is keyed on `dbId`, and
     * `ExpenseTransactionRepository::findBySheetId()` matches `sheetId`, so the sheet is saved first
     * to obtain the dbId and every transaction is saved with that `sheetId`.
     *
     * @param list<ExpenseTransaction> $transactions
     */
    private function persistSheet(SheetStatus $status, array $transactions): ExpenseTransaction
    {
        $sheet = ExpenseSheet::open('sheet-1', 'test-owner', 'acc-1', CurrencyCode::fromString('IRR'), 'Test Sheet');
        $sheet->status = $status;
        $this->sheets->save($sheet);

        $sheetDbId = $sheet->dbId;
        /** @var list<ExpenseTransaction> $saved */
        $saved = [];
        foreach ($transactions as $index => $transaction) {
            $transaction->sheetId = $sheetDbId;
            $this->transactions->save($transaction);
            $saved[] = $transaction;
        }

        return $saved[0];
    }

    private function terminalTransaction(string $id, TransactionStatus $status): ExpenseTransaction
    {
        $transaction = new ExpenseTransaction($id, 'test-owner', $this->makeRevision('rev-' . $id));
        $transaction->stage = WorkflowStage::Final;
        $transaction->status = $status;
        $transaction->finalOutcome = $status === TransactionStatus::CompletedApproved ? 'APPROVED' : 'REJECTED';

        return $transaction;
    }

    private function pendingTransaction(string $id): ExpenseTransaction
    {
        $transaction = new ExpenseTransaction($id, 'test-owner', $this->makeRevision('rev-' . $id));
        $transaction->stage = WorkflowStage::Finance;
        $transaction->status = TransactionStatus::AwaitingFinanceReview;

        return $transaction;
    }

    public function testCompletedSheetWithEveryTransactionTerminalIsExposed(): void
    {
        $target = $this->persistSheet(
            SheetStatus::Completed,
            [
                $this->terminalTransaction('tx-a', TransactionStatus::CompletedApproved),
                $this->terminalTransaction('tx-b', TransactionStatus::CompletedApproved),
            ]
        );

        self::assertTrue(
            $this->scope->isExposedByCompletedHistory($target),
            'a COMPLETED sheet whose every transaction is terminal must be exposed'
        );
    }

    public function testACompletedRejectedTargetTransactionIsAlsoExposed(): void
    {
        $target = $this->persistSheet(
            SheetStatus::Completed,
            [$this->terminalTransaction('tx-a', TransactionStatus::CompletedRejected)]
        );

        self::assertTrue(
            $this->scope->isExposedByCompletedHistory($target),
            'a terminal rejected outcome still counts as a terminal Finance decision'
        );
    }

    public function testATargetTransactionStillFinancePendingIsRefused(): void
    {
        $target = $this->persistSheet(
            SheetStatus::Completed,
            [$this->pendingTransaction('tx-a')]
        );

        self::assertFalse(
            $this->scope->isExposedByCompletedHistory($target),
            'a transaction still awaiting its Finance decision must not be exposed'
        );
    }

    public function testATargetAtTheFinalStageButNotTerminalIsRefused(): void
    {
        // A transaction at FINAL with a non-terminal status is an inconsistent record that must not
        // be treated as finished.
        $target = $this->terminalTransaction('tx-a', TransactionStatus::CompletedApproved);
        $target->status = TransactionStatus::AwaitingFinanceReview;
        $this->persistSheet(SheetStatus::Completed, [$target]);

        self::assertFalse(
            $this->scope->isExposedByCompletedHistory($target),
            'a FINAL-stage transaction whose status is not terminal must be refused'
        );
    }

    public function testASheetNotCompletedIsRefused(): void
    {
        $target = $this->persistSheet(
            SheetStatus::Finance,
            [$this->terminalTransaction('tx-a', TransactionStatus::CompletedApproved)]
        );

        self::assertFalse(
            $this->scope->isExposedByCompletedHistory($target),
            'a sheet still at the Finance stage must not be exposed as completed history'
        );
    }

    public function testACompletedSheetWithANonTerminalSiblingIsRefused(): void
    {
        $target = $this->persistSheet(
            SheetStatus::Completed,
            [
                $this->terminalTransaction('tx-a', TransactionStatus::CompletedApproved),
                // The sheet says COMPLETED, but one sibling is still Finance-pending: not eligible.
                $this->pendingTransaction('tx-b'),
            ]
        );

        self::assertFalse(
            $this->scope->isExposedByCompletedHistory($target),
            'one non-terminal transaction in the sheet must exclude the whole sheet'
        );
    }

    public function testCompletedWithRejectionsWithAllTransactionsTerminalIsExposed(): void
    {
        $target = $this->persistSheet(
            SheetStatus::CompletedWithRejections,
            [
                $this->terminalTransaction('tx-a', TransactionStatus::CompletedApproved),
                $this->terminalTransaction('tx-b', TransactionStatus::CompletedApproved),
                $this->terminalTransaction('tx-c', TransactionStatus::CompletedRejected),
            ]
        );

        self::assertTrue(
            $this->scope->isExposedByCompletedHistory($target),
            'a completion-with-rejections whose transactions are all terminal must be exposed'
        );
    }

    public function testATransactionWithoutASheetIsRefused(): void
    {
        $orphan = $this->terminalTransaction('tx-orphan', TransactionStatus::CompletedApproved);
        // sheetId stays null: no sheet to belong to, so nothing to expose.

        self::assertFalse(
            $this->scope->isExposedByCompletedHistory($orphan),
            'a transaction that belongs to no sheet must be refused'
        );
    }

    public function testAnOutOfScopeRecordReturnsFalseWithoutThrowing(): void
    {
        // A transaction whose sheet does not exist (dbId never saved) returns false, not an exception.
        $transaction = new ExpenseTransaction('tx-missing', 'test-owner', $this->makeRevision(), 999);
        $transaction->stage = WorkflowStage::Final;
        $transaction->status = TransactionStatus::CompletedApproved;

        self::assertFalse(
            $this->scope->isExposedByCompletedHistory($transaction),
            'an unresolvable sheet must be refused without throwing'
        );
    }

    public function testEvaluationPerformsNoMutation(): void
    {
        $target = $this->persistSheet(
            SheetStatus::CompletedWithRejections,
            [
                $this->terminalTransaction('tx-a', TransactionStatus::CompletedApproved),
                $this->terminalTransaction('tx-b', TransactionStatus::CompletedRejected),
            ]
        );

        $sheetDbId = $target->sheetId;
        $sheet = $this->sheets->findById($sheetDbId);

        $beforeSheetStatus = $sheet->status;
        $beforeStates = array_map(
            static fn (ExpenseTransaction $t): string => $t->stage->value . ':' . $t->status->value,
            $this->transactions->findBySheetId($sheetDbId)
        );

        $this->scope->isExposedByCompletedHistory($target);

        self::assertSame(
            $beforeSheetStatus,
            $this->sheets->findById($sheetDbId)->status,
            'the sheet status must be unchanged'
        );
        self::assertSame(
            $beforeStates,
            array_map(
                static fn (ExpenseTransaction $t): string => $t->stage->value . ':' . $t->status->value,
                $this->transactions->findBySheetId($sheetDbId)
            ),
            'every transaction stage and status must be unchanged'
        );
        self::assertSame(SheetStatus::CompletedWithRejections, $sheet->status, 'sanity: the fixture sheet was completed');
    }
}
