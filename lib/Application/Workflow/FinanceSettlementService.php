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
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;

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
        private AuditWriter $auditWriter,
        private IdempotencyService $idempotencyService,
        private CurrentUser $currentUser,
        private UserDirectory $userDirectory,
        private AuthorizationService $authorizationService
    ) {}

    /**
     * Record a finance decision for a transaction
     *
     * @param string $transactionPublicId
     * @param Decision $decision
     * @param string $explanation
     * @param string $commandId
     * @param int $expectedVersion
     * @return ExpenseTransaction
     */
    public function recordDecision(
        string $transactionPublicId,
        Decision $decision,
        string $explanation,
        string $commandId,
        int $expectedVersion
    ): ExpenseTransaction {
        $actorUid = $this->currentUser->uid();

        return $this->transactionManager->transactional(function () use (
            $transactionPublicId,
            $actorUid,
            $decision,
            $explanation,
            $commandId,
            $expectedVersion
        ): ExpenseTransaction {
            // Check idempotency
            $payload = [
                'transaction_id' => $transactionPublicId,
                'stage' => WorkflowStage::Finance->value,
                'decision' => $decision->value,
                'command_id' => $commandId,
            ];

            $this->idempotencyService->execute($commandId, $payload, function () use (
                $transactionPublicId,
                $actorUid,
                $decision,
                $explanation,
                $commandId,
                $expectedVersion
            ): array {
                $transaction = $this->transactionRepository->findByPublicId($transactionPublicId);

                if ($transaction === null) {
                    throw new InvalidArgumentException('Transaction not found');
                }

                if ($transaction->lockVersion !== $expectedVersion) {
                    throw new InvalidArgumentException('Stale version');
                }

                // Verify transaction is at finance stage
                if ($transaction->stage !== WorkflowStage::Finance) {
                    throw new InvalidArgumentException('Transaction is not at finance stage');
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

                // Validate finance decision policy
                $policy = new FinanceDecisionPolicy();
                $policy->assertCanApprove($transaction->status);

                // Validate explanation required for reject/return
                if (in_array($decision, [Decision::Reject, Decision::Return], true) && trim($explanation) === '') {
                    throw new InvalidArgumentException('Explanation required for reject or return');
                }

                // Get the petty cash account for this transaction
                $currencyCode = $transaction->revision()->amount()->currency();
                $accounts = $this->accountRepository->findActiveByUserAndCurrency($transaction->ownerUid, $currencyCode);
                if ($accounts === []) {
                    throw new LogicException('No active petty cash account found for transaction currency');
                }
                $account = $accounts[0];

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

                // Handle financial effects for APPROVE decision
                if ($decision === Decision::Approve) {
                    $this->recordConsumption($transaction, $account, $workflowDecision, $commandId);
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
                    'event_type' => 'DECISION',
                    'actor_uid' => $actorUid,
                    'actor_name' => $this->userDirectory->displayName($actorUid),
                    'actor_role' => $this->getUserRole($actorUid, $transaction->revision()->costCenterId()),
                    'explanation' => $explanation,
                    'before_json' => json_encode([], JSON_THROW_ON_ERROR),
                    'after_json' => json_encode([
                        'decision' => $decision->value,
                        'stage' => WorkflowStage::Finance->value,
                        'explanation' => $explanation,
                        'revision_number' => $transaction->revision()->number(),
                        'financial_effect' => $decision === Decision::Approve ? 'CONSUMPTION_RECORDED' : 'NONE',
                    ], JSON_THROW_ON_ERROR),
                    'command_id' => $commandId,
                    'occurred_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
                ];
                $this->auditWriter->append($auditData);

                // Persist transaction and decision
                $this->transactionRepository->save($transaction);

                return ['transaction_id' => $transactionPublicId];
            });

            // Reload and return the transaction
            $transaction = $this->transactionRepository->findByPublicId($transactionPublicId);
            if ($transaction === null) {
                throw new LogicException('Transaction not found after decision');
            }

            return $transaction;
        });
    }

    private function recordConsumption(
        ExpenseTransaction $transaction,
        PettyCashAccount $account,
        \OCA\PettyCash\Domain\Workflow\WorkflowDecision $workflowDecision,
        string $commandId
    ): void {
        $amount = $transaction->revision()->amount();

        // Use the account's consume method which handles balance update
        $account->consume($amount, $commandId, (string)$workflowDecision->id);

        // Save the account with updated balance
        $this->accountRepository->save($account);
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