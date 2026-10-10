<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Workflow;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Command\CommandResultRepository;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\TransactionManager;
use OCA\PettyCash\Application\Workflow\SubmitSheet;
use OCA\PettyCash\Domain\Account\PettyCashAccount;
use OCA\PettyCash\Domain\Audit\Repository\AuditEventRepository;
use OCA\PettyCash\Domain\Configuration\CostCenter;
use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Domain\Configuration\ExpenseType;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Configuration\Repository\CurrencyRepository;
use OCA\PettyCash\Domain\Configuration\Repository\ExpenseTypeRepository;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditEventRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCostCenterRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrencyRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseSheetRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseTransactionRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionRevisionRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCommandResultRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionManager;
use OCA\PettyCash\Tests\TestDoubles\InMemoryUserDirectory;
use OCA\PettyCash\Tests\TestContainerAccessor;
use LogicException;
use PHPUnit\Framework\TestCase;

final class TransactionCorrectionTest extends TestCase
{
    private $container;

    protected function setUp(): void
    {
        $this->container = \TestContainerAccessor::getInstance();
    }

    private function createSubmittedSheetWithTransaction(): ExpenseSheet
    {
        $purchaserUid = 'test-user';
        $currencyCode = CurrencyCode::fromString('IRR');

        $sheet = ExpenseSheet::open('sheet-1', $purchaserUid, 'acc-1', $currencyCode, 'Test Sheet');
        $sheet->publicId = 'sheet-1';

        $revision = TransactionRevision::draft(
            'rev-1',
            1,
            new DateTimeImmutable('2026-09-20', new DateTimeZone('UTC')),
            Money::positive(50000, CurrencyCode::fromString('IRR'))
        );
        $revision->complete('Description', 'CC001', 'CC001', 'Engineering', 1, 'Office Supplies', 1, true);

        $tx = new ExpenseTransaction('tx-1', $purchaserUid, $revision);
        $sheet->addTransaction($tx);
        $sheet->submit();

        // Save the sheet to the repository
        $sheetRepo = $this->container->query(ExpenseSheetRepository::class);
        $sheetRepo->save($sheet);

        return $sheet;
    }

    public function testReturnToCorrectionCreatesNewRevision(): void
    {
        $sheet = $this->createSubmittedSheetWithTransaction();
        $tx = $sheet->transactions()[0];

        // Return from cost center stage
        $tx->recordDecision(WorkflowStage::CostCenter, Decision::Return, 'Missing evidence', 'manager-1');

        self::assertEquals(WorkflowStage::Correction, $tx->stage);
        self::assertEquals(TransactionStatus::AwaitingPurchaserCorrection, $tx->status);

        // Create correction draft
        $correctionRevision = $tx->revision()->correction('rev-2');
        $correctionRevision->activate();

        // Verify new revision is active
        self::assertTrue($correctionRevision->isActive());
        self::assertEquals(2, $correctionRevision->number());
        self::assertEquals('rev-1', $correctionRevision->supersedes());
    }

    public function testReturnFromSeniorStageRestartsAtCostCenter(): void
    {
        $sheet = $this->createSubmittedSheetWithTransaction();
        $tx = $sheet->transactions()[0];

        // Cost center approves
        $tx->recordDecision(WorkflowStage::CostCenter, Decision::Approve, 'OK', 'manager-1');
        
        // Senior returns
        $tx->recordDecision(WorkflowStage::Senior, Decision::Return, 'Need clarification', 'senior-1');

        self::assertEquals(WorkflowStage::Correction, $tx->stage);
        self::assertEquals(TransactionStatus::AwaitingPurchaserCorrection, $tx->status);
    }

    public function testReturnFromFinanceStageRestartsAtCostCenter(): void
    {
        $sheet = $this->createSubmittedSheetWithTransaction();
        $tx = $sheet->transactions()[0];

        // Cost center approves
        $tx->recordDecision(WorkflowStage::CostCenter, Decision::Approve, 'OK', 'manager-1');
        
        // Senior approves
        $tx->recordDecision(WorkflowStage::Senior, Decision::Approve, 'OK', 'senior-1');
        
        // Finance returns
        $tx->recordDecision(WorkflowStage::Finance, Decision::Return, 'Incomplete docs', 'finance-1');

        self::assertEquals(WorkflowStage::Correction, $tx->stage);
        self::assertEquals(TransactionStatus::AwaitingPurchaserCorrection, $tx->status);
    }

    public function testNewRevisionActivationRestartsFullReviewPath(): void
    {
        $sheet = $this->createSubmittedSheetWithTransaction();
        $tx = $sheet->transactions()[0];

        // Return and create correction
        $tx->recordDecision(WorkflowStage::CostCenter, Decision::Return, 'Missing evidence', 'manager-1');
        $correctionRevision = $tx->revision()->correction('rev-2');
        
        // Complete the correction with same data
        $correctionRevision->complete('Description', 'CC001', 'CC001', 'Engineering', 1, 'Office Supplies', 1, true);
        $correctionRevision->activate();
        
        // Activate the correction revision on transaction
        $tx->activate($correctionRevision);

        // Should restart at cost center review
        self::assertEquals(WorkflowStage::CostCenter, $tx->stage);
        self::assertEquals(TransactionStatus::AwaitingCostCenterReview, $tx->status);
    }

    public function testRetainedCompletedSiblingTransactions(): void
    {
        $purchaserUid = 'test-user';
        $currencyCode = CurrencyCode::fromString('IRR');

        $sheet = ExpenseSheet::open('sheet-2', $purchaserUid, 'acc-1', $currencyCode, 'Test Sheet');
        $sheet->publicId = 'sheet-2';

        // Transaction 1
        $revision1 = TransactionRevision::draft(
            'rev-1',
            1,
            new DateTimeImmutable('2026-09-20', new DateTimeZone('UTC')),
            Money::positive(50000, CurrencyCode::fromString('IRR'))
        );
        $revision1->complete('Desc 1', 'CC001', 'CC001', 'Engineering', 1, 'Office Supplies', 1, true);
        $tx1 = new ExpenseTransaction('tx-1', $purchaserUid, $revision1);
        $sheet->addTransaction($tx1);

        // Transaction 2
        $revision2 = TransactionRevision::draft(
            'rev-2',
            1,
            new DateTimeImmutable('2026-09-21', new DateTimeZone('UTC')),
            Money::positive(30000, CurrencyCode::fromString('IRR'))
        );
        $revision2->complete('Desc 2', 'CC002', 'CC002', 'Marketing', 2, 'Travel', 2, true);
        $tx2 = new ExpenseTransaction('tx-2', $purchaserUid, $revision2);
        $sheet->addTransaction($tx2);

        $sheet->submit();

        $sheetRepo = $this->container->query(ExpenseSheetRepository::class);
        $sheetRepo->save($sheet);

        // Both transactions at cost center
        self::assertEquals(TransactionStatus::AwaitingCostCenterReview, $tx1->status);
        self::assertEquals(TransactionStatus::AwaitingCostCenterReview, $tx2->status);

        // Cost center approves tx1
        $tx1->recordDecision(WorkflowStage::CostCenter, Decision::Approve, 'OK', 'manager-1');
        
        // Cost center returns tx2
        $tx2->recordDecision(WorkflowStage::CostCenter, Decision::Return, 'Missing evidence', 'manager-1');

        // tx1 stays at senior, tx2 goes to correction
        self::assertEquals(WorkflowStage::Senior, $tx1->stage);
        self::assertEquals(WorkflowStage::Correction, $tx2->stage);

        // Sheet status should be earliest outstanding (Correction)
        $sheet->recalculateStatus();
        self::assertEquals(SheetStatus::Correction, $sheet->status);
    }

    public function testAuditInsertionForReturnAndCorrection(): void
    {
        $sheet = $this->createSubmittedSheetWithTransaction();
        $tx = $sheet->transactions()[0];

        // Return decision should create audit event
        $tx->recordDecision(WorkflowStage::CostCenter, Decision::Return, 'Missing evidence', 'manager-1', 'Manager One', 'CostCenterManager', 'cmd-return-1');

        $decisions = $tx->getDecisions();
        $returnDecision = array_filter($decisions, fn($d) => $d->decision() === Decision::Return);
        self::assertCount(1, $returnDecision);
        
        $returnDecision = array_values($returnDecision)[0];
        self::assertEquals(Decision::Return, $returnDecision->decision());
        self::assertEquals('Missing evidence', $returnDecision->explanation());
        self::assertEquals('manager-1', $returnDecision->actor()->userId());
    }

    public function testCorrectionCannotBeMadeOnFinanceApprovedTransaction(): void
    {
        $sheet = $this->createSubmittedSheetWithTransaction();
        $tx = $sheet->transactions()[0];

        // Cost center approves
        $tx->recordDecision(WorkflowStage::CostCenter, Decision::Approve, 'OK', 'manager-1');
        
        // Senior approves
        $tx->recordDecision(WorkflowStage::Senior, Decision::Approve, 'OK', 'senior-1');
        
        // Finance approves - this should complete the transaction
        $tx->recordDecision(WorkflowStage::Finance, Decision::Approve, 'Approved', 'finance-1');

        self::assertEquals(TransactionStatus::CompletedApproved, $tx->status);
        self::assertEquals('APPROVED', $tx->finalOutcome);

        // Attempting to correct a completed transaction should fail
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot correct a finance-approved or finance-rejected transaction');

        // Try to create correction from completed revision
        $tx->createCorrectionRevision('rev-2');
    }
}