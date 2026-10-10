<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Configuration;

use DateTimeImmutable;
use InvalidArgumentException;

final class CostCenter
{
    /**
     * A cost center carries no manager.
     *
     * `data-model.md` gives `CostCenter` exactly `id`, `public_id`, `code`, `name`, `description`,
     * the validity window, `active`, the audit columns and `lock_version` — and states the
     * relationship as "Has effective-dated cost-center-manager and senior-manager **role
     * assignments**". So the managers are rows in `pettycash_role_assignments` scoped by
     * `cost_center_id`, which is also how `ManagementReviewService::getManagedCostCenterCodes()`
     * resolves a reviewer's scope.
     *
     * `managerUid` and `seniorManagerUid` used to sit here, and `DbCostCenterRepository` wrote them
     * to columns that exist in no migration and in no table — so every cost-center write failed with
     * "Unknown column 'manager_uid'" and every `findByManagerUid()` read would have failed the same
     * way. Nothing caught it because all 13 test doubles accept the aggregate as it stands. Holding
     * the field while nothing persists it is the worse version of the same defect: `T082` would
     * create a cost center, pass manager uids, and watch them disappear.
     */
    public function __construct(
        private string $code,
        public string $name,
        public ?string $description,
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
        string $code,
        string $name,
        ?string $description,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validTo
    ): self {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $code)) {
            throw new InvalidArgumentException('Invalid cost center code');
        }
        if (trim($name) === '') {
            throw new InvalidArgumentException('Cost center name is required');
        }
        if ($validTo !== null && $validTo < $validFrom) {
            throw new InvalidArgumentException('validTo must not be before validFrom');
        }
        return new self($code, $name, $description, $validFrom, $validTo);
    }

    public function code(): string
    {
        return $this->code;
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