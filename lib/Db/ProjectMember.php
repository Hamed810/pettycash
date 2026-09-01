<?php

declare(strict_types=1);

namespace OCA\PettyCash\Db;

use OCP\AppFramework\Db\Entity;

final class ProjectMember extends Entity {
    protected int $projectId = 0;
    protected string $userId = '';
    protected string $role = '';
    protected bool $active = true;
    protected int $createdAt = 0;

    public function __construct() {
        $this->addType('projectId', 'integer');
        $this->addType('active', 'boolean');
        $this->addType('createdAt', 'integer');
    }
}
