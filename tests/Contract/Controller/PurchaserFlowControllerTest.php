<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
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
        // Inside the Nextcloud container, the web server runs on port 80
        $this->baseUrl = 'http://localhost/ocs/v2.php/apps/pettycash/api/v1';
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Accept' => 'application/json',
                'OCS-APIRequest' => 'true',
            ],
            'http_errors' => false,
            'verify' => false,
        ]);
    }

    // ===== SHEETS =====

    public function testCreateSheetReturns201WithSheetDetail(): void
    {
        $response = $this->client->request('POST', '/sheets', [
            'headers' => [
                'X-PettyCash-Command-ID' => '11111111-1111-1111-1111-111111111111',
            ],
            'json' => [
                'title' => 'Test Sheet',
                'accountId' => '00000000-0000-0000-0000-000000000000',
            ],
        ]);

        // Contract: POST /sheets returns 201 with SheetDetail in ocs.data
        $this->assertEquals(201, $response->getStatusCode(), 'POST /sheets should return 201 Created');
        $this->assertOcsResponseStructure($response, 201);
    }

    public function testListSheetsReturns200WithPagedSheets(): void
    {
        $response = $this->client->request('GET', '/sheets');

        // Contract: GET /sheets returns 200 with paged SheetSummary array
        $this->assertEquals(200, $response->getStatusCode(), 'GET /sheets should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testGetSheetDetailReturns200WithSheetDetail(): void
    {
        $response = $this->client->request('GET', '/sheets/00000000-0000-0000-0000-000000000000');

        // Contract: GET /sheets/{id} returns 200 with SheetDetail, or 403/404
        $this->assertEquals(200, $response->getStatusCode(), 'GET /sheets/{id} should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testUpdateSheetReturns200WithUpdatedSheetDetail(): void
    {
        $response = $this->client->request('PATCH', '/sheets/00000000-0000-0000-0000-000000000000', [
            'headers' => [
                'X-PettyCash-Command-ID' => '22222222-2222-2222-2222-222222222222',
            ],
            'json' => [
                'title' => 'Updated Title',
                'accountId' => '00000000-0000-0000-0000-000000000000',
                'expectedVersion' => 1,
            ],
        ]);

        // Contract: PATCH /sheets/{id} returns 200 with updated SheetDetail
        $this->assertEquals(200, $response->getStatusCode(), 'PATCH /sheets/{id} should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testSubmitSheetReturns200WithSubmittedSheetDetail(): void
    {
        $response = $this->client->request('POST', '/sheets/00000000-0000-0000-0000-000000000000/submit', [
            'headers' => [
                'X-PettyCash-Command-ID' => '33333333-3333-3333-3333-333333333333',
            ],
            'json' => [
                'expectedVersion' => 1,
            ],
        ]);

        // Contract: POST /sheets/{id}/submit returns 200 with SheetDetail, or 422/409
        $this->assertEquals(200, $response->getStatusCode(), 'POST /sheets/{id}/submit should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    // ===== TRANSACTIONS =====

    public function testCreateTransactionReturns201WithTransactionDetail(): void
    {
        $response = $this->client->request('POST', '/sheets/00000000-0000-0000-0000-000000000000/transactions', [
            'headers' => [
                'X-PettyCash-Command-ID' => '44444444-4444-4444-4444-444444444444',
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
        $response = $this->client->request('GET', '/transactions/00000000-0000-0000-0000-000000000000');

        // Contract: GET /transactions/{id} returns 200 with TransactionDetail
        $this->assertEquals(200, $response->getStatusCode(), 'GET /transactions/{id} should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testUpdateTransactionReturns200WithUpdatedTransactionDetail(): void
    {
        $response = $this->client->request('PATCH', '/transactions/00000000-0000-0000-0000-000000000000', [
            'headers' => [
                'X-PettyCash-Command-ID' => '55555555-5555-5555-5555-555555555555',
            ],
            'json' => [
                'expenseDate' => '2026-09-21',
                'amountMinor' => 2000,
                'expectedVersion' => 1,
            ],
        ]);

        // Contract: PATCH /transactions/{id} returns 200 with updated TransactionDetail
        $this->assertEquals(200, $response->getStatusCode(), 'PATCH /transactions/{id} should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testDeleteTransactionReturns204(): void
    {
        $response = $this->client->request('DELETE', '/transactions/00000000-0000-0000-0000-000000000000', [
            'headers' => [
                'X-PettyCash-Command-ID' => '66666666-6666-6666-6666-666666666666',
            ],
        ]);

        // Contract: DELETE /transactions/{id} returns 204 No Content
        $this->assertEquals(204, $response->getStatusCode(), 'DELETE /transactions/{id} should return 204 No Content');
    }

    public function testRecordDecisionReturns201WithDecisionResult(): void
    {
        $response = $this->client->request('POST', '/transactions/00000000-0000-0000-0000-000000000000/decisions', [
            'headers' => [
                'X-PettyCash-Command-ID' => '77777777-7777-7777-7777-777777777777',
            ],
            'json' => [
                'decision' => 'APPROVE',
                'expectedVersion' => 1,
            ],
        ]);

        // Contract: POST /transactions/{id}/decisions returns 201 with DecisionResult, or 403/409/422
        $this->assertEquals(201, $response->getStatusCode(), 'POST /transactions/{id}/decisions should return 201 Created');
        $this->assertOcsResponseStructure($response, 201);
    }

    public function testCreateCorrectionReturns201WithRevision(): void
    {
        $response = $this->client->request('POST', '/transactions/00000000-0000-0000-0000-000000000000/correction', [
            'headers' => [
                'X-PettyCash-Command-ID' => '88888888-8888-8888-8888-888888888888',
            ],
        ]);

        // Contract: POST /transactions/{id}/correction returns 201 with Revision
        $this->assertEquals(201, $response->getStatusCode(), 'POST /transactions/{id}/correction should return 201 Created');
        $this->assertOcsResponseStructure($response, 201);
    }

    public function testResubmitCorrectionReturns200WithTransactionDetail(): void
    {
        $response = $this->client->request('POST', '/transactions/00000000-0000-0000-0000-000000000000/resubmit', [
            'headers' => [
                'X-PettyCash-Command-ID' => '99999999-9999-9999-9999-999999999999',
            ],
            'json' => [
                'expectedVersion' => 2,
            ],
        ]);

        // Contract: POST /transactions/{id}/resubmit returns 200 with TransactionDetail
        $this->assertEquals(200, $response->getStatusCode(), 'POST /transactions/{id}/resubmit should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testListRevisionsReturns200WithRevisions(): void
    {
        $response = $this->client->request('GET', '/transactions/00000000-0000-0000-0000-000000000000/revisions');

        // Contract: GET /transactions/{id}/revisions returns 200 with array of Revision
        $this->assertEquals(200, $response->getStatusCode(), 'GET /transactions/{id}/revisions should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testListAuditReturns200WithAuditPage(): void
    {
        $response = $this->client->request('GET', '/transactions/00000000-0000-0000-0000-000000000000/audit', [
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
            $response = $this->client->request('POST', '/transactions/00000000-0000-0000-0000-000000000000/evidence', [
                'headers' => [
                    'X-PettyCash-Command-ID' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
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
        $response = $this->client->request('DELETE', '/evidence/00000000-0000-0000-0000-000000000000', [
            'headers' => [
                'X-PettyCash-Command-ID' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            ],
        ]);

        // Contract: DELETE /evidence/{id} returns 200 with deactivated Evidence metadata
        $this->assertEquals(200, $response->getStatusCode(), 'DELETE /evidence/{id} should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testGetEvidenceContentReturns200WithStream(): void
    {
        $response = $this->client->request('GET', '/evidence/00000000-0000-0000-0000-000000000000/content', [
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
            $response = $this->client->request('POST', '/transactions/00000000-0000-0000-0000-000000000000/evidence', [
                'headers' => [
                    'X-PettyCash-Command-ID' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
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
            $response = $this->client->request('POST', '/transactions/00000000-0000-0000-0000-000000000000/evidence', [
                'headers' => [
                    'X-PettyCash-Command-ID' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
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

    // ===== HELPER METHODS =====

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
        $this->assertEquals('ok', $meta['status'], 'OCS meta.status should be "ok" for success responses');
        $this->assertArrayHasKey('statuscode', $meta, 'OCS meta should have "statuscode"');
        $this->assertEquals((string)$expectedStatus, $meta['statuscode'], 'OCS meta.statuscode should match HTTP status');

        // OCS data
        $this->assertArrayHasKey('data', $ocs, 'OCS envelope should have "data"');
    }
}