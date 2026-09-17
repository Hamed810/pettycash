<?php

declare(strict_types=1);

namespace OCA\PettyCash\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<CostList> */
final class CostListMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'pcash_list', CostList::class);
    }


    private function notDeleted($qb): void {
        $qb->andWhere(
            $qb->expr()->eq(
                'deleted',
                $qb->createNamedParameter(
                    false,
                    IQueryBuilder::PARAM_BOOL
                )
            )
        );
    }


    public function find(int $id): CostList {

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'id',
                    $qb->createNamedParameter(
                        $id,
                        IQueryBuilder::PARAM_INT
                    )
                )
            );

        return $this->findEntity($qb);
    }


    public function findByUuid(string $uuid): CostList {

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'uuid',
                    $qb->createNamedParameter($uuid)
                )
            );

        $this->notDeleted($qb);

        return $this->findEntity($qb);
    }


    /**
     * v2.0.0: all open lists for a purchaser, regardless of type.
     * Callers filter by listType themselves (see CostListService) --
     * Business Trip lists are exempt from the single-open-list rule.
     *
     * @return list<CostList>
     */
    public function findOpenForPurchaser(
        string $userId
    ): array {

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'purchaser_id',
                    $qb->createNamedParameter($userId)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'status',
                    $qb->createNamedParameter('OPEN')
                )
            );

        $this->notDeleted($qb);

        return $this->findEntities($qb);
    }


    /**
     * @return list<CostList>
     */
    public function findForPurchaser(
        string $userId
    ): array {

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'purchaser_id',
                    $qb->createNamedParameter($userId)
                )
            )
            ->orderBy('created_at','DESC');

        $this->notDeleted($qb);

        return $this->findEntities($qb);
    }


    /**
     * @return list<CostList>
     */
    public function findByStatus(
        string $status
    ): array {

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'status',
                    $qb->createNamedParameter($status)
                )
            )
            ->orderBy('submitted_at','ASC')
            ->addOrderBy('id','ASC');

        $this->notDeleted($qb);

        return $this->findEntities($qb);
    }


    /**
     * v2.0.0: admin-wide visibility. Replaces the old
     * iterate-every-project-then-union approach, since lists are no
     * longer project-scoped.
     *
     * @return list<CostList>
     */
    public function findAll(): array {

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('created_at','DESC');

        $this->notDeleted($qb);

        return $this->findEntities($qb);
    }
}
