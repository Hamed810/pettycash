<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Security;

use OCA\PettyCash\Application\Security\AuthorizationContext;
use OCA\PettyCash\Application\Security\AuthorizationService;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use PHPUnit\Framework\TestCase;

final class ManagementReviewAuthorizationTest extends TestCase
{
    public function testCostCenterManagerSeesAssignedTransactions(): void
    {
        $service = new AuthorizationService();
        
        // Cost center manager with assigned cost center
        self::assertTrue($service->canReview(
            new AuthorizationContext('manager-1', 'owner-1', [Role::CostCenterManager], ['CC001'], false),
            WorkflowStage::CostCenter,
            'CC001'
        ));
    }

    public function testCostCenterManagerDeniedOutOfScope(): void
    {
        $service = new AuthorizationService();
        
        // Cost center manager without assigned cost center
        self::assertFalse($service->canReview(
            new AuthorizationContext('manager-1', 'owner-1', [Role::CostCenterManager], ['CC001'], false),
            WorkflowStage::CostCenter,
            'CC002'
        ));
    }

    public function testSeniorManagerSeesAssignedTransactions(): void
    {
        $service = new AuthorizationService();
        
        // Senior manager with assigned cost center
        self::assertTrue($service->canReview(
            new AuthorizationContext('senior-1', 'owner-1', [Role::SeniorManager], ['CC001'], false),
            WorkflowStage::Senior,
            'CC001'
        ));
    }

    public function testSeniorManagerDeniedOutOfScope(): void
    {
        $service = new AuthorizationService();
        
        // Senior manager without assigned cost center
        self::assertFalse($service->canReview(
            new AuthorizationContext('senior-1', 'owner-1', [Role::SeniorManager], ['CC001'], false),
            WorkflowStage::Senior,
            'CC002'
        ));
    }

    public function testFinanceOfficerAccessesFinanceStage(): void
    {
        $service = new AuthorizationService();
        
        // Finance officer can access finance stage (shared queue)
        self::assertTrue($service->canReview(
            new AuthorizationContext('finance-1', 'owner-1', [Role::Finance], [], false),
            WorkflowStage::Finance,
            'CC001'
        ));
    }

    public function testFinanceOfficerDeniedManagementStages(): void
    {
        $service = new AuthorizationService();
        
        // Finance officer cannot access cost center or senior stages
        self::assertFalse($service->canReview(
            new AuthorizationContext('finance-1', 'owner-1', [Role::Finance], [], false),
            WorkflowStage::CostCenter,
            'CC001'
        ));
        self::assertFalse($service->canReview(
            new AuthorizationContext('finance-1', 'owner-1', [Role::Finance], [], false),
            WorkflowStage::Senior,
            'CC001'
        ));
    }

    public function testAdministratorWithoutFinanceCannotMakeFinanceDecision(): void
    {
        $service = new AuthorizationService();
        
        // Administrator without finance role cannot make finance decisions
        self::assertFalse($service->canReview(
            new AuthorizationContext('admin-1', 'owner-1', [], [], true),
            WorkflowStage::Finance,
            'CC001'
        ));
    }

    public function testAdministratorCanManageConfiguration(): void
    {
        $service = new AuthorizationService();
        
        // Administrator can view configuration
        self::assertTrue($service->canView(
            new AuthorizationContext('admin-1', 'owner-1', [], [], true)
        ));
    }

    public function testAuthorizedMultiRoleOwnerMaySelfReview(): void
    {
        $service = new AuthorizationService();
        
        // Purchaser who is also cost center manager can review own transaction
        // when scope and stage match
        self::assertTrue($service->canReview(
            new AuthorizationContext('user-1', 'user-1', [Role::CostCenterManager], ['CC001'], false),
            WorkflowStage::CostCenter,
            'CC001'
        ));
    }

    public function testPurchaserCannotReviewWithoutRole(): void
    {
        $service = new AuthorizationService();
        
        // Purchaser without review role cannot review
        self::assertFalse($service->canReview(
            new AuthorizationContext('user-1', 'user-1', [Role::Purchaser], [], false),
            WorkflowStage::CostCenter,
            'CC001'
        ));
    }

    public function testMultiRoleWithMissingScopeDenied(): void
    {
        $service = new AuthorizationService();
        
        // User has review role but not the right scope
        self::assertFalse($service->canReview(
            new AuthorizationContext('user-1', 'user-1', [Role::CostCenterManager], ['CC001'], false),
            WorkflowStage::CostCenter,
            'CC002'
        ));
    }

    public function testMultiRoleWithWrongStageDenied(): void
    {
        $service = new AuthorizationService();
        
        // Cost center manager cannot review at senior stage
        self::assertFalse($service->canReview(
            new AuthorizationContext('user-1', 'user-1', [Role::CostCenterManager], ['CC001'], false),
            WorkflowStage::Senior,
            'CC001'
        ));
    }

    public function testOwnershipGrantsPurchaserRightsOnly(): void
    {
        $service = new AuthorizationService();
        
        // Ownership allows viewing own sheet
        self::assertTrue($service->canView(
            new AuthorizationContext('user-1', 'user-1', [Role::Purchaser], [], false)
        ));
        
        // But ownership does NOT grant review rights
        self::assertFalse($service->canReview(
            new AuthorizationContext('user-1', 'user-1', [Role::Purchaser], [], false),
            WorkflowStage::CostCenter,
            'CC001'
        ));
    }
}