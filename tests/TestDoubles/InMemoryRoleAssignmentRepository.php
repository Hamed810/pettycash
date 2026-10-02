<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

/**
 * These lookups are wrapped in `array_values()` deliberately.
 *
 * `array_filter()` preserves the keys of the array it filters, so a plain `array_filter()` over a list
 * keyed by surrogate id returns a *sparse* array -- `[3 => $assignment]` rather than `[0 => $assignment]`.
 * The port declares `@return list<RoleAssignment>` and the real repository honours it by rebuilding the
 * result with `array_map()`, so a caller is entitled to index the result from zero.
 *
 * Returning the sparse form here broke a caller that did exactly that: `$result[0] ?? null` silently
 * evaluated to null even though a match was present, and the audit event recorded "no manager assigned"
 * for a cost centre that had one. Nothing failed; the answer was just wrong. Any double that quietly
 * returns a different shape from the implementation it stands in for will eventually be believed.
 */

use DateTimeImmutable;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Shared\Enum\Role;

final class InMemoryRoleAssignmentRepository implements RoleAssignmentRepository
{
    /**
     * @var array<int, RoleAssignment>
     */
    private array $assignments = [];

    public function findById(int $id): ?RoleAssignment
    {
        return $this->assignments[$id] ?? null;
    }

    /**
     * @return list<RoleAssignment>
     */
    public function findActiveByUserUid(string $userUid, DateTimeImmutable $date): array
    {
        return array_values(array_filter(
            $this->assignments,
            fn($a) => $a->userUid === $userUid && $a->isValidOn($date)
        ));
    }

    /**
     * @return list<RoleAssignment>
     */
    public function findActiveByRoleAndCostCenter(Role $role, int $costCenterId, DateTimeImmutable $date): array
    {
        return array_values(array_filter(
            $this->assignments,
            fn($a) => $a->role === $role && $a->costCenterId === $costCenterId && $a->isValidOn($date)
        ));
    }

    /**
     * @return list<RoleAssignment>
     */
    public function findActiveByUserAndRole(string $userUid, Role $role, DateTimeImmutable $date): array
    {
        return array_values(array_filter(
            $this->assignments,
            fn($a) => $a->userUid === $userUid && $a->role === $role && $a->isValidOn($date)
        ));
    }

    /**
     * @return list<RoleAssignment>
     */
    public function findAllByRole(Role $role): array
    {
        // No `isValidOn()` call, and no `active` predicate: the real adapter does not filter either,
        // because the listing it serves is specified as active *and historical*. Filtering in the
        // double but not in the adapter is exactly the divergence this file's docblock warns about.
        $matched = array_values(array_filter(
            $this->assignments,
            fn($a) => $a->role === $role
        ));
        // The adapter orders by surrogate key; mirror it, or a caller asserting order against the
        // adapter and against this double gets two different answers.
        usort($matched, static fn(RoleAssignment $a, RoleAssignment $b): int => ($a->id ?? 0) <=> ($b->id ?? 0));
        return $matched;
    }

    /**
     * @see \OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository::findCurrentByUserAndRole()
     */
    public function findCurrentByUserAndRole(string $userUid, Role $role, DateTimeImmutable $date): ?RoleAssignment
    {
        // The date is compared as an ISO calendar-day string rather than as a `DateTimeImmutable`.
        // `$a->validFrom <= $date` would compare a midnight against whatever instant the clock
        // returned, and an assignment ending on the day being asked about would then read as expired
        // a whole day early -- the adapter compares DATE columns and would disagree.
        $day = $date->format('Y-m-d');

        $matched = array_values(array_filter(
            $this->assignments,
            fn($a) => $a->userUid === $userUid
                && $a->role === $role
                && $a->validFrom->format('Y-m-d') <= $day
                && ($a->validTo === null || $a->validTo->format('Y-m-d') >= $day)
        ));

        if ($matched === []) {
            return null;
        }

        usort($matched, static fn(RoleAssignment $a, RoleAssignment $b): int => ($b->id ?? 0) <=> ($a->id ?? 0));

        return $matched[0];
    }

    public function save(RoleAssignment $assignment): void
    {
        if ($assignment->id === null) {
            $assignment->id = count($this->assignments) + 1;
        }
        $this->assignments[$assignment->id] = $assignment;
    }

    public function clear(): void
    {
        $this->assignments = [];
    }
}