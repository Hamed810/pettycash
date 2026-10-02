<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Application\Configuration;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use OCA\PettyCash\Application\Audit\ConfigurationAuditService;
use OCA\PettyCash\Application\Configuration\FinanceAssignmentService;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Tests\TestDoubles\FixedClock;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAdministratorCheck;
use OCA\PettyCash\Tests\TestDoubles\InMemoryRoleAssignmentRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryUserDirectory;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionManager;
use PHPUnit\Framework\TestCase;

/**
 * T084 — `FinanceAssignmentService`, over the in-memory doubles.
 *
 * The contract has exactly two finance-assignment operations, `GET` and `POST /configuration/
 * finance-assignments`, and **no** `PATCH`. That single fact shapes this whole service: because the
 * only write carries `active` in its body and there is no separate deactivate route, `POST` must be an
 * *upsert that can also revoke*. If it merely inserted, a finance officer could never be removed from
 * the shared queue through the approved API, and FR-016's shared queue would only ever grow.
 *
 * Three properties are asserted over and over, because each is a place where the obvious
 * implementation is wrong:
 *
 * - **The role is `FINANCE` and the cost centre is null.** `data-model.md` gives `RoleAssignment.role`
 *   the value `FINANCE` and `cost_center_id` the note "null for shared finance role". A finance officer
 *   in a shared queue is deliberately *not* scoped to a centre, so passing anything other than null here
 *   would place a global role inside one centre's scope — and `RoleAssignment::create()` rejects it, so
 *   the domain already holds the line and the service must not work around it.
 * - **A lapsed assignment is never overwritten.** `GET` is specified as "Active **and historical**
 *   finance assignments". History only exists if an ended row survives, so re-adding a user whose prior
 *   assignment has lapsed must insert a new row and leave the old one alone. This is why the service
 *   needs the `Clock`: the decision is "is there a *currently valid* assignment", which is a question
 *   about today. `RoleAssignmentRepository::findAllByRole()` (added here) can list history but cannot
 *   answer it.
 * - **One live assignment per user.** The table carries only `pettycash_role_assignments_user_active_idx`,
 *   a non-unique index on `(user_uid, role, active)`, so nothing below the application layer stops two
 *   live `FINANCE` rows for one user. FR-016's shared queue would then count that officer twice. Only
 *   this service can hold the invariant.
 *
 * `changeReason` is required by `FinanceAssignmentWrite` and validated here, but nothing persists it:
 * `data-model.md` gives `RoleAssignment` no reason column, and FR-017's "explanation" is written to the
 * separate configuration audit history, which is T086's. So its absence here is a boundary, not a gap —
 * see the service docblock.
 */
final class FinanceAssignmentServiceTest extends TestCase
{
    private const TODAY = '2026-01-01T00:00:00+00:00';
    private const ADMIN = 'test-admin';

    private InMemoryRoleAssignmentRepository $roleAssignments;

    private FixedClock $clock;

    private FinanceAssignmentService $service;

    protected function setUp(): void
    {
        $this->roleAssignments = new InMemoryRoleAssignmentRepository();
        $this->clock = new FixedClock(self::TODAY);
        $this->service = new FinanceAssignmentService(
            $this->roleAssignments,
            new InMemoryUserDirectory(),
            new InMemoryAdministratorCheck(),
            $this->clock,
            // T086: see `CurrencyServiceTest` -- supplied so the constructor is complete; the audit
            // behaviour is asserted in `tests/Integration/Audit/ConfigurationMutationAuditTest.php`.
            new ConfigurationAuditService(new InMemoryAuditWriter(), new InMemoryTransactionManager())
        );
    }

    // --- the shape the shared queue requires ---

    public function testCreatingAnAssignmentYieldsAFinanceRoleWithNoCostCentre(): void
    {
        $created = $this->service->create(self::ADMIN, 'test-finance', '2026-01-01', 'onboarding');

        $this->assertSame(Role::Finance, $created->role, 'FR-016/T078: finance assignments share one queue');
        $this->assertNull(
            $created->costCenterId,
            'data-model.md: cost_center_id is null for the shared finance role'
        );
        $this->assertSame('test-finance', $created->userUid);
    }

    public function testTheCreatedAssignmentIsActiveByDefaultAndCarriesItsValidityWindow(): void
    {
        $created = $this->service->create(self::ADMIN, 'test-finance', '2026-01-01', 'onboarding');

        $this->assertTrue($created->active);
        $this->assertSame('2026-01-01', $created->validFrom->format('Y-m-d'));
        $this->assertNull($created->validTo, 'an open-ended assignment has no end date');
    }

    // --- authorization: writes are administrators only (T078's matrix) ---

    public function testANonAdministratorCannotCreateAnAssignment(): void
    {
        $this->expectException(LogicException::class);

        $this->service->create('test-finance', 'test-user', '2026-01-01', 'self-appointment');
    }

    public function testAnEmptyActorCannotCreateAnAssignment(): void
    {
        $this->expectException(LogicException::class);

        $this->service->create('', 'test-user', '2026-01-01', 'anonymous');
    }

    public function testTheAuthorizationCheckRunsBeforeAnyWrite(): void
    {
        // A refusal that still left a row behind would be a refusal the caller could not trust.
        try {
            $this->service->create('test-user', 'test-user', '2026-01-01', 'self-appointment');
        } catch (LogicException) {
            // expected
        }

        $this->assertSame([], $this->service->listForCaller(self::ADMIN));
    }

    // --- host-user validation: an assignment may only name a real Nextcloud user ---

    public function testAnAssignmentForAnUnknownHostUserIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->create(self::ADMIN, 'nobody', '2026-01-01', 'typo');
    }

    public function testAUserIdIsRequiredAndIsTrimmed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->create(self::ADMIN, '   ', '2026-01-01', 'blank');
    }

    // --- input validation the contract states ---

    public function testABlankChangeReasonIsRefused(): void
    {
        // `FinanceAssignmentWrite.changeReason` is `minLength: 1`, and FR-017 requires an explanation
        // for a sensitive change, so this is enforced at the edge rather than left to the audit layer.
        $this->expectException(InvalidArgumentException::class);

        $this->service->create(self::ADMIN, 'test-finance', '2026-01-01', '   ');
    }

    public function testAMalformedValidFromIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->create(self::ADMIN, 'test-finance', '01/01/2026', 'wrong format');
    }

    public function testAValidToBeforeValidFromIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->create(self::ADMIN, 'test-finance', '2026-06-01', 'backwards', '2026-01-01');
    }

    // --- POST is an upsert, because the contract has no deactivate route ---

    public function testPostingAgainForALiveAssignmentUpdatesItInPlaceRatherThanDuplicatingIt(): void
    {
        $first = $this->service->create(self::ADMIN, 'test-finance', '2026-01-01', 'onboarding');

        $second = $this->service->create(self::ADMIN, 'test-finance', '2026-01-01', 'correcting the note');

        $this->assertSame(
            $first->id,
            $second->id,
            'the contract summary is "Add or update": a live assignment must be updated, not duplicated'
        );
        $this->assertCount(1, $this->service->listForCaller(self::ADMIN));
    }

    public function testAnUpdateCanMoveTheValidityWindow(): void
    {
        $this->service->create(self::ADMIN, 'test-finance', '2026-01-01', 'onboarding');

        $updated = $this->service->create(self::ADMIN, 'test-finance', '2026-01-01', 'fixed term', '2026-03-31');

        $this->assertSame('2026-03-31', $updated->validTo?->format('Y-m-d'));
    }

    public function testPostingActiveFalseRevokesTheSharedQueueMembership(): void
    {
        // The contract offers no PATCH and no DELETE, and `FinanceAssignmentWrite.active` is required.
        // Revocation therefore has to arrive as a POST, or a finance officer could never be removed.
        $this->service->create(self::ADMIN, 'test-finance', '2026-01-01', 'onboarding');

        $revoked = $this->service->create(self::ADMIN, 'test-finance', '2026-01-01', 'left the team', null, false);

        $this->assertFalse($revoked->active);
        $this->assertCount(1, $this->service->listForCaller(self::ADMIN), 'the row is retained, not deleted');
        $this->assertSame([], $this->roleAssignments->findActiveByUserAndRole('test-finance', Role::Finance, $this->today()));
    }

    public function testTwoAdministratorsCannotLeaveTwoLiveAssignmentsForOneUser(): void
    {
        $this->service->create(self::ADMIN, 'test-finance', '2026-01-01', 'first administrator');
        $this->service->create(self::ADMIN, 'test-finance', '2026-01-01', 'second administrator');

        $live = $this->roleAssignments->findActiveByUserAndRole('test-finance', Role::Finance, $this->today());

        $this->assertCount(
            1,
            $live,
            'FR-016 counts officers, and no unique index exists to stop a second live row'
        );
    }

    // --- history is retained, which is what makes the Clock necessary ---

    public function testALapsedAssignmentIsNotOverwrittenWhenTheUserIsReAdded(): void
    {
        // GET is specified as "Active and historical finance assignments". If re-adding reused the
        // ended row, the record that this user *was* finance during the old window would be rewritten
        // and the history the contract promises would not exist.
        $this->service->create(self::ADMIN, 'test-finance', '2025-01-01', 'last year', '2025-12-31');

        $this->clock->setNow(self::TODAY);

        $reAdded = $this->service->create(self::ADMIN, 'test-finance', '2026-01-01', 'reappointed');

        $this->assertNotNull(
            $reAdded->id,
            'a new row is expected; the assertion that fails is the one on the retained history below'
        );

        $all = $this->service->listForCaller(self::ADMIN);
        $this->assertCount(2, $all, 'the lapsed assignment must survive alongside the new one');

        $windows = array_map(
            static fn (RoleAssignment $a): string => $a->validFrom->format('Y-m-d') . '/' . ($a->validTo?->format('Y-m-d') ?? 'open'),
            $all
        );
        $this->assertContains('2025-01-01/2025-12-31', $windows, 'the historical window must be untouched');
        $this->assertContains('2026-01-01/open', $windows);
    }

    public function testTheClockDecidesWhichAssignmentIsLive(): void
    {
        // Pinning the boundary proves the decision reads the clock rather than merely comparing rows.
        $this->clock->setNow('2025-06-30T23:00:00+00:00');
        $this->service->create(self::ADMIN, 'test-finance', '2025-01-01', 'in force', '2025-12-31');

        // Still inside the window: an update must land on the existing row.
        $this->clock->setNow('2025-12-31T23:00:00+00:00');
        $sameWindow = $this->service->create(self::ADMIN, 'test-finance', '2025-01-01', 'last day', '2025-12-31');
        $this->assertCount(1, $this->service->listForCaller(self::ADMIN), 'the last day of the window is still live');

        // The day after: the row has lapsed, so a new one is inserted and the old one is kept.
        $this->clock->setNow('2026-01-02T00:00:00+00:00');
        $this->service->create(self::ADMIN, 'test-finance', '2026-01-01', 'reappointed');
        $this->assertCount(2, $this->service->listForCaller(self::ADMIN));
        $this->assertTrue($sameWindow->active);
    }

    public function testValidityIsEvaluatedByCalendarDayNotByTheInstantItWasAsked(): void
    {
        // `valid_from`/`valid_to` are DATE columns and `isValidOn()` is inclusive of `valid_to`. An
        // assignment ending 2025-12-31 must still be live at 23:00 on that day; comparing the raw
        // instant against midnight would declare it expired a whole day early.
        $this->clock->setNow('2025-12-31T23:00:00+00:00');
        $this->service->create(self::ADMIN, 'test-finance', '2025-01-01', 'in force', '2025-12-31');

        $this->clock->setNow('2025-12-31T23:00:00+00:00');
        $this->service->create(self::ADMIN, 'test-finance', '2025-01-01', 'still in force', '2025-12-31');

        $this->assertCount(
            1,
            $this->service->listForCaller(self::ADMIN),
            'the same day must not be mistaken for a lapsed assignment'
        );
    }

    public function testAnInactiveAssignmentDoesNotBlockARevivalOfTheSameUser(): void
    {
        // A revoked officer is not "live", so re-appointing them must reuse the retained row rather than
        // accumulate one row per revocation.
        $this->service->create(self::ADMIN, 'test-finance', '2026-01-01', 'onboarding', null, false);

        $this->service->create(self::ADMIN, 'test-finance', '2026-01-01', 'reappointed');

        $this->assertCount(1, $this->service->listForCaller(self::ADMIN));
    }

    // --- the listing ---

    public function testTheListingReturnsActiveAndHistoricalAssignmentsTogether(): void
    {
        $this->service->create(self::ADMIN, 'test-finance', '2025-01-01', 'last year', '2025-12-31');
        $this->clock->setNow(self::TODAY);
        $this->service->create(self::ADMIN, 'test-finance', '2026-01-01', 'current');
        $this->service->create(self::ADMIN, 'test-user', '2026-01-01', 'revoked', null, false);

        $listing = $this->service->listForCaller(self::ADMIN);

        $this->assertCount(3, $listing, 'GET is specified as active *and historical* assignments');
    }

    public function testTheListingExcludesManagerAssignmentsOfTheSameUsers(): void
    {
        // The table holds every role. Returning a cost-centre manager here would answer a different
        // question than the contract's "List shared-queue finance officers".
        $this->roleAssignments->save(
            RoleAssignment::create('test-manager', Role::CostCenterManager, 1, $this->today(), null)
        );
        $this->roleAssignments->save(
            RoleAssignment::create('test-senior', Role::SeniorManager, 1, $this->today(), null)
        );

        $this->service->create(self::ADMIN, 'test-finance', '2026-01-01', 'onboarding');

        $listing = $this->service->listForCaller(self::ADMIN);

        $this->assertSame(['test-finance'], array_map(static fn (RoleAssignment $a): string => $a->userUid, $listing));
    }

    public function testEveryListedAssignmentIsAFinanceRoleWithNoCostCentre(): void
    {
        $this->service->create(self::ADMIN, 'test-finance', '2026-01-01', 'onboarding');

        foreach ($this->service->listForCaller(self::ADMIN) as $assignment) {
            $this->assertSame(Role::Finance, $assignment->role);
            $this->assertNull($assignment->costCenterId);
        }
    }

    public function testTheListingIsOrderedDeterministically(): void
    {
        $this->service->create(self::ADMIN, 'test-finance', '2026-01-01', 'onboarding');
        $this->service->create(self::ADMIN, 'test-user', '2026-01-01', 'second officer');

        $first = array_map(static fn (RoleAssignment $a): string => (string) $a->id, $this->service->listForCaller(self::ADMIN));
        $second = array_map(static fn (RoleAssignment $a): string => (string) $a->id, $this->service->listForCaller(self::ADMIN));

        $this->assertSame($first, $second, 'a listing whose order varies between identical calls is not usable');
    }

    public function testTheListingRequiresAnAuthenticatedCaller(): void
    {
        $this->expectException(LogicException::class);

        $this->service->listForCaller('   ');
    }

    public function testTheListingIsEmptyBeforeAnythingIsCreated(): void
    {
        $this->assertSame([], $this->service->listForCaller(self::ADMIN));
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::TODAY);
    }
}