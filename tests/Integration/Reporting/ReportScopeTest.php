<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Reporting;

use OCA\PettyCash\Application\Reporting\ReportFilter;
use OCA\PettyCash\Application\Reporting\ReportQuery;
use OCA\PettyCash\Application\Reporting\ReportScope;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use PHPUnit\Framework\TestCase;

/**
 * T102: role-scoped reports filter by scope before paging, per report type.
 *
 * The per-type `$inScope` predicates below mirror `ReportController::candidatesFor()`
 * (administrator always; cost-centre/process rows need assigned centres; owned rows
 * need ownership; finance reads charged accounts). They are mirrors, not copies:
 * the controller owns the rules; this pins the shared `paginate()` mechanism every
 * report type funnels through — scope first, then filter, then window — plus the
 * audit-scope hooks. Transaction/config audit-stream separation is owned by T139
 * and the round-trip suite, not repeated here.
 */
final class ReportScopeTest extends TestCase
{
    private ReportQuery $reports;

    protected function setUp(): void
    {
        $this->reports = new ReportQuery();
    }

    private function scope(string $uid, array $roles = [Role::Purchaser], array $centers = [], bool $admin = false): ReportScope
    {
        return new ReportScope($uid, $roles, $centers, $admin);
    }

    private function rows(): array
    {
        return [
            ['kind' => 'sheet', 'ownerUid' => 'purchaser-1', 'costCenterDbIds' => [7]],
            ['kind' => 'sheet', 'ownerUid' => 'purchaser-2', 'costCenterDbIds' => [9]],
            ['kind' => 'transaction', 'ownerUid' => 'purchaser-1', 'reachedFinanceStage' => false],
            ['kind' => 'transaction', 'ownerUid' => 'purchaser-2', 'reachedFinanceStage' => true],
            ['kind' => 'cost_center', 'id' => 7],
            ['kind' => 'cost_center', 'id' => 9],
            ['kind' => 'account', 'ownerUid' => 'purchaser-1'],
            ['kind' => 'process', 'ownerUid' => 'purchaser-2'],
            ['kind' => 'evidence', 'ownerUid' => 'purchaser-1'],
            ['kind' => 'audit', 'ownerUid' => 'purchaser-2', 'reachedFinanceStage' => false],
        ];
    }

    /**
     * Mirrors the controller's per-type predicates closely enough to exercise every
     * branch: owned rows by ownership, centred rows by assigned centres, and the
     * administrator passing everything.
     */
    private function inScope(array $row, ReportScope $scope): bool
    {
        if ($scope->administrator) {
            return true;
        }
        return match ($row['kind']) {
            'sheet', 'transaction', 'account', 'evidence' => $row['ownerUid'] === $scope->actorUid,
            'cost_center' => in_array($row['id'], $scope->costCenterDbIds, true),
            'process' => $scope->costCenterDbIds !== [],
            'audit' => $this->reports->transactionAuditInScope($scope, $row),
            default => false,
        };
    }

    private function paginateFor(ReportScope $scope, int $page = 1, int $pageSize = 25): array
    {
        return $this->reports->paginate(
            $scope,
            new ReportFilter($page, $pageSize),
            $this->rows(),
            fn (array $row, ReportScope $s): bool => $this->inScope($row, $s),
            ['kind'],
            []
        );
    }

    public function testPurchaserSeesOnlyOwnedRows(): void
    {
        $page = $this->paginateFor($this->scope('purchaser-1'));

        self::assertSame(4, $page['total']);
        foreach ($page['rows'] as $row) {
            self::assertSame('purchaser-1', $row['ownerUid'] ?? null);
        }
    }

    public function testManagerSeesAssignedCentresPlusOwnedRows(): void
    {
        $page = $this->paginateFor($this->scope('manager-1', [Role::Purchaser, Role::CostCenterManager], [7]));

        $kinds = array_column($page['rows'], 'kind');
        self::assertContains('cost_center', $kinds);
        self::assertNotContains('account', $kinds);
    }

    public function testAdministratorSeesEverything(): void
    {
        $page = $this->paginateFor($this->scope('admin-1', [Role::Administrator], [], true));

        self::assertSame(count($this->rows()), $page['total']);
    }

    public function testScopeAppliesBeforePaging(): void
    {
        // Page 2 of size 3 over the purchaser's 4 scoped rows: total counts the
        // scoped set, not the candidate set, and the window holds the remainder.
        $page = $this->paginateFor($this->scope('purchaser-1'), 2, 3);

        self::assertSame(4, $page['total']);
        self::assertCount(1, $page['rows']);
    }

    public function testAuditRowsFollowTransactionVisibility(): void
    {
        $scope = $this->scope('outsider-1');
        self::assertFalse($this->reports->transactionAuditInScope($scope, [
            'ownerUid' => 'purchaser-2',
            'reachedFinanceStage' => false,
        ]));
        self::assertTrue($this->reports->transactionAuditInScope(
            $this->scope('admin-1', [Role::Administrator], [], true),
            ['ownerUid' => 'purchaser-2', 'reachedFinanceStage' => false]
        ));
    }
}
