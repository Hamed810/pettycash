<?php

declare(strict_types=1);

namespace OCA\PettyCash\Db;

use OCP\AppFramework\Db\Entity;

final class ExpenseCategory extends Entity {
    protected string $code = '';
    protected string $name = '';
    protected ?string $description = null;
    protected bool $receiptRequired = true;
    protected bool $vehicleRequired = false;
    protected bool $odometerRequired = false;
    protected bool $workerRequired = false;
    protected bool $permitRequired = false;
    protected bool $attendanceRequired = false;
    protected bool $active = true;
    protected int $sortOrder = 0;
    protected int $createdAt = 0;
    protected int $updatedAt = 0;

    public function __construct() {
        foreach (['receiptRequired','vehicleRequired','odometerRequired','workerRequired','permitRequired','attendanceRequired','active'] as $field) {
            $this->addType($field, 'boolean');
        }
        $this->addType('sortOrder', 'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }
}
