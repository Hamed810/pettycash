<?php

declare(strict_types=1);
namespace OCA\PettyCash\Db;
use OCP\AppFramework\Db\Entity;
final class Transaction extends Entity {
    protected string $uuid=''; protected int $listId=0; protected int $categoryId=0; protected string $purchaserId=''; protected int $currencyId=0; protected int $amountMinor=0; protected string $purchaseDate=''; protected string $description=''; protected ?string $vendor=null; protected ?int $vehicleId=null; protected ?int $odometerKm=null; protected ?string $workerName=null; protected ?string $workerReference=null; protected ?int $workDays=null; protected ?int $workMinutes=null; protected ?string $workDescription=null; protected string $status='DRAFT'; protected int $currentRevision=1; protected int $createdAt=0; protected int $updatedAt=0; protected int $version=1;
    public function __construct(){foreach(['listId','categoryId','currencyId','amountMinor','vehicleId','odometerKm','workDays','workMinutes','currentRevision','createdAt','updatedAt','version'] as $f)$this->addType($f,'integer');}
}
