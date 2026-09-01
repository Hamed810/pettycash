<?php

declare(strict_types=1);

namespace OCA\PettyCash\Db;

use OCP\AppFramework\Db\Entity;

final class Project extends Entity {
    protected string $uuid = '';
    protected string $code = '';
    protected string $name = '';
    protected ?string $description = null;
    protected int $defaultCurrencyId = 0;
    protected bool $active = true;
    protected string $createdBy = '';
    protected int $createdAt = 0;
    protected int $updatedAt = 0;

    public function __construct() {
        $this->addType('defaultCurrencyId', 'integer');
        $this->addType('active', 'boolean');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }
}
