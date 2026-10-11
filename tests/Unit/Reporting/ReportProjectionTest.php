<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Reporting;

use DateTimeImmutable;
use OCA\PettyCash\Application\Reporting\ReportProjection;
use PHPUnit\Framework\TestCase;

/**
 * T315: the report and export row projections share one definition.
 *
 * The failure mode is T307-shaped: two producers each declare the row
 * vocabulary independently, so a column added to one and not the other makes
 * a facet answerable on the export and refused on the report with no test
 * failing. These tests fail on exactly that drift.
 */
final class ReportProjectionTest extends TestCase
{
    public function testSheetBaseColumnsAreTheSevenBothEndpointsAgreeOn(): void
    {
        self::assertSame(
            ['id', 'title', 'ownerUid', 'status', 'currency', 'submittedTotalMinor', 'createdAt'],
            ReportProjection::SHEET_BASE_COLUMNS
        );
    }

    public function testSheetColumnsAreBasePlusTheThreeStageFigures(): void
    {
        self::assertSame(
            [
                'id', 'title', 'ownerUid', 'status', 'currency', 'submittedTotalMinor',
                'costCenterApprovedMinor', 'seniorApprovedMinor', 'financeApprovedMinor', 'createdAt',
            ],
            ReportProjection::SHEET_COLUMNS
        );
    }

    public function testTransactionColumnsAreTheTwelveBothEndpointsAgreeOn(): void
    {
        self::assertSame(
            [
                'id', 'ownerUid', 'stage', 'status', 'sheetId', 'createdAt',
                'costCenterDbId', 'costCenterCode', 'expenseTypeDbId', 'expenseTypeTitle', 'amountMinor', 'currency',
            ],
            ReportProjection::TRANSACTION_COLUMNS
        );
    }

    public function testSheetRowKeysMatchBaseColumnsInOrder(): void
    {
        $row = ReportProjection::sheetRow($this->sheet());
        self::assertSame(ReportProjection::SHEET_BASE_COLUMNS, array_keys($row));
    }

    public function testSheetRowWithTotalsKeysMatchSheetColumnsInOrder(): void
    {
        $row = ReportProjection::sheetRowWithTotals($this->sheet(), [
            'costCenterApprovedMinor' => 1,
            'seniorApprovedMinor' => 2,
            'financeApprovedMinor' => 3,
        ]);
        self::assertSame(ReportProjection::SHEET_COLUMNS, array_keys($row));
        self::assertSame(1, $row['costCenterApprovedMinor']);
        self::assertSame(2, $row['seniorApprovedMinor']);
        self::assertSame(3, $row['financeApprovedMinor']);
    }

    public function testTransactionRowKeysMatchTransactionColumnsInOrder(): void
    {
        $row = ReportProjection::transactionRow($this->transaction());
        self::assertSame(ReportProjection::TRANSACTION_COLUMNS, array_keys($row));
    }

    public function testExportControllerSharesTheProjection(): void
    {
        // Source scan, not reflection: controller classes extend Nextcloud's
        // OCSController, which is not autoloadable in the unit-test context
        // (no unit test loads a controller class), so reflecting on one
        // fatals. The invariant — no column literals of its own — is what
        // matters, and it is checkable as text.
        $source = (string) file_get_contents(__DIR__ . '/../../../lib/Controller/ExportController.php');
        self::assertStringContainsString('ReportProjection::SHEET_COLUMNS', $source);
        self::assertStringContainsString('ReportProjection::TRANSACTION_COLUMNS', $source);
        self::assertStringContainsString('ReportProjection::SHEET_NUMERIC_COLUMNS', $source);
        self::assertStringContainsString('ReportProjection::sheetRowWithTotals', $source);
        self::assertStringContainsString('ReportProjection::transactionRow', $source);
        self::assertDoesNotMatchRegularExpression(
            "/private const (SHEET_COLUMNS|TRANSACTION_COLUMNS|SHEET_NUMERIC_COLUMNS) = \\[/",
            $source,
            'ExportController must not declare its own column literals; they live in ReportProjection'
        );
    }

    public function testReportControllerReferencesTheProjection(): void
    {
        // ReportController holds no column literals of its own: both row shapes
        // come from ReportProjection. A literal reintroduction fails here via
        // the source scan, not via a behaviour change noticed months later.
        $source = (string) file_get_contents(__DIR__ . '/../../../lib/Controller/ReportController.php');
        self::assertStringContainsString('ReportProjection::sheetRow', $source);
        self::assertStringContainsString('ReportProjection::transactionRow', $source);
        self::assertStringContainsString('ReportProjection::SHEET_BASE_COLUMNS', $source);
        self::assertStringContainsString('ReportProjection::TRANSACTION_COLUMNS', $source);
    }

    private function sheet(): object
    {
        return new class() {
            public string $publicId = 'sheet-1';
            public string $title = 'Title';
            public string $ownerUid = 'purchaser';
            public \OCA\PettyCash\Domain\Workflow\SheetStatus $status = \OCA\PettyCash\Domain\Workflow\SheetStatus::Open;
            public string $currency = 'IRR';
            public int $submittedTotalMinor = 50000;
            public ?\DateTimeImmutable $createdAt;

            public function __construct()
            {
                $this->createdAt = new DateTimeImmutable('2026-10-01T00:00:00+00:00');
            }
        };
    }

    private function transaction(): object
    {
        $revision = new class() {
            public ?int $costCenterDbId = 7;
            public string $costCenterCode = 'E2E';
            public ?int $expenseTypeDbId = 9;
            public function expenseTypeTitle(): string
            {
                return 'Travel';
            }
            public function amount(): object
            {
                return new class() {
                    public function minorUnits(): int
                    {
                        return 50000;
                    }
                    public function currency(): object
                    {
                        return new class() {
                            public function __toString(): string
                            {
                                return 'IRR';
                            }
                        };
                    }
                };
            }
        };

        return new class($revision) {
            public string $publicId = 'tx-1';
            public string $ownerUid = 'purchaser';
            public \OCA\PettyCash\Domain\Workflow\WorkflowStage $stage = \OCA\PettyCash\Domain\Workflow\WorkflowStage::CostCenter;
            public \OCA\PettyCash\Domain\Workflow\TransactionStatus $status = \OCA\PettyCash\Domain\Workflow\TransactionStatus::Draft;
            public string $sheetId = 'sheet-1';
            public ?\DateTimeImmutable $createdAt;
            private object $revision;

            public function __construct(object $revision)
            {
                $this->revision = $revision;
                $this->createdAt = new DateTimeImmutable('2026-10-01T00:00:00+00:00');
            }

            public function revision(): object
            {
                return $this->revision;
            }
        };
    }
}
