<?php

declare(strict_types=1);

namespace OCA\PettyCash\Service;

use OCA\PettyCash\Db\Decision;
use OCA\PettyCash\Db\DecisionMapper;
use OCA\PettyCash\Db\Transaction;
use OCA\PettyCash\Domain\ApprovalAction;
use OCA\PettyCash\Domain\DecisionRole;
use OCA\PettyCash\Domain\Exception\ForbiddenException;
use OCA\PettyCash\Domain\Exception\ValidationException;
use OCA\PettyCash\Domain\TransactionStatus;

/**
 * Records per-role (M1/M2) decisions, scoped to the transaction's
 * current revision, and computes a transaction's effective status
 * from them. Manager 1 never blocks the workflow -- whatever M1
 * decides, the transaction still reaches M2. The final outcome is
 * always M2's decision; M1's original decision is never overwritten
 * or hidden. A manager edit creates a new revision, and decisions
 * from a prior revision never count toward the new one.
 *
 * All guardrails here (self-approval, M1=M2 flag) belong in this
 * Service layer, never the frontend.
 */
final class DecisionService {
    private const VALID_DECISIONS = [
        ApprovalAction::APPROVE,
        ApprovalAction::REJECT,
        ApprovalAction::RETURN,
    ];

    public function __construct(
        private DecisionMapper $mapper,
        private AuditService $audit,
    ) {}

    /**
     * Records a decision for a role on a transaction, scoped to the
     * given revision **row id** (the pcash_revision snapshot's own id,
     * same convention as the existing ApprovalActionEntity.revisionId
     * -- not the transaction's currentRevision *number*). Append-only
     * -- never updates or removes a prior decision, even a re-decision
     * for the same role inserts a new row.
     *
     * @return array<string,mixed>
     */
    public function record(Transaction $txn, string $role, string $decision, ?string $reason, string $actorId, ?int $revisionId): array {
        $this->assertNotSelfApproval($txn, $actorId);

        if (!in_array($role, DecisionRole::ALL, true)) {
            throw new ValidationException('Unknown decision role.');
        }
        if (!in_array($decision, self::VALID_DECISIONS, true)) {
            throw new ValidationException('Unsupported decision.');
        }
        if (in_array($decision, [ApprovalAction::REJECT, ApprovalAction::RETURN], true) && trim((string)$reason) === '') {
            throw new ValidationException('A reason is required for reject or return.');
        }

        $d = new Decision();
        $d->setTxnId((int)$txn->getId());
        $d->setRevisionId($revisionId);
        $d->setRole($role);
        $d->setDecision($decision);
        $d->setReason($reason);
        $d->setActorId($actorId);
        $d->setCreatedAt(time());
        $d = $this->mapper->insert($d);

        $this->audit->record('TRANSACTION_DECISION', (int)$d->getId(), $role . '_' . $decision, $actorId, [
            'transactionId' => $txn->getId(),
            'revisionId' => $revisionId,
            'reason' => $reason,
        ]);

        return $this->serialize($d);
    }

    /**
     * A transaction's effective status for the given revision row id,
     * derived from its M1/M2 decision records -- never a stored,
     * single mutable field.
     *
     * Rule: M1's decision (any outcome) simply advances the
     * transaction to pending-M2; it never determines the final
     * outcome. Once M2 decides, that decision is final, regardless of
     * what M1 decided.
     */
    public function computeEffectiveStatus(
        int $txnId,
        ?int $revisionId,
        bool $skipManager1
    ): string {

        /*
        * Accountant is the final approval gate.
        */
        $accountant = $this->mapper->findLatestForRole(
            $txnId,
            DecisionRole::ACCOUNTANT,
            $revisionId
        );

        if ($accountant !== null) {

            return match ($accountant->getDecision()) {

                ApprovalAction::APPROVE =>
                    TransactionStatus::FINAL_APPROVED,

                ApprovalAction::REJECT =>
                    TransactionStatus::REJECTED_ACCOUNTANT,

                default =>
                    TransactionStatus::RETURNED_ACCOUNTANT,
            };
        }


        /*
        * Manager 2 is before Accountant.
        */
        $m2 = $this->mapper->findLatestForRole(
            $txnId,
            DecisionRole::M2,
            $revisionId
        );


        if ($m2 !== null) {

            return match ($m2->getDecision()) {

                ApprovalAction::APPROVE =>
                    TransactionStatus::PENDING_ACCOUNTANT,

                ApprovalAction::REJECT =>
                    TransactionStatus::REJECTED_M2,

                default =>
                    TransactionStatus::RETURNED_M2,
            };
        }


        /*
        * Business trip lists may skip Manager 1.
        */
        if ($skipManager1) {

            return TransactionStatus::PENDING_M2;
        }


        $m1 = $this->mapper->findLatestForRole(
            $txnId,
            DecisionRole::M1,
            $revisionId
        );


        if ($m1 !== null) {

            return TransactionStatus::PENDING_M2;
        }


        return TransactionStatus::PENDING_M1;
    }

    /** @return array<string,mixed>|null Latest decision for a role/revision, serialized -- for queue-summary badges. */
    public function latestForRole(int $txnId, string $role, ?int $revisionId): ?array {
        $d = $this->mapper->findLatestForRole($txnId, $role, $revisionId);
        return $d !== null ? $this->serialize($d) : null;
    }

    /** @return list<array<string,mixed>> All decisions for a transaction (all revisions), in order. */
    public function history(int $txnId): array {
        return array_map($this->serialize(...), $this->mapper->findByTransaction($txnId));
    }

    /**
     * True when the same person is resolved as both Manager 1 and
     * Manager 2 on this transaction. Allowed, but must be surfaced to
     * the reviewer -- never silent.
     */
    public function sameManagerFlag(Transaction $txn): bool {
        $m1 = $txn->getManager1Id();
        $m2 = $txn->getManager2Id();
        return $m1 !== null && $m2 !== null && $m1 === $m2;
    }

    private function assertNotSelfApproval(Transaction $txn, string $actorId): void {
        if ($txn->getPurchaserId() === $actorId) {
            throw new ForbiddenException('A purchaser cannot approve, reject, or return their own transaction.');
        }
    }

    /** @return array<string,mixed> */
    private function serialize(Decision $d): array {
        return [
            'role' => $d->getRole(),
            'decision' => $d->getDecision(),
            'reason' => $d->getReason(),
            'actorId' => $d->getActorId(),
            'revisionId' => $d->getRevisionId(),
            'createdAt' => $d->getCreatedAt(),
        ];
    }
}
