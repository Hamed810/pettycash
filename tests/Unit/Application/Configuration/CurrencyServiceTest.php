<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Application\Configuration;

use InvalidArgumentException;
use LogicException;
use OCA\PettyCash\Application\Configuration\ConfigurationConflict;
use OCA\PettyCash\Application\Audit\ConfigurationAuditService;
use OCA\PettyCash\Application\Configuration\CurrencyService;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAdministratorCheck;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrencyRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionManager;
use PHPUnit\Framework\TestCase;

/**
 * T081 — `CurrencyService`, over the in-memory repository double.
 *
 * The double seeds one currency, `IRR`, which is also the default rial, so the "is this the rial?"
 * cases are answerable without arranging anything first.
 */
final class CurrencyServiceTest extends TestCase
{
    private InMemoryCurrencyRepository $currencies;

    private CurrencyService $service;

    protected function setUp(): void
    {
        $this->currencies = new InMemoryCurrencyRepository();
        // T086: the service writes a configuration audit event on every mutation, so the double is supplied
        // here rather than left out of the constructor. These cases are about currency rules, and an
        // audit stream that accepts everything keeps them that way; the audit behaviour itself is
        // asserted in `tests/Integration/Audit/ConfigurationMutationAuditTest.php`.
        $this->service = new CurrencyService(
            $this->currencies,
            new InMemoryAdministratorCheck(),
            new ConfigurationAuditService(new InMemoryAuditWriter(), new InMemoryTransactionManager())
        );
    }

    // --- authorization ---

    public function testANonAdministratorIsRefusedEveryWrite(): void
    {
        // Each of the three writes is checked, not just the first: a guard added to `create()` and
        // forgotten in `update()` would let a non-administrator rewrite a currency's scale, which is
        // exactly the field every stored amount is read against.
        foreach (
            [
            fn () => $this->service->create('test-user', 'USD', 'US Dollar', 2),
            fn () => $this->service->update('test-user', 'IRR', 'Rial', 0, true, 0),
            fn () => $this->service->listForCaller('test-user'),
            ] as $index => $write
        ) {
            try {
                $write();
                self::fail(sprintf('write %d was allowed for a non-administrator', $index));
            } catch (LogicException $e) {
                self::assertStringContainsString('administrator', $e->getMessage());
            }
        }
    }

    public function testAnEmptyActorIsRefusedRatherThanTreatedAsAnAdministrator(): void
    {
        $this->expectException(LogicException::class);
        $this->service->create('', 'USD', 'US Dollar', 2);
    }

    // --- create ---

    public function testCreateRegistersAnActiveCurrency(): void
    {
        $currency = $this->service->create('test-admin', 'USD', 'US Dollar', 2);

        self::assertSame('USD', $currency->code());
        self::assertSame('US Dollar', $currency->name);
        self::assertSame(2, $currency->minorUnit);
        self::assertTrue($currency->active);
        self::assertNotNull($this->currencies->findActiveByCode('USD'));
    }

    public function testCreateNormalisesTheCodeToUppercase(): void
    {
        // The domain requires `^[A-Z]{3}$`; accepting `usd` and storing `USD` is the only behaviour
        // that lets a caller's casing typo reach the uniqueness check rather than the domain's regex.
        $currency = $this->service->create('test-admin', 'usd', 'US Dollar', 2);

        self::assertSame('USD', $currency->code());
        self::assertNotNull($this->currencies->findByCode('USD'));
    }

    public function testCreateRefusesADuplicateCodeAsAConflictNotAsAnInvalidRequest(): void
    {
        try {
            $this->service->create('test-admin', 'IRR', 'Rial Again', 0);
            self::fail('a duplicate currency code was accepted');
        } catch (ConfigurationConflict $e) {
            self::assertSame('CONFIGURATION_CURRENCY_ALREADY_EXISTS', $e->errorCode());
            self::assertStringContainsString('IRR', $e->getMessage());
        }
    }

    public function testCreateRefusesAnInvalidCodeThroughTheDomainRatherThanDuplicatingTheRule(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->create('test-admin', 'US', 'Too short', 2);
    }

    public function testCreateRefusesAMinorUnitOutsideTheDomainRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->create('test-admin', 'USD', 'US Dollar', 5);
    }

    // --- update ---

    public function testUpdateChangesNameScaleAndActiveState(): void
    {
        $updated = $this->service->update('test-admin', 'IRR', 'Iranian Rial', 0, true, 0);

        self::assertSame('Iranian Rial', $updated->name);
        self::assertTrue($updated->active);
    }

    public function testUpdateRefusesAStaleVersionAsAConflict(): void
    {
        // The version is the whole point of FR-042's atomicity rule applied to configuration: two
        // administrators editing one currency must not silently lose one edit.
        $this->expectException(ConfigurationConflict::class);
        $this->service->update('test-admin', 'IRR', 'Renamed', 0, true, 7);
    }

    public function testUpdateNamesBothVersionsSoTheCallerCanRecoverByReloading(): void
    {
        try {
            $this->service->update('test-admin', 'IRR', 'Renamed', 0, true, 7);
            self::fail('a stale version was accepted');
        } catch (ConfigurationConflict $e) {
            self::assertSame('CONFIGURATION_CURRENCY_VERSION_CONFLICT', $e->errorCode());
            self::assertStringContainsString('at version 0, not the expected 7', $e->getMessage());
        }
    }

    public function testUpdateRefusesAnUnknownCodeAsAnInvalidRequest(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->update('test-admin', 'ZZZ', 'Nope', 0, true, 0);
    }

    public function testUpdateValidatesThroughTheDomainSoAnInvalidNameCannotBeStored(): void
    {
        // An update that assigned the properties instead of re-running the factory would accept a blank
        // name, because `Currency::create()` is the only thing that refuses one.
        $this->expectException(InvalidArgumentException::class);
        $this->service->update('test-admin', 'IRR', '   ', 0, true, 0);
    }

    // --- deactivation, which is how retirement works ---

    public function testDeactivatedCurrencyStaysReadableByCodeButLeavesTheActiveList(): void
    {
        // FR-012's historical retention in the only form a currency can express it: the row keyed
        // `IRR` still resolves, so a stored amount keeps the scale it was filed against, while no new
        // record can select it.
        $this->service->update('test-admin', 'IRR', 'Iranian Rial', 0, false, 0);

        $byCode = $this->currencies->findByCode('IRR');
        self::assertNotNull($byCode, 'a deactivated currency must still resolve by code');
        self::assertFalse($byCode->active);
        self::assertNull($this->currencies->findActiveByCode('IRR'));
        self::assertNotContains('IRR', array_map(
            static fn ($c): string => $c->code(),
            $this->currencies->findAllActive()
        ));
    }

    // --- listing ---

    public function testListingReturnsOnlyActiveCurrenciesPaged(): void
    {
        $this->service->create('test-admin', 'USD', 'US Dollar', 2);
        $this->service->create('test-admin', 'EUR', 'Euro', 2);
        $this->service->update('test-admin', 'EUR', 'Euro', 2, false, 0);

        $page = $this->service->listForCaller('test-admin', 1, 25);

        $codes = array_map(static fn ($c): string => $c->code(), $page['items']);
        self::assertSame(['IRR', 'USD'], $codes);
        self::assertSame(2, $page['total']);
        self::assertSame(1, $page['page']);
        self::assertSame(25, $page['pageSize']);
    }

    public function testListingSlicesRatherThanReturningEverythingRegardlessOfPageSize(): void
    {
        $this->service->create('test-admin', 'USD', 'US Dollar', 2);
        $this->service->create('test-admin', 'EUR', 'Euro', 2);

        $page = $this->service->listForCaller('test-admin', 2, 1);

        self::assertCount(1, $page['items']);
        self::assertSame(3, $page['total'], 'total counts the whole set, not the page');
    }

    public function testListingRefusesAPageBelowTheContractMinimum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->listForCaller('test-admin', 0, 25);
    }

    // --- the rial default, which T085 provisioning depends on ---

    public function testTheSeededRialIsIdentifiedAsTheDefault(): void
    {
        self::assertTrue($this->service->isDefaultRial('IRR'));
        self::assertTrue($this->service->isDefaultRial('irr'), 'identification is case-insensitive');
        self::assertFalse($this->service->isDefaultRial('USD'));
    }

    public function testDefaultRialReturnsTheConfiguredCurrency(): void
    {
        $rial = $this->service->defaultRial();

        self::assertSame('IRR', $rial->code());
        self::assertSame(CurrencyService::DEFAULT_RIAL_MINOR_UNIT, $rial->minorUnit);
    }

    public function testDefaultRialRefusesWhenTheRialIsNotConfigured(): void
    {
        // FR-020 provisions a purchaser's first account in rial. A provisioning path that continued
        // without a currency would create an account whose scale is unknown, so this must be loud --
        // and it must be loud for a repository that genuinely lacks the row, which is why this uses
        // its own double rather than the seeded one.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not configured');

        (new CurrencyService(
            new CurrencyRepositoryWithoutRial(),
            new InMemoryAdministratorCheck(),
            new ConfigurationAuditService(new InMemoryAuditWriter(), new InMemoryTransactionManager())
        ))
            ->defaultRial();
    }

    public function testDefaultRialRefusesWhenTheConfiguredRialHasTheWrongScale(): void
    {
        // Rial has no minor unit. A configured `IRR` with `minorUnit = 2` would round every rial amount
        // by 100, so identifying it as "the rial" without checking the scale would be worse than failing.
        $this->service->update('test-admin', 'IRR', 'Iranian Rial', 2, true, 0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('minor unit 2');
        $this->service->defaultRial();
    }
}

/**
 * A repository that genuinely has no `IRR`, which the seeded double cannot express.
 *
 * Deactivating the seeded rial leaves the row readable, which is correct for FR-012's historical
 * retention but means it can never make `defaultRial()` fail for the "not configured" reason. This
 * double is the only way to reach that branch, and reaching it matters: it is the branch a
 * half-provisioned installation lands in.
 */
final class CurrencyRepositoryWithoutRial implements \OCA\PettyCash\Domain\Configuration\Repository\CurrencyRepository
{
    public function findByCode(string $code): ?\OCA\PettyCash\Domain\Configuration\Currency
    {
        return null;
    }

    public function findActiveByCode(string $code): ?\OCA\PettyCash\Domain\Configuration\Currency
    {
        return null;
    }

    /** @return list<\OCA\PettyCash\Domain\Configuration\Currency> */
    public function findAllActive(): array
    {
        return [];
    }

    public function save(\OCA\PettyCash\Domain\Configuration\Currency $currency): void
    {
    }
}
