<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Configuration\Repository;

use DateTimeImmutable;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Shared\Enum\Role;

/** @extends \IteratorAggregate<int, RoleAssignment> */
interface RoleAssignmentRepository
{
    public function findById(int $id): ?RoleAssignment;
    /** @return list<RoleAssignment> */
    public function findActiveByUserUid(string $userUid, DateTimeImmutable $date): array;
    /** @return list<RoleAssignment> */
    public function findActiveByRoleAndCostCenter(Role $role, int $costCenterId, DateTimeImmutable $date): array;
    /** @return list<RoleAssignment> */
    public function findActiveByUserAndRole(string $userUid, Role $role, DateTimeImmutable $date): array;
    /**
     * Every assignment holding this role, active or not, valid today or not.
     *
     * Added for T084. The three existing lookups cannot answer the contract's
     * `GET /configuration/finance-assignments`, which is specified as returning "Active **and
     * historical** finance assignments": every one of them filters on `active = true` and on a date,
     * and each is keyed by something the finance roster does not supply — a user, or a non-null
     * `int $costCenterId`. `findActiveByRoleAndCostCenter()` in particular cannot return a finance row
     * at all, because a finance assignment has `cost_center_id` NULL by definition.
     *
     * Ordered by surrogate key ascending so that repeated calls over unchanged data return the same
     * order; the schema offers no column that would make a different ordering meaningful.
     *
     * @return list<RoleAssignment>
     */
    public function findAllByRole(Role $role): array;
    /**
     * The user's most recent assignment holding this role whose validity window contains `$date`,
     * whether or not it is active.
     *
     * Added for T084 alongside `findAllByRole()`. "Whether or not it is active" is the whole point
     * and is what the three pre-existing lookups cannot express: each of them filters
     * `active = true`, so none can find the row an administrator has switched off.
     *
     * The `active` flag and the validity window answer different questions, and T084 needs both
     * separately. A window that has *ended* is a historical claim — "this user held the role until
     * that date" — and must survive to be overwritten by a re-appointment. A row that is merely
     * *inactive* carries no such claim; it is the same assignment, switched off, so re-appointing
     * must reuse it rather than leave a second row claiming the user held the role twice.
     *
     * The date is compared as a calendar day, inclusive of both ends, matching the DATE columns and
     * `RoleAssignment::isValidOn()`. Where more than one row matches, the highest surrogate key wins,
     * so repeated calls over unchanged data resolve to the same row.
     */
    public function findCurrentByUserAndRole(string $userUid, Role $role, DateTimeImmutable $date): ?RoleAssignment;
    public function save(RoleAssignment $roleAssignment): void;
}