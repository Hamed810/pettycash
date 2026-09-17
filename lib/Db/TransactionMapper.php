<?php

declare(strict_types=1);

namespace OCA\PettyCash\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Transaction> */
final class TransactionMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'pcash_txn', Transaction::class);
    }


    public function find(int $id): Transaction {

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


    public function findByUuid(string $uuid): Transaction {

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'uuid',
                    $qb->createNamedParameter($uuid)
                )
            );

        return $this->findEntity($qb);
    }


    /** @return list<Transaction> */
    public function findByList(int $listId): array {

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'list_id',
                    $qb->createNamedParameter(
                        $listId,
                        IQueryBuilder::PARAM_INT
                    )
                )
            )
            ->orderBy('purchase_date','DESC')
            ->addOrderBy('id','DESC');

        return $this->findEntities($qb);
    }


    public function sumByList(int $listId): int {

        $qb = $this->db->getQueryBuilder();

        $qb->selectAlias(
                $qb->func()->sum('amount_minor'),
                'total'
            )
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'list_id',
                    $qb->createNamedParameter(
                        $listId,
                        IQueryBuilder::PARAM_INT
                    )
                )
            );

        $value = $qb->executeQuery()->fetchOne();

        return $value === false || $value === null
            ? 0
            : (int)$value;
    }


    /** @param list<string> $statuses */
    public function sumByListStatuses(
        int $listId,
        array $statuses
    ): int {

        if ($statuses === []) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();

        $qb->selectAlias(
                $qb->func()->sum('amount_minor'),
                'total'
            )
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'list_id',
                    $qb->createNamedParameter(
                        $listId,
                        IQueryBuilder::PARAM_INT
                    )
                )
            )
            ->andWhere(
                $qb->expr()->in(
                    'status',
                    $qb->createNamedParameter(
                        $statuses,
                        IQueryBuilder::PARAM_STR_ARRAY
                    )
                )
            );

        $value = $qb->executeQuery()->fetchOne();

        return $value === false || $value === null
            ? 0
            : (int)$value;
    }


    /** @param list<string> $statuses */
    public function countByListStatuses(
        int $listId,
        array $statuses
    ): int {

        if ($statuses === []) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();

        $qb->select(
                $qb->func()->count('*')
            )
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'list_id',
                    $qb->createNamedParameter(
                        $listId,
                        IQueryBuilder::PARAM_INT
                    )
                )
            )
            ->andWhere(
                $qb->expr()->in(
                    'status',
                    $qb->createNamedParameter(
                        $statuses,
                        IQueryBuilder::PARAM_STR_ARRAY
                    )
                )
            );

        return (int)$qb->executeQuery()->fetchOne();
    }


    public function latestAcceptedOdometer(
        int $vehicleId,
        ?int $excludeTxnId = null
    ): ?int {

        $qb = $this->db->getQueryBuilder();

        $qb->select('odometer_km')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'vehicle_id',
                    $qb->createNamedParameter(
                        $vehicleId,
                        IQueryBuilder::PARAM_INT
                    )
                )
            )
            ->andWhere(
                $qb->expr()->isNotNull('odometer_km')
            )
            ->orderBy('purchase_date','DESC')
            ->addOrderBy('id','DESC')
            ->setMaxResults(1);


        if ($excludeTxnId !== null) {

            $qb->andWhere(
                $qb->expr()->neq(
                    'id',
                    $qb->createNamedParameter(
                        $excludeTxnId,
                        IQueryBuilder::PARAM_INT
                    )
                )
            );
        }


        $value = $qb->executeQuery()->fetchOne();

        return $value === false || $value === null
            ? null
            : (int)$value;
    }


    public function findPendingForManager1(
        string $managerId
    ): array {

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'status',
                    $qb->createNamedParameter(
                        'PENDING_M1'
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'manager1_id',
                    $qb->createNamedParameter($managerId)
                )
            )
            ->orderBy('purchase_date','ASC')
            ->addOrderBy('id','ASC');

        return $this->findEntities($qb);
    }


    public function findPendingForManager2(
        string $managerId
    ): array {

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'status',
                    $qb->createNamedParameter(
                        'PENDING_M2'
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'manager2_id',
                    $qb->createNamedParameter($managerId)
                )
            )
            ->orderBy('purchase_date','ASC')
            ->addOrderBy('id','ASC');

        return $this->findEntities($qb);
    }


    /**
     * Accountant queue.
     *
     * Accountant is project-level responsibility,
     * therefore it is resolved through project membership.
     *
     * @return list<Transaction>
     */
    public function findPendingForAccountant(
        string $userId
    ): array {

        $qb = $this->db->getQueryBuilder();

        $qb->select('txn.*')
            ->from($this->getTableName(), 'txn')
            ->innerJoin(
                'txn',
                'pcash_list',
                'list',
                $qb->expr()->eq(
                    'txn.list_id',
                    'list.id'
                )
            )
            ->innerJoin(
                'list',
                'pcash_member',
                'member',
                $qb->expr()->eq(
                    'list.project_id',
                    'member.project_id'
                )
            )
            ->where(
                $qb->expr()->eq(
                    'txn.status',
                    $qb->createNamedParameter(
                        'PENDING_ACCOUNTANT'
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'member.user_id',
                    $qb->createNamedParameter($userId)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'member.role',
                    $qb->createNamedParameter(
                        'ACCOUNTANT'
                    )
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'member.active',
                    $qb->createNamedParameter(
                        true,
                        IQueryBuilder::PARAM_BOOL
                    )
                )
            )
            ->orderBy('txn.purchase_date','ASC')
            ->addOrderBy('txn.id','ASC');


        return $this->findEntities($qb);
    }


    /** @return list<Transaction> */
    public function findAllPendingByStatus(
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
            ->orderBy('purchase_date','ASC')
            ->addOrderBy('id','ASC');


        return $this->findEntities($qb);
    }
}