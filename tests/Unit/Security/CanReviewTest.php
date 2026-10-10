<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Security;

use OCA\PettyCash\Application\Security\AuthorizationContext;
use OCA\PettyCash\Application\Security\AuthorizationService;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use PHPUnit\Framework\TestCase;

/**
 * T211: `canReview()` decides role, scope, stage and ownership -- and says which of them decide what.
 *
 * ## The gap this closes, and why it is a security one
 *
 * `canReview()` ends with two branches that are **byte-identical**:
 *
 * ```php
 * if ($context->actorUid === $context->ownerUid) {
 *     return in_array($requiredRole, $context->roles, true)
 *         && in_array($costCenterCode, $context->costCenterCodes, true);
 * }
 * return in_array($requiredRole, $context->roles, true)
 *     && in_array($costCenterCode, $context->costCenterCodes, true);
 * ```
 *
 * The comment above the first says "Multi-role self-review: if actor is the owner AND holds the
 * required role + scope + stage permission, allow", which reads as though a distinction is being drawn.
 * None is: FR-002 *permits* self-review when the role, scope and stage permission are independently
 * held, so ownership adds no further condition, and there is deliberately no ownership check here.
 * That is a defensible rule. Writing it as two identical branches with a comment claiming a
 * distinction is not, because it invites the edit that adds one -- and the direction that edit would
 * take is the dangerous one, silently forbidding self-review for the multi-role user FR-002 names.
 *
 * Nothing in the suite could catch that. Every pre-existing review test either had the reviewer as the
 * owner or used the Finance stage, which returns before these branches. **There was no test in which a
 * non-owner reviewed at all.** So the collapse below is proven to be caught, rather than asserted to be
 * caught.
 *
 * ## The Correction stage
 *
 * T211's first clause is "extend `canReview()` for Correction stage", and the branch exists -- with no
 * test at all. It is a security rule deciding who may alter a returned transaction, and it is now pinned
 * in both directions.
 */
final class CanReviewTest extends TestCase
{
    private AuthorizationService $service;

    protected function setUp(): void
    {
        $this->service = new AuthorizationService();
    }

    /**
     * @param list<Role> $roles
     * @param list<string> $costCenters
     */
    private function context(string $actor, string $owner, array $roles, array $costCenters): AuthorizationContext
    {
        return new AuthorizationContext($actor, $owner, $roles, $costCenters, false);
    }

    // ===== a non-owner may review: the case that had no coverage at all =====

    public function testAScopedManagerWhoIsNotTheOwnerMayReview(): void
    {
        // The load-bearing case. It is also the one an edit that adds an ownership check to the
        // non-owner branch would break, which is the direction such an edit naturally goes.
        self::assertTrue(
            $this->service->canReview(
                $this->context('manager-1', 'purchaser-1', [Role::CostCenterManager], ['cc1']),
                WorkflowStage::CostCenter,
                'cc1'
            ),
            'FR-002 makes ownership a purchaser right, not a review right. A manager outside the review '
            . 'path entirely would make every queue permanently empty.'
        );
    }

    public function testAScopedSeniorManagerWhoIsNotTheOwnerMayReview(): void
    {
        self::assertTrue(
            $this->service->canReview(
                $this->context('senior-1', 'purchaser-1', [Role::SeniorManager], ['cc1']),
                WorkflowStage::Senior,
                'cc1'
            )
        );
    }

    public function testAnAdministratorWhoIsNotTheOwnerMayNotDecideAtAQueue(): void
    {
        // Administration grants configuration rights, never decision authority. Asserted in the
        // positive direction too, because the Finance-stage test already covers the other half and
        // this is the stage the queue tests do not reach.
        $administrator = new AuthorizationContext('admin-1', 'purchaser-1', [], [], true);

        self::assertFalse($this->service->canReview($administrator, WorkflowStage::CostCenter, 'cc1'));
        self::assertFalse($this->service->canReview($administrator, WorkflowStage::Senior, 'cc1'));
    }

    // ===== all five factors: each one alone is not enough =====

    public function testTheRequiredRoleIsRequired(): void
    {
        // A senior manager must not decide at the cost-centre stage, and vice versa. Both hold the
        // scope, so only the role is under test.
        self::assertFalse(
            $this->service->canReview(
                $this->context('senior-1', 'purchaser-1', [Role::SeniorManager], ['cc1']),
                WorkflowStage::CostCenter,
                'cc1'
            ),
            'the senior role does not carry the cost-centre stage'
        );
        self::assertFalse(
            $this->service->canReview(
                $this->context('manager-1', 'purchaser-1', [Role::CostCenterManager], ['cc1']),
                WorkflowStage::Senior,
                'cc1'
            ),
            'the cost-centre role does not carry the senior stage'
        );
    }

    public function testScopeIsRequired(): void
    {
        // FR-002 names responsibility scope as one of the five factors, and a manager with no
        // assignment for the transaction's cost centre is the exact case that is easy to lose when a
        // branch is added.
        self::assertFalse(
            $this->service->canReview(
                $this->context('manager-1', 'purchaser-1', [Role::CostCenterManager], ['cc-other']),
                WorkflowStage::CostCenter,
                'cc1'
            ),
            'a manager assigned to another cost centre must not review this one'
        );
    }

    public function testOwnershipAloneGrantsNoReviewRight(): void
    {
        // "Ownership grants purchaser rights only, not review rights" -- FR-002, verbatim. The owner
        // with no role must be refused, and this is the half of self-review that only exists because
        // of who you are rather than what you hold.
        self::assertFalse(
            $this->service->canReview(
                $this->context('purchaser-1', 'purchaser-1', [], ['cc1']),
                WorkflowStage::CostCenter,
                'cc1'
            ),
            'owning the transaction does not make its owner a reviewer'
        );
    }

    // ===== self-review: permitted, and only with role and scope =====

    public function testAnOwnerWhoHoldsRoleAndScopeMaySelfReview(): void
    {
        // FR-002 permits it, and the two independent branches in the old implementation agreed. What
        // was missing was anything that would notice if they stopped agreeing.
        self::assertTrue(
            $this->service->canReview(
                $this->context('manager-1', 'manager-1', [Role::CostCenterManager], ['cc1']),
                WorkflowStage::CostCenter,
                'cc1'
            )
        );
    }

    public function testAnOwnerWithoutScopeMayNotSelfReview(): void
    {
        // The dangerous half of self-review. If a future edit adds an ownership check to the non-owner
        // branch, this still passes and the self-review tests fail -- which is the point of having both.
        self::assertFalse(
            $this->service->canReview(
                $this->context('manager-1', 'manager-1', [Role::CostCenterManager], ['cc-other']),
                WorkflowStage::CostCenter,
                'cc1'
            )
        );
    }

    public function testAnOwnerWhoHoldsBothRolesMaySelfReviewAtEitherStage(): void
    {
        // The multi-role case FR-002 is written for: one user, both review roles, both scopes.
        $context = $this->context('manager-1', 'manager-1', [Role::CostCenterManager, Role::SeniorManager], ['cc1']);

        self::assertTrue($this->service->canReview($context, WorkflowStage::CostCenter, 'cc1'));
        self::assertTrue($this->service->canReview($context, WorkflowStage::Senior, 'cc1'));
    }

    // ===== the Correction stage, which had no test at all =====

    public function testOnlyTheOwnerMayCorrectATransaction(): void
    {
        self::assertTrue(
            $this->service->canReview(
                $this->context('purchaser-1', 'purchaser-1', [], [], false),
                WorkflowStage::Correction,
                ''
            ),
            'a returned transaction is corrected by the purchaser who raised it'
        );
    }

    public function testAManagerMayNotCorrectSomebodyElsesTransaction(): void
    {
        // Even a manager with the right role and the right scope. Correction is the owner's act, and
        // the branch returns before the role check, so this asserts the *ordering* as much as the rule.
        self::assertFalse(
            $this->service->canReview(
                $this->context('manager-1', 'purchaser-1', [Role::CostCenterManager], ['cc1']),
                WorkflowStage::Correction,
                'cc1'
            )
        );
    }

    public function testAnAdministratorMayNotCorrectSomebodyElsesTransaction(): void
    {
        $administrator = new AuthorizationContext('admin-1', 'purchaser-1', [], [], true);

        self::assertFalse(
            $this->service->canReview($administrator, WorkflowStage::Correction, ''),
            'administration is not a substitute for ownership on the correction path'
        );
    }

    // ===== Finance: shared queue, no scope check =====

    public function testAnyFinanceOfficerMayDecideAtTheFinanceStageWithoutScope(): void
    {
        // FR-016 puts every finance officer on one shared queue, so the cost-centre argument is empty
        // here and must not be consulted. Asserted with an empty scope list so a later edit that
        // hoists the scope check above the stage branches fails here.
        self::assertTrue(
            $this->service->canReview(
                $this->context('finance-1', 'purchaser-1', [Role::Finance], []),
                WorkflowStage::Finance,
                'cc1'
            )
        );
    }

    public function testANonFinanceActorWithTheRightScopeMayNotDecideAtTheFinanceStage(): void
    {
        self::assertFalse(
            $this->service->canReview(
                $this->context('manager-1', 'purchaser-1', [Role::CostCenterManager], ['cc1']),
                WorkflowStage::Finance,
                'cc1'
            )
        );
    }

    public function testAnAdministratorWithoutTheFinanceRoleMayNotDecideAtTheFinanceStage(): void
    {
        // T078's one unproven sub-claim: administration grants configuration rights, never finance
        // decision authority. Distinct from the manager case above because an administrator is the
        // actor most likely to be assumed omnipotent — and the finance queue lists every transaction
        // regardless of scope, so the refusal must come from the role check alone, not from a scope
        // the administrator trivially satisfies.
        self::assertFalse(
            $this->service->canReview(
                $this->context('admin-1', 'purchaser-1', [Role::Administrator], []),
                WorkflowStage::Finance,
                'cc1'
            )
        );
    }
}
