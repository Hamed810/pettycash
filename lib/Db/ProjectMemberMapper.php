<?php

declare(strict_types=1);

namespace OCA\PettyCash\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<ProjectMember> */
final class ProjectMemberMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'pcash_member', ProjectMember::class);
    }

    /** @return list<ProjectMember> */
    public function findByProject(int $projectId, bool $activeOnly = true): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->orderBy('role', 'ASC')->addOrderBy('user_id', 'ASC');
        if ($activeOnly) {
            $qb->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        }
        return $this->findEntities($qb);
    }

    /** @return list<ProjectMember> */
    public function findForUser(int $projectId, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        return $this->findEntities($qb);
    }

    public function deactivateProjectMembers(int $projectId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('active', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }
}
