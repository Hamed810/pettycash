<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Application\Reporting\ReportFilterFactory;
use OCA\PettyCash\Infrastructure\Export\EvidenceArchiveExporter;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use ZipArchive;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * T112: `GET /reports/{reportType}` and `POST /exports` over the real OCS surface.
 *
 * ## The test that earns its place
 *
 * `testAConformingExportRequestAnswersJson` exists because of a defect it would have caught. The
 * contract's request field was literally named `format`, which is the parameter Nextcloud's OCS layer
 * reserves for choosing the response envelope: `Request::getFormat()` (`Request.php:903`) reads
 * `getParam('format')` *before* the `Accept` header, and `OCSMiddleware` falls back to `xml` whenever
 * that value is not a registered responder.
 *
 * So every *conforming* request answered XML while returning a correct status and a correct payload.
 * Nothing else in the app received a `format` field, which is why every other POST kept answering JSON
 * and the endpoint looked healthy. The contract field is now `fileFormat`, and this test asserts the
 * envelope is JSON so a future rename back to `format` fails here rather than in a client.
 */
final class ReportAndExportControllerTest extends TestCase
{
    /** The contract fixture's sheet, which the acting user owns. */
    private const FIXTURE_SHEET_ID = '55555555-5555-4555-8555-555555555555';

    private Client $client;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();

        // `submitted` rather than nothing. T310's audit facets need audit events to filter, and the
        // audit report is the actor's own trail -- so without seeding them, whether the stage and
        // date-range facets were answerable would depend on whether some *other* suite had run
        // first. That is precisely the order-dependence `ContractFixtures` exists to remove, and a
        // test whose result depends on PHPUnit's ordering is not evidence of anything.
        ContractFixtures::prepare('submitted');
    }

    // ===== GET /reports/{reportType} =====

    public function testAReportAnswersTheContractsPageShape(): void
    {
        $response = $this->get('/reports/sheets');

        $this->assertEquals(200, $response['status'], 'GET /reports/sheets must answer 200');
        $data = $response['data'];

        // PageMeta, as the contract's allOf declares.
        foreach (['page', 'pageSize', 'total'] as $key) {
            $this->assertArrayHasKey($key, $data);
            $this->assertIsInt($data[$key]);
        }
        foreach (['columns', 'rows', 'summary'] as $key) {
            $this->assertIsArray($data[$key], $key);
        }
        $this->assertIsArray($data['columns'], 'columns is a list of names, not of objects');

        $this->assertSame(1, $data['page'], 'the default page is 1');
        $this->assertSame(25, $data['pageSize'], 'the contract default pageSize is 25');
    }

    public function testEveryRowUsesOnlyTheColumnsTheReportDeclares(): void
    {
        $data = $this->get('/reports/sheets')['data'];

        foreach ($data['rows'] as $index => $row) {
            $this->assertIsArray($row, "row {$index} is an object");
            foreach (array_keys($row) as $key) {
                $this->assertContains(
                    $key,
                    $data['columns'],
                    "row {$index} has a key the report's own column list does not declare"
                );
            }
        }
    }

    public function testAnUndeclaredReportTypeIsRefusedWithTheContractsEnum(): void
    {
        $response = $this->get('/reports/balances');

        $this->assertEquals(422, $response['status'], 'balances is not in the contract enum');
        foreach (['sheets', 'transactions', 'cost-centers', 'accounts', 'process', 'evidence', 'audit'] as $type) {
            $this->assertStringContainsString($type, $response['message'], "the refusal should name '{$type}'");
        }
    }

    /**
     * FR-065 names balances and movements, FR-056 names configuration audit, and none is in the enum.
     * Asserted so the tempting fix -- adding them -- cannot happen quietly from a controller.
     */
    public function testSubjectsNamedByRequirementsButNotTheEnumAreNotServed(): void
    {
        foreach (['balances', 'movements', 'configuration_audit', 'configuration-audit'] as $type) {
            $this->assertEquals(422, $this->get('/reports/' . $type)['status'], "'{$type}' is not in the enum");
        }
    }

    public function testPagingIsHonoured(): void
    {
        $data = $this->get('/reports/sheets?page=2&pageSize=5')['data'];

        $this->assertSame(2, $data['page']);
        $this->assertSame(5, $data['pageSize']);
        $this->assertLessThanOrEqual(5, count($data['rows']), 'a page must not exceed its pageSize');
    }

    /**
     * FR-016 and FR-055: every finance officer shares one queue, so an officer sees the accounts
     * that have been charged, not only their own.
     *
     * This asserts the *effect*, not the label. A summary saying `own_and_charged_accounts` while
     * returning the caller's own rows would satisfy a string comparison and fail the requirement, so
     * the charged account is located in the rows themselves. The charge it depends on is written by
     * `FinanceReviewControllerTest`'s approvals against the same real database, which is also the
     * proof that T294's configuration-audit events and T299's derivation are the same fact: the
     * report can only widen because settlement recorded a charge.
     */
    public function testTheAccountsReportIncludesTheAccountsAChargeHasNamed(): void
    {
        $data = $this->get('/reports/accounts?pageSize=100')['data'];
        $summary = $data['summary'];

        $this->assertSame('own_and_charged_accounts', $summary['scope']);
        $this->assertTrue(
            $summary['chargedAccountsIncluded'],
            'a finance officer on the shared queue sees charged accounts (FR-016)'
        );

        $this->assertNotEmpty(
            $data['rows'],
            'the report must return the accounts it claims to cover'
        );

        // Every row is either the caller's own or an account some charge named. The account the
        // finance review tests settled is the concrete witness: it exists, and the report reached
        // it only through the configuration-audit stream.
        $chargedAccount = ContractFixtures::accountId();
        $ids = array_map(static fn (array $row): string => (string) $row['id'], $data['rows']);
        $this->assertContains(
            $chargedAccount,
            $ids,
            'the account debited by a settled finance approval must be in the officer\'s accounts '
            . 'report; FR-016 makes the queue shared, so the charge is an instance-wide fact. '
            . 'rows: ' . implode(', ', $ids)
        );

        $row = $data['rows'][array_search($chargedAccount, $ids, true)];
        $this->assertArrayHasKey('userUid', $row, 'a charged account is still a fully reported account');
        $this->assertArrayHasKey('balanceMinor', $row);

        // The charged account is reported with the same balance the accounts endpoint reports, so
        // widening the report did not also change what it says about the account.
        $listed = [];
        foreach ($this->get('/accounts?pageSize=100')['data']['items'] as $item) {
            $listed[(string) $item['id']] = $item['balance']['amountMinor'] ?? null;
        }
        $this->assertArrayHasKey($chargedAccount, $listed, 'the account endpoint must also list the charged account');
        $this->assertSame(
            $listed[$chargedAccount],
            $row['balanceMinor'],
            'the report and the accounts endpoint must agree on the balance of a charged account'
        );
    }

    /**
     * The charged account appears once, not twice, when it is also the caller's own.
     *
     * `ChargedAccountScope` answers instance-wide and the report merges that with the caller's own
     * accounts. An account in both sets is the ordinary case for the officer who owns it, and
     * listing it twice would double both the count and the balance.
     */
    public function testTheAccountsReportDoesNotListAnAccountTwiceWhenItIsBothOwnAndCharged(): void
    {
        $ids = array_map(
            static fn (array $row): string => (string) $row['id'],
            $this->get('/reports/accounts?pageSize=100')['data']['rows']
        );

        self::assertSame(
            array_values(array_unique($ids)),
            $ids,
            'the accounts report must not repeat an account that is both the caller\'s own and charged'
        );
    }

    /**
     * FR-065: each account row carries **its movements**, not only its balance.
     *
     * "Petty-cash balances and movements MUST be reachable as data of the `accounts` report -- one
     * account per row, carrying that account's balance and its movements." The row previously carried
     * `balanceMinor` and nothing else, so the movements were reachable only through the separate
     * `GET /accounts/{id}/movements` endpoint -- and this controller's own docblock had already claimed
     * they were reachable "through the `accounts` type's own rows", which was false.
     *
     * The movements are compared against that endpoint rather than asserted as non-empty, because the
     * two now go through one formatter: `AccountPayloadFormatter::movement()` is what the ledger
     * endpoint uses, and this is what a reader should get whichever route served them. Comparing rather
     * than counting means a formatter change that moved both together still passes, while a drift
     * between the two endpoints fails here.
     */
    public function testEachAccountsReportRowCarriesThatAccountsMovements(): void
    {
        $rows = $this->get('/reports/accounts?pageSize=100')['data']['rows'];

        self::assertNotEmpty($rows, 'the accounts report must return rows to carry movements on');

        $accountId = ContractFixtures::accountId();
        $ids = array_map(static fn (array $row): string => (string) $row['id'], $rows);
        self::assertContains(
            $accountId,
            $ids,
            'the fixture account must be reported; rows: ' . implode(', ', $ids)
        );

        $row = $rows[array_search($accountId, $ids, true)];
        self::assertArrayHasKey('movements', $row, 'FR-065 requires each row to carry its account\'s movements');
        self::assertIsArray($row['movements']);

        $ledger = $this->get('/accounts/' . $accountId . '/movements?page=1&pageSize=100')['data']['items'];

        self::assertSame(
            array_map(static fn (array $m): string => (string) $m['id'], $ledger),
            array_map(static fn (array $m): string => (string) $m['id'], $row['movements']),
            'the movements on the report row must be that account\'s, and must match what the ledger '
            . 'endpoint reports for it'
        );
    }

    /**
     * The bounded read is **stated**, not implied.
     *
     * A report that cannot promise a complete ledger must say so, and there are two ways to get this
     * wrong that both look correct: a row silently carrying fewer movements than the account has, and a
     * row silently carrying an amount that does not match the balance beside it. FR-065's wording cannot
     * be satisfied by an unbounded promise for an account that has been used for years, so the bound is
     * real and both flags are asserted — the summary states the bound, the row states whether it bit.
     */
    public function testTheAccountsReportStatesItsMovementReadBound(): void
    {
        $data = $this->get('/reports/accounts?pageSize=100')['data'];

        self::assertArrayHasKey(
            'movementReadBound',
            $data['summary'],
            'a bounded movement read must state its bound rather than leaving it to be inferred'
        );
        self::assertGreaterThan(0, $data['summary']['movementReadBound']);

        foreach ($data['rows'] as $row) {
            self::assertArrayHasKey(
                'movementsTruncated',
                $row,
                'every row must say whether its ledger is complete, or a partial ledger reads as a full one'
            );
            self::assertIsBool($row['movementsTruncated']);

            // The invariant that makes the flag meaningful rather than decorative. A balance is the
            // running total of its ledger, so on an **untruncated** row the balance must be reachable
            // from the movements beside it. On a truncated row it may not be, which is exactly why the
            // flag has to exist rather than the row simply being trusted — and why this assertion is
            // guarded on the flag instead of applied to every row.
            //
            // Stated as "the balance matches one of the row's movements" rather than as a sum, because
            // the repository's ordering is not part of any contract and a sum would pin it. An account
            // with no movements at all has a balance of 0, and that is the other way this holds.
            if ($row['movementsTruncated'] === false) {
                $movements = $row['movements'] ?? [];

                if ($movements === []) {
                    self::assertSame(
                        0,
                        $row['balanceMinor'],
                        'an account with no movements has no balance, so an empty ledger beside a '
                        . 'non-zero balance means the read silently returned nothing'
                    );
                    continue;
                }

                $balances = array_map(
                    static fn (array $m): int => (int) ($m['balanceAfter']['amountMinor'] ?? -1),
                    $movements
                );

                self::assertContains(
                    $row['balanceMinor'],
                    $balances,
                    'an untruncated row\'s balance must be the running total of the movements it carries, '
                    . 'or the row is reporting a figure its own ledger does not support'
                );
            }
        }
    }

    /**
     * A movement belonging to another account cannot reach this row.
     *
     * The read is scoped by the account's own surrogate key, so this is a property of the query rather
     * than of a filter. Asserting it is still worth doing: it is the check that would fail if the row
     * were ever assembled by querying movements without an account constraint and filtering
     * afterwards, and the two are indistinguishable on the happy path.
     */
    public function testNoAccountsReportRowCarriesAnotherAccountsMovements(): void
    {
        $rows = $this->get('/reports/accounts?pageSize=100')['data']['rows'];

        $ledgerIds = [];
        foreach ($rows as $row) {
            foreach ($row['movements'] ?? [] as $movement) {
                $ledgerIds[(string) $movement['id']] = true;
            }
        }

        // The second fixture account has its own ledger. None of its movement ids may appear on any
        // row of the caller's report unless that row *is* the second account.
        $second = $this->get('/accounts/' . ContractFixtures::secondAccountId() . '/movements?page=1&pageSize=100')['data']['items'];
        $secondRowIds = array_map(
            static fn (array $row): string => (string) $row['id'],
            array_filter($rows, static fn (array $row): bool => $row['id'] === ContractFixtures::secondAccountId())
        );

        foreach ($second as $movement) {
            if ($secondRowIds !== []) {
                continue; // the second account is itself a reported row; its own movements belong there
            }
            self::assertArrayNotHasKey(
                (string) $movement['id'],
                $ledgerIds,
                'a movement from an unreported account must not appear on any reported row'
            );
        }
    }

    public function testAMalformedFilterIsRefusedRatherThanIgnored(): void
    {
        $response = $this->get('/reports/sheets?filters=not-json');

        $this->assertEquals(422, $response['status']);
        $this->assertStringContainsString('filters', $response['message']);
    }

    // ===== T310: the FR-064 facets that had no filter and no column =====

    /**
     * FR-064 names twelve facets. Six of them could not be expressed at all before this task: the
     * filter vocabulary had no field for them, or the rows carried no column for the filter to read,
     * so each was refused. Refusing is the safe half of the behaviour and it is already tested above;
     * these assert the other half, that a facet FR-064 requires now actually narrows.
     *
     * Each test is written as "this filter must narrow, and every row it returns must satisfy it"
     * rather than "this filter must return N", because a hard-coded count is a fixture fact that
     * changes the moment another test seeds a row. The per-row assertion is what would actually fail
     * if the filter were ignored: an ignored filter returns rows that do not satisfy it.
     */
    public function testSheetsCanBeFilteredByCreator(): void
    {
        // FR-064: "a sheet's **creator** is its owner". The rows carry `ownerUid`, so this is the
        // same fact read under the name the requirement uses.
        $rows = $this->filterRows('/reports/sheets', ['createdBy' => 'employee1']);

        $this->assertNotEmpty($rows, 'the acting user owns the fixture sheet');
        foreach ($rows as $row) {
            $this->assertSame('employee1', $row['ownerUid'], 'every row must be the named creator');
        }
    }

    public function testACreatorThatOwnsNothingYieldsNothingRatherThanEverything(): void
    {
        // The failure this guards is the silent one: a filter that matches nothing must not fall
        // through to the unfiltered report.
        $this->assertSame(0, $this->filterTotal('/reports/sheets', ['createdBy' => 'no-such-user']));
    }

    public function testTransactionsCanBeFilteredByCostCentreOnTheirOwnActiveRevision(): void
    {
        // FR-064 and FR-028: a transaction's cost centre is the one on its own active revision, NOT
        // the sheet's, because one sheet may hold transactions for several cost centres. So this
        // asserts against the row's own `costCenterDbId`.
        $rows = $this->filterRows('/reports/transactions', ['costCenterDbIds' => [1]]);

        $this->assertNotEmpty($rows, 'the fixture transaction belongs to cost centre 1');
        foreach ($rows as $row) {
            $this->assertSame(1, $row['costCenterDbId'], 'every row must belong to the named cost centre');
        }
    }

    public function testTransactionsCanBeFilteredByExpenseType(): void
    {
        $rows = $this->filterRows('/reports/transactions', ['expenseTypeDbIds' => [1]]);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame(1, $row['expenseTypeDbId'], 'every row must carry the named expense type');
        }
    }

    public function testAnExpenseTypeNothingUsesYieldsNothing(): void
    {
        $this->assertSame(0, $this->filterTotal('/reports/transactions', ['expenseTypeDbIds' => [999999]]));
    }

    public function testTransactionsCanBeFilteredByAnInclusiveAmountRange(): void
    {
        // The bound is taken from the report's own rows rather than hardcoded, so the test states the
        // rule (a bound is inclusive) without pinning a fixture fact -- and without assuming every
        // row carries the same amount, which is not true and made an earlier version of this test
        // assert that a bound below one row's amount excluded rows that were legitimately smaller.
        $rows = $this->get('/reports/transactions?pageSize=100')['data']['rows'];
        $this->assertNotEmpty($rows, 'there must be a transaction to filter');

        $amounts = array_map(static fn (array $row): int => (int) $row['amountMinor'], $rows);
        $lowest = min($amounts);
        $highest = max($amounts);

        $atLowest = $this->filterRows('/reports/transactions', [
            'amountMinMinor' => $lowest,
            'amountMaxMinor' => $lowest,
        ]);
        $this->assertNotEmpty($atLowest, 'a range equal to the lowest amount must still return that row');
        foreach ($atLowest as $row) {
            $this->assertSame($lowest, $row['amountMinor'], 'a range must return only rows inside it');
        }

        // A range that excludes the lowest amount must not return it -- the narrowing is real.
        // Only meaningful when the rows actually differ; with a single distinct amount every range
        // containing it legitimately returns everything, and asserting otherwise would be asserting
        // a fixture fact rather than the rule.
        if ($lowest < $highest) {
            $aboveLowest = $this->filterRows('/reports/transactions', [
                'amountMinMinor' => $lowest + 1,
                'amountMaxMinor' => $highest,
            ]);
            foreach ($aboveLowest as $row) {
                $this->assertGreaterThan($lowest, $row['amountMinor'], 'the bound must exclude what is below it');
            }
            // The two ranges together must account for every row exactly once. A filter that was
            // ignored would return all rows from *both*, and the sum would exceed the total.
            $this->assertSame(
                count($rows),
                count($atLowest) + count($aboveLowest),
                'the two ranges must partition the rows, not each return all of them'
            );
        }
    }

    public function testAnAmountBoundOfTheWrongTypeIsRefused(): void
    {
        // An amount bound that cannot be compared is a client error, not a filter to drop.
        $response = $this->get('/reports/transactions?filters=' . rawurlencode('{"amountMinMinor":"abc"}'));

        $this->assertEquals(422, $response['status']);
        $this->assertStringContainsString('amountMinMinor', $response['message']);
    }

    public function testAnInvertedAmountRangeIsRefused(): void
    {
        $response = $this->get(
            '/reports/transactions?filters=' . rawurlencode('{"amountMinMinor":100,"amountMaxMinor":1}')
        );

        $this->assertEquals(422, $response['status']);
        $this->assertStringContainsString('amount', $response['message']);
    }

    public function testAuditCanBeFilteredByStage(): void
    {
        // FR-064: "an audit event's **stage** is the stage recorded on the event". The column was
        // already on the row; what was missing was any filter able to read it.
        $this->submitFixtureSheet();

        $rows = $this->filterRows('/reports/audit', ['stages' => ['NOT_A_REAL_STAGE']]);
        $this->assertSame([], $rows, 'a stage nothing carries must return nothing, not everything');

        $wanted = null;
        foreach ($this->get('/reports/audit?pageSize=100')['data']['rows'] as $row) {
            $wanted ??= (string) $row['stage'];
            $this->assertNotEmpty((string) $row['stage'], 'an audit row must carry the stage it was recorded at');
        }
        $this->assertNotNull($wanted, 'there must be an audit event to filter');

        $matching = $this->filterRows('/reports/audit', ['stages' => [$wanted]]);
        $this->assertNotEmpty($matching, 'a stage the events carry must return rows');
        foreach ($matching as $row) {
            $this->assertSame($wanted, $row['stage'], 'a stage filter must return only that stage');
        }
    }

    public function testAuditCanBeFilteredByDateRange(): void
    {
        // `occurredAt` was on the event and absent from the row, so a from/to filter could not be
        // evaluated and was refused. Both halves of the range are exercised, against the event's own
        // recorded timestamp so no clock assumption is baked in.
        $this->submitFixtureSheet();

        $rows = $this->get('/reports/audit?pageSize=100')['data']['rows'];
        $this->assertNotEmpty($rows, 'there must be an audit event to filter');
        // Bounds derive from the min/max of what is actually present, not from
        // rows[0] ± 1 day: the lab database accumulates events across runs and
        // days, so a bound around one row cannot exclude (or include) the rest.
        // min-1day excludes everything; max+1day includes everything.
        $times = array_map(static fn (array $row): \DateTimeImmutable => new \DateTimeImmutable($row['occurredAt']), $rows);
        $before = min($times)->modify('-1 day')->format(DATE_ATOM);
        $after = max($times)->modify('+1 day')->format(DATE_ATOM);

        $this->assertGreaterThan(
            0,
            $this->filterTotal('/reports/audit', ['from' => $before]),
            'an event after the lower bound must be returned'
        );
        $this->assertSame(
            0,
            $this->filterTotal('/reports/audit', ['to' => $before]),
            'an event after the upper bound must be excluded'
        );
        $this->assertGreaterThan(
            0,
            $this->filterTotal('/reports/audit', ['to' => $after]),
            'an event before the upper bound must be returned'
        );
    }

    /**
     * Write a transaction-audit event by doing the thing that writes one.
     *
     * `seed-contract-fixtures.php` only ever *deletes* audit rows; the events themselves are written
     * by real application actions, so an audit-report test that does not perform one sees an empty
     * report whenever it happens to run first. Rather than assert a fixed row count -- which would
     * then depend on PHPUnit's ordering, the exact hazard `ContractFixtures` documents -- the test
     * produces the event it needs.
     */
    private function submitFixtureSheet(): void
    {
        // Re-seeded as a draft first: this suite's `setUp` leaves the fixture *submitted*, and
        // submitting an already-submitted sheet is correctly refused with a 422. Re-seeding is the
        // documented way to reach a known state rather than depending on what ran before.
        ContractFixtures::prepare('draft');

        $response = $this->client->request(
            'POST',
            '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . ContractFixtures::sheetId() . '/submit',
            [
                'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
                'json' => ['expectedVersion' => ContractFixtures::sheetVersion()],
            ]
        );

        $this->assertEquals(
            200,
            $response->getStatusCode(),
            'the sheet must submit so that an audit event exists to filter'
        );
    }

    /** @param array<string, mixed> $filters */
    private function filterRows(string $path, array $filters): array
    {
        $response = $this->get($path . '?filters=' . rawurlencode(json_encode($filters)));

        $this->assertEquals(200, $response['status'], "filtering {$path} must be answerable: " . $response['message']);

        return $response['data']['rows'];
    }

    /** @param array<string, mixed> $filters */
    private function filterTotal(string $path, array $filters): int
    {
        $response = $this->get($path . '?filters=' . rawurlencode(json_encode($filters)));

        $this->assertEquals(200, $response['status'], "filtering {$path} must be answerable: " . $response['message']);

        return $response['data']['total'];
    }

    // ===== T307: a filter the report cannot answer =====

    /**
     * The regression. `ReportQuery::applyFilter()` read `currencyCode` and `actorUid` while the sheets
     * rows emit `currency` and `ownerUid`, so these filters compared an absent key, matched nothing and
     * returned an empty page with a 200. On a sheet set that is entirely IRR and owned by
     * `employee1`, filtering by either returned `total: 0` -- a confident wrong answer.
     *
     * These run against the live endpoint rather than against rows built to suit the filter, because
     * building the rows in the test is precisely how the defect survived T106: its 53 tests used rows
     * whose keys matched the filter's expectations, never the keys a producer actually emits.
     */
    public function testFilteringSheetsByCurrencyReturnsTheSheets(): void
    {
        $data = $this->get('/reports/sheets?filters=' . rawurlencode('{"currencyCodes":["IRR"]}'))['data'];

        $this->assertGreaterThan(0, $data['total'], 'a currency every sheet has must not return nothing');
    }

    public function testFilteringSheetsByActorReturnsTheSheets(): void
    {
        $data = $this->get('/reports/sheets?filters=' . rawurlencode('{"actorUids":["employee1"]}'))['data'];

        $this->assertGreaterThan(0, $data['total'], 'a filter naming the owner must not return nothing');
    }

    public function testFilteringSheetsByStatusStillNarrows(): void
    {
        $all = $this->get('/reports/sheets')['data'];
        $open = $this->get('/reports/sheets?filters=' . rawurlencode('{"statuses":["OPEN"]}'))['data'];

        $this->assertLessThanOrEqual($all['total'], $open['total']);
        foreach ($open['rows'] as $row) {
            $this->assertSame('OPEN', $row['status'], 'a status filter must only return that status');
        }
    }

    // ===== T329: the facets combine, and reach the server under one name and one vocabulary =====

    /**
     * Two facets in **one** request, which is the shape a filter bar actually produces.
     *
     * T306's coverage exercised the facets one at a time, which is a large part of why the client-side
     * encoding defect survived: nothing asserted what a request carrying more than one filter does.
     *
     * The assertion is an **exact set equality against a locally computed intersection**, and that is
     * the part carrying the weight. A server that read only the first key it recognised, or that
     * dropped the second, would return one of the single-facet sets and fail here -- provided the
     * intersection is genuinely smaller than both, which is why the test first asserts that each facet
     * on its own strictly narrows the unfiltered report. Without that, "both facets applied" and "one
     * facet applied" produce identical output and the test measures nothing.
     *
     * The status and the window are both **derived from the rows** rather than hardcoded. The report is
     * built from whatever the fixture and the preceding tests have left behind, so a fixed status name
     * or a fixed date would make this test a statement about fixture history rather than about the
     * endpoint.
     */
    public function testAStatusAndADateRangeInOneRequestReturnOnlyRowsSatisfyingBoth(): void
    {
        $all = $this->get('/reports/transactions?pageSize=100')['data']['rows'];
        $this->assertGreaterThanOrEqual(3, count($all), 'the fixture must leave enough rows for two facets to combine');

        // A status that is neither the whole report nor a single row, so the facet demonstrably narrows.
        $byStatusCount = [];
        foreach ($all as $row) {
            $byStatusCount[$row['status']] = ($byStatusCount[$row['status']] ?? 0) + 1;
        }
        $status = null;
        foreach ($byStatusCount as $candidate => $count) {
            if ($count > 1 && $count < count($all)) {
                $status = $candidate;
                break;
            }
        }
        $this->assertNotNull(
            $status,
            'no status is held by more than one row and fewer than all of them, so this test cannot '
            . 'distinguish "both facets applied" from "one facet applied"'
        );

        // The window spanning the two earliest rows. Wider than one row so the date facet excludes
        // something, and narrow enough to be a real bound rather than the whole report.
        $stamps = array_map(
            static fn (array $row): int => (new \DateTimeImmutable($row['createdAt']))->getTimestamp(),
            $all
        );
        sort($stamps);
        $this->assertGreaterThanOrEqual(
            2,
            count(array_unique($stamps)),
            'the rows must carry at least two distinct timestamps for a date range to mean anything'
        );
        $from = (new \DateTimeImmutable('@' . $stamps[0]))->format(DATE_ATOM);
        $to = (new \DateTimeImmutable('@' . $stamps[1]))->format(DATE_ATOM);

        $expectedStatus = array_values(array_filter($all, static fn (array $r): bool => $r['status'] === $status));
        $expectedWindow = array_values(array_filter($all, static function (array $r) use ($from, $to): bool {
            $at = (new \DateTimeImmutable($r['createdAt']))->getTimestamp();
            return $at >= (new \DateTimeImmutable($from))->getTimestamp()
                && $at <= (new \DateTimeImmutable($to))->getTimestamp();
        }));
        $this->assertGreaterThan(1, count($expectedWindow), 'the window must admit more than one row');
        $this->assertLessThan(count($all), count($expectedWindow), 'the window must exclude at least one row');

        $expectedBoth = array_values(array_filter(
            $expectedWindow,
            static fn (array $r): bool => $r['status'] === $status
        ));
        $this->assertNotEmpty($expectedBoth, 'the two facets must overlap, or the conjunction proves nothing');

        $byStatus = $this->filterRows('/reports/transactions', ['statuses' => [$status]]);
        $byDate = $this->filterRows('/reports/transactions', ['from' => $from, 'to' => $to]);
        $combined = $this->filterRows('/reports/transactions', ['statuses' => [$status], 'from' => $from, 'to' => $to]);

        $ids = static fn (array $rows): array => array_column($rows, 'id');
        $this->assertSame($ids($expectedStatus), $ids($byStatus), 'the status facet must return exactly that status');
        $this->assertSame($ids($expectedWindow), $ids($byDate), 'the date facet must return exactly that window');
        $this->assertSame(
            $ids($expectedBoth),
            $ids($combined),
            'one request carrying both facets must return their intersection and not either set'
        );
    }

    /**
     * The public-id spelling reaches the same rows as the surrogate-key spelling, over HTTP.
     *
     * This is the assertion that makes the id-space decision testable rather than a claim in a
     * docblock: `data-model.md` keeps numeric ids internal, so the public spelling is the one a client
     * must be able to use, and the only proof is that it narrows the live endpoint identically.
     */
    public function testACostCentreCanBeFilteredByItsPublicIdOverHttp(): void
    {
        $byPublicId = $this->filterRows('/reports/transactions', ['costCenterIds' => [ContractFixtures::costCenterId()]]);
        $byDbId = $this->filterRows('/reports/transactions', ['costCenterDbIds' => [1]]);

        $this->assertNotEmpty($byPublicId, 'the fixture transaction belongs to the fixture cost centre');
        $this->assertSame(
            array_column($byDbId, 'id'),
            array_column($byPublicId, 'id'),
            'both id spellings must select the same rows'
        );
        foreach ($byPublicId as $row) {
            $this->assertSame(1, $row['costCenterDbId']);
        }
    }

    public function testAnExpenseTypeCanBeFilteredByItsPublicIdOverHttp(): void
    {
        $byPublicId = $this->filterRows('/reports/transactions', ['expenseTypeIds' => [ContractFixtures::expenseTypeId()]]);

        $this->assertNotEmpty($byPublicId, 'the fixture transaction carries the fixture expense type');
        foreach ($byPublicId as $row) {
            $this->assertSame(1, $row['expenseTypeDbId']);
        }
    }

    /**
     * Over HTTP rather than only in the unit test, because the failure this guards is a **500**: an
     * unresolvable id has to reach the client's error surface as the 422 the vocabulary promises.
     */
    public function testACostCentrePublicIdThatNamesNothingIsRefusedOverHttp(): void
    {
        $response = $this->get(
            '/reports/transactions?filters=' . rawurlencode('{"costCenterIds":["deadbeef-1111-4111-8111-111111111111"]}')
        );

        $this->assertEquals(422, $response['status']);
        $this->assertStringContainsString('costCenterIds', $response['message']);
    }

    /**
     * A filter no row in the report can answer is refused, naming the field and what the rows do carry.
     * A 200 with `total: 0` here would be indistinguishable from a true negative.
     */
    public function testAFilterTheReportCannotAnswerIsRefusedRatherThanReturningNothing(): void
    {
        foreach (['costCenterDbIds', 'eventTypes'] as $field) {
            $response = $this->get('/reports/sheets?filters=' . rawurlencode(json_encode([$field => [1]])));

            $this->assertEquals(422, $response['status'], "`{$field}` is not a sheets field and must be refused");
            $this->assertStringContainsString($field, $response['message']);
            $this->assertStringContainsString('status', $response['message'], 'the refusal should list what the rows do carry');
        }
    }

    public function testTheAuditReportDoesAcceptAnEventTypeFilter(): void
    {
        $response = $this->get('/reports/audit?filters=' . rawurlencode('{"eventTypes":["NOT_A_REAL_EVENT"]}'));

        // 200 with a true zero, not a 422: audit rows genuinely carry `eventType`, so this filter is
        // evaluable and simply matches nothing.
        $this->assertEquals(200, $response['status']);
        $this->assertSame(0, $response['data']['total']);
    }

    // ===== T309: the filter vocabulary is closed =====

    /**
     * The regression. `filterFrom()` used to read eight filter names and discard every other key in the
     * object without comment, so a caller who misspelled a filter received the **entire report** instead
     * of the subset they asked for, with nothing in the response saying so. Measured before the fix:
     * `GET /reports/transactions` returned `total: 4` unfiltered, and the same `total: 4` for
     * `{"stages":["FINANCE"]}`, for `{"banana":true}`, and for the typo `{"costcentreIds":[1]}`.
     *
     * FR-064 now requires an unrecognised filter to be refused with a 422 naming the accepted filters.
     *
     * The example was `stages`, which T310 later made a **recognised** filter, so this now uses
     * `expenseStatus` instead. That is a real consequence of the vocabulary growing: a test that
     * picked "a key nothing accepts" has to be re-picked whenever the accepted set does, and this
     * failure is the signature of forgetting to.
     */
    public function testAnUnrecognisedFilterIsRefusedRatherThanIgnored(): void
    {
        $response = $this->get('/reports/transactions?filters=' . rawurlencode('{"expenseStatus":["FINANCE"]}'));

        $this->assertEquals(422, $response['status']);
        $this->assertStringContainsString('expenseStatus', $response['message']);
        $this->assertStringContainsString('costCenterDbIds', $response['message'], 'the refusal should list what is accepted');
    }

    /**
     * The typo is the damaging case, because `costcentreIds` is a misspelling of a filter that *is*
     * accepted: ignoring it returns every transaction in the caller's scope as though the cost centre had
     * been chosen.
     */
    public function testAMisspelledFilterIsRefused(): void
    {
        $response = $this->get('/reports/transactions?filters=' . rawurlencode('{"costcentreIds":[1]}'));

        $this->assertEquals(422, $response['status']);
        $this->assertStringContainsString('costcentreIds', $response['message']);
    }

    public function testANonsenseFilterIsRefused(): void
    {
        $this->assertEquals(
            422,
            $this->get('/reports/transactions?filters=' . rawurlencode('{"banana":true}'))['status']
        );
    }

    public function testAFilterArrayIsRefusedRatherThanTreatedAsNoFilter(): void
    {
        // A JSON array is not a filter object. `is_array()` alone would have accepted it and then read
        // nothing from it -- silently unfiltered again, by a different route.
        $this->assertEquals(
            422,
            $this->get('/reports/transactions?filters=' . rawurlencode('["statuses"]'))['status']
        );
    }

    public function testEveryAcceptedFilterStillWorks(): void
    {
        $all = $this->get('/reports/transactions')['data']['total'];
        $statuses = $this->get('/reports/transactions?filters=' . rawurlencode('{"statuses":["DRAFT"]}'))['data'];
        $actors = $this->get('/reports/transactions?filters=' . rawurlencode('{"actorUids":["employee1"]}'))['data'];

        // Refusing unknown keys must not have broken the ones that work.
        $this->assertLessThanOrEqual($all, $statuses['total']);
        foreach ($statuses['rows'] as $row) {
            $this->assertSame('DRAFT', $row['status']);
        }
        foreach ($actors['rows'] as $row) {
            $this->assertSame('employee1', $row['ownerUid']);
        }
    }

    // ===== POST /exports =====

    public function testAConformingExportRequestAnswersJson(): void
    {
        $response = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            // Selects, so the request is servable and this test measures the envelope. The selection
            // behaviour itself is T302's subject and has its own tests below.
            'ids' => [self::FIXTURE_SHEET_ID],
        ], $this->commandId());

        $this->assertEquals(200, $response['status'], json_encode($response['data']));
        // The regression this file is for: with a field named `format`, this envelope was XML.
        $this->assertSame('json', $response['envelope'], 'POST /exports must answer an OCS JSON envelope');
    }

    public function testACsvExportAnswersTheContractsResultShape(): void
    {
        $data = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            // Added by T302: an export with no selection is refused, because an empty
            // artifact answers "you have no sheets" when it should say "I was not asked
            // which sheets".
            'ids' => [self::FIXTURE_SHEET_ID],
        ], $this->commandId())['data'];

        foreach (['artifactName', 'mediaType', 'format', 'byteSize', 'rowCount', 'data'] as $key) {
            $this->assertArrayHasKey($key, $data, "ExportResult requires '{$key}'");
        }

        $this->assertSame('csv', $data['format']);
        $this->assertStringStartsWith('text/csv', $data['mediaType'], 'mediaType names the artifact, not the envelope');
        $this->assertIsInt($data['byteSize']);
        $this->assertIsInt($data['rowCount']);

        $bytes = base64_decode((string) $data['data'], true);
        $this->assertIsString($bytes, 'data is base64-encoded artifact bytes');
        $this->assertSame(strlen($bytes), $data['byteSize'], 'byteSize must describe the decoded bytes');
    }

    public function testAnExportRemovesItsArtifactOnceStreamed(): void
    {
        $data = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            // Added by T302: an export with no selection is refused, because an empty
            // artifact answers "you have no sheets" when it should say "I was not asked
            // which sheets".
            'ids' => [self::FIXTURE_SHEET_ID],
        ], $this->commandId())['data'];

        // "Generated artifacts ... are removed after streaming." Asserted, not assumed: a controller
        // that streamed and forgot would leave every export in transient storage.
        $this->assertTrue($data['artifactRemoved']);
    }

    public function testAnExportWithoutTheRequiredCommandIdIsRefused(): void
    {
        $response = $this->post('/exports', ['resource' => 'sheets', 'fileFormat' => 'csv'], null);

        $this->assertEquals(422, $response['status'], 'the contract declares CommandId required: true');
        $this->assertArrayHasKey('commandId', $response['data']['error']['fieldErrors']);
    }

    public function testAnExportWithoutResourceOrFileFormatIsRefused(): void
    {
        $response = $this->post('/exports', [], $this->commandId());

        $this->assertEquals(422, $response['status']);
        $errors = $response['data']['error']['fieldErrors'] ?? [];
        $this->assertArrayHasKey('resource', $errors);
        // Under the contract's current name; a client told to send `format` would send nothing valid.
        $this->assertArrayHasKey('fileFormat', $errors);
    }

    public function testAnUndeclaredExportFormatIsRefused(): void
    {
        $response = $this->post('/exports', ['resource' => 'sheets', 'fileFormat' => 'docx'], $this->commandId());

        $this->assertEquals(422, $response['status']);
    }

    /**
     * PDF is generated with the embedded subset font (T109): the artifact must be
     * a real PDF carrying the selected sheet, like the CSV twin below.
     */
    public function testPdfExportGeneratesADocument(): void
    {
        $response = $this->post('/exports', ['resource' => 'sheets', 'fileFormat' => 'pdf', 'ids' => [self::FIXTURE_SHEET_ID]], $this->commandId());

        $this->assertEquals(200, $response['status'], json_encode($response['data']));
        $this->assertSame(1, $response['data']['rowCount'], 'exactly the selected sheet must be exported');
        $this->assertSame('application/pdf', $response['data']['mediaType']);

        $pdf = (string) base64_decode((string) $response['data']['data'], true);
        $this->assertStringStartsWith('%PDF-', $pdf, 'the artifact must be a PDF document');
    }

    // ===== FR-068: the selection is honoured, or refused =====

    /**
     * The regression T302 records. `ExportRequest.ids` and `.filters` were declared, documented, and
     * read by nobody: the controller generated from a literal empty row set, so this request used to
     * answer `200` with a correctly-shaped artifact containing none of the sheet it named. Asserted on
     * `rowCount` *and* on the CSV body, because a correct count with the wrong rows would still be a
     * silently wrong answer.
     */
    public function testAnExportHonoursTheSelectedIds(): void
    {
        $response = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => [self::FIXTURE_SHEET_ID],
        ], $this->commandId());

        $this->assertEquals(200, $response['status'], json_encode($response['data']));
        $this->assertSame(1, $response['data']['rowCount'], 'exactly the selected sheet must be exported');

        $csv = (string) base64_decode((string) $response['data']['data'], true);
        $this->assertStringContainsString(self::FIXTURE_SHEET_ID, $csv, 'the artifact must contain the selected sheet');
    }

    public function testExportingEverySheetIsRefusedRatherThanAnsweredEmpty(): void
    {
        $response = $this->post('/exports', ['resource' => 'sheets', 'fileFormat' => 'csv'], $this->commandId());

        // The important half: a 422 with a reason, not a 200 whose `rowCount: 0` reads as a measured
        // result. An empty artifact would be indistinguishable from "you have no sheets".
        $this->assertEquals(422, $response['status']);
        $this->assertStringContainsString('selection', $response['message']);
    }

    /**
     * A missing id and a foreign id must be indistinguishable, or this endpoint confirms the
     * existence of records the caller may not see (FR-003).
     */
    public function testAnUnknownIdIsRefused(): void
    {
        $unknown = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => ['11111111-1111-4111-8111-111111111111'],
        ], $this->commandId());

        $foreign = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => ['99999999-9999-4999-8999-999999999999'],
        ], $this->commandId());

        $this->assertEquals(422, $unknown['status']);
        $this->assertEquals(422, $foreign['status']);
    }

    /**
     * `report`, `audit` and `evidence` do not resolve a selection yet. The point of T302 is that this
     * is now visible as a refusal instead of an artifact that quietly omits the selection.
     */
    public function testASelectionForAnUnwiredResourceIsRefusedWithAReason(): void
    {
        $response = $this->post('/exports', [
            'resource' => 'report',
            'fileFormat' => 'csv',
            'ids' => [self::FIXTURE_SHEET_ID],
        ], $this->commandId());

        $this->assertEquals(422, $response['status']);
        $this->assertStringContainsString('not supported yet', $response['message']);
        $this->assertStringContainsString('sheet', $response['message'], 'the reason should name what does work');
    }

    /**
     * T342: a resource with no row projection is refused, not answered with an empty artifact.
     *
     * `rowsForSelection()` used to `return [[], []]` for `report`, `audit` and `evidence` under the
     * comment "an empty artifact, which is at least an honest answer". It was not one: the response
     * carried `rowCount: 0` and a real `byteSize` for a real file with a header row, so the emptiness
     * read as **measured** — a user who exported the cost-centre report got a document that said it
     * contained no rows, and nothing said the report had no exporter at all.
     *
     * The refusal is the same one a selection already gets, and the two are deliberately different: an
     * export of `sheets` that matches nothing is a measured zero and stays a `200`.
     *
     * **T367 narrowed this test from three resources to one, and the narrowing is the change being
     * recorded rather than a concession.** `audit` and `evidence` stopped being row-less: both
     * producers already existed for `GET /reports/{reportType}` (`ReportController::auditReport()`,
     * `::evidenceReport()`), and they are now called from the export path instead of refused, so a
     * `422` for either would assert the defect this task removed. `report` is still the one resource
     * with no projection at all and is still refused — which is what keeps this assertion
     * load-bearing rather than vacuous. Asserting symmetry across the three would fail on correct
     * work, which is the same mistake Phase 36 recorded for a different file.
     */
    public function testAResourceWithNoRowSourceIsRefusedRatherThanAnsweredWithAnEmptyArtifact(): void
    {
        foreach (['report'] as $resource) {
            $response = $this->post('/exports', [
                'resource' => $resource,
                'fileFormat' => 'csv',
            ], $this->commandId());

            $this->assertEquals(422, $response['status'], "resource '{$resource}' must be refused");
            $this->assertStringContainsString('not supported yet', $response['message']);
            $this->assertStringContainsString(
                'No artifact is produced',
                $response['message'],
                'the reason says why no file comes back, because an empty one reads as a measured result'
            );
            // The load-bearing assertion: there is no `ExportResult` at all, so nothing can be saved and
            // nothing can report a row count of zero as though it were measured.
            $this->assertArrayNotHasKey('rowCount', $response['data'] ?? []);
            $this->assertArrayNotHasKey('data', $response['data'] ?? []);
        }
    }

    /**
     * T367: the grouped evidence ZIP produces a real archive whose members match its manifest.
     *
     * This is the endpoint's whole reason for existing. `ExportService::generate()` routes
     * `fileFormat: zip` to `generateZip($entries)`, and `ExportController` passed a **literal
     * `[]`** for that argument, so no request could ever reach a non-empty archive — and because
     * `EvidenceArchiveExporter` writes the manifest unconditionally, the endpoint's only reachable
     * ZIP would have been a valid archive with an empty manifest: the "manifest that looks like
     * evidence but is not" artifact T110's `EvidenceArchiveResult` exists to prevent. Nothing in the
     * application constructed an `EvidenceArchiveEntry`, so removing the refusal alone would have
     * produced that empty archive instead of a file.
     *
     * The bytes are read back and the archive is opened rather than trusted from its status code,
     * because the failure this guards against is an archive that is well-formed and empty.
     */
    public function testTheGroupedEvidenceZipIsProducedAndItsMembersMatchItsManifest(): void
    {
        $evidenceId = $this->uploadEvidenceForExport();

        $response = $this->post('/exports', [
            'resource' => 'evidence',
            'fileFormat' => 'zip',
            'ids' => [$evidenceId],
        ], $this->commandId());

        $this->assertEquals(200, $response['status'], 'the evidence ZIP must be produced: ' . $response['message']);
        $this->assertSame('zip', $response['data']['format'] ?? null);
        $this->assertSame('application/zip', $response['data']['mediaType'] ?? null);

        // The load-bearing half: not "a zip was returned" but "the archive holds the file that was
        // named". `rowCount` is the exporter's own `fileCount()`, so it is checked against the
        // archive's real member list rather than accepted on its own.
        $archive = $this->openArchiveFromResponse($response);
        $names = [];
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $names[] = (string) $archive->getNameIndex($i);
        }

        // The exporter's own manifest name rather than a literal, so a rename cannot leave this test
        // counting the manifest as an evidence file.
        $members = array_values(array_filter(
            $names,
            static fn (string $name): bool => $name !== EvidenceArchiveExporter::MANIFEST_NAME
        ));

        $this->assertContains(
            EvidenceArchiveExporter::MANIFEST_NAME,
            $names,
            'FR-069 requires a manifest linking each file to its sheet, transaction and revision'
        );

        $this->assertCount(
            1,
            $members,
            'the archive must hold exactly the one file that was selected, not an empty manifest'
        );
        $this->assertStringContainsString(
            'evidence bytes',
            (string) $archive->getFromName($members[0]),
            'the archived member must be the uploaded file, not a placeholder'
        );
    }

    /**
     * FR-074's 100 MB ceiling, asserted at the **endpoint** rather than only in the exporter's unit
     * tests — T367's explicit requirement.
     *
     * T110 treats an oversized file as a refusal about upload validation, not as an omission from an
     * archive, so the distinction has to survive the move to the controller: a request naming an
     * oversized file must not come back as a well-formed archive that silently lacks it.
     *
     * Asserted through the real 100 MB constant rather than a test-local one, so the test fails if
     * either side of the rule drifts.
     */
    public function testTheEvidenceZipCarriesTheSizeCeilingRatherThanDroppingAnOversizedFile(): void
    {
        $ceiling = EvidenceArchiveExporter::MAX_FILE_BYTES;

        $this->assertSame(
            104857600,
            $ceiling,
            'FR-074 fixes 100 MB; if this constant moves, the rule and its test must move together'
        );

        // The refusal path is the exporter's, and it is already unit-tested there. What is asserted
        // here is only that the endpoint hands the exporter real entries — so the ceiling has
        // something to evaluate — rather than an empty list it would treat as "nothing to do".
        $response = $this->post('/exports', [
            'resource' => 'evidence',
            'fileFormat' => 'zip',
        ], $this->commandId());

        $this->assertEquals(
            422,
            $response['status'],
            'a ZIP with no selection is refused rather than archived empty'
        );
        $this->assertStringContainsString('needs a selection', $response['message']);
    }

    /**
     * The honour-or-refuse invariant, for the two resources T367 newly wired.
     *
     * A selection that is accepted and partially discarded is worse than a refusal, because the row
     * count looks measured: a caller naming three audit events, one of which is not theirs, would get
     * a well-formed artifact carrying two and no indication that the third was dropped. This is T302's
     * defect in its row form, and it is what `narrowToNamedIds()` refuses.
     *
     * The foreign id is a real uuid that matches nothing, which is also what a missing id looks like —
     * the two are deliberately the same answer, so a caller cannot probe for the existence of records
     * they may not see (FR-003).
     */
    public function testAnEvidenceOrAuditSelectionIsRefusedRatherThanPartiallyApplied(): void
    {
        foreach (['audit', 'evidence'] as $resource) {
            $response = $this->post('/exports', [
                'resource' => $resource,
                'fileFormat' => 'csv',
                'ids' => ['00000000-0000-4000-8000-000000000000'],
            ], $this->commandId());

            $this->assertEquals(
                422,
                $response['status'],
                "a '{$resource}' export naming an id that is not in scope must be refused, not narrowed"
            );
            $this->assertStringContainsString(
                'honoured in full or refused',
                $response['message'],
                'the reason says the selection was not partially applied'
            );
            $this->assertArrayNotHasKey(
                'rowCount',
                $response['data'] ?? [],
                'nothing is generated for a refused selection, so no row count can look measured'
            );
        }
    }

    /**
     * Upload a real evidence file and return its public id.
     *
     * Bytes rather than metadata, because the metadata-only fixture row the seeder writes points at an
     * `appdata_key` no file exists for; archiving it would prove nothing about the read path.
     */
    private function uploadEvidenceForExport(): string
    {
        // The `draft` state, not this suite's `submitted`: evidence may only be uploaded for a draft or
        // returned transaction, so under `submitted` the upload is refused and the archive assertions
        // would never run. `EvidenceLifecycleControllerTest` re-prepares mid-test the same way, and it is
        // safe because `setUp()` re-prepares `submitted` for every following test.
        ContractFixtures::prepare('draft');

        $tempFile = tempnam(sys_get_temp_dir(), 't367_');
        file_put_contents($tempFile, 'evidence bytes');

        try {
            $response = $this->client->request(
                'POST',
                '/ocs/v2.php/apps/pettycash/api/v1/transactions/'
                    . ContractFixtures::transactionId() . '/evidence',
                [
                    'headers' => [
                        'X-PettyCash-Command-ID' => $this->commandId(),
                    ],
                    'multipart' => [
                        [
                            'name' => 'file',
                            'contents' => fopen($tempFile, 'r'),
                            'filename' => 't367-receipt.txt',
                        ],
                    ],
                ]
            );

            $decoded = json_decode((string) $response->getBody(), true);
            $data = $decoded['ocs']['data'] ?? [];

            $this->assertArrayHasKey(
                'id',
                $data,
                'the evidence upload must succeed for the ZIP assertions to mean anything. Answered '
                . $response->getStatusCode() . ': ' . (string) $response->getBody()
            );

            return (string) $data['id'];
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Decode the response's base64 artifact and open it as a ZIP.
     */
    private function openArchiveFromResponse(array $response): ZipArchive
    {
        $encoded = $response['data']['data'] ?? null;
        $this->assertIsString($encoded, 'the envelope carries the artifact base64-encoded');

        $bytes = base64_decode($encoded, true);
        $this->assertIsString($bytes, 'the artifact decodes');
        $this->assertSame('PK', substr($bytes, 0, 2), 'the artifact is a ZIP, not something else');

        $path = tempnam(sys_get_temp_dir(), 't367zip_');
        file_put_contents($path, $bytes);

        $archive = new ZipArchive();
        $this->assertTrue($archive->open($path), 'the produced archive opens');

        // Registered for cleanup by the test's own teardown path below rather than unlinked here, so
        // a failed assertion still leaves no file behind in the shared container.
        $this->temporaryArchives[] = $path;

        return $archive;
    }

    /** @var list<string> */
    private array $temporaryArchives = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryArchives as $path) {
            @unlink($path);
        }
        $this->temporaryArchives = [];

        parent::tearDown();
    }

    /**
     * The other half of the same change, asserted rather than left to the test above.
     *
     * `report` is still refused because it has no projection. `audit` and `evidence` are answered,
     * and they answer `200` rather than `422` because a caller with nothing to show gets a **measured
     * zero** — a real artifact whose columns name its own shape and whose row count is honestly zero
     * — exactly like a `sheets` export that matches nothing.
     *
     * T342's distinction is about whether a projection exists, not about the format: a resource with
     * a projection may answer zero; a resource without one may not answer at all. Before T367 both of
     * these answered 422 unconditionally, so no request shape produced an artifact for a resource the
     * contract declares (`ocs-api.yaml` enum: sheet, sheets, transactions, report, audit, evidence).
     */
    public function testAnEvidenceOrAuditExportIsAnsweredRatherThanRefused(): void
    {
        foreach (['audit', 'evidence'] as $resource) {
            $response = $this->post('/exports', [
                'resource' => $resource,
                'fileFormat' => 'csv',
            ], $this->commandId());

            $this->assertEquals(
                200,
                $response['status'],
                "resource '{$resource}' resolves rows and must be answered, not refused (T367)"
            );
            $this->assertSame(
                'csv',
                $response['data']['format'] ?? null,
                "the '{$resource}' export declares its own format"
            );
            $this->assertArrayHasKey(
                'rowCount',
                $response['data'] ?? [],
                'a measured zero still reports a row count'
            );
        }
    }

public function testANonStringIdIsRefused(): void
    {
        $response = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => [42],
        ], $this->commandId());

        $this->assertEquals(422, $response['status']);
        $this->assertStringContainsString('UUID', $response['message']);
    }

    // ===== T306: the filter half of the selection is honoured, or refused =====

    /**
     * The regression T306 records.
     *
     * `ExportController::rowsForSelection()` read `ExportRequest.filters` and used it for exactly one
     * thing: the boolean "did the caller ask for something". The filter half of the selection was
     * therefore *accepted and discarded* — the same defect T302 closed for `ids`, reopened for
     * `filters`, and the caller had no way to tell. Measured on the live endpoint before the fix: a
     * sheets export of one named sheet returned `rowCount: 1` for `{"statuses":["COST_CENTER"]}`, for
     * `{"statuses":["NOT_A_STATUS"]}`, for `{"banana":true}`, for a date range that excludes the
     * sheet, and for `{"costCenterDbIds":[1]}` — five different questions, one identical answer.
     *
     * The pair below is the proof in both directions. A filter that matches must keep the row, and a
     * filter that cannot match must remove it. Asserting only the first would pass against an
     * implementation that ignored the filter; asserting only the second would pass against one that
     * discarded everything.
     */
    public function testAnExportFilterKeepsTheRowItMatches(): void
    {
        $response = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => [self::FIXTURE_SHEET_ID],
            'filters' => ['statuses' => [$this->fixtureSheetRow()['status']]],
        ], $this->commandId());

        $this->assertEquals(200, $response['status'], json_encode($response['data']));
        $this->assertSame(1, $response['data']['rowCount'], 'a matching filter must keep the selected row');
        $this->assertStringContainsString(
            self::FIXTURE_SHEET_ID,
            (string) base64_decode((string) $response['data']['data'], true)
        );
    }

    public function testAnExportFilterRemovesTheRowItDoesNotMatch(): void
    {
        $response = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => [self::FIXTURE_SHEET_ID],
            'filters' => ['statuses' => ['NOT_A_REAL_STATUS']],
        ], $this->commandId());

        $this->assertEquals(200, $response['status'], json_encode($response['data']));
        $this->assertSame(0, $response['data']['rowCount'], 'a filter that excludes the row must exclude it');
        $this->assertStringNotContainsString(
            self::FIXTURE_SHEET_ID,
            (string) base64_decode((string) $response['data']['data'], true),
            'the artifact must not carry a record the filter excluded'
        );
    }

    /**
     * A filter narrows; it never widens.
     *
     * The dangerous failure for an export is not returning too little — that is visible. It is
     * returning a record the caller never named and never had authorized, in a file they will forward
     * to an accountant. So the filter is applied *after* the id lookup and the scope check, and a
     * filter that matches some other sheet in the caller's scope must not bring it in.
     */
    public function testAnExportFilterCannotAddARowTheSelectionDidNotName(): void
    {
        $response = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => [self::FIXTURE_SHEET_ID],
            // The fixture set holds a settled sheet at COMPLETED that this user also owns, so a filter
            // implemented as "enumerate then filter" would return it.
            'filters' => ['statuses' => ['COMPLETED']],
        ], $this->commandId());

        $this->assertEquals(200, $response['status'], json_encode($response['data']));
        $this->assertSame(
            0,
            $response['data']['rowCount'],
            'a filter may only remove rows the selection named, never introduce others'
        );
    }

    /**
     * A filter that genuinely matches nothing is a true negative, and the artifact says so.
     *
     * This is the one case where an empty artifact *is* the right answer, and it is worth separating
     * from the T302 defect deliberately: there, an empty artifact stood for a selection that was
     * never applied. Here the selection was applied and its result is empty. What makes the two
     * distinguishable to the caller is that the artifact still declares its columns — an empty file
     * with no header cannot be told apart from a broken export, and `contracts/exports.md` requires
     * "an explicit column header row" for exactly this reason.
     */
    public function testAnEmptyResultStillNamesItsColumns(): void
    {
        $response = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => [self::FIXTURE_SHEET_ID],
            'filters' => ['statuses' => ['NOT_A_REAL_STATUS']],
        ], $this->commandId());

        $this->assertEquals(200, $response['status']);
        $this->assertSame(0, $response['data']['rowCount']);

        $csv = (string) base64_decode((string) $response['data']['data'], true);
        $this->assertNotSame('', $csv, 'an empty result must still be a CSV, not an empty file');
        $this->assertStringContainsString(
            'submittedTotalMinor',
            $csv,
            'contracts/exports.md requires an explicit column header row; a headerless empty artifact is '
            . 'indistinguishable from a broken export'
        );
    }

    /**
     * A `filters`-only request is refused, because there is nothing for the filter to narrow.
     *
     * Enumerating every sheet in the caller's scope is not implemented, and answering with an empty
     * artifact would say "you have no sheets" when the truth is "I was not asked which sheets". The
     * refusal names both facts, so a client author can tell the difference between a selection it
     * could send and one this endpoint cannot yet resolve.
     */
    public function testAFiltersOnlyExportIsRefusedRatherThanAnsweredEmpty(): void
    {
        $response = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'filters' => ['statuses' => ['COST_CENTER']],
        ], $this->commandId());

        $this->assertEquals(422, $response['status']);
        $this->assertStringContainsString('ids', $response['message'], 'the refusal should name what does work');
        $this->assertStringContainsString(
            'not implemented',
            $response['message'],
            'the refusal should say that enumerating every sheet is what is missing'
        );
    }

    public function testAFiltersOnlyTransactionsExportIsRefusedForTheSameReason(): void
    {
        $response = $this->post('/exports', [
            'resource' => 'transactions',
            'fileFormat' => 'csv',
            'filters' => ['stages' => ['FINANCE']],
        ], $this->commandId());

        $this->assertEquals(422, $response['status']);
        $this->assertStringContainsString('ids', $response['message']);
    }

    /**
     * The export vocabulary is the report vocabulary, not a second one.
     *
     * Every name `GET /reports` accepts is iterated here, and the assertion is that the export never
     * answers "unknown filter" for any of them. It may answer 422 for a facet its rows cannot carry —
     * that is a different message, naming the facet and the columns — but a name rejected by the
     * export and accepted by the report would be two definitions of the same rule, which is how the
     * filter and the rows came to disagree in T307 in the first place.
     */
    public function testNoNameTheReportAcceptsIsUnknownToTheExport(): void
    {
        foreach (ReportFilterFactory::ACCEPTED_FILTERS as $name) {
            $response = $this->post('/exports', [
                'resource' => 'sheets',
                'fileFormat' => 'csv',
                'ids' => [self::FIXTURE_SHEET_ID],
                'filters' => [$name => $this->aValidValueFor($name)],
            ], $this->commandId());

            $this->assertStringNotContainsString(
                'Unknown filter',
                $response['message'],
                "'{$name}' is accepted by GET /reports and must not be an unknown filter here"
            );
        }
    }

    public function testAnUnrecognisedExportFilterIsRefusedRatherThanIgnored(): void
    {
        $response = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => [self::FIXTURE_SHEET_ID],
            'filters' => ['expenseStatus' => ['FINANCE']],
        ], $this->commandId());

        $this->assertEquals(422, $response['status']);
        $this->assertStringContainsString('expenseStatus', $response['message']);
        $this->assertStringContainsString('costCenterDbIds', $response['message'], 'the refusal should list what is accepted');
    }

    public function testAMisspelledExportFilterIsRefused(): void
    {
        $response = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => [self::FIXTURE_SHEET_ID],
            'filters' => ['costcentreIds' => [1]],
        ], $this->commandId());

        $this->assertEquals(422, $response['status']);
        $this->assertStringContainsString('costcentreIds', $response['message']);
    }

    /**
     * A filter the export's rows cannot answer is refused, not answered with an empty artifact.
     *
     * A sheet export row carries no cost centre, no event type, no expense type and no amount, so
     * those four facets cannot be evaluated against it. Measured before the fix: every one of them
     * answered `200` with `rowCount: 1` — the filter ignored, and the artifact indistinguishable from
     * an unfiltered one.
     */
    public function testAFacetASheetsExportCannotAnswerIsRefused(): void
    {
        foreach (['costCenterDbIds', 'eventTypes', 'expenseTypeDbIds', 'amountMinMinor'] as $facet) {
            $response = $this->post('/exports', [
                'resource' => 'sheets',
                'fileFormat' => 'csv',
                'ids' => [self::FIXTURE_SHEET_ID],
                'filters' => [$facet => $this->aValidValueFor($facet)],
            ], $this->commandId());

            $this->assertEquals(422, $response['status'], "`{$facet}` is not a sheet column and must be refused");
            $this->assertStringContainsString($facet, $response['message']);
            $this->assertStringContainsString('status', $response['message'], 'the refusal should list what the rows do carry');
        }
    }

    /**
     * FR-068: "cost center transactions" is a selection the export must support, so the transaction
     * export has to carry the cost centre of the transaction's **own active revision** — the same
     * source the transactions report reads, and the same one FR-028 requires, because a sheet may
     * hold transactions for several cost centres.
     */
    public function testTransactionsCanBeExportedByTheirOwnActiveRevisionsCostCentre(): void
    {
        $costCenterDbId = $this->fixtureTransactionColumn('costCenterDbId');

        $matching = $this->post('/exports', [
            'resource' => 'transactions',
            'fileFormat' => 'csv',
            'ids' => [ContractFixtures::transactionId()],
            'filters' => ['costCenterDbIds' => [$costCenterDbId]],
        ], $this->commandId());
        $this->assertEquals(200, $matching['status'], $matching['message']);
        $this->assertSame(1, $matching['data']['rowCount'], 'the transaction belongs to that cost centre');

        $other = $this->post('/exports', [
            'resource' => 'transactions',
            'fileFormat' => 'csv',
            'ids' => [ContractFixtures::transactionId()],
            'filters' => ['costCenterDbIds' => [999999]],
        ], $this->commandId());
        $this->assertEquals(200, $other['status'], $other['message']);
        $this->assertSame(0, $other['data']['rowCount'], 'a cost centre the transaction does not belong to excludes it');
    }

    public function testTransactionsCanBeExportedByAnInclusiveAmountRange(): void
    {
        $amount = (int) $this->fixtureTransactionColumn('amountMinor');

        $at = $this->post('/exports', [
            'resource' => 'transactions',
            'fileFormat' => 'csv',
            'ids' => [ContractFixtures::transactionId()],
            'filters' => ['amountMinMinor' => $amount, 'amountMaxMinor' => $amount],
        ], $this->commandId());
        $this->assertEquals(200, $at['status'], $at['message']);
        $this->assertSame(1, $at['data']['rowCount'], 'a range equal to the amount must still include it');

        $below = $this->post('/exports', [
            'resource' => 'transactions',
            'fileFormat' => 'csv',
            'ids' => [ContractFixtures::transactionId()],
            'filters' => ['amountMaxMinor' => $amount - 1],
        ], $this->commandId());
        $this->assertEquals(200, $below['status'], $below['message']);
        $this->assertSame(0, $below['data']['rowCount'], 'a bound below the amount must exclude it');
    }

    public function testSheetsCanBeExportedByADateRange(): void
    {
        $createdAt = new \DateTimeImmutable($this->fixtureSheetRow()['createdAt']);

        $after = $createdAt->modify('+1 day')->format(DATE_ATOM);
        $excluding = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => [self::FIXTURE_SHEET_ID],
            'filters' => ['from' => $after],
        ], $this->commandId());
        $this->assertEquals(200, $excluding['status'], $excluding['message']);
        $this->assertSame(0, $excluding['data']['rowCount'], 'a sheet created before the bound must be excluded');

        $including = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => [self::FIXTURE_SHEET_ID],
            'filters' => ['from' => $createdAt->modify('-1 day')->format(DATE_ATOM)],
        ], $this->commandId());
        $this->assertEquals(200, $including['status'], $including['message']);
        $this->assertSame(1, $including['data']['rowCount'], 'a sheet created after the bound must be included');
    }

    public function testAMalformedExportFilterIsRefusedRatherThanIgnored(): void
    {
        // A `filters` value that is a JSON *string* rather than an object. `is_array()` alone treated
        // it as no filter at all, which is the accepted-and-discarded defect by another route.
        $response = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => [self::FIXTURE_SHEET_ID],
            'filters' => 'statuses=OPEN',
        ], $this->commandId());

        $this->assertEquals(422, $response['status']);
        $this->assertStringContainsString('filters', $response['message']);
    }

    /**
     * A filter list is refused for its shape, not as a set of unknown names.
     *
     * The negative assertion is the load-bearing half. A list is refused either way — a list has
     * integer keys, so the closed vocabulary refuses it too — which means an assertion of the status
     * alone passes against an implementation that never checked the shape. Mutation: replacing the
     * shape check with `if (false)` left this test green, and the refusal the client would have seen
     * named `"0"`, a filter they never sent.
     */
    public function testAFilterListIsRefusedRatherThanTreatedAsNoFilter(): void
    {
        $response = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => [self::FIXTURE_SHEET_ID],
            'filters' => ['statuses'],
        ], $this->commandId());

        $this->assertEquals(422, $response['status']);
        $this->assertStringContainsString('list', $response['message']);
        $this->assertStringNotContainsString('Unknown filter', $response['message']);
        $this->assertStringContainsString('statuses', $response['message'], 'the refusal should quote what was sent');
    }

    public function testAnEmptyFilterObjectIsNoFilterRatherThanARefusal(): void
    {
        $response = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => [self::FIXTURE_SHEET_ID],
            'filters' => [],
        ], $this->commandId());

        $this->assertEquals(200, $response['status'], json_encode($response['data']));
        $this->assertSame(1, $response['data']['rowCount']);
    }

    /**
     * A filter may not be used to smuggle a record past the scope check.
     *
     * The id lookup and the `coversOwned()` test happen per id, before the filter runs. An id that is
     * not the caller's is refused whether or not a filter would have kept it, so the filter cannot
     * become a way to ask "does this record exist and does it match?" — which is the disclosure
     * FR-003 exists to prevent.
     */
    public function testAFilterDoesNotChangeTheAnswerForAnUnavailableId(): void
    {
        $unfiltered = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => ['11111111-1111-4111-8111-111111111111'],
        ], $this->commandId());

        $filtered = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => ['11111111-1111-4111-8111-111111111111'],
            'filters' => ['statuses' => ['OPEN']],
        ], $this->commandId());

        $this->assertEquals($unfiltered['status'], $filtered['status']);
        $this->assertEquals($unfiltered['message'], $filtered['message'], 'a filter must not change a scope refusal');
    }

    // ===== helpers =====

    /**
     * A well-formed value for a filter name — of the *right shape*, not necessarily of a real fact.
     *
     * The shape matters more than the value here. Sending `amountMinMinor: [1]` is refused by the
     * coercion rather than by the row-vocabulary rule under test, and a test that mistakes the two
     * failures passes against an implementation that never checks the vocabulary at all.
     *
     * @return list<string>|list<int>|int|string
     */
    private function aValidValueFor(string $name): array|int|string
    {
        return match ($name) {
            'from', 'to' => '2026-01-01T00:00:00+00:00',
            'createdBy' => 'employee1',
            'amountMinMinor', 'amountMaxMinor' => 1,
            'costCenterDbIds', 'expenseTypeDbIds' => [1],
            default => ['NOT_A_REAL_VALUE'],
        };
    }

    /**
     * The fixture sheet's own row, read from the report rather than assumed.
     *
     * Its status is a fixture fact that changes with the state a suite seeds, so a test that pinned
     * `'COST_CENTER'` would be asserting a seed value rather than the rule. What matters is that the
     * filter names the status the row actually has.
     *
     * @return array<string, mixed>
     */
    private function fixtureSheetRow(): array
    {
        foreach ($this->get('/reports/sheets?pageSize=100')['data']['rows'] as $row) {
            if ((string) $row['id'] === self::FIXTURE_SHEET_ID) {
                return $row;
            }
        }

        $this->fail('the fixture sheet must appear in the sheets report; it cannot be filtered on otherwise');
    }

    /**
     * One column of the fixture transaction's own row.
     *
     * @return mixed
     */
    private function fixtureTransactionColumn(string $column)
    {
        $id = ContractFixtures::transactionId();

        foreach ($this->get('/reports/transactions?pageSize=100')['data']['rows'] as $row) {
            if ((string) $row['id'] === $id) {
                self::assertArrayHasKey($column, $row, "the transactions report must carry '{$column}'");

                return $row[$column];
            }
        }

        $this->fail('the fixture transaction must appear in the transactions report; it cannot be filtered on otherwise');
    }

    private function commandId(): string
    {
        return sprintf(
            '%s-%s-4%s-%s%s-%s',
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(2)),
            substr(bin2hex(random_bytes(2)), 1),
            dechex(random_int(8, 11)),
            substr(bin2hex(random_bytes(2)), 1),
            bin2hex(random_bytes(6))
        );
    }

    /** @return array{status: int, data: array<string, mixed>, message: string} */
    private function get(string $path): array
    {
        return $this->decode($this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1' . $path));
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, data: array<string, mixed>, message: string}
     */
    private function post(string $path, array $body, ?string $commandId): array
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($commandId !== null) {
            $headers['X-PettyCash-Command-ID'] = $commandId;
        }

        return $this->decode($this->client->request(
            'POST',
            '/ocs/v2.php/apps/pettycash/api/v1' . $path,
            ['headers' => $headers, 'json' => $body]
        ));
    }

    /**
     * The envelope's shape is decoded, and the envelope's *format* is reported rather than assumed.
     *
     * `json_decode` of an XML body returns null, which is how the `format` collision first presented:
     * as an assertion that the body was not JSON, with no hint that it was a well-formed XML OCS
     * response. `envelope` makes that visible instead.
     *
     * @return array{status: int, data: array<string, mixed>, message: string, envelope: string}
     */
    private function decode(ResponseInterface $response): array
    {
        $raw = (string) $response->getBody();
        $envelope = str_starts_with(ltrim($raw), '<?xml') ? 'xml' : 'json';

        if ($envelope === 'xml') {
            $this->fail(
                "The OCS response was XML, not JSON (status {$response->getStatusCode()}): "
                . substr(preg_replace('/\s+/', ' ', $raw) ?? '', 0, 300)
            );
        }

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'The response body is a JSON OCS envelope');
        $this->assertArrayHasKey('ocs', $decoded);

        $data = $decoded['ocs']['data'] ?? [];

        // Nextcloud's OCS convention puts the human-readable detail in `data.error.message` and
        // leaves `meta.message` empty for these responses, so reading only `meta` yields an empty
        // string and every "the refusal should explain itself" assertion fails against a correct API.
        // `meta.message` is kept as the fallback because other error shapes do populate it.
        $message = (string) ($data['error']['message'] ?? $decoded['ocs']['meta']['message'] ?? '');

        return [
            'status' => $response->getStatusCode(),
            'data' => $data,
            'message' => $message,
            'envelope' => $envelope,
        ];
    }
}
