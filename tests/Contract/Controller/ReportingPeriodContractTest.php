<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * T373: FR-068's predefined Jalali periods over the **live** OCS surface.
 *
 * ## Why these exist separately from the unit tests
 *
 * T312 built `JalaliReportingPeriods`, `TimeZonePreference` and the `ReportFilterFactory` arm, and its
 * own verification clause said *"Assertions belong on the live endpoint the way every other T306 facet
 * is asserted."* It was closed on unit coverage alone, so the path that matters was unproven:
 * a query-parameter `filters` JSON string → the factory → a resolved range → a disclosure on the wire.
 * Every step between those two had a passing test; the wiring between them had none.
 *
 * ## The assertion that earns this file
 *
 * `testAPeriodSelectsExactlyTheRowsItsOwnDisclosureNames` sends `period=month` and then sends
 * `from=<resolvedFrom>&to=<resolvedTo>` — the range the server itself disclosed — and asserts the two
 * return **identical rows**.
 *
 * That is the whole requirement in one comparison. A period that resolves correctly but filters
 * differently, or a disclosure that describes a range the query did not use, fails it: the two answers
 * disagree, and one of them is a wrong financial answer. Asserting the disclosure's *shape* instead
 * would pass against an implementation that reported a plausible range and filtered by another.
 *
 * ## Why nothing here depends on the date the suite runs
 *
 * The period is resolved "in progress at the clock's current instant", so a hard-coded expected range
 * would be correct only on the day it was written. Instead the assertions are **relational**: the
 * resolved range must bracket today *in the disclosed zone*, and must span the length of a Jalali month,
 * quarter or year. A server stuck on a fixed date, or resolving in the wrong zone, fails both.
 */
final class ReportingPeriodContractTest extends TestCase
{
    private const FIXTURE_SHEET_ID = '55555555-5555-4555-8555-555555555555';

    /** The three periods `ReportingPeriod::NAMES` accepts, and their expected inclusive day spans. */
    private const PERIODS = [
        'month' => [28, 31],
        'quarter' => [89, 92],
        'year' => [365, 366],
    ];

    /**
     * The zone `testThePeriodResolvesInTheUsersOwnConfiguredZone` gives the acting user.
     *
     * Chosen because it is the app's own locale, because it is **not** UTC, and because it is a zone
     * *name* rather than an offset — the last being FR-068's point about telling two users' periods
     * apart. It is a constant rather than a discovered value so that a run which fails to establish it
     * fails loudly, instead of quietly adopting whatever zone the instance already had (which is how
     * the clause went unproven in the first place).
     */
    private const USER_ZONE = 'Asia/Tehran';

    private Client $client;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
        ContractFixtures::prepare('submitted');
    }

    // ===== GET /reports/{reportType} =====

    /**
     * FR-068: the report resolves a period and **discloses** it — its name, the range it resolved to, and
     * the zone it resolved it in.
     *
     * The disclosure is inside `summary`, per the contract's `ReportPage`, so it is asserted there
     * rather than beside it.
     */
    public function testAReportDisclosesThePeriodItResolved(): void
    {
        $data = $this->report('/reports/sheets', ['period' => 'month'])['data'];

        $this->assertArrayHasKey('summary', $data, 'the disclosure rides in ReportPage.summary');
        $summary = $data['summary'];

        $this->assertSame('month', $summary['period'] ?? null, 'the resolved period name');
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}$/',
            (string) ($summary['resolvedFrom'] ?? ''),
            'resolvedFrom is a date'
        );
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}$/',
            (string) ($summary['resolvedTo'] ?? ''),
            'resolvedTo is a date'
        );
        $this->assertNotSame('', (string) ($summary['resolvedTimeZone'] ?? ''), 'the zone is disclosed');

        // The zone is a **name**, not a UTC offset. An offset cannot say which zone two users were in,
        // which is the entire reason FR-068 requires it -- Asia/Tehran and Asia/Dubai share +03:30 and
        // resolve a period's boundary on different days.
        $this->assertDoesNotMatchRegularExpression(
            '/^[+-]\d{2}:?\d{2}$/',
            (string) $summary['resolvedTimeZone'],
            'the disclosure states a zone name, not an offset'
        );
    }

    /**
     * The resolved range is the **in-progress** one, in the disclosed zone.
     *
     * Written relationally on purpose: a fixed expected range would pass only on the day it was
     * authored. This asserts the property FR-068 states -- the period currently in progress -- by
     * requiring the range to bracket today as the server itself places today.
     */
    public function testTheResolvedRangeIsThePeriodInProgress(): void
    {
        foreach (array_keys(self::PERIODS) as $name) {
            $summary = $this->report('/reports/sheets', ['period' => $name])['data']['summary'];

            $zone = new \DateTimeZone((string) $summary['resolvedTimeZone']);
            $from = new \DateTimeImmutable((string) $summary['resolvedFrom'], $zone);
            $to = new \DateTimeImmutable((string) $summary['resolvedTo'], $zone);
            $today = new \DateTimeImmutable('today', $zone);

            $this->assertLessThanOrEqual(
                $today->format('Y-m-d'),
                $from->format('Y-m-d'),
                "the {$name} period must start on or before today"
            );
            $this->assertGreaterThanOrEqual(
                $today->format('Y-m-d'),
                $to->format('Y-m-d'),
                "the {$name} period must end on or after today"
            );

            [$shortest, $longest] = self::PERIODS[$name];
            $days = (int) $from->diff($to)->days + 1;   // both endpoints inclusive
            $this->assertGreaterThanOrEqual(
                $shortest,
                $days,
                "a Jalali {$name} is at least {$shortest} inclusive days"
            );
            $this->assertLessThanOrEqual(
                $longest,
                $days,
                "a Jalali {$name} is at most {$longest} inclusive days"
            );
        }
    }

    /**
     * The comparison this file exists for: `period=month` and the range the server itself disclosed
     * must select **identical rows**.
     *
     * A period that resolves correctly but filters by something else, or a disclosure describing a range
     * the query never used, makes these two answers disagree — and one of them is a wrong answer about
     * money, presented with a confident date range beside it.
     */
    public function testAPeriodSelectsExactlyTheRowsItsOwnDisclosureNames(): void
    {
        foreach (array_keys(self::PERIODS) as $name) {
            $summary = $this->report('/reports/sheets', ['period' => $name])['data']['summary'];

            // `pageSize` is a **query parameter**, not a member of `filters` — putting it inside the
            // filter object earns a 422 from the closed vocabulary, which is the rule working correctly.
            // Both sides omit it so they share the contract's default page size and differ only in the
            // one dimension under test.
            $periodResponse = $this->report('/reports/sheets', ['period' => $name]);
            $this->assertSame(200, $periodResponse['status'], $periodResponse['message']);

            $rangeResponse = $this->report('/reports/sheets', [
                'from' => $summary['resolvedFrom'],
                'to' => $summary['resolvedTo'],
            ]);
            $this->assertSame(
                200,
                $rangeResponse['status'],
                "the range {$summary['resolvedFrom']}..{$summary['resolvedTo']} disclosed by period={$name} "
                . 'must itself be a requestable range'
            );

            // Asserted present before compared. An earlier version compared `$byRange['rows']` directly and
            // this range request was *not* returning 200, so both sides of the comparison were an undefined
            // key and `assertSame(null, null)` passed. PHPUnit reported it as four "Undefined array key"
            // warnings, which is how a vacuous assertion surfaced. An equivalence check is only evidence
            // when both sides are known to exist.
            $this->assertArrayHasKey('rows', $rangeResponse['data'], 'the range request must answer rows');

            $this->assertSame(
                $periodResponse['data']['rows'],
                $rangeResponse['data']['rows'],
                "period={$name} must select exactly the rows from {$summary['resolvedFrom']} to "
                . "{$summary['resolvedTo']} — the range it disclosed"
            );
            $this->assertSame(
                $periodResponse['data']['total'],
                $rangeResponse['data']['total'],
                "period={$name} and its disclosed range must agree on the row count"
            );
        }
    }

    /**
     * An explicit range is not a period and carries no disclosure.
     *
     * The contract makes the disclosure **absent** when no period was resolved, rather than present and
     * null: a key describing a request the response did not use is a claim about the request rather than
     * about the answer. A disclosure appearing here would be a claim the server never made.
     */
    public function testAnExplicitRangeCarriesNoPeriodDisclosure(): void
    {
        $summary = $this->report('/reports/sheets', [
            'from' => '2020-01-01T00:00:00+00:00',
            'to' => '2030-01-01T00:00:00+00:00',
        ])['data']['summary'];

        foreach (['period', 'resolvedFrom', 'resolvedTo', 'resolvedTimeZone'] as $key) {
            $this->assertArrayNotHasKey(
                $key,
                $summary,
                "'{$key}' describes a period this request did not use"
            );
        }
    }

    /**
     * An unfiltered report carries no disclosure either -- the baseline of the test above.
     *
     * Asserted on the four keys rather than on an empty `summary`, because an unfiltered report is not
     * summary-less: `rowsRead`/`rowsBoundedBy` are present on every sheets report. An earlier version of
     * this test asserted `[]` and failed on exactly that, which says nothing about the disclosure.
     */
    public function testAnUnfilteredReportCarriesNoPeriodDisclosure(): void
    {
        $summary = $this->report('/reports/sheets', [])['data']['summary'];

        foreach (['period', 'resolvedFrom', 'resolvedTo', 'resolvedTimeZone'] as $key) {
            $this->assertArrayNotHasKey($key, $summary, "'{$key}' must be absent when no period was requested");
        }
    }

    /**
     * A blank `period` is no period, not a malformed one.
     *
     * `isset($decoded['period'])` is true for `""`, so the exclusion check and the resolution branch
     * both have to treat it as absent. A request carrying `period=&from=...` is a legitimate explicit
     * range; refusing it would be the closed-vocabulary rule applied to a value that names nothing.
     */
    public function testABlankPeriodIsNoPeriodRatherThanAConflict(): void
    {
        $response = $this->report('/reports/sheets', ['period' => '', 'from' => '2020-01-01T00:00:00+00:00']);

        $this->assertSame(200, $response['status'], $response['message']);
        $this->assertStringNotContainsString(
            'mutually exclusive',
            $response['message'],
            'a blank period names no period, so it cannot conflict with a range'
        );
    }

    /**
     * A period outside FR-068's vocabulary is refused, naming the three that exist.
     *
     * Asserted over HTTP because `ReportingPeriod::NAMES` is a domain constant: nothing forces the
     * transport to pass it through, and a client is the only place that learns which values are legal.
     */
    public function testAPeriodOutsideTheVocabularyIsRefusedNamingTheAcceptedOnes(): void
    {
        $response = $this->report('/reports/sheets', ['period' => 'week']);

        $this->assertSame(422, $response['status']);
        $this->assertStringNotContainsString('Unknown filter', $response['message'], '`period` IS an accepted filter name');
        foreach (['month', 'quarter', 'year'] as $accepted) {
            $this->assertStringContainsString($accepted, $response['message'], "the refusal should name '{$accepted}'");
        }
    }

    // ===== FR-068's mutual exclusion =====

    /**
     * FR-068: a period and an explicit range are **mutually exclusive** within one request, and neither
     * may be honoured while the other is discarded.
     *
     * **Both key orders are checked, and that is the substance.** A caller may send `period` before or
     * after `from`/`to`, so an implementation that let whichever arrived first win would pass a
     * single-order assertion while silently discarding half the request. The refusal also has to *name*
     * the conflict: an error that says only "invalid filters" leaves the client unable to tell which of
     * its two filters to remove.
     *
     * @dataProvider conflictingPeriodAndRangeProvider
     */
    public function testAPeriodAndAnExplicitRangeAreRefusedInEitherOrder(array $filters, string $conflict): void
    {
        $response = $this->report('/reports/sheets', $filters);

        $this->assertSame(422, $response['status'], 'a period with a range must not resolve to a filtered report');
        $this->assertStringContainsString('mutually exclusive', $response['message']);
        $this->assertStringContainsString(
            'period',
            $response['message'],
            'the refusal should name the filter the caller has to remove'
        );
        $this->assertStringContainsString(
            $conflict,
            $response['message'],
            "the refusal should name '{$conflict}' specifically, so the client knows which key to drop"
        );
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function conflictingPeriodAndRangeProvider(): array
    {
        $range = '2020-01-01T00:00:00+00:00';

        return [
            'period then from' => [['period' => 'month', 'from' => $range], 'from'],
            'from then period' => [['from' => $range, 'period' => 'month'], 'from'],
            'period then to' => [['period' => 'month', 'to' => $range], 'to'],
            'to then period' => [['to' => $range, 'period' => 'month'], 'to'],
            'all three' => [['period' => 'month', 'from' => $range, 'to' => $range], 'from'],
        ];
    }

    // ===== POST /exports =====

    /**
     * The export path resolves and discloses the period too, and identically to the report path.
     *
     * The controller comment on `periodDisclosure()` says it lives in `ReportFilter` precisely so the
     * two endpoints state it the same way — *"two copies is how the two endpoints drift."* So this does
     * not merely assert the export discloses: it asserts the export's disclosure **equals** the report's
     * for the same request, which is the property that shared construction exists to provide.
     */
    public function testTheExportDisclosesTheSameResolvedRangeAsTheReport(): void
    {
        $reportSummary = $this->report('/reports/sheets', ['period' => 'quarter'])['data']['summary'];

        $export = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => [self::FIXTURE_SHEET_ID],
            'filters' => ['period' => 'quarter'],
        ], $this->commandId());

        $this->assertSame(200, $export['status'], $export['message']);
        foreach (['period', 'resolvedFrom', 'resolvedTo', 'resolvedTimeZone'] as $key) {
            $this->assertArrayHasKey($key, $export['data'], "the export discloses '{$key}'");
            $this->assertSame(
                $reportSummary[$key],
                $export['data'][$key],
                "'{$key}' must be identical across GET /reports and POST /exports for one request"
            );
        }
    }

    /**
     * The export applies the period as a filter, not merely as a disclosure.
     *
     * The same equivalence as the report path, on the export: an export whose artifact ignored the
     * period while its metadata announced one would produce a file whose numbers belong to a different
     * range than the one printed beside them.
     */
    public function testTheExportSelectsTheRowsItsDisclosureNames(): void
    {
        $period = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => [self::FIXTURE_SHEET_ID],
            'filters' => ['period' => 'month'],
        ], $this->commandId());

        $this->assertSame(200, $period['status'], $period['message']);

        $byRange = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => [self::FIXTURE_SHEET_ID],
            'filters' => [
                'from' => $period['data']['resolvedFrom'],
                'to' => $period['data']['resolvedTo'],
            ],
        ], $this->commandId());

        $this->assertSame(
            $period['data']['rowCount'],
            $byRange['data']['rowCount'],
            'period=month must select exactly the rows from the range it disclosed'
        );
    }

    /**
     * The exclusion holds on the export path as well.
     *
     * Checked here because `POST /exports` decodes `filters` from a JSON **body** while `GET /reports`
     * decodes it from a query-parameter JSON **string** — two transports, and the conflict rule has to
     * be the one rule rather than a per-endpoint copy.
     */
    public function testTheExportAlsoRefusesAPeriodBesideAnExplicitRange(): void
    {
        $response = $this->post('/exports', [
            'resource' => 'sheets',
            'fileFormat' => 'csv',
            'ids' => [self::FIXTURE_SHEET_ID],
            'filters' => [
                'period' => 'month',
                'from' => '2020-01-01T00:00:00+00:00',
            ],
        ], $this->commandId());

        $this->assertSame(422, $response['status']);
        $this->assertStringContainsString('mutually exclusive', $response['message']);
        $this->assertStringContainsString('from', $response['message']);
    }

    /**
     * FR-068's **fallback** clause: with no user zone, the instance's configured default is used.
     *
     * **This test covers only the fallback, and it skips on this instance — which is now correct rather
     * than a gap.** Measured for this instance: the acting user has no `core.timezone`, `config.php`
     * declares no `default_timezone`, and PHP's default is UTC — so the chain resolves to UTC and an
     * implementation that *hardcoded* UTC would be indistinguishable from a correct one here. A mutation
     * replacing the zone resolution with `new DateTimeZone('UTC')` left all 15 tests in this file green
     * when this test was written. That was measured, not assumed.
     *
     * The **user**-zone clause is no longer in that position:
     * {@see testThePeriodResolvesInTheUsersOwnConfiguredZone} establishes a real non-UTC zone for the
     * acting user and fails under the same mutation, so the requirement's user half is genuinely
     * covered. What remains unexercised here is the *fallback* — which needs a `config.php` that
     * declares a real zone, and this shared instance declares none. Writing one would move every user's
     * period resolution, including the other applications in this Nextcloud, so the assertion skips with
     * its reason stated rather than quietly passing.
     */
    public function testTheResolvedZoneIsTheInstancesConfiguredZone(): void
    {
        $configured = $this->instanceDefaultTimeZone();

        if ($configured === null || $configured === 'UTC') {
            $this->markTestSkipped(
                'This instance declares no `default_timezone`, so the FR-068 fallback resolves to PHP\'s UTC '
                . 'default and a hardcoded-UTC implementation is indistinguishable here. The user-zone '
                . 'clause is covered by testThePeriodResolvesInTheUsersOwnConfiguredZone, which does run.'
            );
        }

        $summary = $this->report('/reports/sheets', ['period' => 'month'])['data']['summary'];

        $this->assertSame(
            $configured,
            $summary['resolvedTimeZone'] ?? null,
            'the period must resolve in the zone the host configures, not a literal'
        );
    }

    /**
     * FR-068's **user-zone** clause, established rather than assumed.
     *
     * ## Why the test above cannot prove this
     *
     * `testTheResolvedZoneIsTheInstancesConfiguredZone` reads `config.php` and skips when the instance
     * default is UTC, because on this instance it is: the acting user states no `core.timezone`,
     * `config.php` declares no `default_timezone`, and PHP's default is UTC. With every reading of the
     * zone resolving to UTC, a controller that **hardcoded** UTC answers identically — which was
     * measured by mutating the zone resolution to `new DateTimeZone('UTC')` and watching every test in
     * this file stay green.
     *
     * So rather than skip, this test **creates** the condition the clause needs: it gives the acting
     * user a real non-UTC zone and asserts the server resolves in *that* zone. A hardcoded UTC now has
     * something to disagree with, and the mutation fails here.
     *
     * ## Why the preference is written through a subprocess
     *
     * The contract suites talk to a running Nextcloud over HTTP and never bootstrap the server
     * in-process — `tests/bootstrap.php` wires a lightweight container of in-memory doubles, so loading
     * `lib/base.php` here would replace the global server. `occ` is unusable in this container (`Call to
     * undefined method Symfony\Component\Console\Application::add()`). So the preference is written by
     * `tests/Contract/Fixture/user-timezone-fixture.php`, the same subprocess shape
     * `seed-contract-fixtures.php` uses, and through `IConfig` — **the same key
     * `NextcloudRequestTimeZone` reads**, because a fixture writing some other key would satisfy a broken
     * adapter and a correct one alike.
     *
     * ## What it touches, and what it puts back
     *
     * One user's `core.timezone`, restored in a `finally` to exactly the value found beforehand —
     * including "no preference stated", which is what this instance has. The shared `config.php` is never
     * written; changing `default_timezone` would move every user's periods, including the other
     * applications in this Nextcloud.
     *
     * `Asia/Tehran` is deliberate rather than arbitrary. It is the app's own locale, it is +03:30 so its
     * current local date differs from UTC's for part of every day, and it is **not** an offset — which is
     * the property FR-068 requires the disclosure to carry.
     */
    public function testThePeriodResolvesInTheUsersOwnConfiguredZone(): void
    {
        $original = $this->userTimeZone();

        try {
            $this->setUserTimeZone(self::USER_ZONE);

            // Read back rather than trusted: this is the value the *server* will consult, and a write
            // that silently did nothing would leave the assertion below passing against UTC — which is
            // the exact vacuity this test exists to remove.
            $this->assertSame(
                self::USER_ZONE,
                $this->userTimeZone(),
                'the fixture must have set the zone the server reads, or this test proves nothing'
            );

            $summary = $this->report('/reports/sheets', ['period' => 'month'])['data']['summary'];

            $this->assertSame(
                self::USER_ZONE,
                $summary['resolvedTimeZone'] ?? null,
                'FR-068 resolves the period in the requesting user\'s own zone, so the disclosure must name '
                . 'that zone and not the instance default or a literal'
            );

            // The disclosure's own consistency, now that the zone is not UTC. A disclosure whose name
            // and range both moved to UTC together is *internally* consistent, so the zone-name
            // assertion above is what carries this test; these two confirm the range is the in-progress
            // Jalali month as the user's own zone places today.
            $zone = new \DateTimeZone(self::USER_ZONE);
            $today = new \DateTimeImmutable('today', $zone);
            $from = new \DateTimeImmutable((string) $summary['resolvedFrom'], $zone);
            $to = new \DateTimeImmutable((string) $summary['resolvedTo'], $zone);

            $this->assertLessThanOrEqual(
                $today->format('Y-m-d'),
                $from->format('Y-m-d'),
                'the month must start on or before today in the user\'s own zone'
            );
            $this->assertGreaterThanOrEqual(
                $today->format('Y-m-d'),
                $to->format('Y-m-d'),
                'the month must end on or after today in the user\'s own zone'
            );
        } finally {
            // Restore, not "clear": a user who already had a zone must get it back, and one who had
            // none must get back *no preference* rather than a stored empty string.
            if ($original === '') {
                $this->clearUserTimeZone();
            } else {
                $this->setUserTimeZone($original);
            }
        }
    }

    // ===== The per-user zone fixture =====

    /**
     * The acting user's `core.timezone` as Nextcloud stores it — `''` when they state none.
     *
     * @see tests/Contract/Fixture/user-timezone-fixture.php for why this runs out of process
     */
    private function userTimeZone(): string
    {
        return $this->timeZoneFixture('read');
    }

    private function setUserTimeZone(string $zone): void
    {
        $this->timeZoneFixture('write', $zone);
    }

    private function clearUserTimeZone(): void
    {
        $this->timeZoneFixture('clear');
    }

    /**
     * Runs the fixture for the acting user and returns the `core.timezone` it reports.
     *
     * The fixture re-reads the preference after acting and prints what Nextcloud then returns, so a
     * failed write is visible here rather than surfacing later as a mysteriously passing timezone
     * assertion — which is exactly how the first version of this fixture failed, since
     * `OC\AllConfig::getUserValue()` requires a uid **string** and raised a `TypeError` that the
     * running server reported with no output and exit status 0.
     */
    private function timeZoneFixture(string $action, ?string $zone = null): string
    {
        $user = getenv('PETTYCASH_TEST_USER');
        if ($user === false || trim($user) === '') {
            throw new RuntimeException(
                'The FR-068 zone test needs PETTYCASH_TEST_USER. Run it through scripts/dev/phpunit.'
            );
        }

        $fixture = dirname(__DIR__) . '/Fixture/user-timezone-fixture.php';
        if (!is_file($fixture)) {
            throw new RuntimeException("The user-timezone fixture is missing at {$fixture}.");
        }

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' '
            . escapeshellarg($user) . ' ' . escapeshellarg($action)
            . ($zone === null ? '' : ' ' . escapeshellarg($zone)) . ' 2>&1';

        $output = shell_exec($command);
        if ($output === null || trim($output) === '') {
            throw new RuntimeException(
                "The user-timezone fixture produced no output for '{$action}'. It writes its reason to "
                . 'stderr, and an empty result means the subprocess did not run at all.'
            );
        }

        $decoded = json_decode($output, true);
        if (!is_array($decoded) || !array_key_exists('core.timezone', $decoded)) {
            throw new RuntimeException("The user-timezone fixture did not return JSON:\n" . $output);
        }

        return (string) $decoded['core.timezone'];
    }

    /**
     * The instance default as Nextcloud would read it, or null when nothing is configured.
     *
     * Read straight from `config.php` because the contract suites talk to a **running** Nextcloud over HTTP
     * and never bootstrap the server in-process, so `\OCP\Server::get(IConfig::class)` is not available
     * here — the same reason the fixture manifest is produced by a subprocess. Read-only: this test changes
     * no configuration, so it cannot leave the shared instance altered.
     */
    private function instanceDefaultTimeZone(): ?string
    {
        $configPath = '/var/www/html/config/config.php';
        if (!is_file($configPath)) {
            return null;
        }

        $config = require $configPath;
        if (!is_array($config)) {
            return null;
        }

        $value = $config['default_timezone'] ?? '';

        return is_string($value) && $value !== '' ? $value : null;
    }

    // ===== helpers =====

    /**
     * @param array<string, mixed> $filters
     * @return array{status: int, data: array<string, mixed>, message: string}
     */
    private function report(string $path, array $filters): array
    {
        $query = $filters === [] ? '' : '?filters=' . rawurlencode((string) json_encode($filters));

        return $this->decode($this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1' . $path . $query
        ));
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
     * @return array{status: int, data: array<string, mixed>, message: string}
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        $this->assertIsArray($decoded, 'The response body is a JSON OCS envelope');

        $data = $decoded['ocs']['data'] ?? [];

        // Nextcloud's OCS convention puts the human-readable detail in `data.error.message` and leaves
        // `meta.message` empty for these responses, so reading only `meta` yields an empty string for a
        // refusal that in fact explains itself. `meta.message` stays as the fallback because other error
        // shapes do populate it. Found by measurement: the first run of this file asserted 8 refusals'
        // wording against an empty string, because this helper read the wrong field.
        return [
            'status' => $response->getStatusCode(),
            'data' => $data,
            'message' => (string) ($data['error']['message'] ?? $decoded['ocs']['meta']['message'] ?? ''),
        ];
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
}
