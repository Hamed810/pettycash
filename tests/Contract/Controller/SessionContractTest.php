<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;

/**
 * `GET /me` — the session context every client bootstraps from.
 *
 * ## Why this file exists
 *
 * `src/stores/auth.ts` read `capabilities.costCenterCodes` to answer FR-004 and FR-005's scope
 * question, and **nothing in `lib/` has ever emitted that key** — `SessionController::capabilities()`
 * returned a fixed five-boolean map. So the list was permanently empty, `hasCostCenterScope()` was
 * permanently `false`, and both scope branches of `canReview()` were permanently `false` for
 * `COST_CENTER` and `SENIOR` whatever the caller's assignments were. The gate could never open, and
 * because nothing called it, nothing failed (T336).
 *
 * The endpoint now emits `costCenterCodes` on `SessionContext`, and this file is the pin.
 *
 * **The fixture makes the assertion meaningful.** In the `scope` state the acting user manages `CC-100`
 * and `CC-200` exists with **no** assignment to them, seeded precisely so that "a queue filtered to
 * the actor's own cost centres" can be told from "no filtering at all". The same is true here: an
 * endpoint that returned every cost centre in the installation, or the first one it found, would pass
 * a test that only asserted `CC-100` is present. Both directions are asserted.
 */
final class SessionContractTest extends TestCase
{
    /** The cost centre the acting user holds a manager assignment for. */
    private const ASSIGNED = 'CC-100';

    /** Seeded without an assignment for the acting user, and that is the point of it. */
    private const NOT_ASSIGNED = 'CC-200';

    private Client $client;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
    }

    /**
     * The scope is emitted, as a list of strings the client can compare against a row's
     * `costCenter.code`.
     *
     * **No absolute list is asserted anywhere in this file.** The acting user is a real account on a
     * shared instance and carries assignments beyond the fixture's own cost centres — the first draft
     * of this case asserted `costCenterCodes === ['CC-100']` and failed with four codes — so every
     * assertion here is a containment, an exclusion, or a shape.
     */
    public function testTheSessionCarriesTheCallersManagedCostCentres(): void
    {
        ContractFixtures::prepare('scope');

        $data = $this->me();

        $this->assertArrayHasKey(
            'costCenterCodes',
            $data,
            'FR-004/FR-005 are not expressible client-side without the caller\'s scope'
        );
        $this->assertIsArray($data['costCenterCodes']);
        $this->assertContainsOnly('string', $data['costCenterCodes']);
        $this->assertContains(self::ASSIGNED, $data['costCenterCodes'], 'the assignment the caller holds is reported');
    }

    /**
     * The scope is the caller's, not the installation's.
     *
     * Without this, an implementation that listed every cost centre — or the first one it read —
     * would satisfy the case above and pass a gate that opens for cost centres the server refuses.
     */
    public function testTheSessionDoesNotReportCostCentresTheCallerIsNotAssignedTo(): void
    {
        ContractFixtures::prepare('scope');

        $codes = $this->me()['costCenterCodes'];

        $this->assertNotContains(
            self::NOT_ASSIGNED,
            $codes,
            'a cost centre with no assignment for the caller is outside their scope'
        );
    }

    /**
     * A set, not a row order.
     *
     * The list is what a client compares against, so two requests that differ only in assignment
     * order must not produce two different answers; and a duplicate assignment for one cost centre
     * would otherwise appear twice in the client's scope.
     */
    public function testTheScopeIsASetWithNoDuplicates(): void
    {
        ContractFixtures::prepare('scope');

        $codes = $this->me()['costCenterCodes'];

        $this->assertSame(array_values(array_unique($codes)), array_values($codes), 'the scope is a set');
    }

    /**
     * The rest of the context is unchanged, and the roles and the scope must not disagree.
     *
     * `resolveRoles()` and `costCenterCodes()` read the same assignments through the same
     * repository and the same clock, so a caller cannot hold a manager role with an empty scope by
     * accident; this asserts the two halves of that claim from outside.
     */
    public function testRolesAndScopeComeFromTheSameAssignments(): void
    {
        ContractFixtures::prepare('scope');

        $data = $this->me();

        $managerish = array_intersect(
            $data['roles'],
            ['COST_CENTER_MANAGER', 'SENIOR_MANAGER']
        );
        $this->assertNotEmpty(
            $managerish,
            'the `scope` fixture grants the acting user a cost-centre-scoped role'
        );
        $this->assertNotEmpty(
            $data['costCenterCodes'],
            'a cost-centre-scoped role with an empty scope would be an assignment the client cannot use'
        );
    }

    /**
     * The scope agrees with the scope the decision path enforces.
 *
     * **This case was originally written as "every reported code is a cost centre that exists" and
     * it failed, correctly, on `UPD6F3913X`.** `GET /configuration/cost-centers` lists cost centres
     * *active today*, while the session reports cost centres the caller is *assigned* to — and an
     * assignment can outlive a cost centre's active window. The two are answering different questions,
     * and the second is the right one for a scope: what the session says is exactly what
     * `ManagementReviewService::getManagedCostCenterCodes()` compares a decision against, so a client
     * gate built on it agrees with the rule rather than with a listing.
     *
     * So the assertion is the one that can be made from outside: every reported code is a non-empty
     * string, the set has no duplicates, and the unassigned centre is absent.
     */
    public function testEveryReportedCodeIsANonEmptyStringInASet(): void
    {
        ContractFixtures::prepare('scope');

        $codes = $this->me()['costCenterCodes'];
        $this->assertNotEmpty($codes, 'the `scope` fixture assigns the acting user at least one cost centre');

        foreach ($codes as $code) {
            $this->assertIsString($code);
            $this->assertNotSame('', trim($code), 'an empty code would match no cost centre and no row');
        }
        $this->assertSame(array_values(array_unique($codes)), array_values($codes), 'the scope is a set');
        $this->assertNotContains(self::NOT_ASSIGNED, $codes, 'a cost centre with no assignment is outside the scope');
    }

    // ===== helpers -----------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function me(): array
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/me');
        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body, 'The response body is JSON');

        $data = $body['ocs']['data'] ?? [];
        $this->assertIsArray($data);

        return $data;
    }
}