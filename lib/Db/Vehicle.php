<?php

declare(strict_types=1);

namespace OCA\PettyCash\Db;

use OCP\AppFramework\Db\Entity;

final class Vehicle extends Entity {
    protected string $uuid = '';
    protected int $projectId = 0;
    protected string $name = '';
    protected string $plateNumber = '';
    protected ?string $vehicleType = null;
    protected ?string $notes = null;
    protected bool $active = true;
    protected int $createdAt = 0;
    protected int $updatedAt = 0;

    public function __construct() {
        $this->addType('projectId', 'integer');
        $this->addType('active', 'boolean');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }
}
