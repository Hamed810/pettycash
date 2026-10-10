<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Security;

use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;

final class AuthorizationService
{
    /**
     * Whether the actor may *see* the record this context describes.
     *
     * ## Warning: the third clause is permissive, and this method has no production caller
     *
     * `$context->roles !== []` means **"holds any role at all"**, so this returns `true` for any
     * authenticated user who has any assignment — including a purchaser holding only `PURCHASER`. It
     * is therefore **not** a safe rule for anything scoped, and it must not be used for evidence or
     * document access: doing so would fix a reviewer's 404 while disclosing every transaction's
     * receipt to every purchaser in the instance (T362). Evidence access uses
     * `Application\Evidence\EvidenceVisibility`, which requires ownership, administration, or
     * `canReview()` at the record's own stage.
     *
     * The clause is left as-is rather than tightened because narrowing it would change a method that
     * only its own tests exercise, and that change is a reviewable decision about what "any role may
     * see" was ever meant to mean — not something to slip into an evidence-authorization fix.
     */
    public function canView(AuthorizationContext $context): bool
    {
        return $context->actorUid === $context->ownerUid || $context->administrator || $context->roles !== [];
    }

    /**
     * Whether the actor may take the review action this stage requires.
     *
     * FR-002 fixes the rule: effective access is determined by role, responsibility scope, record
     * ownership, workflow stage and record state, and **"all five factors must be satisfied for review
     * actions"** — except that ownership does not *forbid* review, it only fails to grant it. A user who
     * independently holds the required review role, scope and stage permission may review their own
     * transaction ("self-review is permitted when..."), and a user who owns it but holds neither the role
     * nor the scope may not.
     *
     * So ownership is deliberately **absent** from the two queue stages, and that is a decision rather
     * than an omission. It is stated here because the previous version of this method spelled the
     * absence as two byte-identical branches -- one commented "multi-role self-review: if actor is the
     * owner AND holds the required role + scope, allow" and one not -- which read as though a
     * distinction were being drawn when none was. That shape invites the edit that adds an ownership
     * check to the second branch, and the direction that edit naturally goes is the dangerous one: it
     * silently forbids self-review for exactly the multi-role user FR-002 names, and leaves every queue
     * empty for a manager who is not the owner.
     *
     * `CanReviewTest` covers both directions, so a collapse like that now fails rather than passing.
     *
     * The Correction stage is the exception and does check ownership: a returned transaction is
     * corrected by the purchaser who raised it, and that check returns before the role lookup, so a
     * manager with the right role and the right scope still cannot correct somebody else's record.
     */
    public function canReview(AuthorizationContext $context, WorkflowStage $stage, string $costCenterCode): bool
    {
        // Finance stage: only Finance role. FR-016 puts every finance officer on one shared queue, so
        // the cost-centre argument is not consulted at all -- asserted with an empty scope list in
        // `CanReviewTest`, so a later edit that hoists the scope check above this branch fails there.
        if ($stage === WorkflowStage::Finance) {
            return in_array(Role::Finance, $context->roles, true);
        }

        if ($stage === WorkflowStage::Correction) {
            return $context->actorUid === $context->ownerUid;
        }

        // The two queue stages differ only in which role they require. Ownership is not consulted --
        // see the method docblock for why, and for the test that pins it.
        $requiredRole = $stage === WorkflowStage::CostCenter ? Role::CostCenterManager : Role::SeniorManager;

        return in_array($requiredRole, $context->roles, true)
            && in_array($costCenterCode, $context->costCenterCodes, true);
    }
}
