<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for ManagementReviewController OCS API endpoints.
 *
 * These tests verify that the OCS API endpoints defined in contracts/ocs-api.yaml
 * for management review (cost-center, senior, finance queues and decisions) are
 * implemented as the contract specifies.
 *
 * Each decision assertion stages the fixture transaction fresh, because deciding it
 * moves it out of cost-center review and the next assertion would otherwise be
 * asserting against a transaction that is no longer in the state it names.
 */
final class ManagementReviewControllerTest extends TestCase
{
    private Client $client;
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = 'http://localhost';
        $this->client = OcsTestClient::create();
        ContractFixtures::prepare('submitted');
    }

    /**
     * Decide the staged transaction. The decision endpoint is addressed by the
     * transaction's real public id, never by a placeholder UUID.
     */
    private function decide(string $decision, ?string $explanation = null, bool $withCommandId = true): \Psr\Http\Message\ResponseInterface
    {
        $payload = [
            'decision' => $decision,
            'expectedVersion' => ContractFixtures::transactionVersion(),
        ];
        if ($explanation !== null) {
            $payload['explanation'] = $explanation;
        }

        $headers = [];
        if ($withCommandId) {
            $headers['X-PettyCash-Command-ID'] = ContractFixtures::commandId();
        }

        return $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/decisions', [
            'headers' => $headers,
            'json' => $payload,
        ]);
    }

    // ===== QUEUES =====

    public function testCostCenterQueueReturns200WithPagedTransactions(): void
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/queues/cost-center');

        // Contract: GET /queues/cost-center returns 200 with TransactionPage
        $this->assertEquals(200, $response->getStatusCode(), 'GET /queues/cost-center should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testSeniorQueueReturns200WithPagedTransactions(): void
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/queues/senior');

        // Contract: GET /queues/senior returns 200 with TransactionPage
        $this->assertEquals(200, $response->getStatusCode(), 'GET /queues/senior should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testFinanceQueueReturns200WithPagedTransactions(): void
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/queues/finance');

        // Contract: GET /queues/finance returns 200 with TransactionPage
        $this->assertEquals(200, $response->getStatusCode(), 'GET /queues/finance should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testInvalidQueueStageReturns400Or404(): void
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/queues/invalid-stage');

        // Contract: Invalid stage should return 400 or 404
        $this->assertTrue(
            in_array($response->getStatusCode(), [400, 404], true),
            'GET /queues/invalid-stage should return 400 or 404'
        );
    }

    // ===== RETURNS (PURCHASER CORRECTION QUEUE) =====

    public function testReturnsQueueReturns200WithPagedTransactions(): void
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/returns');

        // Contract: GET /returns returns 200 with TransactionPage
        $this->assertEquals(200, $response->getStatusCode(), 'GET /returns should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    // ===== DECISIONS =====

    public function testRecordDecisionApproveReturns201WithDecisionResult(): void
    {
        $response = $this->decide('APPROVE');

        // Contract: POST /transactions/{id}/decisions with APPROVE returns 201 with DecisionResult
        // or 403/409/422 for authorization/conflict/validation errors
        $this->assertEquals(201, $response->getStatusCode(), 'POST /transactions/{id}/decisions with APPROVE should return 201 Created');
        $this->assertOcsResponseStructure($response, 201);
    }

    public function testRecordDecisionRejectRequiresExplanation(): void
    {
        // No explanation supplied - the contract requires one for this decision.
        $response = $this->decide('REJECT');

        // Contract: REJECT requires explanation - should return 422 ValidationError
        $this->assertEquals(422, $response->getStatusCode(), 'REJECT without explanation should return 422 ValidationError');
        $this->assertOcsResponseStructure($response, 422);
    }

    public function testRecordDecisionRejectWithExplanationReturns201(): void
    {
        $response = $this->decide('REJECT', 'Invalid expense');

        // Contract: REJECT with explanation returns 201 with DecisionResult
        $this->assertEquals(201, $response->getStatusCode(), 'REJECT with explanation should return 201 Created');
        $this->assertOcsResponseStructure($response, 201);
    }

    public function testRecordDecisionReturnRequiresExplanation(): void
    {
        // No explanation supplied - the contract requires one for this decision.
        $response = $this->decide('RETURN');

        // Contract: RETURN requires explanation - should return 422 ValidationError
        $this->assertEquals(422, $response->getStatusCode(), 'RETURN without explanation should return 422 ValidationError');
        $this->assertOcsResponseStructure($response, 422);
    }

    public function testRecordDecisionReturnWithExplanationReturns201(): void
    {
        $response = $this->decide('RETURN', 'Missing evidence');

        // Contract: RETURN with explanation returns 201 with DecisionResult
        $this->assertEquals(201, $response->getStatusCode(), 'RETURN with explanation should return 201 Created');
        $this->assertOcsResponseStructure($response, 201);
    }

    public function testRecordDecisionInvalidDecisionReturns400Or422(): void
    {
        $response = $this->decide('INVALID');

        // Contract: Invalid decision value should return 400 or 422
        $this->assertTrue(
            in_array($response->getStatusCode(), [400, 422], true),
            'Invalid decision should return 400 or 422'
        );
    }

    /**
     * A revision read back from the database must still carry its business fields.
     *
     * `DbTransactionRevisionRepository::save()` writes `description()` and
     * `expenseTypeTitle()` back to the row, so a revision whose accessors came back
     * empty from `mapRow()` had its description overwritten with an empty string and
     * its expense type set to NULL every time it was saved. Recording a decision
     * saves the transaction, so this exercises that write-then-read round trip
     * against the real repository and guards the data loss.
     */
    public function testDecisionDoesNotEraseTheRevisionBusinessFields(): void
    {
        $response = $this->decide('APPROVE');
        $this->assertEquals(201, $response->getStatusCode(), 'The staged decision should be accepted');

        $detail = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId());
        $this->assertEquals(200, $detail->getStatusCode(), 'The decided transaction should still be readable');

        $body = json_decode((string) $detail->getBody(), true);
        $revision = $body['ocs']['data']['revision'] ?? [];

        $this->assertNotEmpty(
            $revision['description'] ?? null,
            'A saved revision must keep its description; an empty value means save() wrote back a lost field'
        );
        $this->assertNotEmpty(
            $revision['expenseType']['name'] ?? null,
            'A saved revision must keep its expense type'
        );
        $this->assertSame(
            'CC-100',
            $revision['costCenter']['code'] ?? null,
            'A saved revision must keep its cost-center snapshot'
        );
    }

    public function testRecordDecisionMissingCommandIdReturns400(): void
    {
        $response = $this->decide('APPROVE', null, false);

        // Contract: Missing X-PettyCash-Command-ID should return 400
        $this->assertEquals(400, $response->getStatusCode(), 'Missing command ID should return 400');
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
        if ($expectedStatus < 400) {
            $this->assertEquals('ok', $meta['status'], 'OCS meta.status should be "ok" for success responses');
        }
        $this->assertArrayHasKey('statuscode', $meta, 'OCS meta should have "statuscode"');
        $this->assertEquals((string)$expectedStatus, $meta['statuscode'], 'OCS meta.statuscode should match HTTP status');

        // OCS data
        $this->assertArrayHasKey('data', $ocs, 'OCS envelope should have "data"');
    }
}