<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Persistence;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * T006/T007/T008: the real `FinanceReadQuery` SQL, verified against MariaDB.
 *
 * ## Why this file runs the query in a subprocess
 *
 * The PHPUnit process wires a container of `InMemory*` doubles and cannot load `lib/base.php`
 * (`tests/bootstrap.php`, and `FinanceSettlementTest` documents the same boundary), so no PHPUnit
 * test reaches the real database. `roundtrip-runner.php` is the repository's harness for that: it
 * bootstraps the Nextcloud server, seeds probe rows, runs the real `FinanceReadQuery`, and prints
 * the values it read out of the real tables. This file asserts them.
 *
 * ## Why the runner reports observations and this file asserts
 *
 * The scenario prints the figures it observed and deletes its own rows in a `finally`; the
 * assertions here compare `FinanceReadQuery` against an independent hand-written SQL probe of the
 * same table. A bug in the runner cannot agree with itself into a green test, and a bug in the query
 * (wrong table, wrong column, missing group, wrong pagination) diverges from the probe.
 *
 * ## What the in-memory suite cannot prove
 *
 * `FinanceReadQueryTest` proves the service logic over an in-memory `FinanceRead` double; it cannot
 * prove the grouped/paginated SQL. That proof is this file's job: grouped holder population,
 * accountCount, activeAccountCount, distinct-holder total, deterministic pagination/order, batched
 * `accountActivityFor()`, and empty-input/empty-result behaviour — all against a real MariaDB.
 *
 * It also covers the adjacent `SheetSummaryQuery::postedAmountsFor()` contract: a completed sheet
 * with no consumption movement must still project one authoritative zero-posting row.
 */
final class FinanceReadQueryRoundTripTest extends TestCase
{
    private static ?array $report = null;
    private static ?array $postedAmountsReport = null;

    private function report(): array
    {
        return self::$report ??= $this->runScenario('financereads');
    }

    private function postedAmountsReport(): array
    {
        return self::$postedAmountsReport ??= $this->runScenario('postedamounts');
    }

    /** @return array<string, mixed> */
    private function runScenario(string $scenario): array
    {
        $runner = __DIR__ . '/roundtrip-runner.php';
        if (!is_file($runner)) {
            throw new RuntimeException("The round-trip runner is missing at {$runner}.");
        }

        $output = shell_exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg($scenario) . ' 2>/dev/null'
        );

        if ($output === null || trim($output) === '') {
            throw new RuntimeException(
                "The round-trip runner produced no output for the {$scenario} scenario. It exits with a "
                . 'message on stderr when it cannot bootstrap Nextcloud.'
            );
        }

        $decoded = json_decode($output, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("The round-trip runner did not return JSON:\n" . $output);
        }
        if (isset($decoded['error'])) {
            throw new RuntimeException(
                "The {$scenario} round trip failed against the real database: " . $decoded['error']
            );
        }

        return $decoded;
    }

    // ===== grouped holder population, accountCount, activeAccountCount =====

    public function testDistinctHolderTotalMatchesTheIndependentRawCount(): void
    {
        $report = $this->report();

        self::assertSame(
            $report['raw']['distinctCount'],
            $report['read']['count'],
            'holderCount() must equal COUNT(DISTINCT user_uid) over the table, never COUNT(*) over rows'
        );
    }

    public function testHolderCountEqualsTheNumberOfGroupedRows(): void
    {
        $report = $this->report();

        self::assertSame(
            count($report['read']['all']),
            $report['read']['count'],
            'allHolders() must list exactly one grouped row per distinct holder'
        );
    }

    public function testGroupedPopulationMatchesTheIndependentProjection(): void
    {
        $report = $this->report();

        self::assertSame(
            $report['raw']['holders'],
            $report['read']['all'],
            'the grouped holder projection (accountCount, activeAccountCount, order) must equal a '
            . 'hand-written GROUP BY over the same table'
        );
    }

    public function testSeededHolderCountsSurviveTheRoundTrip(): void
    {
        $report = $this->report();

        $byUserId = [];
        foreach ($report['read']['all'] as $holder) {
            $byUserId[$holder['userId']] = $holder;
        }

        foreach ($report['seed']['holders'] as $uid => $expected) {
            self::assertArrayHasKey($uid, $byUserId, "seeded holder {$uid} must be in the population");
            self::assertSame($expected['accountCount'], $byUserId[$uid]['accountCount'], $uid);
            self::assertSame($expected['activeAccountCount'], $byUserId[$uid]['activeAccountCount'], $uid);
        }
    }

    public function testEachSeededHolderIsListedExactlyOnce(): void
    {
        $report = $this->report();

        foreach (array_keys($report['seed']['holders']) as $uid) {
            $occurrences = 0;
            foreach ($report['read']['all'] as $holder) {
                if ($holder['userId'] === $uid) {
                    $occurrences++;
                }
            }
            self::assertSame(1, $occurrences, "holder {$uid} must appear exactly once");
        }
    }

    // ===== deterministic pagination / order =====

    public function testAllHoldersAreOrderedByUserIdAscending(): void
    {
        $report = $this->report();

        $uids = array_column($report['read']['all'], 'userId');
        $sorted = $uids;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $uids, 'the grouped population must be deterministically ordered by uid');
    }

    public function testHolderPageIsASliceOfTheFullSortedPopulation(): void
    {
        $report = $this->report();

        $all = $report['read']['all'];
        self::assertSame(
            array_slice($all, 0, 2),
            $report['read']['pageA'],
            'holderPage(0,2) must equal the first two rows of the full sorted population'
        );
        self::assertSame(
            array_slice($all, 2, 2),
            $report['read']['pageB'],
            'holderPage(2,2) must equal the next two rows, independent of other holders that exist on the instance'
        );
    }

    // ===== batched accountActivityFor =====

    public function testUsedAccountActivityCountsItsTwoMovements(): void
    {
        $report = $this->report();

        $key = (string) $report['seed']['alphaActivityDbId'];
        self::assertArrayHasKey(
            $key,
            $report['activity']['fact'],
            'the account with two movements must appear in the batched activity map'
        );
        self::assertSame(2, $report['activity']['fact'][$key]['movementCount']);
    }

    public function testLastMovementAtIsTheLaterOfTheTwoBackDatedInstants(): void
    {
        $report = $this->report();

        $key = (string) $report['seed']['alphaActivityDbId'];
        self::assertSame(
            '2024-01-01 11:00:00',
            $report['activity']['fact'][$key]['lastMovementAt'],
            'MAX(occurred_at) must be the later of the two persisted movement timestamps'
        );
    }

    public function testUnusedAccountsAreAbsentFromTheBatchedActivityMap(): void
    {
        $report = $this->report();

        $unused = [
            (string) $report['seed']['alphaUnusedDbId'],
            (string) $report['seed']['bravoDbIds'][0],
            (string) $report['seed']['bravoDbIds'][1],
        ];
        foreach ($unused as $dbId) {
            self::assertArrayNotHasKey(
                $dbId,
                $report['activity']['fact'],
                "account {$dbId} has no movements and must be absent from the activity map (the caller renders 0/null)"
            );
        }
    }

    public function testEveryRequestedAccountIsAnsweredByTheSingleBatchedCall(): void
    {
        $report = $this->report();

        $ids = array_map('strval', $report['activity']['ids']);
        foreach ($ids as $dbId) {
            // Every requested account resolves either with facts (used) or by absence (unused);
            // json_decode surfaces numeric keys directly, so both lookups are by string.
            self::assertTrue(
                array_key_exists($dbId, $report['activity']['fact']) ||
                in_array($dbId, [
                    (string) $report['seed']['alphaUnusedDbId'],
                    (string) $report['seed']['bravoDbIds'][0],
                    (string) $report['seed']['bravoDbIds'][1],
                ], true),
                "batched call must cover the requested account {$dbId}"
            );
        }
    }

    // ===== empty input / result behaviour =====

    public function testEmptyInputReturnsAnEmptyActivityMap(): void
    {
        self::assertSame([], $this->report()['activity']['empty']);
    }

    // ===== `SheetSummaryQuery::postedAmountsFor()` zero-posting contract =====

    public function testEveryRequestedSheetWithNoPostingsStillProjectsARow(): void
    {
        $section = $this->postedAmountsReport()['postedAmounts'];

        self::assertSame(
            2,
            $section['rowCount'],
            'postedAmountsFor() must return one row per requested sheet, not only those with movements'
        );
        self::assertCount(2, $section['rows']);

        foreach ($section['rows'] as $row) {
            self::assertTrue($row['present'], "requested sheet {$row['sheetDbId']} must be present in the result");
        }
    }

    public function testZeroPostingRowsCarryZeroAmountsAndTheConfiguredScale(): void
    {
        $section = $this->postedAmountsReport()['postedAmounts'];
        self::assertSame(2, $section['configuredMinorUnit']);

        $byId = [];
        foreach ($section['rows'] as $row) {
            $byId[(int) $row['sheetDbId']] = $row;
        }

        foreach (['withoutTransactions', 'withoutMovement'] as $name) {
            $sheetDbId = (int) $section['sheets'][$name];
            self::assertArrayHasKey($sheetDbId, $byId, "requested sheet '{$name}' must be present");

            $row = $byId[$sheetDbId];
            self::assertSame(0, $row['postedAmount'], "sheet '{$name}' has no consumption movement");
            self::assertSame(0, $row['postingCount'], "sheet '{$name}' has no consumption movement");
            self::assertSame($section['currency'], $row['currency'], "sheet '{$name}' must report its configured currency");
            self::assertSame(
                $section['configuredMinorUnit'],
                $row['minorUnit'],
                "sheet '{$name}' must report its configured currency scale"
            );
        }
    }
}
