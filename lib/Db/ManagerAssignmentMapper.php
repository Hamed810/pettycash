<?php

declare(strict_types=1);

namespace OCA\PettyCash\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<ManagerAssignment> */
final class ManagerAssignmentMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'pcash_manager_assignment', ManagerAssignment::class);
    }

    /** The currently active assignment for a purchaser, if any. */
    public function findActiveForPurchaser(string $purchaserId): ?ManagerAssignment {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('purchaser_id', $qb->createNamedParameter($purchaserId)))
            ->andWhere($qb->expr()->isNull('valid_to'))
            ->setMaxResults(1);
        $rows = $this->findEntities($qb);
        return $rows[0] ?? null;
    }

    /** @return list<ManagerAssignment> Full history, most recent first. */
    public function findHistoryForPurchaser(string $purchaserId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('purchaser_id', $qb->createNamedParameter($purchaserId)))
            ->orderBy('valid_from', 'DESC');
        return $this->findEntities($qb);
    }

    /** Closes the active assignment (if any) as of $closedAt. */
    public function closeActive(string $purchaserId, int $closedAt): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('valid_to', $qb->createNamedParameter($closedAt, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('purchaser_id', $qb->createNamedParameter($purchaserId)))
            ->andWhere($qb->expr()->isNull('valid_to'))
            ->executeStatement();
    }
}
