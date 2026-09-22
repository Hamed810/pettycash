<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Persistence\Configuration;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Configuration\CostCenter;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class DbCostCenterRepository implements CostCenterRepository
{
    public function __construct(
        private readonly IDBConnection $connection,
        private readonly UserDirectory $userDirectory,
        private readonly CurrentUser $currentUser
    ) {}

    public function findById(int $id): ?CostCenter
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pc_cost_centers')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findByCode(string $code): ?CostCenter
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pc_cost_centers')
            ->where($qb->expr()->eq('code', $qb->createNamedParameter($code)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findActiveByCodeOn(string $code, DateTimeImmutable $date): ?CostCenter
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pc_cost_centers')
            ->where($qb->expr()->eq('code', $qb->createNamedParameter($code)))
            ->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->lte('valid_from', $qb->createNamedParameter($date->format('Y-m-d'))))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('valid_to'),
                $qb->expr()->gte('valid_to', $qb->createNamedParameter($date->format('Y-m-d')))
            ))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    /** @return list<CostCenter> */
    public function findAllActiveOn(DateTimeImmutable $date): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pc_cost_centers')
            ->where($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->lte('valid_from', $qb->createNamedParameter($date->format('Y-m-d'))))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('valid_to'),
                $qb->expr()->gte('valid_to', $qb->createNamedParameter($date->format('Y-m-d')))
            ));
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /** @return list<CostCenter> */
    public function findByManagerUid(string $managerUid, DateTimeImmutable $date): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pc_cost_centers')
            ->where($qb->expr()->eq('manager_uid', $qb->createNamedParameter($managerUid)))
            ->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->lte('valid_from', $qb->createNamedParameter($date->format('Y-m-d'))))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('valid_to'),
                $qb->expr()->gte('valid_to', $qb->createNamedParameter($date->format('Y-m-d')))
            ));
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /** @return list<CostCenter> */
    public function findBySeniorManagerUid(string $seniorManagerUid, DateTimeImmutable $date): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pc_cost_centers')
            ->where($qb->expr()->eq('senior_manager_uid', $qb->createNamedParameter($seniorManagerUid)))
            ->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->lte('valid_from', $qb->createNamedParameter($date->format('Y-m-d'))))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('valid_to'),
                $qb->expr()->gte('valid_to', $qb->createNamedParameter($date->format('Y-m-d')))
            ));
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    public function save(CostCenter $costCenter): void
    {
        $qb = $this->connection->getQueryBuilder();
        $existing = $costCenter->id ? $this->findById($costCenter->id) : $this->findByCode($costCenter->code());
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $currentUser = $this->currentUser->uid();

        if ($existing) {
            $qb->update('pc_cost_centers')
                ->set('name', $qb->createNamedParameter($costCenter->name))
                ->set('description', $qb->createNamedParameter($costCenter->description))
                ->set('valid_from', $qb->createNamedParameter($costCenter->validFrom->format('Y-m-d')))
                ->set('valid_to', $costCenter->validTo ? $qb->createNamedParameter($costCenter->validTo->format('Y-m-d')) : $qb->createNamedParameter(null))
                ->set('active', $qb->createNamedParameter($costCenter->active, IQueryBuilder::PARAM_BOOL))
                ->set('manager_uid', $costCenter->managerUid ? $qb->createNamedParameter($costCenter->managerUid) : $qb->createNamedParameter(null))
                ->set('senior_manager_uid', $costCenter->seniorManagerUid ? $qb->createNamedParameter($costCenter->seniorManagerUid) : $qb->createNamedParameter(null))
                ->set('updated_at', $qb->createNamedParameter($now))
                ->set('updated_by', $qb->createNamedParameter($currentUser))
                ->set('lock_version', $qb->createNamedParameter($existing->lockVersion + 1))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($existing->id)))
                ->andWhere($qb->expr()->eq('lock_version', $qb->createNamedParameter($existing->lockVersion)))
                ->executeStatement();
        } else {
            $qb->insert('pc_cost_centers')
                ->values([
                    'code' => $qb->createNamedParameter($costCenter->code()),
                    'name' => $qb->createNamedParameter($costCenter->name),
                    'description' => $qb->createNamedParameter($costCenter->description),
                    'valid_from' => $qb->createNamedParameter($costCenter->validFrom->format('Y-m-d')),
                    'valid_to' => $costCenter->validTo ? $qb->createNamedParameter($costCenter->validTo->format('Y-m-d')) : $qb->createNamedParameter(null),
                    'active' => $qb->createNamedParameter($costCenter->active, IQueryBuilder::PARAM_BOOL),
                    'manager_uid' => $costCenter->managerUid ? $qb->createNamedParameter($costCenter->managerUid) : $qb->createNamedParameter(null),
                    'senior_manager_uid' => $costCenter->seniorManagerUid ? $qb->createNamedParameter($costCenter->seniorManagerUid) : $qb->createNamedParameter(null),
                    'created_at' => $qb->createNamedParameter($now),
                    'created_by' => $qb->createNamedParameter($currentUser),
                    'updated_at' => $qb->createNamedParameter($now),
                    'updated_by' => $qb->createNamedParameter($currentUser),
                    'lock_version' => $qb->createNamedParameter(0),
                ])
                ->executeStatement();
        }
    }

    private function mapRow(array $row): CostCenter
    {
        $costCenter = CostCenter::create(
            $row['code'],
            $row['name'],
            $row['description'] ?? null,
            new DateTimeImmutable($row['valid_from']),
            $row['valid_to'] ? new DateTimeImmutable($row['valid_to']) : null,
            $row['manager_uid'] ?? null,
            $row['senior_manager_uid'] ?? null
        );
        $costCenter->active = (bool) $row['active'];
        $costCenter->createdAt = isset($row['created_at']) ? new DateTimeImmutable($row['created_at']) : null;
        $costCenter->createdBy = $row['created_by'] ?? null;
        $costCenter->updatedAt = isset($row['updated_at']) ? new DateTimeImmutable($row['updated_at']) : null;
        $costCenter->updatedBy = $row['updated_by'] ?? null;
        $costCenter->lockVersion = (int) ($row['lock_version'] ?? 0);
        $costCenter->id = (int) $row['id'];
        $costCenter->publicId = $row['public_id'] ?? null;
        return $costCenter;
    }
}