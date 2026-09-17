<?php

declare(strict_types=1);

namespace OCA\PettyCash\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Decision> */
final class DecisionMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'pcash_decision', Decision::class);
    }

    /** @return list<Decision> All decisions for a transaction, oldest first. */
    public function findByTransaction(int $txnId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('txn_id', $qb->createNamedParameter($txnId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC')->addOrderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Most recent decision for a given role on a transaction, scoped to
     * a specific revision. A manager edit creates a new revision, and
     * decisions from a prior (now-corrected) revision must not count
     * toward the new revision's effective status.
     */
    public function findLatestForRole(int $txnId, string $role, ?int $revisionId = null): ?Decision {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('txn_id', $qb->createNamedParameter($txnId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('role', $qb->createNamedParameter($role)));

        if ($revisionId !== null) {
            $qb->andWhere($qb->expr()->eq('revision_id', $qb->createNamedParameter($revisionId, IQueryBuilder::PARAM_INT)));
        }

        $qb->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC')->setMaxResults(1);
        $rows = $this->findEntities($qb);
        return $rows[0] ?? null;
    }
}
