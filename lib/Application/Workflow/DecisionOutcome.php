<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Workflow;

use OCA\PettyCash\Domain\Account\AccountMovement;
use OCA\PettyCash\Domain\Account\PettyCashAccount;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Workflow\WorkflowDecision;

/**
 * What a recorded decision produced.
 *
 * `contracts/ocs-api.yaml` answers `POST /transactions/{id}/decisions` with `DecisionResult`: the
 * decision that was recorded, the transaction it was recorded on, and — on a finance approval —
 * the account mutation it caused. Both services therefore returned only the transaction, and the
 * controller answered with a bare `TransactionDetail`: the decision that had just been taken
 * against an immutable history was not in the response at all, and neither was the money that
 * moved. The frontend was already written against `DecisionResult` and read
 * `result.transaction`, so it dereferenced `undefined` after every decision.
 *
 * The account effect is nullable because it is genuinely absent for a reject, a return, and every
 * non-finance stage: FR-040 records a consumption only for a final finance approval. A
 * zero-valued mutation on those paths would be a claim that money moved when none did.
 */
final readonly class DecisionOutcome
{
    public function __construct(
        public ExpenseTransaction $transaction,
        public WorkflowDecision $decision,
        public ?AccountMovement $movement = null,
        public ?PettyCashAccount $account = null,
    ) {}

    /**
     * Whether this decision moved money.
     *
     * Asked by the payload formatter so that the two cannot disagree: an `accountMutation` built
     * from a movement that is not there would need a null check, and a null check that returns an
     * empty object would report an effect that never happened.
     */
    public function hasAccountMutation(): bool
    {
        return $this->movement !== null && $this->account !== null;
    }
}
