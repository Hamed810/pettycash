<?php

declare(strict_types=1);
namespace OCA\PettyCash\Service;
use OCA\PettyCash\Db\CostList;
use OCA\PettyCash\Db\CostListMapper;
use OCA\PettyCash\Db\ProjectMapper;
use OCA\PettyCash\Db\Transaction;
use OCA\PettyCash\Db\TransactionMapper;
use OCA\PettyCash\Db\TransactionRevisionMapper;
use OCA\PettyCash\Domain\ApprovalAction;
use OCA\PettyCash\Domain\ApprovalStage;
use OCA\PettyCash\Domain\CostListStatus;
use OCA\PettyCash\Domain\DecisionRole;
use OCA\PettyCash\Domain\Exception\ConflictException;
use OCA\PettyCash\Domain\Exception\ForbiddenException;
use OCA\PettyCash\Domain\Exception\NotFoundException;
use OCA\PettyCash\Domain\Exception\ValidationException;
use OCA\PettyCash\Domain\ListType;
use OCA\PettyCash\Domain\TransactionStatus;
use OCP\AppFramework\Db\DoesNotExistException;
use OCA\PettyCash\Domain\ProjectRole;

/**
 * v2.0.0: approval queues and decisions are transaction-level, not
 * list/project-level -- routing is resolved per transaction (see
 * TransactionService::resolveAndApplyRouting), so a single Cost List
 * can have transactions sitting with different Manager 2s
 * simultaneously. "Stage" here still means MANAGER1/MANAGER2 (see
 * Domain\ApprovalStage) and maps 1:1 to Domain\DecisionRole M1/M2.
 */
final class ApprovalService {
    public function __construct(
        private TransactionMapper $txnMapper,
        private CostListMapper $listMapper,
        private ProjectMapper $projectMapper,
        private TransactionRevisionMapper $revisionMapper,
        private TransactionService $transactions,
        private DecisionService $decisions,
        private AuthorizationService $auth,
        private AuditService $audit,
    ) {}

    /** @return list<array<string,mixed>> */
    public function queue(string $stage): array {
        $role = $this->roleForStage($stage);
        $uid = $this->auth->currentUserId();
        if ($uid === null) return [];

        $status = match ($role) {
    DecisionRole::M1 => TransactionStatus::PENDING_M1,
    DecisionRole::M2 => TransactionStatus::PENDING_M2,
    DecisionRole::ACCOUNTANT => TransactionStatus::PENDING_ACCOUNTANT,
    default => throw new ValidationException('Unknown approval role.'),
    };

    $txns = $this->auth->isAdmin($uid)
    ? $this->txnMapper->findAllPendingByStatus($status)
    : match ($role) {
        DecisionRole::M1 => $this->txnMapper->findPendingForManager1($uid),
        DecisionRole::M2 => $this->txnMapper->findPendingForManager2($uid),
        DecisionRole::ACCOUNTANT => $this->txnMapper->findPendingForAccountant($uid),
        default => [],
    };

            return array_map(fn(Transaction $t) => $this->queueSummary($t, $role), $txns);
    }

    /** @return array<string,mixed> */
    public function detail(string $txnUuid, string $stage): array {
        [$txn, ,] = $this->assertTransactionStage($txnUuid, $stage, null, true);
        return $this->transactions->serialize($txn);
    }

    /** @return array<string,mixed> */
    public function decide(string $txnUuid, string $stage, string $action, int $version, ?string $comment): array {
        $action = strtoupper($action);
        if (!in_array($action, [ApprovalAction::APPROVE, ApprovalAction::REJECT, ApprovalAction::RETURN], true)) {
            throw new ValidationException('Unsupported approval action.');
        }

        [$txn, $list, $uid, $role] = $this->assertTransactionStage($txnUuid, $stage, $version, false);
        $revisionId = $this->currentRevisionRowId($txn);

        $this->decisions->record($txn, $role, $action, $comment, $uid);

        $skipManager1 = $list->getListType() === ListType::BUSINESS_TRIP;
        $newStatus = $this->decisions->computeEffectiveStatus((int)$txn->getId(), $revisionId, $skipManager1);

        $txn->setStatus($newStatus);
        $txn->setVersion($txn->getVersion() + 1);
        $txn->setUpdatedAt(time());
        $txn = $this->txnMapper->update($txn);

        $this->audit->record('TRANSACTION', (int)$txn->getId(), $role . '_' . $action, $uid, [
            'comment' => $comment,
            'revision' => $txn->getCurrentRevision(),
        ]);

            if (in_array($newStatus, [
                TransactionStatus::FINAL_APPROVED,
                TransactionStatus::REJECTED_M2,
                TransactionStatus::RETURNED_M2,
                TransactionStatus::REJECTED_ACCOUNTANT,
                TransactionStatus::RETURNED_ACCOUNTANT,
            ], true)) {            
            $this->maybeAdvanceListToAccounting($list);
        }

        return $this->transactions->serialize($txn);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function edit(string $txnUuid, string $stage, int $version, array $data, ?string $reason): array {
        if (trim((string)$reason) === '') {
            throw new ValidationException('A reason is required when a manager edits financial data.');
        }
        [$txn, $list, $uid, ] = $this->assertTransactionStage($txnUuid, $stage, $version, false);
        $data['changeReason'] = $reason;
        $updated = $this->transactions->updateAsApprover($txnUuid, $version, $data, $stage, $uid);
        $this->audit->record('COST_LIST', (int)$list->getId(), 'APPROVAL_RESET_AFTER_EDIT', $uid, [
            'transactionUuid' => $txnUuid,
            'stage' => $stage,
        ]);
        return $updated;
    }

    private function roleForStage(string $stage): string {
    return match (strtoupper($stage)) {
        ApprovalStage::MANAGER1 => DecisionRole::M1,
        ApprovalStage::MANAGER2 => DecisionRole::M2,
        ApprovalStage::ACCOUNTANT => DecisionRole::ACCOUNTANT,
        default => throw new ValidationException('Unknown approval stage.'),
        };
    }

    /**
     * @return array{0:Transaction,1:CostList,2:string,3:string}
     */
    private function assertTransactionStage(string $txnUuid, string $stage, ?int $version, bool $readOnly): array {
        $role = $this->roleForStage($stage);

        try {
            $txn = $this->txnMapper->findByUuid($txnUuid);
            $list = $this->listMapper->find((int)$txn->getListId());
        } catch (DoesNotExistException) {
            throw new NotFoundException('Transaction or Cost List not found.');
        }

        $uid = $this->auth->currentUserId();
        if ($uid === null) throw new ForbiddenException('Login is required.');

        if ($txn->getPurchaserId() === $uid) {
            throw new ForbiddenException('A purchaser cannot approve, edit, or review their own transaction.');
        }

        if (!$this->auth->isAdmin($uid)) {
            if ($role === DecisionRole::M1) {

    if ($txn->getManager1Id() !== $uid) {
        throw new ForbiddenException(
            'You are not the assigned Manager 1 reviewer.'
        );
    }

        } elseif ($role === DecisionRole::M2) {

            if ($txn->getManager2Id() !== $uid) {
                throw new ForbiddenException(
                    'You are not the assigned Manager 2 reviewer.'
                );
            }

        } elseif ($role === DecisionRole::ACCOUNTANT) {

        if (
              $txn->getDestinationId() === null
    ||
    !$this->auth->hasAnyProjectRole(
        (int)$txn->getDestinationId(),
        [ProjectRole::ACCOUNTANT],
        $uid
    )
) {
                throw new ForbiddenException(
                    'You are not assigned as accountant for this project.'
                );
            }
        }
        }

        $expected = match ($role) {
            DecisionRole::M1 => TransactionStatus::PENDING_M1,
            DecisionRole::M2 => TransactionStatus::PENDING_M2,
            DecisionRole::ACCOUNTANT => TransactionStatus::PENDING_ACCOUNTANT,
            default => throw new ValidationException('Unknown approval role.'),
        };   
         if (!$readOnly && $txn->getStatus() !== $expected) {
            throw new ValidationException('This transaction is not currently pending at this approval stage.');
        }

        if (!$readOnly && $version !== null && $txn->getVersion() !== $version) {
            throw new ConflictException('This transaction changed after you opened it. Review the latest revision.');
        }

        return [$txn, $list, $uid, $role];
    }

    private function currentRevisionRowId(Transaction $txn): ?int {
        try {
            return (int)$this->revisionMapper->findRevision((int)$txn->getId(), $txn->getCurrentRevision())->getId();
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Moves the Cost List to ACCOUNTING once every transaction in it
     * has reached a terminal M2 outcome. Since routing is per
     * transaction, this is a simple "are we all done" check, not a
     * stage transition tied to a single manager pair.
     */
    private function maybeAdvanceListToAccounting(CostList $list): void {
        $pending = $this->txnMapper->countByListStatuses((int)$list->getId(), [TransactionStatus::PENDING_M1, TransactionStatus::PENDING_M2]);
        if ($pending > 0) return;

        $list->setFinalTotal($this->txnMapper->sumByListStatuses((int)$list->getId(), [TransactionStatus::FINAL_APPROVED]));
        $list->setStatus(CostListStatus::ACCOUNTING);
        $list->setManager2CompletedAt(time());
        $list->setVersion($list->getVersion() + 1);
        $this->listMapper->update($list);
        $this->audit->record('COST_LIST', (int)$list->getId(), 'LIST_REACHED_ACCOUNTING', 'system', [
            'finalTotal' => $list->getFinalTotal(),
        ]);
    }

    /** @return array<string,mixed> */
    private function queueSummary(Transaction $txn, string $role): array {
        $destination = null;
        if ($txn->getDestinationId() !== null) {
            try {
                $d = $this->projectMapper->find((int)$txn->getDestinationId());
                $destination = ['uuid' => $d->getUuid(), 'code' => $d->getCode(), 'name' => $d->getName(), 'type' => $d->getType()];
            } catch (DoesNotExistException) {
            }
        }

        $revisionId = $this->currentRevisionRowId($txn);
        $priorM1 = $role === DecisionRole::M2 ? $this->decisions->latestForRole((int)$txn->getId(), DecisionRole::M1, $revisionId) : null;

        return [
            'uuid' => $txn->getUuid(),
            'destination' => $destination,
            'purchaserId' => $txn->getPurchaserId(),
            'amountMinor' => $txn->getAmountMinor(),
            'status' => $txn->getStatus(),
            'sameManagerFlag' => $this->decisions->sameManagerFlag($txn),
            // Surfaced directly on the queue row so M2 sees a
            // disagreement before opening the transaction, not after.
            'manager1Decision' => $priorM1,
            'version' => $txn->getVersion(),
        ];
    }
}
