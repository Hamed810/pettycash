<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Application\Reporting\ReportProjection;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Feature 007 (T026/T028/T029): the senior-scoped sheet read and the `senior-queue` export over the
 * real OCS surface.
 *
 * The acting fixture user holds active `SENIOR_MANAGER` assignments for CC-100 (and FINANCE, and
 * COST_CENTER_MANAGER for CC-100) in every fixture state, so both the sheet read and the export's
 * scope checks are exercised against real assignments rather than row positions.
 *
 * ## Why the sheet read has to be driven through the decision endpoint
 *
 * The fixture transaction never sits at the Senior stage: the contract fixture states top out at
 * COST_CENTER (`submitted`, `scope`) or below (draft/correction), and `finance` leaves the
 * transaction past Senior. `getSeniorSheetForManager()` only returns a sheet once a transaction in
 * the senior's scope is at Senior awaiting, or was decided by this senior — so the 200 shape is
 * reached by recording a real Cost Center decision (and, for the decided half, a real Senior
 * decision) through `POST /transactions/{id}/decisions` first. That is the same path the manager
 * twin's contract suite uses, and it keeps the endpoint tests asserting against behaviour the
 * workflow can actually reach.
 *
 * ## What the export assertions pin
 *
 * `senior-queue` is XLSX-only, row order equals `ids` order (owner D4), an out-of-scope or unknown
 * id is refused before anything is generated, a legitimate empty selection is a measured zero with an
 * explicit header row, and no other format answers. The XLSX bytes are read back with PhpSpreadsheet
 * rather than trusted from the status code, because a "correct" row count over the wrong rows is the
 * exact shape T302/T367 recorded.
 */
final class SeniorManagerReviewControllerTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
        ContractFixtures::prepare('submitted');
    }

    // ===== GET /queues/senior/sheets/{sheetId} =====

    public function testSeniorSheetIsNotDisclosedUntilItActuallyAwaitsTheSenior(): void
    {
        // In the freshly-seeded `submitted` state the fixture transaction is still awaiting Cost
        // Center, so a senior reading the sheet must answer the same 404 as an absent sheet — the
        // sheet is not in the senior queue yet and must not be confirmable (FR-007-056).
        $response = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/queues/senior/sheets/' . ContractFixtures::sheetId()
        );

        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testSeniorSheetAnswersTheContractShapeForAnAwaitingTransaction(): void
    {
        $this->decide(ContractFixtures::transactionId(), 'APPROVE', null, ContractFixtures::transactionVersion());

        $data = $this->ocsData($this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/queues/senior/sheets/' . ContractFixtures::sheetId()
        ));

        // The senior-scoped sheet header: the shared identity/submission snapshot plus the two
        // fields only this read carries (T026's SeniorSheetSummary).
        $sheet = $data['sheet'];
        self::assertSame(ContractFixtures::sheetId(), $sheet['id']);
        self::assertSame('Contract Fixture Sheet', $sheet['title']);
        self::assertSame(getenv('PETTYCASH_TEST_USER'), $sheet['ownerId']);
        self::assertNotEmpty($sheet['ownerDisplayName']);
        self::assertSame('IRR', $sheet['currency']);
        self::assertSame(100000, $sheet['submittedTotalMinor']);
        self::assertSame(
            ['amountMinor' => 100000, 'currency' => 'IRR', 'minorUnit' => 0],
            $sheet['submittedTotal'],
            'The submitted total must be currency-safe and derived from the submission snapshot'
        );
        self::assertNotEmpty($sheet['submittedAt']);
        self::assertArrayHasKey('version', $sheet);
        self::assertTrue(
            $sheet['allTransactionsCostCenterDecided'],
            'The single fixture transaction carries a final Cost Center approval on its current revision (T025)'
        );
        self::assertSame('PENDING_REVIEW', $sheet['reviewStatus']);

        self::assertSame(
            ['awaitingSenior' => 1, 'decidedBySenior' => 0, 'total' => 1],
            $data['progress']
        );

        self::assertCount(1, $data['transactions']);
        $transaction = $data['transactions'][0];
        self::assertSame(ContractFixtures::transactionId(), $transaction['id']);
        self::assertSame('AWAITING_SENIOR_DECISION', $transaction['seniorState']);
        self::assertNull($transaction['seniorDecision']);
    }

    public function testSeniorSheetReportsThisSeniorsDecisionAndReviewCompletion(): void
    {
        $this->decide(ContractFixtures::transactionId(), 'APPROVE', null, ContractFixtures::transactionVersion());
        $this->decide(ContractFixtures::transactionId(), 'APPROVE', null, $this->currentVersion());

        $data = $this->ocsData($this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/queues/senior/sheets/' . ContractFixtures::sheetId()
        ));

        self::assertSame('REVIEW_COMPLETED', $data['sheet']['reviewStatus']);
        self::assertTrue($data['sheet']['allTransactionsCostCenterDecided']);
        self::assertSame(
            ['awaitingSenior' => 0, 'decidedBySenior' => 1, 'total' => 1],
            $data['progress']
        );

        $transaction = $data['transactions'][0];
        self::assertSame('DECIDED_BY_SENIOR', $transaction['seniorState']);
        self::assertSame('APPROVE', $transaction['seniorDecision']['decision']);
        self::assertSame(getenv('PETTYCASH_TEST_USER'), $transaction['seniorDecision']['actor']['userId']);
    }

    public function testUnknownAndOutOfScopeSheetsAnswerTheSame404(): void
    {
        ContractFixtures::prepare('scope');

        // The out-of-scope sheet belongs to CC-200, which the acting user holds no SENIOR_MANAGER
        // assignment for; the unknown id matches no sheet. Both must answer 404 and must not expose
        // anything about whether the sheet exists (FR-007-056).
        $outOfScope = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/queues/senior/sheets/' . ContractFixtures::outOfScopeSheetId()
        );
        $unknown = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/queues/senior/sheets/' . ContractFixtures::unknownId()
        );

        $this->assertEquals(404, $outOfScope->getStatusCode());
        $this->assertEquals(404, $unknown->getStatusCode());
        $this->assertSame(
            $this->errorMessage($outOfScope),
            $this->errorMessage($unknown),
            'The two 404s must be textually indistinguishable, or the endpoint confirms out-of-scope sheets'
        );
    }

    // ===== GET /queues/senior/sheets (additive senior-scoped list, UX-007-07) =====

    public function testSeniorSheetsListIsEmptyUntilTheFixtureTransactionReachesTheSeniorQueue(): void
    {
        // In the freshly-seeded `submitted` state the fixture transaction is still awaiting Cost
        // Center, so the list is empty: a sheet is listed only once a scoped transaction awaits the
        // senior or carries a decision by this senior (same reachability rule as the per-sheet read).
        $data = $this->ocsData($this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/queues/senior/sheets'
        ));

        self::assertSame(0, $data['total']);
        self::assertSame([], $data['items']);
    }

    public function testSeniorSheetsListAnswersTheContractShapeForPendingAndCompletedSheets(): void
    {
        $this->decide(ContractFixtures::transactionId(), 'APPROVE', null, ContractFixtures::transactionVersion());

        $pending = $this->ocsData($this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/queues/senior/sheets'
        ));

        self::assertSame(1, $pending['total']);
        $row = $pending['items'][0];
        self::assertSame(ContractFixtures::sheetId(), $row['id']);
        self::assertSame('Contract Fixture Sheet', $row['title']);
        self::assertSame('IRR', $row['currency']);
        self::assertSame('PENDING_REVIEW', $row['reviewStatus']);
        self::assertTrue($row['allTransactionsCostCenterDecided']);
        self::assertSame(['awaitingSenior' => 1, 'decidedBySenior' => 0, 'total' => 1], $row['progress']);
        self::assertArrayHasKey('latestActivityAt', $row);
        self::assertArrayNotHasKey('transactions', $row, 'a list row is a summary, never a transaction set');
        self::assertArrayNotHasKey('allowedActions', $row, 'a completed list row must expose no decision capability');

        // The final Senior decision advances the transaction to Finance and completes the review, but
        // the sheet must remain discoverable and now report REVIEW_COMPLETED (UX-007-07).
        $this->decide(ContractFixtures::transactionId(), 'APPROVE', null, $this->currentVersion());

        $completed = $this->ocsData($this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/queues/senior/sheets'
        ));

        self::assertSame(1, $completed['total'], 'the completed sheet must stay discoverable after advancing to Finance');
        self::assertSame('REVIEW_COMPLETED', $completed['items'][0]['reviewStatus']);
        self::assertSame(['awaitingSenior' => 0, 'decidedBySenior' => 1, 'total' => 1], $completed['items'][0]['progress']);
    }

    public function testSeniorSheetsListPaginationReportsTheTrueTotalWithoutOmission(): void
    {
        $this->decide(ContractFixtures::transactionId(), 'APPROVE', null, ContractFixtures::transactionVersion());

        $pageOne = $this->ocsData($this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/queues/senior/sheets',
            ['query' => ['page' => 1, 'pageSize' => 1]]
        ));
        $pageTwo = $this->ocsData($this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/queues/senior/sheets',
            ['query' => ['page' => 2, 'pageSize' => 1]]
        ));

        self::assertSame(1, $pageOne['total'], '`total` counts the sheets in scope, not the page');
        self::assertCount(1, $pageOne['items']);
        self::assertSame([], $pageTwo['items'], 'page two simply has nothing left; nothing is silently truncated');
    }

    // ===== POST /exports, resource: senior-queue =====

    public function testSeniorQueueExportAnswersXlsxForASelectedId(): void
    {
        $data = $this->export([
            'resource' => 'senior-queue',
            'fileFormat' => 'xlsx',
            'ids' => [ContractFixtures::transactionId()],
        ]);

        self::assertSame(200, $data['status'], $data['message']);
        self::assertSame('xlsx', $data['data']['format']);
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $data['data']['mediaType']
        );
        self::assertSame(1, $data['data']['rowCount']);

        $book = $this->workbook($data['data']);
        try {
            [$header, $rows] = $this->rowsIn($book);

            // The additive contract declares the full combined shape; the header must carry the
            // separate cost-center and senior decision columns (owner D4, FR-007-004/051).
            foreach (ReportProjection::SENIOR_QUEUE_COLUMNS as $column) {
                self::assertContains($column, $header, 'the senior-queue export must declare every contract column');
            }

            $transactionIds = $this->values($header, $rows, 'transactionId');
            self::assertSame([ContractFixtures::transactionId()], $transactionIds);
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function testSeniorQueueExportHonoursTheSubmittedRowOrderAndTypedFigures(): void
    {
        // `finance` leaves two CC-100 transactions in the fixture set (one senior-approved, one
        // senior-rejected), both inside the acting senior's scope — the two rows needed to prove
        // that the artifact reproduces the exact `ids` order the client saw on screen (owner D4).
        ContractFixtures::prepare('finance');
        $first = ContractFixtures::seniorRejectedFinanceTransactionId();
        $second = ContractFixtures::transactionId();

        $data = $this->export([
            'resource' => 'senior-queue',
            'fileFormat' => 'xlsx',
            'ids' => [$first, $second],
        ]);

        self::assertSame(200, $data['status'], $data['message']);
        self::assertSame(2, $data['data']['rowCount']);

        $book = $this->workbook($data['data']);
        try {
            [$header, $rows] = $this->rowsIn($book);

            self::assertSame(
                [$first, $second],
                $this->values($header, $rows, 'transactionId'),
                'the export must resolve the selection in the submitted ids order, not the store\'s order'
            );

            // The amount columns are emitted as numeric cells, not text, per the additive
            // SENIOR_QUEUE_NUMERIC_COLUMNS wiring.
            $amountColumn = array_search('amountMinor', $header, true);
            self::assertNotFalse($amountColumn, 'the row must carry amountMinor');
            foreach ($rows as $row) {
                self::assertIsNotString(
                    $row[$amountColumn],
                    'amountMinor must be a typed numeric cell in the senior-queue XLSX'
                );
            }
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function testSeniorQueueExportRefusesANonXlsxFormat(): void
    {
        foreach (['csv', 'pdf'] as $format) {
            $response = $this->export([
                'resource' => 'senior-queue',
                'fileFormat' => $format,
                'ids' => [ContractFixtures::transactionId()],
            ]);

            self::assertSame(422, $response['status'], "'{$format}' must be refused for senior-queue");
            self::assertStringContainsString('xlsx', $response['message']);
            self::assertArrayNotHasKey('rowCount', $response['data'] ?? [], 'no artifact is produced for a refused format');
        }
    }

    public function testSeniorQueueExportEmptySelectionIsAMeasuredZeroWithColumns(): void
    {
        $response = $this->export([
            'resource' => 'senior-queue',
            'fileFormat' => 'xlsx',
            'ids' => [],
        ]);

        self::assertSame(200, $response['status'], $response['message']);
        self::assertSame(0, $response['data']['rowCount']);

        $book = $this->workbook($response['data']);
        try {
            [$header, $rows] = $this->rowsIn($book);

            self::assertSame(ReportProjection::SENIOR_QUEUE_COLUMNS, $header);
            self::assertSame([], $rows, 'an empty selection is a measured zero, still carrying its explicit header row');
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function testSeniorQueueExportRefusesAnOutOfScopeOrUnknownIdWithoutAnArtifact(): void
    {
        ContractFixtures::prepare('scope');

        // `otherTransactionId` is inside CC-200, which the acting user has no senior assignment
        // for; the unknown id matches no transaction. Both are refused identically, with nothing
        // generated (FR-003 / FR-007-056), so the endpoint never confirms an out-of-scope record.
        $foreign = $this->export([
            'resource' => 'senior-queue',
            'fileFormat' => 'xlsx',
            'ids' => [ContractFixtures::outOfScopeTransactionId()],
        ]);
        $unknown = $this->export([
            'resource' => 'senior-queue',
            'fileFormat' => 'xlsx',
            'ids' => [ContractFixtures::unknownId()],
        ]);

        foreach ([$foreign, $unknown] as $response) {
            self::assertSame(422, $response['status']);
            self::assertStringContainsString('available to you', $response['message']);
            self::assertArrayNotHasKey('rowCount', $response['data'] ?? []);
            self::assertArrayNotHasKey('data', $response['data'] ?? []);
        }
    }

    // ===== helpers =====

    /**
     * Record one decision on the fixture transaction through the real decision endpoint.
     */
    private function decide(string $transactionId, string $decision, ?string $explanation, int $version): void
    {
        $payload = ['decision' => $decision, 'expectedVersion' => $version];
        if ($explanation !== null) {
            $payload['explanation'] = $explanation;
        }

        $response = $this->client->request(
            'POST',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . $transactionId . '/decisions',
            [
                'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
                'json' => $payload,
            ]
        );

        self::assertEquals(201, $response->getStatusCode(), 'the fixture transaction must be decidable: ' . (string) $response->getBody());
    }

    /**
     * The transaction's current lock version, read back from the detail endpoint so a caller that
     * follows one decision with another asks for the version the first left behind.
     */
    private function currentVersion(): int
    {
        $data = $this->ocsData($this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId()
        ));

        self::assertArrayHasKey('version', $data, 'the transaction detail must report its lock version');

        return (int) $data['version'];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, data: array<string, mixed>, message: string}
     */
    private function export(array $body): array
    {
        $response = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/exports', [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
            ],
            'json' => $body,
        ]);

        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        $data = $decoded['ocs']['data'] ?? [];
        $message = (string) ($data['error']['message'] ?? $decoded['ocs']['meta']['message'] ?? '');

        return [
            'status' => $response->getStatusCode(),
            'data' => $data,
            'message' => $message,
        ];
    }

    /**
     * Load the base64 artifact as a live PhpSpreadsheet workbook, cleaning the temporary file up first.
     *
     * Returns the live book without disconnecting: a disconnected worksheet cannot serve `toArray()`
     * (its `$cellCollection` is nulled), and the caller reads the Rows sheet through `rowsIn()` and
     * disconnects in a `finally` — the same shape `CompleteSheetExportContractTest::workbook()` uses.
     */
    private function workbook(array $result): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        self::assertArrayHasKey('data', $result, 'the artifact travels base64-encoded in the envelope');
        $bytes = (string) base64_decode((string) $result['data'], true);
        self::assertStringStartsWith('PK', $bytes, 'an xlsx is a zip container');

        $path = tempnam(sys_get_temp_dir(), 't007_');
        self::assertIsString($path);
        file_put_contents($path, $bytes);

        try {
            return \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>} the header row and the data rows
     */
    private function rowsIn(\PhpOffice\PhpSpreadsheet\Spreadsheet $book): array
    {
        $worksheet = $book->getSheetByName('Rows');
        self::assertNotNull($worksheet, 'a flat xlsx export builds a single Rows sheet');

        // Raw values (`formatData: false`), not formatted strings: `toArray()` defaults to returning
        // the *formatted* value, which turns a numeric cell into '70000' and would misreport a
        // numeric cell as text — the exact distinction the sheet export's numeric-cell test reads
        // through `getDataType()` instead.
        $all = $worksheet->toArray(false, false, false);
        self::assertNotEmpty($all);

        $header = array_map(static fn ($cell): string => (string) $cell, $all[0]);
        $rows = array_slice($all, 1);

        return [$header, $rows];
    }

    /**
     * @param list<string> $header
     * @param list<list<mixed>> $rows
     * @return list<string>
     */
    private function values(array $header, array $rows, string $column): array
    {
        $index = array_search($column, $header, true);
        self::assertNotFalse($index, "the row set must carry column '{$column}'");

        return array_map(static fn (array $row): string => (string) ($row[$index] ?? ''), $rows);
    }

    /** @return array<string, mixed> */
    private function ocsData(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('ocs', $body);
        self::assertArrayHasKey('data', $body['ocs']);

        return $body['ocs']['data'];
    }

    private function errorMessage(ResponseInterface $response): string
    {
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);

        $data = $body['ocs']['data'] ?? [];

        return (string) ($data['error']['message'] ?? $body['ocs']['meta']['message'] ?? '');
    }
}
