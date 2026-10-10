<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FR-064's sheet facets, measured through the transport that carries them (T333).
 *
 * ## Why these exist separately from the round-trip scenario
 *
 * `sheetlist` in `tests/Integration/Persistence/roundtrip-runner.php` answers "what does the repository do
 * with a {@see \OCA\PettyCash\Domain\Expense\SheetListFilter} once it has one" — the SQL, the currency
 * join, the `PARAM_DATETIME_IMMUTABLE` comparison. Its own docblock records that it "does not bind the
 * query parameters through HTTP", so the half it cannot see is exactly this file: that `status`,
 * `currency`, `from` and `to` arrive under the names `contracts/ocs-api.yaml` declares, that each one
 * narrows the answer, and that an unrecognised value is refused rather than ignored.
 *
 * That gap is why the defect survived. `SheetController::index()` bound
 * `public function index(int $page = 1, int $pageSize = 25)`, so all five declared filters were discarded
 * in the transport while the repository grew a filter object nothing on the request path could construct.
 *
 * ## Why the assertions are relative to the endpoint's own unfiltered answer
 *
 * `GET /sheets` is scoped to the authenticated user, and this is a shared instance in which the acting
 * user already holds hundreds of sheets from earlier runs and other suites. Pinning exact ids or an exact
 * `total` would make this file fail for reasons that have nothing to do with filtering, and comparing
 * against a hand-written fixture would reproduce the trap Phase 55 recorded for T327 and T331 — a green
 * suite that agrees with a fixture the server never sent.
 *
 * So every test captures the **unfiltered** answer first and asserts against it. That is the only
 * comparison an ignored filter cannot satisfy: a facet that changed nothing returns the baseline verbatim,
 * and each test below would fail on it.
 */
final class SheetListFilterContractTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
    }

    // ---------------------------------------------------------------- helpers

    /**
     * `GET /sheets` with a query string, returning the decoded `ocs.data` payload and asserting 200.
     *
     * The query is passed through verbatim rather than assembled from an array, because the subject of
     * several tests below is the exact spelling of a parameter name.
     */
    private function sheets(string $query): array
    {
        $path = '/ocs/v2.php/apps/pettycash/api/v1/sheets' . ($query === '' ? '' : '?' . $query);
        $response = $this->client->request('GET', $path);

        // The status only, not the body: a 200 that answers with 25 sheet summaries produces a failure
        // message hundreds of lines long, and the length buries the assertion that actually failed.
        $this->assertEquals(
            200,
            $response->getStatusCode(),
            "GET {$path} should answer 200 but answered " . $response->getStatusCode() . '.'
        );

        return $this->dataOf($response);
    }

    /**
     * Every row of a query's result, paged through.
     *
     * Paging rather than one large page because the acting user holds more sheets than a single page
     * carries on this shared instance, and a facet's `total` cannot be checked against a page that
     * silently stopped early. The loop terminates on `total` *and* on an empty page, so a `total` that
     * disagrees with the rows cannot spin forever.
     *
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    private function allSheets(string $query): array
    {
        $items = [];
        $total = 0;
        $page = 1;

        do {
            $data = $this->sheets($this->withPaging($query, $page));
            $this->assertArrayHasKey('total', $data);
            $this->assertArrayHasKey('items', $data);
            $this->assertIsArray($data['items']);

            $total = (int) $data['total'];
            foreach ($data['items'] as $item) {
                $items[] = $item;
            }
            $page++;
        } while (count($items) < $total && $data['items'] !== []);

        $this->assertCount(
            $total,
            $items,
            'The paged read must reach every row `total` claims, or the total cannot be checked against them.'
        );

        return ['items' => $items, 'total' => $total];
    }

    /** Append `page`/`pageSize` to an existing query string. */
    private function withPaging(string $query, int $page): string
    {
        $paging = 'page=' . $page . '&pageSize=100';

        return $query === '' ? $paging : $query . '&' . $paging;
    }

    /** @return list<string> */
    private function idsOf(array $page): array
    {
        return array_map(
            static fn (array $item): string => (string) $item['id'],
            $this->itemsOf($page)
        );
    }

    /** @return list<array<string,mixed>> */
    private function itemsOf(array $page): array
    {
        return array_values($page['items'] ?? []);
    }

    /** The distinct values of one `SheetSummary` member across a page, sorted for stable messages. */
    private function distinct(array $page, string $member): array
    {
        $values = array_map(
            static fn (array $sheet): string => (string) ($sheet[$member] ?? ''),
            $this->itemsOf($page)
        );

        $values = array_values(array_unique(array_filter(
            $values,
            static fn (string $value): bool => $value !== ''
        )));
        sort($values);

        return $values;
    }

    /** The rows of a page satisfying a predicate. */
    private function matching(array $page, callable $predicate): array
    {
        return array_values(array_filter($this->itemsOf($page), $predicate));
    }

    private function dataOf(Response $response): array
    {
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body, 'The response should carry a JSON body.');
        $this->assertArrayHasKey('ocs', $body);

        return $body['ocs']['data'];
    }

    private function actingUser(): string
    {
        $user = getenv('PETTYCASH_TEST_USER');
        $this->assertNotFalse($user, 'The OCS contract tests require PETTYCASH_TEST_USER.');

        return trim((string) $user);
    }

    private function utcDay(string $modifier = 'today'): string
    {
        return (new DateTimeImmutable($modifier, new DateTimeZone('UTC')))->format('Y-m-d');
    }

    // ------------------------------------------------------------------ tests

    /**
     * The precondition every other test here rests on.
     *
     * A facet test asserts a *narrowing*. If the caller's estate held one sheet, or one status, or one
     * currency, then "the facet returned everything it was given" would be indistinguishable from "the
     * facet worked", and the file would pass against the unfiltered endpoint it exists to catch. This
     * states the requirement rather than leaving it implied.
     */
    public function testTheFixtureStateGivesAtLeastTwoStatusesAndTwoCurrenciesToFilter(): void
    {
        // `usd` is the fixture state that seeds a second, non-rial sheet. Against `draft` alone every
        // sheet carries the same code and the currency facet would be indistinguishable from one that
        // matched everything, so the state is chosen for what it makes observable.
        ContractFixtures::prepare('usd');
        $baseline = $this->allSheets('');

        $this->assertGreaterThan(
            1,
            $baseline['total'],
            'A one-sheet estate cannot show any narrowing at all.'
        );
        $this->assertGreaterThan(
            1,
            count($this->distinct($baseline, 'status')),
            'The `status` facet needs more than one status present to be observable.'
        );
        $this->assertGreaterThan(
            1,
            count($this->distinct($baseline, 'currency')),
            'The `currency` facet needs more than one currency present to be observable.'
        );
    }

    /**
     * FR-064's `status`: a narrowed request returns only sheets in that status, and `total` counts the
     * facet rather than the caller's whole estate.
     *
     * Every case of the enum is asserted rather than one hand-picked status, because a filter that works
     * for `OPEN` and ignores `CORRECTION` is the shape of this defect.
     */
    #[DataProvider('statusProvider')]
    public function testStatusFilterReturnsOnlyThatStatusAndATotalThatMatchesTheNarrowing(string $status): void
    {
        ContractFixtures::prepare('submitted');
        $baseline = $this->allSheets('');

        $filtered = $this->allSheets('status=' . rawurlencode($status));

        foreach ($this->itemsOf($filtered) as $sheet) {
            $this->assertSame(
                $status,
                $sheet['status'],
                'A status-filtered list must contain only sheets in the requested status.'
            );
        }

        $this->assertSame(
            count($this->matching($baseline, static fn (array $s): bool => $s['status'] === $status)),
            $filtered['total'],
            '`total` must equal the number of the caller\'s sheets actually in that status.'
        );

        $this->assertSame(
            count($this->itemsOf($filtered)),
            $filtered['total'],
            '`total` counts records in scope, not the length of the page beside it.'
        );

        // The subset relation, which is what an ignored filter cannot satisfy.
        $this->assertEmpty(
            array_diff($this->idsOf($filtered), $this->idsOf($baseline)),
            'A narrowed sheet list must be a subset of the unfiltered one.'
        );

        if ($filtered['total'] > 0 && $filtered['total'] < $baseline['total']) {
            $this->assertLessThan(
                $baseline['total'],
                $filtered['total'],
                'A facet matching some sheets but not all must narrow `total`.'
            );
        }
    }

    public static function statusProvider(): array
    {
        return array_map(
            static fn (SheetStatus $case): array => [$case->value],
            SheetStatus::cases()
        );
    }

    /**
     * FR-064's `currency`.
     *
     * `usd` is prepared because it is the state that seeds a non-rial sheet; against `draft` alone every
     * sheet carries the same code and the facet is indistinguishable from one that matched everything.
     */
    public function testCurrencyFilterReturnsOnlyThatCurrencyAndNarrowsTotal(): void
    {
        ContractFixtures::prepare('usd');
        $baseline = $this->allSheets('');

        $currencies = $this->distinct($baseline, 'currency');
        $this->assertGreaterThan(1, count($currencies), 'The currency facet needs two currencies to be observable.');

        foreach ($currencies as $currency) {
            $filtered = $this->allSheets('currency=' . rawurlencode($currency));

            foreach ($this->itemsOf($filtered) as $sheet) {
                $this->assertSame(
                    $currency,
                    $sheet['currency'],
                    'A currency-filtered list must contain only sheets in the requested currency.'
                );
            }

            $this->assertSame(
                count($this->matching($baseline, static fn (array $s): bool => $s['currency'] === $currency)),
                $filtered['total'],
                '`total` must count the currency-filtered set, not the whole estate.'
            );
        }
    }

    /**
     * FR-064's `from` and `to`.
     *
     * The assertion that matters is the containment one. `from=today` selects exactly the sheets created
     * today, and `to=today` must be a **superset** of them: a `to` bound read as midnight would exclude
     * every sheet created during the very day it names, and then the two results would be disjoint. So
     * "`to=today` contains everything `from=today` contains" is the statement that distinguishes the
     * end-of-day reading from the midnight one -- and `SheetListFilterFactory::date()` is what turns a
     * `format: date` bound into the last second of its day.
     *
     * The remaining assertions are deliberately relative rather than absolute. The acting user holds
     * sheets created on many different days, and `SheetSummary` carries no creation date, so a literal
     * "yesterday matches nothing" would be a claim about the shared instance's history rather than about
     * filtering. Disjointness says the same thing without that dependency: whatever `to=yesterday`
     * returns, none of it may be a sheet `from=today` returned.
     */
    public function testDateBoundsNarrowTheListAndToIncludesTheWholeDayItNames(): void
    {
        ContractFixtures::prepare('draft');
        $baseline = $this->allSheets('');

        $this->assertGreaterThan(0, $baseline['total'], 'The date facets need rows to match.');

        $today = $this->utcDay();
        $tomorrow = $this->utcDay('+1 day');
        $yesterday = $this->utcDay('-1 day');

        $fromToday = $this->allSheets('from=' . rawurlencode($today));
        $this->assertGreaterThan(
            0,
            $fromToday['total'],
            '`from` on today must match the fixture sheets, which the seeder stamps now. If it matches '
            . 'nothing, every exclusion below is vacuous.'
        );

        $toToday = $this->allSheets('to=' . rawurlencode($today));

        // The end-of-day proof: everything created today is also "on or before today".
        $this->assertEmpty(
            array_diff($this->idsOf($fromToday), $this->idsOf($toToday)),
            '`to` on a sheet\'s own creation day must include it. A bound read as midnight excludes every '
            . 'row written during that day, which is the defect this assertion exists for.'
        );

        // Nothing is created in the future, so `to=today` is the whole estate and `from=tomorrow` is empty.
        $this->assertSame(
            $baseline['total'],
            $toToday['total'],
            '`to` on today must cover every sheet the caller owns.'
        );

        $fromTomorrow = $this->allSheets('from=' . rawurlencode($tomorrow));
        $this->assertSame(0, $fromTomorrow['total'], '`from` on a future day matches nothing created before it.');
        $this->assertSame([], $this->idsOf($fromTomorrow), 'A narrowed date range returns no rows.');

        // A closed range naming only yesterday is disjoint from today's, whichever sheets fall in it.
        $justYesterday = $this->allSheets(sprintf('from=%s&to=%s', $yesterday, $yesterday));
        $this->assertEmpty(
            array_intersect($this->idsOf($justYesterday), $this->idsOf($fromToday)),
            'A closed range naming only yesterday must be disjoint from the sheets created today.'
        );

        // And `to` alone, as one bound of a pair, must also exclude today's sheets.
        $toYesterday = $this->allSheets('to=' . rawurlencode($yesterday));
        $this->assertEmpty(
            array_intersect($this->idsOf($toYesterday), $this->idsOf($fromToday)),
            '`to` on a day before today must exclude every sheet created today.'
        );
    }

    /** Two facets at once: a filter object resolving only its first argument would match `status` alone. */
    public function testTwoFacetsAtOnceMatchTheIntersectionNotTheWiderSet(): void
    {
        ContractFixtures::prepare('usd');
        $baseline = $this->allSheets('');

        $statuses = $this->distinct($baseline, 'status');
        $currencies = $this->distinct($baseline, 'currency');
        $this->assertGreaterThan(1, count($statuses));
        $this->assertGreaterThan(1, count($currencies));

        $status = $statuses[0];

        // The one-facet result, so "two facets" can be shown to be narrower than "one facet".
        $statusOnly = $this->allSheets('status=' . rawurlencode($status));

        foreach ($currencies as $currency) {
            $combined = $this->allSheets(sprintf(
                'status=%s&currency=%s',
                rawurlencode($status),
                rawurlencode($currency)
            ));

            foreach ($this->itemsOf($combined) as $sheet) {
                $this->assertSame($status, $sheet['status'], 'A combined facet must satisfy the status.');
                $this->assertSame($currency, $sheet['currency'], 'A combined facet must satisfy the currency.');
            }

            $this->assertSame(
                count($this->matching(
                    $baseline,
                    static fn (array $s): bool => $s['status'] === $status && $s['currency'] === $currency
                )),
                $combined['total'],
                'Two facets must match their intersection.'
            );

            $this->assertLessThanOrEqual(
                $statusOnly['total'],
                $combined['total'],
                'Adding a facet cannot widen the result.'
            );
        }
    }

    /**
     * FR-064, in as many words: a filter naming a value outside the accepted set "MUST be refused with a
     * 422 naming the accepted filters, and MUST NOT be ignored — an unrecognised filter silently
     * returning the unfiltered report is a wrong answer, not an empty one".
     *
     * The damaging case is a misspelling, because `OPENN` looks filtered and is not.
     */
    #[DataProvider('refusedFilterProvider')]
    public function testAnUnrecognisedFilterValueIsRefusedWith422NamingTheOffendingField(
        string $query,
        string $field
    ): void {
        $response = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/sheets?' . $query
        );

        $this->assertEquals(
            422,
            $response->getStatusCode(),
            "GET /sheets?{$query} must be refused rather than ignored, but answered "
            . $response->getStatusCode() . '.'
        );

        $error = $this->dataOf($response)['error'] ?? [];
        $this->assertIsArray($error);
        $this->assertArrayHasKey('message', $error);
        $this->assertStringContainsString(
            $field,
            $error['message'],
            'A refusal must name the offending filter, or the caller cannot tell which one was wrong.'
        );

        if ($field === 'status') {
            foreach (SheetStatus::cases() as $case) {
                $this->assertStringContainsString(
                    $case->value,
                    $error['message'],
                    'A refused status must name the accepted vocabulary, so the refusal is actionable.'
                );
            }
        }
    }

    public static function refusedFilterProvider(): array
    {
        return [
            'misspelled status' => ['status=OPENN', 'status'],
            'status outside the enum' => ['status=REJECTED_BY_SOMEBODY', 'status'],
            'malformed from' => ['from=not-a-date', 'from'],
            'impossible to' => ['to=2026-13-45', 'to'],
            'currency too short' => ['currency=IR', 'currency'],
            'currency with a digit' => ['currency=IR1', 'currency'],
            'range ending before it starts' => ['from=2026-10-04&to=2026-10-01', 'from'],
        ];
    }

    /** A refused filter must not smuggle the unfiltered answer out alongside the error. */
    public function testARefusedFilterCarriesNoRows(): void
    {
        $response = $this->client->request('GET', '/ocs/v2.php/apps/pettycash/api/v1/sheets?status=OPENN');
        $data = $this->dataOf($response);

        $this->assertArrayNotHasKey(
            'items',
            $data,
            'A refused filter must not carry a page of sheets beside the error.'
        );
        $this->assertArrayNotHasKey('total', $data, 'A refused filter must not report a count.');
    }

    /**
     * FR-064 resolves "creator" to the sheet's owner, which makes it the *scope* of this list rather than
     * one of its predicates — so naming the caller is accepted, and naming anybody else is a 403.
     *
     * A silent widening is the outcome to refuse: a purchaser able to list another user's sheets through a
     * filter has a data leak that an empty page would have disguised.
     */
    public function testOwnerIdNamesTheScopeSoNamingSomebodyElseIsRefused(): void
    {
        ContractFixtures::prepare('draft');
        $baseline = $this->allSheets('');

        $mine = $this->allSheets('ownerId=' . rawurlencode($this->actingUser()));
        $this->assertSame(
            $baseline['total'],
            $mine['total'],
            'Naming the authenticated user must not change the scope of the list.'
        );

        $response = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/sheets?ownerId=' . rawurlencode('pettycash-somebody-else')
        );

        $this->assertEquals(
            403,
            $response->getStatusCode(),
            'Naming another user must be refused, not answered with an empty page.'
        );
    }
}