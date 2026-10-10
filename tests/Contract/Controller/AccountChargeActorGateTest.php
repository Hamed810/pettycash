<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;

/**
 * T291 / FR-015: the actor gate on `POST /accounts/{id}/charges`, observed over
 * HTTP by an identity that holds no charging role.
 *
 * Only `FINANCE` or an administrator may charge. The service-level gate is proven
 * in `tests/Integration/Account/AccountChargeAuthorizationTest.php` (removing it
 * fails 5); what was missing is the HTTP observable: the contract suite's single
 * credential holds `FINANCE` in the only state the charge tests can prepare, so a
 * refusal was unexpressible with one user. A dedicated probe purchaser — no
 * finance assignment, no administrator right — supplies the second identity
 * without touching the shared credential or any harness.
 *
 * The refusal is ordered before every other check on purpose: the probe sends a
 * stale `expectedVersion` (0) against a real account with a valid body, so a 403
 * proves the actor gate runs first rather than the version check. A 409 here
 * would mean the gate lost its position.
 */
final class AccountChargeActorGateTest extends TestCase
{
    /**
     * A principal this suite creates and removes.
     *
     * A plain purchaser: no role argument, so no `admin`-group membership and no
     * petty-cash assignment of any kind.
     */
    private const PROBE_UID = 'pettycash-t291-probe';

    private Client $probe;
    private string $probePassword = '';

    protected function setUp(): void
    {
        ContractFixtures::prepare('draft');

        $this->probePassword = bin2hex(random_bytes(16));
        $this->runFixture('create', [$this->probePassword]);

        // Basic auth as the probe: `OcsTestClient` is a singleton bound to the
        // shared credential, so a probe-authenticated client is built directly.
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
        $this->runFixture('purge', []);
    }

    public function testChargeWithoutAChargingRoleIsRefused(): void
    {
        $refused = $this->probe->request(
            'POST',
            '/ocs/v2.php/apps/pettycash/api/v1/accounts/' . ContractFixtures::accountId() . '/charges',
            [
                'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
                'json' => [
                    'amountMinor' => 1000,
                    'description' => 'T291: a purchaser must not fund accounts',
                    'occurredAt' => '2026-09-27T10:00:00+00:00',
                    // Deliberately stale: the actor gate must fire before the
                    // version check, so a 409 here would be the wrong refusal.
                    'expectedVersion' => 0,
                ],
            ]
        );

        self::assertSame(403, $refused->getStatusCode(), 'charging without FINANCE or administrator role must be refused');
        self::assertStringContainsString(
            'Only finance may charge',
            (string) $refused->getBody(),
            'the refusal must name the missing role rather than merely forbidding'
        );
    }

    /** @return array<string, mixed> */
    private function runFixture(string $action, array $args): array
    {
        $fixture = dirname(__DIR__) . '/Fixture/probe-user-fixture.php';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' ' . $action . ' ' . escapeshellarg(self::PROBE_UID);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        // Argument order follows the fixture's CLI shape:
        // `create <uid> <password> [role]`, `purge <uid>`.
        $output = shell_exec($command . ' 2>&1');

        self::assertIsString($output, 'the probe fixture must run');
        $decoded = json_decode(trim($output), true);
        self::assertIsArray(
            $decoded,
            'probe-user-fixture.php did not return a manifest: ' . $output
        );

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
