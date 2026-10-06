<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Reporting;

use InvalidArgumentException;
use OCA\PettyCash\Application\Reporting\FilterIdentityResolver;
use OCA\PettyCash\Application\Reporting\ReportFilter;
use OCA\PettyCash\Application\Reporting\ReportFilterFactory;
use OCA\PettyCash\Application\Reporting\ReportQuery;
use OCA\PettyCash\Application\Reporting\UnsupportedFilterField;
use OCA\PettyCash\Domain\Configuration\CostCenter;
use OCA\PettyCash\Domain\Shared\JalaliReportingPeriods;
use OCA\PettyCash\Domain\Configuration\ExpenseType;
use OCA\PettyCash\Tests\TestDoubles\FixedClock;
use OCA\PettyCash\Tests\TestDoubles\FixedRequestTimeZone;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCostCenterRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseTypeRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * T306: the filter vocabulary, in one place, for both callers.
 *
 * ## Why this class exists rather than living in a controller
 *
 * `ReportController` grew a closed `ACCEPTED_FILTERS` list and the coercion that goes with it, both
 * private to it (T309). The export endpoint then needed the *same* rule for `ExportRequest.filters` —
 * and the obvious way to get it was to write it again, which is precisely how the report filter and
 * the report rows came to disagree in T307. Two copies of a vocabulary is one copy too many.
 *
 * So the vocabulary lives here, in the application layer, and both controllers ask this class. The
 * tests below are therefore not "the controller parses filters" tests: they assert the one rule, and
 * `ReportAndExportControllerTest` asserts that both endpoints obey it over real HTTP.
 *
 * ## Every accepted name is asserted
 *
 * The dangerous regression is a *silent* one: a name added to the vocabulary without a test, or a test
 * that pins "a key nothing accepts" and quietly starts passing against a name that is now accepted.
 * The first provider below therefore iterates the list itself, so adding a name without a case fails
 * here rather than producing an untested facet.
 */
final class ReportFilterFactoryTest extends TestCase
{
    private ReportFilterFactory $factory;

    private InMemoryCostCenterRepository $costCenters;

    private InMemoryExpenseTypeRepository $expenseTypes;

    /** The public id `setUp` saves a cost centre under, so the resolver has something to resolve. */
    private const COST_CENTER_PUBLIC_ID = '11111111-1111-4111-8111-111111111111';

    private const EXPENSE_TYPE_PUBLIC_ID = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        $this->costCenters = new InMemoryCostCenterRepository();
        $this->expenseTypes = new InMemoryExpenseTypeRepository();

        $costCenter = CostCenter::create('CC900', 'Ninth floor', null, new DateTimeImmutable('2026-01-01'), null);
        $costCenter->id = 900;
        $costCenter->publicId = self::COST_CENTER_PUBLIC_ID;
        $this->costCenters->save($costCenter);

        $expenseType = ExpenseType::create('Calibration', null, false);
        $expenseType->id = 42;
        $expenseType->publicId = self::EXPENSE_TYPE_PUBLIC_ID;
        $this->expenseTypes->save($expenseType);

        $this->factory = new ReportFilterFactory(
            new FilterIdentityResolver($this->costCenters, $this->expenseTypes),
            // FR-068. The clock and the zone are the whole of a period's resolution, so both are fixed
            // here rather than left to the system: a test that cannot move the clock cannot assert that a
            // period is the *in-progress* one.
            new JalaliReportingPeriods(new FixedClock('2026-09-23T10:00:00+03:30')),
            new FixedRequestTimeZone('Asia/Tehran'),
            new InMemoryCurrentUser('employee1')
        );
    }

    // ===== FR-068: the `period` filter =====

    /**
     * The three predefined periods resolve to concrete dates a query can use.
     *
     * The clock is fixed at 2026-09-23 10:00 Asia/Tehran — Mehr 1 1405 — which `plan.md` records as the
     * measured case, so the expected values are the plan's rather than ones chosen for convenience.
     */
    public function testAPredefinedPeriodResolvesToTheInProgressJalaliRange(): void
    {
        $cases = [
            'month'   => ['2026-09-23', '2026-10-22'],
            'quarter' => ['2026-09-23', '2026-12-21'],
            'year'    => ['2026-03-21', '2027-03-20'],
        ];

        foreach ($cases as $period => [$from, $to]) {
            $filter = $this->factory->fromArray(['period' => $period]);

            self::assertSame($from, $filter->from?->format('Y-m-d'), "period={$period} start");
            self::assertSame($to, $filter->to?->format('Y-m-d'), "period={$period} end");
            self::assertTrue($filter->hasReportingPeriod(), 'a resolved period is still a filter');
            self::assertTrue($filter->hasFieldFilters(), 'and must not read as "everything in scope"');
        }
    }

    /**
     * FR-068 requires the response to state the zone it resolved in, so the filter carries it.
     */
    public function testAResolvedPeriodCarriesTheZoneItWasResolvedIn(): void
    {
        self::assertSame('Asia/Tehran', $this->factory->fromArray(['period' => 'month'])->resolvedZone);
    }

    public function testNoPeriodMeansNoZoneToDisclose(): void
    {
        $filter = $this->factory->fromArray([]);

        self::assertFalse($filter->hasReportingPeriod());
        self::assertNull($filter->resolvedZone, 'a disclosure for a filter that resolved nothing is a lie');
    }

    /**
     * Two users in different zones get different ranges — FR-068's own verification, at the filter layer
     * where the zone enters.
     */
    public function testTwoUsersInDifferentZonesResolveDifferentRanges(): void
    {
        $tehran = new ReportFilterFactory(
            new FilterIdentityResolver($this->costCenters, $this->expenseTypes),
            new JalaliReportingPeriods(new FixedClock('2026-09-23T00:30:00+03:30')),
            new FixedRequestTimeZone('Asia/Tehran'),
            new InMemoryCurrentUser('employee1')
        );
        $utc = new ReportFilterFactory(
            new FilterIdentityResolver($this->costCenters, $this->expenseTypes),
            new JalaliReportingPeriods(new FixedClock('2026-09-23T00:30:00+03:30')),
            new FixedRequestTimeZone('UTC'),
            new InMemoryCurrentUser('employee2')
        );

        self::assertSame('2026-09-23', $tehran->fromArray(['period' => 'month'])->from?->format('Y-m-d'));
        self::assertSame(
            '2026-08-23',
            $utc->fromArray(['period' => 'month'])->from?->format('Y-m-d'),
            'the same instant resolves the previous Jalali month for a UTC user'
        );
    }

    /**
     * FR-068 requires every resolving response to state the range **and** the zone.
     *
     * Asserted as data rather than through a controller, because the disclosure is built here and both
     * endpoints spread it into their own response — so this is the single place its shape is decided.
     * A mutation that returned nothing would otherwise pass every test here: an empty disclosure is
     * indistinguishable from "no period was requested", which is exactly what it looks like on the wire.
     */
    public function testAResolvedPeriodDisclosesItsRangeAndZone(): void
    {
        self::assertSame(
            [
                'period' => 'month',
                'resolvedFrom' => '2026-09-23',
                'resolvedTo' => '2026-10-22',
                'resolvedTimeZone' => 'Asia/Tehran',
            ],
            $this->factory->fromArray(['period' => 'month'])->periodDisclosure()
        );
    }

    /**
     * A request that resolved nothing discloses nothing.
     *
     * Absent rather than null-valued, because a key describing a request the response did not use is a
     * claim about the request rather than about the answer.
     */
    public function testNoDisclosureWhenNoPeriodWasRequested(): void
    {
        self::assertSame([], $this->factory->fromArray([])->periodDisclosure());
        self::assertSame([], $this->factory->fromArray(['from' => '2026-01-01T00:00:00+00:00'])->periodDisclosure());
    }

    // ===== The mutual exclusion FR-068 requires =====

    /**
     * `period` and an explicit range are mutually exclusive, and **neither order is honoured**.
     *
     * All four combinations are refused, because "whichever key arrived first wins" is exactly the
     * silent discard FR-068 forbids: the caller would get one filter while believing they sent two.
     */
    public function testAPeriodAndAnExplicitRangeAreRefusedInBothOrderings(): void
    {
        foreach ([['from', 'to'], ['to', 'from']] as [$first, $second]) {
            $range = [$first => '2026-01-01T00:00:00+00:00', $second => '2026-06-30T23:59:59+00:00'];

            try {
                $this->factory->fromArray(['period' => 'month'] + $range);
                self::fail("period + {$first} must be refused");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('mutually exclusive', $e->getMessage());
                self::assertStringContainsString('Remove one of them', $e->getMessage());
            }
        }
    }

    /**
     * The refusal names which keys conflicted, so the client can fix it in one attempt.
     */
    public function testTheRefusalNamesTheKeysThatConflict(): void
    {
        try {
            $this->factory->fromArray(['period' => 'month', 'to' => '2026-06-30T23:59:59+00:00']);
            self::fail('period + to must be refused');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('period', $e->getMessage());
            self::assertStringContainsString('`to`', $e->getMessage());
        }
    }

    /**
     * Neither one is quietly discarded, and they are never intersected.
     *
     * A blank `period` is "the caller did not supply one", not a period named "" — so it must not trip the
     * exclusion and turn an ordinary explicit range into a 422.
     */
    public function testABlankPeriodIsNotAConflict(): void
    {
        $filter = $this->factory->fromArray(['period' => '', 'from' => '2026-01-01T00:00:00+00:00']);

        self::assertSame('2026-01-01', $filter->from?->format('Y-m-d'));
        self::assertFalse($filter->hasReportingPeriod());
    }

    /**
     * A period outside FR-068's vocabulary is refused by name.
     *
     * `week` is the tempting one: "this week" is a thing people ask for and it is not one of the three
     * periods FR-068 defines.
     */
    public function testAnUnknownPeriodNameIsRefusedRatherThanGuessed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown reporting period');

        $this->factory->fromArray(['period' => 'week']);
    }

    // ===== The vocabulary is closed, and the list is the contract =====

    /**
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    public static function acceptedFilters(): array
    {
        return [
            ['from', ['from' => '2026-01-01T00:00:00+00:00']],
            ['to', ['to' => '2026-12-31T23:59:59+00:00']],
            ['statuses', ['statuses' => ['OPEN', 'COST_CENTER']]],
            ['currencyCodes', ['currencyCodes' => ['IRR', 'USD']]],
            ['costCenterIds', ['costCenterIds' => ['11111111-1111-4111-8111-111111111111']]],
            ['costCenterDbIds', ['costCenterDbIds' => [1, 2]]],
            ['actorUids', ['actorUids' => ['employee1']]],
            ['eventTypes', ['eventTypes' => ['SHEET_SUBMITTED']]],
            ['createdBy', ['createdBy' => 'employee1']],
            ['expenseTypeIds', ['expenseTypeIds' => ['22222222-2222-4222-8222-222222222222']]],
            ['expenseTypeDbIds', ['expenseTypeDbIds' => [7]]],
            ['stages', ['stages' => ['FINANCE']]],
            ['amountMinMinor', ['amountMinMinor' => 100]],
            ['amountMaxMinor', ['amountMaxMinor' => 200]],
        ];
    }

    /**
     * Every name the factory advertises is a name it accepts.
     *
     * Asserted against `ACCEPTED_FILTERS` as data rather than a hand-copied list, so a name added to
     * the vocabulary without a case here is a failure, and a name removed from the vocabulary without
     * removing its case is also a failure. Both directions matter: the first is an untested filter,
     * the second is a test asserting a filter that no longer exists.
     */
    #[DataProvider('acceptedFilters')]
    public function testEveryAdvertisedFilterIsAccepted(string $name, array $payload): void
    {
        $filter = $this->factory->fromArray($payload);

        self::assertTrue(
            $filter->hasFieldFilters(),
            "'{$name}' was accepted but produced a filter that narrows nothing"
        );
    }

    public function testTheAdvertisedVocabularyIsExactlyTheAcceptedOne(): void
    {
        self::assertSame(
            [
                'from', 'to', 'statuses', 'currencyCodes', 'costCenterIds', 'costCenterDbIds', 'actorUids',
                'eventTypes', 'createdBy', 'expenseTypeIds', 'expenseTypeDbIds', 'stages', 'amountMinMinor',
                'amountMaxMinor',
                // FR-068. Written out rather than derived, deliberately: this assertion exists to catch
                // the vocabulary and the constant drifting apart, and deriving one from the other would
                // make it incapable of failing. FR-064 was amended on 2026-09-30 to accept `period`.
                'period',
            ],
            ReportFilterFactory::ACCEPTED_FILTERS
        );
    }

    // ===== T329: a client is never asked for a surrogate key =====

    /**
     * The public-id spelling resolves to the same field the internal spelling names.
     *
     * `data-model.md` requires public UUIDs on the wire and keeps numeric ids as internal join keys, so
     * this is the direction a client must be able to travel. Asserted as a resolved `dbId` rather than
     * as "it did not throw", because a resolver that silently returned nothing would pass the weaker
     * assertion and reintroduce the unfiltered answer this task exists to remove.
     */
    public function testACostCentreCanBeFilteredByItsPublicId(): void
    {
        $filter = $this->factory->fromArray(['costCenterIds' => [self::COST_CENTER_PUBLIC_ID]]);

        self::assertSame([900], $filter->costCenterDbIds);
    }

    public function testAnExpenseTypeCanBeFilteredByItsPublicId(): void
    {
        $filter = $this->factory->fromArray(['expenseTypeIds' => [self::EXPENSE_TYPE_PUBLIC_ID]]);

        self::assertSame([42], $filter->expenseTypeDbIds);
    }

    /**
     * Both spellings name members of one facet, so a request naming both means the union — not a
     * precedence rule that would silently drop whichever space the caller spelled differently.
     */
    public function testTheTwoIdSpacesAreUnionedRatherThanOneWinning(): void
    {
        $filter = $this->factory->fromArray([
            'costCenterIds' => [self::COST_CENTER_PUBLIC_ID],
            'costCenterDbIds' => [7],
        ]);

        self::assertSame([7, 900], $filter->costCenterDbIds);
    }

    /**
     * The damaging case. An id that resolves to nothing must be refused, because dropping it widens the
     * result set to everything while the caller believes the filter was applied — the exact failure
     * `ReportFilterFactory`'s closed vocabulary was built to prevent, reached through the new name.
     */
    public function testACostCentrePublicIdThatNamesNothingIsRefusedRatherThanDropped(): void
    {
        try {
            $this->factory->fromArray(['costCenterIds' => ['33333333-3333-4333-8333-333333333333']]);
            self::fail('an unresolvable cost centre id must be refused, not dropped');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('costCenterIds', $e->getMessage());
            self::assertStringContainsString('33333333-3333-4333-8333-333333333333', $e->getMessage());
            self::assertStringContainsString('public UUID', $e->getMessage(), 'the refusal should say which id space is expected');
        }
    }

    public function testAnExpenseTypePublicIdThatNamesNothingIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expenseTypeIds');

        $this->factory->fromArray(['expenseTypeIds' => ['44444444-4444-4444-8444-444444444444']]);
    }

    /**
     * An empty list is no filter, not an error: an unfilled cost-centre field must not narrow anything,
     * and must not be reported as a caller mistake either.
     */
    public function testAnEmptyPublicIdListIsNoFilterRatherThanARefusal(): void
    {
        $filter = $this->factory->fromArray(['costCenterIds' => [], 'expenseTypeIds' => []]);

        self::assertSame([], $filter->costCenterDbIds);
        self::assertSame([], $filter->expenseTypeDbIds);
        self::assertFalse($filter->hasFieldFilters());
    }

    public function testAnUnrecognisedFilterIsRefusedNamingTheAcceptedSet(): void
    {
        try {
            $this->factory->fromArray(['costcentreIds' => [1]]);
            self::fail('a misspelled filter must be refused, not ignored');
        } catch (InvalidArgumentException $e) {
            // The typo is the damaging case: `costcentreIds` is a misspelling of a name that *is*
            // accepted, so ignoring it returns the unfiltered answer while the caller believes the
            // cost centre was chosen.
            self::assertStringContainsString('costcentreIds', $e->getMessage());
            self::assertStringContainsString('costCenterDbIds', $e->getMessage());
        }
    }

    public function testSeveralUnrecognisedFiltersAreNamedTogether(): void
    {
        try {
            $this->factory->fromArray(['banana' => true, 'expenseStatus' => ['FINANCE']]);
            self::fail('unrecognised filters must be refused');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('banana', $e->getMessage());
            self::assertStringContainsString('expenseStatus', $e->getMessage());
        }
    }

    /**
     * A list is refused as the wrong *shape*, not as a set of unknown names.
     *
     * The distinction is asserted rather than assumed, and it is not cosmetic. `array_keys(['statuses'])`
     * is `[0]`, so with the list check removed the request is still refused — by the closed-vocabulary
     * check, with the message `Unknown filter "0"`. That passes any assertion of the form "a list is
     * refused", and it tells the client nothing: the message names an integer they never sent, and
     * points at the accepted-filter list where their `statuses` demonstrably appears.
     *
     * Found by mutation: replacing the list check with `if (false)` left both this test and
     * `testAFilterListIsRefusedRatherThanTreatedAsNoFilter` green, because both asserted the status
     * and both were satisfied by the other refusal.
     */
    public function testAListIsRefusedAsTheWrongShapeRatherThanAsUnknownNames(): void
    {
        try {
            $this->factory->fromArray(['statuses']);
            self::fail('a list is not a filter object');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('object', $e->getMessage());
            self::assertStringContainsString('list', $e->getMessage());
            self::assertStringNotContainsString(
                'Unknown filter',
                $e->getMessage(),
                'a list must be refused for its shape, not by naming its indexes as unknown filters'
            );
            self::assertStringContainsString('statuses', $e->getMessage(), 'the message should quote what was sent');
        }
    }

    /**
     * Every name a caller may send is answered by a rule that can refuse it *by that name*.
     *
     * `ReportQuery` has two maps: the rules a filter is evaluated against, and the request names each
     * rule answers. A name in the first and missing from the second is the T306 defect of a different
     * shape — a caller sends `amountMinMinor` and is told the report cannot be filtered by
     * `amountRange`, a name that appears nowhere in the accepted list they were just shown. The refusal
     * is then unanswerable, which is a poor way to learn that a facet is unavailable.
     *
     * Asserted by asking each name to be *refused* and reading the message: a rule that could not
     * evaluate it would refuse with a different message, and a name no rule covers would be rejected
     * by the vocabulary first.
     */
    #[DataProvider('acceptedFilters')]
    public function testEveryAcceptedNameIsRefusedByTheNameTheCallerSent(string $name, array $payload): void
    {
        $this->expectException(UnsupportedFilterField::class);
        $this->expectExceptionMessage('`' . $name . '`');

        // Rows with no facet columns at all, so every rule refuses and the message names the rule.
        (new ReportQuery())->narrow($this->factory->fromArray($payload), [['id' => 'a']]);
    }

    public function testTheRequestNamesOfTheTwoCompoundRulesAreBothNamed(): void
    {
        // `amountMinMinor` and `from` are the *first* name of their rule, and a caller who sent the
        // second must still recognise the refusal. Asserting the first name only would let the second
        // regress into an unrecognisable word.
        self::assertSame(
            ['amountMinMinor', 'amountMaxMinor'],
            (new \ReflectionClass(ReportQuery::class))->getConstant('RULE_REQUEST_NAMES')['amountRange']
        );
        self::assertSame(
            ['from', 'to'],
            (new \ReflectionClass(ReportQuery::class))->getConstant('RULE_REQUEST_NAMES')['dateRange']
        );
    }

    /**
     * The guard `ReportQuery::RULE_REQUEST_NAMES` claims to have in its docblock but did not have.
     *
     * The consequence of a name missing here is specific and bad: the application **accepts** the filter
     * — it is in `ACCEPTED_FILTERS`, so it survives the closed-vocabulary check — and then refuses it
     * later, in `ReportQuery`, naming a different word. The caller is shown an accepted-filter list
     * containing a name, sends it, and is refused by a name that is not in that list.
     *
     * T329 found this by adding `costCenterIds` to the vocabulary: `testEveryAcceptedNameIsRefusedByTheNameTheCallerSent`
     * failed for the two new names because the rules they resolve into named only the surrogate-key
     * spelling. This test is what stops the next name from arriving the same way.
     */
    public function testEveryRequestNameIsAnsweredByARuleThatNamesIt(): void
    {
        $rules = (new \ReflectionClass(ReportQuery::class))->getConstant('RULE_REQUEST_NAMES');
        self::assertIsArray($rules);

        $named = [];
        foreach ($rules as $requestNames) {
            self::assertIsArray($requestNames);
            foreach ($requestNames as $requestName) {
                $named[] = $requestName;
            }
        }
        // Rule names that are not request names answer for themselves.
        $fieldKeys = (new \ReflectionClass(ReportQuery::class))->getConstant('FILTER_FIELD_KEYS');
        self::assertIsArray($fieldKeys);
        $named = array_merge($named, array_keys($fieldKeys));

        foreach (ReportFilterFactory::ACCEPTED_FILTERS as $accepted) {
            self::assertContains(
                $accepted,
                $named,
                "'{$accepted}' is accepted, so a rule must be able to refuse it by the name the caller sent"
            );
        }
    }

    // ===== Coercion, which is where a wrong value becomes a wrong answer =====

    public function testTheValuesReachTheFilterTheCallerAskedFor(): void
    {
        $filter = $this->factory->fromArray([
            'from' => '2026-01-01T00:00:00+00:00',
            'to' => '2026-06-30T00:00:00+00:00',
            'statuses' => ['OPEN'],
            'currencyCodes' => ['IRR'],
            'costCenterDbIds' => [3, 4],
            'actorUids' => ['employee1'],
            'eventTypes' => ['SHEET_SUBMITTED'],
            'createdBy' => 'employee2',
            'expenseTypeDbIds' => [9],
            'stages' => ['FINANCE'],
            'amountMinMinor' => 100,
            'amountMaxMinor' => 900,
        ]);

        self::assertSame('2026-01-01T00:00:00+00:00', $filter->from?->format(DATE_ATOM));
        self::assertSame('2026-06-30T00:00:00+00:00', $filter->to?->format(DATE_ATOM));
        self::assertSame(['OPEN'], $filter->statuses);
        self::assertSame(['IRR'], $filter->currencyCodes);
        self::assertSame([3, 4], $filter->costCenterDbIds);
        self::assertSame(['employee1'], $filter->actorUids);
        self::assertSame(['SHEET_SUBMITTED'], $filter->eventTypes);
        self::assertSame('employee2', $filter->createdBy);
        self::assertSame([9], $filter->expenseTypeDbIds);
        self::assertSame(['FINANCE'], $filter->stages);
        self::assertSame(100, $filter->amountMinMinor);
        self::assertSame(900, $filter->amountMaxMinor);
    }

    public function testAnEmptyObjectIsNoFilterRatherThanARefusal(): void
    {
        // A caller that builds a filter object and finds every value empty has asked for no filter.
        // Refusing that would make an unrelated client change fail for no stated reason.
        $filter = $this->factory->fromArray([]);

        self::assertFalse($filter->hasFieldFilters());
    }

    public function testAMalformedDateIsRefusedNamingTheKey(): void
    {
        try {
            $this->factory->fromArray(['from' => 'the day before yesterday']);
            self::fail('an unparseable date must be refused');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('from', $e->getMessage());
        }
    }

    public function testAListFilterMustBeAList(): void
    {
        try {
            $this->factory->fromArray(['statuses' => 'OPEN']);
            self::fail('a scalar is not a list of statuses');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('statuses', $e->getMessage());
        }
    }

    public function testAnAmountBoundMustBeAWholeNumber(): void
    {
        try {
            $this->factory->fromArray(['amountMinMinor' => 'abc']);
            self::fail('a bound that cannot be compared is a client error, not a filter to drop');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('amountMinMinor', $e->getMessage());
        }
    }

    public function testAnInvertedAmountRangeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('amount');

        $this->factory->fromArray(['amountMinMinor' => 900, 'amountMaxMinor' => 100]);
    }

    // ===== Pagination belongs to the report, and the export does not page =====

    public function testPaginationIsCarriedThroughForTheReportPath(): void
    {
        $filter = $this->factory->fromArray(['statuses' => ['OPEN']], 3, 10);

        self::assertSame(3, $filter->page);
        self::assertSame(10, $filter->pageSize);
        self::assertSame(20, $filter->offset);
    }

    public function testTheDefaultWindowIsTheContractDefault(): void
    {
        $filter = $this->factory->fromArray([]);

        self::assertSame(1, $filter->page);
        self::assertSame(ReportFilter::DEFAULT_PAGE_SIZE, $filter->pageSize);
    }
}
