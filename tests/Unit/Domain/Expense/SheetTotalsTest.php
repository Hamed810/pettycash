<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Domain\Expense;

use OCA\PettyCash\Domain\Expense\SheetTotals;
use OCA\PettyCash\Domain\Workflow\Decision;
use PHPUnit\Framework\TestCase;

final class SheetTotalsTest extends TestCase
{
    public function testLatestEffectiveAndStageTotalsRemainDistinct(): void
    {
        $totals = SheetTotals::fromRows([
            ['amount' => 10, 'latest' => Decision::Approve, 'manager' => Decision::Approve, 'senior' => Decision::Reject, 'finance' => null],
            ['amount' => 5, 'latest' => null, 'manager' => Decision::Approve, 'senior' => null, 'finance' => null],
        ]);
        self::assertSame(15, $totals->current);
        self::assertSame(10, $totals->approved);
        self::assertSame(15, $totals->managerApproved);
        self::assertSame(0, $totals->seniorApproved);
        self::assertSame(5, $totals->pending);
    }

    /**
     * data-model.md, "Latest Effective Outcome and Totals": the effective outcome is the most
     * advanced recorded decision — finance if present, otherwise senior, otherwise cost center,
     * otherwise pending.
     *
     * Every case is asserted separately, because the rule is a precedence chain and a single
     * combined assertion would still pass if the precedence were inverted.
     *
     * @dataProvider effectiveOutcomeCases
     */
    public function testEffectiveOutcomeIsTheMostAdvancedDecision(
        ?Decision $finance,
        ?Decision $senior,
        ?Decision $costCenter,
        ?Decision $expected
    ): void {
        self::assertSame($expected, SheetTotals::effectiveOutcome($finance, $senior, $costCenter));
    }

    /** @return array<string, array{0: ?Decision, 1: ?Decision, 2: ?Decision, 3: ?Decision}> */
    public static function effectiveOutcomeCases(): array
    {
        return [
            'no decision at all is pending' => [null, null, null, null],
            'cost center only' => [null, null, Decision::Approve, Decision::Approve],
            'senior outranks cost center' => [null, Decision::Reject, Decision::Approve, Decision::Reject],
            'finance outranks senior' => [Decision::Approve, Decision::Reject, Decision::Approve, Decision::Approve],
            'finance reject is final' => [Decision::Reject, Decision::Approve, Decision::Approve, Decision::Reject],
            'a finance return is pending, not senior approval' => [Decision::Return, Decision::Approve, Decision::Approve, null],
            'a senior return is pending, not cost-center approval' => [null, Decision::Return, Decision::Approve, null],
            'a cost-center return is pending' => [null, null, Decision::Return, null],
        ];
    }

    /**
     * A return parks the transaction as pending while the stage totals still record the approvals
     * that came before it, so the two never have to agree — which is the whole point of FR-062
     * separating the columns.
     */
    public function testAReturnedTransactionIsPendingWhileItsEarlierApprovalsStillCount(): void
    {
        $totals = SheetTotals::fromRows([
            [
                'amount' => 100,
                'latest' => SheetTotals::effectiveOutcome(Decision::Return, Decision::Approve, Decision::Approve),
                'manager' => Decision::Approve,
                'senior' => Decision::Approve,
                'finance' => Decision::Return,
            ],
        ]);

        self::assertSame(100, $totals->pending, 'A returned transaction is pending');
        self::assertSame(0, $totals->approved);
        self::assertSame(0, $totals->rejected);
        self::assertSame(100, $totals->managerApproved, 'The cost-center approval still counts at its own stage');
        self::assertSame(100, $totals->seniorApproved, 'The senior approval still counts at its own stage');
        self::assertSame(0, $totals->financeApproved, 'A finance return is not a finance approval');
    }

    /** A sheet with no transactions totals zero in every column rather than being absent. */
    public function testASheetWithNoTransactionsTotalsZero(): void
    {
        $totals = SheetTotals::fromRows([]);

        self::assertSame(0, $totals->current);
        self::assertSame(0, $totals->approved);
        self::assertSame(0, $totals->rejected);
        self::assertSame(0, $totals->pending);
        self::assertSame(0, $totals->managerApproved);
        self::assertSame(0, $totals->seniorApproved);
        self::assertSame(0, $totals->financeApproved);
    }
}
