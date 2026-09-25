<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

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
        return array_filter($this->assignments, fn($a) => $a->userUid === $userUid && $a->isValidOn($date));
    }

    /**
     * @return list<RoleAssignment>
     */
    public function findActiveByRoleAndCostCenter(Role $role, int $costCenterId, DateTimeImmutable $date): array
    {
        return array_filter($this->assignments, fn($a) => $a->role === $role && $a->costCenterId === $costCenterId && $a->isValidOn($date));
    }

    /**
     * @return list<RoleAssignment>
     */
    public function findActiveByUserAndRole(string $userUid, Role $role, DateTimeImmutable $date): array
    {
        return array_filter($this->assignments, fn($a) => $a->userUid === $userUid && $a->role === $role && $a->isValidOn($date));
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