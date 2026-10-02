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

    /**
     * Make a revision the transaction's current one and route it to cost-center review.
     */
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
                    // `SeniorApproved`, not `AwaitingFinanceReview`.
                    //
                    // `contracts/workflow.md` gives finance **two** rows — "Finance after senior
                    // approval" (approve, reject, return) and "Finance after senior rejection"
                    // (reject, return; approval forbidden) — so the senior's outcome has to be
                    // carried in the status for finance to tell them apart. This branch also
                    // sets `SeniorRejected` below, and the cost-centre branch likewise records
                    // `CostCenterApproved`/`CostCenterRejected`, so naming the outcome is the
                    // established pattern rather than a new one.
                    //
                    // Setting `AwaitingFinanceReview` here instead left `SeniorApproved` with no
                    // producer at all: it was read by `FinanceDecisionPolicy::canFinanceApprove()`
                    // and by nothing else in the codebase, so every senior-approved transaction
                    // arrived at finance in a state no one was allowed to approve. FR-038, FR-040,
                    // and FR-043 were unreachable, and the exception they raised reported the
                    // cause as "Finance cannot approve a senior-rejected transaction" for a
                    // transaction the senior had approved.
                    $this->status = TransactionStatus::SeniorApproved;
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
                // The stage advances to `FINAL` on a final decision, as the cost-centre and
                // senior branches above already advance it.
                //
                // It used not to, so a settled transaction reported `stage: FINANCE` beside
                // `status: COMPLETED_APPROVED` — a transaction that has been decided while
                // still saying it awaits the decision. `TransactionStage` declares `FINAL` for
                // exactly this, and `ExpenseSheet::recalculateStatusFrom()` orders the stages
                // with it last, so a stage left at `FINANCE` also kept a sheet whose
                // transactions were all decided from reaching its own completed state.
                if ($decision === Decision::Approve) {
                    $this->status = TransactionStatus::CompletedApproved;
                    $this->finalOutcome = 'APPROVED';
                    $this->stage = WorkflowStage::Final;
                } elseif ($decision === Decision::Reject) {
                    $this->status = TransactionStatus::CompletedRejected;
                    $this->finalOutcome = 'REJECTED';
                    $this->stage = WorkflowStage::Final;
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
     * Make a revision the one being worked on, without activating it.
     *
     * ## Why this exists
     *
     * `CreateCorrectionDraft::execute()` created the draft, saved it, and returned it — but never
     * attached it here. The draft was therefore an orphan: the transaction still referenced the
     * revision it was correcting, every later read re-loaded that one, and
     * `resubmitCorrection()`'s `activate($this->activeRevision)` activated **the version the
     * purchaser was trying to replace**. Measured against the contract fixture: after
     * create-then-resubmit the active revision was `77777777-…`, the fixture's original, and not the
     * created draft. The purchaser's corrections were computed, persisted, and then discarded —
     * the precise failure US4 exists to prevent, and what FR-049 and FR-050 forbid.
     *
     * The link is what `DbExpenseTransactionRepository::save()` writes to `active_revision_id`, and
     * `findActiveByTransactionId()` resolves through that column rather than the revision's own
     * `active` flag, so the revision round-trips as the working one while it is still a draft.
     *
     * ## Why this does not activate
     *
     * `workflow.md`'s Correction Contract clause 5 puts activation at **resubmission**: "Activation
     * atomically switches the active revision and routes it to cost-center review." Activating here
     * would change the transaction's current information (FR-050) before the purchaser had finished,
     * and would route a half-entered revision to a manager.
     *
     * The stage and status are deliberately left alone for the same reason. This is what
     * `updateTransaction()` must use while a transaction is being corrected: it previously called
     * `activate()`, which on every edit of a correction draft set the stage to `COST_CENTER` and the
     * status to `AWAITING_COST_CENTER_REVIEW` — so a purchaser's second edit ended correction and
     * routed the expense to a manager *before they resubmitted anything*.
     *
     * ## Why it does not deactivate what it replaces
     *
     * There is nowhere to deactivate it *to*. `pettycash_transaction_revisions` has no `active`
     * column — `DbTransactionRevisionRepository::mapRow()` derives `active` for the API — so
     * "which revision is current" is carried solely by `pettycash_transactions.active_revision_id`.
     * That pointer is what this method moves, and it is the whole of the answer: exactly one revision
     * is current because exactly one row points at it.
     *
     * An earlier attempt deactivated the superseded revision in memory and saved it, which silently
     * did nothing — `save()` writes `activated_at` and there is no flag to clear — and the correction
     * still came back with two revisions reporting `active: true`. The projection is fixed in
     * `mapRow()` instead, which is where the value actually comes from.
     */
    public function attachWorkingRevision(TransactionRevision $revision): void
    {
        $this->activeRevision = $revision;
        $this->lockVersion++;
        $this->updatedAt = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
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