<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Audit;

use DateTimeImmutable;
use OCA\PettyCash\Application\Reporting\ReportQuery;
use OCA\PettyCash\Application\Reporting\ReportScope;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Tests\TestDoubles\InMemoryRoleAssignmentRepository;
use PHPUnit\Framework\TestCase;

/**
 * T139: configuration-audit visibility follows role scope (FR-055/FR-056).
 *
 * Administrators see all; finance officers see charged accounts; managers see
 * assigned cost centres; anyone else sees nothing. A multi-role actor holds the
 * union. Transaction/config separation across streams is pinned by the
 * round-trip scenario (separate tables, separate reads), not here.
 */
final class ConfigAuditScopeTest extends TestCase
{
    private InMemoryRoleAssignmentRepository $assignments;
    private ReportQuery $reports;
    private DateTimeImmutable $on;

    protected function setUp(): void
    {
        $this->assignments = new InMemoryRoleAssignmentRepository();
        $this->reports = new ReportQuery();
        $this->on = new DateTimeImmutable('2026-10-01T00:00:00Z');
    }

    private function assign(string $uid, Role $role, ?int $costCenterId = null): void
    {
        $this->assignments->save(RoleAssignment::create(
            $uid,
            $role,
            $costCenterId,
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
            null
        ));
    }

    private function scopeFor(string $uid, bool $isPlatformAdministrator = false): ReportScope
    {
        return ReportScope::forActor($this->assignments, $uid, $this->on, $isPlatformAdministrator);
    }

    public function testAdministratorSeesAllConfigurationAudit(): void
    {
        $scope = $this->reports->configurationAuditScope($this->scopeFor('admin-1', true));

        self::assertTrue($scope['visible']);
        self::assertSame([], $scope['costCenterDbIds']);
        self::assertSame([], $scope['accountDbIds']);
    }

    public function testFinanceOfficerSeesChargedAccounts(): void
    {
        $this->assign('finance-1', Role::Finance);
        $scope = $this->reports->configurationAuditScope($this->scopeFor('finance-1'), [11, 22]);

        self::assertTrue($scope['visible']);
        self::assertSame([11, 22], $scope['accountDbIds']);
        self::assertSame('account', $scope['subjectType']);
    }

    public function testFinanceOfficerWithNoChargedAccountsSeesNothing(): void
    {
        $this->assign('finance-1', Role::Finance);
        $scope = $this->reports->configurationAuditScope($this->scopeFor('finance-1'), []);

        self::assertFalse($scope['visible']);
    }

    public function testManagerSeesAssignedCostCentres(): void
    {
        $this->assign('manager-1', Role::CostCenterManager, 7);
        $scope = $this->reports->configurationAuditScope($this->scopeFor('manager-1'));

        self::assertTrue($scope['visible']);
        self::assertSame([7], $scope['costCenterDbIds']);
        self::assertSame('cost_center', $scope['subjectType']);
    }

    public function testPurchaserSeesNoConfigurationAudit(): void
    {
        $scope = $this->reports->configurationAuditScope($this->scopeFor('purchaser-1'));

        self::assertFalse($scope['visible']);
    }

    public function testMultiRoleActorHoldsTheUnion(): void
    {
        $this->assign('multi-1', Role::CostCenterManager, 7);
        $this->assign('multi-1', Role::Finance);
        $scope = $this->reports->configurationAuditScope($this->scopeFor('multi-1'), [11]);

        self::assertTrue($scope['visible']);
        self::assertSame([7], $scope['costCenterDbIds']);
        self::assertSame([11], $scope['accountDbIds']);
        self::assertSame('cost_center_or_account', $scope['subjectType']);
    }
}
