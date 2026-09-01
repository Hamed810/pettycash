<?php

declare(strict_types=1);
namespace OCA\PettyCash\Db;
use OCP\AppFramework\Db\Entity;
final class TransactionRevision extends Entity {
    protected int $txnId=0; protected int $revisionNumber=1; protected int $categoryId=0; protected int $currencyId=0; protected int $amountMinor=0; protected string $purchaseDate=''; protected string $description=''; protected ?string $vendor=null; protected ?int $vehicleId=null; protected ?int $odometerKm=null; protected ?string $workerName=null; protected ?string $workerReference=null; protected ?int $workDays=null; protected ?int $workMinutes=null; protected ?string $workDescription=null; protected string $changedBy=''; protected ?string $changeReason=null; protected int $createdAt=0;
    public function __construct(){foreach(['txnId','revisionNumber','categoryId','currencyId','amountMinor','vehicleId','odometerKm','workDays','workMinutes','createdAt'] as $f)$this->addType($f,'integer');}
}
