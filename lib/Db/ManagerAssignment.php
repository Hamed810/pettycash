<?php

declare(strict_types=1);
namespace OCA\PettyCash\Db;
use OCP\AppFramework\Db\Entity;

/**
 * Maps a purchaser to their Manager 1 (direct manager), project-independent.
 * Append-only: reassignment closes the active row (sets validTo) and
 * inserts a new one. Never updated in place.
 */
final class ManagerAssignment extends Entity {
    protected string $purchaserId = '';
    protected string $managerId = '';
    protected int $validFrom = 0;
    protected ?int $validTo = null;
    protected int $createdAt = 0;

    public function __construct() {
        foreach (['validFrom', 'validTo', 'createdAt'] as $f) {
            $this->addType($f, 'integer');
        }
    }
}
