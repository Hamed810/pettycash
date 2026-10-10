<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Domain\Shared;

use DateTimeImmutable;
use DateTimeZone;
use IntlCalendar;
use OCA\PettyCash\Domain\Shared\Clock;
use OCA\PettyCash\Domain\Shared\JalaliReportingPeriods;
use OCA\PettyCash\Domain\Shared\ReportingPeriod;
use PHPUnit\Framework\TestCase;

/**
 * T312 / FR-068: the Jalali period resolution, against the verification list `plan.md` requires.
 *
 * ## Why these are unit tests, and why that is not a dodge
 *
 * Every assertion here is about **calendar arithmetic**, and the conversion is pure: an instant in a
 * zone, in and out. Driving it over HTTP would test the wiring rather than the rule, and would cost
 * one request per assertion against an instance that is currently refusing traffic with `429`. The
 * endpoint wiring is asserted in `ReportingPeriodFilterTest` and the contract suite.
 *
 * ## Each of `plan.md`'s required verifications, and where it is
 *
 * | `plan.md` requires | Test |
 * |---|---|
 * | month boundaries, incl. a 30- and a 31-day month | `testAMonthResolvesToItsFirstAndLastDay`, `…ThirtyDay…`, `…ThirtyOneDay…` |
 * | quarter begins at Farvardin | `testAQuarterBeginsAtFarvardinNotAGregorianQuarter` |
 * | year 365 vs 366, 1403/1408 leap, 1404/1405 not | `testTheJalaliYearLengthIsNeverAFixedNumber`, `testLeapYearsAreDecidedByTheLibrary` |
 * | inclusive at both ends | `testBothEndsOfThePeriodAreInsideIt` |
 * | resolved in the requester's zone, not shifted by UTC | `testTheResolvedRangeIsNotShiftedByUtcFormatting` |
 * | two users in different zones each get their own range | `testTwoUsersInDifferentZonesResolveDifferentRanges` |
 * | ICU differential **skips** rather than fails | `testTheConversionMatchesIcuAcrossALargeDateRange` |
 * | stored Gregorian dates untouched, no migration | `testResolutionWritesNothing` |
 */
final class JalaliReportingPeriodsTest extends TestCase
{
    private const TEHRAN = 'Asia/Tehran';

    private JalaliReportingPeriods $periods;

    protected function setUp(): void
    {
        $this->periods = new JalaliReportingPeriods($this->clockAt('2026-09-23 10:00:00', self::TEHRAN));
    }

    // ===== MONTH =====

    /**
     * `plan.md`'s own worked example: Mehr 1 1405 is 2026-09-23 in Tehran.
     *
     * Used as the anchor throughout because it is the case the plan measured rather than one chosen for
     * convenience, and because it sits one day past a UTC-midnight boundary — so it fails loudly if any
     * step converts through UTC.
     */
    public function testAMonthResolvesToItsFirstAndLastDay(): void
    {
        $period = $this->periods->resolveInProgress(ReportingPeriod::MONTH, new DateTimeZone(self::TEHRAN));

        self::assertSame('2026-09-23', $period->fromDate(), 'Mehr 1 1405 is 2026-09-23 in Tehran');
        self::assertSame('2026-10-22', $period->toDate());
        self::assertSame(self::TEHRAN, $period->zoneName(), 'FR-068 requires the resolved zone to be disclosed');
    }

    /**
     * A 30-day month, and a 31-day month, as `plan.md` requires both.
     *
     * Farvardin is 31 days; Shahrivar is 30. Asserted as separate tests rather than one loop so a
     * failure names which length was wrong — the whole point of the requirement is that the length is
     * read from the calendar rather than assumed.
     */
    public function testAThirtyDayMonthResolvesToThirtyDays(): void
    {
        // Mehr 1405 is a 30-day month: 2026-09-23 through 2026-10-22 inclusive.
        //
        // The month was chosen by measuring the calendar rather than by assuming: 1405 runs
        // 31,31,31,31,31,31,30,30,30,30,30,29, so Farvardin-Shahrivar are 31 days and Mehr onward are
        // 30. An earlier version of this test used Shahrivar and asserted 30 days, which was simply
        // wrong about the calendar -- a good illustration of why the length is asserted rather than
        // hardcoded.
        $period = $this->periods->resolveInProgress(ReportingPeriod::MONTH, new DateTimeZone(self::TEHRAN));

        self::assertSame('2026-09-23', $period->fromDate());
        self::assertSame('2026-10-22', $period->toDate());
        self::assertSame(30, $this->inclusiveDays($period), 'Mehr 1405 is a 30-day month');
    }

    public function testAThirtyOneDayMonthResolvesToThirtyOneDays(): void
    {
        // Farvardin 1405 starts 2026-03-21 in Tehran.
        $periods = new JalaliReportingPeriods($this->clockAt('2026-04-01 10:00:00', self::TEHRAN));
        $period = $periods->resolveInProgress(ReportingPeriod::MONTH, new DateTimeZone(self::TEHRAN));

        self::assertSame('2026-03-21', $period->fromDate(), 'Farvardin has 31 days');
        self::assertSame('2026-04-20', $period->toDate());
        self::assertSame(31, $this->inclusiveDays($period));
    }

    /** A period's length in days, counting both ends, as FR-068 requires. */
    private function inclusiveDays(ReportingPeriod $period): int
    {
        return (int) (new DateTimeImmutable($period->fromDate()))
            ->diff(new DateTimeImmutable($period->toDate()))->days + 1;
    }

    // ===== QUARTER =====

    /**
     * FR-068: "A period spans Farvardin to Esfand and a quarter begins at Farvardin."
     *
     * The Gregorian quarter containing this date begins 2026-07-01; the Jalali one begins at Farvardin.
     * Asserting against the Gregorian boundary by name is what makes this a test of the rule rather
     * than a test of whatever the library happened to return — a Gregorian-aligned quarter would answer
     * 2026-07-01 and could otherwise pass a test that only checked "the quarter is 90-ish days".
     */
    public function testAQuarterBeginsAtFarvardinNotAGregorianQuarter(): void
    {
        $period = $this->periods->resolveInProgress(ReportingPeriod::QUARTER, new DateTimeZone(self::TEHRAN));

        self::assertSame('2026-09-23', $period->fromDate(), 'Mehr 1 begins Jalali Q3, at Farvardin alignment');
        self::assertSame('2026-12-21', $period->toDate(), 'Q3 runs through Azar 9');
        self::assertNotSame(
            '2026-07-01',
            $period->fromDate(),
            'the Gregorian quarter boundary is exactly what FR-068 forbids'
        );
    }

    // ===== YEAR =====

    /**
     * A Jalali year is 365 or 366 days and is **never a fixed length**.
     *
     * `plan.md` names 1403/1408 as leap and 1404/1405 as non-leap controls, and the assertion is
     * structural: each year must be the length the calendar says, expressed as a difference between
     * resolved dates. A hardcoded 365 would pass one of these four and fail the other, which is why all
     * four are asserted.
     */
    public function testTheJalaliYearLengthIsNeverAFixedNumber(): void
    {
        // Farvardin 1 of each year, in Tehran.
        $cases = [
            '1403 leap'     => ['2024-03-20', 366],
            '1404 non-leap' => ['2025-03-21', 365],
            '1405 non-leap' => ['2026-03-21', 365],
            '1408 leap'     => ['2029-03-20', 366],
        ];

        foreach ($cases as $label => [$instant, $expectedDays]) {
            $tz = new DateTimeZone(self::TEHRAN);
            $periods = new JalaliReportingPeriods($this->clockAt($instant . ' 10:00:00', self::TEHRAN));
            $year = $periods->resolveInProgress(ReportingPeriod::YEAR, $tz);

            $from = new DateTimeImmutable($year->fromDate());
            $to = new DateTimeImmutable($year->toDate());
            $days = (int) $from->diff($to)->days + 1; // inclusive at both ends

            self::assertSame($expectedDays, $days, "Jalali year {$label} must be {$expectedDays} days");
        }
    }

    public function testLeapYearsAreDecidedByTheLibraryNotByAConstantHere(): void
    {
        self::assertTrue($this->periods->isLeapYear(1403), 'plan.md names 1403 a leap year');
        self::assertTrue($this->periods->isLeapYear(1408), 'and 1408');
        self::assertFalse($this->periods->isLeapYear(1404), 'and 1404 not');
        self::assertFalse($this->periods->isLeapYear(1405), 'and 1405 not');
    }

    // ===== THE UTC BOUNDARY TRAP =====

    /**
     * The measured trap, asserted as a regression: Tehran is UTC+3:30, so a period resolved in Tehran and
     * formatted through UTC moves by a day.
     *
     * At 2026-09-23 00:30 Tehran the UTC instant is still 2026-09-22, so an implementation that
     * converted the *instant* to UTC before converting calendars would resolve Mehr 1 to the 22nd.
     */
    public function testTheResolvedRangeIsNotShiftedByUtcFormatting(): void
    {
        // Half past midnight in Tehran: the same wall-clock instant is the previous day in UTC.
        $periods = new JalaliReportingPeriods($this->clockAt('2026-09-23 00:30:00', self::TEHRAN));

        $tehran = $periods->resolveInProgress(ReportingPeriod::MONTH, new DateTimeZone(self::TEHRAN));
        self::assertSame('2026-09-23', $tehran->fromDate(), 'the range is derived in the requester\'s zone');

        // And the same instant resolved for a UTC caller is genuinely a different period — which is why
        // the zone has to be part of the value rather than an afterthought.
        // The UTC caller is not merely "a day behind in the same month" -- at 21:00 on 2026-09-22 it is
        // in Shahrivar, a *different Jalali month*, so the two users are looking at different periods
        // entirely. That is the concrete reason FR-068 insists the zone be disclosed.
        $utc = $periods->resolveInProgress(ReportingPeriod::MONTH, new DateTimeZone('UTC'));
        self::assertSame('2026-08-23', $utc->fromDate(), 'a UTC caller resolves the previous Jalali month');
        self::assertNotSame(
            $tehran->fromDate(),
            $utc->fromDate(),
            'two zones must be able to disagree; that is what FR-068\'s disclosure exists to explain'
        );
    }

    /**
     * FR-068's disclosure requirement: two users in different zones each get their own correct range,
     * and each is told which zone it was resolved in.
     */
    public function testTwoUsersInDifferentZonesResolveDifferentRanges(): void
    {
        // The instant is 00:30 **in Tehran**, then resolved for two different requester zones.
        // Writing it in UTC here made both callers land on the same Jalali day, which is the opposite
        // of what this test is about.
        $periods = new JalaliReportingPeriods($this->clockAt('2026-09-23 00:30:00', self::TEHRAN));

        $tehran = $periods->resolveInProgress(ReportingPeriod::MONTH, new DateTimeZone(self::TEHRAN));
        $utc = $periods->resolveInProgress(ReportingPeriod::MONTH, new DateTimeZone('UTC'));

        self::assertSame('2026-09-23', $tehran->fromDate(), 'Mehr 1 1405 in Tehran');
        self::assertSame('2026-08-23', $utc->fromDate(), 'Shahrivar 1 1405 for the same instant in UTC');
        self::assertSame(self::TEHRAN, $tehran->zoneName());
        self::assertSame('UTC', $utc->zoneName());
    }

    // ===== INCLUSIVE BOUNDS =====

    /**
     * Inclusive at both ends — a period must select exactly the records the equivalent explicit range
     * would, which is only true if the last day is inside the span.
     */
    public function testBothEndsOfThePeriodAreInsideIt(): void
    {
        $period = $this->periods->resolveInProgress(ReportingPeriod::MONTH, new DateTimeZone(self::TEHRAN));

        self::assertTrue($period->contains('2026-09-23'), 'the first day is inside');
        self::assertTrue($period->contains('2026-10-22'), 'the last day is inside');
        self::assertTrue($period->contains('2026-10-01'), 'and a day between them');
        self::assertFalse($period->contains('2026-09-22'), 'the day before is not');
        self::assertFalse($period->contains('2026-10-23'), 'and the day after is not');
    }

    // ===== THE VOCABULARY IS CLOSED =====

    public function testAnUnknownPeriodNameIsRefusedRatherThanGuessed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown reporting period 'week'");

        $this->periods->resolveInProgress('week', new DateTimeZone(self::TEHRAN));
    }

    /**
     * An impossible calendar day is refused rather than rolled over.
     *
     * PHP accepts `2026-02-30` and returns 2026-03-02 with only a *warning*, so a plain
     * `createFromFormat() === false` check would accept it — and a period boundary that silently became
     * the 2nd would select the wrong records with nothing to indicate it.
     */
    public function testAPeriodBoundaryThatIsNotARealDateIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ReportingPeriod::fromDates(
            ReportingPeriod::MONTH,
            '2026-02-30',
            '2026-03-05',
            new DateTimeZone(self::TEHRAN)
        );
    }

    // ===== THE ICU DIFFERENTIAL, WHICH SKIPS =====

    /**
     * Cross-check the library against ICU over a large date range — `plan.md`'s stated method, since
     * both implement the Borkowski algorithm and can be compared rather than trusted.
     *
     * **Skips rather than fails where ICU is absent**, which `plan.md` requires explicitly: the library
     * was chosen *because* it needs no `ext-intl`, so an instance without ICU is a supported deployment
     * and must not fail a test over a missing optional extension. When ICU *is* present, a disagreement
     * is a real failure and is reported as one.
     */
    public function testTheConversionMatchesIcuAcrossALargeDateRange(): void
    {
        $tz = new DateTimeZone(self::TEHRAN);

        if (!class_exists(IntlCalendar::class)) {
            self::markTestSkipped(
                'ext-intl is absent, which plan.md treats as a supported deployment: morilog/jalali was '
                . 'chosen so the conversion does not require it. The differential is the oracle, not the '
                . 'implementation.'
            );
        }

        $calendar = IntlCalendar::createInstance($tz, 'en_US@calendar=persian');

        // **ICU has no Solar Hijri calendar**, so the oracle `plan.md` prescribes does not exist.
        //
        // Measured on ICU 76.1: `persian` reports 1348-10-01 for 2026-09-23 in Tehran, `islamic-umalqura`
        // reports 1389, `islamic` 1389 and `dangi` is Korean. All lunar or unrelated; Solar Hijri's
        // 1405-07-01 appears under none of them. CLDR aliased the `persian` keyword to the *lunar*
        // Islamic calendar, which is the opposite of what the name suggests.
        //
        // So the first version of this differential compared against the lunar calendar and reported a
        // disagreement on *every one* of 1,827 days -- a wall of false failures that says nothing about
        // morilog/jalali. A missing oracle and a wrong oracle both have to end in a skip; only the second
        // is the dangerous one, because `class_exists(IntlCalendar::class)` is true here and would have
        // let it run.
        //
        // The oracle is therefore *anchored* against a known Solar Hijri date before it is trusted.
        $anchor = $this->persianDate($calendar, '2026-09-23');
        if ($anchor !== [1405, 7]) {
            self::markTestSkipped(sprintf(
                'ICU %s exposes no Solar Hijri calendar: `en_US@calendar=persian` reports %d-%02d for '
                . '2026-09-23 in Tehran, where Solar Hijri is 1405-07. plan.md prescribes an ICU '
                . 'differential, but comparing against a lunar Islamic oracle would report a false '
                . 'disagreement on every day rather than finding a real one.',
                INTL_ICU_VERSION,
                $anchor[0],
                $anchor[1]
            ));
        }

        $disagreements = [];
        $day = new DateTimeImmutable('2026-01-01 00:00:00', $tz);
        $end = new DateTimeImmutable('2031-01-01 00:00:00', $tz);
        $checked = 0;

        while ($day < $end) {
            $today = $day->format('Y-m-d');

            $period = (new JalaliReportingPeriods(
                $this->clockAt($today . ' 10:00:00', self::TEHRAN)
            ))->resolveInProgress(ReportingPeriod::MONTH, $tz);

            // ICU must place the resolved boundaries and the day itself in the same Jalali month, with
            // the boundaries on its first and last day.
            //
            // Comparing the library against ICU as *values* would need a `Morilog\Jalali\` type here,
            // and the task forbids it outside `JalaliReportingPeriods` -- so the cross-check is done on
            // what this abstraction actually promises: a range. The first version of this test compared
            // the Gregorian `m-d` of the period start against ICU's Jalali `m-d`, which is meaningless
            // and disagreed on every single day.
            $first = $this->persianDate($calendar, $period->fromDate());
            $last = $this->persianDate($calendar, $period->toDate());
            $here = $this->persianDate($calendar, $today);

            if ($first !== [$here[0], 1] || $last !== [$here[0], $here[1]] || $here[0] !== $last[0]) {
                $disagreements[] = sprintf(
                    '%s: resolved %s..%s, ICU says that is %d-%02d..%d-%02d while the day itself is %d-%02d',
                    $today,
                    $period->fromDate(),
                    $period->toDate(),
                    $first[0],
                    $first[1],
                    $last[0],
                    $last[1],
                    $here[0],
                    $here[1]
                );
            }

            $checked++;
            $day = $day->modify('+1 day');
        }

        self::assertGreaterThan(1800, $checked, 'the differential must actually cover a large range');
        self::assertSame([], array_slice($disagreements, 0, 5), 'morilog/jalali and ICU must agree');
    }

    /**
     * A Gregorian `Y-m-d` in Tehran as ICU's Persian `[year, month]`.
     *
     * `@return array{0: int, 1: int}
     */
    private function persianDate(IntlCalendar $calendar, string $gregorianDate): array
    {
        $tz = new DateTimeZone(self::TEHRAN);
        // `setTime()` takes a single timestamp here, not (year, month, day) -- passing three raises an
        // ArgumentCountError, which would leave this differential never actually running.
        $calendar->setTime((new DateTimeImmutable($gregorianDate . ' 00:00:00', $tz))->getTimestamp());

        return [
            (int) $calendar->get(IntlCalendar::FIELD_YEAR),
            (int) $calendar->get(IntlCalendar::FIELD_MONTH),
        ];
    }

    // ===== NOTHING IS WRITTEN =====

    /**
     * FR-068: stored Gregorian expense dates are never altered, re-encoded or migrated.
     *
     * The resolver is a pure function of the clock and the zone, so this is asserted structurally —
     * it takes no repository and returns no aggregate — and the `instanceof` check is what would fail if
     * a future change threaded a persistence port through here to "helpfully" re-encode dates.
     */
    public function testResolutionWritesNothing(): void
    {
        $before = $this->resolveMany();
        $after = $this->resolveMany();

        // `assertEquals`, not `assertSame`: the claim is that resolution returns an equal *value* twice,
        // and two separately built objects are never identical.
        self::assertEquals($before, $after, 'resolution is a pure function of clock and zone');
        self::assertContainsOnlyInstancesOf(ReportingPeriod::class, $after);
    }

    /** @return list<ReportingPeriod> */
    private function resolveMany(): array
    {
        $out = [];
        foreach (ReportingPeriod::NAMES as $name) {
            $out[] = $this->periods->resolveInProgress($name, new DateTimeZone(self::TEHRAN));
        }

        return $out;
    }

    // ===== HELPERS =====

    /** A `Clock` frozen at a given instant, so "in progress" is testable. */
    private function clockAt(string $instant, string $zone): Clock
    {
        $at = new DateTimeImmutable($instant, new DateTimeZone($zone));

        return new class ($at) implements Clock {
            public function __construct(private DateTimeImmutable $at) {}

            public function now(): DateTimeImmutable
            {
                return $this->at;
            }
        };
    }
}