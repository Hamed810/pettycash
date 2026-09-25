<?php
declare(strict_types=1);
namespace OCA\PettyCash\Domain\Workflow;

enum TransactionStatus: string
{
    case Draft = 'DRAFT';
    case AwaitingCostCenterReview = 'AWAITING_COST_CENTER_REVIEW';
    case CostCenterApproved = 'COST_CENTER_APPROVED';
    case CostCenterRejected = 'COST_CENTER_REJECTED';
    case AwaitingSeniorReview = 'AWAITING_SENIOR_REVIEW';
    case SeniorApproved = 'SENIOR_APPROVED';
    case SeniorRejected = 'SENIOR_REJECTED';
    case AwaitingFinanceReview = 'AWAITING_FINANCE_REVIEW';
    case AwaitingPurchaserCorrection = 'AWAITING_PURCHASER_CORRECTION';
    case CompletedApproved = 'COMPLETED_APPROVED';
    case CompletedRejected = 'COMPLETED_REJECTED';
}