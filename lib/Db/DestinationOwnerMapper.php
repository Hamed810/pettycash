<?php

declare(strict_types=1);

namespace OCA\PettyCash\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<DestinationOwner> */
final class DestinationOwnerMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'pcash_destination_owner', DestinationOwner::class);
    }

    /** The currently active owner for a destination, if any. */
    public function findActiveForDestination(int $destinationId): ?DestinationOwner {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('destination_id', $qb->createNamedParameter($destinationId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('valid_to'))
            ->setMaxResults(1);
        $rows = $this->findEntities($qb);
        return $rows[0] ?? null;
    }

    /** @return list<DestinationOwner> Full history, most recent first. */
    public function findHistoryForDestination(int $destinationId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('destination_id', $qb->createNamedParameter($destinationId, IQueryBuilder::PARAM_INT)))
            ->orderBy('valid_from', 'DESC');
        return $this->findEntities($qb);
    }

    /** Closes the active owner assignment (if any) as of $closedAt. */
    public function closeActive(int $destinationId, int $closedAt): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('valid_to', $qb->createNamedParameter($closedAt, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('destination_id', $qb->createNamedParameter($destinationId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('valid_to'))
            ->executeStatement();
    }
}
