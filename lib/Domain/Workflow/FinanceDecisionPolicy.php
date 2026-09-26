<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Workflow;

use InvalidArgumentException;

/**
 * Which decisions finance may record, per `contracts/workflow.md`.
 *
 * The contract gives finance two rows:
 *
 * | Stage                        | Allowed decisions           |
 * |------------------------------|-----------------------------|
 * | Finance after senior approval | Approve, reject, return    |
 * | Finance after senior rejection | Reject, return             |
 *
 * The second row is what makes the matrix a *matrix*: whether finance may approve is a function of
 * the senior's outcome, not of finance's role. FR-039 states the same rule.
 */
final class FinanceDecisionPolicy
{
    public function canApprove(TransactionStatus $status): bool
    {
        return $this->canFinanceApprove($status);
    }

    /**
     * Only a senior-approved transaction may be approved by finance.
     *
     * This used to also accept `SeniorRejected`, on the reasoning that both statuses mean "the
     * senior has decided". That conflation is exactly what FR-039 forbids, and it is why the
     * capability endpoint offered an Approve button on a senior-rejected transaction while the
     * command path refused it.
     */
    public function canFinanceApprove(TransactionStatus $status): bool
    {
        return $status === TransactionStatus::SeniorApproved;
    }

    public function canReject(TransactionStatus $status): bool
    {
        return $this->canFinanceReject($status);
    }

    public function canFinanceReject(TransactionStatus $status): bool
    {
        return in_array($status, [
            TransactionStatus::SeniorApproved,
            TransactionStatus::SeniorRejected,
            TransactionStatus::AwaitingFinanceReview,
        ], true);
    }

    public function canReturn(TransactionStatus $status): bool
    {
        return $this->canFinanceReturn($status);
    }

    public function canFinanceReturn(TransactionStatus $status): bool
    {
        return in_array($status, [
            TransactionStatus::SeniorApproved,
            TransactionStatus::SeniorRejected,
            TransactionStatus::AwaitingFinanceReview,
        ], true);
    }

    /**
     * The capability for one specific decision, which is what a caller actually needs.
     *
     * Separate `can*` methods invite the mistake the settlement path made: asking only
     * "may finance approve this?" and applying the answer to whichever decision was requested.
     */
    public function can(TransactionStatus $status, Decision $decision): bool
    {
        return match ($decision) {
            Decision::Approve => $this->canFinanceApprove($status),
            Decision::Reject => $this->canFinanceReject($status),
            Decision::Return => $this->canFinanceReturn($status),
        };
    }

    /**
     * Refuse a decision the current status does not permit.
     *
     * The message names the decision as well as the status: a caller that asked to reject and was
     * told only that it could not approve had to work out the rest, and the two are different
     * faults with different causes.
     */
    public function assertCanDecide(TransactionStatus $status, Decision $decision): void
    {
        if ($this->can($status, $decision)) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Finance cannot %s a transaction in status %s%s',
            $decision->value,
            $status->value,
            $status === TransactionStatus::SeniorRejected ? ' (senior-rejected)' : ''
        ));
    }

    public function assertCanApprove(TransactionStatus $status): void
    {
        $this->assertCanDecide($status, Decision::Approve);
    }
}
