<?php

declare(strict_types=1);
namespace OCA\PettyCash\Db;
use OCP\AppFramework\Db\Entity;
final class CostList extends Entity {
    protected string $uuid=''; protected ?string $reference=null; protected int $projectId=0; protected string $purchaserId=''; protected int $currencyId=0; protected int $jalaliYear=0; protected int $jalaliMonth=0; protected string $status='OPEN'; protected int $submittedTotal=0; protected int $manager1Total=0; protected int $finalTotal=0; protected int $createdAt=0; protected ?int $submittedAt=null; protected ?int $manager1CompletedAt=null; protected ?int $manager2CompletedAt=null; protected ?int $processedAt=null; protected int $version=1;
    public function __construct(){foreach(['projectId','currencyId','jalaliYear','jalaliMonth','submittedTotal','manager1Total','finalTotal','createdAt','submittedAt','manager1CompletedAt','manager2CompletedAt','processedAt','version'] as $f)$this->addType($f,'integer');}
}
