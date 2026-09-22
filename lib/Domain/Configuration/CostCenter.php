<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Configuration;

use DateTimeImmutable;
use InvalidArgumentException;

final class CostCenter
{
    public function __construct(
        private string $code,
        public string $name,
        public ?string $description,
        public DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validTo,
        public bool $active = true,
        public ?string $managerUid = null,
        public ?string $seniorManagerUid = null,
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
        ?DateTimeImmutable $validTo,
        ?string $managerUid = null,
        ?string $seniorManagerUid = null
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
        return new self($code, $name, $description, $validFrom, $validTo, true, $managerUid, $seniorManagerUid);
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