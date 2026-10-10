<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Reporting;

use InvalidArgumentException;
use OCA\PettyCash\Application\Reporting\ExportService;
use OCA\PettyCash\Infrastructure\Export\CsvExporter;
use OCA\PettyCash\Infrastructure\Export\EvidenceArchiveExporter;
use OCA\PettyCash\Infrastructure\Export\XlsxExporter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryPrivateFileStore;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransientExportStore;
use PHPUnit\Framework\TestCase;

/**
 * T366 / FR-067: `ExportService::generateCompleteSheetXlsx()` builds the six-sheet layout.
 *
 * ## What this covers and what it deliberately does not
 *
 * The *grouping* — which rows land in which of the six tables for a real sheet — is
 * `CompleteSheetExport`'s job and is proven over live HTTP in
 * `tests/Contract/Controller/CompleteSheetExportContractTest.php`, where a real sheet, real
 * transactions and a real summary query exist. That coverage cannot live here: the groups arrive as
 * plain arrays, and asserting their content would be asserting the fixture, not the code.
 *
 * What *can* be wrong here, and only here: the layout validation (a group missing a column the
 * contract names must fail naming the gap), the workbook actually containing six sheets rather than
 * one, and the reported counts describing the six tables rather than the first. Each is asserted by
 * reading the produced bytes back, never from the builder's own report.
 */
final class CompleteSheetXlsxTest extends TestCase
{
    private InMemoryTransientExportStore $store;
    private ExportService $exports;

    protected function setUp(): void
    {
        $this->store = new InMemoryTransientExportStore();
        $this->exports = new ExportService(
            new CsvExporter(),
            new XlsxExporter($this->store),
            new EvidenceArchiveExporter($this->store),
            $this->store,
            new InMemoryPrivateFileStore()
        );
    }

    public function testACompleteSheetBuildsAllSixTablesInLayoutOrder(): void
    {
        $result = $this->exports->generateCompleteSheetXlsx($this->rowGroups());

        self::assertSame(XlsxExporter::MEDIA_TYPE, $result['mediaType']);
        self::assertSame('xlsx', $result['format']);

        $names = $this->withWorkbook($result['artifactName'], static fn ($book) => $book->getSheetNames());
        self::assertSame(
            ['Sheet', 'Transactions', 'Decisions', 'Revisions', 'Audit', 'Evidence'],
            $names,
            'the artifact must carry the contract layout, not a flat Rows sheet'
        );
    }

    public function testRowCountSumsEveryTableRatherThanTheFirst(): void
    {
        // Two transactions, one decision, no audit or evidence rows: 1 + 2 + 1 = 4. A count of the
        // first table alone would answer 1, and a count of the transactions table alone 2 — both
        // wrong, and both invisible without groups of deliberately different sizes.
        $result = $this->exports->generateCompleteSheetXlsx($this->rowGroups());

        self::assertSame(4, $result['rowCount']);
    }

    public function testAGroupMissingAContractColumnFailsNamingTheGap(): void
    {
        $groups = $this->rowGroups();
        unset($groups['Transactions'][0]['amountMinor']);

        try {
            $this->exports->generateCompleteSheetXlsx($groups);
            self::fail('a group missing a layout column must be refused, not built short');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString(
                'Transactions.amountMinor',
                $e->getMessage(),
                'the refusal must name the table and the column, so the assembler can fix it'
            );
        }
    }

    public function testASheetWithNoTransactionsStillBuilds(): void
    {
        // The Sheet table always has its one row; every other group may be empty. An empty group is
        // legitimate — a sheet with no decisions yet — and still needs its columns, which the layout
        // supplies. Refusing it would make a freshly created sheet unexportable.
        $groups = $this->rowGroups();
        foreach (['Transactions', 'Decisions', 'Revisions', 'Audit', 'Evidence'] as $name) {
            $groups[$name] = [];
        }

        $result = $this->exports->generateCompleteSheetXlsx($groups);

        self::assertSame(1, $result['rowCount']);
        $names = $this->withWorkbook($result['artifactName'], static fn ($book) => $book->getSheetNames());
        self::assertCount(6, $names);
    }

    /**
     * @return array<string, list<array<string, scalar|null>>>
     */
    private function rowGroups(): array
    {
        return [
            'Sheet' => [[
                'sheetPublicId' => 'sheet-1',
                'title' => 'March fuel',
                'ownerUid' => 'purchaser-1',
                'ownerDisplayName' => 'Purchaser One',
                'currencyCode' => 'IRR',
                'status' => 'OPEN',
                'createdAt' => new \DateTimeImmutable('2026-03-01T00:00:00+00:00'),
                'firstSubmittedAt' => null,
                'currentTotalMinor' => 5000,
                'submittedTotalMinor' => null,
                'effectiveApprovedMinor' => 0,
                'effectiveRejectedMinor' => 0,
                'effectivePendingMinor' => 5000,
                'costCenterApprovedMinor' => 0,
                'seniorApprovedMinor' => 0,
                'financeApprovedMinor' => 0,
            ]],
            'Transactions' => [
                [
                    'transactionPublicId' => 'tx-1',
                    'revisionNumber' => 1,
                    'expenseDate' => new \DateTimeImmutable('2026-03-02'),
                    'amountMinor' => 3000,
                    'description' => 'Fuel',
                    'costCenterCode' => 'CC-100',
                    'costCenterName' => 'Engineering',
                    'expenseTypeTitle' => 'Travel',
                    'currentStage' => 'COST_CENTER',
                    'currentStatus' => 'SUBMITTED',
                ],
                [
                    'transactionPublicId' => 'tx-2',
                    'revisionNumber' => 1,
                    'expenseDate' => new \DateTimeImmutable('2026-03-03'),
                    'amountMinor' => 2000,
                    'description' => 'Tolls',
                    'costCenterCode' => 'CC-100',
                    'costCenterName' => 'Engineering',
                    'expenseTypeTitle' => 'Travel',
                    'currentStage' => 'COST_CENTER',
                    'currentStatus' => 'SUBMITTED',
                ],
            ],
            'Decisions' => [[
                'decisionPublicId' => 'dec-1',
                'transactionPublicId' => 'tx-1',
                'stage' => 'COST_CENTER',
                'outcome' => 'APPROVED',
                'actorUid' => 'manager-1',
                'actorDisplayName' => 'Manager One',
                'actorRole' => 'COST_CENTER_MANAGER',
                'explanation' => null,
                'decidedAt' => new \DateTimeImmutable('2026-03-04T10:00:00+00:00'),
            ]],
            'Revisions' => [],
            'Audit' => [],
            'Evidence' => [],
        ];
    }

    /**
     * @template T
     * @param callable(\PhpOffice\PhpSpreadsheet\Spreadsheet): T $fn
     * @return T
     */
    private function withWorkbook(string $artifactName, callable $fn): mixed
    {
        // `IOFactory` has no `loadFromString`, so the bytes go to a temporary file removed
        // afterwards — the same shape `XlsxExporterTest::withWorkbook()` uses, for the same reason.
        $path = tempnam(sys_get_temp_dir(), 't366_');
        self::assertIsString($path);
        try {
            file_put_contents($path, $this->store->artifactBytes($artifactName));
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            try {
                return $fn($book);
            } finally {
                $book->disconnectWorksheets();
            }
        } finally {
            @unlink($path);
            $this->store->delete($artifactName);
        }
    }
}