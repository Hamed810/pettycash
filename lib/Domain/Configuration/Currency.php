<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Configuration;

use DateTimeImmutable;
use InvalidArgumentException;

final class Currency
{
    public function __construct(
        private string $code,
        public string $name,
        public int $minorUnit,
        public bool $active = true,
        public ?DateTimeImmutable $createdAt = null,
        public ?string $createdBy = null,
        public ?DateTimeImmutable $updatedAt = null,
        public ?string $updatedBy = null,
        public int $lockVersion = 0,
        public ?int $id = null,
        public ?string $publicId = null
    ) {}

    public static function create(string $code, string $name, int $minorUnit): self
    {
        if (!preg_match('/^[A-Z]{3}$/', $code) || $minorUnit < 0 || $minorUnit > 4 || trim($name) === '') {
            throw new InvalidArgumentException('Invalid currency');
        }
        return new self($code, $name, $minorUnit, true);
    }

    public function code(): string
    {
        return $this->code;
    }
}