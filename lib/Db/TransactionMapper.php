<?php

declare(strict_types=1);
namespace OCA\PettyCash\Db;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
/** @template-extends QBMapper<Transaction> */
final class TransactionMapper extends QBMapper {
    public function __construct(IDBConnection $db){parent::__construct($db,'pcash_txn',Transaction::class);}
    public function findByUuid(string $uuid):Transaction{$qb=$this->db->getQueryBuilder();$qb->select('*')->from($this->getTableName())->where($qb->expr()->eq('uuid',$qb->createNamedParameter($uuid)));return $this->findEntity($qb);}
    /** @return list<Transaction> */
    public function findByList(int $listId):array{$qb=$this->db->getQueryBuilder();$qb->select('*')->from($this->getTableName())->where($qb->expr()->eq('list_id',$qb->createNamedParameter($listId,IQueryBuilder::PARAM_INT)))->orderBy('purchase_date','DESC')->addOrderBy('id','DESC');return $this->findEntities($qb);}
    public function sumByList(int $listId):int{$qb=$this->db->getQueryBuilder();$qb->selectAlias($qb->func()->sum('amount_minor'),'total')->from($this->getTableName())->where($qb->expr()->eq('list_id',$qb->createNamedParameter($listId,IQueryBuilder::PARAM_INT)));$value=$qb->executeQuery()->fetchOne();return $value===false||$value===null?0:(int)$value;}

    /** @param list<string> $statuses */
    public function sumByListStatuses(int $listId,array $statuses):int{if($statuses===[])return 0;$qb=$this->db->getQueryBuilder();$qb->selectAlias($qb->func()->sum('amount_minor'),'total')->from($this->getTableName())->where($qb->expr()->eq('list_id',$qb->createNamedParameter($listId,IQueryBuilder::PARAM_INT)))->andWhere($qb->expr()->in('status',$qb->createNamedParameter($statuses,IQueryBuilder::PARAM_STR_ARRAY)));$value=$qb->executeQuery()->fetchOne();return $value===false||$value===null?0:(int)$value;}
    /** @param list<string> $statuses */
    public function countByListStatuses(int $listId,array $statuses):int{if($statuses===[])return 0;$qb=$this->db->getQueryBuilder();$qb->select($qb->func()->count('*'))->from($this->getTableName())->where($qb->expr()->eq('list_id',$qb->createNamedParameter($listId,IQueryBuilder::PARAM_INT)))->andWhere($qb->expr()->in('status',$qb->createNamedParameter($statuses,IQueryBuilder::PARAM_STR_ARRAY)));return (int)$qb->executeQuery()->fetchOne();}
    public function latestAcceptedOdometer(int $vehicleId,?int $excludeTxnId=null):?int{$qb=$this->db->getQueryBuilder();$qb->select('odometer_km')->from($this->getTableName())->where($qb->expr()->eq('vehicle_id',$qb->createNamedParameter($vehicleId,IQueryBuilder::PARAM_INT)))->andWhere($qb->expr()->isNotNull('odometer_km'))->orderBy('purchase_date','DESC')->addOrderBy('id','DESC')->setMaxResults(1);if($excludeTxnId!==null)$qb->andWhere($qb->expr()->neq('id',$qb->createNamedParameter($excludeTxnId,IQueryBuilder::PARAM_INT)));$value=$qb->executeQuery()->fetchOne();return $value===false||$value===null?null:(int)$value;}
}
