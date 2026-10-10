<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Audit;

use OCA\PettyCash\Application\Evidence\EvidenceVisibility;
use OCA\PettyCash\Domain\Audit\Repository\AuditEventRepository;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository;

/**
 * Role-scoped reads over one transaction's history (FR-051, FR-055, FR-056).
 *
 * Formal audit follows transaction visibility: the owner, an in-scope reviewer,
 * finance at its stage, and an administrator may read a transaction's audit and
 * revisions; anyone outside that scope gets nothing (the caller answers 404, so
 * the endpoint cannot confirm a record the caller may not see).
 *
 * ## Why the visibility predicate lives elsewhere
 *
 * The rule is `EvidenceVisibility::mayRead()`, and it is reused rather than
 * restated here on purpose: FR-060 keys evidence off transaction visibility and
 * FR-051/FR-055 key audit off the same rule, so "may I see this receipt" and
 * "may I see this history" cannot drift apart. A second copy would be a second
 * definition of a security rule, where a divergence is a disclosure bug rather
 * than a cosmetic one — which is exactly what `EvidenceVisibility`'s own
 * docblock forbids. The dependency direction (audit queries read the shared
 * transaction-visibility rule) is deliberate and stated, not incidental.
 *
 * ## What this deliberately does not own
 *
 * Configuration audit keeps its own scope (`ReportScope` /
 * `ChargedAccountScope`: administrators see all, finance see charged accounts,
 * managers see assigned cost centres) and is read through the report and export
 * paths, not here. Folding it into this service would duplicate that rule.
 */
final readonly class AuditQueryService
{
    public function __construct(
        private ExpenseTransactionRepository $transactions,
        private AuditEventRepository $audit,
        private TransactionRevisionRepository $revisions,
        private EvidenceVisibility $visibility,
    ) {}

    /**
     * Whether the actor may read this transaction's history.
     */
    public function mayRead(string $actorUid, ExpenseTransaction $transaction): bool
    {
        return $this->visibility->mayRead($actorUid, $transaction);
    }

    /**
     * The transaction when the actor may read its history, or null when the
     * transaction does not exist or the actor is outside its scope.
     *
     * Null covers both cases on purpose: distinguishing them would let a caller
     * without scope confirm that a record exists.
     */
    public function visibleTransaction(string $actorUid, string $transactionPublicId): ?ExpenseTransaction
    {
        $transaction = $this->transactions->findByPublicId($transactionPublicId);
        if ($transaction === null || !$this->mayRead($actorUid, $transaction)) {
            return null;
        }

        return $transaction;
    }

    /**
     * One page of the transaction's audit events, newest scope-read first.
     *
     * @return array{total: int, items: list<object>}
     */
    public function readTransactionAudit(ExpenseTransaction $transaction, int $offset, int $pageSize): array
    {
        $dbId = $transaction->dbId ?? 0;

        return [
            'total' => $this->audit->countByTransactionId($dbId),
            'items' => $this->audit->findByTransactionId($dbId, $offset, $pageSize),
        ];
    }

    /**
     * The transaction's immutable revisions, in stored order.
     *
     * @return list<object>
     */
    public function readRevisions(ExpenseTransaction $transaction): array
    {
        return $this->revisions->findByTransactionId($transaction->dbId ?? 0);
    }
}
