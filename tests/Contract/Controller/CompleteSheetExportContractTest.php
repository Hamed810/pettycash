<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * T366 / FR-067: a single sheet exported as XLSX arrives as the six-table complete-sheet layout.
 *
 * ## What was wrong
 *
 * `XlsxExporter::completeSheet()` built the layout the contract's `exports.md` specifies — Sheet,
 * Transactions, Decisions, Revisions, Audit, Evidence — and nothing called it. Every XLSX went through
 * `generateXlsx()`, which builds one flat `Rows` sheet, so a caller asking for a complete sheet received
 * a dump carrying none of the relationships FR-067 exists to preserve.
 *
 * ## Why these assertions read the workbook back
 *
 * The response envelope reports `rowCount` and a media type, and both would agree with a flat artifact:
 * a one-table workbook is still well-formed XLSX. Only opening the artifact and naming its sheets
 * distinguishes the layout from the dump — the same reason `XlsxExporterTest` reads workbooks back
 * rather than trusting the builder's report.
 *
 * ## Why the draft fixture is enough
 *
 * A draft sheet has transactions but no decisions, and its audit and evidence groups may be empty — and
 * `completeSheet()` tolerates empty groups, supplying their columns from the layout. What matters here
 * is that the six tables exist in order and the rows that do exist link to each other by public id.
 * Driving a sheet through the whole workflow first would prove more and cost a full review cycle per
 * test; the grouping across stages is `CompleteSheetExport`'s unit-covered composition, not this
 * endpoint's contract.
 */
final class CompleteSheetExportContractTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
        ContractFixtures::prepare('draft');
    }

    public function testASingleSheetExportsAsSixTablesInLayoutOrder(): void
    {
        $book = $this->workbook([
            'resource' => 'sheet',
            'fileFormat' => 'xlsx',
            'ids' => [ContractFixtures::sheetId()],
        ]);

        try {
            self::assertSame(
                ['Sheet', 'Transactions', 'Decisions', 'Revisions', 'Audit', 'Evidence'],
                $book->getSheetNames(),
                'FR-067: a complete sheet export preserves relationships, and a flat Rows sheet carries none'
            );
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function testTheTablesLinkByPublicId(): void
    {
        $book = $this->workbook([
            'resource' => 'sheet',
            'fileFormat' => 'xlsx',
            'ids' => [ContractFixtures::sheetId()],
        ]);

        try {
            $sheetIds = $this->column($book, 'Sheet', 'A');
            self::assertContains(
                ContractFixtures::sheetId(),
                $sheetIds,
                'the Sheet table must carry the exported sheet; got: ' . json_encode($sheetIds)
            );

            $transactionIds = $this->column($book, 'Transactions', 'A');
            self::assertContains(
                ContractFixtures::transactionId(),
                $transactionIds,
                'the Transactions table must carry the sheet\'s transaction, linked by public id'
            );
        } finally {
            $book->disconnectWorksheets();
        }
    }

    /**
     * The routing boundary, pinned: the plural resource keeps the flat rows path.
     *
     * `resource: sheet` with exactly one id takes the complete-sheet path; anything else — several
     * sheets, another format, another resource — keeps `generateXlsx()`. A multi-sheet "complete"
     * workbook (one per sheet, or prefixed groups) is not specified, so asserting flat output for the
     * plural resource documents the boundary rather than leaving it to inference.
     */
    public function testThePluralResourceKeepsTheFlatRowsPath(): void
    {
        $book = $this->workbook([
            'resource' => 'sheets',
            'fileFormat' => 'xlsx',
            'ids' => [ContractFixtures::sheetId()],
        ]);

        try {
            self::assertSame(
                ['Rows'],
                $book->getSheetNames(),
                'only resource "sheet" with a single id takes the complete-sheet path'
            );
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function testAnUnknownSheetIsRefusedRatherThanExportedEmpty(): void
    {
        $response = $this->post([
            'resource' => 'sheet',
            'fileFormat' => 'xlsx',
            'ids' => [ContractFixtures::unknownId()],
        ]);

        self::assertSame(
            422,
            $response->getStatusCode(),
            'an unknown sheet must be refused — and refused identically to a foreign one, so the '
            . 'endpoint cannot be used to discover which sheet ids are real'
        );
    }

    // ===== T365 / FR-062: the flat sheet export carries the three stage figures =====

    /**
     * The flat (plural-resource) sheet export names the three stage-approved columns.
     *
     * The complete-sheet layout already declares them; the reachable flat artifact did not, so a user
     * exporting sheets received less than the layout the figures are measured against. Asserted by
     * reading the header row back rather than from the exporter's report.
     */
    public function testAFlatSheetExportNamesTheThreeStageApprovedColumns(): void
    {
        $headers = $this->flatSheetHeaders();

        foreach (['costCenterApprovedMinor', 'seniorApprovedMinor', 'financeApprovedMinor'] as $column) {
            self::assertContains(
                $column,
                $headers,
                "the flat sheet export must name {$column}: FR-062 applies to sheet exports, not only to the detail view"
            );
        }
    }

    /**
     * ...as numeric cells, not text.
     *
     * A figure stored as text opens perfectly and cannot be totalled — so a workbook that looks
     * correct in review is not a correct workbook, and only reading the cell type distinguishes the
     * two. This is the same distinction `XlsxExporterTest` pins for amounts.
     */
    public function testTheThreeStageApprovedCellsAreNumeric(): void
    {
        $book = $this->flatWorkbook();

        try {
            $sheet = $book->getSheetByName('Rows');
            self::assertNotNull($sheet, 'the flat export keeps its single Rows sheet');

            $headers = [];
            foreach ($sheet->getRowIterator(1, 1) as $row) {
                foreach ($row->getCellIterator() as $cell) {
                    $headers[$cell->getValue()] = $cell->getColumn();
                }
            }

            foreach (['costCenterApprovedMinor', 'seniorApprovedMinor', 'financeApprovedMinor'] as $column) {
                self::assertArrayHasKey($column, $headers);
                // Row 2 is the fixture sheet's data row. Its type must be numeric: only the header
                // (row 1) is text by construction.
                $type = $sheet->getCell($headers[$column] . '2')->getDataType();
                self::assertSame(
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC,
                    $type,
                    "{$column} must be a numeric cell so a spreadsheet can total it"
                );
            }
        } finally {
            $book->disconnectWorksheets();
        }
    }

    /**
     * The CSV twin names the same three columns.
     *
     * CSV has no cell typing — integers serialize as themselves — so presence is the whole assertion.
     * Asserted because the two flat formats must not disagree about which figures a sheet export
     * carries.
     */
    public function testAFlatSheetCsvNamesTheThreeStageApprovedColumns(): void
    {
        $response = $this->post([
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => [ContractFixtures::sheetId()],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $decoded = json_decode((string) $response->getBody(), true);
        $csv = (string) base64_decode((string) ($decoded['ocs']['data']['data'] ?? ''));
        $header = strtok($csv, "\n");

        foreach (['costCenterApprovedMinor', 'seniorApprovedMinor', 'financeApprovedMinor'] as $column) {
            self::assertStringContainsString($column, $header);
        }
    }

    /** @return list<string> */
    private function flatSheetHeaders(): array
    {
        $book = $this->flatWorkbook();

        try {
            $sheet = $book->getSheetByName('Rows');
            self::assertNotNull($sheet);

            $headers = [];
            foreach ($sheet->getRowIterator(1, 1) as $row) {
                foreach ($row->getCellIterator() as $cell) {
                    $headers[] = (string) $cell->getValue();
                }
            }

            return $headers;
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function flatWorkbook(): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        // The plural resource keeps the flat rows path even for one id: only `resource: sheet`
        // with exactly one id takes the complete-sheet layout, and that boundary is asserted in
        // `testThePluralResourceKeepsTheFlatRowsPath` above.
        $response = $this->post([
            'resource' => 'sheets',
            'fileFormat' => 'xlsx',
            'ids' => [ContractFixtures::sheetId()],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $decoded = json_decode((string) $response->getBody(), true);
        $data = $decoded['ocs']['data'] ?? [];
        self::assertArrayHasKey('data', $data);

        $path = tempnam(sys_get_temp_dir(), 't365_');
        self::assertIsString($path);
        file_put_contents($path, (string) base64_decode((string) $data['data']));

        try {
            return \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function workbook(array $body): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $response = $this->post($body);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $decoded = json_decode((string) $response->getBody(), true);
        $data = $decoded['ocs']['data'] ?? [];
        self::assertArrayHasKey('data', $data, 'the artifact travels base64-encoded inside the OCS envelope');

        // `IOFactory` has no `loadFromString`, so the bytes go to a temporary file removed by the
        // caller in a `finally` — the same shape `XlsxExporterTest::withWorkbook()` uses. The path
        // below returns the live book; every caller disconnects it.
        $path = tempnam(sys_get_temp_dir(), 't366_');
        self::assertIsString($path);
        file_put_contents($path, (string) base64_decode((string) $data['data']));

        try {
            return \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return list<string> the values of one column, header excluded
     */
    private function column(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, string $sheet, string $column): array
    {
        $worksheet = $book->getSheetByName($sheet);
        self::assertNotNull($worksheet, "no sheet named {$sheet} in the workbook");

        $values = [];
        $highestRow = $worksheet->getHighestRow($column);
        for ($row = 2; $row <= $highestRow; $row++) {
            $values[] = (string) $worksheet->getCell($column . $row)->getValue();
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): ResponseInterface
    {
        return $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/exports', [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
            ],
            'json' => $body,
        ]);
    }
}