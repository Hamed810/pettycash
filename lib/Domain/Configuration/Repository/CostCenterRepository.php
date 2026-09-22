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
    public function findActiveByCodeOn(string $code, DateTimeImmutable $date): ?CostCenter;
    /** @return list<CostCenter> */
    public function findAllActiveOn(DateTimeImmutable $date): array;
    /** @return list<CostCenter> */
    public function findByManagerUid(string $managerUid, DateTimeImmutable $date): array;
    /** @return list<CostCenter> */
    public function findBySeniorManagerUid(string $seniorManagerUid, DateTimeImmutable $date): array;
    public function save(CostCenter $costCenter): void;
}