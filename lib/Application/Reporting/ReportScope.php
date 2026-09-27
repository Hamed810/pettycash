<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Reporting;

use DateTimeImmutable;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Shared\Enum\Role;

/**
 * What one actor may see in a report, resolved from their role assignments.
 *
 * ## Why this is resolved once, up front
 *
 * `contracts/exports.md` requires that "every report query is role-scoped **before** filtering,
 * aggregation, or file generation". Resolving the scope into an immutable value first makes that order
 * structural: `DashboardQuery` and `ReportQuery` both take a `ReportScope` and a `ReportFilter` and can
 * only apply the scope first, because the scope is not something a later call can widen.
 *
 * It also removes the temptation to re-derive a reviewer's cost centres per query. That resolution
 * already exists in `ManagementReviewService::getManagedCostCenterCodes()`; this is the same rule in one
 * place both the workflow and the reports can read, which is why it lives here rather than being copied
 * into each report.
 *
 * ## The four scopes
 *
 * | Actor | Sees |
 * |---|---|
 * | Purchaser | their own sheets, transactions, evidence, balances and movements (FR-003) |
 * | Cost-centre / senior manager | only their assigned cost centres (FR-004, FR-005) |
 * | Finance officer | only what has reached the finance stage, or historical finance scope (FR-006) |
 * | Administrator | everything, but **no** decision authority (FR-007) |
 *
 * A multi-role actor holds the **union** of their scopes, which is FR-002/FR-008 applied to reads: a
 * user who is both a purchaser and a manager sees their own records *and* their cost centres, and
 * ownership never grants a reviewer's visibility. `administrator` is kept separate from the other roles
 * precisely because it grants visibility without authority.
 */
final readonly class ReportScope
{
    /**
     * @param list<Role> $roles
     * @param list<int> $costCenterDbIds cost centres the actor is assigned to, as surrogate keys
     * @param bool $unrestricted true only for an administrator
     */
    public function __construct(
        public string $actorUid,
        public array $roles = [],
        public array $costCenterDbIds = [],
        public bool $administrator = false
    ) {}

    /**
     * Resolve an actor's scope from their effective role assignments on a given date.
     *
     * "Effective" means the assignment is active and inside its validity window on that date, which is
     * the same rule `RoleAssignment::isValidOn()` applies and the same one the review queues use — so a
     * report cannot show a manager cost centres their assignment no longer covers.
     */
    public static function forActor(
        RoleAssignmentRepository $assignments,
        string $actorUid,
        DateTimeImmutable $on
    ): self {
        $effective = $assignments->findActiveByUserUid($actorUid, $on);

        $roles = [];
        $costCenterIds = [];
        $administrator = false;

        foreach ($effective as $assignment) {
            if (!$assignment->isValidOn($on)) {
                continue;
            }
            $roles[] = $assignment->role;

            if ($assignment->role === Role::Administrator) {
                $administrator = true;
            }

            // Managers and senior managers are scoped by assignment rows, not by a column on the cost
            // centre — T236 removed `manager_uid`/`senior_manager_uid` because they existed in no
            // migration and no table, so a report reading them would be reading a phantom.
            if (
                $assignment->role === Role::CostCenterManager
                || $assignment->role === Role::SeniorManager
            ) {
                $costCenterIds[] = $assignment->costCenterId;
            }
        }

        $roles = array_values(array_unique($roles, SORT_REGULAR));

        // A manager whose assignment carries no cost centre covers none. Dropping the nulls here rather
        // than passing them into every query is the difference between "no results" and "all results", and
        // the latter is the dangerous direction.
        //
        // No existence check against `CostCenterRepository` is made: an assignment pointing at a cost
        // centre that is not there is a data-integrity question for the owning code, and inventing a
        // `findByIds()` port method to serve a report's convenience would be speculative.
        $costCenterIds = array_values(array_unique(array_filter(
            $costCenterIds,
            static fn (?int $id): bool => $id !== null
        )));

        return new self($actorUid, $roles, $costCenterIds, $administrator);
    }

    public function hasRole(Role $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /**
     * Whether the actor may see records owned by someone else.
     *
     * Only an administrator, and only for *visibility*: FR-007 is explicit that the administrator role
     * does not confer financial approval authority, which is why this is about reads only.
     */
    public function seesOthers(): bool
    {
        return $this->administrator;
    }

    /**
     * Restrict a list of cost-centre keys to those in scope, preserving order and removing duplicates.
     *
     * An administrator gets the requested set unchanged; a manager gets the intersection; anyone with
     * no cost-centre scope gets nothing, which is the safe direction — an empty scope must never widen
     * into "everything".
     *
     * @param list<int> $requested
     * @return list<int>
     */
    public function narrowCostCenters(array $requested): array
    {
        if ($this->administrator) {
            return array_values(array_unique($requested));
        }

        if ($this->costCenterDbIds === []) {
            return [];
        }

        return array_values(array_intersect($requested, $this->costCenterDbIds));
    }

    /**
     * Whether a cost centre is in scope.
     *
     * An administrator sees all; a manager sees their assignments; a purchaser or finance officer sees
     * none, because FR-003 and FR-006 scope them by ownership and workflow stage rather than by cost
     * centre.
     */
    public function coversCostCenter(int $costCenterDbId): bool
    {
        if ($this->administrator) {
            return true;
        }

        return in_array($costCenterDbId, $this->costCenterDbIds, true);
    }

    /**
     * Whether a record owned by `$ownerUid` is visible.
     *
     * The purchaser's own records are always visible, an administrator's are, and a finance officer's
     * are once they have reached the finance stage — that last part is the caller's decision, because it
     * depends on the transaction's state, so this method takes it as a parameter rather than guessing.
     */
    public function coversOwned(string $ownerUid, bool $reachedFinanceStage = false): bool
    {
        if ($ownerUid === $this->actorUid) {
            return true;
        }
        if ($this->administrator) {
            return true;
        }

        return $this->hasRole(Role::Finance) && $reachedFinanceStage;
    }
}
