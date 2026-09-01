<?php

declare(strict_types=1);

namespace OCA\PettyCash\Db;

use OCP\AppFramework\Db\Entity;

final class Currency extends Entity {
    protected string $code = '';
    protected string $name = '';
    protected ?string $symbol = null;
    protected int $decimalPlaces = 0;
    protected bool $isDefault = false;
    protected bool $active = true;
    protected int $createdAt = 0;
    protected int $updatedAt = 0;

    public function __construct() {
        $this->addType('decimalPlaces', 'integer');
        $this->addType('isDefault', 'boolean');
        $this->addType('active', 'boolean');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }
}
