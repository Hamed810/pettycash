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