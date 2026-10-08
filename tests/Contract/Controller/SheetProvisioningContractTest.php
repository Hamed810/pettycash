<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Domain\Shared\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * FR-020's provisioning outcome, observed **over HTTP** rather than at a service boundary.
 *
 * ## Why this file exists
 *
 * T358 removed `accountId` from `SheetCreate.required` and made `SheetService::createSheet()` accept a
 * null account, resolving the caller's account from their own active accounts. T388 then proved the
 * outcome against real MariaDB — zero accounts become exactly one, and a second call provisions
 * nothing — and its acceptance criteria permitted either that or a live-HTTP test, so it recorded the
 * HTTP variant as *not established*.
 *
 * The gap that left is narrow and specific, and it is a reporting gap rather than a logic gap. Nothing
 * had read the response **after** provisioning: `AccountService::listForUser()`, the
 * `AccountPayloadFormatter` projection and the OCS envelope for a caller whose only account was created
 * by their own sheet request had no coverage together. T388 showed the server *writes* the account.
 * Nothing showed it then *reports* it.
 *
 * ## Why it needs a principal that does not exist yet
 *
 * `OcsTestClient::create()` authenticates with one credential, and that credential holds accounts — so
 * `POST /sheets` takes the *resolve* branch, never the *provision* branch. FR-020's precondition is
 * **zero** active accounts.
 *
 * Measured on this instance before this file was written, not assumed:
 *
 * - five principals already hold zero petty-cash accounts (`pts-f004-e2e`, `pts-employee`,
 *   `pts-f004-visual`, `pts-f004-no-profile`, `hamed2`), so the precondition is reachable for all of
 *   them;
 * - **none of them authenticates.** Each answers **401** from `/ocs/…/api/v1/me` with the contract
 *   password, while `employee1` answers **200** with it. Their credentials are not this suite's to use
 *   or reset.
 *
 * So `probe-user-fixture.php` provisions a principal this suite owns, in the same subprocess shape T378
 * used for `core.timezone`, and `tearDown()` removes it. That is the owner's decision recorded for T390:
 * the shared instance already carries five probe accounts from earlier E2E work, so a sixth named for
 * this purpose is consistent with what is there rather than a new kind of thing on it.
 *
 * ## What this asserts, and what it deliberately does not
 *
 * FR-020's three clauses — none provisions, one resolves, several refuse — are T358's and T388's. This
 * file observes only the outcome nobody had read: that after a zero-account caller's first sheet, the
 * **server reports exactly one active `IRR` account**, and that the report survives a second sheet
 * without provisioning again.
 *
 * It does not re-derive the other two clauses through the same caller. The *refusal* clause needs
 * **several** accounts, which is a different precondition and a different setup; asserting it here on a
 * single account would be asserting something else.
 */
final class SheetProvisioningContractTest extends TestCase
{
    /**
     * A principal this suite creates and removes.
     *
     * Named for the criterion rather than reusing any of the instance's existing probe accounts: this
     * suite sets the account's password, and it must not do that to a principal another suite may own.
     */
    private const PROBE_UID = 'pettycash-t390-probe';

    private Client $client;

    private ?string $probePassword = null;

    protected function setUp(): void
    {
        // Booted **before** the client is built: `OcsTestClient::create()` reads one credential from
        // the environment, and this test is the one caller that authenticates as somebody else.
        $this->probePassword = $this->provisionProbeUser();
        $this->client = $this->clientFor(self::PROBE_UID, $this->probePassword);
    }

    protected function tearDown(): void
    {
        // In a `finally`-equivalent rather than left to the last assertion: a failing test must not
        // leave a principal behind on an instance shared with other applications.
        $this->removeProbeUser();
    }

    /**
     * FR-020's observable outcome, over HTTP, for a caller in the zero-account precondition.
     */
    public function testFirstSheetWithoutAccountIdProvisionsExactlyOneActiveRialAccount(): void
    {
        $precondition = $this->accounts();
        $this->assertSame(
            [],
            $precondition,
            'FR-020\'s precondition is zero accounts. If this fails the fixture did not give us a fresh '
                . 'principal, and the provisioning branch below was never taken -- the sheet would have '
                . 'resolved an existing account and every other assertion here would pass for the wrong '
                . 'reason.'
        );

        $create = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/sheets', [
            'headers' => ['X-PettyCash-Command-ID' => (string) Uuid::random()],
            'json' => ['title' => 'T390 provisioning probe'],
        ]);

        $this->assertEquals(
            201,
            $create->getStatusCode(),
            'a sheet created with no accountId must succeed by provisioning: '
                . (string) $create->getBody()
        );

        $accounts = $this->accounts();

        $this->assertCount(
            1,
            $accounts,
            'FR-020: exactly one account after the first sheet. Got: '
                . json_encode($accounts, JSON_UNESCAPED_SLASHES)
        );

        $account = $accounts[0];

        $this->assertTrue(
            $account['active'],
            'the provisioned account must be active -- an inactive account would not be offered by the '
                . 'resolve branch on the next sheet, so "one account" would be true but unusable.'
        );

        // The contract models Money as {amountMinor, currency, minorUnit}; the currency rides on the
        // balance rather than beside it, so this is where "the default *rial* account" is observable.
        $this->assertSame(
            'IRR',
            $account['balance']['currency'] ?? null,
            'FR-020 provisions the default rial account. Got: '
                . json_encode($account['balance'] ?? null, JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * The report is stable: a second sheet resolves the one account rather than provisioning another.
     *
     * This is the half of the outcome nobody had read. T388 proved the *service* provisions once; the
     * question here is whether the server's own listing agrees across two requests, which is a different
     * code path (`AccountService::listForUser()` re-run, not a write guarded against a duplicate key).
     */
    public function testASecondSheetDoesNotProvisionASecondAccount(): void
    {
        $this->createSheet('T390 provisioning probe one');

        // A listing read *between* the two creates, so "one account" is observed as a stable reading
        // rather than only as an end state. Without it, a second account created and removed inside the
        // request would still leave this assertion green.
        $this->assertCount(1, $this->accounts(), 'the account count must be stable between requests');

        $this->createSheet('T390 provisioning probe two');

        $accounts = $this->accounts();

        $this->assertCount(
            1,
            $accounts,
            'FR-020: a second sheet with one account available resolves that account, so the listing '
                . 'must still hold exactly one. Got: ' . json_encode($accounts, JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * The created sheet is usable in the provisioned account's currency, under the caller who
     * provisioned it.
     *
     * ## What this asserts, and what it cannot
     *
     * FR-020 says the provisioned account is **used** by the sheet. Identity linkage — "this sheet's
     * account is *that* account" — is **not observable over HTTP**, and that is a contract decision
     * rather than an omission: `SheetPayloadFormatter` documents that the sheet response carried an
     * `accountId` the schema does not declare, and dropped it, because "a field the contract does not
     * describe is one nothing can be tested against." Adding it back here would re-litigate a settled
     * decision from a test, so this does not.
     *
     * So this asserts the linkage that *is* expressible in the declared schema: the sheet reports the
     * provisioned account's currency, and it belongs to the caller who caused the provisioning. A
     * sheet pointed at a different or nonexistent account would fail on currency or on `ownerId`.
     * Recorded as a real limit, not presented as full identity coverage.
     */
    public function testTheCreatedSheetIsInTheProvisionedCurrencyAndOwnedByTheCaller(): void
    {
        $create = $this->createSheet('T390 provisioning probe linkage');
        $detail = $create['ocs']['data'] ?? [];

        $accounts = $this->accounts();
        $this->assertCount(1, $accounts, 'precondition for this assertion: exactly one account');

        $this->assertSame(
            $accounts[0]['balance']['currency'] ?? null,
            $detail['currency'] ?? null,
            'the sheet must carry the provisioned account\'s currency, or provisioning created an '
                . 'account the sheet did not use. Sheet: ' . json_encode($detail, JSON_UNESCAPED_SLASHES)
        );

        $this->assertSame(
            self::PROBE_UID,
            $detail['ownerId'] ?? null,
            'the sheet belongs to the caller whose zero accounts caused the provisioning'
        );
    }

    /**
     * @param array<string, mixed> $create
     */
    private function createSheet(string $title): array
    {
        $response = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/sheets', [
            'headers' => ['X-PettyCash-Command-ID' => (string) Uuid::random()],
            'json' => ['title' => $title],
        ]);

        $this->assertEquals(
            201,
            $response->getStatusCode(),
            "creating sheet '{$title}' with no accountId must succeed by provisioning: "
                . (string) $response->getBody()
        );

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    /**
     * The account listing, exactly as the server reports it.
     *
     * Paged explicitly rather than relying on a default page size: a default large enough to hold every
     * account is not an assumption worth making when the assertion is "exactly one".
     *
     * @return array<int, array<string, mixed>>
     */
    private function accounts(): array
    {
        $response = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/accounts?pageSize=100'
        );

        $this->assertEquals(
            200,
            $response->getStatusCode(),
            'GET /accounts must be readable for the provisioned caller: ' . (string) $response->getBody()
        );

        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];

        return array_values($data['items'] ?? []);
    }

    private function clientFor(string $user, string $password): Client
    {
        $baseUrl = getenv('PETTYCASH_TEST_BASE_URL') ?: 'http://localhost';

        return new Client([
            'base_uri' => $baseUrl,
            'auth' => [$user, $password],
            'headers' => [
                'Accept' => 'application/json',
                'OCS-APIRequest' => 'true',
            ],
            'http_errors' => false,
            'verify' => false,
        ]);
    }

    /**
     * Create the probe principal, failing loudly rather than running a test that cannot reach its
     * precondition.
     */
    private function provisionProbeUser(): string
    {
        // A per-process password rather than a constant: it exists only for the life of this test, and
        // a fixed value in a committed test file is a credential that outlives its usefulness. It is
        // derived from the contract password so `.env.testing` stays the one place a secret is named.
        $password = (string) Uuid::random() . (string) Uuid::random();
        $this->probePassword = $password;

        $fixture = dirname(__DIR__) . '/Fixture/probe-user-fixture.php';
        $output = shell_exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' create '
            . escapeshellarg(self::PROBE_UID) . ' ' . escapeshellarg($password) . ' 2>&1'
        );

        $this->assertIsString($output, 'the probe fixture must run');
        $decoded = json_decode(trim((string) $output), true);

        $this->assertIsArray(
            $decoded,
            "probe-user-fixture.php did not return a manifest, so FR-020's precondition was never "
                . 'established and nothing below would be evidence:\n' . $output
        );
        $this->assertSame(
            0,
            $decoded['pettyCashAccounts'] ?? null,
            'the fixture must confirm zero accounts before this suite claims to have observed provisioning'
        );

        return $password;
    }

    /**
     * Remove the probe, and its rows, so the **next** run starts from zero accounts.
     *
     * `purge`, not `delete`, and the distinction is T391/ADR-005's: `delete` is product-faithful and
     * deliberately *retains* the deleted user's rows (deactivated), which is correct for FR-054 and exactly
     * wrong for test hygiene. Leaving them meant this suite's second run was refused by `create` — the
     * previous run's provisioned account was still there under the same uid — which reads as a flaky test
     * rather than as a teardown that quietly stopped cleaning up.
     */
    private function removeProbeUser(): void
    {
        $fixture = dirname(__DIR__) . '/Fixture/probe-user-fixture.php';
        shell_exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' purge '
            . escapeshellarg(self::PROBE_UID) . ' 2>&1'
        );
    }
}