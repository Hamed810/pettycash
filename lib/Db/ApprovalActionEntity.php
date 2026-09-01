<?php

declare(strict_types=1);
namespace OCA\PettyCash\Db;
use OCP\AppFramework\Db\Entity;
final class ApprovalActionEntity extends Entity { protected int $txnId=0; protected ?int $revisionId=null; protected string $stage=''; protected string $action=''; protected string $actorId=''; protected ?string $comment=null; protected int $createdAt=0; public function __construct(){foreach(['txnId','revisionId','createdAt'] as $f)$this->addType($f,'integer');} }
