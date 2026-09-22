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

    public function canReview(AuthorizationContext $context, WorkflowStage $stage, string $costCenterId): bool
    {
        if ($stage === WorkflowStage::Finance) { return in_array(Role::Finance, $context->roles, true); }
        $role = $stage === WorkflowStage::CostCenter ? Role::CostCenterManager : Role::SeniorManager;
        return in_array($role, $context->roles, true) && in_array($costCenterId, $context->costCenterIds, true);
    }
}
