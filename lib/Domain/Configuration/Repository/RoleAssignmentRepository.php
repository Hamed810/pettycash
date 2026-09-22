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
    public function save(RoleAssignment $roleAssignment): void;
}