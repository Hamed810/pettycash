<?php

declare(strict_types=1);
namespace OCA\PettyCash\Db;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
/** @template-extends QBMapper<TransactionRevision> */
final class TransactionRevisionMapper extends QBMapper {
    public function __construct(IDBConnection $db){parent::__construct($db,'pcash_revision',TransactionRevision::class);}
    public function findRevision(int $txnId,int $revisionNumber): TransactionRevision { $qb=$this->db->getQueryBuilder(); $qb->select('*')->from($this->getTableName())->where($qb->expr()->eq('txn_id',$qb->createNamedParameter($txnId,IQueryBuilder::PARAM_INT)))->andWhere($qb->expr()->eq('revision_number',$qb->createNamedParameter($revisionNumber,IQueryBuilder::PARAM_INT))); return $this->findEntity($qb); }
    public function deleteByTransaction(int $txnId): void { foreach ($this->findByTransaction($txnId) as $entity) { $this->delete($entity); } }
    /** @return list<TransactionRevision> */
    public function findByTransaction(int $txnId):array{$qb=$this->db->getQueryBuilder();$qb->select('*')->from($this->getTableName())->where($qb->expr()->eq('txn_id',$qb->createNamedParameter($txnId,IQueryBuilder::PARAM_INT)))->orderBy('revision_number','ASC');return $this->findEntities($qb);}
}
