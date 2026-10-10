<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;

/**
 * T092 / FR-051 / FR-055 (contract half): revision history, transaction audit,
 * and evidence-deactivation history over HTTP.
 *
 * Formal audit follows transaction visibility (FR-055): the owner, an in-scope
 * reviewer, finance at its stage, and an administrator may read a transaction's
 * audit and revisions; anyone outside that scope gets the same 404 hiding the
 * endpoint gives for a record that does not exist.
 *
 * The readers below are deliberately never the owner. The shared credential
 * owns every fixture transaction, so asserting through it would pass through
 * the ownership gate and prove nothing about the role path — which is exactly
 * why the scope defect survived: every suite acted as the owner. A dedicated
 * probe holds only the role under test, granted and revoked around each read
 * through `role-assignment-fixture.php`.
 *
 * (The Vue half of T092 — `AuditEvidence.spec.ts` — rides T098, which owns the
 * components it would mount.)
 */
final class HistoryEvidenceControllerTest extends TestCase
{
    /**
     * A principal this suite creates and removes, holding only the role under
     * test at any moment. Named for the task rather than reusing an existing
     * probe account.
     */
    private const PROBE_UID = 'pettycash-t092-probe';

    private Client $client;
    private Client $probe;
    private string $probePassword = '';

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();

        $this->probePassword = bin2hex(random_bytes(16));
        $this->runFixture('probe-user-fixture.php', 'create', [$this->probePassword]);

        $baseUrl = getenv('PETTYCASH_TEST_BASE_URL') ?: 'http://localhost';
        $this->probe = new Client([
            'base_uri' => $baseUrl,
            'headers' => ['Accept' => 'application/json', 'OCS-APIRequest' => 'true'],
            'http_errors' => false,
            'verify' => false,
            'allow_redirects' => false,
            'auth' => [self::PROBE_UID, $this->probePassword],
        ]);
    }

    protected function tearDown(): void
    {
        // Revoke every assignment the tests granted: the probe fixture reuses
        // an existing user by name, so a leftover row would promote its next
        // incarnation and silently widen every later scope assertion.
        $this->runFixture('role-assignment-fixture.php', 'revoke', []);
        $this->runFixture('probe-user-fixture.php', 'purge', []);
    }

    /**
     * A cost-center manager reads the audit of a transaction routed to the cost
     * center they manage. Owner-only gating answers this 404; the manager is
     * entitled to the history of work they are asked to decide.
     */
    public function testManagerReadsAuditOfInScopeTransaction(): void
    {
        ContractFixtures::prepare('submitted');
        $this->grant('COST_CENTER_MANAGER', ContractFixtures::costCenterId());

        try {
            $response = $this->probe->request(
                'GET',
                '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/audit'
            );

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $data = $this->data($response);
            foreach (['page', 'pageSize', 'total', 'items'] as $key) {
                self::assertArrayHasKey($key, $data, "the AuditPage must carry {$key}");
            }
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['COST_CENTER_MANAGER']);
        }
    }

    /**
     * Finance reads the audit of a transaction at the finance stage.
     */
    public function testFinanceReadsAuditAtFinanceStage(): void
    {
        ContractFixtures::prepare('finance');
        $this->grant('FINANCE');

        try {
            $response = $this->probe->request(
                'GET',
                '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::unmanagedFinanceTransactionId() . '/audit'
            );

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    /**
     * An administrator reads the audit of a transaction they own nothing of.
     */
    public function testAdministratorReadsAudit(): void
    {
        ContractFixtures::prepare('submitted');
        $this->grant('ADMINISTRATOR');

        try {
            $response = $this->probe->request(
                'GET',
                '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/audit'
            );

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['ADMINISTRATOR']);
        }
    }

    /**
     * Outside every scope the endpoint hides rather than refuses: 404, not 403,
     * so it cannot confirm a record the caller may not see. The probe holds no
     * role here, so this guards the hiding rule itself rather than any one role.
     */
    public function testOutOfScopeReaderGetsNotFound(): void
    {
        ContractFixtures::prepare('scope');

        $response = $this->probe->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::outOfScopeTransactionId() . '/audit'
        );

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * Revisions follow the same visibility as audit: an in-scope manager reads
     * them, and the immutable history is what they get back.
     */
    public function testManagerReadsRevisionsOfInScopeTransaction(): void
    {
        ContractFixtures::prepare('submitted');
        $this->grant('COST_CENTER_MANAGER', ContractFixtures::costCenterId());

        try {
            $response = $this->probe->request(
                'GET',
                '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/revisions'
            );

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $items = $this->data($response);
            self::assertNotEmpty($items, 'a submitted transaction has a revision to show');
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['COST_CENTER_MANAGER']);
        }
    }

    /**
     * An in-scope manager reads the transaction detail itself, not just its
     * history: the review-detail view is fed by this endpoint, and owner-only
     * gating answered every reviewer with 404 on the record the queue listed
     * as awaiting their decision. The capabilities pin the formatter fix
     * alongside: a cost-center manager must be offered all three decisions.
     */
    public function testManagerReadsDetailOfInScopeTransaction(): void
    {
        ContractFixtures::prepare('submitted');
        $this->grant('COST_CENTER_MANAGER', ContractFixtures::costCenterId());

        try {
            $response = $this->probe->request(
                'GET',
                '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId()
            );

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $actions = $this->data($response)['allowedActions'] ?? [];
            foreach (['canApprove', 'canReject', 'canReturn'] as $capability) {
                self::assertTrue(
                    (bool) ($actions[$capability] ?? false),
                    "an in-scope cost-center manager must be offered {$capability}"
                );
            }
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['COST_CENTER_MANAGER']);
        }
    }

    /**
     * The detail endpoint hides like the history reads: 404, not 403.
     */
    public function testOutOfScopeReaderGetsNotFoundOnDetail(): void
    {
        ContractFixtures::prepare('scope');

        $response = $this->probe->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::outOfScopeTransactionId()
        );

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * FR-059: deactivated evidence keeps its metadata readable. Deactivating
     * returns the metadata with `active: false`, and the transaction detail
     * still lists the item rather than dropping it.
     */
    public function testDeactivatedEvidenceHistoryRemainsListed(): void
    {
        ContractFixtures::prepare('correction');
        $evidenceId = ContractFixtures::evidenceId();
        self::assertNotNull($evidenceId, 'the correction state must seed evidence to deactivate');

        $deactivated = $this->client->request(
            'DELETE',
            '/ocs/v2.php/apps/pettycash/api/v1/evidence/' . $evidenceId
        );
        self::assertSame(200, $deactivated->getStatusCode(), (string) $deactivated->getBody());
        self::assertFalse(
            (bool) ($this->data($deactivated)['active'] ?? true),
            'the deactivation must report the item inactive'
        );

        $detail = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId()
        );
        self::assertSame(200, $detail->getStatusCode(), (string) $detail->getBody());

        $foundInactive = false;
        foreach ($this->data($detail)['evidence'] ?? [] as $item) {
            if (($item['id'] ?? null) === $evidenceId && ($item['active'] ?? true) === false) {
                $foundInactive = true;
            }
        }
        self::assertTrue($foundInactive, 'the inactive item must remain listed with active=false');
    }

    private function grant(string $role, ?string $costCenterId = null): void
    {
        $args = [$role];
        if ($costCenterId !== null) {
            $args[] = $costCenterId;
        }
        $manifest = $this->runFixture('role-assignment-fixture.php', 'grant', $args);
        self::assertArrayHasKey('assignmentId', $manifest, 'the grant must identify the row it wrote');
    }

    /** @return array<string, mixed> */
    private function data(object $response): array
    {
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body, 'The response body is JSON');

        return $body['ocs']['data'] ?? [];
    }

    /** @return array<string, mixed> */
    private function runFixture(string $script, string $action, array $args): array
    {
        $fixture = dirname(__DIR__) . '/Fixture/' . $script;
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' ' . $action . ' ' . escapeshellarg(self::PROBE_UID);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        // Both fixtures take `<action> <uid>` with further arguments appended
        // (`create` takes `<password>` after the uid, `grant` takes `<role>`
        // and optionally the cost center).
        $output = shell_exec($command . ' 2>&1');

        self::assertIsString($output, 'the fixture must run');
        $decoded = json_decode(trim($output), true);
        self::assertIsArray(
            $decoded,
            "{$script} did not return a manifest: " . $output
        );

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
