<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Configuration;

use DateTimeImmutable;
use InvalidArgumentException;

final class ExpenseType
{
    public function __construct(
        public string $title,
        public ?string $description,
        public bool $evidenceRequired,
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
        string $title,
        ?string $description,
        bool $evidenceRequired
    ): self {
        if (trim($title) === '') {
            throw new InvalidArgumentException('Expense type title is required');
        }
        return new self($title, $description, $evidenceRequired, true);
    }
}