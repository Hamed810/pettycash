<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Persistence;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * UXC-7F-02/03/04: the real `SheetSummaryQuery::stageApprovedTotalsForSheets()` SQL, verified
 * against MariaDB.
 *
 * ## Why this file runs the query in a subprocess
 *
 * The same boundary `FinanceReadQueryRoundTripTest` documents: the PHPUnit process wires
 * `InMemory*` doubles and cannot load `lib/base.php`, so no PHPUnit test reaches the real database.
 * The `stageapprovedtotals` scenario in `roundtrip-runner.php` bootstraps the server, seeds the
 * sheets/revisions/decisions, runs the real batched query, and prints what it answered.
 *
 * ## What is proven here and not elsewhere
 *
 * `SheetTotalsTest` proves the reduction rules over hand-built rows; the contract suites prove the
 * response shape over the fixture sheet. Neither proves the batched SQL itself — the `IN`-set read
 * over several sheets, the active-revision join that excludes a superseded revision's approval, the
 * scope-free whole-sheet coverage (a decision on a transaction of another cost centre still counts),
 * the per-sheet currency scale, and the absent-sheet behaviour. Those are this file's job, asserted
 * against hand-derived figures from the seeded decisions — never against the code under test.
 */
final class SheetSummaryQueryStageTotalsRoundTripTest extends TestCase
{
    private static ?array $report = null;

    private function report(): array
    {
        return self::$report ??= $this->runScenario('stageapprovedtotals');
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

    /**
     * @param string $label 'mixed'|'undecided'|'unknown'
     * @return array<string, mixed>
     */
    private function row(string $label): array
    {
        $rows = array_values(array_filter(
            $this->report()['stageApprovedTotals']['rows'],
            static fn (array $row): bool => $row['label'] === $label
        ));
        self::assertCount(1, $rows, "exactly one observation row is printed for '{$label}'");

        return $rows[0];
    }

    public function testApprovedDecisionsAtCostCenterAndSeniorCountTowardTheirStageTotals(): void
    {
        $row = $this->row('mixed');

        // t1 (12000) carries the Cost Center AND the Senior approval; t5 (3000) carries a Cost
        // Center approval on another cost centre. Nothing else approves.
        self::assertTrue((bool) $row['present']);
        self::assertSame(15000, $row['costCenterApprovedMinor'], 'CC total = t1 (12000) + t5 (3000)');
        self::assertSame(12000, $row['seniorApprovedMinor'], 'Senior total = t1 alone');
    }

    public function testRejectedReturnedAndUndecidedTransactionsContributeNothing(): void
    {
        $row = $this->row('mixed');

        // t2 (8000) is Cost Center REJECTED, t3 (5000) Cost Center RETURNED: neither appears in any
        // approved total. The Finance stage has no decision anywhere in the seeded sheet.
        self::assertSame(0, $row['financeApprovedMinor']);
        // The rejected/returned amounts would show up under the CC total had the query read them.
        self::assertNotSame(23000, $row['costCenterApprovedMinor']);
        self::assertNotSame(38000, $row['costCenterApprovedMinor']);
    }

    public function testASupersededRevisionApprovalDoesNotCount(): void
    {
        $row = $this->row('mixed');

        // t4 (7000) was Cost Center APPROVED on revision 1; revision 2 is the active revision and
        // carries no decisions. The approval is history (FR-050) and must not count.
        self::assertSame(15000, $row['costCenterApprovedMinor'], 'the superseded 7000 approval adds nothing');
    }

    public function testAnUndecidedSheetAnswersDerivedZerosInItsOwnCurrencyScale(): void
    {
        $row = $this->row('undecided');

        self::assertTrue((bool) $row['present'], 'an undecided sheet is a real answer, not an absent one');
        self::assertSame(0, $row['costCenterApprovedMinor']);
        self::assertSame(0, $row['seniorApprovedMinor']);
        self::assertSame(0, $row['financeApprovedMinor']);
        self::assertSame($this->report()['stageApprovedTotals']['currency'], $row['currency']);
        self::assertSame(2, $row['minorUnit'], 'the scale comes from the sheet currency row, not a guess');
    }

    public function testAnUnknownSheetDbIdYieldsNoEntry(): void
    {
        $row = $this->row('unknown');

        self::assertFalse((bool) $row['present'], 'an unknown sheet db id answers no entry at all');
        self::assertSame(2, $this->report()['stageApprovedTotals']['rowCount'], 'only the two seeded sheets are answered');
    }

    public function testBatchedAnswersCoverSeveralSheetsInOneRead(): void
    {
        $report = $this->report()['stageApprovedTotals'];

        self::assertSame(3, count($report['requested']), 'the scenario requests two seeded sheets plus an unknown id');
        self::assertSame(2, $report['rowCount'], 'the batched read answers every seeded sheet in ONE query');
    }
}
