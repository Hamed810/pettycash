<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Configuration;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\PettyCash\Domain\Shared\Enum\Role;

final class RoleAssignment
{
    public function __construct(
        public string $userUid,
        public Role $role,
        public ?int $costCenterId,
        public DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validTo,
        public bool $active = true,
        public ?DateTimeImmutable $createdAt = null,
        public ?string $createdBy = null,
        public ?DateTimeImmutable $updatedAt = null,
        public ?string $updatedBy = null,
        public int $lockVersion = 0,
        public ?int $id = null,
        public ?string $publicId = null
    ) {}

    public static function create(
        string $userUid,
        Role $role,
        ?int $costCenterId,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validTo
    ): self {
        if (trim($userUid) === '') {
            throw new InvalidArgumentException('User UID is required');
        }
        if ($role === Role::CostCenterManager || $role === Role::SeniorManager) {
            if ($costCenterId === null) {
                throw new InvalidArgumentException('Cost center is required for manager roles');
            }
        } else {
            if ($costCenterId !== null) {
                throw new InvalidArgumentException('Cost center must be null for non-manager roles');
            }
        }
        if ($validTo !== null && $validTo < $validFrom) {
            throw new InvalidArgumentException('validTo must not be before validFrom');
        }
        return new self($userUid, $role, $costCenterId, $validFrom, $validTo, true);
    }

    public function isValidOn(DateTimeImmutable $date): bool
    {
        if (!$this->active) {
            return false;
        }
        if ($date < $this->validFrom) {
            return false;
        }
        if ($this->validTo !== null && $date > $this->validTo) {
            return false;
        }
        return true;
    }
}