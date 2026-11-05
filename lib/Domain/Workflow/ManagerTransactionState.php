<?php
declare(strict_types=1);
namespace OCA\PettyCash\Domain\Workflow;

/**
 * A transaction's state **from one cost-centre manager's point of view**.
 *
 * Deliberately not a `TransactionStatus`: the same stored status can mean different things to two
 * managers assigned to the same cost centre. A transaction decided by manager A is
 * `DECIDED_BY_MANAGER` for A and invisible to B, while one awaiting review is awaiting *every*
 * manager assigned to that cost centre. `SheetStatus`/`TransactionStatus` cannot express that, and
 * inferring whole-sheet completion from one manager's decisions is exactly what FR-006-047/048
 * forbids.
 */
enum ManagerTransactionState: string
{
    /** The current revision is at cost-centre review and this manager has not decided it yet. */
    case AwaitingManagerDecision = 'AWAITING_MANAGER_DECISION';

    /** This manager approved or rejected the current revision at cost-centre review. */
    case DecidedByManager = 'DECIDED_BY_MANAGER';

    /** This manager returned the current revision for correction. */
    case ReturnedForCorrection = 'RETURNED_FOR_CORRECTION';
}
