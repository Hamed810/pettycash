<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Shared;

use InvalidArgumentException;

final readonly class Money
{
    private function __construct(private int $minorUnits, private CurrencyCode $currency) {}

    public static function of(int $minorUnits, CurrencyCode $currency): self { return new self($minorUnits, $currency); }

    public static function positive(int $minorUnits, CurrencyCode $currency): self
    {
        if ($minorUnits <= 0) { throw new InvalidArgumentException('Amount must be greater than zero'); }
        return new self($minorUnits, $currency);
    }

    public function add(self $other): self
    {
        if (!$this->currency->equals($other->currency)) { throw new InvalidArgumentException('Currency mismatch'); }
        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function minorUnits(): int { return $this->minorUnits; }
    public function currency(): CurrencyCode { return $this->currency; }
    /** @return array{minorUnits:int,currency:string} */
    public function jsonSerialize(): array { return ['minorUnits' => $this->minorUnits, 'currency' => (string) $this->currency]; }
}
