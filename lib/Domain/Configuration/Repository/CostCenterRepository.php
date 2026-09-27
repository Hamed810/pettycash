<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Configuration\Repository;

use DateTimeImmutable;
use OCA\PettyCash\Domain\Configuration\CostCenter;

/** @extends \IteratorAggregate<int, CostCenter> */
interface CostCenterRepository
{
    public function findById(int $id): ?CostCenter;
    public function findByCode(string $code): ?CostCenter;
    public function findByPublicId(string $publicId): ?CostCenter;
    public function findActiveByCodeOn(string $code, DateTimeImmutable $date): ?CostCenter;
    /** @return list<CostCenter> */
    public function findAllActiveOn(DateTimeImmutable $date): array;
    public function save(CostCenter $costCenter): void;

    /**
     * There is deliberately no "cost centers this user manages" read here.
     *
     * `findByManagerUid()` and `findBySeniorManagerUid()` were declared against a `manager_uid` and
     * `senior_manager_uid` column that no migration creates and no table has, so both would have
     * thrown on first use, and neither had a single caller in `lib/`. A cost center's managers are
     * effective-dated **role assignments**: find them with
     * `RoleAssignmentRepository::findActiveByUserAndRole()` and follow `costCenterId` back here, which
     * is what `ManagementReviewService::getManagedCostCenterCodes()` already does.
     */
}