<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Shared;

use InvalidArgumentException;

final readonly class CurrencyCode
{
    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        if (preg_match('/^[A-Z]{3}$/', $value) !== 1) {
            throw new InvalidArgumentException('Currency code must be three uppercase letters');
        }
        return new self($value);
    }

    public function equals(self $other): bool { return $this->value === $other->value; }
    public function __toString(): string { return $this->value; }
}
