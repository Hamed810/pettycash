<?php

declare(strict_types=1);
namespace OCA\PettyCash\Db;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
/** @template-extends QBMapper<Attachment> */
final class AttachmentMapper extends QBMapper {
    public function __construct(IDBConnection $db){parent::__construct($db,'pcash_attach',Attachment::class);}
    public function findByUuid(string $uuid):Attachment{$qb=$this->db->getQueryBuilder();$qb->select('*')->from($this->getTableName())->where($qb->expr()->eq('uuid',$qb->createNamedParameter($uuid)));return $this->findEntity($qb);}
    /** @return list<Attachment> */
    public function findByTransaction(int $txnId,bool $activeOnly=true):array{$qb=$this->db->getQueryBuilder();$qb->select('*')->from($this->getTableName())->where($qb->expr()->eq('txn_id',$qb->createNamedParameter($txnId,IQueryBuilder::PARAM_INT)))->orderBy('created_at','ASC');if($activeOnly)$qb->andWhere($qb->expr()->eq('active',$qb->createNamedParameter(true,IQueryBuilder::PARAM_BOOL)));return $this->findEntities($qb);}
    public function countType(int $txnId,string $type):int{$qb=$this->db->getQueryBuilder();$qb->select($qb->func()->count('*'))->from($this->getTableName())->where($qb->expr()->eq('txn_id',$qb->createNamedParameter($txnId,IQueryBuilder::PARAM_INT)))->andWhere($qb->expr()->eq('type',$qb->createNamedParameter($type)))->andWhere($qb->expr()->eq('active',$qb->createNamedParameter(true,IQueryBuilder::PARAM_BOOL)));return (int)$qb->executeQuery()->fetchOne();}
}
