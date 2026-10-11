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
use OCA\PettyCash\Domain\Account\AccountMovement;
use OCA\PettyCash\Domain\Account\PettyCashAccount;
use OCA\PettyCash\Domain\Account\Repository\AccountMovementRepository;
use OCA\PettyCash\Domain\Account\Repository\AccountRepository;
use OCA\PettyCash\Domain\Audit\AuditEvent;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository;
use OCA\PettyCash\Domain\Shared\ActorSnapshot;
use OCA\PettyCash\Domain\Shared\Enum\MovementType;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCA\PettyCash\Domain\Workflow\FinanceDecisionPolicy;
use OCA\PettyCash\Domain\Workflow\Repository\WorkflowDecisionRepository;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowDecision;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Application\Audit\ConfigurationEventType;
use OCA\PettyCash\Application\Audit\DecisionEventType;

final readonly class FinanceSettlementService
{
    public function __construct(
        private TransactionManager $transactionManager,
        private ExpenseSheetRepository $sheetRepository,
        private ExpenseTransactionRepository $transactionRepository,
        private TransactionRevisionRepository $revisionRepository,
        private AccountRepository $accountRepository,
        private AccountMovementRepository $movementRepository,
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
     * Record a finance decision for a transaction
     *
     * @param string $transactionPublicId
     * @param Decision $decision
     * @param string $explanation
     * @param string $commandId
     * @param int $expectedVersion
     * @return DecisionOutcome
     */
    public function recordDecision(
        string $transactionPublicId,
        Decision $decision,
        string $explanation,
        string $commandId,
        int $expectedVersion
    ): DecisionOutcome {
        $actorUid = $this->currentUser->uid();

        // Carried out of the idempotency callback, which does not run at all on a replay.
        $recordedDecision = null;
        $recordedMovement = null;
        $recordedAccount = null;

        // FR-085. Same shape and the same reason as `ManagementReviewService`: the refusal is raised
        // inside the transaction below, and caught here because the `SUPERSEDED_ATTEMPT` event has to
        // be written *after* the rollback that the refusal causes.
        try {
            return $this->recordDecisionInTransaction(
                $transactionPublicId,
                $actorUid,
                $decision,
                $explanation,
                $commandId,
                $expectedVersion,
                $recordedDecision,
                $recordedMovement,
                $recordedAccount
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
     * @param-out WorkflowDecision|null $recordedDecision
     * @param-out AccountMovement|null   $recordedMovement
     * @param-out PettyCashAccount|null  $recordedAccount
     */
    private function recordDecisionInTransaction(
        string $transactionPublicId,
        string $actorUid,
        Decision $decision,
        string $explanation,
        string $commandId,
        int $expectedVersion,
        ?WorkflowDecision &$recordedDecision,
        ?AccountMovement &$recordedMovement,
        ?PettyCashAccount &$recordedAccount
    ): DecisionOutcome {
        return $this->transactionManager->transactional(function () use (
            $transactionPublicId,
            $actorUid,
            $decision,
            $explanation,
            $commandId,
            $expectedVersion,
            &$recordedDecision,
            &$recordedMovement,
            &$recordedAccount
        ): DecisionOutcome {
            // Check idempotency
            //
            // The stage is deliberately **not** part of the payload. It used to be, and it made a
            // replayed command look like a different command: the first attempt advanced the
            // transaction out of the finance stage, so the controller's retry routed it here with
            // `FINAL` in hand, the payload hashed differently, and `IdempotencyService` answered
            // 409 "Command ID was already used with a different payload" for a command that had
            // already succeeded. A client retrying after a timeout was told its decision had
            // been refused, having actually been applied.
            //
            // The transaction and the decision are what the command means, and both are in the
            // payload, so a genuine reuse of one command id for different work is still a
            // conflict.
            $payload = [
                'transaction_id' => $transactionPublicId,
                'decision' => $decision->value,
                'command_id' => $commandId,
            ];

            $storedResult = $this->idempotencyService->execute($commandId, $actorUid, 'review.decision.FINANCE', $transactionPublicId, $payload, function () use (
                $transactionPublicId,
                $actorUid,
                $decision,
                $explanation,
                $commandId,
                $expectedVersion,
                &$recordedDecision,
                &$recordedMovement,
                &$recordedAccount
            ): array {
                $transaction = $this->transactionRepository->findByPublicId($transactionPublicId);

                if ($transaction === null) {
                    throw new InvalidArgumentException('Transaction not found');
                }

                // Same `409` as `ManagementReviewService` for the same reason: this service answers the
                // **same** `POST /transactions/{id}/decisions` endpoint, which declares a `409`.
                // Leaving the finance stage at 422 would make one route answer 409 for a management
                // decision and 422 for a finance one — a difference no client could explain.
                if ($transaction->lockVersion !== $expectedVersion) {
                    throw StaleRecordVersion::forResource(
                        'transaction',
                        $transactionPublicId,
                        $expectedVersion,
                        $transaction->lockVersion
                    );
                }

                // Authorization check - only finance role can make finance decisions
                $context = new AuthorizationContext(
                    $actorUid,
                    $transaction->ownerUid,
                    $this->getUserRoles($actorUid),
                    [],
                    false
                );

                if (!$this->authorizationService->canReview($context, WorkflowStage::Finance, '')) {
                    throw new LogicException('User not authorized for finance decisions');
                }

                // FR-085: first write wins.
                //
                // **Before the stage guard, and before the account lookup**, for the same reason as
                // on the management path: a decision already recorded at the finance stage is
                // precisely why the transaction is no longer there, so a stage guard placed first
                // makes this check unreachable and answers every losing race with "not at finance
                // stage" instead of naming the decision that is already in force.
                //
                // Before the account lookup for a second reason. Two finance officers approving the
                // same transaction is the *ordinary* case, not an exotic one. If the losing officer
                // is answered `ACCOUNT_NOT_SETTLEABLE` because the purchaser's account was
                // deactivated in between, finance is sent to fix a configuration problem that is not
                // the one stopping them, while the real outcome — their colleague already settled it —
                // is never stated. The 409 is the more informative answer about the transaction, and
                // the account question is moot once the transaction has moved on.
                $this->refuseIfAlreadyDecided($transaction, WorkflowStage::Finance, $decision);

                // Verify transaction is at finance stage
                if ($transaction->stage !== WorkflowStage::Finance) {
                    throw new InvalidArgumentException('Transaction is not at finance stage');
                }

                // Validate the finance decision matrix for **this** decision.
                //
                // This used to call `assertCanApprove()` unconditionally, which meant asking only
                // "may finance approve this?" and applying the answer to whichever decision had
                // been requested. A senior-rejected transaction therefore could not be rejected
                // or returned either: `assertCanApprove(SeniorRejected)` throws, so FR-039's "finance
                // may reject it or return it for correction" had no reachable path. A purchaser
                // whose expense was refused at senior stage could not be sent back to correct it.
                $policy = new FinanceDecisionPolicy();
                $policy->assertCanDecide($transaction->status, $decision);

                // Validate explanation required for reject/return
                if (in_array($decision, [Decision::Reject, Decision::Return], true) && trim($explanation) === '') {
                    throw new InvalidArgumentException('Explanation required for reject or return');
                }

                // Get the petty cash account for this transaction
                $currencyCode = $transaction->revision()->amount()->currency();
                $accounts = $this->accountRepository->findActiveByUserAndCurrency($transaction->ownerUid, $currencyCode);
                if ($accounts === []) {
                    // FR-040: an unsettleable approval is refused with an actionable 422, not a 500
                    // and not a validation failure. See `UnsettleableAccount` for why the account is
                    // named, and for why provisioning one here would be wrong: FR-020 scopes
                    // auto-provisioning to sheet creation, and an approval must never fund the
                    // balance it is charged to.
                    //
                    // Thrown *before* any lock is taken and before any row is written, so the
                    // refusal leaves nothing behind: no movement, no consumption record, no workflow
                    // transition and no sheet completion. `FR-042` requires the refusal to apply
                    // nothing at all, and applying nothing is easiest to prove when nothing has been
                    // touched yet.
                    throw new UnsettleableAccount($transaction->ownerUid, $currencyCode);
                }
                $account = $accounts[0];

                // Lock every row this settlement writes, always in the adapter's fixed
                // order: sheet, transaction, revision, account.
                //
                // Two finance officers approving at once is the ordinary case, not the
                // exotic one. Without one agreed order the pair can deadlock, and a
                // deadlock reaches the business as a settlement that failed for no stated
                // reason. FR-042 requires the multi-record write to be atomic, and an
                // atomic write that can deadlock on ordinary concurrency is not delivering
                // that.
                //
                // The account is locked because it is the row most likely to be contended:
                // it is shared by every one of that user's transactions. It has to be read
                // first to learn its key, which is why the read here is followed by a
                // re-read under the lock — locking after reading, then trusting the first
                // read, would settle against a balance another settlement has already moved.
                $this->transactionManager->lockInSettlementOrder(array_filter([
                    'sheet' => $transaction->sheetId,
                    'transaction' => $transaction->dbId,
                    'revision' => $transaction->revision()->dbId,
                    'account' => $account->dbId,
                ], static fn ($id): bool => $id !== null));

                if ($account->dbId !== null) {
                    $locked = $this->accountRepository->findById($account->dbId);
                    if ($locked !== null) {
                        $account = $locked;
                    }
                }

                // Record the decision on the transaction domain model
                $workflowDecision = $transaction->recordDecision(
                    WorkflowStage::Finance,
                    $decision,
                    $explanation,
                    $actorUid,
                    $this->userDirectory->displayName($actorUid),
                    $this->getUserRole($actorUid, $transaction->revision()->costCenterId()),
                    $commandId
                );

                // Persist the decision, and do it *before* the consumption.
                //
                // `ExpenseTransactionRepository::save()` writes the transaction row and nothing
                // else, so the decision was never written at all, and
                // `DbAccountMovementRepository::save()` resolves the movement's
                // `source_decision_id` from the decision's public UUID. Recording the
                // consumption first therefore failed to resolve a decision that did not exist
                // yet, and **every finance approval answered 422** — the settlement path was
                // unreachable through the API, whatever the in-memory doubles reported.
                //
                // The order is load-bearing for a second reason: the audit event below records
                // `decision_id`, which is the null an unpersisted aggregate carries.
                $this->decisionRepository->save($workflowDecision);
                $recordedDecision = $workflowDecision;

                // Handle financial effects for APPROVE decision
                if ($decision === Decision::Approve) {
                    $recordedMovement = $this->recordConsumption(
                        $transaction,
                        $account,
                        $workflowDecision,
                        $commandId
                    );
                    $recordedAccount = $account;
                }

                // Create audit event
                $auditData = [
                    'id' => (string)Uuid::random(),
                    'sheet_id' => $transaction->sheetId,
                    'transaction_id' => $transaction->dbId,
                    'revision_id' => $transaction->revision()->dbId,
                    'stage' => WorkflowStage::Finance->value,
                    'decision_id' => $workflowDecision->dbId,
                    'entity_type' => 'transaction',
                    'entity_id' => $transactionPublicId,
                    // See `ManagementReviewService`: the same shared mapper records the outcome, so
                    // this path cannot drift from the cost-center one.
                    'event_type' => DecisionEventType::for($decision)->value,
                    'actor_uid' => $actorUid,
                    'actor_name' => $this->userDirectory->displayName($actorUid),
                    'actor_role' => $this->getUserRole($actorUid, $transaction->revision()->costCenterId()),
                    'explanation' => $explanation,
                    // See `ManagementReviewService`: this used to send `before_json` and `after_json`,
                    // keys `AuditWriterService` does not read, so the finance DECISION event stored "[]"
                    // for both snapshots and lost the decision, stage, explanation, revision number and
                    // the `financial_effect` that says whether money actually moved. Passing plain
                    // `before` / `after` arrays lets the writer store them.
                    'before' => [],
                    'after' => [
                        'decision' => $decision->value,
                        'stage' => WorkflowStage::Finance->value,
                        'explanation' => $explanation,
                        'revision_number' => $transaction->revision()->number(),
                        'financial_effect' => $decision === Decision::Approve ? 'CONSUMPTION_RECORDED' : 'NONE',
                    ],
                    'command_id' => $commandId,
                    'occurred_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
                ];
                $this->auditWriter->append($auditData);

                // FR-055: the account is the configuration a finance officer is charged against, and
                // the specification derives an officer's charged accounts from their
                // funding/charging configuration-audit events. Until settlement wrote one, that
                // derivation had nothing to read and every officer answered as holding no charged
                // account. It is written only when money actually moved, so a reject or return does
                // not accrue a charge, and it lands in the configuration stream addressed by
                // entity_type/entity_id because it has no sheet or transaction of its own.
                if ($decision === Decision::Approve) {
                    $this->auditWriter->appendConfiguration([
                        'id' => (string)Uuid::random(),
                        'entity_type' => 'account',
                        'entity_id' => $account->id,
                        // FR-055 configuration stream, not the FR-051 transaction vocabulary, so this
                        // one deliberately stays out of `AuditEventType`. The enum is the single
                        // definition, and `ChargedAccountScope` reads the same case to derive the
                        // charged-account set — so a producer and the code that reads it cannot drift
                        // apart. T369 moved this off the `ChargedAccountScope::CHARGE_EVENT_TYPE`
                        // literal for exactly that reason.
                        'event_type' => ConfigurationEventType::AccountCharged->value,
                        'explanation' => $explanation,
                        // `recordConsumption()` mutates the account in place, so a "before"
                        // balance read here would be the post-consumption value wearing a
                        // "before" label. The event records the charge that occurred, not a
                        // balance pair that cannot be read honestly at this point.
                        'after' => [
                            'movement_id' => $recordedMovement?->id,
                            'transaction_id' => $transactionPublicId,
                            'decision' => $decision->value,
                        ],
                        'command_id' => $commandId,
                    ]);
                }

                $this->transactionRepository->save($transaction);

                // FR-043 and the workflow contract's "recalculated sheet state".
                //
                // The sheet's own projection was never refreshed, so a sheet whose last transaction
                // reached a final financial outcome stayed at its previous status and never became
                // `COMPLETED`. `sheetRepository` was injected into this class and used nowhere.
                //
                // It runs after the transaction is saved and inside the same transaction, so the
                // sheet can never be completed against a transaction whose settlement rolled back.
                $this->recalculateSheet($transaction->sheetId);

                // The movement is named in the stored result so a replay can report the account
                // effect its first attempt caused.
                //
                // Until this, the stored result held only the transaction id, so a client that
                // retried after a timeout got the decision and the transaction back with no
                // `accountMutation` — the one member that says money moved. Nothing was wrong with
                // the ledger on a replay; the answer was simply incomplete, and the fix is to record
                // what was written rather than to re-read the ledger afterwards and hope the newest
                // row is the right one.
                return [
                    'transaction_id' => $transactionPublicId,
                    'movement_id' => $recordedMovement?->id,
                ];
            }, 201);

            // Reload and return the transaction
            $transaction = $this->transactionRepository->findByPublicId($transactionPublicId);
            if ($transaction === null) {
                throw new LogicException('Transaction not found after decision');
            }

            // On a replay the callback never ran, so the decision is read back by the command
            // id that recorded it. Reporting no decision for a replayed command would make the
            // two responses of one command differ with nothing in the request to explain it.
            if ($recordedDecision === null) {
                $recordedDecision = $this->decisionRepository->findByCommandId($commandId);
            }
            if ($recordedDecision === null) {
                throw new LogicException('The recorded decision could not be found for this command');
            }

            // The account effect of a replay, read back through the ports that already exist for it.
            //
            // `AccountMovementRepository::findByCommandId()` needs no new method because the movement's
            // `command_id` **is** the command id — the same reason the unique index on that column makes
            // a replay safe — and the movement carries its account's public id, so the account follows
            // from it. The stored result is only consulted to confirm the first attempt really did
            // write a movement: without it, a replay of a *reject* would report whichever consumption
            // happened to share the command id, which is none.
            if ($recordedMovement === null && !empty($storedResult['movement_id'])) {
                $recordedMovement = $this->movementRepository->findByCommandId($commandId);
                if ($recordedMovement === null) {
                    throw new LogicException(
                        'The command result names a movement that cannot be read back'
                    );
                }
                $recordedAccount = $this->accountRepository->findByPublicId(
                    (string) $recordedMovement->accountPublicId
                );
                if ($recordedAccount === null) {
                    throw new LogicException('The settled movement names an account that cannot be read back');
                }
            }

            return new DecisionOutcome($transaction, $recordedDecision, $recordedMovement, $recordedAccount);
        });
    }

    /**
     * FR-085: refuse a decision for a stage this revision has already been decided at.
     *
     * The settlement-specific reason this matters more here than on the management path: an accepted
     * second `APPROVE` is the only outcome that could produce two consumption movements against one
     * transaction and decrement the account twice. The unique index
     * `pettycash_workflow_decisions_revision_stage_uniq` prevents that today, so the gap closed here
     * is the *outcome* — an explained 409 naming the decision in force, plus a `SUPERSEDED_ATTEMPT`
     * event, instead of an unexplained 500 and no record that the officer acted.
     *
     * The window between this read and the insert is closed by
     * `DbWorkflowDecisionRepository::save()`, which converts the unique-index violation into the same
     * exception rather than letting it reach the default 500 arm. FR-085 declines server-side
     * serialisation of the decision path, so this read-and-then-write is the intended shape and the
     * index is the backstop rather than a last resort.
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

        // Read by `decided_at ASC`, and one row per (revision, stage) by the unique index, so the
        // last element is the decision in force.
        throw new SupersededDecisionAttempt(
            $transaction->id,
            $transaction->revision()->id(),
            $stage,
            $decision,
            $recorded[count($recorded) - 1]->decision,
        );
    }

    /**
     * Refresh a sheet's status from the transactions it actually holds.
     *
     * The sheet's transactions are loaded here rather than read off the aggregate, because neither
     * sheet repository hydrates that collection — see `ExpenseSheet::recalculateStatusFrom()`.
     *
     * A sheet that cannot be loaded is left alone rather than treated as complete. An empty
     * transaction set already means "nothing to recalculate", and inventing a status for a sheet
     * this settlement cannot see would be a worse failure than a stale one.
     */
    private function recalculateSheet(?int $sheetId): void
    {
        if ($sheetId === null) {
            return;
        }

        $sheet = $this->sheetRepository->findById($sheetId);
        if ($sheet === null) {
            return;
        }

        $sheet->recalculateStatusFrom($this->transactionRepository->findBySheetId($sheetId));
        $this->sheetRepository->save($sheet);
    }

    /**
     * FR-040: record the consumption and return it.
     *
     * The movement is returned rather than discarded because `DecisionResult` reports the account
     * effect, and re-reading the ledger to find the row this call just wrote would be a second
     * source of truth for a fact this method already holds.
     */
    private function recordConsumption(
        ExpenseTransaction $transaction,
        PettyCashAccount $account,
        \OCA\PettyCash\Domain\Workflow\WorkflowDecision $workflowDecision,
        string $commandId
    ): AccountMovement {
        $amount = $transaction->revision()->amount();
        $revision = $transaction->revision();

        // The movement carries the revision's **public** UUID, not its surrogate key.
        // `contracts/ocs-api.yaml` declares `sourceRevisionId` as `format: uuid`, and the
        // repository's read path already projects the joined `r.public_id`; handing the
        // movement a surrogate key here would make the write and the read disagree about
        // what the same field holds, and a raw auto-increment key is not resolvable by any
        // client. An unhydrated revision is a corrupted aggregate and must not be settled.
        $revisionPublicId = $revision->publicId;
        if ($revisionPublicId === null || trim($revisionPublicId) === '') {
            throw new LogicException('Transaction revision is missing its public identifier');
        }

        // FR-040: final finance approval records **one** consumption movement against the
        // petty-cash account. The movement is what makes a balance reconstructible, so
        // updating the balance and stopping here left a finance approval that moved money
        // without leaving a ledger row — and the unique `source_revision_id` index, which
        // is what makes "exactly one" an enforced fact rather than an intention, was never
        // exercised by any code path.
        //
        // `consume()` is what produces the movement, and it has already applied the new
        // balance to the aggregate, so the movement and the account are saved together
        // inside the caller's transaction: either the ledger and the balance both move, or
        // neither does.
        $movement = $account->consume(
            $amount,
            $commandId,
            $revisionPublicId,
            (string)$workflowDecision->id
        );

        $this->movementRepository->save($movement);
        $this->accountRepository->save($account);

        return $movement;
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
        if (!in_array(Role::Purchaser, $roles, true)) {
            $roles[] = Role::Purchaser;
        }
        return $roles;
    }

    private function getUserRole(string $userUid, ?string $costCenterCode): string
    {
        $roles = $this->getUserRoles($userUid);

        if (in_array(Role::Finance, $roles, true)) {
            return 'FINANCE';
        }
        if (in_array(Role::SeniorManager, $roles, true)) {
            return 'SENIOR_MANAGER';
        }
        if (in_array(Role::CostCenterManager, $roles, true)) {
            return 'COST_CENTER_MANAGER';
        }
        if (in_array(Role::Administrator, $roles, true)) {
            return 'ADMINISTRATOR';
        }
        return 'PURCHASER';
    }
}