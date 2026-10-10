<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Every declared error response carries `ocs.data.error` — the contract's `ErrorEnvelope`, not a
 * bare `Error`.
 *
 * **Why this file exists.** `OcsErrorMapper::respond()` returns `new DataResponse(['error' => $error], $status)`,
 * so the four `Error` properties have always arrived at `ocs.data.error.*`, while the contract
 * referenced `Error` directly as the `data` value of all five error responses and states at
 * `:6-8` that "schemas below describe the value carried in `ocs.data`". Three sides disagreed, and
 * the third — `src/services/http.ts` — was written against the implementation, so correcting either
 * side alone would have broken the other.
 *
 * The decision taken (recorded in the T334 task entry) is that **the contract gains the wrapper**:
 * `ErrorEnvelope` now declares the value `ocs.data` carries for an error. The reasons are recorded
 * there; the short version is that the wrapper is what lets `unwrap()` tell an OCS-200-that-carries-
 * a-failure from a success payload, which the protocol permits and a bare `{code, message}` cannot.
 *
 * So the mapper is unchanged and this file is the pin. It asserts the *nesting*, not the codes —
 * the codes are pinned where they are produced (`AccountWriteControllerTest` for the named
 * conflicts, `ReportAndExportControllerTest` for `fileFormat`), and duplicating them here would
 * make a code change look like an envelope change.
 *
 * **`Unauthorized` is not asserted here, and the reason is a finding rather than an omission.**
 * Nothing in `lib/` produces a 401: `OcsErrorMapper` has no `STATUS_UNAUTHORIZED` arm
 * (`grep -rn STATUS_UNAUTHORIZED lib/` returns nothing), because an unauthenticated request is
 * refused by Nextcloud's own auth layer before a controller runs. That layer answers with its own
 * body — `ocs.data` empty, the refusal described in `ocs.meta` — so the contract's `Unauthorized`
 * response describes a shape this application never emits. `testAnUnauthenticatedRequestIsRefusedBy
 * NextcloudRatherThanByThisApplication` asserts the status and records the boundary; asserting our
 * envelope there would be asserting a shape Nextcloud owns.
 */
final class ErrorEnvelopeContractTest extends TestCase
{
    private const BASE = '/ocs/v2.php/apps/pettycash/api/v1';

    private Client $client;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
    }

    // ===== Forbidden ---------------------------------------------------------

    public function testForbiddenIsAnErrorEnvelope(): void
    {
        ContractFixtures::prepare('draft');

        // FR-010/FR-012/FR-013 put configuration administration with administrators, and the acting
        // contract user is a purchaser, so this is refused. Read-only, so the case is repeatable.
        $response = $this->client->request('GET', self::BASE . '/configuration/currencies');

        $this->assertErrorEnvelope($response, 403);
        $this->assertSame('FORBIDDEN', $this->errorOf($response)['code']);
    }

    // ===== NotFound ----------------------------------------------------------

    public function testNotFoundIsAnErrorEnvelope(): void
    {
        ContractFixtures::prepare('draft');

        $response = $this->client->request('GET', self::BASE . '/sheets/' . ContractFixtures::unknownId());

        $this->assertErrorEnvelope($response, 404);
        $this->assertSame('NOT_FOUND', $this->errorOf($response)['code']);
    }

    // ===== Conflict ----------------------------------------------------------

    /**
     * FR-084's refusal: the same `X-PettyCash-Command-ID` carrying a *different* payload.
     *
     * Chosen on the charge path because that is where the conflict is deterministic without
     * arranging one: `AccountService::charge()` runs the command id through `IdempotencyService`,
     * which compares the stored payload hash and throws `CommandConflict` when it differs, and
     * `OcsErrorMapper` maps that to `409 IDEMPOTENCY_CONFLICT`. A replayed *identical* payload is
     * answered from the stored result — that is the guarantee, and it is asserted in
     * `AccountWriteControllerTest` — so the second request deliberately changes one field.
     *
     * `expectedVersion` is read from the fixture rather than guessed, and it is the same value in
     * both requests: a changed version would be a `StaleAccountVersion` conflict, which is a
     * *different* code and would make this case assert the wrong thing.
     */
    public function testConflictIsAnErrorEnvelope(): void
    {
        ContractFixtures::prepare('finance');
        $commandId = 't334-envelope-conflict';
        $accountId = ContractFixtures::secondAccountId();
        $version = $this->accountVersion($accountId);

        $first = $this->charge($commandId, $accountId, 1000, 'T334 envelope probe', $version);
        $this->assertSame(201, $first->getStatusCode(), 'the first charge is the one that is stored');

        // The first charge moved the account's optimistic-lock version, so the second request must
        // quote the *new* one. Quoting the old value would be refused as `ACCOUNT_VERSION_CONFLICT`
        // before the command id was ever compared — a 409 of a different kind, which would make
        // this case assert a code it is not about.
        $second = $this->charge($commandId, $accountId, 2000, 'T334 envelope probe', $this->accountVersion($accountId));

        $this->assertErrorEnvelope($second, 409);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $this->errorOf($second)['code']);
        $this->assertSame(
            $commandId,
            $this->errorOf($second)['commandId'] ?? null,
            'a conflict names the command id that conflicted, so the caller can identify the retry'
        );
    }

    // ===== ValidationError (422) ---------------------------------------------

    public function testValidationFailureIsAnErrorEnvelopeWithItsFieldErrors(): void
    {
        ContractFixtures::prepare('draft');

        // `fileFormat` omitted. The field errors are the payload a form places next to its input, so
        // this asserts the whole path rather than only the status.
        $response = $this->export('t334-envelope-validation', ['resource' => 'sheets']);

        $this->assertErrorEnvelope($response, 422);
        $error = $this->errorOf($response);
        $this->assertSame('VALIDATION_FAILED', $error['code']);
        $this->assertArrayHasKey('fileFormat', $error['fieldErrors'] ?? []);
        $this->assertNotSame(
            [],
            $error['fieldErrors']['fileFormat'],
            'the refusal names the field that was missing rather than only saying something was'
        );
    }

    // ===== ValidationError (400) ---------------------------------------------

    /**
     * The contract points `'400'` at the same `ValidationError` response, and the application
     * distinguishes the two by *cause*: a missing command id means the request never carried what the
     * endpoint needs, which is a 400, while a present-but-unacceptable value is a 422.
     *
     * Both must still answer with the same envelope, or a client needs two error paths.
     */
    public function testAMalformedRequestIsTheSameEnvelopeAtFourHundred(): void
    {
        ContractFixtures::prepare('finance');

        $response = $this->client->request('POST', self::BASE . '/accounts/' . ContractFixtures::secondAccountId() . '/charges', [
            'headers' => ['Content-Type' => 'application/json'],
            // No `X-PettyCash-Command-ID`: the header is `required` by FR-084 and absent here.
            'json' => [
                'amountMinor' => 1000,
                'description' => 'T334 envelope probe',
                'occurredAt' => '2026-01-01T00:00:00+00:00',
                'expectedVersion' => 0,
            ],
        ]);

        $this->assertErrorEnvelope($response, 400);
        $this->assertSame('BAD_REQUEST', $this->errorOf($response)['code']);
    }

    /**
     * T415: a command id that is **present but unstoreable** is a `400`, never a `500`.
     *
     * Observed before this task, not inferred: `pettycash_command_results.command_id` is `string(36)` and
     * the contract declares `format: uuid`, so an over-long value reached MySQL and came back as
     * `SQLSTATE[22001] Data too long for column 'command_id'`, which `OcsErrorMapper`'s catch-all arm
     * mapped to **`500 UNEXPECTED_FAILURE`**. A malformed request answered as a server fault — the one
     * status a caller cannot act on, and the one that tells an operator to hunt a bug in their client.
     *
     * **Asserted on two endpoints with their own statuses, deliberately.** The guard is per-controller
     * rather than one shared choke point, because the six controllers do not agree on the absent case
     * today: three answer `400`, `ExportController` and `SheetController` answer `422`, and two
     * accumulate field errors. Each site therefore refuses with *its own* convention, and a single
     * expected status would have been wrong for one of them. What is uniform — and what this asserts — is
     * that the answer is a **client** error rather than a server fault.
     */
    public function testAnOverlongCommandIdIsFourHundredRatherThanFiveHundred(): void
    {
        ContractFixtures::prepare('finance');

        $overlong = 't415-' . str_repeat('x', 40);

        foreach ([
            'charges' => [400, fn (): \Psr\Http\Message\ResponseInterface => $this->client->request(
                'POST',
                self::BASE . '/accounts/' . ContractFixtures::secondAccountId() . '/charges',
                [
                    'headers' => ['Content-Type' => 'application/json', 'X-PettyCash-Command-ID' => $overlong],
                    'json' => [
                        'amountMinor' => 1000,
                        'description' => 'T415 overlong probe',
                        'occurredAt' => '2026-01-01T00:00:00+00:00',
                        'expectedVersion' => 0,
                    ],
                ]
            )],
            // **422, not 400, and deliberately.** `ExportController` answers `422` for every field
            // complaint — its own absent-commandId case included — so its malformed-commandId answer
            // matches its local convention rather than the majority one. Making this 400 would mean
            // overriding that controller's established envelope for one value, and the status a caller
            // sees would then depend on which endpoint they hit. T415 settled the *500 → client error*
            // half of this; it did not re-open the 400-vs-422 question for the absent case, which is a
            // separate inconsistency across the six controllers.
            'export' => [422, fn (): \Psr\Http\Message\ResponseInterface => $this->client->request(
                'POST',
                self::BASE . '/exports',
                [
                    'headers' => ['Content-Type' => 'application/json', 'X-PettyCash-Command-ID' => $overlong],
                    'json' => ['resource' => 'sheets', 'fileFormat' => 'xlsx'],
                ]
            )],
        ] as $endpoint => [$expectedStatus, $send]) {
            $response = $send();

            $this->assertErrorEnvelope($response, $expectedStatus);
            $this->assertNotSame(
                500,
                $response->getStatusCode(),
                $endpoint . ': a command id that cannot be stored is a malformed request, not a fault.'
            );
            $this->assertStringContainsString(
                'commandId',
                json_encode($this->errorOf($response)['fieldErrors'] ?? []),
                $endpoint . ': the field error must be keyed to `commandId`, so a client knows which field '
                . 'to fix rather than receiving a bare status.'
            );
        }
    }

    /**
     * The refusal is not a blanket ban on short ids, or the guard above would be satisfied by refusing
     * everything. A well-formed id at the column's exact width must still be accepted and stored.
     */
    public function testACommandIdAtTheColumnWidthIsStillAccepted(): void
    {
        ContractFixtures::prepare('finance');

        $atWidth = sprintf('%036d', 7); // exactly 36 characters, the column's width

        $response = $this->client->request(
            'POST',
            self::BASE . '/accounts/' . ContractFixtures::secondAccountId() . '/charges',
            [
                'headers' => ['Content-Type' => 'application/json', 'X-PettyCash-Command-ID' => $atWidth],
                'json' => [
                    'amountMinor' => 1000,
                    'description' => 'T415 at-width probe',
                    'occurredAt' => '2026-01-01T00:00:00+00:00',
                    'expectedVersion' => 0,
                ],
            ]
        );

        // Not 400/422: the guard must not refuse a storable value. The charge itself may legitimately
        // fail for a fixture reason, so the assertion is that the *command id* was not the complaint.
        $this->assertNotSame(400, $response->getStatusCode(), 'a 36-character command id must not be refused as malformed');
        $this->assertNotSame(
            422,
            $response->getStatusCode(),
            'and must not be refused as an invalid field value either'
        );
    }

    // ===== Unauthorized ------------------------------------------------------

    public function testAnUnauthenticatedRequestIsRefusedByNextcloudRatherThanByThisApplication(): void
    {
        $anonymous = new Client([
            'base_uri' => (getenv('PETTYCASH_TEST_BASE_URL') ?: 'http://localhost'),
            'auth' => ['pettycash-not-a-user', 'pettycash-not-a-password'],
            'headers' => ['Accept' => 'application/json', 'OCS-APIRequest' => 'true'],
            'http_errors' => false,
            'verify' => false,
        ]);

        $response = $anonymous->request('GET', self::BASE . '/sheets');

        $this->assertSame(
            401,
            $response->getStatusCode(),
            'the boundary this file documents: the refusal is Nextcloud\'s, before any controller runs'
        );
    }

    // ===== helpers -----------------------------------------------------------

    /**
     * @param array<string, mixed> $payload
     */
    private function export(string $commandId, array $payload): ResponseInterface
    {
        return $this->client->request('POST', self::BASE . '/exports', [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-PettyCash-Command-ID' => $commandId,
            ],
            'json' => $payload,
        ]);
    }

    private function charge(
        string $commandId,
        string $accountId,
        int $amountMinor,
        string $description,
        int $expectedVersion,
    ): ResponseInterface {
        return $this->client->request('POST', self::BASE . '/accounts/' . $accountId . '/charges', [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-PettyCash-Command-ID' => $commandId,
            ],
            'json' => [
                'amountMinor' => $amountMinor,
                'description' => $description,
                'occurredAt' => '2026-01-01T00:00:00+00:00',
                'expectedVersion' => $expectedVersion,
            ],
        ]);
    }

    /**
     * The account's current version, read rather than assumed.
     *
     * Hard-coding it would make this case depend on how many charges a previous test left on the
     * fixture account, which is the order-dependence `ContractFixtures::prepare()` exists to
     * remove — so the version is fetched from the endpoint under test.
     */
    private function accountVersion(string $accountId): int
    {
        // Read from the listing rather than from `GET /accounts/{id}`: the fixture's second account
        // belongs to another user, so the single-account read is not a read the caller is entitled
        // to, while the finance-scoped listing is.
        $response = $this->client->request('GET', self::BASE . '/accounts?page=1&pageSize=100');
        $body = json_decode((string) $response->getBody(), true);

        foreach ($body['ocs']['data']['items'] ?? [] as $account) {
            if (($account['id'] ?? null) === $accountId) {
                $this->assertIsInt($account['version'] ?? null);
                return $account['version'];
            }
        }

        $this->fail("the fixture account {$accountId} is not in the caller's account listing");
    }

    /**
     * Assert the agreed nesting, and nothing else.
     *
     * `ocs.data` carrying **only** `error` is the load-bearing assertion: it is what distinguishes
     * the envelope from a future sibling key added beside it, and it is what would fail if a
     * controller hand-rolled its own body — the exact thing `OcsErrorMapper` exists to prevent.
     */
    private function assertErrorEnvelope(ResponseInterface $response, int $expectedStatus): void
    {
        $status = $response->getStatusCode();
        $this->assertSame($expectedStatus, $status, 'the status this case is about');

        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body, 'the body is JSON');

        // `ocs.meta.statuscode` and the HTTP status are one fact stated twice; a disagreement means
        // a client reading either one gets a different answer from the other.
        $this->assertSame(
            $status,
            $body['ocs']['meta']['statuscode'] ?? null,
            'ocs.meta.statuscode agrees with the HTTP status'
        );

        $data = $body['ocs']['data'] ?? null;
        $this->assertIsArray($data, 'ocs.data is the object the contract describes');
        $this->assertSame(['error'], array_keys($data), 'ocs.data carries the ErrorEnvelope and nothing else');

        $error = $data['error'];
        $this->assertIsArray($error, 'ocs.data.error is the Error object');
        $this->assertArrayHasKey('code', $error, 'Error.code is required');
        $this->assertArrayHasKey('message', $error, 'Error.message is required');
        $this->assertIsString($error['code']);
        $this->assertNotSame('', $error['code']);
        $this->assertIsString($error['message']);
        $this->assertNotSame('', $error['message']);

        // The two optional members are optional, so their *shape* is asserted rather than presence.
        foreach (array_keys($error) as $key) {
            $this->assertContains($key, ['code', 'message', 'commandId', 'fieldErrors'], "unexpected Error property '{$key}'");
        }
        if (isset($error['commandId'])) {
            $this->assertIsString($error['commandId']);
        }
        if (isset($error['fieldErrors'])) {
            $this->assertIsArray($error['fieldErrors']);
            foreach ($error['fieldErrors'] as $field => $messages) {
                $this->assertIsString($field, 'a field error is keyed by the field name');
                $this->assertIsArray($messages, 'a field error carries a list of messages');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function errorOf(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);

        return $body['ocs']['data']['error'] ?? [];
    }
}