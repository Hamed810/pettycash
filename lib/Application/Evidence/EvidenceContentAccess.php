<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Evidence;

use OCA\PettyCash\Application\Finance\CompletedSheetScope;
use OCA\PettyCash\Application\Finance\FinanceAccess;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;

/**
 * Feature 008 — content-only, completed-history-scoped evidence read authorization (T020).
 *
 * `mayRead()` decides whether an actor may stream evidence content through the existing
 * `GET /evidence/{evidenceId}/content` delivery point:
 *
 * 1. when the existing `EvidenceVisibility::mayRead()` rule already admits the actor — owner,
 *    administrator, or in-scope reviewer — access is granted unchanged; or
 * 2. otherwise, only when `FinanceAccess` confirms an effective `FINANCE` assignment **and**
 *    `CompletedSheetScope` confirms the transaction is terminal (`COMPLETED_APPROVED` /
 *    `COMPLETED_REJECTED` at `WorkflowStage::Final`), its sheet is `COMPLETED` /
 *    `COMPLETED_WITH_REJECTIONS`, and every transaction of that sheet is terminal.
 *
 * It is deliberately **content-only**: no upload, replacement, deletion, deactivation, link
 * creation, or any other mutation method exists here. `EvidenceVisibility` itself and
 * `AuditQueryService` are not modified — the branch is composed only at
 * `EvidenceController::content()`, and out-of-scope callers keep the existing 404/403
 * non-disclosure answers.
 */
final readonly class EvidenceContentAccess
{
    public function __construct(
        private EvidenceVisibility $visibility,
        private FinanceAccess $financeAccess,
        private CompletedSheetScope $completedSheetScope,
    ) {}

    /**
     * Whether `$actorUid` may read evidence content for `$transaction`.
     *
     * Returns `false` (never throws) for out-of-scope input.
     */
    public function mayRead(string $actorUid, ExpenseTransaction $transaction): bool
    {
        if ($this->visibility->mayRead($actorUid, $transaction)) {
            return true;
        }

        return $this->financeAccess->hasEffectiveFinanceAssignment()
            && $this->completedSheetScope->isExposedByCompletedHistory($transaction);
    }
}
