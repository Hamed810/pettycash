<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use DateTimeImmutable;
use OCA\PettyCash\Domain\Shared\Clock;

/**
 * A clock that returns a time the test chose.
 *
 * Retention compliance is entirely a question of how old a record is relative to now, so a real
 * clock would make every boundary assertion depend on the day the suite runs. A test that passes
 * today and fails next month is not a test.
 */
final class FixedClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $now = '2026-01-01T00:00:00+00:00')
    {
        $this->now = new DateTimeImmutable($now);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function setNow(string $now): void
    {
        $this->now = new DateTimeImmutable($now);
    }

    /**
     * Move time forward by a relative amount, e.g. `advance('+1.5 seconds')`.
     *
     * T414: `setNow()` was the only way to move this clock, and that is a **limitation, not a virtue**.
     * It could only land on a value the test wrote out, so a test could not ask the question that
     * actually mattered — *does behaviour hold when time passes between two attempts?* — and instead had
     * to assert across two attempts at the same instant, which any correct-or-incorrect implementation
     * satisfies when the clock does not move. That is how a defect in the evidence-link path's default
     * expiry survived: a frozen clock makes two attempts byte-identical, so the idempotency payload
     * comparison had nothing to disagree about.
     *
     * The sub-second step is what makes this usable for that question rather than only for coarse
     * boundaries — 1.5 seconds crosses the second-granularity line that `resolveExpiry()` now truncates
     * to, so an implementation truncating to the minute would fail.
     */
    public function advance(string $modifier): void
    {
        $advanced = $this->now->modify($modifier);

        if ($advanced === false) {
            throw new \InvalidArgumentException(sprintf('Cannot advance the clock by "%s".', $modifier));
        }

        $this->now = $advanced;
    }
}
