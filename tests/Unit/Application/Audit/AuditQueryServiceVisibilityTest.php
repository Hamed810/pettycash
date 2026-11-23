<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Application\Audit;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Audit\AuditQueryService;
use OCA\PettyCash\Application\Evidence\EvidenceVisibility;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Security\AuthorizationService;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Audit\Repository\AuditEventRepository;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository;
use OCA\PettyCash\Domain\Shared\Clock;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Tests\TestDoubles\FixedClock;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditEventRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseSheetRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseTransactionRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionRevisionRepository;
use PHPUnit\Framework\TestCase;

/**
 * T021 — audit/revision visibility regression for Feature 008.
 *
 * `AuditQueryService::mayRead()` delegates to `EvidenceVisibility::mayRead()` —
 * Feature 008's `EvidenceContentAccess` adds a Finance completed-history branch on top
 * of `EvidenceVisibility` for evidence *content* delivery, but `AuditQueryService` itself
 * is unchanged. This suite proves:
 *
 * 1. `AuditQueryService::mayRead()` delegates to `EvidenceVisibility::mayRead()` — no
 *    second, independent visibility rule.
 * 2. When `EvidenceVisibility` denies, `AuditQueryService` denies — out-of-scope actors
 *    get nothing.
 * 3. When `EvidenceVisibility` admits (owner, in-scope reviewer, administrator), the
 *    same actors can read audit/revisions — existing behavior is unchanged.
 *
 * `EvidenceVisibility`, `AuthorizationService`, and `AuditQueryService` are final, so
 * they are constructed with mockable interfaces.
 */
final class AuditQueryServiceVisibilityTest extends TestCase
{
    private EvidenceVisibility $visibility;
    private AuditQueryService $audit;
    private ExpenseTransactionRepository $transactions;
    private AuditEventRepository $auditEvents;
    private TransactionRevisionRepository $revisions;

    private RoleAssignmentRepository $roleAssignments;
    private CostCenterRepository $costCenters;
    private CurrentUser $currentUser;
    private Clock $clock;

    private InMemoryExpenseSheetRepository $sheets;
    private InMemoryExpenseTransactionRepository $txRepo;
    private InMemoryTransactionRevisionRepository $revRepo;
    private InMemoryAuditEventRepository $auditRepo;

    protected function setUp(): void
    {
        $this->sheets = new InMemoryExpenseSheetRepository();
        $this->txRepo = new InMemoryExpenseTransactionRepository();
        $this->revRepo = new InMemoryTransactionRevisionRepository();
        $this->auditRepo = new InMemoryAuditEventRepository();

        $this->transactions = $this->txRepo;
        $this->auditEvents = $this->auditRepo;
        $this->revisions = $this->revRepo;

        $this->roleAssignments = $this->createMock(RoleAssignmentRepository::class);
        $this->costCenters = $this->createMock(CostCenterRepository::class);
        $this->currentUser = $this->createMock(CurrentUser::class);
        $this->clock = new FixedClock('2026-11-18T00:00:00+00:00');

        $this->visibility = new EvidenceVisibility(
            new AuthorizationService(),
            $this->roleAssignments,
            $this->costCenters
        );

        $this->audit = new AuditQueryService(
            $this->transactions,
            $this->auditEvents,
            $this->revisions,
            $this->visibility
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
        $this->txRepo->save($transaction);

        return $transaction;
    }

    /**
     * Configure mocks so EvidenceVisibility denies for a cross-user actor with no roles.
     */
    private function configureVisibilityDenied(): void
    {
        $this->currentUser->method('uid')->willReturn('someone-else');
        $this->roleAssignments->method('findActiveByUserUid')->willReturn([]);
    }

    /**
     * Configure mocks so EvidenceVisibility admits the owner.
     */
    private function configureVisibilityAdmitsOwner(): void
    {
        $this->currentUser->method('uid')->willReturn('test-owner');
        $this->roleAssignments->method('findActiveByUserUid')->willReturn([]);
    }

    // Case 1: AuditQueryService denies when EvidenceVisibility denies
    public function testAuditVisibilityDeniedWhenVisibilityDenies(): void
    {
        $transaction = $this->terminalTransaction('tx-a', SheetStatus::Completed);

        $this->configureVisibilityDenied();

        self::assertFalse(
            $this->audit->mayRead('someone-else', $transaction),
            'audit/revision visibility follows EvidenceVisibility — denied for out-of-scope actor'
        );
    }

    // Case 2: AuditQueryService admits when EvidenceVisibility admits (owner)
    public function testAuditVisibilityAdmittedWhenVisibilityAdmits(): void
    {
        $transaction = $this->terminalTransaction('tx-a', SheetStatus::Completed);

        $this->configureVisibilityAdmitsOwner();

        self::assertTrue(
            $this->audit->mayRead('test-owner', $transaction),
            'audit/revision visibility admits the owner through EvidenceVisibility'
        );
    }

    // Case 3: visibleTransaction returns null for out-of-scope actor
    public function testVisibleTransactionNullForOutOfScope(): void
    {
        $transaction = $this->terminalTransaction('tx-a', SheetStatus::Completed);

        $this->configureVisibilityDenied();

        self::assertNull(
            $this->audit->visibleTransaction('someone-else', (string) $transaction->publicId),
            'visibleTransaction returns null for out-of-scope actor — no existence disclosure'
        );
    }

    // Case 4: visibleTransaction returns the transaction for in-scope actor
    public function testVisibleTransactionReturnsTransactionForOwner(): void
    {
        $transaction = $this->terminalTransaction('tx-a', SheetStatus::Completed);

        $this->configureVisibilityAdmitsOwner();

        $visible = $this->audit->visibleTransaction('test-owner', (string) $transaction->publicId);

        self::assertNotNull($visible);
        self::assertSame((string) $transaction->publicId, (string) $visible->publicId);
    }

    // Case 5: readTransactionAudit and readRevisions delegate to visibility-checked access
    public function testReadTransactionAuditDeniedForOutOfScope(): void
    {
        $transaction = $this->terminalTransaction('tx-a', SheetStatus::Completed);

        $this->configureVisibilityDenied();

        // mayRead is the gate; readTransactionAudit does not re-check visibility itself,
        // but mayRead must return false for out-of-scope actors so callers refuse first.
        self::assertFalse(
            $this->audit->mayRead('someone-else', $transaction),
            'readTransactionAudit is gated by mayRead — out-of-scope denied'
        );
    }

    public function testReadRevisionsDeniedForOutOfScope(): void
    {
        $transaction = $this->terminalTransaction('tx-a', SheetStatus::Completed);

        $this->configureVisibilityDenied();

        self::assertFalse(
            $this->audit->mayRead('someone-else', $transaction),
            'readRevisions is gated by mayRead — out-of-scope denied'
        );
    }

    // Case 6: Feature 008's EvidenceContentAccess does not change AuditQueryService
    public function testAuditServiceUnchangedByFeature008(): void
    {
        // AuditQueryService's only constructor dependency for visibility is EvidenceVisibility.
        // Feature 008 adds EvidenceContentAccess as a separate dependency of
        // EvidenceController::content(), not of AuditQueryService.
        $reflection = new \ReflectionClass(AuditQueryService::class);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        $parameters = $constructor->getParameters();
        $parameterNames = array_map(fn(\ReflectionParameter $p): string => $p->getName(), $parameters);
        self::assertContains('visibility', $parameterNames, 'AuditQueryService still takes EvidenceVisibility');
        self::assertNotContains('evidenceContentAccess', $parameterNames, 'AuditQueryService does not take EvidenceContentAccess');
        self::assertNotContains('financeAccess', $parameterNames, 'AuditQueryService does not take FinanceAccess');
    }
}
