<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for PurchaserFlowController OCS API endpoints.
 *
 * These tests verify that the OCS API endpoints defined in contracts/ocs-api.yaml
 * are properly implemented. The tests should FAIL because the endpoints are not yet
 * implemented - they return 404 instead of the contract-defined responses.
 */
final class PurchaserFlowControllerTest extends TestCase
{
    private Client $client;
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = 'http://localhost';
        $this->client = OcsTestClient::create();
        ContractFixtures::prepare('draft');
    }

    /**
     * FR-062: the sheet response must carry `totals`, because the three stage-approved columns the
     * requirement names have nowhere to be read from otherwise.
     *
     * `contracts/ocs-api.yaml` lists `totals` among `SheetSummary`'s required members, and
     * `formatSheet()` never emitted it — so this asserts the whole `SheetTotals` schema is present
     * and correctly typed on the sheet endpoints, not merely that a `totals` key exists.
     */
    public function testSheetResponseCarriesTheFullTotalsObject(): void
    {
        ContractFixtures::prepare('draft');

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId());
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];

        $this->assertArrayHasKey('totals', $data, 'SheetSummary requires totals');
        $this->assertIsArray($data['totals']);

        foreach ([
            'current', 'submitted',
            'effectiveApproved', 'effectiveRejected', 'effectivePending',
            'costCenterApproved', 'seniorApproved', 'financeApproved',
        ] as $figure) {
            $this->assertArrayHasKey($figure, $data['totals'], "SheetTotals requires {$figure}");
            $this->assertIsInt($data['totals'][$figure]['amountMinor'] ?? null, "{$figure}.amountMinor must be an integer");
            $this->assertIsString($data['totals'][$figure]['currency'] ?? null, "{$figure}.currency must be a string");
            $this->assertIsInt($data['totals'][$figure]['minorUnit'] ?? null, "{$figure}.minorUnit must be an integer");
        }
    }

    /**
     * The projection must read the sheet's real transactions, not answer zero.
     *
     * The fixture seeds one transaction of 100,000 minor units with no decisions, so the sheet's
     * current total is 100,000 and the whole of it is pending — a transaction nobody has decided
     * yet. A projection that returned zeros, or that counted the transaction as approved, would both
     * satisfy a test that only checked the key was present.
     */
    public function testSheetTotalsSumTheRealActiveRevisions(): void
    {
        ContractFixtures::prepare('draft');

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId());
        $totals = json_decode((string) $response->getBody(), true)['ocs']['data']['totals'] ?? [];

        $this->assertSame(
            100000,
            $totals['current']['amountMinor'] ?? null,
            'The current total must sum the active revisions the sheet actually holds'
        );
        $this->assertSame(
            100000,
            $totals['effectivePending']['amountMinor'] ?? null,
            'An undecided transaction is pending, so the whole amount is pending'
        );
        $this->assertSame(0, $totals['effectiveApproved']['amountMinor'] ?? null, 'Nothing is approved yet');
        $this->assertSame(0, $totals['effectiveRejected']['amountMinor'] ?? null, 'Nothing is rejected yet');
        foreach (['costCenterApproved', 'seniorApproved', 'financeApproved'] as $stage) {
            $this->assertSame(0, $totals[$stage]['amountMinor'] ?? null, "{$stage} must be zero before any decision");
        }
    }

    /**
     * `minorUnit` has to come from the configured currency, because a reader divides by ten to the
     * power of it. The fixture sheet is USD with two minor digits, so a hardcoded 0 or a fixed /100
     * would misstate every figure on the sheet.
     */
    public function testSheetTotalsReportTheConfiguredCurrencyScale(): void
    {
        ContractFixtures::prepare('draft');

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId());
        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];

        $currency = $data['currency'] ?? null;
        $this->assertIsString($currency);

        foreach ($data['totals'] as $figure => $money) {
            $this->assertSame($currency, $money['currency'], "{$figure} must be denominated in the sheet's own currency");
        }

        $expected = $currency === 'USD' ? 2 : 0;
        $this->assertSame(
            $expected,
            $data['totals']['current']['minorUnit'] ?? null,
            'minorUnit must be the configured value for the sheet currency, not a guess'
        );
    }

    /**
     * `submitted` is a snapshot taken at first submission, not a live sum — that is the only reason
     * it is a separate figure from `current`. Before submission it is zero; afterwards it is the
     * amount that was submitted.
     */
    public function testSubmittedTotalIsASnapshotNotALiveSum(): void
    {
        ContractFixtures::prepare('draft');

        $before = json_decode(
            (string) $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId())->getBody(),
            true
        )['ocs']['data']['totals'] ?? [];
        $this->assertSame(0, $before['submitted']['amountMinor'] ?? null, 'A draft has submitted nothing');
        $this->assertSame(100000, $before['current']['amountMinor'] ?? null, 'The draft still has a current total');

        $submit = $this->client->request(
            'POST',
            '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId() . '/submit',
            [
                'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
                'json' => ['expectedVersion' => ContractFixtures::sheetVersion()],
            ]
        );
        $this->assertEquals(200, $submit->getStatusCode());

        $after = json_decode(
            (string) $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId())->getBody(),
            true
        )['ocs']['data']['totals'] ?? [];

        $this->assertSame(
            100000,
            $after['submitted']['amountMinor'] ?? null,
            'Submission must record the submitted amount as a snapshot'
        );
    }

    /**
     * The sheet list carries the same shape as the sheet detail, so `index` cannot answer without
     * totals where `show` answers with them.
     */
    public function testSheetListCarriesTotalsToo(): void
    {
        ContractFixtures::prepare('draft');

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets');
        $items = json_decode((string) $response->getBody(), true)['ocs']['data']['items'] ?? [];

        $this->assertNotEmpty($items, 'The fixture should seed at least one sheet');
        foreach ($items as $item) {
            $this->assertArrayHasKey('totals', $item, 'Every SheetSummary in a page must carry totals');
        }

        $withTheFixtureSheet = null;
        foreach ($items as $item) {
            if (($item['id'] ?? null) === ContractFixtures::sheetId()) {
                $withTheFixtureSheet = $item;
            }
        }
        $this->assertNotNull($withTheFixtureSheet, 'The fixture sheet should appear in the list');
        $this->assertSame(
            100000,
            $withTheFixtureSheet['totals']['current']['amountMinor'] ?? null,
            'The listed sheet must total its real transactions, not zero'
        );
    }

    /**
     * The contract does not declare `accountId` on `SheetSummary`, and the formatter used to emit
     * it. A field the schema does not describe is one nothing can be tested against, so it is
     * dropped rather than kept as an extra.
     */
    public function testSheetSummaryCarriesNoUndeclaredAccountId(): void
    {
        ContractFixtures::prepare('draft');

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId());
        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];

        $this->assertArrayNotHasKey('accountId', $data, 'SheetSummary does not declare accountId');
    }

    // ===== SHEETS =====

    public function testCreateSheetReturns201WithSheetDetail(): void
    {
        $response = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/sheets', [
            'headers' => [
                'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
            ],
            'json' => [
                'title' => 'Test Sheet',
                'accountId' => ContractFixtures::accountId(),
            ],
        ]);

        // Contract: POST /sheets returns 201 with SheetDetail in ocs.data
        $this->assertEquals(201, $response->getStatusCode(), 'POST /sheets should return 201 Created');
        $this->assertOcsResponseStructure($response, 201);
    }

    public function testListSheetsReturns200WithPagedSheets(): void
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets');

        // Contract: GET /sheets returns 200 with paged SheetSummary array
        $this->assertEquals(200, $response->getStatusCode(), 'GET /sheets should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testGetSheetDetailReturns200WithSheetDetail(): void
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId());

        // Contract: GET /sheets/{id} returns 200 with SheetDetail, or 403/404
        $this->assertEquals(200, $response->getStatusCode(), 'GET /sheets/{id} should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testUpdateSheetReturns200WithUpdatedSheetDetail(): void
    {
        $response = $this->client->request('PATCH', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId(), [
            'headers' => [
                'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
            ],
            'json' => [
                'title' => 'Updated Title',
                'accountId' => ContractFixtures::accountId(),
                'expectedVersion' => ContractFixtures::sheetVersion(),
            ],
        ]);

        // Contract: PATCH /sheets/{id} returns 200 with updated SheetDetail
        $this->assertEquals(200, $response->getStatusCode(), 'PATCH /sheets/{id} should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testSubmitSheetReturns200WithSubmittedSheetDetail(): void
    {
        $response = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId() . '/submit', [
            'headers' => [
                'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
            ],
            'json' => [
                'expectedVersion' => ContractFixtures::sheetVersion(),
            ],
        ]);

        // Contract: POST /sheets/{id}/submit returns 200 with SheetDetail, or 422/409
        $this->assertEquals(200, $response->getStatusCode(), 'POST /sheets/{id}/submit should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    // ===== TRANSACTIONS =====

    public function testCreateTransactionReturns201WithTransactionDetail(): void
    {
        $response = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId() . '/transactions', [
            'headers' => [
                'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
            ],
            'json' => [
                'expenseDate' => '2026-09-20',
                'amountMinor' => 1000,
            ],
        ]);

        // Contract: POST /sheets/{id}/transactions returns 201 with TransactionDetail
        $this->assertEquals(201, $response->getStatusCode(), 'POST /sheets/{id}/transactions should return 201 Created');
        $this->assertOcsResponseStructure($response, 201);
    }

    public function testGetTransactionDetailReturns200WithTransactionDetail(): void
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId());

        // Contract: GET /transactions/{id} returns 200 with TransactionDetail
        $this->assertEquals(200, $response->getStatusCode(), 'GET /transactions/{id} should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testUpdateTransactionReturns200WithUpdatedTransactionDetail(): void
    {
        $response = $this->client->request('PATCH', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId(), [
            'headers' => [
                'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
            ],
            'json' => [
                'expenseDate' => '2026-09-21',
                'amountMinor' => 2000,
                'expectedVersion' => ContractFixtures::transactionVersion(),
            ],
        ]);

        // Contract: PATCH /transactions/{id} returns 200 with updated TransactionDetail
        $this->assertEquals(200, $response->getStatusCode(), 'PATCH /transactions/{id} should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testDeleteTransactionReturns204(): void
    {
        $response = $this->client->request('DELETE', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId(), [
            'headers' => [
                'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
            ],
            'json' => [
                'expectedVersion' => ContractFixtures::transactionVersion(),
            ],
        ]);

        // Contract: DELETE /transactions/{id} returns 204 No Content
        $this->assertEquals(204, $response->getStatusCode(), 'DELETE /transactions/{id} should return 204 No Content');
    }

    public function testRecordDecisionReturns201WithDecisionResult(): void
    {
        ContractFixtures::prepare('submitted');

        $response = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/decisions', [
            'headers' => [
                'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
            ],
            'json' => [
                'decision' => 'APPROVE',
                'expectedVersion' => ContractFixtures::transactionVersion(),
            ],
        ]);

        // Contract: POST /transactions/{id}/decisions returns 201 with DecisionResult, or 403/409/422
        $this->assertEquals(201, $response->getStatusCode(), 'POST /transactions/{id}/decisions should return 201 Created');
        $this->assertOcsResponseStructure($response, 201);
    }

    public function testCreateCorrectionReturns201WithRevision(): void
    {
        ContractFixtures::prepare('correction');

        $response = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/correction', [
            'headers' => [
                'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
            ],
            'json' => [
                'expectedVersion' => ContractFixtures::transactionVersion(),
            ],
        ]);

        // Contract: POST /transactions/{id}/correction returns 201 with Revision
        $this->assertEquals(201, $response->getStatusCode(), 'POST /transactions/{id}/correction should return 201 Created');
        $this->assertOcsResponseStructure($response, 201);
    }

    public function testResubmitCorrectionReturns200WithTransactionDetail(): void
    {
        ContractFixtures::prepare('correction');

        $response = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/resubmit', [
            'headers' => [
                'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
            ],
            'json' => [
                'expectedVersion' => ContractFixtures::transactionVersion(),
            ],
        ]);

        // Contract: POST /transactions/{id}/resubmit returns 200 with TransactionDetail
        $this->assertEquals(200, $response->getStatusCode(), 'POST /transactions/{id}/resubmit should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testListRevisionsReturns200WithRevisions(): void
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/revisions');

        // Contract: GET /transactions/{id}/revisions returns 200 with array of Revision
        $this->assertEquals(200, $response->getStatusCode(), 'GET /transactions/{id}/revisions should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testListAuditReturns200WithAuditPage(): void
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/audit', [
            'query' => ['page' => 1, 'pageSize' => 25],
        ]);

        // Contract: GET /transactions/{id}/audit returns 200 with AuditPage
        $this->assertEquals(200, $response->getStatusCode(), 'GET /transactions/{id}/audit should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    // ===== EVIDENCE =====

    public function testUploadEvidenceReturns201WithEvidenceMetadata(): void
    {
        // Create a small test file
        $tempFile = tempnam(sys_get_temp_dir(), 'evidence_');
        file_put_contents($tempFile, str_repeat('x', 1024)); // 1KB test file

        try {
            $response = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/evidence', [
                'headers' => [
                    'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
                ],
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => fopen($tempFile, 'r'),
                        'filename' => 'test-receipt.pdf',
                    ],
                ],
            ]);

            // Contract: POST /transactions/{id}/evidence returns 201 with Evidence metadata
            $this->assertEquals(201, $response->getStatusCode(), 'POST /evidence should return 201 Created');
            $this->assertOcsResponseStructure($response, 201);
        } finally {
            @unlink($tempFile);
        }
    }

    public function testDeleteEvidenceReturns200WithDeactivatedMetadata(): void
    {
        $evidenceId = $this->uploadEvidence();

        $response = $this->client->request('DELETE', '/ocs/v2.php/apps/pettycash/api/v1/evidence/' . $evidenceId, [
            'headers' => [
                'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
            ],
        ]);

        // Contract: DELETE /evidence/{id} returns 200 with deactivated Evidence metadata
        $this->assertEquals(200, $response->getStatusCode(), 'DELETE /evidence/{id} should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testGetEvidenceContentReturns200WithStream(): void
    {
        $evidenceId = $this->uploadEvidence();

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/evidence/' . $evidenceId . '/content', [
            'query' => ['disposition' => 'inline'],
        ]);

        // Contract: GET /evidence/{id}/content returns 200 with byte stream
        $this->assertEquals(200, $response->getStatusCode(), 'GET /evidence/{id}/content should return 200 OK');
        $this->assertEquals('application/octet-stream', $response->getHeaderLine('Content-Type'));
    }

    // ===== EVIDENCE CONSTRAINTS =====

    public function testEvidenceUploadEnforces100MBLimit(): void
    {
        // Create a file larger than 100MB (101MB)
        $tempFile = tempnam(sys_get_temp_dir(), 'large_evidence_');
        $handle = fopen($tempFile, 'w');
        fwrite($handle, str_repeat('x', 101 * 1024 * 1024)); // 101MB
        fclose($handle);

        try {
            $response = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/evidence', [
                'headers' => [
                    'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
                ],
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => fopen($tempFile, 'r'),
                        'filename' => 'large-file.pdf',
                    ],
                ],
            ]);

            // Contract: Oversized files should be rejected (413 or 422)
            $this->assertEquals(413, $response->getStatusCode(), 'POST /evidence with >100MB should return 413 Payload Too Large');
            $this->assertOcsResponseStructure($response, 413);
        } finally {
            @unlink($tempFile);
        }
    }

    public function testNoAdvancedEvidenceRestrictionsInV1(): void
    {
        // V1 should NOT enforce advanced evidence restrictions (classification, redaction, etc.)
        // The endpoint should accept evidence uploads and return 201 Created per the contract
        $tempFile = tempnam(sys_get_temp_dir(), 'evidence_');
        file_put_contents($tempFile, 'test content');

        try {
            $response = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/evidence', [
                'headers' => [
                    'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
                ],
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => fopen($tempFile, 'r'),
                        'filename' => 'test.txt',
                    ],
                ],
            ]);

            // Contract: Valid evidence upload returns 201 Created (ocs-api.yaml)
            $this->assertEquals(201, $response->getStatusCode(), 'Valid evidence upload should return 201 Created');

            // V1 must NOT enforce advanced evidence restrictions (classification, redaction, etc.)
            $this->assertNotEquals(403, $response->getStatusCode(), 'V1 should not enforce advanced evidence restrictions (403)');
        } finally {
            @unlink($tempFile);
        }
    }

    // ===== CURRENCY READ-BACK (FR-021) =====

    /**
     * A revision's `amount_minor` is only meaningful beside a currency, and FR-021
     * makes the sheet the authority for that currency. Reading a USD sheet's
     * transaction back must therefore report USD, not the rial default: a hardcoded
     * currency turns a 10.00 USD expense into a ten-thousand-rial one and corrupts
     * every total, settlement, and export derived from it.
     */
    public function testRevisionReadsBackTheSheetCurrencyRatherThanRial(): void
    {
        ContractFixtures::prepare('usd');

        $response = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::usdTransactionId()
        );

        $this->assertEquals(200, $response->getStatusCode(), 'GET /transactions/{id} should return 200 OK');

        $body = json_decode((string) $response->getBody(), true);
        $amount = $body['ocs']['data']['activeRevision']['amount'] ?? null;

        $this->assertIsArray($amount, 'The transaction detail should carry the revision amount');
        $this->assertSame(
            'USD',
            $amount['currency'] ?? null,
            'A revision read back from a USD sheet must report the sheet currency (FR-021)'
        );
        $this->assertSame(
            1000,
            $amount['amountMinor'] ?? null,
            'The stored minor units must be reported unchanged alongside their own currency'
        );
        $this->assertSame(
            2,
            $amount['minorUnit'] ?? null,
            'The fraction digits reported for USD must be the configured two, not the rial default of zero'
        );
    }

    /**
     * The rial sheet must keep reporting rial, so resolving the currency from the
     * sheet cannot degenerate into always answering with whatever the second
     * fixture happens to use.
     */
    public function testRialSheetStillReadsBackRial(): void
    {
        ContractFixtures::prepare('draft');

        $response = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId()
        );

        $this->assertEquals(200, $response->getStatusCode(), 'GET /transactions/{id} should return 200 OK');

        $body = json_decode((string) $response->getBody(), true);
        $amount = $body['ocs']['data']['activeRevision']['amount'] ?? null;

        $this->assertIsArray($amount, 'The transaction detail should carry the revision amount');
        $this->assertSame(
            'IRR',
            $amount['currency'] ?? null,
            'A revision read back from a rial sheet must report rial'
        );
    }

    // ===== DRAFT DELETE (FR-025, FR-058) =====

    /**
     * A successful delete must actually remove the transaction.
     *
     * `TransactionService::deleteTransaction()` used to validate ownership, the open
     * sheet, and the optimistic lock, then only filter the sheet's in-memory
     * transaction collection and re-save it. No `DELETE` reached
     * `pettycash_transactions`, so the endpoint answered `204 No Content` while the
     * expense remained fully present in the database and in every later total.
     */
    public function testDeletingADraftTransactionRemovesItFromTheDatabase(): void
    {
        ContractFixtures::prepare('draft');

        $delete = $this->client->request(
            'DELETE',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId(),
            [
                'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
                'json' => ['expectedVersion' => ContractFixtures::transactionVersion()],
            ]
        );

        $this->assertSame(
            204,
            $delete->getStatusCode(),
            'DELETE /transactions/{id} on a draft should answer 204 No Content'
        );

        $readBack = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId()
        );

        $this->assertSame(
            404,
            $readBack->getStatusCode(),
            'A deleted draft must no longer be readable; a 204 over a record that is still there is a no-op delete'
        );
    }

    /**
     * A submitted transaction must not be deletable even by its owner.
     *
     * A returned transaction sits in an open sheet again, so the open-sheet check
     * alone would let a purchaser erase submitted business history — which
     * Constitution IV forbids. This guards the draft-state restriction independently
     * of the delete actually working.
     */
    public function testDeletingASubmittedTransactionIsRefused(): void
    {
        ContractFixtures::prepare('submitted');

        $response = $this->client->request(
            'DELETE',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId(),
            [
                'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
                'json' => ['expectedVersion' => ContractFixtures::transactionVersion()],
            ]
        );

        $this->assertContains(
            $response->getStatusCode(),
            [400, 403, 409, 422],
            'A transaction that has been submitted must not be deletable, whatever the caller'
        );

        $readBack = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId()
        );

        $this->assertSame(
            200,
            $readBack->getStatusCode(),
            'The refused delete must leave the submitted transaction intact'
        );
    }

    // ===== TRANSACTION PAYLOAD CONFORMANCE (contracts/ocs-api.yaml) =====

    /**
     * The response must carry the contract's `TransactionDetail` members.
     *
     * `contracts/ocs-api.yaml` defines `TransactionSummary` (required: id, sheetId,
     * activeRevision, stage, status, evidenceCount, version) and `TransactionDetail` (adding
     * allowedActions, decisions, evidence). Both controllers used to carry their own private
     * formatter and both had drifted: the key was `revision` rather than `activeRevision`, and
     * `ReviewController` emitted flat `costCenterId`/`expenseTypeTitle` scalars instead of the
     * `EntitySnapshot` objects the contract requires.
     *
     * The frontend is written to the contract — `CostCenterQueueView.vue` and
     * `SheetDetailView.vue` both read `item.activeRevision.costCenter.name` — so a response that
     * does not match makes those views dereference `undefined` on every load. Asserting the
     * shape is the only way the suite can catch that, because the status code is 200 either way.
     */
    public function testTransactionDetailCarriesTheContractShape(): void
    {
        ContractFixtures::prepare('submitted');

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId());
        $this->assertEquals(200, $response->getStatusCode(), 'GET /transactions/{id} should return 200 OK');

        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];

        foreach (['id', 'sheetId', 'activeRevision', 'stage', 'status', 'evidenceCount', 'version'] as $required) {
            $this->assertArrayHasKey(
                $required,
                $data,
                "TransactionSummary requires `{$required}`; the response must satisfy contracts/ocs-api.yaml"
            );
        }

        foreach (['allowedActions', 'decisions', 'evidence'] as $detailMember) {
            $this->assertArrayHasKey(
                $detailMember,
                $data,
                "TransactionDetail requires `{$detailMember}`"
            );
        }

        $revision = $data['activeRevision'];
        $this->assertIsArray($revision['costCenter'] ?? null, 'Revision.costCenter must be an EntitySnapshot object');
        $this->assertArrayHasKey('name', $revision['costCenter'], 'EntitySnapshot.name is required');
        $this->assertIsArray($revision['expenseType'] ?? null, 'Revision.expenseType must be an EntitySnapshot object');
        $this->assertArrayHasKey('name', $revision['expenseType'], 'EntitySnapshot.name is required');

        foreach (['amountMinor', 'currency', 'minorUnit'] as $moneyMember) {
            $this->assertArrayHasKey($moneyMember, $revision['amount'] ?? [], "Money requires `{$moneyMember}`");
        }

        // The undeclared flat shapes must be gone, or the drift can silently return.
        $this->assertArrayNotHasKey('revision', $data, 'The key is `activeRevision`; the flat `revision` shape is not in the contract');
        $this->assertArrayNotHasKey('ownerId', $data, 'TransactionSummary does not declare `ownerId`');
        $this->assertArrayNotHasKey('costCenterId', $revision, 'Revision declares `costCenter`, not the scalar `costCenterId`');
        $this->assertArrayNotHasKey('expenseTypeTitle', $revision, 'Revision declares `expenseType`, not the scalar `expenseTypeTitle`');
    }

    /**
     * The queue endpoints answer `TransactionSummary`, which carries no decisions or evidence.
     *
     * Asserting the summary shape on a queue keeps the two response kinds from being conflated,
     * which is how `PriorDecisionHistory` came to have nothing to render: the review detail
     * endpoint is the only one that returns a `decisions` array.
     */
    public function testQueueReturnsTransactionSummaryItems(): void
    {
        ContractFixtures::prepare('scope');

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/queues/cost-center');
        $this->assertEquals(200, $response->getStatusCode(), 'GET /queues/cost-center should return 200 OK');

        $items = json_decode((string) $response->getBody(), true)['ocs']['data']['items'] ?? [];
        $this->assertNotEmpty($items, 'The fixture assigns the acting user as CC-100 manager, so the queue is not empty');

        foreach ($items as $item) {
            $this->assertArrayHasKey('activeRevision', $item, 'Queue items must expose `activeRevision`');
            $this->assertArrayHasKey('evidenceCount', $item, 'Queue items must expose `evidenceCount`');
            $this->assertArrayNotHasKey('decisions', $item, 'TransactionSummary does not carry `decisions`');
            $this->assertArrayNotHasKey('revision', $item, 'The key is `activeRevision`');
        }
    }

    // ===== SHEET READ MODEL HONESTY =====

    /**
     * A sheet must not report a transaction count that contradicts its own status.
     *
     * `SheetController::formatSheet()` counted from the `ExpenseSheet` aggregate, which
     * `DbExpenseSheetRepository` does not compose on read, so every sheet reported
     * `transactionCount: 0`. A submitted sheet therefore answered `200` with
     * `status: COST_CENTER` alongside a count of zero — a response that contradicts itself and
     * understates the purchaser's record. The count now comes from the repository.
     */
    public function testSheetDetailReportsItsRealTransactionCount(): void
    {
        ContractFixtures::prepare('draft');

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId());
        $this->assertEquals(200, $response->getStatusCode(), 'GET /sheets/{id} should return 200 OK');

        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];

        // The fixture seeds exactly one transaction on the fixture sheet.
        $this->assertSame(
            1,
            $data['transactionCount'] ?? null,
            'A sheet holding one seeded transaction must report transactionCount 1, not 0'
        );
    }

    /**
     * Submitting a sheet must raise the count, not leave it at the draft figure.
     *
     * The count is derived rather than stored, so this guards the derivation against a future
     * change that reads a stale snapshot instead of the live rows.
     */
    public function testSheetTransactionCountSurvivesSubmission(): void
    {
        ContractFixtures::prepare('draft');

        $submit = $this->client->request(
            'POST',
            '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId() . '/submit',
            [
                'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
                'json' => ['expectedVersion' => ContractFixtures::sheetVersion()],
            ]
        );
        $this->assertEquals(200, $submit->getStatusCode(), 'The fixture sheet should submit');

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId());
        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];

        $this->assertSame('COST_CENTER', $data['status'] ?? null, 'The sheet should now be in cost-center review');
        $this->assertSame(
            1,
            $data['transactionCount'] ?? null,
            'A submitted sheet must still report the transaction it carries'
        );
    }

    // ===== HELPER METHODS =====

    /**
     * Upload a small file to the fixture transaction and return its public id.
     *
     * The evidence assertions have to address a record that really exists: a
     * well-formed UUID that matches nothing only ever proves the 404 branch, so
     * each of them uploads its own evidence first.
     */
    private function uploadEvidence(): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'evidence_');
        file_put_contents($tempFile, str_repeat('x', 1024));

        try {
            $response = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/evidence', [
                'headers' => [
                    'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
                ],
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => fopen($tempFile, 'r'),
                        'filename' => 'contract-fixture-receipt.pdf',
                    ],
                ],
            ]);

            $this->assertEquals(201, $response->getStatusCode(), 'Evidence upload must succeed before its id can be addressed');

            $body = json_decode((string) $response->getBody(), true);
            $this->assertIsArray($body, 'Evidence upload should return valid JSON');
            $this->assertArrayHasKey('id', $body['ocs']['data'] ?? [], 'Evidence upload should return the evidence id');

            return (string) $body['ocs']['data']['id'];
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Assert that the response follows the OCS standard envelope structure.
     * OCS responses have: { "ocs": { "meta": {...}, "data": ... } }
     */
    private function assertOcsResponseStructure(\Psr\Http\Message\ResponseInterface $response, int $expectedStatus): void
    {
        $this->assertEquals($expectedStatus, $response->getStatusCode(), 'Unexpected status code');

        $contentType = $response->getHeaderLine('Content-Type');
        $this->assertStringContainsString('application/json', $contentType, 'Response should be JSON');

        $body = (string)$response->getBody();
        $this->assertNotEmpty($body, 'Response body should not be empty');

        $data = json_decode($body, true);
        $this->assertIsArray($data, 'Response body should be valid JSON');

        // OCS standard envelope
        $this->assertArrayHasKey('ocs', $data, 'Response should have "ocs" root key');
        $ocs = $data['ocs'];

        // OCS meta
        $this->assertArrayHasKey('meta', $ocs, 'OCS envelope should have "meta"');
        $meta = $ocs['meta'];
        $this->assertArrayHasKey('status', $meta, 'OCS meta should have "status"');
        // Nextcloud reports "ok" only for 2xx; every error status is "failure".
        $expectedMetaStatus = $expectedStatus >= 400 ? 'failure' : 'ok';
        $this->assertEquals($expectedMetaStatus, $meta['status'], 'OCS meta.status should match the HTTP status class');
        $this->assertArrayHasKey('statuscode', $meta, 'OCS meta should have "statuscode"');
        $this->assertEquals((string)$expectedStatus, $meta['statuscode'], 'OCS meta.statuscode should match HTTP status');

        // OCS data
        $this->assertArrayHasKey('data', $ocs, 'OCS envelope should have "data"');
    }
}