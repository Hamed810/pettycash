<?php

declare(strict_types=1);
namespace OCA\PettyCash\Db;
use OCP\AppFramework\Db\Entity;
final class Attachment extends Entity {
    protected string $uuid=''; protected int $txnId=0; protected ?int $revisionId=null; protected string $type='RECEIPT'; protected string $storageKey=''; protected string $originalName=''; protected string $mimeType=''; protected int $fileSize=0; protected string $sha256=''; protected string $uploadedBy=''; protected bool $sensitive=false; protected bool $active=true; protected int $createdAt=0;
    public function __construct(){foreach(['txnId','revisionId','fileSize','createdAt'] as $f)$this->addType($f,'integer');foreach(['sensitive','active'] as $f)$this->addType($f,'boolean');}
}
