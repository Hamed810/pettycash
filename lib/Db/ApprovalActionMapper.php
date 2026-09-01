<?php

declare(strict_types=1);
namespace OCA\PettyCash\Db;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
/** @template-extends QBMapper<ApprovalActionEntity> */
final class ApprovalActionMapper extends QBMapper {
 public function __construct(IDBConnection $db){parent::__construct($db,'pcash_action',ApprovalActionEntity::class);}
 /** @return list<ApprovalActionEntity> */
 public function findByTransaction(int $txnId):array{$qb=$this->db->getQueryBuilder();$qb->select('*')->from($this->getTableName())->where($qb->expr()->eq('txn_id',$qb->createNamedParameter($txnId,IQueryBuilder::PARAM_INT)))->orderBy('created_at','ASC')->addOrderBy('id','ASC');return $this->findEntities($qb);}
}
