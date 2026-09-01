<?php

declare(strict_types=1);
namespace OCA\PettyCash\Db;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
/** @template-extends QBMapper<CostList> */
final class CostListMapper extends QBMapper {
    public function __construct(IDBConnection $db){parent::__construct($db,'pcash_list',CostList::class);}
    public function findByUuid(string $uuid):CostList{$qb=$this->db->getQueryBuilder();$qb->select('*')->from($this->getTableName())->where($qb->expr()->eq('uuid',$qb->createNamedParameter($uuid)));return $this->findEntity($qb);}
    public function findOpenForPurchaserProject(string $userId,int $projectId):CostList{$qb=$this->db->getQueryBuilder();$qb->select('*')->from($this->getTableName())->where($qb->expr()->eq('purchaser_id',$qb->createNamedParameter($userId)))->andWhere($qb->expr()->eq('project_id',$qb->createNamedParameter($projectId,IQueryBuilder::PARAM_INT)))->andWhere($qb->expr()->eq('status',$qb->createNamedParameter('OPEN')));return $this->findEntity($qb);}
    /** @return list<CostList> */
    public function findForPurchaser(string $userId):array{$qb=$this->db->getQueryBuilder();$qb->select('*')->from($this->getTableName())->where($qb->expr()->eq('purchaser_id',$qb->createNamedParameter($userId)))->orderBy('created_at','DESC');return $this->findEntities($qb);}

    /** @return list<CostList> */
    public function findByStatus(string $status):array{$qb=$this->db->getQueryBuilder();$qb->select('*')->from($this->getTableName())->where($qb->expr()->eq('status',$qb->createNamedParameter($status)))->orderBy('submitted_at','ASC')->addOrderBy('id','ASC');return $this->findEntities($qb);}
    /** @return list<CostList> */
    public function findForReviewer(string $userId,string $role,string $status):array{$qb=$this->db->getQueryBuilder();$qb->selectDistinct('l.*')->from($this->getTableName(),'l')->innerJoin('l','pcash_member','m',$qb->expr()->eq('m.project_id','l.project_id'))->where($qb->expr()->eq('m.user_id',$qb->createNamedParameter($userId)))->andWhere($qb->expr()->eq('m.role',$qb->createNamedParameter($role)))->andWhere($qb->expr()->eq('m.active',$qb->createNamedParameter(true,IQueryBuilder::PARAM_BOOL)))->andWhere($qb->expr()->eq('l.status',$qb->createNamedParameter($status)))->orderBy('l.submitted_at','ASC')->addOrderBy('l.id','ASC');return $this->findEntities($qb);}
    /** @return list<CostList> */
    public function findForProject(int $projectId):array{$qb=$this->db->getQueryBuilder();$qb->select('*')->from($this->getTableName())->where($qb->expr()->eq('project_id',$qb->createNamedParameter($projectId,IQueryBuilder::PARAM_INT)))->orderBy('created_at','DESC');return $this->findEntities($qb);}
}
