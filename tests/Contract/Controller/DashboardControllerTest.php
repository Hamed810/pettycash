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

    public function testEveryCardCarriesFiguresAndTheBoundTheyWereComputedUnder(): void
    {
        foreach ($this->cards() as $key => $card) {
            $this->assertArrayHasKey('figures', $card, "the {$key} card must carry figures");
            $this->assertIsArray($card['figures']);

            // Every repository read behind these figures is paged and no domain repository has a count
            // query, so a figure is a window size. The response states the bound instead of letting a
            // window size pose as an instance total.
            $this->assertArrayHasKey('figuresBoundedBy', $card, "the {$key} card must state its bound");
            $this->assertSame(100, $card['figuresBoundedBy']);
            $this->assertArrayHasKey('figuresTruncated', $card);
            $this->assertIsBool($card['figuresTruncated']);
        }
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
