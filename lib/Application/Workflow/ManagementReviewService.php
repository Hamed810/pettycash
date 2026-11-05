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
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCA\PettyCash\Domain\Workflow\ManagerTransactionState;
use OCA\PettyCash\Domain\Workflow\Repository\WorkflowDecisionRepository;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
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
            
            $this->idempotencyService->execute($commandId, $actorUid, 'review.decision.' . $stage->value, $transactionPublicId, $payload, function () use (
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
            }, 201);

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
        $managedCostCenterIds = $this->managedCostCenterIds($actorUid, Role::CostCenterManager);

        if ($managedCostCenterIds === []) {
            return ['items' => [], 'total' => 0];
        }

        // One query over the de-duplicated scope, then one global pagination pass. Fetching per
        // assignment (or per role entry) merged the same rows repeatedly and applied the page offset
        // inside each cost centre before the outer slice, so `total` was inflated and pages could
        // repeat or skip transactions.
        return $this->paginateUniqueQueue(
            $this->transactionRepository->findByCostCenterIds(
                $managedCostCenterIds,
                WorkflowStage::CostCenter
            ),
            $page,
            $pageSize
        );
    }

    /**
     * One sheet as the acting cost-centre manager may see it — B001/B002/B003.
     *
     * **Authorization is the scope check, and the scope is the actor's active manager assignments.**
     * A sheet is answered only when at least one of its current-revision transactions is in a cost
     * centre this manager actively manages. An unknown sheet and an out-of-scope sheet produce the
     * same `null`, so the controller can answer one `404` for both and the endpoint cannot be used to
     * confirm that a sheet exists outside the caller's scope (FR-004/FR-005/FR-009).
     *
     * **The transaction set is this manager's, not the sheet's.** A transaction is included only when
     * it is awaiting any manager of that cost centre, was decided by *this* manager, or was returned
     * by *this* manager. Another manager's cost-centre decision is therefore never disclosed here, and
     * a sheet holding only such a decision is indistinguishable from an absent sheet to this caller.
     *
     * **Historical revisions do not count.** Manager state is read from the transaction's current
     * revision only. A return recorded on revision 1 is not this manager's state once revision 2 is
     * active and awaiting review again; the new revision is awaiting a decision, which is what the
     * purchaser's resubmission actually produced.
     *
     * The returned `progress` is manager-scoped on purpose. `managerComplete` means *nothing awaits
     * this manager*; it says nothing about whole-sheet cost-centre completion or Senior eligibility,
     * which FR-006-047/048 keeps as a separate, sheet-wide question.
     *
     * @return array{
     *   sheet: \OCA\PettyCash\Domain\Expense\ExpenseSheet,
     *   transactions: list<array{
     *     transaction: ExpenseTransaction,
     *     state: ManagerTransactionState,
     *     decision: ?WorkflowDecision
     *   }>,
     *   progress: array{
     *     awaitingManager: int,
     *     decidedByManager: int,
     *     returnedForCorrection: int,
     *     total: int,
     *     managerComplete: bool
     *   }
     * }|null
     */
    public function getCostCenterSheetForManager(string $sheetPublicId): ?array
    {
        $actorUid = $this->currentUser->uid();
        ['ids' => $managedCostCenterIds, 'codes' => $managedCostCenterCodes] = $this->managedCostCenterScope($actorUid);

        if ($managedCostCenterIds === [] && $managedCostCenterCodes === []) {
            return null;
        }

        $sheet = $this->sheetRepository->findByPublicId($sheetPublicId);
        if ($sheet === null || $sheet->dbId === null) {
            return null;
        }

        $transactions = [];
        $progress = [
            'awaitingManager' => 0,
            'decidedByManager' => 0,
            'returnedForCorrection' => 0,
            'total' => 0,
            'managerComplete' => false,
        ];

        foreach ($this->transactionRepository->findBySheetId($sheet->dbId) as $transaction) {
            $revision = $transaction->revision();
            $costCenterDbId = $revision->costCenterDbId;

            if ($costCenterDbId === null || !isset($managedCostCenterIds[$costCenterDbId])) {
                $code = $revision->costCenterCode;
                if (($code === null || $code === '') && $costCenterDbId !== null) {
                    $code = $this->costCenterRepository->findById($costCenterDbId)?->code();
                }
                if ($code === null || $code === '' || !isset($managedCostCenterCodes[$code])) {
                    continue;
                }
            }

            $decision = $this->currentCostCenterDecision($transaction);

            if ($decision === null) {
                if ($transaction->stage !== WorkflowStage::CostCenter
                    || $transaction->status !== TransactionStatus::AwaitingCostCenterReview
                ) {
                    continue;
                }
                $state = ManagerTransactionState::AwaitingManagerDecision;
            } elseif ($decision->actorUid !== $actorUid) {
                // Another manager's decision is outside this manager's scoped read. It is skipped,
                // not reported as pending, so a sheet they decided cannot be made to look unfinished.
                continue;
            } elseif ($decision->decision === Decision::Return) {
                $state = ManagerTransactionState::ReturnedForCorrection;
            } else {
                $state = ManagerTransactionState::DecidedByManager;
            }

            $transactions[] = [
                'transaction' => $transaction,
                'state' => $state,
                'decision' => $state === ManagerTransactionState::AwaitingManagerDecision ? null : $decision,
            ];

            $progress['total']++;
            match ($state) {
                ManagerTransactionState::AwaitingManagerDecision => $progress['awaitingManager']++,
                ManagerTransactionState::DecidedByManager => $progress['decidedByManager']++,
                ManagerTransactionState::ReturnedForCorrection => $progress['returnedForCorrection']++,
            };
        }

        if ($transactions === []) {
            return null;
        }

        // "Complete" for this manager only: no current-revision transaction in their scope still
        // awaits their decision. It is deliberately not a claim about the other managers, the
        // sheet's cost-centre stage as a whole, or Senior eligibility (FR-006-047/048).
        $progress['managerComplete'] = $progress['awaitingManager'] === 0;

        return [
            'sheet' => $sheet,
            'transactions' => $transactions,
            'progress' => $progress,
        ];
    }

    /**
     * The active cost centres this user manages, by surrogate key and by code.
     *
     * Assignments are read through the same repository/date rule as `getCostCenterQueue()`, but the
     * result is de-duplicated here. The queue's own duplicate/pagination defect is a separate bug
     * workflow item and is deliberately not touched by this read.
     *
     * @return array{ids: array<int, true>, codes: array<string, true>}
     */
    private function managedCostCenterScope(string $actorUid): array
    {
        $ids = [];
        $codes = [];
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        foreach ($this->roleAssignmentRepository->findActiveByUserAndRole($actorUid, Role::CostCenterManager, $now) as $assignment) {
            if ($assignment->costCenterId === null) {
                continue;
            }

            $ids[$assignment->costCenterId] = true;

            $code = $this->costCenterRepository->findById($assignment->costCenterId)?->code();
            if ($code !== null && $code !== '') {
                $codes[$code] = true;
            }
        }

        return ['ids' => $ids, 'codes' => $codes];
    }

    /**
     * The cost-centre decision recorded against this transaction's **current** revision.
     *
     * The repository answers every revision of the transaction, so the decision has to be narrowed
     * to the revision that is active now. A decision on a superseded revision is history: after a
     * correction and resubmission, the transaction is awaiting review on its new revision even
     * though the old one carries a decision from this same manager.
     */
    private function currentCostCenterDecision(ExpenseTransaction $transaction): ?WorkflowDecision
    {
        if ($transaction->dbId === null) {
            return null;
        }

        $currentRevisionId = $transaction->revision()->id();
        $current = null;

        foreach ($this->decisionRepository->findByTransactionId($transaction->dbId) as $decision) {
            if ($decision->stage !== WorkflowStage::CostCenter) {
                continue;
            }
            if ($decision->revisionId !== $currentRevisionId) {
                continue;
            }
            // Ordered by `decided_at ASC`; the unique index admits one per (revision, stage), so the
            // last match is the decision in force rather than a guess between alternatives.
            $current = $decision;
        }

        return $current;
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
        $managedCostCenterIds = $this->managedCostCenterIds($actorUid, Role::SeniorManager);

        if ($managedCostCenterIds === []) {
            return ['items' => [], 'total' => 0];
        }

        // The Senior queue had the same role-loop/merge shape. The scope and page are resolved once
        // here as well; Senior review authorization and workflow transitions are unchanged.
        return $this->paginateUniqueQueue(
            $this->transactionRepository->findByCostCenterIds(
                $managedCostCenterIds,
                WorkflowStage::Senior
            ),
            $page,
            $pageSize
        );
    }

    /**
     * The active cost-centre IDs for one manager role, resolved once as a set.
     *
     * A user may hold several overlapping assignments for the same cost centre, and
     * `findActiveByUserAndRole()` deliberately returns every assignment row. The queue must not
     * fetch that scope once per row: duplicate IDs are collapsed here before any transaction read.
     *
     * @return list<int>
     */
    private function managedCostCenterIds(string $actorUid, Role $role): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $ids = [];

        foreach ($this->roleAssignmentRepository->findActiveByUserAndRole($actorUid, $role, $now) as $assignment) {
            if ($assignment->costCenterId !== null) {
                $ids[$assignment->costCenterId] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * De-duplicate the authorized set, order it once, and apply the page after that.
     *
     * The ID map is the primary guarantee: even if a caller or a future repository implementation
     * returns the same transaction more than once, it contributes one queue row and one count.
     * `created_at` is the contract's ordering; `dbId` is the deterministic tiebreaker for rows
     * written in the same second, which is what keeps page N and page N+1 from overlapping.
     *
     * @param list<ExpenseTransaction> $transactions
     * @return array{items: list<ExpenseTransaction>, total: int}
     */
    private function paginateUniqueQueue(array $transactions, int $page, int $pageSize): array
    {
        $unique = [];
        foreach ($transactions as $transaction) {
            $unique[$transaction->id] ??= $transaction;
        }

        $ordered = array_values($unique);
        usort($ordered, static function (ExpenseTransaction $a, ExpenseTransaction $b): int {
            $createdAtComparison = self::compareCreatedAtDescending($a->createdAt, $b->createdAt);
            if ($createdAtComparison !== 0) {
                return $createdAtComparison;
            }

            $dbIdComparison = ($b->dbId ?? 0) <=> ($a->dbId ?? 0);
            if ($dbIdComparison !== 0) {
                return $dbIdComparison;
            }

            return $b->id <=> $a->id;
        });

        $offset = max(0, ($page - 1) * $pageSize);

        return [
            'items' => array_slice($ordered, $offset, $pageSize),
            'total' => count($ordered),
        ];
    }

    private static function compareCreatedAtDescending(?DateTimeImmutable $a, ?DateTimeImmutable $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return 1;
        }
        if ($b === null) {
            return -1;
        }

        return $b <=> $a;
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
                // Keyed by the enum value: a user with several assignments for the same role holds
                // one role, not one entry per assignment. Authorization only needs the set.
                $roles[$assignment->role->value] = $assignment->role;
            }
        }
        // Every authenticated user is at least a purchaser
        if (!isset($roles[Role::Purchaser->value])) {
            $roles[Role::Purchaser->value] = Role::Purchaser;
        }
        return array_values($roles);
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