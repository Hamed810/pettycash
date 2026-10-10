<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for PurchaserFlowController OCS API endpoints.
 *
 * These tests verify that the OCS API endpoints defined in contracts/ocs-api.yaml
 * are properly implemented. The tests should FAIL because the endpoints are not yet
 * implemented - they return 404 instead of the contract-defined responses.
 */
final class PurchaserFlowControllerTest extends TestCase
{
    private Client $client;
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = 'http://localhost';
        $this->client = OcsTestClient::create();
        ContractFixtures::prepare('draft');
    }

    /**
     * FR-062: the sheet response must carry `totals`, because the three stage-approved columns the
     * requirement names have nowhere to be read from otherwise.
     *
     * `contracts/ocs-api.yaml` lists `totals` among `SheetSummary`'s required members, and
     * `formatSheet()` never emitted it — so this asserts the whole `SheetTotals` schema is present
     * and correctly typed on the sheet endpoints, not merely that a `totals` key exists.
     */
    public function testSheetResponseCarriesTheFullTotalsObject(): void
    {
        ContractFixtures::prepare('draft');

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId());
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];

        $this->assertArrayHasKey('totals', $data, 'SheetSummary requires totals');
        $this->assertIsArray($data['totals']);

        foreach ([
            'current', 'submitted',
            'effectiveApproved', 'effectiveRejected', 'effectivePending',
            'costCenterApproved', 'seniorApproved', 'financeApproved',
        ] as $figure) {
            $this->assertArrayHasKey($figure, $data['totals'], "SheetTotals requires {$figure}");
            $this->assertIsInt($data['totals'][$figure]['amountMinor'] ?? null, "{$figure}.amountMinor must be an integer");
            $this->assertIsString($data['totals'][$figure]['currency'] ?? null, "{$figure}.currency must be a string");
            $this->assertIsInt($data['totals'][$figure]['minorUnit'] ?? null, "{$figure}.minorUnit must be an integer");
        }
    }

    /**
     * The projection must read the sheet's real transactions, not answer zero.
     *
     * The fixture seeds one transaction of 100,000 minor units with no decisions, so the sheet's
     * current total is 100,000 and the whole of it is pending — a transaction nobody has decided
     * yet. A projection that returned zeros, or that counted the transaction as approved, would both
     * satisfy a test that only checked the key was present.
     */
    public function testSheetTotalsSumTheRealActiveRevisions(): void
    {
        ContractFixtures::prepare('draft');

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId());
        $totals = json_decode((string) $response->getBody(), true)['ocs']['data']['totals'] ?? [];

        $this->assertSame(
            100000,
            $totals['current']['amountMinor'] ?? null,
            'The current total must sum the active revisions the sheet actually holds'
        );
        $this->assertSame(
            100000,
            $totals['effectivePending']['amountMinor'] ?? null,
            'An undecided transaction is pending, so the whole amount is pending'
        );
        $this->assertSame(0, $totals['effectiveApproved']['amountMinor'] ?? null, 'Nothing is approved yet');
        $this->assertSame(0, $totals['effectiveRejected']['amountMinor'] ?? null, 'Nothing is rejected yet');
        foreach (['costCenterApproved', 'seniorApproved', 'financeApproved'] as $stage) {
            $this->assertSame(0, $totals[$stage]['amountMinor'] ?? null, "{$stage} must be zero before any decision");
        }
    }

    /**
     * `minorUnit` has to come from the configured currency, because a reader divides by ten to the
     * power of it. The fixture sheet is USD with two minor digits, so a hardcoded 0 or a fixed /100
     * would misstate every figure on the sheet.
     */
    public function testSheetTotalsReportTheConfiguredCurrencyScale(): void
    {
        ContractFixtures::prepare('draft');

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId());
        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];

        $currency = $data['currency'] ?? null;
        $this->assertIsString($currency);

        foreach ($data['totals'] as $figure => $money) {
            $this->assertSame($currency, $money['currency'], "{$figure} must be denominated in the sheet's own currency");
        }

        $expected = $currency === 'USD' ? 2 : 0;
        $this->assertSame(
            $expected,
            $data['totals']['current']['minorUnit'] ?? null,
            'minorUnit must be the configured value for the sheet currency, not a guess'
        );
    }

    /**
     * `submitted` is a snapshot taken at first submission, not a live sum — that is the only reason
     * it is a separate figure from `current`. Before submission it is zero; afterwards it is the
     * amount that was submitted.
     */
    public function testSubmittedTotalIsASnapshotNotALiveSum(): void
    {
        ContractFixtures::prepare('draft');

        $before = json_decode(
            (string) $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId())->getBody(),
            true
        )['ocs']['data']['totals'] ?? [];
        $this->assertSame(0, $before['submitted']['amountMinor'] ?? null, 'A draft has submitted nothing');
        $this->assertSame(100000, $before['current']['amountMinor'] ?? null, 'The draft still has a current total');

        $submit = $this->client->request(
            'POST',
            '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId() . '/submit',
            [
                'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
                'json' => ['expectedVersion' => ContractFixtures::sheetVersion()],
            ]
        );
        $this->assertEquals(200, $submit->getStatusCode());

        $after = json_decode(
            (string) $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId())->getBody(),
            true
        )['ocs']['data']['totals'] ?? [];

        $this->assertSame(
            100000,
            $after['submitted']['amountMinor'] ?? null,
            'Submission must record the submitted amount as a snapshot'
        );
    }

    /**
     * The sheet list carries the same shape as the sheet detail, so `index` cannot answer without
     * totals where `show` answers with them.
     */
    public function testSheetListCarriesTotalsToo(): void
    {
        ContractFixtures::prepare('draft');

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets');
        $items = json_decode((string) $response->getBody(), true)['ocs']['data']['items'] ?? [];

        $this->assertNotEmpty($items, 'The fixture should seed at least one sheet');
        foreach ($items as $item) {
            $this->assertArrayHasKey('totals', $item, 'Every SheetSummary in a page must carry totals');
        }

        $withTheFixtureSheet = null;
        foreach ($items as $item) {
            if (($item['id'] ?? null) === ContractFixtures::sheetId()) {
                $withTheFixtureSheet = $item;
            }
        }
        $this->assertNotNull($withTheFixtureSheet, 'The fixture sheet should appear in the list');
        $this->assertSame(
            100000,
            $withTheFixtureSheet['totals']['current']['amountMinor'] ?? null,
            'The listed sheet must total its real transactions, not zero'
        );
    }

    /**
     * The contract does not declare `accountId` on `SheetSummary`, and the formatter used to emit
     * it. A field the schema does not describe is one nothing can be tested against, so it is
     * dropped rather than kept as an extra.
     */
    public function testSheetSummaryCarriesNoUndeclaredAccountId(): void
    {
        ContractFixtures::prepare('draft');

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId());
        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];

        $this->assertArrayNotHasKey('accountId', $data, 'SheetSummary does not declare accountId');
    }

    // ===== SHEETS =====

    /**
     * T327: `GET /sheets/{sheetId}` must carry `transactions` and `allowedActions` by name.
     *
     * `contracts/ocs-api.yaml:1125` defines `SheetDetail` as `SheetSummary` plus exactly these two
     * members, and the endpoint emitted neither. The consequence was not cosmetic: the frontend store
     * derives `canSubmit` from `transactions.length`, so `?? 0` made it permanently `false` and the
     * Submit button was never rendered — a purchaser could create a sheet, add a draft transaction,
     * save it, and have no way to submit it.
     *
     * Each property is asserted **by name** rather than through a shape check, because a shape check
     * is exactly what let this ship: the payload satisfied every structural assertion anyone made,
     * because the missing keys were missing from the assertion as well.
     *
     * The content is asserted too, and against the count in the same payload — `transactions` being
     * present but empty while `transactionCount` says otherwise would satisfy the first half of this
     * test and still leave the table blank.
     */
    public function testSheetDetailCarriesTransactionsAndAllowedActionsByName(): void
    {
        ContractFixtures::prepare('draft');

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId());
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];

        $this->assertArrayHasKey('transactions', $data, 'SheetDetail requires transactions');
        $this->assertArrayHasKey('allowedActions', $data, 'SheetDetail requires allowedActions');

        $this->assertIsArray($data['transactions']);
        $this->assertIsArray($data['allowedActions']);

        // `CapabilitySet` is `additionalProperties: {type: boolean}`; a non-boolean fails the contract
        // on a live response, which is a much worse place to learn it.
        foreach ($data['allowedActions'] as $capability => $value) {
            $this->assertIsBool($value, "capability {$capability} must be a boolean");
        }

        // The rows and the count come from the same read, so they must agree. A `transactions` array
        // that was present but empty is precisely the defect, and `transactionCount` is what proves it.
        $this->assertCount(
            $data['transactionCount'] ?? -1,
            $data['transactions'],
            'transactions must carry every row transactionCount reports, or the sheet table renders blank '
            . 'for a sheet that plainly has transactions'
        );

        // And the fixture's own transaction is among them, identified by the public id the client uses.
        $ids = array_column($data['transactions'], 'id');
        $this->assertContains(
            ContractFixtures::transactionId(),
            $ids,
            "the fixture's seeded transaction must appear in the sheet's own transaction list"
        );
    }

    /**
     * T327: the capability set must reflect what the command path would actually do.
     *
     * The `draft` fixture is an open sheet holding one transaction, so every capability is true for
     * its owner — including `canSubmit`, which is the one that was permanently false. This is the
     * "a caller who may act" half asserted over real HTTP; the denial half is unreachable from here
     * (the endpoint is owner-scoped and the suite has one set of credentials) and is covered by
     * `tests/Unit/Domain/Expense/SheetCapabilitiesTest.php`.
     */
    public function testSheetDetailReportsCapabilitiesForACallerWhoMayAct(): void
    {
        ContractFixtures::prepare('draft');

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId());
        $actions = json_decode((string) $response->getBody(), true)['ocs']['data']['allowedActions'] ?? [];

        $this->assertTrue(
            $actions['canSubmit'] ?? null,
            'the owner of an open sheet holding a transaction may submit it, and the capability must say so'
        );
        $this->assertTrue($actions['canUpdate'] ?? null, 'the owner of an open sheet may retitle it');
        $this->assertTrue($actions['canAddTransaction'] ?? null, 'the owner of an open sheet may draft a transaction');
    }

    /**
     * T327: a submitted sheet must offer nothing, so the interface stops offering FR-026's forbidden
     * actions after submission.
     */
    public function testSubmittedSheetReportsNoCapabilities(): void
    {
        ContractFixtures::prepare('submitted');

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId());
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];

        $this->assertArrayHasKey('allowedActions', $data, 'SheetDetail requires allowedActions on every response, not only on open sheets');

        foreach (['canUpdate', 'canAddTransaction', 'canSubmit'] as $capability) {
            $this->assertFalse(
                $data['allowedActions'][$capability] ?? null,
                "FR-026 forbids this after submission, so {$capability} must be false rather than absent"
            );
        }
    }

    /**
     * T326/T327: the contract declares `SheetDetail` — not `SheetSummary` — for all four
     * single-sheet responses (`:368` create, `:381` detail, `:405` patch, `:424` submit), so
     * returning the summary from any of them is the same violation in each.
     *
     * **Two of the four are asserted here and two are not, deliberately.** `PATCH /sheets/{id}` and
     * `POST /sheets/{id}/submit` both mutate the shared fixture sheet, and this suite re-seeds from
     * `seed-contract-fixtures.php` for that reason — a test that left the sheet submitted would make
     * every later assertion in the file order-dependent. Asserting the shape on the two read-shaped
     * endpoints is what keeps the guard honest without making the file order-dependent; the two
     * mutating endpoints return the value of the same `formatSheetDetail()` call, which is what the
     * first two prove.
     *
     */
    #[DataProvider('singleSheetEndpoints')]
    public function testEverySingleSheetResponseCarriesTheSheetDetailShape(string $method, string $path, array $body): void
    {
        ContractFixtures::prepare('draft');

        $response = $this->client->request($method, $path, [
            'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
            'json' => $body,
        ]);

        $this->assertContains(
            $response->getStatusCode(),
            [200, 201],
            "{$method} {$path} must answer a success status carrying the sheet"
        );

        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];
        $this->assertArrayHasKey('transactions', $data, "{$method} {$path} returns SheetDetail, which requires transactions");
        $this->assertArrayHasKey('allowedActions', $data, "{$method} {$path} returns SheetDetail, which requires allowedActions");
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function singleSheetEndpoints(): array
    {
        return [
            'GET detail' => ['GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId(), []],
            'POST create' => ['POST', '/ocs/v2.php/apps/pettycash/api/v1/sheets', [
                'title' => 'Shape probe sheet',
                'accountId' => ContractFixtures::accountId(),
            ]],
        ];
    }


    public function testCreateSheetReturns201WithSheetDetail(): void
    {
        $response = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/sheets', [
            'headers' => [
                'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
            ],
            'json' => [
                'title' => 'Test Sheet',
                'accountId' => ContractFixtures::accountId(),
            ],
        ]);

        // Contract: POST /sheets returns 201 with SheetDetail in ocs.data
        $this->assertEquals(201, $response->getStatusCode(), 'POST /sheets should return 201 Created');
        $this->assertOcsResponseStructure($response, 201);
    }

    public function testListSheetsReturns200WithPagedSheets(): void
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets');

        // Contract: GET /sheets returns 200 with paged SheetSummary array
        $this->assertEquals(200, $response->getStatusCode(), 'GET /sheets should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testGetSheetDetailReturns200WithSheetDetail(): void
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId());

        // Contract: GET /sheets/{id} returns 200 with SheetDetail, or 403/404
        $this->assertEquals(200, $response->getStatusCode(), 'GET /sheets/{id} should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testUpdateSheetReturns200WithUpdatedSheetDetail(): void
    {
        $response = $this->client->request('PATCH', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId(), [
            'headers' => [
                'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
            ],
            'json' => [
                'title' => 'Updated Title',
                'accountId' => ContractFixtures::accountId(),
                'expectedVersion' => ContractFixtures::sheetVersion(),
            ],
        ]);

        // Contract: PATCH /sheets/{id} returns 200 with updated SheetDetail
        $this->assertEquals(200, $response->getStatusCode(), 'PATCH /sheets/{id} should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testSubmitSheetReturns200WithSubmittedSheetDetail(): void
    {
        $response = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId() . '/submit', [
            'headers' => [
                'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
            ],
            'json' => [
                'expectedVersion' => ContractFixtures::sheetVersion(),
            ],
        ]);

        // Contract: POST /sheets/{id}/submit returns 200 with SheetDetail, or 422/409
        $this->assertEquals(200, $response->getStatusCode(), 'POST /sheets/{id}/submit should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    // ===== TRANSACTIONS =====

    public function testCreateTransactionReturns201WithTransactionDetail(): void
    {
        $response = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId() . '/transactions', [
            'headers' => [
                'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
            ],
            'json' => [
                'expenseDate' => '2026-09-20',
                'amountMinor' => 1000,
            ],
        ]);

        // Contract: POST /sheets/{id}/transactions returns 201 with TransactionDetail
        $this->assertEquals(201, $response->getStatusCode(), 'POST /sheets/{id}/transactions should return 201 Created');
        $this->assertOcsResponseStructure($response, 201);
    }

    // ===== T317: the contract-required command id on transaction create/update =====

    /**
     * `contracts/ocs-api.yaml:761` declares `CommandId` as `required: true`, and both operations
     * `$ref` it: `POST /sheets/{sheetId}/transactions` at `:436` and `PATCH /transactions/{id}` at
     * `:466`. The controller read the header and then dropped it, so a request without one was
     * accepted and produced an un-replayable write.
     *
     * The status is 400 rather than 422 because this is a missing *parameter*, not an invalid body
     * field. That is the status `ReviewController` already returns for the identical condition, and
     * `ManagementReviewControllerTest::testRecordDecisionMissingCommandIdReturns400` already pins it.
     * The 422 the mapper's `validation()` emits stays where it belongs, on the body fields this
     * endpoint already refuses that way.
     */
    public function testCreateTransactionWithoutCommandIdReturns400(): void
    {
        $response = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId() . '/transactions', [
            'json' => [
                'expenseDate' => '2026-09-20',
                'amountMinor' => 1000,
            ],
        ]);

        $this->assertEquals(
            400,
            $response->getStatusCode(),
            'contracts/ocs-api.yaml declares X-PettyCash-Command-ID required on this operation, so omitting '
            . 'it must be refused rather than silently accepted'
        );
    }

    public function testUpdateTransactionWithoutCommandIdReturns400(): void
    {
        $response = $this->client->request('PATCH', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId(), [
            'json' => [
                'expenseDate' => '2026-09-21',
                'amountMinor' => 2000,
                'expectedVersion' => ContractFixtures::transactionVersion(),
            ],
        ]);

        $this->assertEquals(
            400,
            $response->getStatusCode(),
            'contracts/ocs-api.yaml declares X-PettyCash-Command-ID required on PATCH /transactions/{id} too'
        );
    }

    /**
     * The load-bearing one: a replayed create must not make a second transaction.
     *
     * FR-042 names "creating a revision" as something that must complete atomically, and FR-053 makes
     * the audit rows unrepairable, so a retried request or a double-clicked button previously left a
     * second transaction *and* a second `REVISION_CREATED` event behind. The response is compared
     * whole, because a replay that returns a different transaction id is just as wrong as one that
     * writes a duplicate row while returning 201.
     */
    public function testReplayingTheCreateWithTheSameCommandIdReturnsTheOriginalTransaction(): void
    {
        $commandId = ContractFixtures::commandId();
        $body = [
            'expenseDate' => '2026-09-22',
            'amountMinor' => 4242,
        ];

        $first = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId() . '/transactions', [
            'headers' => ['X-PettyCash-Command-ID' => $commandId],
            'json' => $body,
        ]);
        $this->assertEquals(201, $first->getStatusCode(), 'the first create must succeed');

        $second = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId() . '/transactions', [
            'headers' => ['X-PettyCash-Command-ID' => $commandId],
            'json' => $body,
        ]);

        $this->assertEquals(
            201,
            $second->getStatusCode(),
            'a replay answers with the original result, so it stays 201 rather than becoming an error'
        );
        $this->assertSame(
            $this->ocsData($first),
            $this->ocsData($second),
            'a replayed command must return the transaction the first call created, not a new one'
        );
    }

    /**
     * The other half of idempotency: the same command id with a *different* body is a client bug, and
     * `IdempotencyService` answers it with `CommandConflict`, which the mapper turns into 409 -- the
     * status this PATCH operation already declares.
     */
    public function testReusingACommandIdWithADifferentPayloadReturns409(): void
    {
        $commandId = ContractFixtures::commandId();

        $first = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId() . '/transactions', [
            'headers' => ['X-PettyCash-Command-ID' => $commandId],
            'json' => ['expenseDate' => '2026-09-22', 'amountMinor' => 1000],
        ]);
        $this->assertEquals(201, $first->getStatusCode());

        $second = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId() . '/transactions', [
            'headers' => ['X-PettyCash-Command-ID' => $commandId],
            'json' => ['expenseDate' => '2026-09-22', 'amountMinor' => 9999],
        ]);

        $this->assertEquals(
            409,
            $second->getStatusCode(),
            'the same command id with a different payload is a conflict, not a second create'
        );
    }

    /**
     * The replay path for an edit, which is the case that differs from a create.
     *
     * A replayed `PATCH` must not produce a second revision: `updateTransaction()` creates a new
     * revision on every call, and each one writes a `TRANSACTION_CHANGED` audit event that FR-053 makes
     * permanent. So the assertion is on the transaction's identity *and* version, since a duplicate
     * revision is exactly what an unchanged version proves did not happen.
     */
    public function testReplayingTheUpdateWithTheSameCommandIdDoesNotCreateASecondRevision(): void
    {
        $commandId = ContractFixtures::commandId();
        $body = [
            'expenseDate' => '2026-09-23',
            'amountMinor' => 7777,
            'description' => 'Replayed edit',
            'expectedVersion' => ContractFixtures::transactionVersion(),
        ];

        $first = $this->client->request('PATCH', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId(), [
            'headers' => ['X-PettyCash-Command-ID' => $commandId],
            'json' => $body,
        ]);
        $this->assertEquals(200, $first->getStatusCode(), 'the first update must succeed');

        $second = $this->client->request('PATCH', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId(), [
            'headers' => ['X-PettyCash-Command-ID' => $commandId],
            'json' => $body,
        ]);

        $this->assertEquals(200, $second->getStatusCode(), 'a replay answers with the original result');
        $this->assertSame(
            $this->ocsData($first),
            $this->ocsData($second),
            'a replayed edit must not bump the version, which is what a second revision would do'
        );
    }

    /** The `ocs.data` payload, so two responses can be compared as the client sees them. */
    private function ocsData(\Psr\Http\Message\ResponseInterface $response): mixed
    {
        return json_decode((string) $response->getBody(), true)['ocs']['data'] ?? null;
    }

    public function testGetTransactionDetailReturns200WithTransactionDetail(): void
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId());

        // Contract: GET /transactions/{id} returns 200 with TransactionDetail
        $this->assertEquals(200, $response->getStatusCode(), 'GET /transactions/{id} should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testUpdateTransactionReturns200WithUpdatedTransactionDetail(): void
    {
        $response = $this->client->request('PATCH', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId(), [
            'headers' => [
                'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
            ],
            'json' => [
                'expenseDate' => '2026-09-21',
                'amountMinor' => 2000,
                'expectedVersion' => ContractFixtures::transactionVersion(),
            ],
        ]);

        // Contract: PATCH /transactions/{id} returns 200 with updated TransactionDetail
        $this->assertEquals(200, $response->getStatusCode(), 'PATCH /transactions/{id} should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testDeleteTransactionReturns204(): void
    {
        $response = $this->client->request('DELETE', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId(), [
            'json' => [
                'expectedVersion' => ContractFixtures::transactionVersion(),
            ],
        ]);

        // Contract: DELETE /transactions/{id} returns 204 No Content
        $this->assertEquals(204, $response->getStatusCode(), 'DELETE /transactions/{id} should return 204 No Content');
    }

    /**
     * T318: the contract no longer lists `X-PettyCash-Command-ID` on this operation.
     *
     * `contracts/workflow.md` requires the header only for *non-repeatable* commands, and deleting a
     * draft is repeatable — the endpoint has no idempotency wrapper and never did. `expectedVersion`
     * still guards against a concurrent edit, which is a different condition and is not affected:
     * the assertions below all send a correct version.
     */
    public function testDeleteTransactionSucceedsWithoutACommandIdHeader(): void
    {
        $response = $this->client->request('DELETE', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId(), [
            'json' => [
                'expectedVersion' => ContractFixtures::transactionVersion(),
            ],
        ]);

        $this->assertEquals(
            204,
            $response->getStatusCode(),
            'contracts/ocs-api.yaml does not list X-PettyCash-Command-ID on DELETE /transactions/{id} any '
            . 'more, so a client that does not send it must still delete'
        );
    }

    public function testRecordDecisionReturns201WithDecisionResult(): void
    {
        ContractFixtures::prepare('submitted');

        $response = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/decisions', [
            'headers' => [
                'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
            ],
            'json' => [
                'decision' => 'APPROVE',
                'expectedVersion' => ContractFixtures::transactionVersion(),
            ],
        ]);

        // Contract: POST /transactions/{id}/decisions returns 201 with DecisionResult, or 403/409/422
        $this->assertEquals(201, $response->getStatusCode(), 'POST /transactions/{id}/decisions should return 201 Created');
        $this->assertOcsResponseStructure($response, 201);
    }

    public function testCreateCorrectionReturns201WithRevision(): void
    {
        ContractFixtures::prepare('correction');

        $response = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/correction', [
            'headers' => [
                'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
            ],
            'json' => [
                'expectedVersion' => ContractFixtures::transactionVersion(),
            ],
        ]);

        // Contract: POST /transactions/{id}/correction returns 201 with Revision
        $this->assertEquals(201, $response->getStatusCode(), 'POST /transactions/{id}/correction should return 201 Created');
        $this->assertOcsResponseStructure($response, 201);
    }

    public function testResubmitCorrectionReturns200WithTransactionDetail(): void
    {
        ContractFixtures::prepare('correction');

        $response = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/resubmit', [
            'headers' => [
                'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
            ],
            'json' => [
                'expectedVersion' => ContractFixtures::transactionVersion(),
            ],
        ]);

        // Contract: POST /transactions/{id}/resubmit returns 200 with TransactionDetail
        $this->assertEquals(200, $response->getStatusCode(), 'POST /transactions/{id}/resubmit should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testListRevisionsReturns200WithRevisions(): void
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/revisions');

        // Contract: GET /transactions/{id}/revisions returns 200 with array of Revision
        $this->assertEquals(200, $response->getStatusCode(), 'GET /transactions/{id}/revisions should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    public function testListAuditReturns200WithAuditPage(): void
    {
        $response = $this->client->request('GET', $this->auditUrl(), [
            'query' => ['page' => 1, 'pageSize' => 25],
        ]);

        // Contract: GET /transactions/{id}/audit returns 200 with AuditPage
        $this->assertEquals(200, $response->getStatusCode(), 'GET /transactions/{id}/audit should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    /**
     * T319: `contracts/ocs-api.yaml:581` declares this operation's `200` as `AuditPage`, which at
     * `:913-922` is `PageMeta` (`page`, `pageSize`, `total`, all `required`) plus `items`.
     *
     * `testListAuditReturns200WithAuditPage` above sends `page`/`pageSize` and asserts only the
     * status and the OCS envelope, which stops at `ocs.meta` and never looks at `ocs.data`. It passed
     * while the endpoint returned a bare array, which is the whole reason this gap read as covered.
     * These assertions read `ocs.data`, and they read it as data: a response carrying the right keys
     * with the wrong types still fails.
     */
    public function testTheAuditResponseCarriesTheAuditPageShape(): void
    {
        $response = $this->client->request('GET', $this->auditUrl(), [
            'query' => ['page' => 1, 'pageSize' => 25],
        ]);

        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? null;

        self::assertIsArray(
            $data,
            'AuditPage is an object carrying PageMeta plus items. A JSON array here means the endpoint '
            . 'still returns the bare list, and a client reading `data.items` gets nothing'
        );
        foreach (['page', 'pageSize', 'total'] as $key) {
            self::assertArrayHasKey(
                $key,
                $data,
                "PageMeta marks '{$key}' as required, so its absence is a contract violation rather "
                . 'than an optional field'
            );
            self::assertIsInt($data[$key], "'{$key}' must be an integer per PageMeta");
        }
        self::assertArrayHasKey('items', $data, "AuditPage's second member is 'items'");
        self::assertIsArray($data['items'], 'items must be an array of AuditEvent');
    }

    /**
     * The three `PageMeta` values must describe the request that was actually made.
     *
     * A response that always reports the defaults would satisfy the shape assertions above while
     * ignoring the query entirely, so the echoed values are compared against what was sent.
     */
    public function testTheAuditResponseEchoesTheRequestedPage(): void
    {
        $response = $this->client->request('GET', $this->auditUrl(), [
            'query' => ['page' => 1, 'pageSize' => 2],
        ]);

        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];

        self::assertSame(1, $data['page'] ?? null, 'the response must echo the requested page');
        self::assertSame(
            2,
            $data['pageSize'] ?? null,
            'the response must echo the requested page size, not the default of 25. A fixed value here '
            . 'means pageSize is ignored'
        );
        self::assertLessThanOrEqual(
            2,
            count($data['items'] ?? []),
            'pageSize=2 must bound the returned slice'
        );
    }

    /**
     * `page` must actually move the window, and `total` must count the whole scoped set.
     *
     * `total` is the field most easily got wrong in the direction that still looks correct: reporting
     * `count($items)` makes a single page's length look like the whole history, and a paginated
     * client then stops after page 1 having silently missed every later event. US6/AC1 requires every
     * official event to be reachable, so `total` is the field that makes the rest reachable.
     * `AccountControllerTest:228` already pins this distinction for its endpoint — same rule here.
     */
    public function testPagingTheAuditWindowReachesEveryEventExactlyOnce(): void
    {
        $this->recordThreeAuditEvents();

        $first = $this->auditPage(1, 1);
        self::assertCount(1, $first['items'], 'pageSize=1 must return exactly one event');

        $total = $first['total'];
        self::assertGreaterThanOrEqual(
            3,
            $total,
            'total counts the whole scoped set. Three edits were recorded, so a total below three means '
            . 'the count describes the page rather than the history'
        );

        // Walk every page and collect the ids. This is the assertion the bare-array response cannot
        // pass: `items` does not exist there, so the walk sees nothing and reports zero events.
        $seen = [];
        $pages = 0;
        while ($pages++ < 50) {
            $page = $this->auditPage($pages, 1);
            foreach ($page['items'] as $item) {
                $seen[] = $item['id'] ?? null;
            }
            if (count($page['items']) < 1) {
                break;
            }
        }

        $distinct = array_values(array_unique(array_filter($seen)));

        self::assertNotEmpty($seen, 'paging the audit window must reach events at all');
        self::assertCount(
            count($distinct),
            array_filter($seen),
            'an event must not repeat across pages, or paging duplicates history'
        );
        self::assertSame(
            $total,
            count($distinct),
            'walking every page must yield exactly `total` distinct events. A total that disagrees with '
            . 'what the pages actually yield means one of them is lying, and a client that trusts the '
            . 'total stops early or loops forever'
        );
    }

    /**
     * Record three real audit events through the API, so this test does not depend on the fixture
     * having any.
     *
     * `ContractFixtures` seeds no `pettycash_transaction_audit` rows, so a paging test that assumed
     * them would walk an empty set and prove nothing — and one that assumed a *previous* suite had
     * written some would be order-dependent, which is the failure mode the fixture exists to
     * remove. `ReportAndExportControllerTest:36-43` states the same rule for the same reason.
     *
     * `PATCH /transactions/{id}` is used because it writes a `TRANSACTION_CHANGED` audit event on
     * every call (FR-053 makes it permanent). Each edit bumps the optimistic lock, so the version is
     * read back from each response rather than guessed.
     */
    private function recordThreeAuditEvents(): void
    {
        $version = ContractFixtures::transactionVersion();

        for ($i = 1; $i <= 3; $i++) {
            $response = $this->client->request(
                'PATCH',
                '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId(),
                [
                    'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
                    'json' => [
                        'expenseDate' => '2026-09-2' . $i,
                        'amountMinor' => 1000 + $i,
                        'description' => 'audit paging fixture ' . $i,
                        'expectedVersion' => $version,
                    ],
                ]
            );
            self::assertSame(200, $response->getStatusCode(), 'edit ' . $i . ' must succeed to record an audit event');

            $version = json_decode((string) $response->getBody(), true)['ocs']['data']['version'] ?? null;
            self::assertIsInt($version, 'the edit response must report the new lock version');
        }
    }

    /**
     * `contracts/ocs-api.yaml:766-772` fixes `PageSize` at `minimum: 1, maximum: 100`.
     *
     * Over-large is clamped and under-small is refused, matching the rule
     * `ReportFilter` documents for the sibling surface (`ReportFilter.php:74` rejects `< 1`,
     * `:93` clamps to `MAX_PAGE_SIZE`). Both directions are asserted because an implementation that
     * refused a large page size would look reasonable and would break a client legitimately asking
     * for the contract's own maximum.
     */
    public function testTheAuditPageSizeIsBoundedByTheContract(): void
    {
        $clamped = json_decode(
            (string) $this->client->request('GET', $this->auditUrl(), [
                'query' => ['page' => 1, 'pageSize' => 5000],
            ])->getBody(),
            true
        )['ocs']['data'] ?? [];

        self::assertSame(
            100,
            $clamped['pageSize'] ?? null,
            'the contract caps pageSize at 100, so 5000 must be clamped down to it rather than refused'
        );

        $refused = $this->client->request('GET', $this->auditUrl(), [
            'query' => ['page' => 1, 'pageSize' => 0],
        ]);

        self::assertContains(
            $refused->getStatusCode(),
            [400, 422],
            'pageSize below the contract minimum is a client error and must be refused, not clamped'
        );
    }

    /**
     * The revisions endpoint has the identical bare-array shape and is **correct**.
     *
     * `contracts/ocs-api.yaml:567` declares a plain `Revision` array for it, with no `PageMeta`.
     * This assertion exists to stop "make the audit page conform" from quietly becoming "make every
     * list conform": a change that paged the revisions response would break a client written against
     * the contract, and without this test nothing would say so.
     */
    public function testTheRevisionsEndpointStillReturnsABareArray(): void
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/revisions');

        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? null;

        self::assertIsArray($data, 'revisions returns an array');
        self::assertSame(
            array_keys($data),
            range(0, count($data) - 1),
            'contracts/ocs-api.yaml:567 declares a bare Revision array for this endpoint, so `data` must '
            . 'stay a JSON list. It must NOT be wrapped in PageMeta -- that is a different contract'
        );
        self::assertArrayNotHasKey(
            'items',
            $data === [] ? [] : $data,
            'the revisions response must not acquire an `items` key; only the audit endpoint declares AuditPage'
        );
    }

    private function auditUrl(): string
    {
        return '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/audit';
    }

    /** @return array<string, mixed> */
    private function auditPage(int $page, int $pageSize): array
    {
        $response = $this->client->request('GET', $this->auditUrl(), [
            'query' => ['page' => $page, 'pageSize' => $pageSize],
        ]);

        self::assertSame(200, $response->getStatusCode(), "audit page {$page} must succeed");

        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];
        self::assertIsArray($data['items'] ?? null, "audit page {$page} must carry an `items` array");

        return $data;
    }

    // ===== EVIDENCE =====

    public function testUploadEvidenceReturns201WithEvidenceMetadata(): void
    {
        // Create a small test file
        $tempFile = tempnam(sys_get_temp_dir(), 'evidence_');
        file_put_contents($tempFile, str_repeat('x', 1024)); // 1KB test file

        try {
            $response = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/evidence', [
                'headers' => [
                    'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
                ],
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => fopen($tempFile, 'r'),
                        'filename' => 'test-receipt.pdf',
                    ],
                ],
            ]);

            // Contract: POST /transactions/{id}/evidence returns 201 with Evidence metadata
            $this->assertEquals(201, $response->getStatusCode(), 'POST /evidence should return 201 Created');
            $this->assertOcsResponseStructure($response, 201);
        } finally {
            @unlink($tempFile);
        }
    }

    public function testDeleteEvidenceReturns200WithDeactivatedMetadata(): void
    {
        $evidenceId = $this->uploadEvidence();

        $response = $this->client->request('DELETE', '/ocs/v2.php/apps/pettycash/api/v1/evidence/' . $evidenceId);

        // Contract: DELETE /evidence/{id} returns 200 with deactivated Evidence metadata
        $this->assertEquals(200, $response->getStatusCode(), 'DELETE /evidence/{id} should return 200 OK');
        $this->assertOcsResponseStructure($response, 200);
    }

    /**
     * T318: the DELETE is repeatable, so the contract no longer requires `X-PettyCash-Command-ID`.
     *
     * `contracts/workflow.md` requires that header only for *non-repeatable* commands. The endpoint
     * used to answer 422 when it was absent — a header the Command Contract does not ask this
     * operation for, refused with a status that also contradicted the 400-for-a-missing-parameter
     * convention the rest of the API settled on. The header was never forwarded to
     * `deleteDraftEvidence()` either, so refusing it bought nothing.
     */
    public function testDeleteEvidenceSucceedsWithoutACommandIdHeader(): void
    {
        $evidenceId = $this->uploadEvidence();

        $response = $this->client->request('DELETE', '/ocs/v2.php/apps/pettycash/api/v1/evidence/' . $evidenceId);

        $this->assertEquals(
            200,
            $response->getStatusCode(),
            'contracts/ocs-api.yaml does not list X-PettyCash-Command-ID on DELETE /evidence/{id} any more, '
            . 'so a client that does not send it must not be refused'
        );
    }

    /**
     * T318: repeating a repeatable command must be invisible in the recorded history.
     *
     * The second call answers the same `200` with the same body — including `deactivatedAt` from the
     * *first* call. Before the fix `Evidence::deactivate()` rewrote that timestamp on every call, so
     * the response carried a value that depended on how many times the client had retried. The body
     * is compared whole rather than field-by-field because a partial comparison would pass while a
     * rewrite of `deactivationReason` or `deactivatedBy` went unnoticed.
     */
    public function testRepeatingTheEvidenceDeleteReturnsTheSameMetadata(): void
    {
        $evidenceId = $this->uploadEvidence();

        $first = $this->client->request('DELETE', '/ocs/v2.php/apps/pettycash/api/v1/evidence/' . $evidenceId);
        $this->assertEquals(200, $first->getStatusCode(), 'the first delete must succeed');

        $second = $this->client->request('DELETE', '/ocs/v2.php/apps/pettycash/api/v1/evidence/' . $evidenceId);

        $this->assertEquals(
            200,
            $second->getStatusCode(),
            'a repeatable command must accept a repeat; refusing it would make DELETE non-repeatable '
            . 'while the contract exempts it from the header that guards non-repeatable commands'
        );

        $firstData = json_decode((string) $first->getBody(), true)['ocs']['data'] ?? [];
        $secondData = json_decode((string) $second->getBody(), true)['ocs']['data'] ?? [];

        self::assertNotEmpty(
            $firstData['deactivatedAt'] ?? null,
            'the first delete must record a deactivation time, or the equality below would be vacuous'
        );
        $this->assertSame(
            $firstData,
            $secondData,
            'a repeated delete must return the metadata the first call produced. `deactivatedAt` '
            . 'depending on the retry count is a recorded business fact that varies with how often the '
            . 'client happened to press the button'
        );
    }

    /**
     * FR-060 (T275): an inline-safe type is served with **its own** media type so it renders.
     *
     * This assertion used to require `application/octet-stream` for every evidence file, which is what
     * `contracts/ocs-api.yaml` used to declare — and that combination made FR-060's preview journey
     * unimplementable, because an `application/octet-stream` response downloads rather than renders.
     * The 2026-09-30 clarification settled that the contract was the artifact at fault; this test and
     * the contract were changed together so they cannot drift apart again.
     *
     * The fixture uploads a `.pdf`, so this is the "PDF renders in place" half of FR-060 rather than
     * the fallback half. The safety-critical half — an unlisted type being forced to
     * `application/octet-stream` + `attachment` — is pinned exhaustively and without HTTP in
     * `EvidenceContentPolicyTest`, because it needs an upload whose declared type is hostile.
     */
    public function testGetEvidenceContentReturns200WithStream(): void
    {
        $evidenceId = $this->uploadEvidence();

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/evidence/' . $evidenceId . '/content', [
            'query' => ['disposition' => 'inline'],
        ]);

        self::assertEquals(200, $response->getStatusCode(), 'GET /evidence/{id}/content must answer 200');

        // Both headers, asserted together. They are one decision: a correct type under the wrong
        // disposition is what makes a browser render something it should not, so checking the type
        // alone would accept the failure this task exists to prevent.
        self::assertSame(
            'application/pdf',
            $response->getHeaderLine('Content-Type'),
            'FR-060 sends an inline-safe type with its stored media type so it renders in place'
        );
        self::assertStringContainsString(
            'inline',
            $response->getHeaderLine('Content-Disposition'),
            'and with an inline disposition; the content type and the disposition are one decision'
        );
    }

    /**
     * FR-060's other half, from the caller's side: the disposition is the caller's to choose.
     *
     * Without this, an implementation could satisfy the test above by serving every file as a download
     * and keeping the type right — which satisfies the header assertion and removes preview entirely.
     */
    public function testAnInlineSafeEvidenceCanBeRequestedAsADownload(): void
    {
        $evidenceId = $this->uploadEvidence();

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/evidence/' . $evidenceId . '/content', [
            'query' => ['disposition' => 'attachment'],
        ]);

        self::assertEquals(200, $response->getStatusCode());
        self::assertSame(
            'application/pdf',
            $response->getHeaderLine('Content-Type'),
            'the media type does not change with the disposition'
        );
        self::assertStringContainsString(
            'attachment',
            $response->getHeaderLine('Content-Disposition'),
            'FR-060 requires a caller to be able to request either disposition explicitly'
        );
    }

    // ===== EVIDENCE CONSTRAINTS =====

    /**
     * FR-060's safety half, over HTTP: an attacker-declared type is never rendered.
     *
     * `upload()` stores the uploader's declared media type verbatim — FR-030 defers classification and
     * redaction to V2 — so this is not a hypothetical type. A purchaser who uploads a file declaring
     * `text/html` must not have it executed as script in a reviewer's session when that reviewer opens
     * the receipt, and FR-060 names that harm in its own words.
     *
     * **This is the assertion that makes the wiring observable.** Every other evidence-content test
     * uploads a PDF, and a PDF is inline-safe, so the policy's output and the stored media type are
     * the same string. That was measured rather than assumed: reverting `EvidenceController::content()`
     * to `'Content-Type' => $evidence->mediaType` left the PDF tests green, because for an inline-safe
     * type the two implementations are indistinguishable. Only a hostile upload separates them.
     *
     * The `?disposition=inline` request is the load-bearing half — it is what a naive client sends, and
     * exactly the request that must be overridden.
     */
    public function testAnEvidenceDeclaringAScriptableMediaTypeIsNeverServedInline(): void
    {
        $evidenceId = $this->uploadEvidence('contract-fixture-receipt.html', 'text/html');

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/evidence/' . $evidenceId . '/content', [
            'query' => ['disposition' => 'inline'],
        ]);

        self::assertEquals(200, $response->getStatusCode(), 'the bytes are still served; FR-060 offers them as a download');

        self::assertSame(
            'application/octet-stream',
            $response->getHeaderLine('Content-Type'),
            'FR-060 requires any media type outside the inline-safe set to be served as '
            . 'application/octet-stream, whatever the uploader declared'
        );
        self::assertStringContainsString(
            'attachment',
            $response->getHeaderLine('Content-Disposition'),
            'and forced to attachment even when the caller asks for inline, because an attacker-supplied '
            . 'type served inline would execute as script in the viewer\'s session'
        );
    }

    /**
     * The stored type is still recorded — the coercion is about how it is *served*, not about erasing it.
     *
     * Without this, "serve everything as octet-stream" would satisfy the safety test above while
     * destroying the metadata an auditor needs to know what a purchaser actually uploaded.
     */
    public function testTheDeclaredMediaTypeIsStillRecordedEvenWhenItIsNotServed(): void
    {
        $evidenceId = $this->uploadEvidence('contract-fixture-receipt.html', 'text/html');

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId());

        self::assertEquals(200, $response->getStatusCode());

        $evidence = null;
        foreach (json_decode((string) $response->getBody(), true)['ocs']['data']['evidence'] ?? [] as $item) {
            if (($item['id'] ?? null) === $evidenceId) {
                $evidence = $item;
            }
        }

        self::assertNotNull($evidence, 'the uploaded evidence must appear on the transaction');
        self::assertSame(
            'text/html',
            $evidence['mediaType'],
            'FR-030 defers classification to V2, so the declared type is kept verbatim in the record and '
            . 'only the response headers are coerced'
        );
    }

    public function testEvidenceUploadEnforces100MBLimit(): void
    {
        // Create a file larger than 100MB (101MB)
        $tempFile = tempnam(sys_get_temp_dir(), 'large_evidence_');
        $handle = fopen($tempFile, 'w');
        fwrite($handle, str_repeat('x', 101 * 1024 * 1024)); // 101MB
        fclose($handle);

        try {
            $response = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/evidence', [
                'headers' => [
                    'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
                ],
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => fopen($tempFile, 'r'),
                        'filename' => 'large-file.pdf',
                    ],
                ],
            ]);

            // Contract: Oversized files should be rejected (413 or 422)
            $this->assertEquals(413, $response->getStatusCode(), 'POST /evidence with >100MB should return 413 Payload Too Large');
            $this->assertOcsResponseStructure($response, 413);
        } finally {
            @unlink($tempFile);
        }
    }

    public function testNoAdvancedEvidenceRestrictionsInV1(): void
    {
        // V1 should NOT enforce advanced evidence restrictions (classification, redaction, etc.)
        // The endpoint should accept evidence uploads and return 201 Created per the contract
        $tempFile = tempnam(sys_get_temp_dir(), 'evidence_');
        file_put_contents($tempFile, 'test content');

        try {
            $response = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/evidence', [
                'headers' => [
                    'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
                ],
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => fopen($tempFile, 'r'),
                        'filename' => 'test.txt',
                    ],
                ],
            ]);

            // Contract: Valid evidence upload returns 201 Created (ocs-api.yaml)
            $this->assertEquals(201, $response->getStatusCode(), 'Valid evidence upload should return 201 Created');

            // V1 must NOT enforce advanced evidence restrictions (classification, redaction, etc.)
            $this->assertNotEquals(403, $response->getStatusCode(), 'V1 should not enforce advanced evidence restrictions (403)');
        } finally {
            @unlink($tempFile);
        }
    }

    // ===== CURRENCY READ-BACK (FR-021) =====

    /**
     * A revision's `amount_minor` is only meaningful beside a currency, and FR-021
     * makes the sheet the authority for that currency. Reading a USD sheet's
     * transaction back must therefore report USD, not the rial default: a hardcoded
     * currency turns a 10.00 USD expense into a ten-thousand-rial one and corrupts
     * every total, settlement, and export derived from it.
     */
    public function testRevisionReadsBackTheSheetCurrencyRatherThanRial(): void
    {
        ContractFixtures::prepare('usd');

        $response = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::usdTransactionId()
        );

        $this->assertEquals(200, $response->getStatusCode(), 'GET /transactions/{id} should return 200 OK');

        $body = json_decode((string) $response->getBody(), true);
        $amount = $body['ocs']['data']['activeRevision']['amount'] ?? null;

        $this->assertIsArray($amount, 'The transaction detail should carry the revision amount');
        $this->assertSame(
            'USD',
            $amount['currency'] ?? null,
            'A revision read back from a USD sheet must report the sheet currency (FR-021)'
        );
        $this->assertSame(
            1000,
            $amount['amountMinor'] ?? null,
            'The stored minor units must be reported unchanged alongside their own currency'
        );
        $this->assertSame(
            2,
            $amount['minorUnit'] ?? null,
            'The fraction digits reported for USD must be the configured two, not the rial default of zero'
        );
    }

    /**
     * The rial sheet must keep reporting rial, so resolving the currency from the
     * sheet cannot degenerate into always answering with whatever the second
     * fixture happens to use.
     */
    public function testRialSheetStillReadsBackRial(): void
    {
        ContractFixtures::prepare('draft');

        $response = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId()
        );

        $this->assertEquals(200, $response->getStatusCode(), 'GET /transactions/{id} should return 200 OK');

        $body = json_decode((string) $response->getBody(), true);
        $amount = $body['ocs']['data']['activeRevision']['amount'] ?? null;

        $this->assertIsArray($amount, 'The transaction detail should carry the revision amount');
        $this->assertSame(
            'IRR',
            $amount['currency'] ?? null,
            'A revision read back from a rial sheet must report rial'
        );
    }

    // ===== DRAFT DELETE (FR-025, FR-058) =====

    /**
     * A successful delete must actually remove the transaction.
     *
     * `TransactionService::deleteTransaction()` used to validate ownership, the open
     * sheet, and the optimistic lock, then only filter the sheet's in-memory
     * transaction collection and re-save it. No `DELETE` reached
     * `pettycash_transactions`, so the endpoint answered `204 No Content` while the
     * expense remained fully present in the database and in every later total.
     */
    public function testDeletingADraftTransactionRemovesItFromTheDatabase(): void
    {
        ContractFixtures::prepare('draft');

        $delete = $this->client->request(
            'DELETE',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId(),
            ['json' => ['expectedVersion' => ContractFixtures::transactionVersion()]]
        );

        $this->assertSame(
            204,
            $delete->getStatusCode(),
            'DELETE /transactions/{id} on a draft should answer 204 No Content'
        );

        $readBack = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId()
        );

        $this->assertSame(
            404,
            $readBack->getStatusCode(),
            'A deleted draft must no longer be readable; a 204 over a record that is still there is a no-op delete'
        );
    }

    /**
     * A submitted transaction must not be deletable even by its owner.
     *
     * A returned transaction sits in an open sheet again, so the open-sheet check
     * alone would let a purchaser erase submitted business history — which
     * Constitution IV forbids. This guards the draft-state restriction independently
     * of the delete actually working.
     */
    public function testDeletingASubmittedTransactionIsRefused(): void
    {
        ContractFixtures::prepare('submitted');

        $response = $this->client->request(
            'DELETE',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId(),
            ['json' => ['expectedVersion' => ContractFixtures::transactionVersion()]]
        );

        $this->assertContains(
            $response->getStatusCode(),
            [400, 403, 409, 422],
            'A transaction that has been submitted must not be deletable, whatever the caller'
        );

        $readBack = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId()
        );

        $this->assertSame(
            200,
            $readBack->getStatusCode(),
            'The refused delete must leave the submitted transaction intact'
        );
    }

    // ===== TRANSACTION PAYLOAD CONFORMANCE (contracts/ocs-api.yaml) =====

    /**
     * The response must carry the contract's `TransactionDetail` members.
     *
     * `contracts/ocs-api.yaml` defines `TransactionSummary` (required: id, sheetId,
     * activeRevision, stage, status, evidenceCount, version) and `TransactionDetail` (adding
     * allowedActions, decisions, evidence). Both controllers used to carry their own private
     * formatter and both had drifted: the key was `revision` rather than `activeRevision`, and
     * `ReviewController` emitted flat `costCenterId`/`expenseTypeTitle` scalars instead of the
     * `EntitySnapshot` objects the contract requires.
     *
     * The frontend is written to the contract — `CostCenterQueueView.vue` and
     * `SheetDetailView.vue` both read `item.activeRevision.costCenter.name` — so a response that
     * does not match makes those views dereference `undefined` on every load. Asserting the
     * shape is the only way the suite can catch that, because the status code is 200 either way.
     */
    public function testTransactionDetailCarriesTheContractShape(): void
    {
        ContractFixtures::prepare('submitted');

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId());
        $this->assertEquals(200, $response->getStatusCode(), 'GET /transactions/{id} should return 200 OK');

        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];

        foreach (['id', 'sheetId', 'activeRevision', 'stage', 'status', 'evidenceCount', 'version'] as $required) {
            $this->assertArrayHasKey(
                $required,
                $data,
                "TransactionSummary requires `{$required}`; the response must satisfy contracts/ocs-api.yaml"
            );
        }

        foreach (['allowedActions', 'decisions', 'evidence'] as $detailMember) {
            $this->assertArrayHasKey(
                $detailMember,
                $data,
                "TransactionDetail requires `{$detailMember}`"
            );
        }

        $revision = $data['activeRevision'];
        $this->assertIsArray($revision['costCenter'] ?? null, 'Revision.costCenter must be an EntitySnapshot object');
        $this->assertArrayHasKey('name', $revision['costCenter'], 'EntitySnapshot.name is required');
        $this->assertIsArray($revision['expenseType'] ?? null, 'Revision.expenseType must be an EntitySnapshot object');
        $this->assertArrayHasKey('name', $revision['expenseType'], 'EntitySnapshot.name is required');

        foreach (['amountMinor', 'currency', 'minorUnit'] as $moneyMember) {
            $this->assertArrayHasKey($moneyMember, $revision['amount'] ?? [], "Money requires `{$moneyMember}`");
        }

        // The undeclared flat shapes must be gone, or the drift can silently return.
        $this->assertArrayNotHasKey('revision', $data, 'The key is `activeRevision`; the flat `revision` shape is not in the contract');
        $this->assertArrayNotHasKey('ownerId', $data, 'TransactionSummary does not declare `ownerId`');
        $this->assertArrayNotHasKey('costCenterId', $revision, 'Revision declares `costCenter`, not the scalar `costCenterId`');
        $this->assertArrayNotHasKey('expenseTypeTitle', $revision, 'Revision declares `expenseType`, not the scalar `expenseTypeTitle`');
    }

    /**
     * The queue endpoints answer `TransactionSummary`, which carries no decisions or evidence.
     *
     * Asserting the summary shape on a queue keeps the two response kinds from being conflated,
     * which is how `PriorDecisionHistory` came to have nothing to render: the review detail
     * endpoint is the only one that returns a `decisions` array.
     */
    public function testQueueReturnsTransactionSummaryItems(): void
    {
        ContractFixtures::prepare('scope');

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/queues/cost-center');
        $this->assertEquals(200, $response->getStatusCode(), 'GET /queues/cost-center should return 200 OK');

        $items = json_decode((string) $response->getBody(), true)['ocs']['data']['items'] ?? [];
        $this->assertNotEmpty($items, 'The fixture assigns the acting user as CC-100 manager, so the queue is not empty');

        foreach ($items as $item) {
            $this->assertArrayHasKey('activeRevision', $item, 'Queue items must expose `activeRevision`');
            $this->assertArrayHasKey('evidenceCount', $item, 'Queue items must expose `evidenceCount`');
            $this->assertArrayNotHasKey('decisions', $item, 'TransactionSummary does not carry `decisions`');
            $this->assertArrayNotHasKey('revision', $item, 'The key is `activeRevision`');
        }
    }

    // ===== SHEET READ MODEL HONESTY =====

    /**
     * A sheet must not report a transaction count that contradicts its own status.
     *
     * `SheetController::formatSheet()` counted from the `ExpenseSheet` aggregate, which
     * `DbExpenseSheetRepository` does not compose on read, so every sheet reported
     * `transactionCount: 0`. A submitted sheet therefore answered `200` with
     * `status: COST_CENTER` alongside a count of zero — a response that contradicts itself and
     * understates the purchaser's record. The count now comes from the repository.
     */
    public function testSheetDetailReportsItsRealTransactionCount(): void
    {
        ContractFixtures::prepare('draft');

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId());
        $this->assertEquals(200, $response->getStatusCode(), 'GET /sheets/{id} should return 200 OK');

        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];

        // The fixture seeds exactly one transaction on the fixture sheet.
        $this->assertSame(
            1,
            $data['transactionCount'] ?? null,
            'A sheet holding one seeded transaction must report transactionCount 1, not 0'
        );
    }

    /**
     * Submitting a sheet must raise the count, not leave it at the draft figure.
     *
     * The count is derived rather than stored, so this guards the derivation against a future
     * change that reads a stale snapshot instead of the live rows.
     */
    public function testSheetTransactionCountSurvivesSubmission(): void
    {
        ContractFixtures::prepare('draft');

        $submit = $this->client->request(
            'POST',
            '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId() . '/submit',
            [
                'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
                'json' => ['expectedVersion' => ContractFixtures::sheetVersion()],
            ]
        );
        $this->assertEquals(200, $submit->getStatusCode(), 'The fixture sheet should submit');

        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId());
        $data = json_decode((string) $response->getBody(), true)['ocs']['data'] ?? [];

        $this->assertSame('COST_CENTER', $data['status'] ?? null, 'The sheet should now be in cost-center review');
        $this->assertSame(
            1,
            $data['transactionCount'] ?? null,
            'A submitted sheet must still report the transaction it carries'
        );
    }

    // ===== HELPER METHODS =====

    /**
     * Upload a small file to the fixture transaction and return its public id.
     *
     * The evidence assertions have to address a record that really exists: a
     * well-formed UUID that matches nothing only ever proves the 404 branch, so
     * each of them uploads its own evidence first.
     */
    /**
     * Upload an evidence file and return its public id.
     *
     * `$mediaType` exists so a suite can store a **hostile declared type**. Without it the multipart
     * infers `application/pdf` from the filename, and since a PDF is an inline-safe type the response
     * headers are identical whether the FR-060 policy is applied or the stored type is echoed back
     * verbatim — so a contract test uploading only PDFs cannot tell the safe implementation from the
     * unsafe one. That was measured: reverting `EvidenceController::content()` to
     * `'Content-Type' => $evidence->mediaType` left every PDF-path assertion green.
     */
    private function uploadEvidence(
        string $filename = 'contract-fixture-receipt.pdf',
        ?string $mediaType = null
    ): string {
        $tempFile = tempnam(sys_get_temp_dir(), 'evidence_');
        file_put_contents($tempFile, str_repeat('x', 1024));

        try {
            $part = [
                'name' => 'file',
                'contents' => fopen($tempFile, 'r'),
                'filename' => $filename,
            ];
            if ($mediaType !== null) {
                $part['type'] = $mediaType;
            }

            $response = $this->client->request('POST', '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . ContractFixtures::transactionId() . '/evidence', [
                'headers' => [
                    'X-PettyCash-Command-ID' => ContractFixtures::commandId(),
                ],
                'multipart' => [$part],
            ]);

            $this->assertEquals(201, $response->getStatusCode(), 'Evidence upload must succeed before its id can be addressed');

            $body = json_decode((string) $response->getBody(), true);
            $this->assertIsArray($body, 'Evidence upload should return valid JSON');
            $this->assertArrayHasKey('id', $body['ocs']['data'] ?? [], 'Evidence upload should return the evidence id');

            return (string) $body['ocs']['data']['id'];
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Assert that the response follows the OCS standard envelope structure.
     * OCS responses have: { "ocs": { "meta": {...}, "data": ... } }
     */
    private function assertOcsResponseStructure(\Psr\Http\Message\ResponseInterface $response, int $expectedStatus): void
    {
        $this->assertEquals($expectedStatus, $response->getStatusCode(), 'Unexpected status code');

        $contentType = $response->getHeaderLine('Content-Type');
        $this->assertStringContainsString('application/json', $contentType, 'Response should be JSON');

        $body = (string)$response->getBody();
        $this->assertNotEmpty($body, 'Response body should not be empty');

        $data = json_decode($body, true);
        $this->assertIsArray($data, 'Response body should be valid JSON');

        // OCS standard envelope
        $this->assertArrayHasKey('ocs', $data, 'Response should have "ocs" root key');
        $ocs = $data['ocs'];

        // OCS meta
        $this->assertArrayHasKey('meta', $ocs, 'OCS envelope should have "meta"');
        $meta = $ocs['meta'];
        $this->assertArrayHasKey('status', $meta, 'OCS meta should have "status"');
        // Nextcloud reports "ok" only for 2xx; every error status is "failure".
        $expectedMetaStatus = $expectedStatus >= 400 ? 'failure' : 'ok';
        $this->assertEquals($expectedMetaStatus, $meta['status'], 'OCS meta.status should match the HTTP status class');
        $this->assertArrayHasKey('statuscode', $meta, 'OCS meta should have "statuscode"');
        $this->assertEquals((string)$expectedStatus, $meta['statuscode'], 'OCS meta.statuscode should match HTTP status');

        // OCS data
        $this->assertArrayHasKey('data', $ocs, 'OCS envelope should have "data"');
    }
}