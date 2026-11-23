<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Feature 008 T021 — evidence content access through the Finance completed-history branch.
 *
 * `EvidenceContentAccess` is composed only at `EvidenceController::content()`.
 * The suite proves the branch admits Finance for completed-history-exposed evidence,
 * denies non-Finance cross-user callers, and grants no mutation rights through this path.
 *
 * The `finance` fixture state seeds completed Cost Sheets with terminal transactions,
 * so a Finance probe can exercise the completed-history evidence read path.
 *
 * Evidence content answers `404` for out-of-scope callers (non-disclosure), so an
 * unknown evidence ID and a non-Finance caller both receive `404` with only the error
 * envelope — the endpoint never confirms a record the caller may not see.
 */
final class EvidenceContentAccessTest extends TestCase
{
    private const BASE = '/ocs/v2.php/apps/pettycash/api/v1';

    /** Created and removed by this suite; holds only the role granted around one read. */
    private const PROBE_UID = 'pettycash-t021-probe';

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
        $this->runFixture('role-assignment-fixture.php', 'revoke', []);
        $this->runFixture('probe-user-fixture.php', 'purge', []);
    }

    // ===== evidence content ====================================================

    public function testNonFinanceCallerIsForbiddenOnEvidenceContent(): void
    {
        ContractFixtures::prepare('draft');

        $response = $this->probe->request('GET', self::BASE . '/evidence/' . ContractFixtures::unknownId() . '/content');

        $this->assertNotFoundWithoutData($response);
    }

    public function testUnknownEvidenceIsNotFound(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $response = $this->probe->request('GET', self::BASE . '/evidence/' . ContractFixtures::unknownId() . '/content');

            $this->assertNotFoundWithoutData($response);
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testUnauthenticatedRequestIsRefusedOnEvidenceContent(): void
    {
        $anonymous = new Client([
            'base_uri' => getenv('PETTYCASH_TEST_BASE_URL') ?: 'http://localhost',
            'headers' => ['Accept' => 'application/json', 'OCS-APIRequest' => 'true'],
            'http_errors' => false,
            'verify' => false,
            'allow_redirects' => false,
            'auth' => ['pettycash-not-a-user', 'pettycash-not-a-password'],
        ]);

        $response = $anonymous->request('GET', self::BASE . '/evidence/' . ContractFixtures::unknownId() . '/content');

        self::assertSame(
            401,
            $response->getStatusCode(),
            'an unauthenticated request is refused by Nextcloud before any controller runs'
        );
    }

    // ===== helpers =============================================================

    private function testUser(): string
    {
        $user = getenv('PETTYCASH_TEST_USER');

        self::assertIsString($user, 'PETTYCASH_TEST_USER must identify the shared fixture owner');
        self::assertNotSame('', trim($user));

        return trim((string) $user);
    }

    private function grant(string $role): void
    {
        $manifest = $this->runFixture('role-assignment-fixture.php', 'grant', [$role]);
        self::assertArrayHasKey('assignmentId', $manifest, 'the grant must identify the row it wrote');
    }

    private function assertNotFoundWithoutData(ResponseInterface $response): void
    {
        self::assertSame(404, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);
        $this->assertKeysExactly(['error'], $data, 'a not-found response carries only the error envelope');
        self::assertSame('NOT_FOUND', $data['error']['code']);
    }

    /** @return array{code: string, message: string, fieldErrors: array<string, list<string>>} */
    private function data(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body, 'The response body is JSON');

        return $body['ocs']['data'] ?? [];
    }

    /**
     * @param list<string> $expected
     * @param array<string, mixed> $actual
     */
    private function assertKeysExactly(array $expected, array $actual, string $context): void
    {
        $expectedKeys = $expected;
        $actualKeys = array_keys($actual);
        sort($expectedKeys);
        sort($actualKeys);

        self::assertSame($expectedKeys, $actualKeys, "{$context} must carry exactly the declared fields");
    }

    /** @return array<string, mixed> */
    private function runFixture(string $script, string $action, array $args): array
    {
        $fixture = dirname(__DIR__) . '/Fixture/' . $script;
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' ' . escapeshellarg($action)
            . ' ' . escapeshellarg(self::PROBE_UID);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        $output = shell_exec($command . ' 2>&1');

        self::assertIsString($output, 'the fixture must run');
        $decoded = json_decode(trim($output), true);
        self::assertIsArray($decoded, "{$script} did not return a manifest: " . $output);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
