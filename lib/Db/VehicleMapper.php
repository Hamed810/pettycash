<?php

declare(strict_types=1);

namespace OCA\PettyCash\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\AppFramework\Db\DoesNotExistException;

/** @template-extends QBMapper<Vehicle> */
final class VehicleMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'pcash_vehicle', Vehicle::class);
    }

    public function find(int $id): Vehicle {
    $qb = $this->db->getQueryBuilder();

    $qb->select('*')
        ->from('pcash_vehicle')
        ->where(
            $qb->expr()->eq(
                'id',
                $qb->createNamedParameter($id)
            )
        );

    return $this->findEntity($qb);
    }

    /** @return list<Vehicle> */
    public function findByProject(int $projectId, bool $includeInactive = true): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->orderBy('name', 'ASC');
        if (!$includeInactive) {
            $qb->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        }
        return $this->findEntities($qb);
    }

    public function findByUuid(string $uuid): Vehicle {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));
        return $this->findEntity($qb);
    }
}
