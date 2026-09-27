<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Persistence\Configuration;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class DbRoleAssignmentRepository implements RoleAssignmentRepository
{
    public function __construct(
        private readonly IDBConnection $connection,
        private readonly UserDirectory $userDirectory,
        private readonly CurrentUser $currentUser
    ) {}

    public function findById(int $id): ?RoleAssignment
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_role_assignments')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    /** @return list<RoleAssignment> */
    public function findActiveByUserUid(string $userUid, DateTimeImmutable $date): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_role_assignments')
            ->where($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)))
            ->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->lte('valid_from', $qb->createNamedParameter($date->format('Y-m-d'))))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('valid_to'),
                $qb->expr()->gte('valid_to', $qb->createNamedParameter($date->format('Y-m-d')))
            ));
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /** @return list<RoleAssignment> */
    public function findActiveByRoleAndCostCenter(Role $role, int $costCenterId, DateTimeImmutable $date): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_role_assignments')
            ->where($qb->expr()->eq('role', $qb->createNamedParameter($role->value)))
            ->andWhere($qb->expr()->eq('cost_center_id', $qb->createNamedParameter($costCenterId)))
            ->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->lte('valid_from', $qb->createNamedParameter($date->format('Y-m-d'))))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('valid_to'),
                $qb->expr()->gte('valid_to', $qb->createNamedParameter($date->format('Y-m-d')))
            ));
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /** @return list<RoleAssignment> */
    public function findActiveByUserAndRole(string $userUid, Role $role, DateTimeImmutable $date): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_role_assignments')
            ->where($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)))
            ->andWhere($qb->expr()->eq('role', $qb->createNamedParameter($role->value)))
            ->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->lte('valid_from', $qb->createNamedParameter($date->format('Y-m-d'))))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('valid_to'),
                $qb->expr()->gte('valid_to', $qb->createNamedParameter($date->format('Y-m-d')))
            ));
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    public function save(RoleAssignment $roleAssignment): void
    {
        $qb = $this->connection->getQueryBuilder();
        $existing = $roleAssignment->id ? $this->findById($roleAssignment->id) : null;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $currentUser = $this->currentUser->uid();

        // No optimistic lock here, and that is not an oversight. `data-model.md` gives Role Assignment
        // `id`, `public_id`, `user_uid`, `role`, `cost_center_id`, the validity window, `active` and
        // the audit columns — and no `lock_version`. This repository wrote the column on both the
        // insert and the update, so every role-assignment write failed with "Unknown column
        // 'lock_version'", which is the state `T236`'s real-database round trip found. Concurrent
        // administration of assignments is guarded by the unique public id and by T082's own rules.
        if ($existing) {
            $qb->update('pettycash_role_assignments')
                ->set('user_uid', $qb->createNamedParameter($roleAssignment->userUid))
                ->set('role', $qb->createNamedParameter($roleAssignment->role->value))
                ->set('cost_center_id', $roleAssignment->costCenterId ? $qb->createNamedParameter($roleAssignment->costCenterId) : $qb->createNamedParameter(null))
                ->set('valid_from', $qb->createNamedParameter($roleAssignment->validFrom->format('Y-m-d')))
                ->set('valid_to', $roleAssignment->validTo ? $qb->createNamedParameter($roleAssignment->validTo->format('Y-m-d')) : $qb->createNamedParameter(null))
                ->set('active', $qb->createNamedParameter($roleAssignment->active, IQueryBuilder::PARAM_BOOL))
                ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE))
                ->set('updated_by', $qb->createNamedParameter($currentUser))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($existing->id)))
                ->executeStatement();
        } else {
            // `public_id` is NOT NULL with no default, and nothing upstream mints one: an assignment is
            // keyed by its user and role, and the surrogate `id` is assigned by the database. This
            // insert did not supply the column at all, so every write failed on the constraint. No
            // test noticed, because all 13 repository doubles accept the aggregate as it stands. The
            // repository mints it here, which is the contract `mapRow()` already reads back.
            $publicId = $roleAssignment->publicId ?? (string) Uuid::random();

            $qb->insert('pettycash_role_assignments')
                ->values([
                    'public_id' => $qb->createNamedParameter($publicId),
                    'user_uid' => $qb->createNamedParameter($roleAssignment->userUid),
                    'role' => $qb->createNamedParameter($roleAssignment->role->value),
                    'cost_center_id' => $roleAssignment->costCenterId ? $qb->createNamedParameter($roleAssignment->costCenterId) : $qb->createNamedParameter(null),
                    'valid_from' => $qb->createNamedParameter($roleAssignment->validFrom->format('Y-m-d')),
                    'valid_to' => $roleAssignment->validTo ? $qb->createNamedParameter($roleAssignment->validTo->format('Y-m-d')) : $qb->createNamedParameter(null),
                    'active' => $qb->createNamedParameter($roleAssignment->active, IQueryBuilder::PARAM_BOOL),
                    'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE),
                    'created_by' => $qb->createNamedParameter($currentUser),
                    'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE),
                    'updated_by' => $qb->createNamedParameter($currentUser),
                ])
                ->executeStatement();

            $roleAssignment->publicId = $publicId;
            $roleAssignment->id = (int) $qb->getLastInsertId();
        }
    }

    private function mapRow(array $row): RoleAssignment
    {
        $roleAssignment = RoleAssignment::create(
            $row['user_uid'],
            Role::from($row['role']),
            $row['cost_center_id'] ? (int) $row['cost_center_id'] : null,
            new DateTimeImmutable($row['valid_from']),
            $row['valid_to'] ? new DateTimeImmutable($row['valid_to']) : null
        );
        $roleAssignment->active = (bool) $row['active'];
        $roleAssignment->createdAt = isset($row['created_at']) ? new DateTimeImmutable($row['created_at']) : null;
        $roleAssignment->createdBy = $row['created_by'] ?? null;
        $roleAssignment->updatedAt = isset($row['updated_at']) ? new DateTimeImmutable($row['updated_at']) : null;
        $roleAssignment->updatedBy = $row['updated_by'] ?? null;
        $roleAssignment->id = (int) $row['id'];
        $roleAssignment->publicId = $row['public_id'] ?? null;
        return $roleAssignment;
    }
}