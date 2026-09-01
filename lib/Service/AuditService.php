<?php

declare(strict_types=1);
namespace OCA\PettyCash\Service;
use OCP\IDBConnection;
final class AuditService {
    public function __construct(private IDBConnection $db) {}
    /** @param array<string,mixed> $metadata */
    public function record(string $entityType,int $entityId,string $action,string $actorId,array $metadata=[]):void{$qb=$this->db->getQueryBuilder();$qb->insert('pcash_audit')->values(['entity_type'=>$qb->createNamedParameter($entityType),'entity_id'=>$qb->createNamedParameter($entityId),'action'=>$qb->createNamedParameter($action),'actor_id'=>$qb->createNamedParameter($actorId),'metadata'=>$qb->createNamedParameter($metadata===[]?null:json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),'created_at'=>$qb->createNamedParameter(time())])->executeStatement();}
}
