<?php

declare(strict_types=1);

namespace OCA\PettyCash\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Project> */
final class ProjectMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'pcash_project', Project::class);
    }

    /** @return list<Project> */
    public function findAll(bool $includeInactive = true): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())->orderBy('name', 'ASC');
        if (!$includeInactive) {
            $qb->where($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        }
        return $this->findEntities($qb);
    }

    public function findByUuid(string $uuid): Project {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));
        return $this->findEntity($qb);
    }

    public function findByCode(string $code): Project {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('code', $qb->createNamedParameter($code)));
        return $this->findEntity($qb);
    }

    /** @return list<Project> */
    public function findForUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('p.*')
            ->from($this->getTableName(), 'p')
            ->innerJoin('p', 'pcash_member', 'm', $qb->expr()->eq('m.project_id', 'p.id'))
            ->where($qb->expr()->eq('m.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('m.active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->eq('p.active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->orderBy('p.name', 'ASC');
        return $this->findEntities($qb);
    }
}
