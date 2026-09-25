<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Workflow;

use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;

final readonly class FinanceQueue
{
    public function __construct(
        private ExpenseTransactionRepository $transactionRepository,
        private RoleAssignmentRepository $roleAssignmentRepository,
        private CurrentUser $currentUser
    ) {}

    /**
     * Get finance queue (shared queue for all finance officers)
     *
     * @param int $page
     * @param int $pageSize
     * @return array{items: list<ExpenseTransaction>, total: int}
     */
    public function getQueue(int $page = 1, int $pageSize = 25): array
    {
        $actorUid = $this->currentUser->uid();
        $roles = $this->getUserRoles($actorUid);

        // Check if user has finance role
        if (!in_array(Role::Finance, $roles, true)) {
            return ['items' => [], 'total' => 0];
        }

        $offset = ($page - 1) * $pageSize;

        $transactions = $this->transactionRepository->findForFinanceQueue($offset, $pageSize);

        // For total count, fetch all (or add a count method)
        $allTransactions = $this->transactionRepository->findForFinanceQueue(0, 1000);
        $total = count($allTransactions);

        return ['items' => $transactions, 'total' => $total];
    }

    private function getUserRoles(string $userUid): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
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
}