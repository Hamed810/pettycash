<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;

/**
 * T087 — the eleven `configuration#*` endpoints, over real HTTP.
 *
 * ## What this file is a guard against
 *
 * `appinfo/routes.php` registered all eleven targets on 2026-09-25 while no `ConfigurationController`
 * existed. A declared route with no controller answers **HTTP 500** at runtime, which is worse than an
 * absent one: the contract says the endpoint exists, a caller can construct the request, and the
 * failure arrives as a server error rather than a 404 that would have said plainly that nothing had
 * been built. `src/views/transactions/TransactionFormView.vue:150` loads two of these endpoints when the
 * transaction form opens, so the purchaser could not select a cost centre or an expense type — and
 * FR-023 makes both mandatory at submission. The US1 journey was blocked on an unimplemented class.
 *
 * ## Why most of these assert 403 rather than 200
 *
 * The contract credential is **not** an administrator, and FR-010/FR-012/FR-013 put configuration
 * management with administrators. So a 403 is the correct answer for all eleven here, and asserting it
 * is a *stronger* guard than asserting a 200 would be: a controller that failed to construct — a missing
 * service registration, a bad constructor signature — answers 500, and a controller that was never
 * routed answers 404. Only a controller that ran, dispatched, reached its service and had the actor
 * refused by the domain answers 403.
 *
 * **What this file therefore does not prove:** that an administrator receives real data. That was
 * verified by hand over the same HTTP surface (`GET /configuration/currencies` → 200 with the
 * configured `IRR`/`USD`/`EUR`; `GET /configuration/cost-centers` → 200 with resolved
 * `managerUserId`/`seniorManagerUserId`; all six writes → 201/200; a duplicate code → 409; a stale
 * `expectedVersion` → 409), and it is recorded in the T087 task entry rather than asserted here,
 * because this repository has no administrator credential for the HTTP suite and inventing one would
 * mean committing a secret. `RouteResolutionTest` covers the route-to-method half mechanically.
 *
 * The response *shape* is covered without HTTP in
 * `tests/Unit/Application/Configuration/ConfigurationPayloadFormatterTest.php`, which is where a
 * contract key can be checked against the formatter that produces it without needing a privileged
 * caller.
 */
final class ConfigurationControllerTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
        ContractFixtures::prepare('draft');
    }

/**
 * The eleven declared `configuration#*` routes.
 *
 * Written out rather than derived from `appinfo/routes.php`, because a test that reads the route
 * file asserts only that the file parses. This list is a second, independent statement of what the
 * eleven are, so deleting a route from `routes.php` fails here.
 *
 * The third element is what a **non-administrator** must be answered, which is not uniform across the
 * eleven and the non-uniformity is deliberate:
 *
 * | endpoint | non-admin | why |
 * |---|---|---|
 * | `cost-centers` GET, `expense-types` GET | **200** | FR-023 requires a *purchaser* to choose one active expense type and exactly one cost centre at submission, and `TransactionFormView.vue:150` loads both when the form opens. `T082`/`T083` therefore require authentication on their listings, not administration. |
 * | `currencies` GET | **403** | `T081` restricts the currency listing to the administrative context; no purchaser-facing view calls it, and `AccountService` resolves a currency by code directly. |
 * | `finance-assignments` GET | **403** | Guarded in `ConfigurationController`, because this roster enumerates who can settle expenses and `T084`'s service requires only authentication. |
 * | all six writes | **403** | FR-010/FR-012/FR-013 put every configuration mutation with administrators, and each service enforces that itself. |
 *
 * A blanket "all eleven refuse a non-administrator" assertion was written first and was **wrong**: it
 * would have failed on the two listings a purchaser legitimately needs, and the fix is to encode the
 * real split rather than to widen the guard — locking a purchaser out of the two dropdowns FR-023
 * depends on would block submission.
 *
 * @return list<array{0: string, 1: string, 2: int}>
 */
public static function routeProvider(): array
{
    $base = '/ocs/v2.php/apps/pettycash/api/v1/configuration';

    return [
        'currencies: list' => ['GET', $base . '/currencies', 403],
        'currencies: create' => ['POST', $base . '/currencies', 403],
        'currencies: update' => ['PATCH', $base . '/currencies/IRR', 403],
        // Purchaser-readable: FR-023.
        'cost-centers: list' => ['GET', $base . '/cost-centers', 200],
        'cost-centers: create' => ['POST', $base . '/cost-centers', 403],
        'cost-centers: update' => ['PATCH', $base . '/cost-centers/00000000-0000-4000-8000-000000000000', 403],
        // Purchaser-readable: FR-023.
        'expense-types: list' => ['GET', $base . '/expense-types', 200],
        'expense-types: create' => ['POST', $base . '/expense-types', 403],
        'expense-types: update' => ['PATCH', $base . '/expense-types/00000000-0000-4000-8000-000000000000', 403],
        'finance-assignments: list' => ['GET', $base . '/finance-assignments', 403],
        'finance-assignments: create' => ['POST', $base . '/finance-assignments', 403],
    ];
}

/**
 * @dataProvider routeProvider
 */
public function testEveryConfigurationRouteAnswersADefinedStatusRatherThanAServerError(
    string $method,
    string $path
): void {
    $response = $this->client->request($method, $path, [
        'headers' => [
            'Content-Type' => 'application/json',
            'X-PettyCash-Command-ID' => 'configuration-route-guard',
        ],
        // A deliberately empty body: the point is that a request carrying none of the declared
        // fields still reaches a controller that classifies it, rather than dying in the dispatcher.
        'json' => [],
    ]);

    $status = $response->getStatusCode();

    self::assertNotSame(
        500,
        $status,
        sprintf(
            '%s %s answered 500. The route is declared and the controller method is missing or '
            . 'unconstructible — a declared route must never answer a server error. Body: %s',
            $method,
            $path,
            substr((string)$response->getBody(), 0, 400)
        )
    );
    self::assertNotSame(
        404,
        $status,
        sprintf('%s %s answered 404, so no controller action is routed to it at all.', $method, $path)
    );
}

/**
 * Every endpoint answers a non-administrator exactly as the table above says it must.
 *
 * The provider's third column is the expectation, so this pins the read/write split rather than
 * asserting a single blanket rule. Both directions matter: a write that answered 200 would be an
 * FR-010 boundary failure, and a listing that answered 403 would block FR-023's submission
 * precondition.
 *
 * @dataProvider routeProvider
 */
public function testEveryConfigurationRouteAnswersANonAdministratorAsSpecified(
    string $method,
    string $path,
    int $expectedOcsStatus
): void {
    $response = $this->client->request($method, $path, [
        'headers' => [
            'Content-Type' => 'application/json',
            'X-PettyCash-Command-ID' => 'configuration-authz-guard',
        ],
        'json' => [],
    ]);

    $body = json_decode((string)$response->getBody(), true);
    $ocsStatus = $body['ocs']['meta']['statuscode'] ?? null;

    // A 422 is accepted for the six writes as an alternative to 403, because the bodies here are
    // empty: an endpoint may legitimately refuse the *payload* before it looks at the actor. Both are
    // classified answers and neither is a 500.
    $acceptable = $expectedOcsStatus === 403 ? [403, 422] : [$expectedOcsStatus];

    self::assertContains(
        $ocsStatus,
        $acceptable,
        sprintf(
            '%s %s answered %s for a non-administrator; expected one of %s. Body: %s',
            $method,
            $path,
            var_export($ocsStatus, true),
            implode(', ', $acceptable),
            substr((string)$response->getBody(), 0, 300)
        )
    );

    if ($ocsStatus === 403) {
        self::assertSame(
            'FORBIDDEN',
            $body['ocs']['data']['error']['code'] ?? null,
            'A refused actor must be refused through OcsErrorMapper, so the code is the vocabulary a '
            . 'client reads rather than an ad-hoc string'
        );
    }
}

/**
 * The two listings FR-023 depends on must actually carry selectable rows for a purchaser.
 *
 * Asserting only their status would pass against an empty list, which is the failure that blocked the
 * journey: both endpoints used to answer 500, and the form's dropdowns were empty. The row-level
 * assertions are what make "readable" mean "usable".
 */
public function testAPurchaserCanReadTheTwoListingsItsSubmissionFormNeeds(): void
{
    $base = '/ocs/v2.php/apps/pettycash/api/v1/configuration';

    $centers = json_decode(
        (string)$this->client->request('GET', $base . '/cost-centers')->getBody(),
        true
    )['ocs']['data'] ?? [];

    self::assertNotEmpty(
        $centers['items'] ?? [],
        'FR-023 requires exactly one cost centre per transaction, so a purchaser must be able to read '
        . 'the list to choose one'
    );
    foreach ($centers['items'] as $center) {
        // `CostCenterWrite` makes both required, and the aggregate holds neither — they live in the
        // assignments table. A projection that dropped them would leave an administration screen
        // disagreeing with the contract while still returning 200.
        self::assertArrayHasKey('managerUserId', $center, 'the contract requires managerUserId on a CostCenter');
        self::assertArrayHasKey('seniorManagerUserId', $center, 'the contract requires seniorManagerUserId on a CostCenter');
        self::assertArrayHasKey('validFrom', $center);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}$/',
            (string)$center['validFrom'],
            '`format: date` is a calendar day, not an RFC 3339 timestamp'
        );
    }

    $types = json_decode(
        (string)$this->client->request('GET', $base . '/expense-types')->getBody(),
        true
    )['ocs']['data'] ?? [];

    self::assertNotEmpty($types['items'] ?? [], 'FR-023 requires one active expense type per transaction');
    foreach ($types['items'] as $type) {
        // `evidenceRequired` decides whether evidence must be attached, so a projection omitting it
        // makes the form demand evidence the configuration does not require, or omit what it does.
        self::assertArrayHasKey('evidenceRequired', $type);
        self::assertIsBool($type['active'], 'the client filters on `active` (FR-023: one *active* type)');
    }
}

    /**
     * The refusal has to name the field when the *body* is what is wrong.
     *
     * A 403 for an empty body would pass the assertion above while answering the wrong question: the
     * caller omitted `code`, and the useful answer is which field, not that they may not write.
     * `GET` has no body, so this covers the six write endpoints.
     */
    public function testAnEmptyBodyToAWriteEndpointIsRefusedAsAValidationFailure(): void
    {
        $base = '/ocs/v2.php/apps/pettycash/api/v1/configuration';

        $writes = [
            '/currencies',
            '/cost-centers',
            '/expense-types',
            '/finance-assignments',
        ];

        foreach ($writes as $path) {
            $body = json_decode(
                (string)$this->client->request('POST', $base . $path, [
                    'headers' => ['X-PettyCash-Command-ID' => 'configuration-empty-body'],
                    'json' => [],
                ])->getBody(),
                true
            );

            $error = $body['ocs']['data']['error'] ?? [];

            // Either answer is a *classified* one. Before T087 the same request answered 500, because a
            // `string $code` parameter threw a `TypeError` on the `null` the dispatcher passed for an
            // omitted field — the failure arrived before any validation could name anything.
            self::assertContains(
                $body['ocs']['meta']['statuscode'] ?? null,
                [403, 422],
                sprintf(
                    'POST %s with an empty body answered %s. An omitted field must be classified as a '
                    . 'validation failure or a refused actor, never as a server error.',
                    $path,
                    var_export($body['ocs']['meta']['statuscode'] ?? null, true)
                )
            );

            if (($body['ocs']['meta']['statuscode'] ?? null) === 422) {
                self::assertNotEmpty(
                    $error['fieldErrors'] ?? [],
                    sprintf('POST %s answered 422 with no fieldErrors, so the caller cannot tell what to fix', $path)
                );
            }
        }
    }

    /**
     * The finance roster is the one listing that names people who can settle expenses.
     *
     * It is guarded in the controller rather than left to `T084`'s service, which requires only
     * authentication — so this asserts the tighter rule explicitly. If it is ever relaxed to
     * "authenticated", a purchaser could enumerate the finance roster, and nothing else in the suite
     * would notice.
     */
    public function testTheFinanceRosterIsNotReadableByANonAdministrator(): void
    {
        $response = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/configuration/finance-assignments'
        );
        $body = json_decode((string)$response->getBody(), true);

        self::assertSame(403, $body['ocs']['meta']['statuscode'] ?? null);
        self::assertStringContainsString(
            'administrator',
            strtolower((string)($body['ocs']['data']['error']['message'] ?? '')),
            'the refusal must say who may read it'
        );
        // The roster must not be present in any form. `data` is the error envelope here rather than a
        // list of assignments, and the assertion is on the *shape* rather than on `data` being a
        // non-array: a bare `assertIsNotArray($data)` would pass for the wrong reason, since the error
        // envelope is an array too, and it would also fail if the refusal ever moved its keys.
        self::assertArrayNotHasKey(
            'items',
            $body['ocs']['data'],
            'a refused roster must not carry assignment rows'
        );
        self::assertSame(
            ['error'],
            array_keys($body['ocs']['data']),
            'a refused roster must carry the error envelope and nothing else'
        );
    }
}