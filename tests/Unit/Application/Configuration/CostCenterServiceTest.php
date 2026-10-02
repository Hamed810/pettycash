<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Application\Configuration;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use OCA\PettyCash\Application\Configuration\ConfigurationConflict;
use OCA\PettyCash\Application\Audit\ConfigurationAuditService;
use OCA\PettyCash\Application\Configuration\CostCenterService;
use OCA\PettyCash\Domain\Configuration\CostCenter;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Tests\TestDoubles\FixedClock;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAdministratorCheck;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCostCenterRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryRoleAssignmentRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryUserDirectory;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionManager;
use PHPUnit\Framework\TestCase;

/**
 * T082 — `CostCenterService`, over the in-memory doubles.
 *
 * The centre of this file is that a cost centre's managers are **role assignments**, not properties on
 * the aggregate. T236's real-database round trip found `DbCostCenterRepository` writing `manager_uid`
 * and `senior_manager_uid` to columns no migration creates, so the fields were removed. Every case below
 * that touches a manager therefore asserts a row in the assignment repository, and the one case that
 * asserts the aggregate stays clean is the one that would fail if someone re-added the field.
 */
final class CostCenterServiceTest extends TestCase
{
    private const TODAY = '2026-01-01T00:00:00+00:00';

    private InMemoryCostCenterRepository $costCenters;

    private InMemoryRoleAssignmentRepository $roleAssignments;

    private FixedClock $clock;

    private CostCenterService $service;

    protected function setUp(): void
    {
        $this->costCenters = new InMemoryCostCenterRepository();
        $this->roleAssignments = new InMemoryRoleAssignmentRepository();
        $this->clock = new FixedClock(self::TODAY);
        $this->service = new CostCenterService(
            $this->costCenters,
            $this->roleAssignments,
            new InMemoryUserDirectory(),
            new InMemoryAdministratorCheck(),
            $this->clock,
            // T086: see `CurrencyServiceTest` -- supplied so the constructor is complete; the audit
            // behaviour is asserted in `tests/Integration/Audit/ConfigurationMutationAuditTest.php`.
            new ConfigurationAuditService(new InMemoryAuditWriter(), new InMemoryTransactionManager())
        );
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::TODAY);
    }

    // --- the shape T082 exists to get right ---

    public function testCreatingACostCentreCreatesTwoRoleAssignmentsRatherThanSettingProperties(): void
    {
        $created = $this->service->create(
            'test-admin',
            'CC-ENG',
            'Engineering',
            'Engineering department',
            $this->today(),
            null,
            'test-manager',
            'test-senior'
        );

        $costCenter = $created['costCenter'];
        self::assertSame('CC-ENG', $costCenter->code());

        // The managers are rows scoped by the centre's surrogate key — which is exactly how
        // `ManagementReviewService::getManagedCostCenterCodes()` already resolves a reviewer's scope.
        $managers = $this->roleAssignments->findActiveByRoleAndCostCenter(
            Role::CostCenterManager,
            (int) $costCenter->id,
            $this->today()
        );
        $seniors = $this->roleAssignments->findActiveByRoleAndCostCenter(
            Role::SeniorManager,
            (int) $costCenter->id,
            $this->today()
        );

        self::assertCount(1, $managers, 'FR-011: exactly one current cost-center manager');
        self::assertSame('test-manager', $managers[0]->userUid);
        self::assertCount(1, $seniors, 'FR-011: exactly one current senior manager');
        self::assertSame('test-senior', $seniors[0]->userUid);
    }

    public function testTheCreatedCostCentreAggregateCarriesNoManagerField(): void
    {
        // A manager property would be unwritable: nothing persists it, so it would be accepted and then
        // silently discarded — the failure T236 found, in the shape T082 is meant to prevent.
        $created = $this->service->create(
            'test-admin',
            'CC-ENG',
            'Engineering',
            null,
            $this->today(),
            null,
            'test-manager',
            'test-senior'
        );

        $properties = array_keys(get_object_vars($created['costCenter']));
        self::assertNotContains('managerUid', $properties);
        self::assertNotContains('seniorManagerUid', $properties);
    }

    public function testCreatingACostCentreRefusesAManagerWhoIsNotAHostUser(): void
    {
        // A typo'd user id leaves the centre unroutable, and it only surfaces when someone tries to
        // submit an expense against it — so it is refused at creation.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a user in this Nextcloud');
        $this->service->create(
            'test-admin',
            'CC-ENG',
            'Engineering',
            null,
            $this->today(),
            null,
            'nobody',
            'test-senior'
        );
    }

    public function testCreatingACostCentreRefusesADuplicateCodeAsAConflict(): void
    {
        // The double seeds CC001, so reusing its code is the duplicate case without arranging anything.
        try {
            $this->service->create(
                'test-admin',
                'CC001',
                'Another',
                null,
                $this->today(),
                null,
                'test-manager',
                'test-senior'
            );
            self::fail('a duplicate cost center code was accepted');
        } catch (ConfigurationConflict $e) {
            self::assertSame('CONFIGURATION_COST_CENTER_ALREADY_EXISTS', $e->errorCode());
        }
    }

    public function testCreatingACostCentreValidatesThroughTheDomain(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->create(
            'test-admin',
            'CC-ENG',
            '   ',
            null,
            $this->today(),
            null,
            'test-manager',
            'test-senior'
        );
    }

    // --- authorization ---

    public function testANonAdministratorIsRefusedEveryWrite(): void
    {
        $center = $this->costCenters->findByCode('CC001');
        self::assertNotNull($center);

        foreach (
            [
            fn () => $this->service->create(
                'test-user',
                'CC-X',
                'X',
                null,
                $this->today(),
                null,
                'test-manager',
                'test-senior'
            ),
            fn () => $this->service->transferManager(
                'test-user',
                $center,
                Role::CostCenterManager,
                'test-senior',
                $this->today()
            ),
            ] as $index => $write
        ) {
            try {
                $write();
                self::fail(sprintf('write %d was allowed for a non-administrator', $index));
            } catch (LogicException $e) {
                self::assertStringContainsString('administrator', $e->getMessage());
            }
        }
    }

    public function testAReadIsNotRestrictedToAdministratorsBecauseThePurchaserFormNeedsIt(): void
    {
        $page = $this->service->listValidOnForCaller('test-user');

        self::assertNotEmpty($page['items']);
        self::assertContains('CC001', array_map(
            static fn (CostCenter $c): string => $c->code(),
            $page['items']
        ));
    }

    // --- future routing, FR-010's validity dates ---

    public function testACentreWhoseValidFromIsInTheFutureIsAbsentFromTodaysListing(): void
    {
        // FR-010: validity dates "govern future transaction routing". A centre that starts next month
        // must not be offered to a purchaser filing an expense today.
        $this->service->create(
            'test-admin',
            'CC-FUTURE',
            'Future',
            null,
            $this->today()->modify('+1 month'),
            null,
            'test-manager',
            'test-senior'
        );

        $today = $this->service->listValidOnForCaller('test-user');
        $later = $this->service->listValidOnForCaller('test-user', $this->today()->modify('+2 months'));

        $todayCodes = array_map(static fn (CostCenter $c): string => $c->code(), $today['items']);
        $laterCodes = array_map(static fn (CostCenter $c): string => $c->code(), $later['items']);

        self::assertNotContains('CC-FUTURE', $todayCodes);
        self::assertContains('CC-FUTURE', $laterCodes);
    }

    public function testACentreWhoseValidToHasPassedIsAbsentFromTodaysListing(): void
    {
        $this->service->create(
            'test-admin',
            'CC-ENDED',
            'Ended',
            null,
            $this->today()->modify('-6 months'),
            $this->today()->modify('-1 day'),
            'test-manager',
            'test-senior'
        );

        $codes = array_map(
            static fn (CostCenter $c): string => $c->code(),
            $this->service->listValidOnForCaller('test-user')['items']
        );

        self::assertNotContains('CC-ENDED', $codes);
    }

    public function testTheListingDateDefaultsToTheClockRatherThanBeingUnstated(): void
    {
        // A listing whose membership depends on an unstated "now" is a listing whose answer depends on
        // when it was asked; the clock is injected so a test can move it.
        $this->service->create(
            'test-admin',
            'CC-FUTURE',
            'Future',
            null,
            $this->today()->modify('+1 month'),
            null,
            'test-manager',
            'test-senior'
        );

        self::assertNotContains('CC-FUTURE', array_map(
            static fn (CostCenter $c): string => $c->code(),
            $this->service->listValidOnForCaller('test-user')['items']
        ));

        $this->clock->setNow('2026-03-01T00:00:00+00:00');
        self::assertContains('CC-FUTURE', array_map(
            static fn (CostCenter $c): string => $c->code(),
            $this->service->listValidOnForCaller('test-user')['items']
        ));
    }

    // --- transfer: FR-011's "exactly one current" as a transfer, not a second assignment ---

    public function testTransferringAManagerClosesTheOutgoingAssignmentTheDayBefore(): void
    {
        // `RoleAssignment::isValidOn()` is inclusive at both ends, so closing on the same date would
        // leave one day on which two people are both current and a transaction dated that day would
        // route ambiguously.
        $created = $this->service->create(
            'test-admin',
            'CC-ENG',
            'Engineering',
            null,
            $this->today(),
            null,
            'test-manager',
            'test-senior'
        );
        $costCenter = $created['costCenter'];
        $from = $this->today()->modify('+1 month');

        $result = $this->service->transferManager(
            'test-admin',
            $costCenter,
            Role::CostCenterManager,
            'test-senior',
            $from
        );

        self::assertNotNull($result['outgoing']);
        self::assertSame('test-manager', $result['outgoing']->userUid);
        self::assertSame(
            $from->modify('-1 second')->format(DATE_ATOM),
            $result['outgoing']->validTo?->format(DATE_ATOM)
        );

        // On the boundary date itself, only the incoming holder is current.
        $current = $this->roleAssignments->findActiveByRoleAndCostCenter(
            Role::CostCenterManager,
            (int) $costCenter->id,
            $from
        );
        self::assertCount(1, $current, 'FR-011: exactly one current manager on the boundary date');
        self::assertSame('test-senior', $current[0]->userUid);
    }

    public function testTheOutgoingManagerIsStillCurrentTheDayBeforeTheTransfer(): void
    {
        $created = $this->service->create(
            'test-admin',
            'CC-ENG',
            'Engineering',
            null,
            $this->today(),
            null,
            'test-manager',
            'test-senior'
        );
        $from = $this->today()->modify('+1 month');
        $this->service->transferManager('test-admin', $created['costCenter'], Role::CostCenterManager, 'test-senior', $from);

        $before = $this->roleAssignments->findActiveByRoleAndCostCenter(
            Role::CostCenterManager,
            (int) $created['costCenter']->id,
            $from->modify('-1 day')
        );

        self::assertCount(1, $before);
        self::assertSame('test-manager', $before[0]->userUid);
    }

    public function testTransferringToTheUserWhoAlreadyHoldsTheRoleIsAConflict(): void
    {
        $created = $this->service->create(
            'test-admin',
            'CC-ENG',
            'Engineering',
            null,
            $this->today(),
            null,
            'test-manager',
            'test-senior'
        );

        try {
            $this->service->transferManager(
                'test-admin',
                $created['costCenter'],
                Role::CostCenterManager,
                'test-manager',
                $this->today()->modify('+1 day')
            );
            self::fail('a transfer to the current holder was accepted');
        } catch (ConfigurationConflict $e) {
            self::assertStringContainsString('COST_CENTER_MANAGER', $e->errorCode());
        }
    }

    public function testTransferringRefusesANonManagerRole(): void
    {
        $center = $this->costCenters->findByCode('CC001');
        self::assertNotNull($center);

        $this->expectException(InvalidArgumentException::class);
        $this->service->transferManager(
            'test-admin',
            $center,
            Role::Finance,
            'test-finance',
            $this->today()
        );
    }

    public function testTransferringRefusesAnUnknownHostUser(): void
    {
        $center = $this->costCenters->findByCode('CC001');
        self::assertNotNull($center);

        $this->expectException(InvalidArgumentException::class);
        $this->service->transferManager(
            'test-admin',
            $center,
            Role::CostCenterManager,
            'ghost',
            $this->today()
        );
    }

    // --- reading the current managers ---

    public function testCurrentManagersAreReportedPerRole(): void
    {
        $created = $this->service->create(
            'test-admin',
            'CC-ENG',
            'Engineering',
            null,
            $this->today(),
            null,
            'test-manager',
            'test-senior'
        );

        $managers = $this->service->currentManagersOn($created['costCenter'], $this->today());

        self::assertArrayHasKey(Role::CostCenterManager->value, $managers);
        self::assertArrayHasKey(Role::SeniorManager->value, $managers);
        self::assertSame('test-manager', $managers[Role::CostCenterManager->value][0]->userUid);
        self::assertSame('test-senior', $managers[Role::SeniorManager->value][0]->userUid);
    }

    public function testReferencingAnUnpersistedCostCentreFailsLoudly(): void
    {
        // `RoleAssignment::costCenterId` is a bigint foreign key, so a null here becomes
        // `cost_center_id = 0` — an assignment attached to no centre, invisible to every scope read.
        $unsaved = CostCenter::create('CC-UNSAVED', 'Unsaved', null, $this->today(), null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must be persisted before it can be referenced');
        $this->service->currentManagersOn($unsaved, $this->today());
    }

    // --- listing ---

    public function testListingPagesAndReportsTheWholeTotal(): void
    {
        $this->service->create(
            'test-admin',
            'CC-ENG',
            'Engineering',
            null,
            $this->today(),
            null,
            'test-manager',
            'test-senior'
        );

        $page = $this->service->listValidOnForCaller('test-user', null, 1, 1);

        self::assertCount(1, $page['items']);
        self::assertSame(2, $page['total'], 'total counts the whole valid set, not the page');
    }

    public function testListingRefusesAPageBelowTheContractMinimum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->listValidOnForCaller('test-user', null, 0, 25);
    }
}
