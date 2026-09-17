<?php

declare(strict_types=1);

namespace OCA\PettyCash\Service;

use OCA\PettyCash\Db\ManagerAssignment;
use OCA\PettyCash\Db\ManagerAssignmentMapper;
use OCA\PettyCash\Db\DestinationOwner;
use OCA\PettyCash\Db\DestinationOwnerMapper;
use OCA\PettyCash\Domain\Exception\ValidationException;
use OCP\IUserManager;

/**
 * Manages who a purchaser's Manager 1 is, and who a destination's
 * Manager 2 (owner) is. Both relationships are append-only: assigning
 * a new manager/owner closes the previous active assignment rather
 * than overwriting it, so history is preserved.
 */
final class AssignmentService {
    public function __construct(
        private ManagerAssignmentMapper $managerMapper,
        private DestinationOwnerMapper $ownerMapper,
        private IUserManager $userManager,
        private AuditService $audit,
    ) {}

    /**
     * Assign (or reassign) a purchaser's direct manager (Manager 1).
     * @return array<string,mixed>
     */
    public function assignManager(string $purchaserId, string $managerId, string $actorId): array {
        if (!$this->userManager->userExists($purchaserId)) {
            throw new ValidationException("Nextcloud user '{$purchaserId}' does not exist.");
        }
        if (!$this->userManager->userExists($managerId)) {
            throw new ValidationException("Nextcloud user '{$managerId}' does not exist.");
        }
        if ($purchaserId === $managerId) {
            throw new ValidationException('A purchaser cannot be their own manager.');
        }

        $now = time();
        $this->managerMapper->closeActive($purchaserId, $now);

        $a = new ManagerAssignment();
        $a->setPurchaserId($purchaserId);
        $a->setManagerId($managerId);
        $a->setValidFrom($now);
        $a->setValidTo(null);
        $a->setCreatedAt($now);
        $a = $this->managerMapper->insert($a);

        $this->audit->record('MANAGER_ASSIGNMENT', (int)$a->getId(), 'MANAGER_ASSIGNED', $actorId, [
            'purchaserId' => $purchaserId,
            'managerId' => $managerId,
        ]);

        return $this->serializeManagerAssignment($a);
    }

    /** Currently active Manager 1 user ID for a purchaser, or null if unassigned. */
    public function resolveManagerFor(string $purchaserId): ?string {
        return $this->managerMapper->findActiveForPurchaser($purchaserId)?->getManagerId();
    }

    /**
     * Assign (or reassign) a destination's budget owner (Manager 2).
     * @return array<string,mixed>
     */
    public function assignDestinationOwner(int $destinationId, string $ownerId, string $actorId): array {
        if (!$this->userManager->userExists($ownerId)) {
            throw new ValidationException("Nextcloud user '{$ownerId}' does not exist.");
        }

        $now = time();
        $this->ownerMapper->closeActive($destinationId, $now);

        $o = new DestinationOwner();
        $o->setDestinationId($destinationId);
        $o->setOwnerId($ownerId);
        $o->setValidFrom($now);
        $o->setValidTo(null);
        $o->setCreatedAt($now);
        $o = $this->ownerMapper->insert($o);

        $this->audit->record('DESTINATION_OWNER', (int)$o->getId(), 'OWNER_ASSIGNED', $actorId, [
            'destinationId' => $destinationId,
            'ownerId' => $ownerId,
        ]);

        return $this->serializeDestinationOwner($o);
    }

    /** Currently active Manager 2 / owner user ID for a destination, or null if unassigned. */
    public function resolveOwnerFor(int $destinationId): ?string {
        return $this->ownerMapper->findActiveForDestination($destinationId)?->getOwnerId();
    }

    /**
     * Resolves both routing legs for a transaction at submission time.
     * Manager 1 is skipped (returned as null) for Business Trip lists
     * whose destination is non-project, per the routing rule.
     *
     * @return array{manager1Id:?string,manager2Id:?string}
     */
    public function resolveRouting(string $purchaserId, int $destinationId, bool $skipManager1): array {
        return [
            'manager1Id' => $skipManager1 ? null : $this->resolveManagerFor($purchaserId),
            'manager2Id' => $this->resolveOwnerFor($destinationId),
        ];
    }

    /** @return array<string,mixed> */
    private function serializeManagerAssignment(ManagerAssignment $a): array {
        return [
            'id' => $a->getId(),
            'purchaserId' => $a->getPurchaserId(),
            'managerId' => $a->getManagerId(),
            'validFrom' => $a->getValidFrom(),
            'validTo' => $a->getValidTo(),
        ];
    }

    /** @return array<string,mixed> */
    private function serializeDestinationOwner(DestinationOwner $o): array {
        return [
            'id' => $o->getId(),
            'destinationId' => $o->getDestinationId(),
            'ownerId' => $o->getOwnerId(),
            'validFrom' => $o->getValidFrom(),
            'validTo' => $o->getValidTo(),
        ];
    }
}
