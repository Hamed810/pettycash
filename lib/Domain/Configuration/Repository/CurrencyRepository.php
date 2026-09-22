<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Configuration\Repository;

use OCA\PettyCash\Domain\Configuration\Currency;

interface CurrencyRepository
{
    public function findByCode(string $code): ?Currency;
    public function findActiveByCode(string $code): ?Currency;
    /** @return list<Currency> */
    public function findAllActive(): array;
    public function save(Currency $currency): void;
}