<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Workflow;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\TransactionManager;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Application\Security\AuthorizationService;
use OCA\PettyCash\Application\Security\AuthorizationContext;
use OCA\PettyCash\Application\StaleRecordVersion;
use OCA\PettyCash\Domain\Audit\AuditEvent;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository;
use OCA\PettyCash\Domain\Shared\ActorSnapshot;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCA\PettyCash\Domain\Workflow\Repository\WorkflowDecisionRepository;
use OCA\PettyCash\Domain\Workflow\WorkflowDecision;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Application\Audit\DecisionEventType;

final readonly class ManagementReviewService
{
    public function __construct(
        private TransactionManager $transactionManager,
        private ExpenseSheetRepository $sheetRepository,
        private ExpenseTransactionRepository $transactionRepository,
        private TransactionRevisionRepository $revisionRepository,
        private CostCenterRepository $costCenterRepository,
        private RoleAssignmentRepository $roleAssignmentRepository,
        private WorkflowDecisionRepository $decisionRepository,
        private AuditWriter $auditWriter,
        private IdempotencyService $idempotencyService,
        private CurrentUser $currentUser,
        private UserDirectory $userDirectory,
        private AuthorizationService $authorizationService,
        private SupersededDecisionAudit $supersededDecisionAudit,
    ) {}

    /**
     * Record a management decision for a transaction
     *
     * @param string $transactionPublicId
     * @param WorkflowStage $stage
     * @param Decision $decision
     * @param string $explanation
     * @param string $commandId
     * @param int $expectedVersion
     * @return DecisionOutcome
     */
    public function recordDecision(
        string $transactionPublicId,
        WorkflowStage $stage,
        Decision $decision,
        string $explanation,
        string $commandId,
        int $expectedVersion
    ): DecisionOutcome {
        $actorUid = $this->currentUser->uid();

        // Carried out of the idempotency callback, which does not run at all on a replay.
        $recordedDecision = null;

        // FR-085. The refusal is raised inside the transaction below so that the read and the
        // attempted write are one unit, and caught here because the business transaction has to roll
        // back *before* the `SUPERSEDED_ATTEMPT` event is written — an event written before the
        // rollback would be discarded with it, and a reviewer who acted would leave no trace.
        //
        // Re-thrown unchanged: the caller is answered 409 by `OcsErrorMapper`'s existing `Conflict`
        // arm, which is where FR-085's status comes from.
        try {
            return $this->recordDecisionInTransaction(
                $transactionPublicId,
                $actorUid,
                $stage,
                $decision,
                $explanation,
                $commandId,
                $expectedVersion,
                $recordedDecision
            );
        } catch (SupersededDecisionAttempt $superseded) {
            $this->supersededDecisionAudit->record(
                $superseded,
                $actorUid,
                $this->getUserRole($actorUid, null),
                $commandId,
            );

            throw $superseded;
        }
    }

    /**
     * The body of {@see recordDecision()}, split out so the FR-085 catch sits outside the database
     * transaction rather than inside it.
     *
     * @param-out DecisionOutcome $recordedDecision
     */
    private function recordDecisionInTransaction(
        string $transactionPublicId,
        string $actorUid,
        WorkflowStage $stage,
        Decision $decision,
        string $explanation,
        string $commandId,
        int $expectedVersion,
        ?WorkflowDecision &$recordedDecision
    ): DecisionOutcome {
        return $this->transactionManager->transactional(function () use (
            $transactionPublicId,
            $actorUid,
            $stage,
            $decision,
            $explanation,
            $commandId,
            $expectedVersion,
            &$recordedDecision
        ): DecisionOutcome {
            // Check idempotency
            //
            // The stage is not part of the payload, and must not be: the first attempt advances
            // the transaction out of the stage it was decided at, so a replay arrives carrying a
            // different stage and hashes to a different command — which `IdempotencyService`
            // answers with 409, telling a client that retried after a timeout that its decision
            // had been refused when it had in fact been applied. The transaction and the decision
            // are what the command means, and both are here.
            $payload = [
                'transaction_id' => $transactionPublicId,
                'decision' => $decision->value,
                'command_id' => $commandId,
            ];
            
            $this->idempotencyService->execute($commandId, $payload, function () use (
                $transactionPublicId,
                $actorUid,
                $stage,
                $decision,
                $explanation,
                $commandId,
                $expectedVersion,
                &$recordedDecision
            ): array {
                $transaction = $this->transactionRepository->findByPublicId($transactionPublicId);

                if ($transaction === null) {
                    throw new InvalidArgumentException('Transaction not found');
                }

                // Distinct from FR-085's `SupersededDecisionAttempt`, which is also a 409: that is a
                // decision already recorded against this stage, whereas this is the caller's view of
                // the record being stale. Both answer 409; the error codes stay distinguishable.
                if ($transaction->lockVersion !== $expectedVersion) {
                    throw StaleRecordVersion::forResource(
                        'transaction',
                        $transactionPublicId,
                        $expectedVersion,
                        $transaction->lockVersion
                    );
                }

                // Authorization check.
                //
                // The scope in the context must be the cost centers the *actor* is
                // actually assigned to, never the cost center of the record under
                // review: `canReview()` asks whether the subject cost center is in
                // that list, so a list built from the subject itself makes the
                // comparison permanently true and lets a manager decide any cost
                // center's transaction (FR-004, FR-005, FR-009).
                $costCenterCode = $this->resolveCostCenterCode($transaction);
                $costCenterId = $costCenterCode !== null
                    ? $this->costCenterRepository->findByCode($costCenterCode)?->id
                    : null;

                $context = new AuthorizationContext(
                    $actorUid,
                    $transaction->ownerUid,
                    $this->getUserRoles($actorUid),
                    $this->getManagedCostCenterCodes($actorUid, $stage),
                    false
                );

                if (!$this->authorizationService->canReview($context, $stage, $costCenterCode ?? '')) {
                    throw new LogicException('User not authorized for this review stage');
                }

                // FR-085: first write wins.
                //
                // **Placed before the stage guard, and that ordering is the whole point.** A decision
                // already recorded at this stage *is* the reason the transaction is no longer at this
                // stage — the first approval advanced it. So with the stage guard first, this check was
                // unreachable: every superseded attempt was answered `422 "Transaction is not at
                // COST_CENTER stage"`, which names a stage mismatch and never mentions that a decision
                // is already in force. FR-085 requires the caller to be told *that*, because that is
                // what tells it to reload rather than to correct its request.
                //
                // **Placed after authorization, deliberately.** Moving it earlier would answer an
                // out-of-scope manager with `409` and, in doing so, confirm that a decision exists on
                // a transaction they may not be able to see. A refusal that carries information is a
                // disclosure, which is the defect T029 was raised for.
                //
                // **Placed after the version guard, also deliberately.** `Stale version` is the
                // optimistic check `data-model.md` describes, it answers 422, and six other call
                // sites depend on that behaviour. FR-085 does not ask for it to change, and widening
                // it would silently reclassify every concurrent edit as a superseded attempt.
                $this->refuseIfAlreadyDecided($transaction, $stage, $decision);

                // Verify transaction is at the expected stage
                if ($transaction->stage !== $stage) {
                    throw new InvalidArgumentException("Transaction is not at {$stage->value} stage");
                }

                // Validate explanation required for reject/return
                if (in_array($decision, [Decision::Reject, Decision::Return], true) && trim($explanation) === '') {
                    throw new InvalidArgumentException('Explanation required for reject or return');
                }

                // Record the decision on the transaction domain model
                $workflowDecision = $transaction->recordDecision(
                    $stage,
                    $decision,
                    $explanation,
                    $actorUid,
                    $this->userDirectory->displayName($actorUid),
                    $this->getUserRole($actorUid, $costCenterCode),
                    $commandId
                );

                // Persist it.
                //
                // This line was absent, and the comment below used to claim it happened.
                // `ExpenseTransactionRepository::save()` writes the transaction row and
                // nothing else, so **no decision was ever written** — not at cost centre, not
                // at senior, not at finance. The consequence was not a missing history: the
                // transaction's status moved on while `pettycash_workflow_decisions` stayed
                // empty, so the audit trail an approver reads back was not there, and a
                // finance approval answered 422 because its consumption's
                // `source_decision_id` could not be resolved to a row.
                //
                // Before the audit event, so the event's `decision_id` is the real surrogate
                // key rather than the null an unpersisted aggregate carries.
                $this->decisionRepository->save($workflowDecision);
                $recordedDecision = $workflowDecision;

                // Create audit event using the append method
                $auditData = [
                    'id' => (string)Uuid::random(),
                    'sheet_id' => $transaction->sheetId,
                    'transaction_id' => $transaction->dbId,
                    'revision_id' => $transaction->revision()->dbId,
                    'stage' => $stage->value,
                    'decision_id' => $workflowDecision->dbId,
                    'entity_type' => 'transaction',
                    'entity_id' => $transactionPublicId,
                    // FR-051 names approvals, rejections and returns as separate records, and both
                    // decision services route through the same mapper so they cannot disagree.
                    'event_type' => DecisionEventType::for($decision)->value,
                    'actor_uid' => $actorUid,
                    'actor_name' => $this->userDirectory->displayName($actorUid),
                    'actor_role' => $this->getUserRole($actorUid, $costCenterCode),
                    'explanation' => $explanation,
                    // `before` and `after` are plain arrays: `AuditWriterService` reads those two
                    // keys and does the encoding itself. This payload used to send `before_json` and
                    // `after_json`, which the writer does not read at all, so the `?? []` default
                    // stored the literal "[]" in both columns and every value above was discarded --
                    // on the DECISION path, meaning no approval, rejection or return recorded its
                    // decision, stage, explanation or revision number. FR-052 requires those
                    // before-and-after values, and FR-053 means the rows already written cannot be
                    // repaired. `AuditWriterService` now refuses this spelling outright.
                    //
                    // `before` is genuinely empty: deciding mutates the transaction, so there is no
                    // pre-decision state captured at this point to compare against.
                    'before' => [],
                    'after' => [
                        'decision' => $decision->value,
                        'stage' => $stage->value,
                        'explanation' => $explanation,
                        'revision_number' => $transaction->revision()->number(),
                    ],
                    'command_id' => $commandId,
                    'occurred_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
                ];
                $this->auditWriter->append($auditData);

                $this->transactionRepository->save($transaction);

                return ['transaction_id' => $transactionPublicId];
            });

            // Reload and return the transaction
            $transaction = $this->transactionRepository->findByPublicId($transactionPublicId);
            if ($transaction === null) {
                throw new LogicException('Transaction not found after decision');
            }

            // On a replay the callback never ran, so the decision is read back by the
            // command id that recorded it. A response that reported no decision for a
            // replayed command would make the two responses of one command differ, and the
            // caller has no way to tell that it is the same decision being described.
            if ($recordedDecision === null) {
                $recordedDecision = $this->decisionRepository->findByCommandId($commandId);
            }
            if ($recordedDecision === null) {
                throw new LogicException('The recorded decision could not be found for this command');
            }

            return new DecisionOutcome($transaction, $recordedDecision);
        });
    }

    /**
     * FR-085: refuse a decision for a stage this revision has already been decided at.
     *
     * The unique index `pettycash_workflow_decisions_revision_stage_uniq` makes a second row
     * impossible, so no double-booking is possible today — the gap this closes is the *outcome*: a
     * stale second decision used to be answered "Transaction is not at COST_CENTER stage", which
     * names a stage mismatch and never mentions that a decision is already in force, and the reviewer
     * who tried it left no record. Reading first converts that into an explained 409 plus a
     * `SUPERSEDED_ATTEMPT` event; `DbWorkflowDecisionRepository::save()` closes the remaining window
     * between this read and that insert, for a race that arrives in exactly between them.
     *
     * @throws SupersededDecisionAttempt when a decision already exists for (revision, stage)
     */
    private function refuseIfAlreadyDecided(
        ExpenseTransaction $transaction,
        WorkflowStage $stage,
        Decision $decision
    ): void {
        $revisionDbId = $transaction->revision()->dbId;
        if ($revisionDbId === null) {
            // An unpersisted revision has no row to have been decided on, so there is nothing to be
            // superseded by. Refusing here would be refusing on no evidence.
            return;
        }

        $recorded = $this->decisionRepository->findByRevisionAndStage($revisionDbId, $stage);
        if ($recorded === []) {
            return;
        }

        // FR-053 makes decisions immutable and the unique index admits one per (revision, stage), so
        // the list holds exactly the decision in force. It is read by `decided_at ASC`, hence the
        // last element rather than the first.
        throw new SupersededDecisionAttempt(
            $transaction->id,
            $transaction->revision()->id(),
            $stage,
            $decision,
            $recorded[count($recorded) - 1]->decision,
        );
    }

    /**
     * Get cost center queue for the current manager
     *
     * @param int $page
     * @param int $pageSize
     * @return array{items: list<ExpenseTransaction>, total: int}
     */
    public function getCostCenterQueue(int $page = 1, int $pageSize = 25): array
    {
        $actorUid = $this->currentUser->uid();
        $roles = $this->getUserRoles($actorUid);
        
        // Get cost centers managed by this user
        $managedCostCenterIds = [];
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        foreach ($roles as $role) {
            if ($role === \OCA\PettyCash\Domain\Shared\Enum\Role::CostCenterManager) {
                $assignments = $this->roleAssignmentRepository->findActiveByUserAndRole($actorUid, $role, $now);
                foreach ($assignments as $assignment) {
                    if ($assignment->costCenterId !== null) {
                        $managedCostCenterIds[] = $assignment->costCenterId;
                    }
                }
            }
        }

        if ($managedCostCenterIds === []) {
            return ['items' => [], 'total' => 0];
        }

        $offset = ($page - 1) * $pageSize;
        $allTransactions = [];
        foreach ($managedCostCenterIds as $costCenterId) {
            $transactions = $this->transactionRepository->findByCostCenterId(
                $costCenterId,
                WorkflowStage::CostCenter,
                null,
                $offset,
                $pageSize
            );
            $allTransactions = array_merge($allTransactions, $transactions);
        }
        
        // Sort by created_at descending
        usort($allTransactions, fn($a, $b) => ($b->createdAt ?? new DateTimeImmutable()) <=> ($a->createdAt ?? new DateTimeImmutable()));
        
        // Apply pagination
        $items = array_slice($allTransactions, 0, $pageSize);
        $total = count($allTransactions);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Get senior manager queue
     *
     * @param int $page
     * @param int $pageSize
     * @return array{items: list<ExpenseTransaction>, total: int}
     */
    public function getSeniorQueue(int $page = 1, int $pageSize = 25): array
    {
        $actorUid = $this->currentUser->uid();
        $roles = $this->getUserRoles($actorUid);
        
        $managedCostCenterIds = [];
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        foreach ($roles as $role) {
            if ($role === \OCA\PettyCash\Domain\Shared\Enum\Role::SeniorManager) {
                $assignments = $this->roleAssignmentRepository->findActiveByUserAndRole($actorUid, $role, $now);
                foreach ($assignments as $assignment) {
                    if ($assignment->costCenterId !== null) {
                        $managedCostCenterIds[] = $assignment->costCenterId;
                    }
                }
            }
        }

        if ($managedCostCenterIds === []) {
            return ['items' => [], 'total' => 0];
        }

        $offset = ($page - 1) * $pageSize;
        $allTransactions = [];
        foreach ($managedCostCenterIds as $costCenterId) {
            $transactions = $this->transactionRepository->findByCostCenterId(
                $costCenterId,
                WorkflowStage::Senior,
                null,
                $offset,
                $pageSize
            );
            $allTransactions = array_merge($allTransactions, $transactions);
        }
        
        usort($allTransactions, fn($a, $b) => ($b->createdAt ?? new DateTimeImmutable()) <=> ($a->createdAt ?? new DateTimeImmutable()));
        
        $items = array_slice($allTransactions, 0, $pageSize);
        $total = count($allTransactions);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Get purchaser's returned transactions (correction queue)
     *
     * @param int $page
     * @param int $pageSize
     * @return array{items: list<ExpenseTransaction>, total: int}
     */
    public function getReturnsQueue(int $page = 1, int $pageSize = 25): array
    {
        $actorUid = $this->currentUser->uid();
        $offset = ($page - 1) * $pageSize;
        
        $transactions = $this->transactionRepository->findByOwnerUid(
            $actorUid,
            WorkflowStage::Correction,
            \OCA\PettyCash\Domain\Workflow\TransactionStatus::AwaitingPurchaserCorrection,
            $offset,
            $pageSize
        );

        $total = count($transactions);

        return ['items' => $transactions, 'total' => $total];
    }

    /**
     * Get finance queue (shared queue for all finance officers)
     *
     * @param int $page
     * @param int $pageSize
     * @return array{items: list<ExpenseTransaction>, total: int}
     */
    public function getFinanceQueue(int $page = 1, int $pageSize = 25): array
    {
        $actorUid = $this->currentUser->uid();
        $roles = $this->getUserRoles($actorUid);
        
        // Check if user has finance role
        if (!in_array(\OCA\PettyCash\Domain\Shared\Enum\Role::Finance, $roles, true)) {
            return ['items' => [], 'total' => 0];
        }

        $offset = ($page - 1) * $pageSize;
        
        $transactions = $this->transactionRepository->findForFinanceQueue($offset, $pageSize);

        // For total count, we need to fetch all (or add a count method)
        $allTransactions = $this->transactionRepository->findForFinanceQueue(0, 1000);
        $total = count($allTransactions);

        return ['items' => $transactions, 'total' => $total];
    }

    /**
     * The cost-center code a decision is scoped to.
     *
     * The revision carries the code as a submission-time snapshot, which is what the
     * queues and the audit trail display. Falling back to the numeric join key keeps
     * the decision path working for a revision whose snapshot is missing, rather than
     * silently treating an unresolvable cost center as "no scope required".
     */
    private function resolveCostCenterCode(ExpenseTransaction $transaction): ?string
    {
        $revision = $transaction->revision();

        if ($revision->costCenterCode !== null && $revision->costCenterCode !== '') {
            return $revision->costCenterCode;
        }

        if ($revision->costCenterDbId !== null) {
            return $this->costCenterRepository->findById($revision->costCenterDbId)?->code();
        }

        return null;
    }

    /**
     * The cost-center codes the actor is actively assigned to for this stage.
     *
     * Role assignments store the cost center as a numeric join key, so each one is
     * resolved to its code: the scope comparison in `AuthorizationService::canReview()`
     * is strict, and a numeric id compared against a code could never match. The
     * finance stage is a shared queue with no cost-center scoping and the correction
     * stage is owner-only, so neither carries a scope.
     *
     * @return list<string>
     */
    private function getManagedCostCenterCodes(string $actorUid, WorkflowStage $stage): array
    {
        $requiredRole = match ($stage) {
            WorkflowStage::CostCenter => \OCA\PettyCash\Domain\Shared\Enum\Role::CostCenterManager,
            WorkflowStage::Senior => \OCA\PettyCash\Domain\Shared\Enum\Role::SeniorManager,
            default => null,
        };

        if ($requiredRole === null) {
            return [];
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $codes = [];
        foreach ($this->roleAssignmentRepository->findActiveByUserAndRole($actorUid, $requiredRole, $now) as $assignment) {
            if ($assignment->costCenterId === null) {
                continue;
            }
            $code = $this->costCenterRepository->findById($assignment->costCenterId)?->code();
            if ($code !== null && $code !== '') {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    private function getUserRoles(string $userUid): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $assignments = $this->roleAssignmentRepository->findActiveByUserUid($userUid, $now);
        $roles = [];
        foreach ($assignments as $assignment) {
            if ($assignment->active) {
                $roles[] = $assignment->role;
            }
        }
        // Every authenticated user is at least a purchaser
        if (!in_array(\OCA\PettyCash\Domain\Shared\Enum\Role::Purchaser, $roles, true)) {
            $roles[] = \OCA\PettyCash\Domain\Shared\Enum\Role::Purchaser;
        }
        return $roles;
    }

    private function getUserRole(string $userUid, ?string $costCenterCode): string
    {
        $roles = $this->getUserRoles($userUid);
        
        // Return the highest relevant role for the context
        if (in_array(\OCA\PettyCash\Domain\Shared\Enum\Role::SeniorManager, $roles, true)) {
            return 'SENIOR_MANAGER';
        }
        if (in_array(\OCA\PettyCash\Domain\Shared\Enum\Role::CostCenterManager, $roles, true)) {
            return 'COST_CENTER_MANAGER';
        }
        if (in_array(\OCA\PettyCash\Domain\Shared\Enum\Role::Finance, $roles, true)) {
            return 'FINANCE';
        }
        if (in_array(\OCA\PettyCash\Domain\Shared\Enum\Role::Administrator, $roles, true)) {
            return 'ADMINISTRATOR';
        }
        return 'PURCHASER';
    }

    /**
     * Get transaction by public ID for decision recording (no owner scoping)
     *
     * @param string $transactionPublicId
     * @return ExpenseTransaction|null
     */
    public function getTransactionForDecision(string $transactionPublicId): ?\OCA\PettyCash\Domain\Expense\ExpenseTransaction
    {
        return $this->transactionRepository->findByPublicId($transactionPublicId);
    }
}