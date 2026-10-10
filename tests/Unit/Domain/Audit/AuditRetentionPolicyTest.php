<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Domain\Audit;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\PettyCash\Domain\Audit\AuditRetentionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * T237 / FR-057: the seven-year floor is a deterministic business rule, so Constitution V requires
 * it to be covered here rather than only through a service that happens to call it.
 *
 * The load-bearing assertions are the boundaries either side of seven years, and the standing
 * prohibition on deletion. A policy that merely "kept things for a while" would pass a test that
 * only checked a happy path, and the floor is the whole of what FR-057 requires.
 */
final class AuditRetentionPolicyTest extends TestCase
{
    // --- The floor itself ---

    public function testTheFloorIsSevenYears(): void
    {
        self::assertSame(7, AuditRetentionPolicy::MINIMUM_RETENTION_YEARS);
    }

    public function testTheDefaultPolicyIsExactlyTheFloor(): void
    {
        self::assertSame(7, AuditRetentionPolicy::default()->retentionYears());
    }

    public function testTheDefaultPolicyHonorsTheMinimum(): void
    {
        self::assertTrue(AuditRetentionPolicy::default()->honorsMinimum());
    }

    /**
     * @dataProvider periodsShorterThanTheFloor
     */
    public function testAPeriodShorterThanSevenYearsIsRefused(int $years): void
    {
        $this->expectException(InvalidArgumentException::class);
        AuditRetentionPolicy::of($years);
    }

    /** @return list<array{int}> */
    public static function periodsShorterThanTheFloor(): array
    {
        return [[6], [5], [1], [0], [-1], [-100]];
    }

    public function testExactlySevenYearsIsAccepted(): void
    {
        self::assertSame(7, AuditRetentionPolicy::of(7)->retentionYears());
    }

    public function testALongerPeriodIsAccepted(): void
    {
        self::assertSame(25, AuditRetentionPolicy::of(25)->retentionYears());
    }

    public function testAnExtendedPeriodStillHonorsTheMinimum(): void
    {
        self::assertTrue(AuditRetentionPolicy::of(25)->honorsMinimum());
    }

    // --- The archival boundary ---

    public function testARecordYoungerThanSevenYearsIsProtected(): void
    {
        $policy = AuditRetentionPolicy::default();
        $occurredAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $now = new DateTimeImmutable('2032-12-31T00:00:00+00:00');

        self::assertTrue($policy->isProtected($occurredAt, $now));
        self::assertFalse($policy->isArchivable($occurredAt, $now));
    }

    public function testARecordIsNotArchivableOneSecondBeforeSevenYears(): void
    {
        $policy = AuditRetentionPolicy::default();
        $occurredAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $now = new DateTimeImmutable('2032-12-31T23:59:59+00:00');

        self::assertTrue($policy->isProtected($occurredAt, $now));
        self::assertFalse($policy->isArchivable($occurredAt, $now));
    }

    public function testARecordIsArchivableExactlyAtSevenYears(): void
    {
        $policy = AuditRetentionPolicy::default();
        $occurredAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $now = new DateTimeImmutable('2033-01-01T00:00:01+00:00');

        self::assertTrue($policy->isArchivable($occurredAt, $now));
        self::assertFalse($policy->isProtected($occurredAt, $now));
    }

    public function testTheBoundaryFollowsTheConfiguredPeriodNotTheFloor(): void
    {
        $policy = AuditRetentionPolicy::of(10);
        $occurredAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        // Nine years: past the floor, still inside the configured window.
        self::assertTrue($policy->isProtected($occurredAt, new DateTimeImmutable('2035-01-01T00:00:00+00:00')));
        // Eleven years: past the configured window.
        self::assertTrue($policy->isArchivable($occurredAt, new DateTimeImmutable('2037-01-01T00:00:00+00:00')));
    }

    public function testAvailableUntilIsTheOccurrenceDatePlusThePeriod(): void
    {
        $occurredAt = new DateTimeImmutable('2026-03-15T10:30:00+00:00');

        self::assertSame(
            '2033-03-15',
            AuditRetentionPolicy::default()->availableUntil($occurredAt)->format('Y-m-d')
        );
        self::assertSame(
            '2036-03-15',
            AuditRetentionPolicy::of(10)->availableUntil($occurredAt)->format('Y-m-d')
        );
    }

    public function testTheBoundaryIsPerRecordRatherThanGlobal(): void
    {
        $policy = AuditRetentionPolicy::default();
        $now = new DateTimeImmutable('2033-06-01T00:00:00+00:00');

        self::assertTrue($policy->isArchivable(new DateTimeImmutable('2020-01-01T00:00:00+00:00'), $now));
        self::assertTrue($policy->isProtected(new DateTimeImmutable('2029-01-01T00:00:00+00:00'), $now));
    }

    // --- The prohibition ---

    /**
     * FR-057 and Constitution IV: the policy may govern archival or availability, and may never
     * permit a business audit record to be deleted. This is the assertion that would fail if the
     * prohibition were ever moved out of the value object and into a caller's judgement.
     */
    public function testNoPolicyPermitsDeletion(): void
    {
        self::assertFalse(AuditRetentionPolicy::default()->permitsDeletion());
        self::assertFalse(AuditRetentionPolicy::of(7)->permitsDeletion());
        self::assertFalse(AuditRetentionPolicy::of(100)->permitsDeletion());
    }

    public function testExtendingThePeriodNeverUnprotectsAnExistingRecord(): void
    {
        $occurredAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $now = new DateTimeImmutable('2031-01-01T00:00:00+00:00');

        // Five years in, the record is protected under a 7-year policy. It must still be protected
        // under a 25-year one, which is the direction that matters: a longer policy protects more.
        self::assertTrue(AuditRetentionPolicy::of(7)->isProtected($occurredAt, $now));
        self::assertTrue(AuditRetentionPolicy::of(25)->isProtected($occurredAt, $now));
    }
}
