<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the account endpoints `contracts/ocs-api.yaml` declares.
 *
 * ## Why this file exists
 *
 * `T063` is checked and claims it added "account summary, and movement OCS routes/controllers in
 * `appinfo/routes.php`, `lib/Controller/ReviewController.php`, and `lib/Controller/AccountController.php`".
 * The routes were added; the controller methods were not. `appinfo/routes.php` declares four
 * account routes and `AccountController` implemented one, so `account#create`, `account#charge` and
 * `account#movements` all resolved to a method that does not exist and answered HTTP 500.
 *
 * A declared route that answers 500 is worse than an absent one: the contract says the endpoint
 * exists, a caller can construct the request, and the failure arrives as a server error rather than
 * as a 404 that would have said plainly that nothing had been built. Nothing in the suite noticed,
 * because the only account assertion was the listing.
 *
 * This file covers the read the account movement view needs, and the ownership rule that makes it
 * safe. `create` and `charge` are still missing and are recorded as such in `tasks.md` under the
 * reopened `T063` rather than quietly asserted here.
 */
final class AccountControllerTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
        ContractFixtures::prepare('draft');
    }

    /** @return array<string, mixed> */
    private function movements(string $accountId, string $query = ''): array
    {
        $response = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/accounts/' . $accountId . '/movements' . $query
        );
        $this->assertEquals(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];
    }

    /**
     * The route is declared and the controller method is missing; answering 500 is the evidence.
     *
     * This is a regression guard rather than a claim about behaviour: it fails if the endpoint ever
     * stops working, which is the state this file was written from.
     */
    public function testMovementsEndpointIsReachable(): void
    {
        $response = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/accounts/' . ContractFixtures::accountId() . '/movements'
        );
        $this->assertEquals(
            200,
            $response->getStatusCode(),
            'a declared route must not answer 500: the method it names has to exist'
        );
    }

    /**
     * The whole `Movement` schema, because a ledger row that is present but malformed is a figure a
     * finance officer will reconcile against.
     */
    public function testMovementResponseCarriesTheWholeSchema(): void
    {
        $data = $this->movements(ContractFixtures::accountId());
        $this->assertNotEmpty($data['items'] ?? [], 'the fixture account has a seeded ledger');

        foreach ($data['items'] as $movement) {
            foreach (['id', 'type', 'amount', 'balanceAfter', 'description', 'occurredAt'] as $member) {
                $this->assertArrayHasKey($member, $movement, "Movement requires {$member}");
            }
            $this->assertContains(
                $movement['type'],
                ['CHARGE', 'CONSUMPTION'],
                'Movement.type is an enum of exactly these two'
            );
            foreach (['amount', 'balanceAfter'] as $figure) {
                $this->assertIsInt($movement[$figure]['amountMinor'] ?? null);
                $this->assertIsString($movement[$figure]['currency'] ?? null);
                $this->assertIsInt($movement[$figure]['minorUnit'] ?? null);
            }
        }
    }

    /**
     * Both movement types are present and distinguishable.
     *
     * A projection that emitted every row as a charge would satisfy a test that only counted rows,
     * and would invert the meaning of the ledger: a consumption is money leaving the account, and
     * reading it as money arriving is the failure that makes a balance unexplainable.
     */
    public function testLedgerReportsBothChargeAndConsumption(): void
    {
        $data = $this->movements(ContractFixtures::accountId());
        $byId = [];
        foreach ($data['items'] as $movement) {
            $byId[$movement['id']] = $movement;
        }

        $this->assertArrayHasKey(ContractFixtures::chargeMovementId(), $byId);
        $this->assertArrayHasKey(ContractFixtures::consumptionMovementId(), $byId);
        $this->assertSame('CHARGE', $byId[ContractFixtures::chargeMovementId()]['type']);
        $this->assertSame('CONSUMPTION', $byId[ContractFixtures::consumptionMovementId()]['type']);
    }

    /**
     * The amount and the resulting balance are the ledger's whole point, so both are asserted against
     * the seeded figures rather than merely for being present.
     */
    public function testLedgerAmountsMatchTheSeededFigures(): void
    {
        $data = $this->movements(ContractFixtures::accountId());
        $byId = [];
        foreach ($data['items'] as $movement) {
            $byId[$movement['id']] = $movement;
        }

        $charge = $byId[ContractFixtures::chargeMovementId()];
        $this->assertSame(100100000, $charge['amount']['amountMinor']);
        $this->assertSame(100100000, $charge['balanceAfter']['amountMinor']);

        $consumption = $byId[ContractFixtures::consumptionMovementId()];
        $this->assertSame(100000, $consumption['amount']['amountMinor']);
        // The charge less the consumption, so the ledger reconciles with itself.
        $this->assertSame(
            $charge['balanceAfter']['amountMinor'] - $consumption['amount']['amountMinor'],
            $consumption['balanceAfter']['amountMinor']
        );
    }

    /**
     * The scale comes from the account's own currency.
     *
     * IRR is configured with `minor_unit => 0`, so a projection that divided by 100 or read a
     * built-in table would print 1,001,000 where the ledger says 100,100,000 — a factor of a
     * hundred on the figure a finance officer reconciles against.
     */
    public function testMovementMoneyCarriesTheConfiguredCurrencyScale(): void
    {
        $data = $this->movements(ContractFixtures::accountId());
        $first = $data['items'][0];
        $this->assertSame('IRR', $first['amount']['currency']);
        $this->assertSame(0, $first['amount']['minorUnit'], 'IRR is configured with no minor unit');
    }

    /**
     * The last movement's `balanceAfter` and the account's own `balance` must agree.
     *
     * These are two separate reads of the same fact. If they ever disagree, one of them is a lie,
     * and which one is wrong cannot be determined from the response — which is why this is asserted
     * rather than assumed.
     */
    public function testLastMovementBalanceAgreesWithTheAccountBalance(): void
    {
        $ledger = $this->movements(ContractFixtures::accountId());
        $latest = $ledger['items'][0];

        $accounts = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/accounts?page=1&pageSize=100');
        $accountData = json_decode((string) $accounts->getBody(), true)['ocs']['data'] ?? [];
        $balances = [];
        foreach ($accountData['items'] ?? [] as $account) {
            $balances[$account['id']] = $account['balance'];
        }

        $this->assertArrayHasKey(ContractFixtures::accountId(), $balances);
        $this->assertSame(
            $balances[ContractFixtures::accountId()]['amountMinor'],
            $latest['balanceAfter']['amountMinor'],
            'the newest movement must leave the account at the balance it reports'
        );
    }

    /**
     * `sourceRevisionId` is nullable and is what ties a consumption back to the revision that
     * justified it. A charge has none; a consumption must, or the ledger cannot be explained.
     */
    public function testConsumptionCarriesItsSourceRevisionAndChargeDoesNot(): void
    {
        $data = $this->movements(ContractFixtures::accountId());
        $byId = [];
        foreach ($data['items'] as $movement) {
            $byId[$movement['id']] = $movement;
        }

        $this->assertNull(
            $byId[ContractFixtures::chargeMovementId()]['sourceRevisionId'],
            'a charge is not justified by a revision'
        );
        $this->assertSame(
            ContractFixtures::ledgerRevisionId(),
            $byId[ContractFixtures::consumptionMovementId()]['sourceRevisionId'],
            'a consumption names the revision it settled'
        );
        $this->assertNotSame(
            ContractFixtures::revisionId(),
            ContractFixtures::ledgerRevisionId(),
            'the settled revision is not the working one: the unique consumption index would '
            . 'then make the working revision unsettleable'
        );
    }

    /**
     * Paging is real: the view offers navigation, so a page request must actually narrow the result
     * and `total` must still report the full ledger.
     */
    public function testPagingNarrowsThePageAndTotalStillCountsTheLedger(): void
    {
        $all = $this->movements(ContractFixtures::accountId());
        $this->assertSame(count($all['items']), $all['total']);

        $firstPage = $this->movements(ContractFixtures::accountId(), '?page=1&pageSize=1');
        $this->assertCount(1, $firstPage['items']);
        $this->assertSame(1, $firstPage['page']);
        $this->assertSame(1, $firstPage['pageSize']);
        $this->assertSame($all['total'], $firstPage['total'], 'total counts the ledger, not the page');

        $secondPage = $this->movements(ContractFixtures::accountId(), '?page=2&pageSize=1');
        $this->assertCount(1, $secondPage['items']);
        $this->assertNotSame(
            $firstPage['items'][0]['id'],
            $secondPage['items'][0]['id'],
            'the second page must not repeat the first'
        );
    }

    /**
     * A page past the end is empty, not an error. The ledger is append-only, so this is a state the
     * view reaches whenever a refresh lands after another user has spent the account.
     */
    public function testPageBeyondTheEndIsAnEmptySuccess(): void
    {
        $data = $this->movements(ContractFixtures::accountId(), '?page=99&pageSize=25');
        $this->assertSame([], $data['items']);
        $this->assertGreaterThan(0, $data['total']);
    }

    /**
     * A second account of the acting user exists and has no ledger.
     *
     * An unused account is a normal state the purchaser view has to render, so it must be a success
     * with an empty page rather than a 404 that reads like missing data.
     */
    public function testAccountWithNoMovementsIsAnEmptySuccess(): void
    {
        $data = $this->movements(ContractFixtures::secondAccountId());
        $this->assertSame([], $data['items']);
        $this->assertSame(0, $data['total']);
    }

    /**
     * The ledger is the most sensitive read in the application, so an account the caller does not
     * own must be refused — and refused indistinguishably from one that does not exist, so the
     * endpoint cannot be used to discover which account ids are real.
     *
     * The refusal is compared against a genuinely non-existent id rather than asserted as a status
     * code alone: an endpoint that returned 404 for everything would pass a "not 200" check on the
     * foreign account while being useless, and one that leaked existence would pass it too.
     */
    public function testAnotherUsersAccountIsRefusedLikeOneThatDoesNotExist(): void
    {
        $foreign = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/accounts/' . ContractFixtures::foreignAccountId() . '/movements'
        );
        $unknown = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/accounts/99999999-9999-4999-8999-999999999999/movements'
        );

        $this->assertNotEquals(200, $foreign->getStatusCode(), "another user's ledger must not be readable");
        $this->assertEquals(
            $unknown->getStatusCode(),
            $foreign->getStatusCode(),
            'refusing must not distinguish "not yours" from "does not exist"'
        );
    }

    /**
     * The caller's own second account stays readable.
     *
     * Without this, the ownership rule above would also be satisfied by refusing every account, and
     * a single-endpoint test suite could not tell the two apart.
     */
    public function testTheCallersOwnSecondAccountIsStillReadable(): void
    {
        $data = $this->movements(ContractFixtures::secondAccountId());
        $this->assertArrayHasKey('items', $data);
        $this->assertSame([], $data['items'], 'the second account is seeded without a ledger');
    }
}
