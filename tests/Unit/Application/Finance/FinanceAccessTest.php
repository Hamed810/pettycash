<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Application\Finance;

use DateTimeImmutable;
use OCA\PettyCash\Application\Finance\FinanceAccess;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Tests\TestDoubles\FixedClock;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryRoleAssignmentRepository;
use PHPUnit\Framework\TestCase;

/**
 * T004 — `FinanceAccess`, the Feature 008 Finance authorization primitive.
 *
 * The primitive answers one question: does the authenticated actor currently hold an *effective*
 * `FINANCE` assignment? Three properties are asserted over and over because each is a place the
 * obvious implementation is wrong:
 *
 * - **The actor is the session user, and only the session user.** `hasEffectiveFinanceAssignment()`
 *   takes no actor argument, so an actor identity can never arrive from request input. The test
 *   proves the decision follows `CurrentUser` by giving someone else a Finance assignment and
 *   confirming the session user is still refused.
 * - **"Effective" means active and inside the validity window.** The lookup reuses
 *   `RoleAssignmentRepository::findActiveByUserUid($uid, $now)`, which applies the `active` flag and
 *   the window — so an expired or inactive assignment is not effective.
 * - **Platform-administrator status grants nothing.** FR-008-005/FR-008-006 scope Feature 008
 *   cross-user reads to the Finance role. An administrator with no Finance assignment is refused,
 *   and the primitive never consults `AdministratorCheck` or `ReportScope`.
 */
final class FinanceAccessTest extends TestCase
{
    private const TODAY = '2026-01-01T00:00:00+00:00';

    private InMemoryRoleAssignmentRepository $assignments;

    private InMemoryCurrentUser $currentUser;

    private FixedClock $clock;

    private FinanceAccess $access;

    protected function setUp(): void
    {
        $this->assignments = new InMemoryRoleAssignmentRepository();
        $this->currentUser = new InMemoryCurrentUser('test-finance');
        $this->clock = new FixedClock(self::TODAY);
        $this->access = new FinanceAccess($this->assignments, $this->currentUser, $this->clock);
    }

    private function grantFinance(string $uid, string $validFrom, ?string $validTo = null, bool $active = true): void
    {
        $assignment = RoleAssignment::create(
            $uid,
            Role::Finance,
            null,
            new DateTimeImmutable($validFrom),
            $validTo === null ? null : new DateTimeImmutable($validTo)
        );
        $assignment->active = $active;
        $this->assignments->save($assignment);
    }

    public function testAnActiveFinanceAssignmentIsAdmitted(): void
    {
        $this->grantFinance('test-finance', '2025-01-01', '2030-12-31');

        self::assertTrue(
            $this->access->hasEffectiveFinanceAssignment(),
            'an active, in-window FINANCE assignment must admit the caller'
        );
    }

    public function testNoAssignmentIsRefused(): void
    {
        self::assertFalse(
            $this->access->hasEffectiveFinanceAssignment(),
            'a caller with no assignment at all must be refused'
        );
    }

    public function testAnExpiredFinanceAssignmentIsRefused(): void
    {
        // The assignment's window ends before the fixed "today" (2026-01-01), so it is not effective.
        $this->grantFinance('test-finance', '2025-01-01', '2025-12-31');

        self::assertFalse(
            $this->access->hasEffectiveFinanceAssignment(),
            'an assignment whose validity window has ended must be refused'
        );
    }

    public function testAnInactiveFinanceAssignmentIsRefused(): void
    {
        // `active = false` is a revoked assignment; `findActiveByUserUid()` filters it out even
        // though its window still covers today.
        $this->grantFinance('test-finance', '2025-01-01', '2030-12-31', false);

        self::assertFalse(
            $this->access->hasEffectiveFinanceAssignment(),
            'a revoked (inactive) FINANCE assignment must be refused'
        );
    }

    public function testAFinanceAssignmentForAnotherUserDoesNotAdmitTheSessionUser(): void
    {
        // Some user holds FINANCE, but not the session user. The primitive must answer for the
        // session user only — the caller cannot supply an actor identity that changes the answer.
        $this->grantFinance('some-other-user', '2025-01-01', '2030-12-31');
        $this->currentUser->setUid('test-finance');

        self::assertFalse(
            $this->access->hasEffectiveFinanceAssignment(),
            'another user\'s Finance assignment must not admit the session user'
        );
    }

    public function testTheDecisionFollowsTheCurrentUserDependency(): void
    {
        // The same assignment set is interpreted through whichever user the session reports. The
        // method takes no actor parameter, so the only source of identity is `CurrentUser`.
        $this->grantFinance('holder', '2025-01-01', '2030-12-31');

        $this->currentUser->setUid('holder');
        self::assertTrue(
            $this->access->hasEffectiveFinanceAssignment(),
            'the session user holding the assignment is admitted'
        );

        $this->currentUser->setUid('someone-else');
        self::assertFalse(
            $this->access->hasEffectiveFinanceAssignment(),
            'the same assignment set must not admit a different session user'
        );
    }

    public function testAnAdministratorWithNoFinanceAssignmentIsRefused(): void
    {
        // A platform administrator may hold an `ADMINISTRATOR` assignment (or none at all); either
        // way, without an effective FINANCE assignment, Feature 008 cross-user reads are refused.
        $administrator = RoleAssignment::create(
            'test-admin',
            Role::Administrator,
            null,
            new DateTimeImmutable('2025-01-01'),
            null
        );
        $this->assignments->save($administrator);

        $this->currentUser->setUid('test-admin');

        self::assertFalse(
            $this->access->hasEffectiveFinanceAssignment(),
            'administrator status alone must not grant the Finance gate'
        );
    }

    public function testAnAdministratorWhoAlsoHoldsAnActiveFinanceAssignmentIsAdmitted(): void
    {
        // The refusal is about the Finance assignment, not about the person: an administrator who
        // holds an effective FINANCE assignment is admitted through the Finance role.
        $administrator = RoleAssignment::create(
            'test-admin-finance',
            Role::Administrator,
            null,
            new DateTimeImmutable('2025-01-01'),
            null
        );
        $this->assignments->save($administrator);
        $this->grantFinance('test-admin-finance', '2025-01-01', '2030-12-31');

        $this->currentUser->setUid('test-admin-finance');

        self::assertTrue(
            $this->access->hasEffectiveFinanceAssignment(),
            'an effective FINANCE assignment admits regardless of administrator status'
        );
    }
}
