<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Workflow;

final class FinanceDecisionPolicy
{
    public function canApprove(TransactionStatus $status): bool
    {
        return in_array($status, [
            TransactionStatus::SeniorApproved,
            TransactionStatus::SeniorRejected,
        ], true);
    }

    public function canReject(TransactionStatus $status): bool
    {
        return in_array($status, [
            TransactionStatus::SeniorApproved,
            TransactionStatus::SeniorRejected,
            TransactionStatus::AwaitingFinanceReview,
        ], true);
    }

    public function canReturn(TransactionStatus $status): bool
    {
        return in_array($status, [
            TransactionStatus::SeniorApproved,
            TransactionStatus::SeniorRejected,
            TransactionStatus::AwaitingFinanceReview,
        ], true);
    }

    public function canFinanceApprove(TransactionStatus $status): bool
    {
        return $status === TransactionStatus::SeniorApproved;
    }

    public function canFinanceReject(TransactionStatus $status): bool
    {
        return in_array($status, [
            TransactionStatus::SeniorApproved,
            TransactionStatus::SeniorRejected,
            TransactionStatus::AwaitingFinanceReview,
        ], true);
    }

    public function canFinanceReturn(TransactionStatus $status): bool
    {
        return in_array($status, [
            TransactionStatus::SeniorApproved,
            TransactionStatus::SeniorRejected,
            TransactionStatus::AwaitingFinanceReview,
        ], true);
    }

    public function assertCanApprove(TransactionStatus $status): void
    {
        if (!$this->canFinanceApprove($status)) {
            throw new \InvalidArgumentException('Finance cannot approve a senior-rejected transaction');
        }
    }
}