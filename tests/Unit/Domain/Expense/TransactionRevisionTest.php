<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Domain\Expense;

use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class TransactionRevisionTest extends TestCase
{
    private function createDraftRevision(): TransactionRevision
    {
        return TransactionRevision::draft(
            'rev-1',
            1,
            new DateTimeImmutable('2026-09-20', new DateTimeZone('UTC')),
            Money::positive(50000, CurrencyCode::fromString('IRR'))
        );
    }

    public function testRevisionNumberStartsAtOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Revision number starts at one');

        TransactionRevision::draft(
            'rev-1',
            0,
            new DateTimeImmutable('2026-09-20', new DateTimeZone('UTC')),
            Money::positive(50000, CurrencyCode::fromString('IRR'))
        );
    }

    public function testRevisionNumberUniquePerTransaction(): void
    {
        $rev1 = $this->createDraftRevision();
        $rev2 = TransactionRevision::draft(
            'rev-2',
            2,
            new DateTimeImmutable('2026-09-21', new DateTimeZone('UTC')),
            Money::positive(30000, CurrencyCode::fromString('IRR'))
        );

        self::assertEquals(1, $rev1->number());
        self::assertEquals(2, $rev2->number());
    }

    public function testSubmittedRevisionImmutability(): void
    {
        $revision = $this->createDraftRevision();
        $revision->complete('Description', 'CC001', 'CC001', 'Engineering', 1, 'Office Supplies', 1, true);
        $revision->activate();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Submitted revisions are immutable');

        $revision->complete('New description', 'CC001', 'CC001', 'Engineering', 1, 'Office Supplies', 1, true);
    }

    public function testCorrectionOwnership(): void
    {
        $originalRevision = $this->createDraftRevision();
        $originalRevision->complete('Description', 'CC001', 'CC001', 'Engineering', 1, 'Office Supplies', 1, true);
        $originalRevision->activate();

        $correctionRevision = $originalRevision->correction('rev-2');

        self::assertEquals(2, $correctionRevision->number());
        self::assertEquals('rev-1', $correctionRevision->supersedes());
        self::assertEquals('Description', $correctionRevision->description());
        self::assertEquals('CC001', $correctionRevision->costCenterId());
        self::assertEquals('Office Supplies', $correctionRevision->expenseTypeTitle());
    }

    public function testCorrectionOnlyFromSubmittedRevision(): void
    {
        $draftRevision = $this->createDraftRevision();
        // Not activated

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Only submitted revisions can be corrected');

        $draftRevision->correction('rev-2');
    }

    public function testSupersedesLinkage(): void
    {
        $originalRevision = $this->createDraftRevision();
        $originalRevision->complete('Description', 'CC001', 'CC001', 'Engineering', 1, 'Office Supplies', 1, true);
        $originalRevision->activate();

        $correctionRevision = $originalRevision->correction('rev-2');

        self::assertEquals('rev-1', $correctionRevision->supersedes());
        self::assertEquals('rev-1', $originalRevision->id());
    }

    public function testActiveRevisionSwitching(): void
    {
        $originalRevision = $this->createDraftRevision();
        $originalRevision->complete('Description', 'CC001', 'CC001', 'Engineering', 1, 'Office Supplies', 1, true);
        $originalRevision->activate();

        $correctionRevision = $originalRevision->correction('rev-2');
        $correctionRevision->activate();

        self::assertTrue($originalRevision->isActive());
        self::assertTrue($correctionRevision->isActive());
        // The original revision remains active (immutable), new one is also active
    }

    public function testDraftRevisionIsNotActive(): void
    {
        $revision = $this->createDraftRevision();

        self::assertFalse($revision->isActive());
    }

    public function testActivatedRevisionIsActive(): void
    {
        $revision = $this->createDraftRevision();
        $revision->complete('Description', 'CC001', 'CC001', 'Engineering', 1, 'Office Supplies', 1, true);
        $revision->activate();

        self::assertTrue($revision->isActive());
    }

    public function testCorrectionCopiesCostCenterAndExpenseType(): void
    {
        $originalRevision = $this->createDraftRevision();
        $originalRevision->complete('Description', 'CC001', 'CC001', 'Engineering', 1, 'Office Supplies', 1, true);
        $originalRevision->costCenterDbId = 1;
        $originalRevision->costCenterCode = 'CC001';
        $originalRevision->costCenterName = 'Engineering';
        $originalRevision->expenseTypeDbId = 5;
        $originalRevision->activate();

        $correctionRevision = $originalRevision->correction('rev-2');

        self::assertEquals(1, $correctionRevision->costCenterDbId);
        self::assertEquals('CC001', $correctionRevision->costCenterCode);
        self::assertEquals('Engineering', $correctionRevision->costCenterName);
        self::assertEquals(5, $correctionRevision->expenseTypeDbId);
    }

    public function testCorrectionPreservesAmountAndDate(): void
    {
        $date = new DateTimeImmutable('2026-09-20', new DateTimeZone('UTC'));
        $amount = Money::positive(50000, CurrencyCode::fromString('IRR'));

        $originalRevision = TransactionRevision::draft(
            'rev-1',
            1,
            $date,
            $amount
        );
        $originalRevision->complete('Description', 'CC001', 'CC001', 'Engineering', 1, 'Office Supplies', 1, true);
        $originalRevision->activate();

        $correctionRevision = $originalRevision->correction('rev-2');

        self::assertEquals($date, $correctionRevision->expenseDate());
        self::assertEquals($amount->minorUnits(), $correctionRevision->amount()->minorUnits());
    }
}