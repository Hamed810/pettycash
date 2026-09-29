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