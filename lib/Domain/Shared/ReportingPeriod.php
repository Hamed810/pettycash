<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Shared;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * One resolved reporting period: a named span of Gregorian calendar dates, and the zone it was
 * resolved in. FR-068.
 *
 * ## Why this is a value object rather than two dates
 *
 * FR-068 requires that **every** response which resolved a predefined period state both the exact date
 * range it resolved *and* the zone it was resolved in, so that "two users in different zones can
 * explain a difference between their exports". A bare `(from, to)` pair cannot carry that, so the zone
 * is part of the value rather than an argument some caller might remember to log. It is also what makes
 * the disclosure mechanical: the value that answers the query is the value the response reports.
 *
 * ## The boundary trap this type exists to make impossible
 *
 * `plan.md` records a measured trap: Tehran is UTC+3:30, so resolving a period and then formatting the
 * instant through UTC shifts the boundary by a full day — Mehr 1 1405 is 2026-09-23 in Tehran and
 * renders `2026-09-22` in UTC. Reproduced during T312: `2026-09-23 Asia/Tehran` resolves to Jalali
 * `1405-07-01`, whose first day is `2026-09-23`, and formatting that instant as UTC yields the 22nd.
 *
 * So `from` and `to` are held as **calendar dates at midnight in the period's own zone**, and
 * {@see self::fromDate()} is the only way to build one. Nothing here converts to UTC, because nothing
 * here needs an instant — a period is a span of days, and an expense date is compared to it as a day.
 *
 * Both ends are inclusive. FR-068 is explicit ("Inclusive at both ends"), and a period covering every
 * expense date from its first day through its last must select exactly the same records as the
 * equivalent explicit range — which is only true if the last day is inside the span rather than the
 * first day of the next period.
 */
final readonly class ReportingPeriod
{
    /** The periods FR-068 names. A closed vocabulary, so a typo is a refusal rather than a guess. */
    public const MONTH = 'month';
    public const QUARTER = 'quarter';
    public const YEAR = 'year';

    public const NAMES = [self::MONTH, self::QUARTER, self::YEAR];

    private function __construct(
        public string $name,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public DateTimeZone $zone
    ) {
        if ($to < $from) {
            // A period that ends before it starts is a conversion failure, not a filter that matched
            // nothing. Refusing it here means a wrong calendar cannot become a silently empty result.
            throw new InvalidArgumentException(
                "Reporting period '{$name}' resolved to an end before its start"
            );
        }
    }

    /**
     * Build from the first and last calendar day of the span, **in the given zone**.
     *
     * Named `fromDates` rather than `fromDate` because the instance accessors below are already
     * `fromDate()`/`toDate()`; PHP cannot declare a static and an instance method of the same name.
     *
     * Both arguments are parsed as `Y-m-d` at midnight in `$zone`. That is the whole point: the pair is
     * a pair of days, and constructing them anywhere else is the measured Tehran/UTC bug.
     */
    public static function fromDates(string $name, string $from, string $to, DateTimeZone $zone): self
    {
        if (!in_array($name, self::NAMES, true)) {
            throw new InvalidArgumentException(
                "Unknown reporting period '{$name}'; FR-068 accepts " . implode(', ', self::NAMES)
            );
        }

        $start = self::parseCalendarDate($from, $zone, $name);
        $end = self::parseCalendarDate($to, $zone, $name);

        return new self($name, $start, $end, $zone);
    }

    /**
     * One calendar date at midnight in `$zone`, refusing a day that does not exist.
     *
     * **The error state is read immediately after this parse and before any other.** PHP's
     * `createFromFormat()` accepts an impossible day — `2026-02-30` returns 2026-03-02 — and reports it
     * only through `getLastErrors()`, whose state is *replaced* by the next parse. Reading it once after
     * both parses therefore silently loses the first date's warning whenever the second one is valid,
     * which is exactly the common case. That bug was in this method's first version and was caught by
     * `testAPeriodBoundaryThatIsNotARealDateIsRefused`.
     */
    private static function parseCalendarDate(
        string $date,
        DateTimeZone $zone,
        string $periodName
    ): DateTimeImmutable {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
        $errors = DateTimeImmutable::getLastErrors();

        $rolledOver = is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);

        if ($parsed === false || $rolledOver) {
            throw new InvalidArgumentException(
                "Reporting period '{$periodName}' resolved to a date that does not exist ({$date})"
            );
        }

        return $parsed;
    }

    /**
     * Whether a stored expense date falls inside this period, inclusive at both ends.
     *
     * Compared as `Y-m-d` strings rather than instants. An expense date is a day with no time and no
     * zone, and instant comparison would reintroduce exactly the UTC shift this type avoids.
     */
    public function contains(string $expenseDate): bool
    {
        return $expenseDate >= $this->fromDate() && $expenseDate <= $this->toDate();
    }

    public function fromDate(): string
    {
        return $this->from->format('Y-m-d');
    }

    public function toDate(): string
    {
        return $this->to->format('Y-m-d');
    }

    /** The zone name to disclose — `Asia/Tehran`, not an offset, so the disclosure is unambiguous. */
    public function zoneName(): string
    {
        return $this->zone->getName();
    }
}