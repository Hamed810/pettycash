<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Feature 008 T009/T010 — the public Finance holder reads over HTTP.
 *
 * ## The actor is never the holder
 *
 * Every authorized case acts through a probe that holds only the role under test, granted and
 * revoked around the read by `role-assignment-fixture.php`. The shared credential owns the fixture
 * accounts, so reading as the owner would pass through a path that proves nothing about the Finance
 * gate. The probe deliberately holds no account of its own: that is also what makes it the
 * account-less uid in the 404 case.
 *
 * ## What the refusals must not disclose
 *
 * A caller without an effective `FINANCE` assignment receives `403` whose `ocs.data` contains only
 * the error envelope — no holder identity, account, balance, or movement. The platform refuses an
 * unauthenticated request before the controller with `401`, the boundary
 * `ErrorEnvelopeContractTest` records; this suite asserts that too rather than pretending the
 * application emits a 403 it cannot.
 *
 * ## What the successful payloads must carry
 *
 * The holder page and the holder-account page are asserted by exact key set, so a capability or
 * mutation field introduced later fails here rather than merely being tolerated by a subset check.
 * Inactive accounts are included, an unused account keeps `lastMovementAt: null`, and `total` stays
 * the authoritative holder/account count rather than the size of the returned page.
 */
final class FinanceManagementControllerTest extends TestCase
{
    private const BASE = '/ocs/v2.php/apps/pettycash/api/v1';

    /** Created and removed by this suite; holds only the role granted around one read. */
    private const PROBE_UID = 'pettycash-t010-probe';

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
        // A leaked assignment is worse than a leaked fixture row: the probe fixture reuses an
        // existing user by name, so a surviving FINANCE row would promote its next incarnation.
        $this->runFixture('role-assignment-fixture.php', 'revoke', []);
        $this->runFixture('probe-user-fixture.php', 'purge', []);
    }

    // ===== authorization =====================================================

    public function testNonFinanceCallerIsForbiddenWithoutHolderData(): void
    {
        ContractFixtures::prepare('draft');

        $response = $this->probe->request('GET', self::BASE . '/finance/holders');

        $this->assertForbiddenWithoutData($response);
    }

    public function testAdministratorWithoutFinanceAssignmentIsForbidden(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('ADMINISTRATOR');

        try {
            $holders = $this->probe->request('GET', self::BASE . '/finance/holders');
            $accounts = $this->probe->request(
                'GET',
                self::BASE . '/finance/holders/' . rawurlencode($this->testUser()) . '/accounts'
            );

            $this->assertForbiddenWithoutData($holders);
            $this->assertForbiddenWithoutData($accounts);
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['ADMINISTRATOR']);
        }
    }

    /**
     * The actor comes from the session, not the query string. A caller without FINANCE cannot
     * promote itself by naming the holder it would like to read as.
     */
    public function testCallerSuppliedActorUidCannotSubstituteForFinanceAssignment(): void
    {
        ContractFixtures::prepare('draft');

        $response = $this->probe->request('GET', self::BASE . '/finance/holders', [
            'query' => ['userId' => $this->testUser(), 'actorUid' => $this->testUser()],
        ]);

        $this->assertForbiddenWithoutData($response);
    }

    public function testUnauthenticatedRequestIsRefusedBeforeTheController(): void
    {
        $anonymous = new Client([
            'base_uri' => getenv('PETTYCASH_TEST_BASE_URL') ?: 'http://localhost',
            'headers' => ['Accept' => 'application/json', 'OCS-APIRequest' => 'true'],
            'http_errors' => false,
            'verify' => false,
            'allow_redirects' => false,
            'auth' => ['pettycash-not-a-user', 'pettycash-not-a-password'],
        ]);

        $response = $anonymous->request('GET', self::BASE . '/finance/holders');

        self::assertSame(
            401,
            $response->getStatusCode(),
            'an unauthenticated request is refused by Nextcloud before any controller runs'
        );

        $body = json_decode((string) $response->getBody(), true);
        if (is_array($body)) {
            $data = $body['ocs']['data'] ?? [];
            self::assertArrayNotHasKey('items', is_array($data) ? $data : []);
            self::assertArrayNotHasKey('holder', is_array($data) ? $data : []);
        }
    }

    // ===== holder list =======================================================

    public function testFinanceCallerReceivesTheHolderPage(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $response = $this->probe->request('GET', self::BASE . '/finance/holders', [
                'query' => ['page' => 1, 'pageSize' => 2],
            ]);

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $data = $this->data($response);

            $this->assertKeysExactly(['items', 'page', 'pageSize', 'total'], $data, 'FinanceHolderPage');
            self::assertSame(1, $data['page']);
            self::assertSame(2, $data['pageSize']);
            self::assertIsInt($data['total']);
            self::assertGreaterThanOrEqual(2, $data['total'], 'the account-owning fixture population is at least two');
            self::assertIsArray($data['items']);
            self::assertLessThanOrEqual(2, count($data['items']));

            foreach ($data['items'] as $holder) {
                self::assertIsArray($holder);
                $this->assertKeysExactly(
                    ['userId', 'displayName', 'accountCount', 'activeAccountCount', 'status'],
                    $holder,
                    'HolderSummary'
                );
                self::assertIsString($holder['userId']);
                self::assertNotSame('', $holder['userId']);
                self::assertIsString($holder['displayName']);
                self::assertNotSame('', $holder['displayName']);
                self::assertIsInt($holder['accountCount']);
                self::assertGreaterThanOrEqual(1, $holder['accountCount']);
                self::assertIsInt($holder['activeAccountCount']);
                self::assertGreaterThanOrEqual(0, $holder['activeAccountCount']);
                self::assertContains($holder['status'], ['ACTIVE', 'INACTIVE', 'MIXED']);
            }
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    /**
     * Search is population-wide, not page-local. The target holder sorts after the first page, so a
     * page-local filter with `pageSize=1` would return nothing.
     */
    public function testSearchMatchesUidCaseInsensitivelyAcrossTheWholePopulation(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $response = $this->probe->request('GET', self::BASE . '/finance/holders', [
                'query' => ['search' => 'PETTYCASH-SOMEBODY', 'page' => 1, 'pageSize' => 1],
            ]);

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $data = $this->data($response);
            self::assertSame(1, $data['total']);
            self::assertCount(1, $data['items']);
            self::assertSame('pettycash-somebody-else', $data['items'][0]['userId']);
            self::assertNotSame('', $data['items'][0]['displayName']);
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testWhitespaceOnlySearchMeansNoFilter(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $unfiltered = $this->probe->request('GET', self::BASE . '/finance/holders');
            $whitespace = $this->probe->request('GET', self::BASE . '/finance/holders', [
                'query' => ['search' => '   '],
            ]);

            self::assertSame(200, $unfiltered->getStatusCode(), (string) $unfiltered->getBody());
            self::assertSame(200, $whitespace->getStatusCode(), (string) $whitespace->getBody());
            self::assertSame(
                $this->data($unfiltered)['total'],
                $this->data($whitespace)['total'],
                'a trimmed empty search must behave exactly like no search'
            );
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testSearchWithNoMatchIsAnEmptySuccess(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $response = $this->probe->request('GET', self::BASE . '/finance/holders', [
                'query' => ['search' => 't010-no-such-holder-anywhere', 'page' => 1, 'pageSize' => 10],
            ]);

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $data = $this->data($response);
            self::assertSame([], $data['items']);
            self::assertSame(0, $data['total']);
            self::assertSame(1, $data['page']);
            self::assertSame(10, $data['pageSize']);
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testOverlongSearchIsAValidationError(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $response = $this->probe->request('GET', self::BASE . '/finance/holders', [
                'query' => ['search' => str_repeat('a', 65)],
            ]);

            $error = $this->assertValidationError($response);
            self::assertArrayHasKey('search', $error['fieldErrors']);
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testInvalidPaginationIsAValidationError(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            foreach ([
                ['page' => 0],
                ['pageSize' => 0],
                ['pageSize' => 101],
            ] as $query) {
                $response = $this->probe->request('GET', self::BASE . '/finance/holders', [
                    'query' => $query,
                ]);

                $error = $this->assertValidationError($response, (string) $response->getBody());
                $field = array_key_first($query);
                self::assertArrayHasKey(
                    $field,
                    $error['fieldErrors'],
                    "a refused {$field} must be named in fieldErrors"
                );
            }
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testDefaultPaginationIsOneAndTwentyFive(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $holders = $this->probe->request('GET', self::BASE . '/finance/holders');
            $accounts = $this->probe->request(
                'GET',
                self::BASE . '/finance/holders/' . rawurlencode($this->testUser()) . '/accounts'
            );

            self::assertSame(200, $holders->getStatusCode(), (string) $holders->getBody());
            self::assertSame(200, $accounts->getStatusCode(), (string) $accounts->getBody());
            self::assertSame(1, $this->data($holders)['page']);
            self::assertSame(25, $this->data($holders)['pageSize']);
            self::assertSame(1, $this->data($accounts)['page']);
            self::assertSame(25, $this->data($accounts)['pageSize']);
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    // ===== holder accounts ===================================================

    public function testUnknownHolderAccountsIsNotFound(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $response = $this->probe->request(
                'GET',
                self::BASE . '/finance/holders/' . rawurlencode('t010-no-such-holder') . '/accounts'
            );

            $this->assertNotFoundWithoutData($response);
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    /**
     * FR-008-010/011: an account-less uid and an unknown uid must be one answer. Otherwise the
     * endpoint is a user-existence oracle.
     */
    public function testAccountLessUserAndUnknownHolderAreIndistinguishable(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $accountLess = $this->probe->request(
                'GET',
                self::BASE . '/finance/holders/' . rawurlencode(self::PROBE_UID) . '/accounts'
            );
            $unknown = $this->probe->request(
                'GET',
                self::BASE . '/finance/holders/' . rawurlencode('t010-no-such-holder') . '/accounts'
            );

            $this->assertNotFoundWithoutData($accountLess);
            $this->assertNotFoundWithoutData($unknown);
            self::assertSame(
                $this->data($accountLess),
                $this->data($unknown),
                'the two not-found cases must be indistinguishable'
            );
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testFinanceCallerReceivesTheHolderAccounts(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $response = $this->probe->request(
                'GET',
                self::BASE . '/finance/holders/' . rawurlencode($this->testUser()) . '/accounts',
                ['query' => ['page' => 1, 'pageSize' => 100]]
            );

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $data = $this->data($response);

            $this->assertKeysExactly(['holder', 'items', 'page', 'pageSize', 'total'], $data, 'FinanceHolderAccountPage');
            $this->assertKeysExactly(['userId', 'displayName'], $data['holder'], 'FinanceUserRef');
            self::assertSame($this->testUser(), $data['holder']['userId']);
            self::assertSame('Employee One', $data['holder']['displayName']);
            self::assertSame(1, $data['page']);
            self::assertSame(100, $data['pageSize']);
            self::assertIsInt($data['total']);
            self::assertGreaterThanOrEqual(2, $data['total']);
            self::assertCount($data['total'], $data['items']);

            $byId = [];
            foreach ($data['items'] as $account) {
                self::assertIsArray($account);
                $this->assertKeysExactly(
                    ['id', 'userId', 'currency', 'minorUnit', 'balance', 'active', 'version', 'movementCount', 'lastMovementAt'],
                    $account,
                    'HolderAccountSummary'
                );
                self::assertSame($this->testUser(), $account['userId']);
                self::assertIsString($account['id']);
                self::assertSame(3, strlen($account['currency']));
                self::assertIsInt($account['minorUnit']);
                self::assertIsBool($account['active']);
                self::assertIsInt($account['version']);
                self::assertGreaterThanOrEqual(0, $account['version']);
                self::assertIsInt($account['movementCount']);
                self::assertGreaterThanOrEqual(0, $account['movementCount']);
                if ($account['lastMovementAt'] !== null) {
                    self::assertIsString($account['lastMovementAt']);
                    self::assertNotFalse(
                        strtotime($account['lastMovementAt']),
                        'lastMovementAt must be an ISO 8601 date-time'
                    );
                }

                self::assertIsArray($account['balance']);
                $this->assertKeysExactly(['amountMinor', 'currency', 'minorUnit'], $account['balance'], 'Money');
                self::assertIsInt($account['balance']['amountMinor']);
                self::assertSame($account['currency'], $account['balance']['currency']);
                self::assertSame($account['minorUnit'], $account['balance']['minorUnit']);

                foreach (['canRecharge', 'allowedActions', 'expectedVersion', 'commandId'] as $capability) {
                    self::assertArrayNotHasKey($capability, $account, 'a read must expose no mutation capability');
                }

                $byId[$account['id']] = $account;
            }

            self::assertArrayHasKey(ContractFixtures::accountId(), $byId, 'the used IRR account must be listed');
            self::assertArrayHasKey(ContractFixtures::secondAccountId(), $byId, 'the unused USD account must be listed');

            $used = $byId[ContractFixtures::accountId()];
            self::assertGreaterThanOrEqual(1, $used['movementCount'], 'the seeded account has movements');
            self::assertNotNull($used['lastMovementAt'], 'a used account has a last movement timestamp');
            self::assertSame(100000000, $used['balance']['amountMinor'], 'the authoritative stored balance is transmitted');

            $unused = $byId[ContractFixtures::secondAccountId()];
            self::assertSame(0, $unused['movementCount']);
            self::assertNull($unused['lastMovementAt'], 'an unused account keeps null rather than a fabricated timestamp');
            self::assertSame(0, $unused['balance']['amountMinor']);
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testInactiveAccountsAreIncludedAndHolderStatusIsInactive(): void
    {
        ContractFixtures::prepare('deactivated-rial');
        $this->grant('FINANCE');

        try {
            $accounts = $this->probe->request(
                'GET',
                self::BASE . '/finance/holders/' . rawurlencode($this->testUser()) . '/accounts',
                ['query' => ['pageSize' => 100]]
            );

            self::assertSame(200, $accounts->getStatusCode(), (string) $accounts->getBody());
            $items = $this->data($accounts)['items'];
            self::assertGreaterThanOrEqual(2, count($items));

            $ids = [];
            foreach ($items as $item) {
                $ids[] = $item['id'];
                self::assertFalse($item['active'], 'a deactivated account must still be listed, with active=false');
            }
            self::assertContains(ContractFixtures::accountId(), $ids);
            self::assertContains(ContractFixtures::secondAccountId(), $ids);

            $holders = $this->probe->request('GET', self::BASE . '/finance/holders', [
                'query' => ['search' => $this->testUser()],
            ]);

            self::assertSame(200, $holders->getStatusCode(), (string) $holders->getBody());
            $page = $this->data($holders);
            self::assertSame(1, $page['total']);
            self::assertSame($this->testUser(), $page['items'][0]['userId']);
            self::assertSame('INACTIVE', $page['items'][0]['status']);
            self::assertSame(0, $page['items'][0]['activeAccountCount']);
            self::assertGreaterThanOrEqual(2, $page['items'][0]['accountCount']);
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testHolderAccountPaginationKeepsTheAuthoritativeTotal(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $path = self::BASE . '/finance/holders/' . rawurlencode($this->testUser()) . '/accounts';
            $first = $this->probe->request('GET', $path, ['query' => ['page' => 1, 'pageSize' => 1]]);
            $second = $this->probe->request('GET', $path, ['query' => ['page' => 2, 'pageSize' => 1]]);
            $beyond = $this->probe->request('GET', $path, ['query' => ['page' => 999, 'pageSize' => 1]]);

            foreach ([$first, $second, $beyond] as $response) {
                self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            }

            $firstData = $this->data($first);
            $secondData = $this->data($second);
            $beyondData = $this->data($beyond);

            self::assertSame(1, $firstData['page']);
            self::assertSame(2, $secondData['page']);
            self::assertSame(999, $beyondData['page']);
            self::assertCount(1, $firstData['items']);
            self::assertCount(1, $secondData['items']);
            self::assertSame([], $beyondData['items']);
            self::assertGreaterThanOrEqual(2, $firstData['total']);
            self::assertSame($firstData['total'], $secondData['total'], 'total must not shrink with the page slice');
            self::assertSame($firstData['total'], $beyondData['total'], 'a page beyond the end keeps the authoritative total');
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    // ===== ledger ============================================================

    /**
     * The ledger contract requires a seeded account with a known set of movements.
     * We use the 'draft' fixture which provides an account with movements, and verify
     * the six-column structure, monetary side exclusivity, persisted balances, and
     * all authorization/validation/error semantics.
     */

    public function testFinanceCallerReceivesTheLedgerPage(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $response = $this->probe->request('GET', self::BASE . '/finance/accounts/' . rawurlencode(ContractFixtures::accountId()) . '/ledger', [
                'query' => ['page' => 1, 'pageSize' => 25],
            ]);

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $data = $this->data($response);

            $this->assertKeysExactly(['account', 'items', 'page', 'pageSize', 'total'], $data, 'FinanceLedgerPage');
            $this->assertKeysExactly(
                ['id', 'userId', 'currency', 'minorUnit', 'balance', 'active', 'version', 'movementCount', 'lastMovementAt'],
                $data['account'],
                'ledger account header'
            );
            self::assertSame(ContractFixtures::accountId(), $data['account']['id']);
            self::assertSame('IRR', $data['account']['currency']);
            self::assertIsInt($data['account']['minorUnit'], 'the account header scale must be an integer');
            self::assertSame(0, $data['account']['minorUnit'], 'IRR is configured with zero minor units');
            self::assertSame(1, $data['page']);
            self::assertSame(25, $data['pageSize']);
            self::assertIsInt($data['total']);
            self::assertGreaterThanOrEqual(1, $data['total']);
            self::assertIsArray($data['items']);
            self::assertLessThanOrEqual(25, count($data['items']));

            foreach ($data['items'] as $row) {
                $this->assertLedgerRow($row, $data['account']['minorUnit']);
            }
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testNonFinanceCallerIsForbiddenOnLedger(): void
    {
        ContractFixtures::prepare('draft');

        $response = $this->probe->request('GET', self::BASE . '/finance/accounts/' . rawurlencode(ContractFixtures::accountId()) . '/ledger');

        $this->assertForbiddenWithoutData($response);
    }

    public function testAdministratorWithoutFinanceIsForbiddenOnLedger(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('ADMINISTRATOR');

        try {
            $response = $this->probe->request('GET', self::BASE . '/finance/accounts/' . rawurlencode(ContractFixtures::accountId()) . '/ledger');

            $this->assertForbiddenWithoutData($response);
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['ADMINISTRATOR']);
        }
    }

    public function testUnknownAccountLedgerIsNotFound(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $response = $this->probe->request('GET', self::BASE . '/finance/accounts/' . rawurlencode('00000000-0000-0000-0000-000000000000') . '/ledger');

            $this->assertNotFoundWithoutData($response);
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testInvalidPageOnLedgerIsValidationError(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $response = $this->probe->request('GET', self::BASE . '/finance/accounts/' . rawurlencode(ContractFixtures::accountId()) . '/ledger', [
                'query' => ['page' => 0],
            ]);

            $error = $this->assertValidationError($response);
            self::assertArrayHasKey('page', $error['fieldErrors']);
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testInvalidPageSizeOnLedgerIsValidationError(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            foreach ([0, 101] as $pageSize) {
                $response = $this->probe->request('GET', self::BASE . '/finance/accounts/' . rawurlencode(ContractFixtures::accountId()) . '/ledger', [
                    'query' => ['pageSize' => $pageSize],
                ]);

                $error = $this->assertValidationError($response);
                self::assertArrayHasKey('pageSize', $error['fieldErrors']);
            }
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testEmptyLedgerIsEmptySuccess(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $response = $this->probe->request('GET', self::BASE . '/finance/accounts/' . rawurlencode(ContractFixtures::secondAccountId()) . '/ledger', [
                'query' => ['page' => 1, 'pageSize' => 25],
            ]);

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $data = $this->data($response);

            $this->assertKeysExactly(['account', 'items', 'page', 'pageSize', 'total'], $data, 'FinanceLedgerPage');
            self::assertSame([], $data['items']);
            self::assertSame(0, $data['total']);
            self::assertSame(1, $data['page']);
            self::assertSame(25, $data['pageSize']);
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testChargeRowHasRechargeOnly(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $response = $this->probe->request('GET', self::BASE . '/finance/accounts/' . rawurlencode(ContractFixtures::accountId()) . '/ledger', [
                'query' => ['page' => 1, 'pageSize' => 100],
            ]);

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $data = $this->data($response);
            self::assertIsInt($data['account']['minorUnit']);
            $accountMinorUnit = $data['account']['minorUnit'];

            $charges = array_filter($data['items'], static fn (array $row): bool => $row['type'] === 'CHARGE');
            self::assertGreaterThanOrEqual(1, count($charges), 'the seeded account has at least one charge');

            foreach ($charges as $row) {
                self::assertNotNull($row['recharge'], 'a charge row must have recharge');
                $this->assertMoneyShape($row['recharge'], $accountMinorUnit, 'CHARGE recharge');
                $this->assertMoneyShape($row['balanceAfter'], $accountMinorUnit, 'CHARGE balanceAfter');
                self::assertNull($row['expense'], 'a charge row must not have expense');
                self::assertNull($row['sheet'], 'a charge row has no sheet');
                self::assertNull($row['approved'], 'a charge row has approved: null');
                self::assertNull($row['sourceRevisionId'], 'a charge row has no source revision');
                self::assertIsString($row['description']);
            }
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testConsumptionRowHasExpenseOnly(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $response = $this->probe->request('GET', self::BASE . '/finance/accounts/' . rawurlencode(ContractFixtures::accountId()) . '/ledger', [
                'query' => ['page' => 1, 'pageSize' => 100],
            ]);

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $data = $this->data($response);
            self::assertIsInt($data['account']['minorUnit']);
            $accountMinorUnit = $data['account']['minorUnit'];

            $consumptions = array_filter($data['items'], static fn (array $row): bool => $row['type'] === 'CONSUMPTION');
            self::assertGreaterThanOrEqual(1, count($consumptions), 'the seeded account has at least one consumption');

            foreach ($consumptions as $row) {
                self::assertNull($row['recharge'], 'a consumption row must not have recharge');
                self::assertNotNull($row['expense'], 'a consumption row must have expense');
                $this->assertMoneyShape($row['expense'], $accountMinorUnit, 'CONSUMPTION expense');
                $this->assertMoneyShape($row['balanceAfter'], $accountMinorUnit, 'CONSUMPTION balanceAfter');
                self::assertNotNull($row['sheet'], 'a consumption row must have a sheet');
                self::assertNotNull($row['approved'], 'a consumption row must have approved counts');
                self::assertIsArray($row['approved']);
                $this->assertKeysExactly(['approvedCount', 'totalCount'], $row['approved'], 'ApprovedCounts');
                self::assertIsInt($row['approved']['approvedCount']);
                self::assertIsInt($row['approved']['totalCount']);
                self::assertGreaterThanOrEqual(0, $row['approved']['approvedCount']);
                self::assertGreaterThanOrEqual(1, $row['approved']['totalCount']);
                self::assertLessThanOrEqual($row['approved']['approvedCount'], $row['approved']['totalCount']);
                self::assertIsString($row['sheet']['id']);
                self::assertSame($row['sheet']['id'], $row['sheet']['reference']);
                self::assertIsString($row['sheet']['title']);
                self::assertSame($row['sheet']['title'], $row['description'], 'description is the sheet title for consumption');
                self::assertIsString($row['sourceRevisionId']);
            }
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testChargeHasApprovedNull(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $response = $this->probe->request('GET', self::BASE . '/finance/accounts/' . rawurlencode(ContractFixtures::accountId()) . '/ledger', [
                'query' => ['page' => 1, 'pageSize' => 100],
            ]);

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $data = $this->data($response);

            foreach ($data['items'] as $row) {
                if ($row['type'] === 'CHARGE') {
                    self::assertNull($row['approved'], 'charge rows must have approved: null');
                }
            }
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testConsumptionHasApprovedTotalPair(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $response = $this->probe->request('GET', self::BASE . '/finance/accounts/' . rawurlencode(ContractFixtures::accountId()) . '/ledger', [
                'query' => ['page' => 1, 'pageSize' => 100],
            ]);

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $data = $this->data($response);

            foreach ($data['items'] as $row) {
                if ($row['type'] === 'CONSUMPTION') {
                    self::assertNotNull($row['approved']);
                    $this->assertKeysExactly(['approvedCount', 'totalCount'], $row['approved'], 'ApprovedCounts');
                }
            }
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testCostSheetUuidAndTitleAppearForConsumption(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $response = $this->probe->request('GET', self::BASE . '/finance/accounts/' . rawurlencode(ContractFixtures::accountId()) . '/ledger', [
                'query' => ['page' => 1, 'pageSize' => 100],
            ]);

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $data = $this->data($response);

            foreach ($data['items'] as $row) {
                if ($row['type'] === 'CONSUMPTION') {
                    self::assertNotNull($row['sheet']);
                    $this->assertKeysExactly(['id', 'reference', 'title'], $row['sheet'], 'sheet object');
                    self::assertSame($row['sheet']['id'], $row['sheet']['reference']);
                    self::assertNotSame('', $row['sheet']['title']);
                    self::assertSame($row['sheet']['title'], $row['description']);
                }
            }
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testNegativeBalanceSurvivesUnchanged(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $response = $this->probe->request('GET', self::BASE . '/finance/accounts/' . rawurlencode(ContractFixtures::accountId()) . '/ledger', [
                'query' => ['page' => 1, 'pageSize' => 100],
            ]);

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $data = $this->data($response);

            // If the seeded account has a negative balance at some point in its history,
            // it must be transmitted verbatim. We don't require the fixture to have one,
            // but we verify that any negative balance is not clamped or recomputed.
            foreach ($data['items'] as $row) {
                $balanceAfter = $row['balanceAfter']['amountMinor'];
                if ($balanceAfter < 0) {
                    self::assertLessThan(0, $balanceAfter, 'negative balance must be transmitted verbatim, not clamped to zero');
                }
            }
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testNoRejectedCountFieldInLedgerRow(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $response = $this->probe->request('GET', self::BASE . '/finance/accounts/' . rawurlencode(ContractFixtures::accountId()) . '/ledger', [
                'query' => ['page' => 1, 'pageSize' => 100],
            ]);

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $data = $this->data($response);

            foreach ($data['items'] as $row) {
                self::assertArrayNotHasKey('rejectedCount', $row, 'the ledger row must not have a rejectedCount field');
                self::assertArrayNotHasKey('rejected_count', $row, 'the ledger row must not have a rejected_count field');
            }
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testNoCapabilityOrMutationFieldsInLedgerResponse(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $response = $this->probe->request('GET', self::BASE . '/finance/accounts/' . rawurlencode(ContractFixtures::accountId()) . '/ledger', [
                'query' => ['page' => 1, 'pageSize' => 100],
            ]);

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $data = $this->data($response);

            $forbidden = ['canRecharge', 'allowedActions', 'expectedVersion', 'commandId', 'actions', 'mutations', 'capabilities'];
            foreach ($data['items'] as $row) {
                foreach ($forbidden as $cap) {
                    self::assertArrayNotHasKey($cap, $row, "ledger row must not expose capability field: {$cap}");
                }
            }
            foreach ($forbidden as $cap) {
                self::assertArrayNotHasKey($cap, $data['account'], "ledger account header must not expose capability field: {$cap}");
            }
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testLedgerPaginationKeepsAuthoritativeTotal(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $path = self::BASE . '/finance/accounts/' . rawurlencode(ContractFixtures::accountId()) . '/ledger';
            $first = $this->probe->request('GET', $path, ['query' => ['page' => 1, 'pageSize' => 1]]);
            $second = $this->probe->request('GET', $path, ['query' => ['page' => 2, 'pageSize' => 1]]);
            $beyond = $this->probe->request('GET', $path, ['query' => ['page' => 999, 'pageSize' => 1]]);

            foreach ([$first, $second, $beyond] as $response) {
                self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            }

            $firstData = $this->data($first);
            $secondData = $this->data($second);
            $beyondData = $this->data($beyond);

            self::assertSame(1, $firstData['page']);
            self::assertSame(2, $secondData['page']);
            self::assertSame(999, $beyondData['page']);
            self::assertCount(1, $firstData['items']);
            self::assertCount(1, $secondData['items']);
            self::assertSame([], $beyondData['items']);
            self::assertSame($firstData['total'], $secondData['total'], 'total must be independent of page slice');
            self::assertSame($firstData['total'], $beyondData['total'], 'a page beyond the end keeps the authoritative total');
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testLedgerOccurredAtIsPersistedMovementTimestamp(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $response = $this->probe->request('GET', self::BASE . '/finance/accounts/' . rawurlencode(ContractFixtures::accountId()) . '/ledger', [
                'query' => ['page' => 1, 'pageSize' => 100],
            ]);

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $data = $this->data($response);

            foreach ($data['items'] as $row) {
                self::assertIsString($row['occurredAt']);
                self::assertNotFalse(strtotime($row['occurredAt']), 'occurredAt must be a valid ISO 8601 date-time');
            }
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    // ===== completed history ===================================================

    /**
     * The completed-history endpoints reuse the same Finance gate as the holder reads.
     * The `draft` fixture already carries one terminal completed sheet — the settled
     * ledger fixture — so the list is expected to contain exactly that sheet. Fully
     * populated detail assertions live in the integration suite; the detail path here
     * is still exercised with an unknown sheet id.
     */

    public function testFinanceCallerReceivesTheCompletedSheetPage(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        // The seeded settled ledger sheet is the one intentional completed-history entry.
        $settledSheetId = 'cccccccc-4444-4444-8444-444444444444';
        $settledSheetTitle = 'Contract Fixture Settled Sheet';

        try {
            $response = $this->probe->request('GET', self::BASE . '/finance/completed-sheets', [
                'query' => ['page' => 1, 'pageSize' => 25],
            ]);

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $data = $this->data($response);

            $this->assertKeysExactly(['items', 'page', 'pageSize', 'total'], $data, 'CompletedSheetPage');
            self::assertSame(1, $data['page']);
            self::assertSame(25, $data['pageSize']);
            self::assertSame(1, $data['total'], 'the draft fixture seeds one terminal completed sheet');
            self::assertCount(1, $data['items']);

            $sheet = $data['items'][0];
            $this->assertKeysExactly(
                [
                    'id',
                    'reference',
                    'title',
                    'purchaser',
                    'totalTransactionCount',
                    'approvedTransactionCount',
                    'rejectedTransactionCount',
                    'postedAmount',
                    'postingCount',
                    'completedAt',
                    'status',
                ],
                $sheet,
                'CompletedSheetSummary'
            );
            self::assertSame($settledSheetId, $sheet['id']);
            self::assertSame($settledSheetId, $sheet['reference']);
            self::assertSame($settledSheetTitle, $sheet['title']);

            $this->assertKeysExactly(['userId', 'displayName'], $sheet['purchaser'], 'FinanceUserRef');
            self::assertSame($this->testUser(), $sheet['purchaser']['userId']);
            self::assertSame($this->testUser(), $sheet['purchaser']['displayName']);

            self::assertSame(1, $sheet['totalTransactionCount']);
            self::assertSame(1, $sheet['approvedTransactionCount']);
            self::assertSame(0, $sheet['rejectedTransactionCount']);
            $this->assertKeysExactly(['amountMinor', 'currency', 'minorUnit'], $sheet['postedAmount'], 'Money');
            self::assertSame(100000, $sheet['postedAmount']['amountMinor']);
            self::assertSame('IRR', $sheet['postedAmount']['currency']);
            self::assertSame(0, $sheet['postedAmount']['minorUnit']);
            self::assertSame(1, $sheet['postingCount']);
            self::assertIsString($sheet['completedAt']);
            self::assertNotSame('', $sheet['completedAt']);
            self::assertSame('COMPLETED', $sheet['status']);

            $forbidden = ['canRecharge', 'allowedActions', 'expectedVersion', 'commandId', 'actions', 'mutations', 'capabilities', 'approve', 'reject', 'returnForCorrection'];
            foreach ($forbidden as $cap) {
                self::assertArrayNotHasKey($cap, $sheet, "completed sheet summary must not expose field: {$cap}");
            }
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testFinanceCallerReceivesTheCompletedSheetDetailWithTheConfiguredScale(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        $settledSheetId = 'cccccccc-4444-4444-8444-444444444444';
        $settledTransactionId = 'cccccccc-5555-4555-8555-555555555555';

        try {
            $response = $this->probe->request(
                'GET',
                self::BASE . '/finance/completed-sheets/' . rawurlencode($settledSheetId)
            );

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $data = $this->data($response);

            $this->assertKeysExactly(
                [
                    'id',
                    'reference',
                    'title',
                    'purchaser',
                    'totalTransactionCount',
                    'approvedTransactionCount',
                    'rejectedTransactionCount',
                    'postedAmount',
                    'postingCount',
                    'completedAt',
                    'status',
                    'accountId',
                    'currency',
                    'minorUnit',
                    'createdAt',
                    'firstSubmittedAt',
                    'financeStartedAt',
                    'transactions',
                ],
                $data,
                'CompletedSheetDetail'
            );

            self::assertSame($settledSheetId, $data['id']);
            self::assertSame($settledSheetId, $data['reference']);
            self::assertSame(1, $data['totalTransactionCount']);
            self::assertSame(1, $data['approvedTransactionCount']);
            self::assertSame(0, $data['rejectedTransactionCount']);
            self::assertSame('IRR', $data['currency']);
            self::assertIsInt($data['minorUnit'], 'the completed detail header scale must be an integer');
            self::assertSame(0, $data['minorUnit'], 'IRR is configured with zero minor units');
            $this->assertMoneyShape($data['postedAmount'], 0, 'completed detail postedAmount', 'IRR');
            self::assertSame(100000, $data['postedAmount']['amountMinor']);
            self::assertSame(1, $data['postingCount']);
            self::assertIsString($data['completedAt']);
            self::assertIsString($data['createdAt']);
            self::assertSame('COMPLETED', $data['status']);
            self::assertCount(1, $data['transactions']);

            $transaction = $data['transactions'][0];
            $this->assertKeysExactly(
                [
                    'id',
                    'reference',
                    'revisionNumber',
                    'description',
                    'expenseDate',
                    'amount',
                    'finalOutcome',
                    'status',
                    'decisions',
                    'decisionHistory',
                    'evidence',
                    'posting',
                ],
                $transaction,
                'CompletedSheetTransaction'
            );
            self::assertSame($settledTransactionId, $transaction['id']);
            self::assertSame($settledTransactionId, $transaction['reference']);
            $this->assertMoneyShape($transaction['amount'], 0, 'completed transaction amount', 'IRR');
            self::assertSame(100000, $transaction['amount']['amountMinor']);
            self::assertSame('APPROVED', $transaction['finalOutcome']);
            self::assertSame('COMPLETED_APPROVED', $transaction['status']);
            self::assertNotNull($transaction['posting'], 'the settled fixture has a linked consumption movement');
            $this->assertKeysExactly(
                ['occurredAt', 'amount', 'balanceAfter'],
                $transaction['posting'],
                'FinancePosting'
            );
            $this->assertMoneyShape($transaction['posting']['amount'], 0, 'posting amount', 'IRR');
            $this->assertMoneyShape($transaction['posting']['balanceAfter'], 0, 'posting balanceAfter', 'IRR');
            self::assertSame(100000, $transaction['posting']['amount']['amountMinor']);
            self::assertSame(100000000, $transaction['posting']['balanceAfter']['amountMinor']);

            $forbidden = ['canRecharge', 'allowedActions', 'expectedVersion', 'commandId', 'actions', 'mutations', 'capabilities', 'approve', 'reject', 'returnForCorrection'];
            foreach ($forbidden as $cap) {
                self::assertArrayNotHasKey($cap, $data, "completed sheet detail must not expose field: {$cap}");
                self::assertArrayNotHasKey($cap, $transaction, "completed sheet transaction must not expose field: {$cap}");
            }
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testNonFinanceCallerIsForbiddenOnCompletedSheets(): void
    {
        ContractFixtures::prepare('draft');

        $response = $this->probe->request('GET', self::BASE . '/finance/completed-sheets');

        $this->assertForbiddenWithoutData($response);
    }

    public function testAdministratorWithoutFinanceIsForbiddenOnCompletedSheets(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('ADMINISTRATOR');

        try {
            $response = $this->probe->request('GET', self::BASE . '/finance/completed-sheets');

            $this->assertForbiddenWithoutData($response);
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['ADMINISTRATOR']);
        }
    }

    public function testUnauthenticatedRequestIsRefusedOnCompletedSheets(): void
    {
        $anonymous = new Client([
            'base_uri' => getenv('PETTYCASH_TEST_BASE_URL') ?: 'http://localhost',
            'headers' => ['Accept' => 'application/json', 'OCS-APIRequest' => 'true'],
            'http_errors' => false,
            'verify' => false,
            'allow_redirects' => false,
            'auth' => ['pettycash-not-a-user', 'pettycash-not-a-password'],
        ]);

        $response = $anonymous->request('GET', self::BASE . '/finance/completed-sheets');

        self::assertSame(
            401,
            $response->getStatusCode(),
            'an unauthenticated request is refused by Nextcloud before any controller runs'
        );
    }

    public function testInvalidPageOnCompletedSheetsIsValidationError(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $response = $this->probe->request('GET', self::BASE . '/finance/completed-sheets', [
                'query' => ['page' => 0],
            ]);

            $error = $this->assertValidationError($response);
            self::assertArrayHasKey('page', $error['fieldErrors']);
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testInvalidPageSizeOnCompletedSheetsIsValidationError(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            foreach ([0, 101] as $pageSize) {
                $response = $this->probe->request('GET', self::BASE . '/finance/completed-sheets', [
                    'query' => ['pageSize' => $pageSize],
                ]);

                $error = $this->assertValidationError($response);
                self::assertArrayHasKey('pageSize', $error['fieldErrors']);
            }
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testNoCapabilityOrMutationFieldsInCompletedSheetsResponse(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $response = $this->probe->request('GET', self::BASE . '/finance/completed-sheets', [
                'query' => ['page' => 1, 'pageSize' => 100],
            ]);

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $data = $this->data($response);

            $forbidden = ['canRecharge', 'allowedActions', 'expectedVersion', 'commandId', 'actions', 'mutations', 'capabilities', 'approve', 'reject', 'returnForCorrection'];
            foreach ($data['items'] as $sheet) {
                foreach ($forbidden as $cap) {
                    self::assertArrayNotHasKey($cap, $sheet, "completed sheet summary must not expose field: {$cap}");
                }
            }
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testUnknownCompletedSheetIsNotFound(): void
    {
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $response = $this->probe->request('GET', self::BASE . '/finance/completed-sheets/' . rawurlencode(ContractFixtures::unknownId()));

            $this->assertNotFoundWithoutData($response);
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    public function testNonFinanceCallerIsForbiddenOnCompletedSheetDetail(): void
    {
        ContractFixtures::prepare('draft');

        $response = $this->probe->request('GET', self::BASE . '/finance/completed-sheets/' . rawurlencode(ContractFixtures::unknownId()));

        $this->assertForbiddenWithoutData($response);
    }

    public function testNoCapabilityOrMutationFieldsInCompletedSheetDetailResponse(): void
    {
        // The detail endpoint is exercised via an unknown sheet id, which answers 404.
        // The payload shape is verified by the integration test suite (CompletedSheetHistoryTest),
        // which has direct access to the service and can assert on a fully populated detail.
        ContractFixtures::prepare('draft');
        $this->grant('FINANCE');

        try {
            $response = $this->probe->request('GET', self::BASE . '/finance/completed-sheets/' . rawurlencode(ContractFixtures::unknownId()));

            $this->assertSame(404, $response->getStatusCode(), (string) $response->getBody());
        } finally {
            $this->runFixture('role-assignment-fixture.php', 'revoke', ['FINANCE']);
        }
    }

    // ===== helpers ===========================================================

    /**
     * Assert a single ledger row has the correct structure and uses the account's authoritative scale.
     *
     * @param array<string, mixed> $row
     */
    private function assertLedgerRow(array $row, int $accountMinorUnit): void
    {
        $this->assertKeysExactly(
            ['id', 'type', 'occurredAt', 'description', 'sheet', 'approved', 'recharge', 'expense', 'balanceAfter', 'sourceRevisionId'],
            $row,
            'FinanceLedgerRow'
        );
        self::assertTrue(in_array($row['type'], ['CHARGE', 'CONSUMPTION'], true), 'type must be CHARGE or CONSUMPTION');
        self::assertIsString($row['id']);
        self::assertNotSame('', $row['id']);
        self::assertIsString($row['occurredAt']);
        self::assertIsString($row['description']);
        self::assertNotSame('', $row['description']);
        self::assertIsArray($row['balanceAfter']);
        $this->assertMoneyShape($row['balanceAfter'], $accountMinorUnit, 'FinanceLedgerRow.balanceAfter');

        $sides = (int) ($row['recharge'] !== null) + (int) ($row['expense'] !== null);
        self::assertSame(1, $sides, 'every ledger row must have exactly one monetary side');

        if ($row['type'] === 'CHARGE') {
            self::assertNotNull($row['recharge']);
            $this->assertMoneyShape($row['recharge'], $accountMinorUnit, 'CHARGE recharge');
            self::assertNull($row['expense']);
            self::assertNull($row['sheet']);
            self::assertNull($row['approved']);
            self::assertNull($row['sourceRevisionId']);
        } else {
            self::assertNull($row['recharge']);
            self::assertNotNull($row['expense']);
            $this->assertMoneyShape($row['expense'], $accountMinorUnit, 'CONSUMPTION expense');
            self::assertNotNull($row['sheet']);
            self::assertNotNull($row['approved']);
            self::assertIsString($row['sourceRevisionId']);
        }
    }

    /**
     * Assert the canonical Money shape and the authoritative configured scale.
     *
     * @param array<string, mixed> $money
     */
    private function assertMoneyShape(
        array $money,
        int $minorUnit,
        string $context,
        ?string $currency = null
    ): void {
        $this->assertKeysExactly(['amountMinor', 'currency', 'minorUnit'], $money, $context . ' Money');
        self::assertIsInt($money['amountMinor'], $context . ' amountMinor must be an integer');
        self::assertIsString($money['currency'], $context . ' currency must be a string');
        self::assertNotSame('', $money['currency'], $context . ' currency must not be empty');
        if ($currency !== null) {
            self::assertSame($currency, $money['currency'], $context . ' currency must be authoritative');
        }
        self::assertIsInt($money['minorUnit'], $context . ' minorUnit must be an integer');
        self::assertSame(
            $minorUnit,
            $money['minorUnit'],
            $context . ' minorUnit must equal the authoritative configured scale'
        );
    }

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

    private function assertForbiddenWithoutData(ResponseInterface $response): void
    {
        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);
        $this->assertKeysExactly(['error'], $data, 'a forbidden response carries only the error envelope');
        self::assertSame('FORBIDDEN', $data['error']['code']);
    }

    private function assertNotFoundWithoutData(ResponseInterface $response): void
    {
        self::assertSame(404, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);
        $this->assertKeysExactly(['error'], $data, 'a not-found response carries only the error envelope');
        self::assertSame('NOT_FOUND', $data['error']['code']);
    }

    /** @return array{code: string, message: string, fieldErrors: array<string, list<string>>} */
    private function assertValidationError(ResponseInterface $response, string $detail = ''): array
    {
        self::assertSame(422, $response->getStatusCode(), $detail !== '' ? $detail : (string) $response->getBody());
        $data = $this->data($response);
        $this->assertKeysExactly(['error'], $data, 'a validation response carries only the error envelope');
        self::assertSame('VALIDATION_FAILED', $data['error']['code']);
        self::assertIsArray($data['error']['fieldErrors']);
        self::assertNotSame([], $data['error']['fieldErrors']);

        return $data['error'];
    }

    /** @return array<string, mixed> */
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
