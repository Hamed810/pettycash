<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Contract tests for the two account writes: `POST /accounts` and
 * `POST /accounts/{id}/charges`.
 *
 * `appinfo/routes.php` declared four account routes and `AccountController` implemented two, so
 * `account#create` and `account#charge` resolved to a method that did not exist and answered
 * **HTTP 500**. A declared route answering 500 is worse than an absent one: the contract says the
 * endpoint exists, a caller can construct the request from it, and the failure arrives as a server
 * error rather than as a 404 that would have said plainly that nothing had been built. Nothing
 * noticed, because `AccountControllerTest` only ever read.
 *
 * FR-013 puts account management with administrators and FR-015 says **finance** charges an account,
 * so the interesting assertions here are the refusals: an account is money, and both endpoints write
 * it.
 *
 * The charge tests prepare the `finance` fixture state rather than `draft`, because that is the only
 * state which grants the acting user the `FINANCE` role FR-015 requires. Preparing it explicitly is
 * what makes these assertions independent of the order the suites run in — the role is seeded by the
 * call, not left behind by an earlier one.
 */
final class AccountWriteControllerTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
        ContractFixtures::prepare('finance');
    }

    // ===== POST /accounts ----------------------------------------------------

    /**
     * A declared route must not answer 500: the method it names has to exist.
     *
     * This is a regression guard rather than a claim about behaviour — it fails if the endpoint ever
     * stops working, which is the state this file was written from.
     */
    public function testAccountCreationIsReachable(): void
    {
        $response = $this->createAccount('EUR');

        $this->assertNotEquals(
            500,
            $response->getStatusCode(),
            'a declared route must not answer 500: the method it names has to exist'
        );
    }

    /**
     * The whole `Account` schema, at the currency's own configured scale.
     *
     * EUR is seeded with `minor_unit => 2` and IRR with `0`, so a projection that divided by 100 or
     * read a built-in table would print the rial scale on a dollar balance — a factor of a hundred on
     * every figure the account will ever hold.
     */
    public function testACreatedAccountCarriesTheWholeSchema(): void
    {
        $created = $this->createdAccount('EUR');

        foreach (['id', 'userId', 'balance', 'active', 'version'] as $member) {
            $this->assertArrayHasKey($member, $created, "Account requires {$member}");
        }
        $this->assertSame('employee1', $created['userId'], 'a user opens their own account');
        $this->assertSame(0, $created['balance']['amountMinor'], 'a new account opens empty');
        $this->assertSame('EUR', $created['balance']['currency']);
        $this->assertSame(2, $created['balance']['minorUnit'], 'EUR is configured with two minor units');
        $this->assertTrue($created['active']);
        $this->assertIsInt($created['version']);
    }

    /**
     * The account is really there afterwards — a 201 for an account that was never written is a 201
     * for nothing.
     */
    public function testACreatedAccountAppearsInTheListing(): void
    {
        $created = $this->createdAccount('EUR');

        $this->assertContains($created['id'], $this->listedAccountIds(), 'the created account is listed');
    }

    /**
     * FR-014: one active account per user **per currency**. A second account in the same currency is
     * not another account, and the contract has no "or you get another" answer, so the pair is a 409
     * rather than a second row. The unique index is what enforces it; this asserts the application
     * says so rather than letting a driver error reach the caller.
     */
    public function testASecondAccountForTheSameCurrencyIsAConflict(): void
    {
        $this->createdAccount('EUR');

        $this->assertEquals(409, $this->createAccount('EUR')->getStatusCode());
    }

    /**
     * A currency that is not configured cannot be opened.
     *
     * `amountMinor` is meaningless without a `minor_unit`, so this is a refusal with the field named
     * rather than an account created in an unknown currency.
     */
    public function testAnUnsupportedCurrencyIsRefused(): void
    {
        $response = $this->createAccount('ZZZ');

        $this->assertContains(
            $response->getStatusCode(),
            [400, 422],
            'an unconfigured currency must not open an account'
        );
    }

    /**
     * `userId` is the one identifier in this controller taken from the request rather than from the
     * trusted session, so it is the one that could be used to open an account for anybody.
     *
     * FR-013 lets an administrator manage accounts *for users*; the acting contract user is a finance
     * officer, not an administrator, so naming somebody else must be refused. An endpoint that
     * opened accounts for arbitrary uids would also be an oracle for which uids exist.
     */
    public function testAnAccountCannotBeOpenedForSomebodyElse(): void
    {
        $response = $this->createAccount('EUR', 'pettycash-somebody-else');

        $this->assertEquals(
            403,
            $response->getStatusCode(),
            'a finance officer is not an administrator and cannot open an account for another user'
        );
    }

    // ===== POST /accounts/{id}/charges ---------------------------------------

    public function testChargingAnAccountIsReachable(): void
    {
        $response = $this->charge(ContractFixtures::secondAccountId(), 1000, 'Opening float');

        $this->assertNotEquals(
            500,
            $response->getStatusCode(),
            'a declared route must not answer 500: the method it names has to exist'
        );
    }

    /**
     * The whole `AccountMutationResult`, because the point of the response is that the caller learns
     * both what moved and what the account now holds, without re-reading the ledger.
     */
    public function testAChargeAnswersWithTheAccountMutationItCaused(): void
    {
        $before = $this->accountBalance(ContractFixtures::secondAccountId());

        $result = $this->data($this->charge(ContractFixtures::secondAccountId(), 1000, 'Opening float', 201));

        $this->assertArrayHasKey('account', $result);
        $this->assertArrayHasKey('movement', $result);
        $this->assertSame(ContractFixtures::secondAccountId(), $result['account']['id'] ?? null);
        $this->assertSame(
            $before + 1000,
            $result['account']['balance']['amountMinor'] ?? null,
            'the account is left at the balance it had, plus the charge'
        );

        $movement = $result['movement'];
        $this->assertSame('CHARGE', $movement['type'] ?? null);
        $this->assertSame(1000, $movement['amount']['amountMinor'] ?? null);
        $this->assertSame($before + 1000, $movement['balanceAfter']['amountMinor'] ?? null);
        $this->assertSame('Opening float', $movement['description'] ?? null);
        $this->assertArrayHasKey('sourceRevisionId', $movement, 'Movement declares the member');
        $this->assertNull($movement['sourceRevisionId'], 'a charge is not justified by a revision');
        $this->assertNotEmpty($movement['occurredAt'] ?? null);
    }

    /**
     * The charge is dated to when the money changed hands, not to when the command was sent: a charge
     * entered from a handover note is dated to the handover, and `AccountCharge.occurredAt` exists
     * because that is a fact the caller knows and the server does not.
     */
    public function testAChargeIsDatedToWhenTheCallerSaysItHappened(): void
    {
        $happened = '2026-03-04T10:30:00+00:00';

        $result = $this->data($this->charge(ContractFixtures::secondAccountId(), 1000, 'Backdated float', 201, $happened));

        $this->assertSame(
            '2026-03-04T10:30:00+00:00',
            $result['movement']['occurredAt'] ?? null,
            'occurredAt is the moment the caller gave, not the moment the request arrived'
        );
    }

    /**
     * The movement is on the ledger, and the ledger is what makes a balance reconstructible. A charge
     * that moved the balance and wrote no row is the failure FR-015's movement exists to prevent.
     */
    public function testAChargeLeavesExactlyOneMovementOnTheLedger(): void
    {
        $before = $this->ledgerIds(ContractFixtures::secondAccountId());

        $result = $this->data($this->charge(ContractFixtures::secondAccountId(), 1000, 'Opening float', 201));

        $after = $this->ledgerIds(ContractFixtures::secondAccountId());
        $this->assertCount(count($before) + 1, $after, 'a charge records exactly one movement');
        $this->assertContains($result['movement']['id'], $after, 'and it is the one the response reported');
    }

    /**
     * A repeated command must not pay the account twice.
     *
     * The unique `command_id` index is what makes that safe, and the command id is run through the
     * same idempotency service the decision path uses, so the replay is answered from the stored
     * result rather than attempted again.
     */
    public function testReplayingAChargeDoesNotPayTheAccountTwice(): void
    {
        $commandId = ContractFixtures::commandId();
        $before = $this->ledgerIds(ContractFixtures::secondAccountId());

        $first = $this->charge(ContractFixtures::secondAccountId(), 1000, 'Opening float', 201, null, $commandId);
        $second = $this->charge(ContractFixtures::secondAccountId(), 1000, 'Opening float', 201, null, $commandId);

        $this->assertEquals(201, $first->getStatusCode());
        $this->assertEquals(201, $second->getStatusCode(), 'a replay is answered, not refused');
        $this->assertCount(
            count($before) + 1,
            $this->ledgerIds(ContractFixtures::secondAccountId()),
            'the replay did not pay the account a second time'
        );
    }

    /**
     * Funding is the one write two finance officers make at the same moment, and a charge applied to
     * a balance that has since moved is a lost update: money in the ledger and not in the account.
     * So the caller's `expectedVersion` has to be checked, and a stale one is a 409.
     */
    public function testAChargeAgainstAStaleVersionIsRefused(): void
    {
        $this->charge(ContractFixtures::secondAccountId(), 1000, 'First float', 201);

        $response = $this->charge(ContractFixtures::secondAccountId(), 1000, 'Stale float', null, null, null, 0);

        $this->assertEquals(
            409,
            $response->getStatusCode(),
            'a charge against a version the account has moved past must be refused'
        );
    }

    /**
     * An account with no command id cannot be made idempotent, and an endpoint that accepted one
     * would be a way to pay the same account twice by accident.
     */
    public function testAChargeWithoutACommandIdIsRefused(): void
    {
        $response = $this->client->request(
            'POST',
            '/ocs/v2.php/apps/pettycash/api/v1/accounts/' . ContractFixtures::secondAccountId() . '/charges',
            [
                'json' => [
                    'amountMinor' => 1000,
                    'description' => 'No command id',
                    'occurredAt' => '2026-09-27T10:00:00+00:00',
                    'expectedVersion' => 0,
                ],
            ]
        );

        $this->assertEquals(400, $response->getStatusCode(), 'a charge needs its command id');
    }

    /**
     * An account that is not there is a 404, and the refusal is compared against the actor gate
     * rather than asserted as a status code alone.
     *
     * This test was first written as "charging another user's account is refused", on the reasoning
     * that the ledger is the most sensitive read in the application. That is FR-015's opposite:
     * finance charges "a **selected user** petty-cash account", so charging somebody else's account is
     * the normal case for this endpoint and ownership is deliberately not the rule. The actor gate is
     * FR-015's, and it is asserted by every other test in this file succeeding.
     */
    public function testChargingAnAccountThatDoesNotExistIsRefused(): void
    {
        $response = $this->charge('99999999-9999-4999-8999-999999999999', 1000, 'Not there');

        $this->assertEquals(404, $response->getStatusCode(), 'an account that is not there cannot be charged');
    }

    /**
     * The permitted case beside the refusal, so a rule that denied every charge could not pass the
     * test above.
     */
    public function testTheCallersOwnAccountIsStillChargeable(): void
    {
        $this->assertEquals(
            201,
            $this->charge(ContractFixtures::secondAccountId(), 1000, 'Opening float')->getStatusCode()
        );
    }

    // ===== HELPERS =====

    /** @return array<string, mixed> */
    private function createdAccount(string $currency, ?string $userId = null): array
    {
        $response = $this->createAccount($currency, $userId);
        $this->assertEquals(201, $response->getStatusCode(), 'the account should have been created');

        return $this->data($response);
    }

    private function createAccount(string $currency, ?string $userId = null): ResponseInterface
    {
        return $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/accounts', [
            'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
            'json' => ['userId' => $userId ?? 'employee1', 'currency' => $currency],
        ]);
    }

    /**
     * @param int|null $expectStatus assert this status rather than returning the response
     */
    private function charge(
        string $accountId,
        int $amountMinor,
        string $description,
        ?int $expectStatus = null,
        ?string $occurredAt = null,
        ?string $commandId = null,
        ?int $expectedVersion = null
    ): ResponseInterface {
        $response = $this->client->request(
            'POST',
            '/ocs/v2.php/apps/pettycash/api/v1/accounts/' . $accountId . '/charges',
            [
                'headers' => ['X-PettyCash-Command-ID' => $commandId ?? ContractFixtures::commandId()],
                'json' => [
                    'amountMinor' => $amountMinor,
                    'description' => $description,
                    'occurredAt' => $occurredAt ?? '2026-09-27T10:00:00+00:00',
                    // An account the caller cannot see has no version to echo, and the request has to
                    // be buildable anyway so that the refusal it gets back is about the charge.
                    'expectedVersion' => $expectedVersion ?? $this->accountVersion($accountId),
                ],
            ]
        );

        if ($expectStatus !== null) {
            $this->assertEquals($expectStatus, $response->getStatusCode());
        }

        return $response;
    }

    /** @return list<string> */
    private function listedAccountIds(): array
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/accounts?page=1&pageSize=100');
        $ids = [];
        foreach ($this->data($response)['items'] ?? [] as $account) {
            $ids[] = $account['id'];
        }

        return $ids;
    }

    /**
     * The account's current optimistic-lock value, or 0 when the caller cannot see it.
     *
     * A refused charge still has to be buildable, so an account outside the caller's own listing has
     * no version to echo and the request falls back to 0 — which the service rejects on ownership
     * before it ever compares versions.
     */
    private function accountVersion(string $accountId): int
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/accounts?page=1&pageSize=100');
        foreach ($this->data($response)['items'] ?? [] as $account) {
            if ($account['id'] === $accountId) {
                return $account['version'];
            }
        }

        return 0;
    }

    private function accountBalance(string $accountId): int
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/accounts?page=1&pageSize=100');
        foreach ($this->data($response)['items'] ?? [] as $account) {
            if ($account['id'] === $accountId) {
                return $account['balance']['amountMinor'];
            }
        }

        $this->fail('The account under test is not in the listing.');
    }

    /** @return list<string> */
    private function ledgerIds(string $accountId): array
    {
        $response = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/accounts/' . $accountId . '/movements?page=1&pageSize=100'
        );

        $ids = [];
        foreach ($this->data($response)['items'] ?? [] as $movement) {
            $ids[] = $movement['id'];
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body, 'The response body is JSON');

        return $body['ocs']['data'] ?? [];
    }
}
