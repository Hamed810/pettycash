<?php

declare(strict_types=1);
namespace OCA\PettyCash\Db;
use OCP\AppFramework\Db\Entity;

/**
 * Maps a destination (pcash_project row, of any DestinationType) to its
 * Manager 2 / budget owner, purchaser-independent. Append-only, same
 * pattern as ManagerAssignment.
 */
final class DestinationOwner extends Entity {
    protected int $destinationId = 0;
    protected string $ownerId = '';
    protected int $validFrom = 0;
    protected ?int $validTo = null;
    protected int $createdAt = 0;

    public function __construct() {
        foreach (['destinationId', 'validFrom', 'validTo', 'createdAt'] as $f) {
            $this->addType($f, 'integer');
        }
    }
}
