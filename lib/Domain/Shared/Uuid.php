<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Shared;

use InvalidArgumentException;

final readonly class Uuid
{
    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        $value = strtolower($value);
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value) !== 1) {
            throw new InvalidArgumentException('Invalid UUID');
        }
        return new self($value);
    }

    public static function random(): self
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return self::fromString(sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20)));
    }

    public function __toString(): string { return $this->value; }
}
