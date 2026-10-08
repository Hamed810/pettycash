<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use PHPUnit\Framework\TestCase;

/**
 * T391 / ADR-005: FR-054 and SC-012, observed across a **real** Nextcloud user deletion.
 *
 * ## Why this had to be a live test
 *
 * FR-054 says disabling **or removing** a user "MUST NOT delete or alter their historical identity,
 * roles-at-event, decisions, records, or in-progress workflow history", and Constitution IV says
 * "Historical actor identity and role-at-event MUST survive account disablement or deletion." Both are
 * claims about a database, and neither had ever been observed across a deletion. T090 covers *disabling*
 * at the service layer; nothing reached an actual `IUser::delete()`.
 *
 * Until this file the claim was not merely untested, it was **falsified**: with no listener registered
 * and no foreign key declared, deleting a user left their account `active` with its balance, unreachable
 * through every API yet counted by the administrator dashboard.
 *
 * ## Why a subprocess rather than an in-process call
 *
 * The listener is resolved and dispatched through Nextcloud's real container and event dispatcher, which
 * means the test cannot construct it — it must let Nextcloud build it. `probe-user-fixture.php delete`
 * does exactly that, from a script that boots the server, so what runs here is the registration in
 * `Application::register()` rather than a hand-wired listener that would prove nothing about it.
 *
 * A subprocess is also the only way to observe the *outcome*: the account rows outlive the script, and
 * reading them back is what turns "the listener ran" into "the listener did the right thing".
 *
 * ## What this deliberately does not assert
 *
 * It does not assert anything about **sheets, transactions, revisions, decisions or audit** belonging to
 * the deleted user. Those are FR-054's other clauses and no task has settled what a reader should see
 * for them; ADR-005 records that as explicitly undecided rather than letting a test imply otherwise.
 */
final class DeletedUserRetentionContractTest extends TestCase
{
    private const PROBE_UID = 'pettycash-t391-probe';

    private string $probePassword;

    protected function setUp(): void
    {
        $this->probePassword = $this->strongPassword();
    }

    /**
     * A password that Nextcloud will actually accept.
     *
     * Two constraints, both found by being wrong first:
     *
     * - **No raw bytes.** `random_bytes()` produces arbitrary bytes, and several are ASCII control
     *   characters. Guzzle refuses those outright — "Basic authentication credentials must not contain
     *   ASCII control characters" — so the request never left the process and the failure looked like a
     *   provisioning problem. Hex is byte-safe.
     * - **Must satisfy `password_policy`.** Nextcloud requires at least 10 characters and rejects the
     *   million most common; `createUser()` throws `HintException` otherwise. Two UUIDs concatenated give
     *   73 characters of hex, which satisfies both with room to spare.
     */
    private function strongPassword(): string
    {
        return $this->commandId() . '-' . $this->commandId();
    }

    protected function tearDown(): void
    {
        // `purge`, not `delete`: this suite's `delete` deliberately leaves rows behind because that is
        // the product behaviour under test, and leaving them would block the *next* run's precondition.
        $this->fixture('purge', self::PROBE_UID);
    }

    /**
     * FR-054 / ADR-005: the account survives its owner, deactivated, with its balance intact.
     *
     * All three are asserted because any one alone is satisfiable by the wrong behaviour: a delete
     * satisfies "not active", a bare row-drop satisfies "no active account" while destroying the balance
     * FR-053 and FR-057 forbid removing, and an untouched active row satisfies "row still there".
     */
    public function testDeletingAUserDeactivatesTheirAccountWithoutDeletingItOrItsBalance(): void
    {
        $this->fixture('create', self::PROBE_UID, $this->probePassword);
        $provisioned = $this->provisionAccountViaHttp();
        self::assertNotNull($provisioned['accountId'] ?? null, 'the probe needs a real account first');

        $after = $this->fixture('delete', self::PROBE_UID);

        self::assertSame('deleted', $after['outcome'] ?? null, 'the principal itself must be gone');

        // The retention half: the row is still there, and it is still the one the sheet drew on.
        self::assertSame(
            1,
            $after['accountsHeld'] ?? null,
            'FR-054: nothing may be deleted. A deleted user\'s account must still exist as history.'
        );
        self::assertContains(
            $provisioned['accountId'],
            $after['accountIds'] ?? [],
            'the surviving row must be the same account, not a replacement'
        );

        // The deactivation half: this is what ADR-005 adds, and what was broken before.
        self::assertSame(
            0,
            $after['accountsActive'] ?? null,
            'a deleted user\'s account must not remain active -- nothing can spend from it and nobody '
                . 'can read it'
        );

        // The balance is financial history, which is why ADR-005 deactivates rather than deleting.
        self::assertSame(
            [0],
            $after['balances'] ?? null,
            'the balance must survive its owner; a balance with movements behind it is not disposable'
        );
    }

    /**
     * The account stops being offered to FR-020's resolve branch.
     *
     * This is the consequence that makes deactivation meaningful rather than cosmetic. Asserted through
     * the fixture's two counts rather than through a second HTTP call, because the *product* rule is
     * `findActiveByUserUid()` and driving HTTP here would re-prove the provisioning path T390 already
     * covers instead of testing what this task changed.
     */
    public function testADeletedUsersAccountIsWithdrawnFromTheActiveListing(): void
    {
        $this->fixture('create', self::PROBE_UID, $this->probePassword);
        $this->provisionAccountViaHttp();

        self::assertSame(
            0,
            $this->fixture('delete', self::PROBE_UID)['accountsActive'] ?? null,
            'FR-020 resolves from active accounts, so a deactivated one must not be offered'
        );
    }

    /**
     * A second deletion event changes nothing, and does not corrupt the row.
     *
     * `delete` on an absent user is a no-op that reports the surviving accounts. Asserting it reports
     * the same state rather than an error is what makes a double-cleanup safe: a fixture that failed here
     * would turn every teardown-after-teardown red.
     */
    public function testDeletingAnAlreadyDeletedUserLeavesTheRetainedAccountAlone(): void
    {
        $this->fixture('create', self::PROBE_UID, $this->probePassword);
        $this->provisionAccountViaHttp();
        $this->fixture('delete', self::PROBE_UID);

        $again = $this->fixture('delete', self::PROBE_UID);

        self::assertSame('absent', $again['outcome'] ?? null);
        self::assertSame(
            1,
            $again['accountsHeld'] ?? null,
            'FR-054: the retained row must survive a repeated deletion too'
        );
        self::assertSame(
            0,
            $again['accountsActive'] ?? null,
            'and it must not be re-activated by a second pass'
        );
    }

    /**
     * Give the probe a real petty-cash account by creating a real sheet over HTTP.
     *
     * Routed through HTTP rather than through the service so the account is one the *server* made, with
     * the same repository writes a real request produces. Provisioning in-process would need an
     * authenticated session (`DbAccountRepository::save()` stamps `created_by` from it) and would be a
     * second, easier thing to arrange wrongly.
     *
     * @return array<string, mixed>
     */
    private function provisionAccountViaHttp(): array
    {
        $create = $this->client()->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/sheets', [
            'auth' => [self::PROBE_UID, $this->probePassword],
            'headers' => ['X-PettyCash-Command-ID' => $this->commandId()],
            'json' => ['title' => 'T391 retention probe'],
        ]);

        self::assertEquals(
            201,
            $create->getStatusCode(),
            'the probe sheet must be created so a real account exists to be retained: '
                . (string) $create->getBody()
        );

        $accounts = $this->client()->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/accounts?pageSize=100', [
            'auth' => [self::PROBE_UID, $this->probePassword],
        ]);

        self::assertEquals(200, $accounts->getStatusCode(), (string) $accounts->getBody());

        $items = json_decode((string) $accounts->getBody(), true)['ocs']['data']['items'] ?? [];

        return ['accountId' => $items[0]['id'] ?? null];
    }

    private function client(): \GuzzleHttp\Client
    {
        return new \GuzzleHttp\Client([
            'base_uri' => getenv('PETTYCASH_TEST_BASE_URL') ?: 'http://localhost',
            'headers' => ['Accept' => 'application/json', 'OCS-APIRequest' => 'true'],
            'http_errors' => false,
            'verify' => false,
        ]);
    }

    private function commandId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Run the fixture and fail loudly rather than returning an empty array.
     *
     * An empty result would satisfy none of the assertions above, so the failure mode is the tests
     * themselves failing with a confusing count mismatch. Returning the decoder error puts the cause in
     * front of the reader instead.
     *
     * @return array<string, mixed>
     */
    private function fixture(string $action, string ...$args): array
    {
        $fixture = dirname(__DIR__) . '/Fixture/probe-user-fixture.php';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' '
            . escapeshellarg($action);

        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }

        $output = shell_exec($command . ' 2>&1');
        $decoded = json_decode(trim((string) $output), true);

        self::assertIsArray(
            $decoded,
            "probe-user-fixture.php {$action} produced no manifest:\n" . $output
        );

        return $decoded;
    }
}