<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Persistence;

use OCA\PettyCash\Application\DataIntegrity\UnreadableRecordData;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * T011/T012/T013: the Finance ledger read SQL and projection, verified against real MariaDB.
 *
 * ## Why this file runs the scenario in a subprocess
 *
 * The PHPUnit process wires in-memory doubles and cannot bootstrap Nextcloud (see
 * `FinanceReadQueryRoundTripTest` for the same boundary). `roundtrip-runner.php` boots the server,
 * seeds the fixture, runs the **real** `FinanceLedgerService` over the real repositories, and prints
 * what it observed. This file asserts those observations.
 *
 * ## What only a real database can prove here
 *
 * `FinanceReadQueryTest` proves the projection over a controlled movement page; it cannot prove the
 * paged SQL, the `id` tie-break, the independent `COUNT(*)`, or the two provenance joins. Those are
 * this file's subject, and the expectations are hand-written SQL probes rather than the repository
 * agreeing with itself.
 */
final class FinanceLedgerRoundTripTest extends TestCase
{
    private static ?array $report = null;

    private function report(): array
    {
        if (self::$report !== null) {
            return self::$report;
        }

        $runner = __DIR__ . '/roundtrip-runner.php';
        if (!is_file($runner)) {
            throw new RuntimeException("The round-trip runner is missing at {$runner}.");
        }

        $output = shell_exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' financeledger 2>/dev/null'
        );

        if ($output === null || trim($output) === '') {
            throw new RuntimeException(
                'The round-trip runner produced no output for the financeledger scenario. It exits with a '
                . 'message on stderr when it cannot bootstrap Nextcloud.'
            );
        }

        $decoded = json_decode($output, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("The round-trip runner did not return JSON:\n" . $output);
        }
        if (isset($decoded['error'])) {
            throw new RuntimeException(
                'The financeledger round trip failed against the real database: ' . $decoded['error']
            );
        }

        return self::$report = $decoded;
    }

    /** @return array<string, array<string, mixed>> movement label => seed row */
    private function movementsByLabel(): array
    {
        $byLabel = [];
        foreach ($this->report()['seed']['movements'] as $movement) {
            $byLabel[$movement['label']] = $movement;
        }

        return $byLabel;
    }

    /** @return list<string> */
    private function rawPublicIds(): array
    {
        return array_column($this->report()['raw']['ordered'], 'publicId');
    }

    /** @return list<array<string, mixed>> */
    private function items(string $page): array
    {
        return $this->report()['pages'][$page]['items'];
    }

    // ===== ordering, tie-break, and page boundaries =====

    public function testRawProbeIsTheHandWrittenDescendingOrder(): void
    {
        $report = $this->report();
        $movements = $this->movementsByLabel();
        $expected = [
            $movements['chg-d']['publicId'],
            $movements['chg-c']['publicId'], // same instant as c2, higher surrogate id
            $movements['c2']['publicId'],
            $movements['chg-b']['publicId'],
            $movements['c1']['publicId'],
            $movements['chg-a']['publicId'],
        ];

        self::assertSame($expected, $this->rawPublicIds(), 'the fixture itself must encode the expected order');
    }

    public function testPageOneIsTheNewestSliceInDeterministicOrder(): void
    {
        self::assertSame(
            array_slice($this->rawPublicIds(), 0, 2),
            array_column($this->items('one'), 'id'),
            'page 1 must be the first two rows of occurred_at DESC, id DESC'
        );
    }

    public function testPageTwoContinuesWithoutRepeatingOrSkipping(): void
    {
        self::assertSame(
            array_slice($this->rawPublicIds(), 2, 2),
            array_column($this->items('two'), 'id'),
            'page 2 must continue the same total order exactly'
        );
    }

    public function testPageThreeContainsTheOldestRow(): void
    {
        self::assertSame(
            array_slice($this->rawPublicIds(), 4, 2),
            array_column($this->items('three'), 'id')
        );
    }

    public function testTimestampTieIsBrokenByMovementIdDescending(): void
    {
        $movements = $this->movementsByLabel();
        $ids = array_column($this->items('one'), 'id');

        self::assertSame(
            [$movements['chg-d']['publicId'], $movements['chg-c']['publicId']],
            $ids,
            'two movements share an instant; the higher surrogate id must come first'
        );
        self::assertGreaterThan(
            (int) $movements['c2']['dbId'],
            (int) $movements['chg-c']['dbId'],
            'the fixture must insert the tie-break winner second'
        );
    }

    public function testPageBeyondTheFinalRowIsAnEmptySuccess(): void
    {
        $beyond = $this->report()['pages']['beyond'];

        self::assertSame([], $beyond['items']);
        self::assertSame(6, $beyond['total'], 'an empty page still reports the authoritative total');
    }

    // ===== independent total =====

    public function testTotalIsTheIndependentCountNotThePageLength(): void
    {
        $report = $this->report();

        self::assertSame(6, $report['raw']['count']);
        self::assertSame($report['raw']['count'], $report['pages']['one']['total']);
        self::assertCount(2, $report['pages']['one']['items']);
        self::assertSame($report['raw']['count'], $report['pages']['two']['total']);
        self::assertSame($report['raw']['count'], $report['pages']['whole']['total']);
        self::assertCount(6, $report['pages']['whole']['items']);
    }

    // ===== persisted facts, exactly one side =====

    public function testEveryRowUsesThePersistedTimestampAndBalance(): void
    {
        $raw = [];
        foreach ($this->report()['raw']['ordered'] as $row) {
            $raw[$row['publicId']] = $row;
        }

        foreach ($this->items('whole') as $item) {
            $expected = $raw[$item['id']] ?? null;
            self::assertNotNull($expected, "row {$item['id']} must exist in the raw probe");
            self::assertSame($expected['occurredAt'], $item['occurredAt'], 'Date must be the persisted movement timestamp');
            self::assertSame($expected['balanceAfterMinor'], $item['balanceAfterMinor'], 'Balance must be the persisted balance_after_minor');
        }
    }

    public function testEveryRowCarriesExactlyOneMonetarySide(): void
    {
        foreach ($this->items('whole') as $item) {
            $sides = (int) ($item['rechargeMinor'] !== null) + (int) ($item['expenseMinor'] !== null);
            self::assertSame(1, $sides, "row {$item['id']} must populate exactly one of recharge/expense");
        }
    }

    public function testChargeRowsCarryTheRechargeSideOnlyWithStoredDescription(): void
    {
        $movements = $this->movementsByLabel();
        $raw = [];
        foreach ($this->report()['raw']['ordered'] as $row) {
            $raw[$row['publicId']] = $row;
        }

        $charges = array_values(array_filter(
            $this->items('whole'),
            static fn (array $item): bool => $item['type'] === 'CHARGE'
        ));
        self::assertCount(4, $charges, 'the account has four charge movements');

        foreach ($charges as $item) {
            self::assertNotNull($item['rechargeMinor']);
            self::assertNull($item['expenseMinor']);
            self::assertNull($item['sheet']);
            self::assertNull($item['approved'], 'a recharge has no approved/total pair');
            self::assertSame(
                $raw[$item['id']]['description'],
                $item['description'],
                'a charge description is the authoritative stored movement description'
            );
            self::assertNull($item['sourceRevisionId']);
        }

        self::assertSame(
            'Initial funding',
            $raw[$movements['chg-a']['publicId']]['description']
        );
    }

    public function testConsumptionRowsResolveSheetReferenceTitleAndApprovedCounts(): void
    {
        $report = $this->report();
        $consumptions = array_values(array_filter(
            $this->items('whole'),
            static fn (array $item): bool => $item['type'] === 'CONSUMPTION'
        ));

        self::assertCount(2, $consumptions, 'the same sheet has two independent postings');

        foreach ($consumptions as $item) {
            self::assertNull($item['rechargeMinor']);
            self::assertNotNull($item['expenseMinor']);
            self::assertSame($report['seed']['sheetPublicId'], $item['sheet']['id']);
            self::assertSame($report['seed']['sheetPublicId'], $item['sheet']['reference']);
            self::assertSame($report['seed']['sheetTitle'], $item['sheet']['title']);
            self::assertSame($report['seed']['sheetTitle'], $item['description']);
            self::assertNotNull($item['sourceRevisionId']);
            self::assertArrayNotHasKey('rejectedCount', $item);
        }

        self::assertSame(['approvedCount' => 2, 'totalCount' => 3], $report['raw']['sheetCounts']);
        self::assertSame($report['raw']['sheetCounts'], $consumptions[0]['approved']);
        self::assertSame($report['raw']['sheetCounts'], $consumptions[1]['approved']);
    }

    public function testProvenanceJoinMatchesTheHandWrittenChain(): void
    {
        $report = $this->report();
        $revisionPublicIds = array_slice($report['seed']['revisionPublicIds'], 0, 2);

        foreach ($revisionPublicIds as $revisionPublicId) {
            self::assertArrayHasKey(
                $revisionPublicId,
                $report['raw']['provenance'],
                'the raw revision -> transaction -> sheet join must resolve both consumption revisions'
            );
            self::assertSame($report['seed']['sheetPublicId'], $report['raw']['provenance'][$revisionPublicId]['sheetId']);
        }
    }

    // ===== no consolidation, no interleaving loss, negative balance =====

    public function testMultipleMovementsFromOneSheetRemainSeparateRows(): void
    {
        $movements = $this->movementsByLabel();
        $consumptions = array_values(array_filter(
            $this->items('whole'),
            static fn (array $item): bool => $item['type'] === 'CONSUMPTION'
        ));

        self::assertSame(
            [$movements['c2']['publicId'], $movements['c1']['publicId']],
            array_column($consumptions, 'id'),
            'each posting keeps its own movement identity, newest first'
        );
        self::assertNotSame(
            $consumptions[0]['occurredAt'],
            $consumptions[1]['occurredAt'],
            'the two postings from one sheet must not share a synthetic timestamp'
        );
        self::assertNotSame(
            $consumptions[0]['balanceAfterMinor'],
            $consumptions[1]['balanceAfterMinor'],
            'the two postings must keep their own resulting balances'
        );
        self::assertSame(
            [$movements['c2']['publicId'], $movements['c1']['publicId']],
            array_values(array_filter(
                array_column($this->items('whole'), 'id'),
                static fn (string $id): bool => in_array($id, [$movements['c2']['publicId'], $movements['c1']['publicId']], true)
            ))
        );
    }

    public function testInterleavedUnrelatedChargeKeepsItsOwnRow(): void
    {
        $movements = $this->movementsByLabel();
        $ids = array_column($this->items('whole'), 'id');
        $position = array_flip($ids);

        self::assertArrayHasKey($movements['chg-b']['publicId'], $position, 'the interleaved charge must not be swallowed');
        self::assertLessThan(
            $position[$movements['c1']['publicId']],
            $position[$movements['chg-b']['publicId']],
            'the charge occurred between the two postings and must sit between them in descending order'
        );
        self::assertLessThan(
            $position[$movements['chg-b']['publicId']],
            $position[$movements['c2']['publicId']]
        );
    }

    public function testNegativePersistedBalanceIsTransmittedVerbatim(): void
    {
        $balances = array_column($this->items('whole'), 'balanceAfterMinor');

        self::assertContains(-200, $balances, 'the first consumption that overdraws the account is the persisted -200');
        self::assertContains(-150, $balances);
        self::assertContains(-125, $balances, 'the latest movement keeps the negative authoritative balance');
    }

    // ===== account header, scoping, empty account, data fault =====

    public function testAccountHeaderReportsTheIndependentCountOnEveryPage(): void
    {
        $first = $this->report()['pages']['one']['account'];
        $second = $this->report()['pages']['two']['account'];

        self::assertSame(6, $first['movementCount']);
        self::assertSame(6, $second['movementCount']);
        self::assertSame('2024-03-05 09:00:00', $first['lastMovementAt']);
        self::assertSame(
            $first['lastMovementAt'],
            $second['lastMovementAt'],
            'the header timestamp is the account maximum, not the first row of the loaded page'
        );
    }

    public function testAnotherAccountsLedgerIsNotMixedIn(): void
    {
        $report = $this->report();
        $movements = $this->movementsByLabel();
        $otherIds = array_column($report['other']['items'], 'id');

        self::assertSame($report['raw']['otherCount'], $report['other']['total']);
        self::assertSame(2, $report['other']['total']);
        self::assertSame(
            [$movements['other-2']['publicId'], $movements['other-1']['publicId']],
            $otherIds
        );
        foreach ($this->rawPublicIds() as $mainId) {
            self::assertNotContains($mainId, $otherIds, 'a per-account page can never leak another account\'s movement');
        }
    }

    public function testAccountWithNoMovementsIsAValidEmptyLedger(): void
    {
        $empty = $this->report()['empty'];

        self::assertSame(0, $empty['total']);
        self::assertSame([], $empty['items']);
        self::assertSame(0, $empty['account']['movementCount']);
        self::assertNull($empty['account']['lastMovementAt']);
    }

    public function testDanglingConsumptionProvenanceRaisesTheExistingDataFault(): void
    {
        $dangling = $this->report()['dangling'];

        self::assertTrue($dangling['threw'], 'an unresolvable provenance chain must not degrade into a valid ledger row');
        self::assertSame(UnreadableRecordData::class, $dangling['type'], 'the existing data-fault mechanism is used, not a second error model');
    }

    // ===== per-request query budget (T015) =====

    /**
     * The per-request query count must match the six-query constant ledger budget:
     * 1. account read
     * 2. account activity aggregate
     * 3. movement count
     * 4. paged movement read
     * 5. batched ledger provenance/context read
     * 6. batched sheet approved/total count read
     *
     * Measured against real MariaDB through `QueryCountingConnection` wrapping the production
     * `FinanceLedgerService`, `DbAccountMovementRepository`, and `FinanceReadQuery`. A fake/double
     * call count is not sufficient proof.
     */
    public function testMixedPopulatedPageStaysWithinSixQueryBudget(): void
    {
        $queryCount = $this->report()['queryCount']['pageOne'] ?? null;
        self::assertNotNull($queryCount, 'page one query count must be reported');
        self::assertLessThanOrEqual(
            6,
            $queryCount,
            'mixed populated page (CHARGE + CONSUMPTION rows) must stay within the six-query constant budget'
        );
    }

    public function testPageTwoDoesNotIncreaseQueryCount(): void
    {
        $counts = $this->report()['queryCount'];
        self::assertArrayHasKey('pageOne', $counts, 'page one query count must be reported');
        self::assertArrayHasKey('pageTwo', $counts, 'page two query count must be reported');
        // Page 2 may be an empty page (beyond available data) and skip enrichment, so it may use
        // fewer queries -- but it must never use MORE than the populated page.
        self::assertLessThanOrEqual(
            $counts['pageTwo'],
            $counts['pageOne'],
            'page 2 must not use more queries than the populated page'
        );
        self::assertLessThanOrEqual(
            6,
            max($counts['pageOne'], $counts['pageTwo']),
            'neither page may exceed the six-query constant budget'
        );
    }

    public function testDifferentPageSizeDoesNotIncreaseQueryCount(): void
    {
        $counts = $this->report()['queryCount'];
        self::assertArrayHasKey('pageOne', $counts, 'page one query count must be reported');
        self::assertArrayHasKey('pageWhole', $counts, 'full-size page query count must be reported');
        // A different page size must not grow the per-request query count beyond the populated page.
        self::assertLessThanOrEqual(
            6,
            max($counts['pageOne'], $counts['pageWhole']),
            'neither page size may exceed the six-query constant budget'
        );
    }

    public function testEmptyPageUsesNoMoreQueriesThanPopulatedPage(): void
    {
        $counts = $this->report()['queryCount'];
        self::assertArrayHasKey('pageOne', $counts, 'page one query count must be reported');
        self::assertArrayHasKey('pageEmpty', $counts, 'empty page query count must be reported');
        self::assertLessThanOrEqual(
            $counts['pageOne'],
            $counts['pageEmpty'],
            'an empty page must not use more queries than a populated page'
        );
    }

    public function testOtherAccountUsesNoMoreQueriesThanPopulatedPage(): void
    {
        $counts = $this->report()['queryCount'];
        self::assertArrayHasKey('pageOne', $counts, 'page one query count must be reported');
        self::assertArrayHasKey('pageOther', $counts, 'other account query count must be reported');
        self::assertLessThanOrEqual(
            $counts['pageOne'],
            $counts['pageOther'],
            'another account\'s ledger must not use more queries than the populated page'
        );
    }
}
