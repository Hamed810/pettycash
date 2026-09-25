<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Security;

use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;

final class AuthorizationService
{
    public function canView(AuthorizationContext $context): bool
    {
        return $context->actorUid === $context->ownerUid || $context->administrator || $context->roles !== [];
    }

    public function canReview(AuthorizationContext $context, WorkflowStage $stage, string $costCenterCode): bool
    {
        // Finance stage: only Finance role (shared queue, no scope check needed)
        if ($stage === WorkflowStage::Finance) {
            return in_array(Role::Finance, $context->roles, true);
        }

        // Correction stage: only the transaction owner (purchaser) can correct
        if ($stage === WorkflowStage::Correction) {
            return $context->actorUid === $context->ownerUid;
        }

        // CostCenter and Senior stages: require role + scope
        $requiredRole = $stage === WorkflowStage::CostCenter ? Role::CostCenterManager : Role::SeniorManager;
        
        // Multi-role self-review: if actor is the owner AND holds the required role + scope + stage permission, allow
        if ($context->actorUid === $context->ownerUid) {
            return in_array($requiredRole, $context->roles, true)
                && in_array($costCenterCode, $context->costCenterCodes, true);
        }

        // Normal review: require role + scope (not owner)
        return in_array($requiredRole, $context->roles, true)
            && in_array($costCenterCode, $context->costCenterCodes, true);
    }
}
