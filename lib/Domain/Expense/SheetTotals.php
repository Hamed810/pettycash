<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Expense;

use OCA\PettyCash\Domain\Workflow\Decision;

/**
 * The headline figures a sheet is summarised by.
 *
 * `data-model.md` derives every one of them rather than storing it, because a stored total is a
 * second source of truth that goes stale the moment a decision or a correction lands. Two distinct
 * groupings are kept apart on purpose, and FR-062 requires both to be shown side by side:
 *
 * - **Headline** figures group each active revision's amount by its *latest effective decision*,
 *   so a transaction returned by finance is pending rather than counted as the cost-center
 *   approval it once earned.
 * - **Stage** figures are independent sums of the approvals recorded at each stage, so a
 *   cost-center approval still counts after a senior returns the transaction. This is what lets a
 *   reader see *where* a transaction stopped rather than only that it stopped.
 */
final readonly class SheetTotals
{
    public function __construct(
        public int $current,
        public int $approved,
        public int $rejected,
        public int $pending,
        public int $managerApproved,
        public int $seniorApproved,
        public int $financeApproved
    ) {}

    /**
     * @param list<array{amount:int,latest:?Decision,manager:?Decision,senior:?Decision,finance:?Decision}> $rows
     */
    public static function fromRows(array $rows): self
    {
        $current = $approved = $rejected = $pending = $manager = $senior = $finance = 0;

        foreach ($rows as $r) {
            $a = $r['amount'];
            $current += $a;

            if ($r['latest'] === Decision::Approve) {
                $approved += $a;
            } elseif ($r['latest'] === Decision::Reject) {
                $rejected += $a;
            } else {
                $pending += $a;
            }

            if ($r['manager'] === Decision::Approve) {
                $manager += $a;
            }
            if ($r['senior'] === Decision::Approve) {
                $senior += $a;
            }
            if ($r['finance'] === Decision::Approve) {
                $finance += $a;
            }
        }

        return new self($current, $approved, $rejected, $pending, $manager, $senior, $finance);
    }

    /**
     * The effective outcome of one active revision, per `data-model.md`: the most advanced recorded
     * decision — finance if there is one, otherwise senior, otherwise cost center, otherwise pending.
     *
     * A return resolves to `null` (pending) rather than to the approval that preceded it, because
     * the transaction is waiting on the purchaser and the earlier approval is no longer its
     * outcome. The approval is not lost: it still counts towards that stage's own total.
     */
    public static function effectiveOutcome(
        ?Decision $finance,
        ?Decision $senior,
        ?Decision $costCenter
    ): ?Decision {
        $mostAdvanced = $finance ?? $senior ?? $costCenter;

        return $mostAdvanced === Decision::Return ? null : $mostAdvanced;
    }
}
