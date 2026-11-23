<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Finance;

use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Shared\Clock;
use OCA\PettyCash\Domain\Shared\Enum\Role;

/**
 * Feature 008 — the single reusable Finance authorization primitive.
 *
 * Answers whether the **authenticated** actor currently holds an effective `FINANCE` assignment.
 * It is the one predicate every Feature 008 cross-user read surface (holder directory, holder
 * accounts, ledger, completed history) and the completed-history evidence branch will share, so
 * none of those consumers can diverge about what "is this caller a Finance Manager" means.
 *
 * ## The actor comes from the authenticated session, never from a request
 *
 * The UID is read from the existing `CurrentUser` port, which is backed by the session
 * (`NextcloudCurrentUser`). No caller-supplied UID, query parameter, or body field is accepted —
 * the method takes no actor argument at all, so "who is asking" cannot be spoofed by input.
 *
 * ## Effective assignment, not administrative platform status
 *
 * "Effective" uses the same active-assignment lookup every role-scoped read uses:
 * `RoleAssignmentRepository::findActiveByUserUid($uid, $now)` filters on the assignment's `active`
 * flag and its validity window (`RoleAssignment::isValidOn()`), so an expired, inactive, or
 * never-existing assignment is not effective.
 *
 * Platform-administrator status deliberately grants **nothing** here. The method never consults
 * `AdministratorCheck` or `Role::Administrator`, and it does not use `ReportScope::seesOthers()`
 * (which is administrator visibility, a different concept). A platform administrator without an
 * effective `FINANCE` assignment is therefore refused — FR-008-005/FR-008-006 scope Feature 008
 * cross-user reads to the Finance role, and administrator status alone must not open them.
 *
 * ## Scope
 *
 * This is a read predicate only. It does not mutate assignments, decide financial outcomes, or
 * broaden any other authorization service, and it leaves purchaser authorization untouched.
 */
final readonly class FinanceAccess
{
    public function __construct(
        private RoleAssignmentRepository $roleAssignments,
        private CurrentUser $currentUser,
        private Clock $clock
    ) {}

    /**
     * Whether the authenticated actor currently holds an effective `FINANCE` assignment.
     *
     * `findActiveByUserUid()` already applies the active-flag and validity-window filters, so the
     * resolution is once per request and the answer is strictly "an active, in-window assignment of
     * role `FINANCE` exists for the session user". No exception is raised when there is none.
     */
    public function hasEffectiveFinanceAssignment(): bool
    {
        $actorUid = $this->currentUser->uid();
        $now = $this->clock->now();

        foreach ($this->roleAssignments->findActiveByUserUid($actorUid, $now) as $assignment) {
            if ($assignment->role === Role::Finance) {
                return true;
            }
        }

        return false;
    }
}
