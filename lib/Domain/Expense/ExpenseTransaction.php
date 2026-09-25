<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Expense;

use DateTimeImmutable;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowDecision;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;

final class ExpenseTransaction
{
    public WorkflowStage $stage = WorkflowStage::Draft;
    public TransactionStatus $status = TransactionStatus::Draft;
    public ?string $finalOutcome = null;

    /** @var list<WorkflowDecision> */
    private array $decisions = [];

    public function __construct(
        public string $id,
        public string $ownerUid,
        private TransactionRevision $activeRevision,
        public ?int $sheetId = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
        public int $lockVersion = 0,
        public ?int $dbId = null,
        public ?string $publicId = null
    ) {}

    public function revision(): TransactionRevision
    {
        return $this->activeRevision;
    }

    public function activate(TransactionRevision $revision): void
    {
        $revision->activate();
        $this->activeRevision = $revision;
        $this->stage = WorkflowStage::CostCenter;
        $this->status = TransactionStatus::AwaitingCostCenterReview;
    }

    /**
     * Record a workflow decision for the current stage
     *
     * @param WorkflowStage $stage
     * @param Decision $decision
     * @param string $explanation
     * @param string $actorUid
     * @param string $actorName
     * @param string $actorRole
     * @param string $commandId
     * @return WorkflowDecision
     */
    public function recordDecision(
        WorkflowStage $stage,
        Decision $decision,
        string $explanation,
        string $actorUid,
        string $actorName = '',
        string $actorRole = '',
        string $commandId = ''
    ): WorkflowDecision {
        // Validate stage matches current stage
        if ($stage !== $this->stage) {
            throw new \InvalidArgumentException("Decision stage {$stage->value} does not match current stage {$this->stage->value}");
        }

        // Validate explanation required for reject/return
        if (in_array($decision, [Decision::Reject, Decision::Return], true) && trim($explanation) === '') {
            throw new \InvalidArgumentException('Explanation required');
        }

        $decisionId = \OCA\PettyCash\Domain\Shared\Uuid::random();
        $workflowDecision = WorkflowDecision::create(
            (string)$decisionId,
            $this->id,
            $this->activeRevision->id(),
            $stage,
            $decision,
            $explanation,
            $commandId
        );
        $workflowDecision->actorUid = $actorUid;
        $workflowDecision->actorName = $actorName;
        $workflowDecision->actorRole = $actorRole;
        $workflowDecision->decidedAt = new DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->decisions[] = $workflowDecision;

        // Update transaction status and stage based on decision
        $this->applyDecision($decision, $stage);

        return $workflowDecision;
    }

    /**
     * Get all decisions for this transaction
     *
     * @return list<WorkflowDecision>
     */
    public function getDecisions(): array
    {
        return $this->decisions;
    }

    private function applyDecision(Decision $decision, WorkflowStage $stage): void
    {
        switch ($stage) {
            case WorkflowStage::CostCenter:
                if ($decision === Decision::Approve) {
                    $this->status = TransactionStatus::CostCenterApproved;
                    $this->stage = WorkflowStage::Senior;
                } elseif ($decision === Decision::Reject) {
                    $this->status = TransactionStatus::CostCenterRejected;
                    $this->stage = WorkflowStage::Senior;
                } elseif ($decision === Decision::Return) {
                    $this->status = TransactionStatus::AwaitingPurchaserCorrection;
                    $this->stage = WorkflowStage::Correction;
                }
                break;

            case WorkflowStage::Senior:
                if ($decision === Decision::Approve) {
                    $this->status = TransactionStatus::AwaitingFinanceReview;
                    $this->stage = WorkflowStage::Finance;
                } elseif ($decision === Decision::Reject) {
                    $this->status = TransactionStatus::SeniorRejected;
                    $this->stage = WorkflowStage::Finance;
                } elseif ($decision === Decision::Return) {
                    $this->status = TransactionStatus::AwaitingPurchaserCorrection;
                    $this->stage = WorkflowStage::Correction;
                }
                break;

            case WorkflowStage::Finance:
                if ($decision === Decision::Approve) {
                    $this->status = TransactionStatus::CompletedApproved;
                    $this->finalOutcome = 'APPROVED';
                } elseif ($decision === Decision::Reject) {
                    $this->status = TransactionStatus::CompletedRejected;
                    $this->finalOutcome = 'REJECTED';
                } elseif ($decision === Decision::Return) {
                    $this->status = TransactionStatus::AwaitingPurchaserCorrection;
                    $this->stage = WorkflowStage::Correction;
                }
                break;

            case WorkflowStage::Correction:
                // Correction resubmission handled by ResubmitCorrection service
                break;
        }

        $this->lockVersion++;
        $this->updatedAt = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Check if a correction can be created for this transaction
     * Per FR-046: V1 MUST NOT reopen or rewrite a transaction after final financial approval
     */
    public function canCreateCorrection(): bool
    {
        return !in_array($this->status, [
            TransactionStatus::CompletedApproved,
            TransactionStatus::CompletedRejected,
        ], true);
    }

    /**
     * Create a correction revision for this transaction
     */
    public function createCorrectionRevision(string $revisionId): TransactionRevision
    {
        if (!$this->canCreateCorrection()) {
            throw new \LogicException('Cannot correct a finance-approved or finance-rejected transaction');
        }
        return $this->activeRevision->correction($revisionId);
    }

    /**
     * Resubmit a correction draft for review
     * Activates the draft revision and routes it back to cost-center review
     */
    public function resubmitCorrection(): int
    {
        $this->activate($this->activeRevision);
        $this->lockVersion++;
        $this->updatedAt = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        
        // Return the submitted total in minor units
        return $this->activeRevision->amount()->minorUnits();
    }
}