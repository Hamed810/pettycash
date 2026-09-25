<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Domain\Configuration\Repository\CurrencyRepository;

final class InMemoryCurrencyRepository implements CurrencyRepository
{
    /**
     * @var array<string, Currency>
     */
    private array $currencies = [];

    public function __construct()
    {
        $irr = Currency::create('IRR', 'Iranian Rial', 0);
        $irr->id = 1;
        $this->currencies['IRR'] = $irr;
    }

    public function findByCode(string $code): ?Currency
    {
        return $this->currencies[$code] ?? null;
    }

    public function findActiveByCode(string $code): ?Currency
    {
        $currency = $this->findByCode($code);
        return $currency !== null && $currency->active ? $currency : null;
    }

    /**
     * @return list<Currency>
     */
    public function findAllActive(): array
    {
        return array_values(array_filter($this->currencies, fn($c) => $c->active));
    }

    public function save(Currency $currency): void
    {
        $this->currencies[$currency->code()] = $currency;
    }
}