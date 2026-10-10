<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use OCA\PettyCash\Tests\Contract\Fixture\CorruptRecordFixture;
use PHPUnit\Framework\TestCase;

/**
 * T314 / FR-083: the data-integrity refusal on the `/dashboard` and
 * administrator report paths.
 *
 * FR-083 requires the `422` data-fault answer on **every** read endpoint, but
 * the contract suite holds one set of non-administrator credentials, so every
 * branch an administrator reads was unexercised: the dashboard's administrative
 * summary and the administrator's own view of the sheets report. Administrator
 * scope comes from a petty-cash assignment row alone, so the suite provisions a
 * dedicated probe plus a `role-assignment-fixture.php` grant (revoked
 * afterwards — a leaked row would promote every future incarnation of the
 * probe) rather than promoting the shared credential.
 *
 * Each test follows the T313 shape: the refusal and the withholding in one
 * fixture lifetime, then proof the endpoint answers again once the probe is
 * gone — so a suite that was already broken cannot masquerade as covered.
 */
final class DashboardDataFaultTest extends TestCase
{
    /**
     * A principal this suite creates, promotes, and removes.
     *
     * Named for the task rather than reusing an existing probe account: this
     * suite sets the password and grants the Administrator assignment, and it
     * must not do either to a principal another suite may own.
     */
    private const PROBE_UID = 'pettycash-t314-probe';

    private Client $shared;
    private Client $probe;
    private string $probePassword = '';

    public static function setUpBeforeClass(): void
    {
        // Repair before anything else, unconditionally: a run killed between the
        // corrupt write and its removal would leave the fault in place, and the
        // next run would answer 422 for a reason three files away from its cause.
        CorruptRecordFixture::sweep();
    }

    protected function setUp(): void
    {
        $this->shared = OcsTestClient::create();
        ContractFixtures::prepare('draft');

        $this->probePassword = bin2hex(random_bytes(16));
        $this->runFixture('probe-user-fixture.php', 'create', [$this->probePassword]);
        $this->runFixture('role-assignment-fixture.php', 'grant', ['ADMINISTRATOR']);

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
        // Revoke before purge: the probe fixture reuses an existing user by
        // name, so a leftover assignment would promote its next incarnation.
        // Revocation verifies rather than assumes — a surviving row fails here
        // instead of silently changing what later suites observe.
        $this->runFixture('role-assignment-fixture.php', 'revoke', ['ADMINISTRATOR']);
        $this->runFixture('probe-user-fixture.php', 'purge', []);
    }

    public function testPurchaserDashboardRefusesTheUnreadableRecord(): void
    {
        // The corrupt sheet is owned by the shared credential, so the
        // purchaser-scoped dashboard reads it through the owner branch.
        $probe = CorruptRecordFixture::insert();

        try {
            $response = $this->asShared('/dashboard');
            $this->assertSame(
                422,
                $response['status'],
                'GET /dashboard over an unreadable record must answer 422, not a partial card and not a 500'
            );
            $this->assertStringContainsString(
                'data fault',
                $response['message'],
                'the refusal must name the fault as being in the data'
            );
            $this->assertStringNotContainsString(
                (string) $probe['revisionDbId'],
                $response['raw'],
                'the surrogate key must not reach the caller'
            );
        } finally {
            CorruptRecordFixture::remove();
        }

        $this->assertSame(
            200,
            $this->asShared('/dashboard')['status'],
            'the dashboard must answer 200 once the unreadable record is gone, proving the refusal was the probe’s doing'
        );
    }

    public function testAdministratorDashboardRefusesTheUnreadableRecord(): void
    {
        // The probe administrator sees every record, so the same corrupt sheet
        // is read through the unscoped administrative-summary branch instead.
        $probe = CorruptRecordFixture::insert();

        try {
            $response = $this->asProbe('/dashboard');
            $this->assertSame(
                422,
                $response['status'],
                'the administrative summary must answer 422 over an unreadable record, like every other read'
            );
            $this->assertStringContainsString(
                'data fault',
                $response['message'],
                'the refusal must name the fault as being in the data'
            );
            $this->assertStringNotContainsString(
                (string) $probe['revisionDbId'],
                $response['raw'],
                'the surrogate key must not reach the caller'
            );
        } finally {
            CorruptRecordFixture::remove();
        }

        $this->assertSame(
            200,
            $this->asProbe('/dashboard')['status'],
            'the administrative summary must answer 200 once the unreadable record is gone'
        );
    }

    public function testAdministratorSheetsReportRefusesTheUnreadableRecord(): void
    {
        $probe = CorruptRecordFixture::insert();

        try {
            $response = $this->asProbe('/reports/sheets');
            $this->assertSame(
                422,
                $response['status'],
                'the administrator’s own view of the sheets report must answer 422 over an unreadable record'
            );
            $this->assertStringContainsString(
                'data fault',
                $response['message'],
                'the refusal must name the fault as being in the data'
            );
            $this->assertStringNotContainsString(
                (string) $probe['revisionDbId'],
                $response['raw'],
                'the surrogate key must not reach the caller'
            );
        } finally {
            CorruptRecordFixture::remove();
        }

        $this->assertSame(
            200,
            $this->asProbe('/reports/sheets')['status'],
            'the sheets report must answer 200 once the unreadable record is gone'
        );
    }

    /** @return array{status: int, message: string, raw: string} */
    private function asShared(string $path): array
    {
        return $this->decode($this->shared->request('GET', '/ocs/v2.php/apps/pettycash/api/v1' . $path));
    }

    /** @return array{status: int, message: string, raw: string} */
    private function asProbe(string $path): array
    {
        return $this->decode($this->probe->request('GET', '/ocs/v2.php/apps/pettycash/api/v1' . $path));
    }

    /** @return array{status: int, message: string, raw: string} */
    private function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('The response was not a JSON OCS envelope.');
        }

        return [
            'status' => $response->getStatusCode(),
            'message' => (string) ($decoded['ocs']['data']['error']['message'] ?? ''),
            'raw' => $raw,
        ];
    }

    /** @return array<string, mixed> */
    private function runFixture(string $script, string $action, array $args): array
    {
        $fixture = dirname(__DIR__) . '/Fixture/' . $script;
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' ' . $action . ' ' . escapeshellarg(self::PROBE_UID);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        // Argument order follows both fixtures' CLI shape: `<action> <uid>`
        // with any further arguments appended (`create` takes `<password>`
        // after the uid).
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
