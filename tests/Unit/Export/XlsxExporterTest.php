<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Export;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\PettyCash\Infrastructure\Export\XlsxExporter;
use OCA\PettyCash\Infrastructure\Export\XlsxSheet;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransientExportStore;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * T108: complete-sheet XLSX export per `contracts/exports.md`.
 *
 * ## The acceptance check, taken literally
 *
 * The contract's check is "XLSX contains the promised sheets and numeric values". Both halves are
 * asserted by **reading the produced workbook back** with PhpSpreadsheet and by unzipping it, never from
 * the exporter's own report. A layout or typing mistake that only the exporter could detect would be a
 * report agreeing with itself.
 *
 * ## The test that carries the task
 *
 * `testAnAmountIsStoredAsANumericCellAndNotAsText` is the one that matters. The contract asks XLSX for
 * "typed numeric/date cells" while explicitly letting CSV get away with machine values, and the reason is
 * that a spreadsheet *recalculates* a numeric cell. An amount written as text opens perfectly and cannot
 * be totalled — so a workbook that looks correct in a review is not a correct workbook, and only reading
 * the cell back distinguishes the two.
 */
final class XlsxExporterTest extends TestCase
{
    private InMemoryTransientExportStore $store;
    private XlsxExporter $exporter;

    protected function setUp(): void
    {
        $this->store = new InMemoryTransientExportStore();
        $this->exporter = new XlsxExporter($this->store);
    }

    // ===== The promised sheets =====

    public function testTheCompleteSheetLayoutIsTheContractsSixSheetsInOrder(): void
    {
        self::assertSame(
            ['Sheet', 'Transactions', 'Decisions', 'Revisions', 'Audit', 'Evidence'],
            XlsxExporter::COMPLETE_SHEET_LAYOUT
        );
    }

    public function testTheWorkbookContainsAllSixPromisedSheets(): void
    {
        $sheets = $this->completeSheetNames();

        self::assertSame(XlsxExporter::COMPLETE_SHEET_LAYOUT, $sheets);
    }

    public function testAnEmptySheetStillExistsWithItsHeaderRow(): void
    {
        // A sheet with no decisions yet is legitimate and must still be present, or a recipient cannot
        // tell "nothing was decided" from "this export is incomplete".
        $sheets = $this->completeSheetNames();

        self::assertContains(XlsxExporter::DECISIONS, $sheets);
        $header = $this->headerRow(XlsxExporter::DECISIONS);
        self::assertSame('decisionPublicId', $header[0]);
    }

    public function testEverySheetCarriesItsContractColumns(): void
    {
        foreach (XlsxExporter::COMPLETE_SHEET_COLUMNS as $name => $columns) {
            $expected = array_map(static fn (array $c): string => $c['name'], $columns);
            $actual = $this->headerRow($name);

            self::assertSame($expected, $actual, "sheet {$name} must carry its contract columns");
        }
    }

    // ===== Typed cells: the reason this format exists =====

    public function testAnAmountIsStoredAsANumericCellAndNotAsText(): void
    {
        // Row 1 is the header row, so the first data row is row 2. `currentTotalMinor` is column J.
        self::assertSame(
            DataType::TYPE_NUMERIC,
            $this->cellType(XlsxExporter::SHEET, 'J2'),
            'an amount written as text opens correctly and cannot be totalled'
        );
        self::assertSame(125000, $this->cellValue(XlsxExporter::SHEET, 'J2'));
        self::assertIsNumeric($this->cellCalculated(XlsxExporter::SHEET, 'J2'));
    }

    public function testANegativeAmountIsAlsoNumeric(): void
    {
        self::assertSame(DataType::TYPE_NUMERIC, $this->cellType(XlsxExporter::TRANSACTIONS, 'D2'));
        self::assertSame(-42000, $this->cellValue(XlsxExporter::TRANSACTIONS, 'D2'));
    }

    public function testADateIsStoredAsADateAndNotAsText(): void
    {
        self::assertSame(
            DataType::TYPE_NUMERIC,
            $this->cellType(XlsxExporter::SHEET, 'G2'),
            'a date is a serial number under the hood'
        );
        // Checked by converting the serial back with PhpSpreadsheet's own inverse, and separately by
        // asserting the number format. An earlier version of this test treated the serial as a Unix
        // timestamp, which is wrong by 36 years — an Excel serial counts from 1899-12-30 — and reported a
        // correct export as broken. The format matters as much as the serial: a serial with no date
        // format displays as a bare number, which is the same defect in different clothes.
        $serial = $this->cellValue(XlsxExporter::SHEET, 'G2');
        self::assertIsFloat($serial + 0.0, 'the cell must hold an Excel serial');
        self::assertSame(
            '2026-03-15 10:30',
            ExcelDate::excelToDateTimeObject((float) $serial, 'UTC')->format('Y-m-d H:i'),
            'the serial must round-trip to the date that was written'
        );
        self::assertSame(
            'yyyy-mm-dd hh:mm',
            $this->cellFormat(XlsxExporter::SHEET, 'G2'),
            'a date cell must carry a date number format, or it displays as a bare serial'
        );
    }

    public function testABooleanIsStoredAsABoolean(): void
    {
        self::assertSame(DataType::TYPE_BOOL, $this->cellType(XlsxExporter::EVIDENCE, 'H2'));
        self::assertTrue($this->cellValue(XlsxExporter::EVIDENCE, 'H2'));
    }

    public function testTextStaysText(): void
    {
        self::assertSame(DataType::TYPE_STRING, $this->cellType(XlsxExporter::SHEET, 'B2'));
        self::assertSame('برگهٔ خردید', $this->cellValue(XlsxExporter::SHEET, 'B2'));
    }

    public function testPersianTextSurvivesTheWorkbook(): void
    {
        self::assertSame('برگهٔ خردید', $this->cellValue(XlsxExporter::SHEET, 'B2'));
    }

    // ===== Totals are the point of the format =====

    public function testTheStageApprovedTotalsArePresentAsNumbers(): void
    {
        // FR-062 requires the three stage-approved totals as separate columns, which is the reason the
        // Sheet sheet carries them at all.
        foreach (['costCenterApprovedMinor', 'seniorApprovedMinor', 'financeApprovedMinor'] as $column) {
            self::assertContains($column, $this->headerRow(XlsxExporter::SHEET));
        }
    }

    // ===== The workbook is a real XLSX =====

    public function testThePersistedArtifactIsARealXlsxPackage(): void
    {
        $bytes = $this->artifactBytes();

        self::assertSame('PK', substr($bytes, 0, 2), 'an xlsx is a zip container');
    }

    public function testThePackageContainsThePartsExcelRequires(): void
    {
        $members = $this->zipMembers();

        // The minimum a reader needs. A workbook missing any of these opens as a repair prompt, which is
        // the failure mode a structural check has to catch and a unit test on the builder cannot.
        foreach (['[Content_Types].xml', 'xl/workbook.xml'] as $required) {
            self::assertContains($required, $members, "an xlsx package must contain {$required}");
        }
    }

    public function testEverySheetNameIsPresentInTheWorkbookPart(): void
    {
        $workbookXml = $this->zipRead('xl/workbook.xml');

        foreach (XlsxExporter::COMPLETE_SHEET_LAYOUT as $name) {
            self::assertStringContainsString(
                $name,
                (string) $workbookXml,
                "the workbook part must name the {$name} sheet"
            );
        }
    }

    public function testTheMediaTypeIsTheXlsxOne(): void
    {
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $this->exporter->mediaType()
        );
    }

    // ===== Row counts =====

    public function testRowCountsAreReportedPerSheet(): void
    {
        $result = $this->build($this->rows());

        self::assertSame(1, $result['rowCounts'][XlsxExporter::SHEET]);
        self::assertSame(1, $result['rowCounts'][XlsxExporter::TRANSACTIONS]);
        self::assertSame(0, $result['rowCounts'][XlsxExporter::DECISIONS]);
    }

    public function testTheReportedArtifactSizeMatchesWhatWasStored(): void
    {
        $result = $this->build($this->rows());

        self::assertSame($this->store->size($result['artifactName']), $result['artifactBytes']);
    }

    // ===== Refusals: a typed column that gets the wrong type =====

    public function testAStringInANumericColumnIsRefused(): void
    {
        $rows = $this->rows();
        $rows[XlsxExporter::SHEET][0]['currentTotalMinor'] = '125000';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sheet.currentTotalMinor is numeric');

        $this->exporter->build($this->exporter->completeSheet($rows));
    }

    public function testAStringInADateColumnIsRefused(): void
    {
        $rows = $this->rows();
        $rows[XlsxExporter::SHEET][0]['createdAt'] = '2026-03-15';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sheet.createdAt is a date');

        $this->exporter->build($this->exporter->completeSheet($rows));
    }

    public function testANonBooleanInABooleanColumnIsRefused(): void
    {
        $rows = $this->rows();
        $rows[XlsxExporter::EVIDENCE][0]['active'] = 'true';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Evidence.active is a boolean');

        $this->exporter->build($this->exporter->completeSheet($rows));
    }

    // ===== Refusals: a missing contract column =====

    public function testARowMissingAContractColumnIsRefused(): void
    {
        $rows = $this->rows();
        unset($rows[XlsxExporter::SHEET][0]['financeApprovedMinor']);

        self::assertContains(
            'Sheet.financeApprovedMinor',
            $this->exporter->missingColumns($rows)
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sheet.financeApprovedMinor');

        $this->exporter->completeSheet($rows);
    }

    public function testTheGapReportNamesEveryMissingColumnAtOnce(): void
    {
        $rows = $this->rows();
        unset($rows[XlsxExporter::SHEET][0]['financeApprovedMinor']);
        unset($rows[XlsxExporter::TRANSACTIONS][0]['currentStage']);

        $missing = $this->exporter->missingColumns($rows);

        // Reported as a list rather than one per run, so a caller can show the whole gap at once.
        self::assertCount(2, $missing);
    }

    public function testACompleteRowSetReportsNoGap(): void
    {
        self::assertSame([], $this->exporter->missingColumns($this->rows()));
    }

    public function testAnEmptySheetIsNotAGap(): void
    {
        $rows = $this->rows();
        $rows[XlsxExporter::DECISIONS] = [];

        self::assertSame([], $this->exporter->missingColumns($rows));
    }

    // ===== Refusals: bad workbook shape =====

    public function testAWorkbookWithNoSheetsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->exporter->build([]);
    }

    public function testASheetWithNoColumnsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new XlsxSheet('Empty', []);
    }

    public function testASheetNameExcelForbidsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('character Excel forbids');

        new XlsxSheet('Bad/Name', [['name' => 'a', 'type' => XlsxSheet::TYPE_TEXT]]);
    }

    public function testAnOverlongSheetNameIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('31-character limit');

        new XlsxSheet(str_repeat('x', 32), [['name' => 'a', 'type' => XlsxSheet::TYPE_TEXT]]);
    }

    public function testAnUnknownColumnTypeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown type');

        new XlsxSheet('Odd', [['name' => 'a', 'type' => 'currency']]);
    }

    // ===== Storage discipline, matching the evidence archive =====

    public function testTheWorkbookIsPersistedToTheTransientStore(): void
    {
        $result = $this->build($this->rows());

        self::assertTrue($this->store->exists($result['artifactName']));
        self::assertStringEndsWith('.xlsx', $result['artifactName']);
    }

    public function testNoScratchFileIsLeftBehind(): void
    {
        $this->build($this->rows());

        self::assertSame([], $this->store->survivingScratchFiles());
    }

    public function testNoScratchFileIsLeftBehindWhenTheBuildFails(): void
    {
        $sheets = $this->exporter->completeSheet($this->rows());
        // Break a cell mid-build so the writer throws after the scratch file exists.
        $sheets[0] = $sheets[0]->withRows([
            ['currentTotalMinor' => 'not a number'],
        ]);

        try {
            $this->exporter->build($sheets);
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame([], $this->store->survivingScratchFiles());
    }

    // ===== Helpers =====

    private function build(array $rows): array
    {
        return $this->exporter->build($this->exporter->completeSheet($rows));
    }

    private function artifactBytes(): string
    {
        $result = $this->build($this->rows());

        return $this->store->artifactBytes($result['artifactName']);
    }

    /** @return list<string> */
    private function completeSheetNames(): array
    {
        return $this->withWorkbook(static fn ($spreadsheet): array => array_map(
            static fn ($sheet): string => $sheet->getTitle(),
            $spreadsheet->getAllSheets()
        ));
    }

    /** @return list<string> */
    private function headerRow(string $sheetName): array
    {
        $columns = XlsxExporter::COMPLETE_SHEET_COLUMNS[$sheetName];

        return $this->withWorkbook(function ($book) use ($sheetName, $columns): array {
            $sheet = $this->sheetIn($book, $sheetName);

            return array_map(
                static fn (array $c): string => (string) $sheet->getCell(
                    Coordinate::stringFromColumnIndex(array_search($c, $columns, true) + 1) . '1'
                )->getValue(),
                $columns
            );
        });
    }

    /**
     * The data type of a cell, read inside the workbook's lifetime.
     *
     * Reads a scalar rather than returning a `Cell`, because returning the cell means handing back a
     * handle into a workbook the callback has already disconnected — which fails with
     * "must not be accessed before initialization", an error that says nothing about the export.
     */
    private function cellType(string $sheetName, string $coordinate): string
    {
        return $this->withWorkbook(function ($book) use ($sheetName, $coordinate): string {
            $sheet = $this->sheetIn($book, $sheetName);

            return (string) $sheet->getCell($coordinate)->getDataType();
        });
    }

    private function cellFormat(string $sheetName, string $coordinate): string
    {
        return $this->withWorkbook(fn ($book) => $this->sheetIn($book, $sheetName)
            ->getCell($coordinate)->getStyle()->getNumberFormat()->getFormatCode());
    }

    private function cellValue(string $sheetName, string $coordinate): mixed
    {
        return $this->withWorkbook(fn ($book) => $this->sheetIn($book, $sheetName)
            ->getCell($coordinate)->getValue());
    }

    private function cellCalculated(string $sheetName, string $coordinate): mixed
    {
        return $this->withWorkbook(fn ($book) => $this->sheetIn($book, $sheetName)
            ->getCell($coordinate)->getCalculatedValue());
    }

    private function sheetIn(
        \PhpOffice\PhpSpreadsheet\Spreadsheet $book,
        string $name
    ): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet {
        $sheet = $book->getSheetByName($name);
        if (!$sheet instanceof \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet) {
            throw new InvalidArgumentException("no sheet named {$name} in the workbook");
        }

        return $sheet;
    }

    /**
     * Load the persisted workbook, hand it to a callback, then clean both up.
     *
     * `IOFactory` has no `loadFromString`, so the bytes go to a temporary file that this removes
     * afterwards. Wrapping the whole thing in a callback rather than returning a live `Spreadsheet` also
     * removes a real hazard: a `Worksheet` holds a reference to its workbook, so the earlier version
     * disconnected the sheets in a `finally` and then returned a handle into a torn-down object.
     *
     * @template T
     * @param callable(\PhpOffice\PhpSpreadsheet\Spreadsheet): T $fn
     * @return T
     */
    private function withWorkbook(callable $fn): mixed
    {
        $path = tempnam(sys_get_temp_dir(), 'pettycash-xlsx-test-');
        self::assertNotFalse($path);
        file_put_contents($path, $this->artifactBytes());

        try {
            $spreadsheet = IOFactory::load($path);
            try {
                return $fn($spreadsheet);
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        } finally {
            @unlink($path);
        }
    }

    /** @return list<string> */
    private function zipMembers(): array
    {
        $path = $this->writeTemp($this->artifactBytes());

        try {
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                return [];
            }
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = (string) $zip->getNameIndex($i);
            }
            $zip->close();

            return $names;
        } finally {
            @unlink($path);
        }
    }

    private function zipRead(string $member): ?string
    {
        $path = $this->writeTemp($this->artifactBytes());

        try {
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                return null;
            }
            $contents = $zip->getFromName($member);
            $zip->close();

            return $contents === false ? null : $contents;
        } finally {
            @unlink($path);
        }
    }

    private function writeTemp(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pettycash-xlsx-test-');
        self::assertNotFalse($path);
        file_put_contents($path, $bytes);

        return $path;
    }

    /**
     * One row for every sheet the layout declares, with the right PHP type in every typed column.
     *
     * @return array<string, list<array<string, scalar|null>>>
     */
    private function rows(): array
    {
        $created = new DateTimeImmutable('2026-03-15T10:30:00+00:00');

        return [
            XlsxExporter::SHEET => [[
                'sheetPublicId' => 'sheet-1',
                'title' => 'برگهٔ خردید',
                'ownerUid' => 'purchaser-1',
                'ownerDisplayName' => 'خریدار',
                'currencyCode' => 'IRR',
                'status' => 'COST_CENTER',
                'createdAt' => $created,
                'firstSubmittedAt' => $created,
                'currentTotalMinor' => 125000,
                'submittedTotalMinor' => 125000,
                'effectiveApprovedMinor' => 125000,
                'effectiveRejectedMinor' => 0,
                'effectivePendingMinor' => 0,
                'costCenterApprovedMinor' => 125000,
                'seniorApprovedMinor' => 0,
                'financeApprovedMinor' => 0,
            ]],
            XlsxExporter::TRANSACTIONS => [[
                'transactionPublicId' => 'tx-1',
                'revisionNumber' => 1,
                'expenseDate' => $created,
                'amountMinor' => -42000,
                'description' => 'هزینهٔ ایاب و ذهاب',
                'costCenterCode' => 'CC-100',
                'costCenterName' => 'دفتر مرکزی',
                'expenseTypeTitle' => 'حمل و نقل',
                'currentStage' => 'COST_CENTER',
                'currentStatus' => 'AWAITING_COST_CENTER_REVIEW',
            ]],
            XlsxExporter::DECISIONS => [],
            XlsxExporter::REVISIONS => [[
                'revisionPublicId' => 'rev-1',
                'transactionPublicId' => 'tx-1',
                'revisionNumber' => 1,
                'active' => true,
                'expenseDate' => $created,
                'amountMinor' => -42000,
                'description' => 'هزینهٔ ایاب و ذهاب',
                'costCenterCode' => 'CC-100',
                'expenseTypeTitle' => 'حمل و نقل',
                'createdAt' => $created,
                'createdBy' => 'purchaser-1',
            ]],
            XlsxExporter::AUDIT => [[
                'eventPublicId' => 'evt-1',
                'occurredAt' => $created,
                'eventType' => 'SUBMISSION',
                'stage' => 'COST_CENTER',
                'actorUid' => 'purchaser-1',
                'actorDisplayName' => 'خریدار',
                'actorRole' => 'PURCHASER',
                'explanation' => null,
                'beforeJson' => '{}',
                'afterJson' => '{}',
            ]],
            XlsxExporter::EVIDENCE => [[
                'evidencePublicId' => 'ev-1',
                'transactionPublicId' => 'tx-1',
                'revisionNumber' => 1,
                'originatingRevisionPublicId' => 'rev-1',
                'originalName' => 'receipt.pdf',
                'mediaType' => 'application/pdf',
                'sizeBytes' => 2048,
                'active' => true,
                'uploaderUid' => 'purchaser-1',
                'uploaderDisplayName' => 'خریدار',
                'uploadedAt' => $created,
            ]],
        ];
    }
}
