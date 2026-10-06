<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Shared;

use DateTimeZone;
use Morilog\Jalali\CalendarUtils;
use Morilog\Jalali\Jalalian;

/**
 * FR-068: resolve a predefined Jalali reporting period, in progress, at query time.
 *
 * ## Why this class exists, and why it is the only one
 *
 * `plan.md` requires the Jalali conversion to be "isolated behind a small value object in the domain
 * layer so that swapping strategy later touches one file rather than the query path". **This is that
 * file.** It is the only class in the application that names `Morilog\Jalali\`, which is what makes the
 * promise checkable rather than aspirational: `grep -rn 'Morilog' lib/` returns exactly this file and
 * the test that cross-checks it against ICU.
 *
 * The library is `morilog/jalali` ^3 (MIT, no `ext-intl` requirement, chosen because Nextcloud 34 does
 * not require `ext-intl` and depending on it alone would narrow the deployment envelope). Note the
 * version's API: the class is `Jalalian`, not `Jalali`, and there is no static `fromGregorian` —
 * conversion goes through `CalendarUtils::toJalali()` / `toGregorian()`. Anything written against the
 * older `Jalali::` name fails at runtime rather than at review, which is why the call sites below are
 * named and commented rather than inlined.
 *
 * ## The semantics, all of them settled in `spec.md` FR-068 on 2026-09-30
 *
 * - **Jalali (Solar Hijri), spanning Farvardin to Esfand**, with a quarter beginning at **Farvardin**
 *   rather than a Gregorian quarter boundary.
 * - Each denotes the period **in progress at request time** — first day through last — *not* the
 *   previous completed period and *not* a trailing window. A previous or trailing period is requested
 *   with the explicit custom date range T306 already delivers.
 * - **Inclusive at both ends.**
 * - A Jalali year is **365 or 366 days and is never treated as a fixed length**; the leap test is the
 *   library's, not a constant here.
 * - Resolution happens **at query time against the injected `Clock`**, never PHP's clock.
 * - **Stored Gregorian expense dates are never altered, re-encoded or migrated.** This class returns
 *   two dates; it writes nothing.
 *
 * ## The zone is a parameter, and that is a recorded gap rather than a finished answer
 *
 * FR-068 says the zone comes "from that user's own account settings, falling back to the instance's
 * configured default when the account states none; no zone is hardcoded". **There is no such source in
 * this repository yet** — every date helper in `lib/` constructs `new DateTimeZone('UTC')`, there is no
 * per-user timezone setting on `PettyCashAccount`, and no instance-default configuration. So the zone is
 * supplied by the caller rather than derived here, which keeps the *conversion* correct and leaves the
 * *source* of the zone as the open question. What this class guarantees is the part it can: the
 * returned range is derived as calendar dates **in the zone passed to it**, which is what makes the
 * Tehran/UTC boundary trap unreachable rather than merely avoided by convention.
 */
final class JalaliReportingPeriods
{
    public function __construct(
        private Clock $clock
    ) {}

    /**
     * Resolve `$name` to the period in progress at the clock's current instant, in `$zone`.
     *
     * @throws \InvalidArgumentException for a name outside FR-068's vocabulary
     */
    public function resolveInProgress(string $name, DateTimeZone $zone): ReportingPeriod
    {
        // `Clock`, never `new DateTimeImmutable('now')`. FR-068 requires resolution at query time
        // against the application clock, and a test that cannot move time cannot assert that a period
        // is the *in-progress* one rather than whatever today happens to be.
        $now = $this->clock->now()->setTimezone($zone);

        // Gregorian -> Jalali, in the requested zone. Converting the instant *first* and formatting the
        // result through UTC is the measured bug: `2026-09-23 00:00 Asia/Tehran` is `1405-07-01`, whose
        // first day is the 23rd, and UTC rendering of that day is the 22nd.
        [$jy, $jm, $jd] = CalendarUtils::toJalali($now->format('Y'), $now->format('m'), $now->format('d'));

        // Midnight in the zone, so the library's own `getFirstDayOf*`/`getEndDayOf*` arithmetic happens
        // on the same day this caller means.
        $today = new Jalalian($jy, $jm, $jd, 0, 0, 0, $zone);

        [$from, $to] = match ($name) {
            ReportingPeriod::MONTH => [$today->getFirstDayOfMonth(), $today->getEndDayOfMonth()],
            ReportingPeriod::QUARTER => [$today->getFirstDayOfQuarter(), $today->getEndDayOfQuarter()],
            ReportingPeriod::YEAR => [$today->getFirstDayOfYear(), $today->getEndDayOfYear()],
            default => throw new \InvalidArgumentException(
                "Unknown reporting period '{$name}'; FR-068 accepts "
                . implode(', ', ReportingPeriod::NAMES)
            ),
        };

        // Jalali -> Gregorian, then straight to a `Y-m-d` **string**. The conversion never becomes an
        // instant in another zone, which is what keeps the boundary on the day the library meant.
        return ReportingPeriod::fromDates(
            $name,
            self::toGregorianDate($from),
            self::toGregorianDate($to),
            $zone
        );
    }

    /**
     * A Jalalian's calendar day as `Y-m-d`, via `CalendarUtils::toGregorian()`.
     *
     * A private helper rather than `$jalalian->format('Y-m-d')` because `Jalalian::format()` renders
     * Jalali field names and its Gregorian mapping is version-specific; `CalendarUtils::toGregorian()`
     * is the documented conversion and is what the differential test cross-checks against ICU.
     */
    private static function toGregorianDate(Jalalian $date): string
    {
        [$gy, $gm, $gd] = CalendarUtils::toGregorian($date->getYear(), $date->getMonth(), $date->getDay());

        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }

    /**
     * Whether a Jalali year is a leap year.
     *
     * Exposed so the verification `plan.md` requires — 1403/1408 leap, 1404/1405 not — is asserted
     * through this abstraction rather than by reaching for the library directly. The point is that the
     * leap rule is **not a constant here**: 365 and 366 both occur and nothing in this file assumes one.
     */
    public function isLeapYear(int $jalaliYear): bool
    {
        return CalendarUtils::isLeapJalaliYear($jalaliYear);
    }
}