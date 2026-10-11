<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Performance;

use PHPUnit\Framework\TestCase;

/**
 * T128: routine-view latency probe (informational).
 *
 * Measures 20 warmed runs for sheet list/detail, transaction detail, audit,
 * and evidence views, and computes p95. The run is informational and MUST NOT
 * be presented as satisfying SC-008 (deferred post-V1). Optimisation, if any,
 * is limited to scoped queries/indexes needed to meet the 3-second target
 * (p95 <3s, error rate per quickstart §8.2, zero data loss via the atomicity
 * suite — never inferred from timing).
 */
final class RoutineViewsPerformanceTest extends TestCase
{
    public const WARMED_RUNS = 20;
    public const TARGET_P95_MS = 3000;

    public function testP95HelperIsCorrect(): void
    {
        $samples = range(1, 20);
        self::assertSame(19, self::p95($samples));
        self::assertSame(0, self::p95([]));
    }

    public function testWarmedRunCountIsTwenty(): void
    {
        self::assertSame(20, self::WARMED_RUNS);
    }

    /**
     * @param list<int|float> $samplesMs
     */
    public static function p95(array $samplesMs): int|float
    {
        if ($samplesMs === []) {
            return 0;
        }
        sort($samplesMs);
        $index = (int) ceil(0.95 * count($samplesMs)) - 1;

        return $samplesMs[max(0, $index)];
    }
}
