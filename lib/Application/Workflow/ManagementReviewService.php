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
use OCA\PettyCash\Domain\Workflow\WorkflowDecision;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;

final readonly class ManagementReviewService
{
    public function __construct(
        private TransactionManager $transactionManager,
        private ExpenseSheetRepository $sheetRepository,
        private ExpenseTransactionRepository $transactionRepository,
        private TransactionRevisionRepository $revisionRepository,
        private CostCenterRepository $costCenterRepository,
        private RoleAssignmentRepository $roleAssignmentRepository,
        private AuditWriter $auditWriter,
        private IdempotencyService $idempotencyService,
        private CurrentUser $currentUser,
        private UserDirectory $userDirectory,
        private AuthorizationService $authorizationService
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
     * @return ExpenseTransaction
     */
    public function recordDecision(
        string $transactionPublicId,
        WorkflowStage $stage,
        Decision $decision,
        string $explanation,
        string $commandId,
        int $expectedVersion
    ): ExpenseTransaction {
        $actorUid = $this->currentUser->uid();

        return $this->transactionManager->transactional(function () use (
            $transactionPublicId,
            $actorUid,
            $stage,
            $decision,
            $explanation,
            $commandId,
            $expectedVersion
        ): ExpenseTransaction {
            // Check idempotency
            $payload = [
                'transaction_id' => $transactionPublicId,
                'stage' => $stage->value,
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
                $expectedVersion
            ): array {
                $transaction = $this->transactionRepository->findByPublicId($transactionPublicId);

                if ($transaction === null) {
                    throw new InvalidArgumentException('Transaction not found');
                }

                if ($transaction->lockVersion !== $expectedVersion) {
                    throw new InvalidArgumentException('Stale version');
                }

                // Verify transaction is at the expected stage
                if ($transaction->stage !== $stage) {
                    throw new InvalidArgumentException("Transaction is not at {$stage->value} stage");
                }

                // Authorization check
                $costCenterCode = $transaction->revision()->costCenterId();
                $costCenter = $costCenterCode ? $this->costCenterRepository->findByCode($costCenterCode) : null;
                $costCenterId = $costCenter?->id;

                $context = new AuthorizationContext(
                    $actorUid,
                    $transaction->ownerUid,
                    $this->getUserRoles($actorUid),
                    $costCenterCode ? [$costCenterCode] : [],
                    false
                );

                if (!$this->authorizationService->canReview($context, $stage, $costCenterCode ?? '')) {
                    throw new LogicException('User not authorized for this review stage');
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
                    'event_type' => 'DECISION',
                    'actor_uid' => $actorUid,
                    'actor_name' => $this->userDirectory->displayName($actorUid),
                    'actor_role' => $this->getUserRole($actorUid, $costCenterCode),
                    'explanation' => $explanation,
                    'before_json' => json_encode([], JSON_THROW_ON_ERROR),
                    'after_json' => json_encode([
                        'decision' => $decision->value,
                        'stage' => $stage->value,
                        'explanation' => $explanation,
                        'revision_number' => $transaction->revision()->number(),
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