<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use DateTimeImmutable;
use OCA\PettyCash\Domain\Configuration\CostCenter;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;

final class InMemoryCostCenterRepository implements CostCenterRepository
{
    /**
     * @var array<int, CostCenter>
     */
    private array $costCenters = [];

    public function __construct()
    {
        // Add a default cost center for testing
        $cc = CostCenter::create('CC001', 'Engineering', 'Engineering department', new DateTimeImmutable('2026-01-01'), null, 'test-manager', 'test-senior');
        $cc->id = 1;
        $this->costCenters[1] = $cc;
    }

    public function findById(int $id): ?CostCenter
    {
        return $this->costCenters[$id] ?? null;
    }

    public function findByCode(string $code): ?CostCenter
    {
        foreach ($this->costCenters as $cc) {
            if ($cc->code() === $code) {
                return $cc;
            }
        }
        return null;
    }

    public function findByPublicId(string $publicId): ?CostCenter
    {
        foreach ($this->costCenters as $cc) {
            if ($cc->publicId === $publicId) {
                return $cc;
            }
        }
        return null;
    }

    public function findActiveByCodeOn(string $code, DateTimeImmutable $date): ?CostCenter
    {
        $cc = $this->findByCode($code);
        if ($cc === null) {
            return null;
        }
        if (!$cc->isValidOn($date)) {
            return null;
        }
        return $cc;
    }

    /**
     * @return list<CostCenter>
     */
    public function findAllActiveOn(DateTimeImmutable $date): array
    {
        return array_filter($this->costCenters, fn($cc) => $cc->isValidOn($date));
    }

    public function save(CostCenter $costCenter): void
    {
        if ($costCenter->id === null) {
            $costCenter->id = count($this->costCenters) + 1;
        }
        $this->costCenters[$costCenter->id] = $costCenter;
    }
}