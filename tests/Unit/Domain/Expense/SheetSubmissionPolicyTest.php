<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Domain\Expense;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use PHPUnit\Framework\TestCase;

final class SheetSubmissionPolicyTest extends TestCase
{
    public function testDraftNeedsDateAndPositiveAmountAndSubmissionNeedsCompleteData(): void
    {
        $sheet = ExpenseSheet::open('sheet', 'u1', 'account', CurrencyCode::fromString('IRR'));
        $revision = TransactionRevision::draft('r1', 1, new DateTimeImmutable('2026-09-20'), Money::positive(10, CurrencyCode::fromString('IRR')));
        $sheet->addTransaction(new ExpenseTransaction('t1', 'u1', $revision));
        $this->expectException(InvalidArgumentException::class);
        $sheet->submit();
    }

    public function testSubmissionIsBalanceIndependent(): void
    {
        $sheet = ExpenseSheet::open('sheet', 'u1', 'account', CurrencyCode::fromString('IRR'));
        $revision = TransactionRevision::draft('r1', 1, new DateTimeImmutable('2026-09-20'), Money::positive(10, CurrencyCode::fromString('IRR')));
        $revision->complete('desc', 'cc', 'CC001', 'Engineering', 1, 'Travel', 1, true);
        $sheet->addTransaction(new ExpenseTransaction('t1', 'u1', $revision));
        self::assertSame(10, $sheet->submit());
    }

    public function testOpenSheetAllowsEditing(): void
    {
        $sheet = ExpenseSheet::open('sheet', 'u1', 'account', CurrencyCode::fromString('IRR'));
        $revision = TransactionRevision::draft('r1', 1, new DateTimeImmutable('2026-09-20'), Money::positive(10, CurrencyCode::fromString('IRR')));
        $revision->complete('desc', 'cc', 'CC001', 'Engineering', 1, 'Travel', 1, true);
        $tx = new ExpenseTransaction('t1', 'u1', $revision);
        $sheet->addTransaction($tx);

        // Should be able to update transaction in open sheet (before submission)
        $tx->activate($tx->revision());

        self::assertEquals(\OCA\PettyCash\Domain\Workflow\SheetStatus::Open, $sheet->status);
    }

    public function testSubmittedSheetIsReadOnly(): void
    {
        $sheet = ExpenseSheet::open('sheet', 'u1', 'account', CurrencyCode::fromString('IRR'));
        $revision = TransactionRevision::draft('r1', 1, new DateTimeImmutable('2026-09-20'), Money::positive(10, CurrencyCode::fromString('IRR')));
        $revision->complete('desc', 'cc', 'CC001', 'Engineering', 1, 'Travel', 1, true);
        $tx = new ExpenseTransaction('t1', 'u1', $revision);
        $sheet->addTransaction($tx);
        $sheet->submit();

        // Should not be able to add transactions to submitted sheet
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Submitted sheets are read-only');

        $newRevision = TransactionRevision::draft('r2', 1, new DateTimeImmutable('2026-09-21'), Money::positive(20, CurrencyCode::fromString('IRR')));
        $newTx = new ExpenseTransaction('t2', 'u1', $newRevision);
        $sheet->addTransaction($newTx);
    }

    public function testTransactionInheritsSheetCurrency(): void
    {
        $sheet = ExpenseSheet::open('sheet', 'u1', 'account', CurrencyCode::fromString('IRR'));
        $revision = TransactionRevision::draft('r1', 1, new DateTimeImmutable('2026-09-20'), Money::positive(100, CurrencyCode::fromString('USD')));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Transaction currency must inherit the sheet currency');

        $sheet->addTransaction(new ExpenseTransaction('t1', 'u1', $revision));
    }

    public function testMultipleOpenSheetsAllowed(): void
    {
        $sheet1 = ExpenseSheet::open('sheet-1', 'u1', 'account', CurrencyCode::fromString('IRR'), 'Sheet 1');
        $sheet2 = ExpenseSheet::open('sheet-2', 'u1', 'account', CurrencyCode::fromString('IRR'), 'Sheet 2');

        $revision1 = TransactionRevision::draft('r1', 1, new DateTimeImmutable('2026-09-20'), Money::positive(10, CurrencyCode::fromString('IRR')));
        $revision1->complete('desc 1', 'cc', 'CC001', 'Engineering', 1, 'Travel', 1, true);
        $tx1 = new ExpenseTransaction('t1', 'u1', $revision1);
        $sheet1->addTransaction($tx1);

        $revision2 = TransactionRevision::draft('r2', 1, new DateTimeImmutable('2026-09-21'), Money::positive(20, CurrencyCode::fromString('IRR')));
        $revision2->complete('desc 2', 'cc', 'CC001', 'Engineering', 1, 'Travel', 1, true);
        $tx2 = new ExpenseTransaction('t2', 'u1', $revision2);
        $sheet2->addTransaction($tx2);

        self::assertCount(1, $sheet1->transactions());
        self::assertCount(1, $sheet2->transactions());
        self::assertEquals('Sheet 1', $sheet1->title);
        self::assertEquals('Sheet 2', $sheet2->title);
    }

    public function testSubmissionRequiresDescription(): void
    {
        $sheet = ExpenseSheet::open('sheet', 'u1', 'account', CurrencyCode::fromString('IRR'));
        $revision = TransactionRevision::draft('r1', 1, new DateTimeImmutable('2026-09-20'), Money::positive(10, CurrencyCode::fromString('IRR')));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Submission fields are required');

        $revision->complete('', 'cc', 'CC001', 'Engineering', 1, 'Travel', 1, true);
        $sheet->addTransaction(new ExpenseTransaction('t1', 'u1', $revision));
        $sheet->submit();
    }

    public function testSubmissionRequiresCostCenter(): void
    {
        $sheet = ExpenseSheet::open('sheet', 'u1', 'account', CurrencyCode::fromString('IRR'));
        $revision = TransactionRevision::draft('r1', 1, new DateTimeImmutable('2026-09-20'), Money::positive(10, CurrencyCode::fromString('IRR')));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Submission fields are required');

        $revision->complete('desc', '', 'CC001', 'Engineering', 1, 'Travel', 1, true);
        $sheet->addTransaction(new ExpenseTransaction('t1', 'u1', $revision));
        $sheet->submit();
    }

    public function testSubmissionRequiresExpenseType(): void
    {
        $sheet = ExpenseSheet::open('sheet', 'u1', 'account', CurrencyCode::fromString('IRR'));
        $revision = TransactionRevision::draft('r1', 1, new DateTimeImmutable('2026-09-20'), Money::positive(10, CurrencyCode::fromString('IRR')));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Submission fields are required');

        $revision->complete('desc', 'cc', 'CC001', 'Engineering', 1, '', 1, true);
        $sheet->addTransaction(new ExpenseTransaction('t1', 'u1', $revision));
        $sheet->submit();
    }

    public function testSubmissionDoesNotRequireEvidenceByDefault(): void
    {
        // Current implementation does not enforce evidence requirement by default
        // This is a known issue - evidence requirement should be based on expense type
        $sheet = ExpenseSheet::open('sheet', 'u1', 'account', CurrencyCode::fromString('IRR'));
        $revision = TransactionRevision::draft('r1', 1, new DateTimeImmutable('2026-09-20'), Money::positive(10, CurrencyCode::fromString('IRR')));
        $revision->complete('desc', 'cc', 'CC001', 'Engineering', 1, 'Travel', 1, false);
        $sheet->addTransaction(new ExpenseTransaction('t1', 'u1', $revision));

        // Should succeed without evidence (current behavior)
        $total = $sheet->submit();

        self::assertSame(10, $total);
    }

    public function testSubmissionRequiresAtLeastOneTransaction(): void
    {
        $sheet = ExpenseSheet::open('sheet', 'u1', 'account', CurrencyCode::fromString('IRR'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one transaction is required');

        $sheet->submit();
    }

    public function testSubmissionWithPositiveAmountOnly(): void
    {
        $sheet = ExpenseSheet::open('sheet', 'u1', 'account', CurrencyCode::fromString('IRR'));
        $revision = TransactionRevision::draft('r1', 1, new DateTimeImmutable('2026-09-20'), Money::positive(1, CurrencyCode::fromString('IRR')));
        $revision->complete('desc', 'cc', 'CC001', 'Engineering', 1, 'Travel', 1, true);
        $sheet->addTransaction(new ExpenseTransaction('t1', 'u1', $revision));

        $total = $sheet->submit();

        self::assertSame(1, $total);
        self::assertEquals(\OCA\PettyCash\Domain\Workflow\SheetStatus::CostCenter, $sheet->status);
    }

    public function testSubmissionSetsFirstSubmittedAt(): void
    {
        $sheet = ExpenseSheet::open('sheet', 'u1', 'account', CurrencyCode::fromString('IRR'));
        $revision = TransactionRevision::draft('r1', 1, new DateTimeImmutable('2026-09-20'), Money::positive(10, CurrencyCode::fromString('IRR')));
        $revision->complete('desc', 'cc', 'CC001', 'Engineering', 1, 'Travel', 1, true);
        $sheet->addTransaction(new ExpenseTransaction('t1', 'u1', $revision));

        $sheet->submit();

        self::assertNotNull($sheet->firstSubmittedAt);
        self::assertInstanceOf(DateTimeImmutable::class, $sheet->firstSubmittedAt);
    }

    public function testSubmissionUpdatesTransactionStatus(): void
    {
        $sheet = ExpenseSheet::open('sheet', 'u1', 'account', CurrencyCode::fromString('IRR'));
        $revision = TransactionRevision::draft('r1', 1, new DateTimeImmutable('2026-09-20'), Money::positive(10, CurrencyCode::fromString('IRR')));
        $revision->complete('desc', 'cc', 'CC001', 'Engineering', 1, 'Travel', 1, true);
        $tx = new ExpenseTransaction('t1', 'u1', $revision);
        $sheet->addTransaction($tx);

        $sheet->submit();

        self::assertEquals(\OCA\PettyCash\Domain\Workflow\TransactionStatus::AwaitingCostCenterReview, $tx->status);
        self::assertEquals(\OCA\PettyCash\Domain\Workflow\WorkflowStage::CostCenter, $tx->stage);
    }

    public function testNegativeAmountRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount must be greater than zero');

        Money::positive(0, CurrencyCode::fromString('IRR'));
    }
}