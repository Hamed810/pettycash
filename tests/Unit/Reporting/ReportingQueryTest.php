<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Reporting;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\PettyCash\Application\Reporting\DashboardQuery;
use OCA\PettyCash\Application\Reporting\ReportFilter;
use OCA\PettyCash\Application\Reporting\ReportQuery;
use OCA\PettyCash\Application\Reporting\ReportScope;
use OCA\PettyCash\Application\Reporting\UnsupportedFilterField;
use OCA\PettyCash\Domain\Configuration\CostCenter;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Shared\Clock;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Tests\TestDoubles\FixedClock;
use OCA\PettyCash\Tests\TestDoubles\InMemoryRoleAssignmentRepository;
use PHPUnit\Framework\TestCase;

/**
 * T106: role-scoped dashboard and report queries with server-side filters and a maximum page size.
 *
 * ## What these tests are for
 *
 * The scope rules are the security-relevant part, so they are asserted directly rather than through a
 * controller. Three properties matter and they fail in different directions, so each gets its own test:
 *
 * 1. **Scope is applied before filtering** — a row out of scope must never survive, whatever the
 *    filter says. Asserted by paginating a set where the out-of-scope row would pass every filter.
 * 2. **An empty scope never widens** — a manager with no cost centre sees nothing, and a finance officer
 *    with no charged accounts sees no configuration audit. This is the direction that fails dangerously:
 *    a scope bug that returns everything is a data leak, and one that returns nothing is a bug report.
 * 3. **The page size is bounded at 100** — the contract's stated maximum.
 */
final class ReportingQueryTest extends TestCase
{
    private const TODAY = '2026-09-27T00:00:00+00:00';

    private InMemoryRoleAssignmentRepository $assignments;
    private FixedClock $clock;
    private ReportQuery $reports;
    private DashboardQuery $dashboard;

    protected function setUp(): void
    {
        $this->assignments = new InMemoryRoleAssignmentRepository();
        $this->clock = new FixedClock(self::TODAY);
        $this->reports = new ReportQuery();
        $this->dashboard = new DashboardQuery($this->assignments, $this->clock);
    }

    private function at(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso);
    }

    // ===== The page size bound =====

    public function testTheMaximumPageSizeIsOneHundred(): void
    {
        self::assertSame(100, ReportFilter::MAX_PAGE_SIZE);
    }

    public function testAnOverLargePageSizeIsClampedToTheMaximum(): void
    {
        // Clamped, not refused: a client sending "give me everything" must be bounded by the server,
        // which is what server-side paging means.
        $filter = new ReportFilter(page: 1, pageSize: 5000);

        self::assertSame(100, $filter->pageSize);
    }

    public function testAPageSizeAtTheMaximumIsKept(): void
    {
        self::assertSame(100, (new ReportFilter(pageSize: 100))->pageSize);
    }

    public function testASmallPageSizeIsKept(): void
    {
        self::assertSame(7, (new ReportFilter(pageSize: 7))->pageSize);
    }

    public function testTheDefaultPageSizeIsTwentyFive(): void
    {
        self::assertSame(25, (new ReportFilter())->pageSize);
        self::assertSame(25, ReportFilter::DEFAULT_PAGE_SIZE);
    }

    public function testAZeroOrNegativePageNumberIsRefused(): void
    {
        // Refused rather than clamped: there is no sensible bound to fall back to, and silently
        // swapping a page number would return the wrong records without saying so.
        $this->expectException(InvalidArgumentException::class);

        new ReportFilter(page: 0);
    }

    public function testAZeroOrNegativePageSizeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReportFilter(pageSize: 0);
    }

    public function testABackwardsDateRangeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReportFilter(from: $this->at('2026-09-01T00:00:00+00:00'), to: $this->at('2026-08-01T00:00:00+00:00'));
    }

    public function testTheOffsetFollowsPageAndPageSize(): void
    {
        $filter = new ReportFilter(page: 3, pageSize: 20);

        self::assertSame(40, $filter->offset);
    }

    public function testTheOffsetUsesTheClampedPageSize(): void
    {
        // A request for 5000 rows on page 2 is bounded to 100, so its offset must be computed from 100
        // rather than from 5000 — otherwise the clamp silently returns a different window.
        $filter = new ReportFilter(page: 2, pageSize: 5000);

        self::assertSame(100, $filter->offset);
    }

    public function testWithPagePreservesEveryOtherFilter(): void
    {
        $filter = new ReportFilter(
            page: 1,
            pageSize: 5000,
            from: $this->at('2026-01-01T00:00:00+00:00'),
            statuses: ['COST_CENTER'],
            costCenterDbIds: [7, 8],
            createdBy: 'maker'
        );

        $next = $filter->withPage(2);

        self::assertSame(2, $next->page);
        self::assertSame(100, $next->pageSize);
        self::assertSame(['COST_CENTER'], $next->statuses);
        self::assertSame([7, 8], $next->costCenterDbIds);
        self::assertSame('maker', $next->createdBy);
    }

    // ===== Scope resolution from role assignments =====

    public function testAPurchaserWithNoAssignmentsResolvesToTheirOwnScope(): void
    {
        $scope = $this->dashboard->scopeFor('purchaser-1');

        self::assertSame('purchaser-1', $scope->actorUid);
        self::assertSame([], $scope->costCenterDbIds);
        self::assertFalse($scope->administrator);
    }

    public function testAManagersCostCentresComeFromTheirRoleAssignments(): void
    {
        $this->givenAssignment('manager-1', Role::CostCenterManager, 11);
        $this->givenAssignment('manager-1', Role::CostCenterManager, 12);

        $scope = $this->dashboard->scopeFor('manager-1');

        // From the assignment rows, not from a column on the cost centre — T236 removed manager_uid and
        // senior_manager_uid because they existed in no migration and no table.
        self::assertSame([11, 12], $scope->costCenterDbIds);
        self::assertTrue($scope->hasRole(Role::CostCenterManager));
    }

    public function testAnAssignmentOutsideItsValidityWindowIsNotInScope(): void
    {
        $this->givenAssignment('manager-1', Role::CostCenterManager, 11, from: '2026-01-01T00:00:00+00:00', to: '2026-06-01T00:00:00+00:00');

        $scope = $this->dashboard->scopeFor('manager-1');

        self::assertSame([], $scope->costCenterDbIds, 'an expired assignment covers nothing');
    }

    public function testAnAssignmentNotYetEffectiveIsNotInScope(): void
    {
        $this->givenAssignment('manager-1', Role::CostCenterManager, 11, from: '2027-01-01T00:00:00+00:00');

        self::assertSame([], $this->dashboard->scopeFor('manager-1')->costCenterDbIds);
    }

    public function testAnAssignmentWithNoCostCentreCoversNone(): void
    {
        // Built through the constructor, not the factory: `RoleAssignment::create()` refuses a manager
        // role with no cost centre, which is a stronger guarantee than the scope filter. The filter in
        // `ReportScope` is defence for a row assembled elsewhere — a legacy read, a migration, a
        // hand-built aggregate — so this is the state it actually protects against.
        $this->assignments->save(new RoleAssignment(
            'manager-1',
            Role::CostCenterManager,
            null,
            $this->at('2020-01-01T00:00:00+00:00'),
            null
        ));

        // Not a null passed into a query — an empty scope, which is the safe direction.
        self::assertSame([], $this->dashboard->scopeFor('manager-1')->costCenterDbIds);
    }

    public function testAMultiRoleActorHoldsTheUnionOfTheirRoles(): void
    {
        $this->givenAssignment('both-1', Role::Purchaser, null);
        $this->givenAssignment('both-1', Role::CostCenterManager, 11);

        $scope = $this->dashboard->scopeFor('both-1');

        self::assertTrue($scope->hasRole(Role::Purchaser));
        self::assertTrue($scope->hasRole(Role::CostCenterManager));
    }

    public function testAnAdministratorIsUnrestrictedButHasNoDecisionAuthority(): void
    {
        $this->givenAssignment('admin-1', Role::Administrator, null);

        $scope = $this->dashboard->scopeFor('admin-1');

        self::assertTrue($scope->seesOthers());
        self::assertFalse(
            $this->dashboard->canDecide($scope),
            'FR-007: the administrator role confers visibility, not financial approval authority'
        );
    }

    public function testAReviewerCanDecide(): void
    {
        $this->givenAssignment('manager-1', Role::CostCenterManager, 11);

        self::assertTrue($this->dashboard->canDecide($this->dashboard->scopeFor('manager-1')));
    }

    public function testAPurchaserCannotDecide(): void
    {
        self::assertFalse($this->dashboard->canDecide($this->dashboard->scopeFor('purchaser-1')));
    }

    // ===== Scope before filter =====

    public function testARowOutOfScopeIsRemovedBeforeTheFilterRuns(): void
    {
        $scope = new ReportScope('manager-1', [Role::CostCenterManager], [11]);

        $rows = [
            ['id' => 'a', 'ownerUid' => 'manager-1', 'status' => 'COST_CENTER'],
            ['id' => 'b', 'ownerUid' => 'someone-else', 'status' => 'COST_CENTER'],
        ];

        // A filter that would keep both rows, so the removal can only be the scope's doing.
        $result = $this->reports->paginate(
            $scope,
            new ReportFilter(pageSize: 100, statuses: ['COST_CENTER']),
            $rows,
            static fn (array $row, ReportScope $s): bool => $s->coversOwned((string) $row['ownerUid'])
        );

        self::assertSame(1, $result['total']);
        self::assertSame('a', $result['rows'][0]['id']);
    }

    public function testAnAdministratorSeesRowsOwnedByOthers(): void
    {
        $scope = new ReportScope('admin-1', [Role::Administrator], [], true);

        $rows = [
            ['id' => 'a', 'ownerUid' => 'admin-1'],
            ['id' => 'b', 'ownerUid' => 'someone-else'],
        ];

        $result = $this->reports->paginate(
            $scope,
            new ReportFilter(pageSize: 100),
            $rows,
            static fn (array $row, ReportScope $s): bool => $s->coversOwned((string) $row['ownerUid'])
        );

        self::assertSame(2, $result['total']);
    }

    public function testAManagerSeesAnOthersRecordOnlyThroughItsCostCentre(): void
    {
        $scope = new ReportScope('manager-1', [Role::CostCenterManager], [11]);

        self::assertTrue($scope->coversCostCenter(11));
        self::assertFalse($scope->coversCostCenter(99), 'a cost centre outside the assignment is not in scope');
    }

    public function testAManagerWithNoCostCentreCoversNone(): void
    {
        $scope = new ReportScope('manager-1', [Role::CostCenterManager], []);

        self::assertFalse($scope->coversCostCenter(11));
    }

    public function testNarrowingACostCentreSetNeverWidensIt(): void
    {
        $scope = new ReportScope('manager-1', [Role::CostCenterManager], [11]);

        self::assertSame([11], $scope->narrowCostCenters([11, 12, 13]));
        self::assertSame([], $scope->narrowCostCenters([12, 13]));
    }

    public function testNarrowingForAnActorWithNoScopeReturnsNothing(): void
    {
        $scope = new ReportScope('purchaser-1', [Role::Purchaser], []);

        self::assertSame([], $scope->narrowCostCenters([11, 12]), 'an empty scope must not widen to all');
    }

    public function testNarrowingForAnAdministratorLeavesTheSetUnchanged(): void
    {
        $scope = new ReportScope('admin-1', [Role::Administrator], [], true);

        self::assertSame([11, 12, 13], $scope->narrowCostCenters([11, 12, 13]));
    }

    public function testAFinanceOfficerSeesAnOthersRecordOnceItReachedFinance(): void
    {
        $scope = new ReportScope('finance-1', [Role::Finance], []);

        self::assertFalse($scope->coversOwned('purchaser-9', reachedFinanceStage: false));
        self::assertTrue(
            $scope->coversOwned('purchaser-9', reachedFinanceStage: true),
            'FR-006: finance sees what has reached the finance stage'
        );
    }

    // ===== FR-055 configuration audit visibility =====

    public function testAnAdministratorSeesAllConfigurationAudit(): void
    {
        $scope = new ReportScope('admin-1', [Role::Administrator], [], true);

        $decision = $this->reports->configurationAuditScope($scope);

        self::assertTrue($decision['visible']);
        self::assertStringContainsString('all', $decision['reason']);
    }

    public function testAManagerSeesConfigurationAuditForItsAssignedCostCentres(): void
    {
        $scope = new ReportScope('manager-1', [Role::CostCenterManager], [11, 12]);

        $decision = $this->reports->configurationAuditScope($scope);

        self::assertTrue($decision['visible']);
        self::assertSame([11, 12], $decision['costCenterDbIds']);
        self::assertSame('cost_center', $decision['subjectType']);
    }

    public function testASeniorManagerSeesConfigurationAuditForItsAssignedCostCentres(): void
    {
        $scope = new ReportScope('senior-1', [Role::SeniorManager], [21]);

        $decision = $this->reports->configurationAuditScope($scope);

        self::assertTrue($decision['visible']);
        self::assertSame([21], $decision['costCenterDbIds']);
    }

    public function testAManagerWithNoCostCentreSeesNoConfigurationAudit(): void
    {
        $scope = new ReportScope('manager-1', [Role::CostCenterManager], []);

        $decision = $this->reports->configurationAuditScope($scope);

        self::assertFalse($decision['visible'], 'an empty scope must not widen to all');
    }

    public function testAFinanceOfficerSeesConfigurationAuditForAccountsTheyCharge(): void
    {
        $scope = new ReportScope('finance-1', [Role::Finance], []);

        $decision = $this->reports->configurationAuditScope($scope, [101, 102]);

        self::assertTrue($decision['visible']);
        self::assertSame([101, 102], $decision['accountDbIds']);
        self::assertSame('account', $decision['subjectType']);
    }

    public function testAFinanceOfficerWithNoChargedAccountsSeesNoConfigurationAudit(): void
    {
        $scope = new ReportScope('finance-1', [Role::Finance], []);

        $decision = $this->reports->configurationAuditScope($scope, []);

        self::assertFalse($decision['visible']);
        self::assertStringContainsString('must not widen', $decision['reason']);
    }

    public function testAPurchaserSeesNoConfigurationAudit(): void
    {
        $scope = new ReportScope('purchaser-1', [Role::Purchaser], []);

        $decision = $this->reports->configurationAuditScope($scope, [101]);

        self::assertFalse($decision['visible']);
    }

    public function testAMultiRoleManagerAndFinanceHoldsTheUnionOfBothRules(): void
    {
        $scope = new ReportScope('both-1', [Role::CostCenterManager, Role::Finance], [11]);

        $decision = $this->reports->configurationAuditScope($scope, [101]);

        // Both rules apply, so both subject scopes are carried and the report can union them.
        self::assertSame([11], $decision['costCenterDbIds']);
        self::assertSame([101], $decision['accountDbIds']);
    }

    public function testAnAdministratorWhoIsAlsoAManagerStillSeesAll(): void
    {
        $scope = new ReportScope('admin-1', [Role::Administrator, Role::CostCenterManager], [11], true);

        $decision = $this->reports->configurationAuditScope($scope, [101]);

        self::assertTrue($decision['visible']);
        self::assertSame([], $decision['costCenterDbIds'], '"all" is expressed as the absence of a restriction');
    }

    // ===== FR-056 the two audit streams stay separable =====

    public function testAConfigurationStageRowIsAConfigurationEvent(): void
    {
        self::assertTrue($this->reports->isConfigurationEvent(['stage' => 'CONFIGURATION']));
        self::assertFalse($this->reports->isTransactionEvent(['stage' => 'CONFIGURATION']));
    }

    public function testATransactionRowIsATransactionEvent(): void
    {
        self::assertTrue($this->reports->isTransactionEvent(['stage' => 'COST_CENTER', 'entityType' => 'transaction']));
        self::assertFalse($this->reports->isConfigurationEvent(['stage' => 'COST_CENTER', 'entityType' => 'transaction']));
    }

    public function testTransactionAuditFollowsTheRelatedTransactionsScope(): void
    {
        $scope = new ReportScope('finance-1', [Role::Finance], []);

        self::assertTrue(
            $this->reports->transactionAuditInScope($scope, [
                'ownerUid' => 'purchaser-9',
                'reachedFinanceStage' => true,
            ])
        );
        self::assertFalse(
            $this->reports->transactionAuditInScope($scope, [
                'ownerUid' => 'purchaser-9',
                'reachedFinanceStage' => false,
            ]),
            'FR-055: audit visibility follows the same restrictions as the related transaction'
        );
    }

    // ===== Report types =====

    public function testEveryContractReportTypeIsSupported(): void
    {
        // The contract's enum at `/reports/{reportType}`, verbatim. A client sends this string in the
        // URL, so an underscore variant or an invented extra type would be a contract violation rather
        // than a naming preference.
        self::assertSame(
            ['sheets', 'transactions', 'cost-centers', 'accounts', 'process', 'evidence', 'audit'],
            ReportQuery::SUPPORTED_TYPES
        );

        foreach (ReportQuery::SUPPORTED_TYPES as $type) {
            self::assertTrue($this->reports->supports($type), "{$type} must be supported");
        }
    }

    public function testTheContractSpellingOfCostCentersIsAccepted(): void
    {
        self::assertTrue($this->reports->supports('cost-centers'));
        self::assertFalse(
            $this->reports->supports('cost_centers'),
            'the underscore spelling is not in the contract and must not be advertised'
        );
    }

    public function testATypeTheContractDoesNotDeclareIsNotAdvertised(): void
    {
        // FR-065 names balances, movements and configuration audit as report subjects and the contract
        // enum omits all three. That gap is recorded on T112 rather than papered over by advertising
        // types the contract does not accept.
        foreach (['balances', 'movements', 'configuration_audit'] as $undeclared) {
            self::assertFalse($this->reports->supports($undeclared), "{$undeclared} is not a contract type");
        }
    }

    public function testTheReportPageShapeMatchesTheContract(): void
    {
        $scope = new ReportScope('purchaser-1', [Role::Purchaser], []);
        $result = $this->reports->paginate(
            $scope,
            new ReportFilter(),
            [['id' => 'a', 'ownerUid' => 'purchaser-1']],
            static fn (array $row, ReportScope $s): bool => $s->coversOwned((string) $row['ownerUid']),
            ['id', 'ownerUid'],
            ['totalMinor' => 125000]
        );

        // PageMeta (page, pageSize, total) plus columns, rows and summary.
        self::assertSame(['page', 'pageSize', 'total', 'columns', 'rows', 'summary'], array_keys($result));
        self::assertSame(['id', 'ownerUid'], $result['columns']);
        self::assertSame(['totalMinor' => 125000], $result['summary']);
    }

    public function testAnUnknownReportTypeIsRefusedWithTheSupportedList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported report type');

        $this->reports->assertSupported('profit_and_loss');
    }

    public function testAnOverLargeRequestStillReturnsAtMostTheMaximum(): void
    {
        $scope = new ReportScope('purchaser-1', [Role::Purchaser], []);
        $rows = array_map(
            static fn (int $i): array => ['id' => (string) $i, 'ownerUid' => 'purchaser-1'],
            range(1, 250)
        );

        $result = $this->reports->paginate(
            $scope,
            new ReportFilter(pageSize: 5000),
            $rows,
            static fn (array $row, ReportScope $s): bool => $s->coversOwned((string) $row['ownerUid'])
        );

        // 250 rows are in scope; a "give me everything" request is bounded to 100 per page.
        self::assertSame(250, $result['total']);
        self::assertCount(100, $result['rows']);
        self::assertSame(100, $result['pageSize']);
        self::assertSame(1, $result['page']);
    }

    public function testTheDefaultPageSizeBoundsTheWindowToTwentyFive(): void
    {
        $scope = new ReportScope('purchaser-1', [Role::Purchaser], []);
        $rows = array_map(
            static fn (int $i): array => ['id' => (string) $i, 'ownerUid' => 'purchaser-1'],
            range(1, 30)
        );

        $result = $this->reports->paginate(
            $scope,
            new ReportFilter(),
            $rows,
            static fn (array $row, ReportScope $s): bool => $s->coversOwned((string) $row['ownerUid'])
        );

        self::assertSame(30, $result['total']);
        self::assertCount(25, $result['rows']);
    }

    public function testTheSecondPageContinuesAfterTheFirst(): void
    {
        $scope = new ReportScope('purchaser-1', [Role::Purchaser], []);
        $rows = array_map(
            static fn (int $i): array => ['id' => (string) $i, 'ownerUid' => 'purchaser-1'],
            range(1, 30)
        );

        $first = $this->reports->paginate(
            $scope,
            new ReportFilter(page: 1),
            $rows,
            static fn (array $row, ReportScope $s): bool => $s->coversOwned((string) $row['ownerUid'])
        );
        $second = $this->reports->paginate(
            $scope,
            (new ReportFilter(page: 1))->withPage(2),
            $rows,
            static fn (array $row, ReportScope $s): bool => $s->coversOwned((string) $row['ownerUid'])
        );

        self::assertCount(25, $first['rows']);
        self::assertCount(5, $second['rows'], 'the remainder lands on page two');
        self::assertNotSame($first['rows'][0]['id'], $second['rows'][0]['id']);
    }

    public function testServerSideFiltersActuallyRemoveRows(): void
    {
        $scope = new ReportScope('purchaser-1', [Role::Purchaser], []);
        $rows = [
            ['id' => 'a', 'ownerUid' => 'purchaser-1', 'status' => 'COST_CENTER', 'currencyCode' => 'IRR'],
            ['id' => 'b', 'ownerUid' => 'purchaser-1', 'status' => 'FINAL', 'currencyCode' => 'IRR'],
            ['id' => 'c', 'ownerUid' => 'purchaser-1', 'status' => 'COST_CENTER', 'currencyCode' => 'USD'],
        ];

        $result = $this->reports->paginate(
            $scope,
            new ReportFilter(pageSize: 100, statuses: ['COST_CENTER'], currencyCodes: ['IRR']),
            $rows,
            static fn (array $row, ReportScope $s): bool => $s->coversOwned((string) $row['ownerUid'])
        );

        self::assertSame(1, $result['total']);
        self::assertSame('a', $result['rows'][0]['id']);
    }

    // ===== Dashboard sections =====

    public function testAMultiRoleUserSeesTheUnionOfTheirSections(): void
    {
        $this->givenAssignment('both-1', Role::Purchaser, null);
        $this->givenAssignment('both-1', Role::SeniorManager, 11);

        $sections = $this->dashboard->sections($this->dashboard->scopeFor('both-1'));

        // US7/AC1: a multi-role user sees the union.
        self::assertContains('mySheets', $sections);
        self::assertContains('balances', $sections);
        self::assertContains('reviewQueues', $sections);
        self::assertNotContains('financeQueue', $sections);
    }

    /**
     * FR-007: an administrator gets full visibility but no financial approval authority, so no
     * queue they cannot act on is offered. FR-018, on the other hand, makes every user with
     * application access a purchaser, so the purchaser sections are present as well.
     *
     * The previous version of this test asserted `['administration']` and nothing else, which
     * encoded the state before `ReportScope::forActor()` seeded the purchaser role. That is the
     * divergence the dashboard contract test found: `/me` listed PURCHASER for the same user while
     * the dashboard had no purchaser card. Asserting the union keeps both requirements honest --
     * the useful one here is the `assertNotContains`, which is what FR-007 actually cares about.
     */
    public function testAnAdministratorSeesAdministrationWithoutAnyDecisionQueue(): void
    {
        $this->givenAssignment('admin-1', Role::Administrator, null);

        $sections = $this->dashboard->sections($this->dashboard->scopeFor('admin-1'));

        self::assertContains('administration', $sections);
        // FR-018: still a purchaser, so the purchaser sections are the union's other half.
        self::assertContains('mySheets', $sections);
        // FR-007: no review or finance queue, because the administrator cannot decide in either.
        self::assertNotContains('reviewQueues', $sections);
        self::assertNotContains('financeQueue', $sections);
    }

    public function testAnAdministratorSummaryIsEmptyForANonAdministrator(): void
    {
        $scope = new ReportScope('purchaser-1', [Role::Purchaser], []);

        self::assertSame([], $this->dashboard->administrativeSummary($scope, 10, 20, 30));
    }

    public function testAnAdministratorSummaryReportsInstanceCounts(): void
    {
        $scope = new ReportScope('admin-1', [Role::Administrator], [], true);

        self::assertSame(
            ['totalSheets' => 10, 'totalTransactions' => 20, 'totalAccounts' => 30],
            $this->dashboard->administrativeSummary($scope, 10, 20, 30)
        );
    }

    // ===== T306: narrowing without paging, for the export path =====

    /**
     * `narrow()` is the export's half of `paginate()`: the same filter rule, with no window.
     *
     * An export that paged would be a lie twice over — `rowCount` would understate the selection and
     * the artifact would silently omit records the caller had named and been authorized to see. So
     * the assertion here is deliberately about a set *larger* than the maximum page size: a
     * `narrow()` that sliced would pass every test written against a handful of rows.
     */
    public function testNarrowingReturnsEveryMatchingRowRatherThanAPageOfThem(): void
    {
        $rows = array_map(
            static fn (int $i): array => [
                'id' => (string) $i,
                'ownerUid' => 'purchaser-1',
                'status' => $i % 2 === 0 ? 'OPEN' : 'FINAL',
            ],
            range(1, 250)
        );

        $narrowed = $this->reports->narrow(new ReportFilter(statuses: ['OPEN']), $rows);

        self::assertCount(125, $narrowed, 'every matching row, not the first 25 or 100');
        // Paging is what `paginate()` adds. Narrowing must not inherit it by accident.
        self::assertSame(
            125,
            $this->reports->paginate(
                new ReportScope('purchaser-1', [Role::Purchaser], []),
                new ReportFilter(pageSize: ReportFilter::MAX_PAGE_SIZE, statuses: ['OPEN']),
                $rows,
                static fn (array $row, ReportScope $s): bool => $s->coversOwned((string) $row['ownerUid'])
            )['total']
        );
    }

    public function testNarrowingWithoutAFilterReturnsTheRowsUnchanged(): void
    {
        $rows = [['id' => 'a', 'ownerUid' => 'purchaser-1'], ['id' => 'b', 'ownerUid' => 'purchaser-1']];

        self::assertSame($rows, $this->reports->narrow(new ReportFilter(), $rows));
    }

    /**
     * A filter that yields nothing is a true negative, and narrowing it yields nothing.
     *
     * Asserted because the neighbouring behaviour is the T307 defect: a filter compared against a key
     * the rows do not carry matches nothing while looking like an answer. That case is *refused*, and
     * the refusal is asserted separately below — this test pins the other half, so that fixing the
     * refusal cannot be achieved by making every filter return everything.
     */
    public function testAFilterThatGenuinelyMatchesNothingNarrowsToNothing(): void
    {
        $rows = [['id' => 'a', 'ownerUid' => 'purchaser-1', 'status' => 'OPEN']];

        self::assertSame([], $this->reports->narrow(new ReportFilter(statuses: ['NOT_A_STATUS']), $rows));
    }

    /**
     * The export path gets the refusal the report path gets, from the same code.
     *
     * A sheet row carries no `costCenterDbId`, so narrowing by one cannot be answered. Answering with
     * an empty set — an artifact with `rowCount: 0` — is the T302 defect's shape: a confident
     * measurement of nothing, delivered successfully.
     */
    public function testNarrowingRefusesAFacetTheRowsCannotAnswer(): void
    {
        $this->expectException(UnsupportedFilterField::class);
        $this->expectExceptionMessage('costCenterDbIds');

        $this->reports->narrow(
            new ReportFilter(costCenterDbIds: [1]),
            [['id' => 'a', 'ownerUid' => 'purchaser-1', 'status' => 'OPEN']]
        );
    }

    public function testNarrowingRefusesBeforeComparingRatherThanAfter(): void
    {
        // Two rows, one of which would have matched. If the refusal came *after* filtering, the
        // matching row would survive and the caller would get a partial answer with no explanation.
        $rows = [
            ['id' => 'a', 'ownerUid' => 'purchaser-1', 'status' => 'OPEN'],
            ['id' => 'b', 'ownerUid' => 'purchaser-1', 'status' => 'FINAL'],
        ];

        try {
            $this->reports->narrow(new ReportFilter(eventTypes: ['SHEET_SUBMITTED']), $rows);
            self::fail('a sheet row cannot answer an event-type filter');
        } catch (UnsupportedFilterField $e) {
            self::assertStringContainsString('eventTypes', $e->getMessage());
            self::assertStringContainsString('status', $e->getMessage(), 'the refusal should list what the rows do carry');
        }
    }

    public function testNarrowingAnEmptyRowSetIsNotARefusal(): void
    {
        // Nothing to compare against, so nothing is being asserted about the data. Refusing here
        // would make a filter unusable on an export whose selection matched no records, which is not
        // a wrong answer.
        self::assertSame([], $this->reports->narrow(new ReportFilter(costCenterDbIds: [1]), []));
    }

    public function testNarrowingReindexesTheRowsItKeeps(): void
    {
        // The export writes rows to CSV by position, so a preserved gap puts an empty line where a
        // record was filtered out — which reads in a spreadsheet as a record that has no values.
        //
        // The input is deliberately *gapped*, produced by `array_filter` without `array_values`, which
        // is what a caller that removed rows for its own reasons hands over. Both current callers pass
        // a list, so this is the only thing that makes the reindex a guarantee rather than a line psalm
        // correctly describes as unnecessary.
        $gapped = array_filter([
            ['id' => 'a', 'ownerUid' => 'purchaser-1', 'status' => 'FINAL'],
            ['id' => 'b', 'ownerUid' => 'purchaser-1', 'status' => 'OPEN'],
            ['id' => 'c', 'ownerUid' => 'purchaser-1', 'status' => 'OPEN'],
        ], static fn (array $row): bool => $row['status'] === 'OPEN');

        self::assertSame([1, 2], array_keys($gapped), 'the input must really be gapped for this to mean anything');

        $narrowed = $this->reports->narrow(new ReportFilter(), $gapped);

        self::assertSame([0, 1], array_keys($narrowed), 'a gap in the input must not reach the artifact');
        self::assertSame(['b', 'c'], array_column($narrowed, 'id'));
    }

    // ===== Helpers =====

    private function givenAssignment(
        string $uid,
        Role $role,
        ?int $costCenterDbId,
        string $from = '2020-01-01T00:00:00+00:00',
        ?string $to = null
    ): void {
        $this->assignments->save(RoleAssignment::create(
            $uid,
            $role,
            $costCenterDbId,
            $this->at($from),
            $to === null ? null : $this->at($to)
        ));
    }
}
