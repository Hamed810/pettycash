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
}
