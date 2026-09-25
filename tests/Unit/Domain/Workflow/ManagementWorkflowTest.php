<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Domain\Workflow;

use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ManagementWorkflowTest extends TestCase
{
    private function createSubmittedSheet(): ExpenseSheet
    {
        $purchaserUid = 'test-purchaser';
        $currencyCode = CurrencyCode::fromString('IRR');

        $sheet = ExpenseSheet::open('sheet-1', $purchaserUid, 'acc-1', $currencyCode, 'Test Sheet');

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

        return $sheet;
    }

    public function testCostCenterApproveAdvancesToSenior(): void
    {
        $sheet = $this->createSubmittedSheet();
        $tx = $sheet->transactions()[0];

        // Cost center manager approves
        $tx->recordDecision(WorkflowStage::CostCenter, Decision::Approve, 'Approved', 'manager-1');

        self::assertEquals(WorkflowStage::Senior, $tx->stage);
        self::assertEquals(TransactionStatus::CostCenterApproved, $tx->status);
    }

    public function testCostCenterRejectRequiresExplanation(): void
    {
        $sheet = $this->createSubmittedSheet();
        $tx = $sheet->transactions()[0];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Explanation required');

        // Reject without explanation should fail
        $tx->recordDecision(WorkflowStage::CostCenter, Decision::Reject, '', 'manager-1');
    }

    public function testCostCenterRejectWithExplanation(): void
    {
        $sheet = $this->createSubmittedSheet();
        $tx = $sheet->transactions()[0];

        // Cost center manager rejects with explanation
        $tx->recordDecision(WorkflowStage::CostCenter, Decision::Reject, 'Invalid expense', 'manager-1');

        self::assertEquals(WorkflowStage::Senior, $tx->stage);
        self::assertEquals(TransactionStatus::CostCenterRejected, $tx->status);
    }

    public function testCostCenterReturnRequiresExplanation(): void
    {
        $sheet = $this->createSubmittedSheet();
        $tx = $sheet->transactions()[0];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Explanation required');

        // Return without explanation should fail
        $tx->recordDecision(WorkflowStage::CostCenter, Decision::Return, '', 'manager-1');
    }

    public function testCostCenterReturnWithExplanation(): void
    {
        $sheet = $this->createSubmittedSheet();
        $tx = $sheet->transactions()[0];

        // Cost center manager returns with explanation
        $tx->recordDecision(WorkflowStage::CostCenter, Decision::Return, 'Missing evidence', 'manager-1');

        self::assertEquals(WorkflowStage::Correction, $tx->stage);
        self::assertEquals(TransactionStatus::AwaitingPurchaserCorrection, $tx->status);
    }

    public function testSeniorApproveAdvancesToFinance(): void
    {
        $sheet = $this->createSubmittedSheet();
        $tx = $sheet->transactions()[0];

        // Cost center approves
        $tx->recordDecision(WorkflowStage::CostCenter, Decision::Approve, 'OK', 'manager-1');
        
        // Senior approves
        $tx->recordDecision(WorkflowStage::Senior, Decision::Approve, 'Approved', 'senior-1');

        self::assertEquals(WorkflowStage::Finance, $tx->stage);
        self::assertEquals(TransactionStatus::AwaitingFinanceReview, $tx->status);
    }

    public function testSeniorMayDisagreeWithCostCenter(): void
    {
        $sheet = $this->createSubmittedSheet();
        $tx = $sheet->transactions()[0];

        // Cost center rejects
        $tx->recordDecision(WorkflowStage::CostCenter, Decision::Reject, 'Too expensive', 'manager-1');
        
        // Senior approves anyway (advisory decision visible but senior decides independently)
        $tx->recordDecision(WorkflowStage::Senior, Decision::Approve, 'Within budget', 'senior-1');

        self::assertEquals(WorkflowStage::Finance, $tx->stage);
        self::assertEquals(TransactionStatus::AwaitingFinanceReview, $tx->status);
        
        // Both decisions should be preserved
        $decisions = $tx->getDecisions();
        self::assertCount(2, $decisions);
        self::assertEquals(Decision::Reject, $decisions[0]->decision());
        self::assertEquals(Decision::Approve, $decisions[1]->decision());
    }

    public function testSeniorRejectAdvancesToFinance(): void
    {
        $sheet = $this->createSubmittedSheet();
        $tx = $sheet->transactions()[0];

        // Cost center approves
        $tx->recordDecision(WorkflowStage::CostCenter, Decision::Approve, 'OK', 'manager-1');
        
        // Senior rejects
        $tx->recordDecision(WorkflowStage::Senior, Decision::Reject, 'Policy violation', 'senior-1');

        self::assertEquals(WorkflowStage::Finance, $tx->stage);
        self::assertEquals(TransactionStatus::SeniorRejected, $tx->status);
    }

    public function testSeniorReturnRestartsAtCostCenter(): void
    {
        $sheet = $this->createSubmittedSheet();
        $tx = $sheet->transactions()[0];

        // Cost center approves
        $tx->recordDecision(WorkflowStage::CostCenter, Decision::Approve, 'OK', 'manager-1');
        
        // Senior returns
        $tx->recordDecision(WorkflowStage::Senior, Decision::Return, 'Need clarification', 'senior-1');

        self::assertEquals(WorkflowStage::Correction, $tx->stage);
        self::assertEquals(TransactionStatus::AwaitingPurchaserCorrection, $tx->status);
    }

    public function testSheetStatusEarliestOutstandingStage(): void
    {
        $purchaserUid = 'test-purchaser';
        $currencyCode = CurrencyCode::fromString('IRR');

        $sheet = ExpenseSheet::open('sheet-1', $purchaserUid, 'acc-1', $currencyCode, 'Test Sheet');

        // Transaction 1: submitted, awaiting cost center
        $revision1 = TransactionRevision::draft(
            'rev-1',
            1,
            new DateTimeImmutable('2026-09-20', new DateTimeZone('UTC')),
            Money::positive(50000, CurrencyCode::fromString('IRR'))
        );
        $revision1->complete('Desc 1', 'CC001', 'CC001', 'Engineering', 1, 'Office Supplies', 1, true);
        $tx1 = new ExpenseTransaction('tx-1', $purchaserUid, $revision1);
        $sheet->addTransaction($tx1);

        // Transaction 2: submitted, awaiting cost center
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

        // Both at cost center stage
        self::assertEquals(SheetStatus::CostCenter, $sheet->status);

        // Cost center approves tx1
        $tx1->recordDecision(WorkflowStage::CostCenter, Decision::Approve, 'OK', 'manager-1');
        $sheet->recalculateStatus();
        
        // Sheet status should still be CostCenter (earliest outstanding)
        self::assertEquals(SheetStatus::CostCenter, $sheet->status);

        // Cost center approves tx2
        $tx2->recordDecision(WorkflowStage::CostCenter, Decision::Approve, 'OK', 'manager-2');
        $sheet->recalculateStatus();
        
        // Now both at senior stage
        self::assertEquals(SheetStatus::Senior, $sheet->status);
    }

    public function testMixedTransactionProgress(): void
    {
        $purchaserUid = 'test-purchaser';
        $currencyCode = CurrencyCode::fromString('IRR');

        $sheet = ExpenseSheet::open('sheet-1', $purchaserUid, 'acc-1', $currencyCode, 'Test Sheet');

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

        // Cost center approves tx1, rejects tx2
        $tx1->recordDecision(WorkflowStage::CostCenter, Decision::Approve, 'OK', 'manager-1');
        $tx2->recordDecision(WorkflowStage::CostCenter, Decision::Reject, 'Invalid', 'manager-2');
        $sheet->recalculateStatus();

        // Both transactions have left cost center review (approved or rejected both advance to senior)
        self::assertEquals(SheetStatus::Senior, $sheet->status);

        // Senior reviews both
        $tx1->recordDecision(WorkflowStage::Senior, Decision::Approve, 'OK', 'senior-1');
        $tx2->recordDecision(WorkflowStage::Senior, Decision::Approve, 'Overruled', 'senior-1');
        $sheet->recalculateStatus();

        // Both at finance
        self::assertEquals(SheetStatus::Finance, $sheet->status);
    }

    public function testPriorDecisionsRemainImmutable(): void
    {
        $sheet = $this->createSubmittedSheet();
        $tx = $sheet->transactions()[0];

        // Cost center approves
        $tx->recordDecision(WorkflowStage::CostCenter, Decision::Approve, 'First decision', 'manager-1');
        $firstDecision = $tx->getDecisions()[0];

        // Senior rejects
        $tx->recordDecision(WorkflowStage::Senior, Decision::Reject, 'Second decision', 'senior-1');
        $secondDecision = $tx->getDecisions()[1];

        // First decision should be unchanged
        self::assertEquals(Decision::Approve, $firstDecision->decision());
        self::assertEquals('First decision', $firstDecision->explanation());
        self::assertEquals('manager-1', $firstDecision->actor()->userId());

        // Second decision should be recorded
        self::assertEquals(Decision::Reject, $secondDecision->decision());
        self::assertEquals('Second decision', $secondDecision->explanation());
        self::assertEquals('senior-1', $secondDecision->actor()->userId());
    }
}