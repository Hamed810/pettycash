<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Application\Evidence;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Evidence\EvidenceContentAccess;
use OCA\PettyCash\Application\Evidence\EvidenceVisibility;
use OCA\PettyCash\Application\Finance\CompletedSheetScope;
use OCA\PettyCash\Application\Finance\FinanceAccess;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Security\AuthorizationService;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Shared\Clock;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Tests\TestDoubles\FixedClock;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseSheetRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseTransactionRepository;
use PHPUnit\Framework\TestCase;

/**
 * T021 — `EvidenceContentAccess` unit cases (minimum five authorization cases).
 *
 * `mayRead()` first defers to `EvidenceVisibility::mayRead()`, then falls back to a
 * Finance-only completed-history branch requiring an effective `FINANCE` assignment and
 * a terminal completed-history scope. No mutation method exists on the class.
 *
 * `EvidenceVisibility`, `AuthorizationService`, and `FinanceAccess` are all final, so
 * they are constructed with mockable interfaces:
 * - `EvidenceVisibility` uses real `AuthorizationService` (stateless) + mocked
 *   `RoleAssignmentRepository`/`CostCenterRepository`
 * - `FinanceAccess` uses mocked `RoleAssignmentRepository`/`CurrentUser`/`Clock`
 */
final class EvidenceContentAccessTest extends TestCase
{
    private EvidenceVisibility $visibility;
    private FinanceAccess $financeAccess;
    private CompletedSheetScope $completedSheetScope;
    private EvidenceContentAccess $access;

    private InMemoryExpenseSheetRepository $sheets;
    private InMemoryExpenseTransactionRepository $transactions;

    private RoleAssignmentRepository $roleAssignments;
    private CostCenterRepository $costCenters;
    private CurrentUser $currentUser;
    private Clock $clock;

    protected function setUp(): void
    {
        $this->sheets = new InMemoryExpenseSheetRepository();
        $this->transactions = new InMemoryExpenseTransactionRepository();
        $this->completedSheetScope = new CompletedSheetScope($this->sheets, $this->transactions);

        $this->roleAssignments = $this->createMock(RoleAssignmentRepository::class);
        $this->costCenters = $this->createMock(CostCenterRepository::class);
        $this->currentUser = $this->createMock(CurrentUser::class);
        $this->clock = new FixedClock('2026-11-18T00:00:00+00:00');

        $this->visibility = new EvidenceVisibility(
            new AuthorizationService(),
            $this->roleAssignments,
            $this->costCenters
        );

        $this->financeAccess = new FinanceAccess(
            $this->roleAssignments,
            $this->currentUser,
            $this->clock
        );

        $this->access = new EvidenceContentAccess(
            $this->visibility,
            $this->financeAccess,
            $this->completedSheetScope
        );
    }

    private function persistSheet(SheetStatus $status): \OCA\PettyCash\Domain\Expense\ExpenseSheet
    {
        $sheet = new \OCA\PettyCash\Domain\Expense\ExpenseSheet(
            'sheet-1', 'test-owner', 'acc-1', CurrencyCode::fromString('IRR'), $status
        );
        $sheet->title = 'Test Sheet';
        $this->sheets->save($sheet);

        return $sheet;
    }

    private function makeRevision(string $id): TransactionRevision
    {
        return TransactionRevision::draft(
            'rev-' . $id,
            1,
            new DateTimeImmutable('2026-09-20', new DateTimeZone('UTC')),
            Money::positive(50000, CurrencyCode::fromString('IRR'))
        );
    }

    private function terminalTransaction(string $id, SheetStatus $sheetStatus): ExpenseTransaction
    {
        $sheet = $this->persistSheet($sheetStatus);
        $transaction = new ExpenseTransaction($id, 'test-owner', $this->makeRevision($id), $sheet->dbId);
        $transaction->stage = WorkflowStage::Final;
        $transaction->status = TransactionStatus::CompletedApproved;
        $transaction->finalOutcome = 'APPROVED';
        $this->transactions->save($transaction);

        return $transaction;
    }

    private function pendingTransaction(SheetStatus $sheetStatus): ExpenseTransaction
    {
        $sheet = $this->persistSheet($sheetStatus);
        $transaction = new ExpenseTransaction('tx-pending', 'test-owner', $this->makeRevision('tx-pending'), $sheet->dbId);
        $transaction->stage = WorkflowStage::Finance;
        $transaction->status = TransactionStatus::AwaitingFinanceReview;
        $this->transactions->save($transaction);

        return $transaction;
    }

    /**
     * Configure mocks so FinanceAccess admits (current user is Finance) while
     * EvidenceVisibility refuses for a non-Finance actor (cross-user, not a reviewer).
     */
    private function configureFinanceAdmittedButVisibilityDenied(): void
    {
        $this->currentUser->method('uid')->willReturn('finance-officer');
        $this->roleAssignments->method('findActiveByUserUid')->willReturnCallback(
            fn(string $uid, DateTimeImmutable $date): array => $uid === 'finance-officer'
                ? [RoleAssignment::create('finance-officer', Role::Finance, null, $date, null)]
                : []
        );
    }

    /**
     * Configure mocks so both EvidenceVisibility and FinanceAccess deny.
     */
    private function configureAllDenied(): void
    {
        $this->currentUser->method('uid')->willReturn('someone-else');
        $this->roleAssignments->method('findActiveByUserUid')->willReturn([]);
    }

    // Case 1: Finance read — effective Finance Manager + exposed completed-history transaction
    public function testFinanceManagerCanReadCompletedHistoryEvidence(): void
    {
        $transaction = $this->terminalTransaction('tx-a', SheetStatus::Completed);

        $this->configureFinanceAdmittedButVisibilityDenied();

        self::assertTrue(
            $this->access->mayRead('finance-officer', $transaction),
            'an effective Finance Manager may read evidence for an exposed completed-history transaction'
        );
    }

    // Case 2: Non-Finance cross-user denial
    public function testNonFinanceCallerCannotReadCrossUserEvidence(): void
    {
        $transaction = $this->terminalTransaction('tx-a', SheetStatus::Completed);

        $this->configureAllDenied();

        self::assertFalse(
            $this->access->mayRead('someone-else', $transaction),
            'a non-Finance caller cannot read another user\'s evidence through this branch'
        );
    }

    // Case 3: No mutation — EvidenceContentAccess has no upload/delete/link method
    public function testNoMutationMethodExists(): void
    {
        $reflection = new \ReflectionClass(EvidenceContentAccess::class);
        $methods = array_map(
            fn (\ReflectionMethod $m): string => $m->getName(),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC)
        );

        self::assertNotContains('upload', $methods, 'no upload method');
        self::assertNotContains('delete', $methods, 'no delete method');
        self::assertNotContains('deactivate', $methods, 'no deactivate method');
        self::assertNotContains('link', $methods, 'no link method');
        self::assertContains('mayRead', $methods, 'mayRead is the only public method');
    }

    // Case 4: Scope denial — Finance-pending transaction refused even with FINANCE
    public function testFinancePendingTransactionIsRefused(): void
    {
        $transaction = $this->pendingTransaction(SheetStatus::Finance);

        // Current user is Finance (admitted), but the actor is cross-user and not a reviewer,
        // so EvidenceVisibility refuses; the FinanceAccess fallback then hits the scope gate.
        $this->configureFinanceAdmittedButVisibilityDenied();

        self::assertFalse(
            $this->access->mayRead('someone-else', $transaction),
            'evidence for a Finance-pending transaction is refused even with FINANCE assignment'
        );
    }

    public function testCompletedSheetWithNonTerminalSiblingIsRefused(): void
    {
        $sheet = new \OCA\PettyCash\Domain\Expense\ExpenseSheet(
            'sheet-2', 'test-owner', 'acc-1', CurrencyCode::fromString('IRR'), SheetStatus::Completed
        );
        $sheet->title = 'Mixed Sheet';
        $this->sheets->save($sheet);

        // Terminal target transaction.
        $target = new ExpenseTransaction('tx-target', 'test-owner', $this->makeRevision('tx-target'), $sheet->dbId);
        $target->stage = WorkflowStage::Final;
        $target->status = TransactionStatus::CompletedApproved;
        $target->finalOutcome = 'APPROVED';
        $this->transactions->save($target);

        // Non-terminal sibling.
        $sibling = new ExpenseTransaction('tx-sibling', 'test-owner', $this->makeRevision('tx-sibling'), $sheet->dbId);
        $sibling->stage = WorkflowStage::Finance;
        $sibling->status = TransactionStatus::AwaitingFinanceReview;
        $this->transactions->save($sibling);

        $this->configureFinanceAdmittedButVisibilityDenied();

        self::assertFalse(
            $this->access->mayRead('someone-else', $target),
            'a completed sheet with a non-terminal sibling is refused'
        );
    }

    // Case 5: Existing behavior unchanged — when EvidenceVisibility admits, FINANCE is not consulted
    public function testExistingVisibilityAdmissionIsUnchanged(): void
    {
        $transaction = $this->terminalTransaction('tx-a', SheetStatus::Completed);

        // Existing visibility admits: actor is the owner (checked first by EvidenceVisibility).
        // FinanceAccess should never be consulted.
        $this->currentUser->method('uid')->willReturn('test-owner');
        $this->roleAssignments->method('findActiveByUserUid')->willReturn([]);

        self::assertTrue(
            $this->access->mayRead('test-owner', $transaction),
            'existing visibility admission grants access without consulting FinanceAccess'
        );
    }
}
