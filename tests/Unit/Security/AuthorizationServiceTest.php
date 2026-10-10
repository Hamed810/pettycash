<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Security;

use OCA\PettyCash\Application\Security\AuthorizationContext;
use OCA\PettyCash\Application\Security\AuthorizationService;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use PHPUnit\Framework\TestCase;

final class AuthorizationServiceTest extends TestCase
{
    public function testOwnerAndScopedReviewersAreAuthorized(): void
    {
        $service = new AuthorizationService();
        self::assertTrue($service->canView(new AuthorizationContext('u1', 'u1', [], [], false)));
        self::assertTrue($service->canReview(new AuthorizationContext('u1', 'u1', [Role::CostCenterManager], ['cc1'], false), WorkflowStage::CostCenter, 'cc1'));
        self::assertTrue($service->canReview(new AuthorizationContext('finance', 'u1', [Role::Finance], [], false), WorkflowStage::Finance, 'cc1'));
    }

    public function testAdministratorWithoutFinanceCannotMakeFinanceDecision(): void
    {
        $service = new AuthorizationService();
        self::assertFalse($service->canReview(new AuthorizationContext('admin', 'u1', [], [], true), WorkflowStage::Finance, 'cc1'));
    }

    public function testAuthorizedMultiRoleOwnerMaySelfReview(): void
    {
        $service = new AuthorizationService();
        self::assertTrue($service->canReview(new AuthorizationContext('u1', 'u1', [Role::SeniorManager], ['cc1'], false), WorkflowStage::Senior, 'cc1'));
    }
}
