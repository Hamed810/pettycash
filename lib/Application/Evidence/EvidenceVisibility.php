<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Evidence;

use OCA\PettyCash\Application\Security\AuthorizationContext;
use OCA\PettyCash\Application\Security\AuthorizationService;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;

/**
 * Who may open one transaction's evidence (FR-060, Constitution IV).
 *
 * ## Why this exists
 *
 * `EvidenceController::content()` gated on `$evidence->uploadedBy === $actorUid`. **A reviewer is never
 * the uploader** — the evidence was uploaded by the purchaser, and the cost-centre manager, senior
 * manager and finance officer who must rely on it are three different accounts — so every reviewer
 * received `404 Evidence not found` for every receipt on every transaction they were asked to approve.
 * FR-060 says "**Authorized users** MUST be able to preview supported evidence", not "the uploader",
 * and Constitution IV names the rule: "Document and export access MUST follow transaction visibility
 * and MUST not expose data beyond the caller's role and responsibility scope."
 *
 * It is extracted from the controller rather than fixed in place for the reason T327 extracted
 * `SheetCapabilities`: the rule has to be assertable **as a matrix**, and a private method on the
 * controller is not testable without an `IDBConnection`, which the PHPUnit process does not have.
 *
 * ## The rule
 *
 * An actor may read a transaction's evidence when they **own the transaction**, **administer the
 * instance** (FR-007's full administrative visibility, which carries no financial authority), or
 * **may review it at its current stage** — which is `AuthorizationService::canReview()` against the
 * transaction's own stage and cost centre. The last clause is what admits the reviewer, and it is the
 * same call the decision endpoints make, so "may I see this receipt" and "may I decide on this
 * transaction" cannot drift apart.
 *
 * **The actor's roles and scope are resolved here rather than passed in.** `ManagementReviewService`
 * and `FinanceSettlementService` each carry a private copy of that resolution; a third copy in a
 * controller would be a third definition to keep in step, and this one is a *security* rule where a
 * divergence is a disclosure bug rather than a cosmetic one.
 *
 * ## What this deliberately does not use
 *
 * `AuthorizationService::canView()` is **not** called. It reads `$context->roles !== []` — "holds any
 * role at all" — so using it would have fixed the reviewer's 404 while **disclosing every transaction's
 * evidence to every purchaser holding the `PURCHASER` role**: a worse defect than the one being fixed,
 * converting an availability bug into a confidentiality bug. That method has no production caller and
 * now carries the same warning on its own docblock.
 */
final class EvidenceVisibility
{
    public function __construct(
        private AuthorizationService $authorization,
        private RoleAssignmentRepository $roleAssignments,
        private CostCenterRepository $costCenters,
    ) {}

    public function mayRead(string $actorUid, ExpenseTransaction $transaction): bool
    {
        // Ownership first: the purchaser who raised the expense reads their own receipt whatever stage
        // it is in, including while it sits in a queue they may not themselves act in.
        if ($actorUid === $transaction->ownerUid) {
            return true;
        }

        if (in_array(Role::Administrator, $this->rolesOf($actorUid), true)) {
            return true;
        }

        return $this->authorization->canReview(
            new AuthorizationContext(
                $actorUid,
                $transaction->ownerUid,
                $this->rolesOf($actorUid),
                $this->managedCostCenterCodes($actorUid, $transaction->stage),
                false
            ),
            $transaction->stage,
            $transaction->revision()->costCenterCode ?? ''
        );
    }

    /**
     * The actor's roles, with `PURCHASER` implied.
     *
     * The implication is `ManagementReviewService::getUserRoles()`'s, and it is copied rather than
     * shared because extracting a third collaborator for it is not worth the constructor churn;
     * **the important part is that it is not the empty list**, which is what `canView()` treats as
     * "may see everything".
     *
     * @return list<Role>
     */
    private function rolesOf(string $actorUid): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $roles = [];
        foreach ($this->roleAssignments->findActiveByUserUid($actorUid, $now) as $assignment) {
            if ($assignment->active) {
                $roles[] = $assignment->role;
            }
        }

        if (!in_array(Role::Purchaser, $roles, true)) {
            $roles[] = Role::Purchaser;
        }

        return array_values(array_unique($roles, SORT_REGULAR));
    }

    /**
     * The cost centres this actor is *assigned* to review at the transaction's stage.
     *
     * Built from the actor's own assignments, never from the record: `canReview()` asks whether the
     * subject's cost centre is in this list, so a list derived from the subject makes the comparison
     * permanently true and admits every manager to every cost centre (FR-004, FR-005, FR-009). This
     * is `ManagementReviewService::getManagedCostCenterCodes()`'s rule, for the same reason.
     *
     * @return list<string>
     */
    private function managedCostCenterCodes(string $actorUid, WorkflowStage $stage): array
    {
        $requiredRole = match ($stage) {
            WorkflowStage::CostCenter => Role::CostCenterManager,
            WorkflowStage::Senior => Role::SeniorManager,
            // Finance carries no cost-centre scope (FR-016's shared queue), and Correction is
            // ownership-only — `canReview()` decides both, and an empty list is the honest input.
            default => null,
        };

        if ($requiredRole === null) {
            return [];
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $codes = [];
        foreach ($this->roleAssignments->findActiveByUserAndRole($actorUid, $requiredRole, $now) as $assignment) {
            if ($assignment->costCenterId === null) {
                continue;
            }
            $code = $this->costCenters->findById($assignment->costCenterId)?->code();
            if ($code !== null && $code !== '') {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}
