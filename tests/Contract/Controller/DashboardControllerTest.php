<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * T112 (dashboard half): `GET /dashboard` over the real OCS surface.
 *
 * ## Why this file exists
 *
 * `/api/v1/dashboard` already resolved — to `SessionController::dashboard()` — so
 * `RouteResolutionTest` counted the requirement met. But that handler returned `['userId' => $uid]` per
 * held role and never consulted `DashboardQuery`, so no figure was ever computed and US7/AC1's
 * role-aware dashboard was unbuilt behind a green route check. The route now points at
 * `DashboardController`.
 *
 * This is a contract test rather than a unit test on purpose. Two of the things that were wrong are
 * invisible to a controller unit test: the route could still point at the old class, and
 * `DashboardQuery` was never registered in the container, so nothing could resolve it. Both only show
 * up when a real request arrives through the real container.
 *
 * ## The union rule is checked against `/me`, not against a guess
 *
 * Rather than hardcoding which roles the acting user holds, the test reads `/me` and asserts the
 * dashboard's card keys are exactly the roles that endpoint reports (with `admin` keyed off
 * administrator status). If the two ever disagree, one of them is wrong — and the test says which.
 */
final class DashboardControllerTest extends TestCase
{
    private const CARD_KEYS = ['purchaser', 'costCenter', 'senior', 'finance', 'admin'];

    private Client $client;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
        ContractFixtures::prepare('finance');
    }

    // ===== The endpoint answers at all =====

    public function testTheDashboardAnswers200(): void
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/dashboard');

        $this->assertEquals(200, $response->getStatusCode(), 'GET /dashboard must answer 200');
    }

    public function testTheRouteIsServedByTheRoleAwareController(): void
    {
        // The regression this whole task exists for. The stub answered 200 too, so a status-code
        // assertion cannot tell the two apart; the discriminator is whether figures are present.
        $cards = $this->cards();

        $this->assertNotEmpty($cards, 'a role-aware dashboard must return at least the purchaser card');
        $this->assertArrayHasKey('purchaser', $cards, 'FR-018: every user with access is a purchaser');
    }

    // ===== The contract's shape =====

    public function testOnlyTheContractsCardKeysAreReturned(): void
    {
        foreach (array_keys($this->cards()) as $key) {
            $this->assertContains(
                $key,
                self::CARD_KEYS,
                'the contract declares exactly these five Dashboard objects; a sixth key is a contract change'
            );
        }
    }

    public function testEveryCardNamesTheActingUser(): void
    {
        $me = $this->me();

        foreach ($this->cards() as $key => $card) {
            $this->assertSame($me['userId'], $card['userId'], "the {$key} card must belong to the caller");
        }
    }

    // ===== The union rule =====

    public function testTheCardsAreExactlyTheRolesTheUserHolds(): void
    {
        $me = $this->me();
        $expected = [];

        // Keyed by `Role`'s own backing values, not by a hand-written guess. The first version of this
        // map used snake_case, so it reported `/me` as declaring a role the Dashboard schema has no card
        // for -- a failure that looked like a contract gap and was really a wrong test.
        $roleToCard = [
            Role::Purchaser->value => 'purchaser',
            Role::CostCenterManager->value => 'costCenter',
            Role::SeniorManager->value => 'senior',
            Role::Finance->value => 'finance',
            Role::Administrator->value => 'admin',
        ];

        foreach ($me['roles'] as $role) {
            // A role with no declared card is a contract gap, not something to assert around: if
            // `/me` ever reports a role the Dashboard schema has no object for, the two surfaces have
            // diverged and this test should say so rather than quietly ignore it.
            $this->assertArrayHasKey(
                $role,
                $roleToCard,
                "the Dashboard schema declares no card for the role '{$role}' that /me reports"
            );
            $expected[] = $roleToCard[$role];
        }

        $this->assertEqualsCanonicalizing(
            $expected,
            array_keys($this->cards()),
            'a multi-role user must see the union of their roles, and no others (US7/AC1)'
        );
    }

    public function testAMultiRoleUserGetsMoreThanOneCard(): void
    {
        $me = $this->me();

        if (count($me['roles']) < 2) {
            $this->markTestSkipped('the contract test user does not hold two roles in this fixture');
        }

        $this->assertGreaterThanOrEqual(
            2,
            count($this->cards()),
            'US7/AC1: a multi-role user sees the union of their roles\' figures'
        );
    }

    // ===== Figures, and the bound they were computed under =====

    /**
     * Every card states how its figures were derived, and the statement is consistent with them.
     *
     * **Two envelopes exist, and this used to assert only one of them.** A card whose figures come
     * from a paged read is a *window*: it states `figuresBoundedBy: 100` and whether the window filled
     * up. A card whose figures are `COUNT`s has no window at all, so it states
     * `figuresBoundedBy: null` and `figuresExact: true` (T379).
     *
     * Asserting `100` unconditionally was true of every card this suite could reach and is **false of
     * the administrator card**, which T379 rebuilt on `countAll()`. It stayed green only because the
     * single OCS credential is a finance officer, so `admin` never appears here — a test whose subject
     * it cannot see. The assertion below therefore checks the envelope against the flag rather than
     * against a constant, so it is correct for a bounded card and an exact card alike, and will still
     * be checking the right thing if an administrator credential is ever added.
     */
    public function testEveryCardCarriesFiguresAndTheBoundTheyWereComputedUnder(): void
    {
        foreach ($this->cards() as $key => $card) {
            $this->assertArrayHasKey('figures', $card, "the {$key} card must carry figures");
            $this->assertIsArray($card['figures']);

            $this->assertArrayHasKey('figuresBoundedBy', $card, "the {$key} card must state its bound");
            $this->assertArrayHasKey('figuresExact', $card, "the {$key} card must state whether its figures are exact");
            $this->assertIsBool($card['figuresExact'], "the {$key}.figuresExact flag must be a boolean");
            $this->assertArrayHasKey('figuresTruncated', $card);
            $this->assertIsBool($card['figuresTruncated']);

            if ($card['figuresExact'] === true) {
                // A COUNT cannot be truncated, because there is no window for one to fall out of. Both
                // halves matter: stating a bound beside an exact figure asserts a limit the figure
                // never had, which is the `totalAccounts: 0` defect in its second form.
                $this->assertNull(
                    $card['figuresBoundedBy'],
                    "the {$key} card's figures are exact, so it must not claim a bound"
                );
                $this->assertFalse(
                    $card['figuresTruncated'],
                    "the {$key} card's figures are exact, so truncation cannot apply to them"
                );

                continue;
            }

            // The narrow claim this test actually makes: a bounded window is stated, and a figure read
            // from a window is a window size rather than an instance total.
            $this->assertSame(100, $card['figuresBoundedBy'], "the {$key} card's figures are window sizes");
        }
    }

    /**
     * The administrator card's three figures cannot be asserted from here, and this records why.
     *
     * T379's acceptance criteria ask for "a contract test [that] asserts each admin figure against an
     * independently computed expectation … so a constant cannot pass". `GET /accounts?active=false`
     * aside, that is **not deliverable over HTTP in this suite**, and the reason is structural rather
     * than a gap someone left: `OcsTestClient::create()` authenticates with exactly one credential,
     * `PETTYCASH_TEST_USER`, and that user is a finance officer rather than an administrator. The
     * union rule above (`testTheCardsAreExactlyTheRolesTheUserHolds`) keys `admin` off administrator
     * status, so the admin card is simply not in the response here — which is also why the previous
     * version of `testEveryCardCarriesFiguresAndTheBoundTheyWereComputedUnder` could assert
     * `figuresBoundedBy === 100` for "every card" and stay green while the admin card contradicted it.
     *
     * **So the assertions live where the credential can reach the subject.** They are in
     * `tests/Integration/Persistence/RepositoryRoundTripTest.php`
     * (`testEachInstanceCountEqualsTheTableItCounts`, `testEachInstanceCountIsWiderThanTheFilteredReadItReplaced`),
     * which drives the real repositories over real SQL: each `countAll()` is compared against
     * `COUNT(*)` on its own table, and against the narrower read it replaced, on an instance seeded
     * with sheets in two statuses, a transaction outside the finance stage and a deactivated account.
     * `totalAccounts: 0` cannot satisfy either comparison, which is the constant the criterion names.
     *
     * This test asserts the *precondition* rather than skipping silently: if an administrator
     * credential is ever added to `.env.testing`, it fails and says what has become possible, instead
     * of continuing to report the admin figures as verified from a card it cannot see.
     */
    public function testTheAdministratorCardIsUnreachableFromThisCredential(): void
    {
        if (isset($this->cards()['admin'])) {
            $this->fail(
                'the acting credential now receives the admin card, so this file must assert the '
                . "administrator's figures rather than record that they cannot be asserted"
            );
        }

        $this->markTestSkipped(
            'the single OCS credential holds no administrator role, so `admin` is not in the response '
            . 'and its three figures cannot be asserted over HTTP here; they are asserted against '
            . '`COUNT(*)` and against the filtered read they replaced in '
            . 'tests/Integration/Persistence/RepositoryRoundTripTest.php'
        );
    }

    public function testThePurchaserCardCountsOpenSheetsAndReturnedTransactions(): void
    {
        $cards = $this->cards();

        $this->assertArrayHasKey('purchaser', $cards);
        $this->assertArrayHasKey('openSheets', $cards['purchaser']['figures']);
        $this->assertArrayHasKey('returnedToMe', $cards['purchaser']['figures']);
        $this->assertGreaterThanOrEqual(0, $cards['purchaser']['figures']['openSheets']);
    }

    public function testFiguresAreIntegers(): void
    {
        foreach ($this->cards() as $key => $card) {
            foreach ($card['figures'] as $name => $value) {
                $this->assertIsInt(
                    $value,
                    "the {$key}.{$name} figure must be an integer; a stringified count would read as a total"
                );
            }
        }
    }

    // ===== Helpers =====

    /** @return array<string, mixed> */
    private function cards(): array
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/dashboard');
        $this->assertEquals(200, $response->getStatusCode(), 'GET /dashboard must answer 200');

        $data = $this->data($response);
        $this->assertIsArray($data, 'the dashboard payload is an object keyed by role');

        return $data;
    }

    /** @return array{userId: string, roles: list<string>} */
    private function me(): array
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/me');
        $this->assertEquals(200, $response->getStatusCode());

        $data = $this->data($response);
        $this->assertArrayHasKey('roles', $data);

        return ['userId' => $data['userId'], 'roles' => $data['roles']];
    }

    /** @return array<string, mixed> */
    private function data(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body, 'The response body is JSON');

        return $body['ocs']['data'] ?? [];
    }
}
