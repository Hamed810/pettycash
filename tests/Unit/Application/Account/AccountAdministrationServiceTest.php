<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Application\Account;

use InvalidArgumentException;
use OCA\PettyCash\Application\Account\AccountAdministrationService;
use OCA\PettyCash\Application\Audit\ConfigurationAuditService;
use OCA\PettyCash\Application\Configuration\CurrencyService;
use OCA\PettyCash\Domain\Account\PettyCashAccount;
use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Domain\Configuration\Repository\CurrencyRepository;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAccountRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAdministratorCheck;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrencyRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionManager;
use PHPUnit\Framework\TestCase;

/**
 * T085 — `AccountAdministrationService`, over the in-memory repository doubles.
 *
 * The double currency repository seeds `IRR` at `minor_unit = 0`, which is the configured rial
 * `CurrencyService::defaultRial()` insists on, so the provisioning path is answerable without
 * arranging a currency first. The cases that need a *different* currency arrange one explicitly.
 */
final class AccountAdministrationServiceTest extends TestCase
{
    private InMemoryAccountRepository $accounts;

    private CurrencyRepository $currencies;

    private AccountAdministrationService $service;

    protected function setUp(): void
    {
        $this->accounts = new InMemoryAccountRepository();
        $this->currencies = new InMemoryCurrencyRepository();
        $this->service = new AccountAdministrationService(
            $this->accounts,
            new CurrencyService(
                $this->currencies,
                new InMemoryAdministratorCheck(),
                new ConfigurationAuditService(new InMemoryAuditWriter(), new InMemoryTransactionManager())
            )
        );
    }

    // --- FR-020's trigger: zero active accounts ---

    public function testAPurchaserWithNoActiveAccountIsProvisionedTheConfiguredRial(): void
    {
        $rial = $this->service->ensureDefaultRialAccount('purchaser-1');

        self::assertInstanceOf(PettyCashAccount::class, $rial);
        self::assertSame('purchaser-1', $rial->userUid);
        // FR-020 names the rial specifically. An account provisioned in whatever currency happened to
        // be first would satisfy "an account exists" and break every figure the sheet will hold.
        self::assertSame('IRR', (string)$rial->currencyCode());
        self::assertSame(0, $rial->currency->minorUnit, 'The rial has no minor unit');
        self::assertTrue($rial->active, 'FR-014 requires a *default active* account');
        self::assertSame(0, $rial->balanceMinor, 'A provisioned account starts empty');
    }

    public function testProvisioningIsIdempotent(): void
    {
        // FR-020 says "(idempotent provisioning)". The property that matters is not that two calls
        // return equal values but that the second one writes nothing: a purchaser who opens the sheet
        // form twice must not accumulate a second default account.
        $first = $this->service->ensureDefaultRialAccount('purchaser-1');
        self::assertInstanceOf(PettyCashAccount::class, $first);

        $second = $this->service->ensureDefaultRialAccount('purchaser-1');

        self::assertNull(
            $second,
            'The precondition is "zero active accounts", which the first call made false'
        );
        self::assertCount(1, $this->accounts->findActiveByUserUid('purchaser-1'));
    }

    public function testAProvisionedAccountIsPersistedSoTheNextReadSeesIt(): void
    {
        $account = $this->service->ensureDefaultRialAccount('purchaser-1');

        self::assertNotNull($account);
        // Not merely returned: a provisioned account that was never saved would satisfy every
        // assertion above and still be invisible to `GET /accounts`, which is the endpoint the sheet
        // form loads its choices from.
        self::assertNotNull(
            $this->accounts->findByPublicId($account->id),
            'The provisioned account must be readable back through findByPublicId'
        );
    }

    // --- FR-020's precondition, which is not "has an account at all" ---

    public function testACallerWithAnActiveNonRialAccountIsNotGivenADefault(): void
    {
        // FR-020 triggers on **zero active accounts**. A purchaser who deliberately holds a dollar
        // account has not asked for a rial one, and silently minting a second default would leave
        // them with two currencies and the same ambiguity FR-020 asks the UI to resolve.
        $usd = Currency::create('USD', 'US Dollar', 2);
        $usd->id = 2;
        $this->currencies->save($usd);
        $this->accounts->save(PettyCashAccount::create('acct-usd', 'purchaser-1', $usd));

        self::assertNull($this->service->ensureDefaultRialAccount('purchaser-1'));
        self::assertCount(1, $this->accounts->findActiveByUserUid('purchaser-1'));
    }

    public function testAnInactiveAccountDoesNotCountAsHavingAnActiveOne(): void
    {
        // The distinction FR-020 actually draws is "zero **active** accounts". Treating a deactivated
        // account as satisfying the precondition would leave a purchaser who retired their only
        // account permanently unable to create a sheet.
        $usd = Currency::create('USD', 'US Dollar', 2);
        $usd->id = 2;
        $this->currencies->save($usd);
        $account = PettyCashAccount::create('acct-usd', 'purchaser-1', $usd);
        $account->active = false;
        $this->accounts->save($account);

        $provisioned = $this->service->ensureDefaultRialAccount('purchaser-1');

        self::assertNotNull($provisioned, 'Zero *active* accounts is the precondition FR-020 states');
        self::assertSame('IRR', (string)$provisioned->currencyCode());
    }

    public function testOneUserBeingProvisionedDoesNotProvisionAnother(): void
    {
        $this->service->ensureDefaultRialAccount('purchaser-1');

        $other = $this->service->ensureDefaultRialAccount('purchaser-2');

        self::assertNotNull($other);
        self::assertSame('purchaser-2', $other->userUid);
        self::assertCount(1, $this->accounts->findActiveByUserUid('purchaser-2'));
    }

    // --- the configuration dependency is load-bearing ---

    public function testAMissingRialCurrencyIsAnAdministratorProblemAndNotAQuietFallback(): void
    {
        // `CurrencyService::defaultRial()` already refuses this. The point of asserting it here is that
        // provisioning does not catch it and continue: an account created in an unknown currency
        // reports every future amount at a guessed scale.
        //
        // `InMemoryCurrencyRepository` seeds `IRR` in its constructor and offers no removal, so the
        // "IRR is not configured" installation is arranged with a repository that has nothing in it
        // rather than by saving over the seeded row -- saving a differently-coded currency would leave
        // `IRR` exactly where it was and quietly test nothing.
        $this->service = $this->serviceWithCurrencies($this->emptyCurrencyRepository());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IRR');
        $this->service->ensureDefaultRialAccount('purchaser-1');
    }

    public function testARialConfiguredAtTheWrongScaleIsRefusedRatherThanProvisionedIn(): void
    {
        // Rial has no minor unit. An `IRR` configured at 2 would store 1,000 rial as 100000, which is
        // a factor of a hundred on every amount in every sheet the account is ever used for.
        $wrong = Currency::create('IRR', 'Iranian Rial', 2);
        $this->service = $this->serviceWithCurrencies($this->currencyRepositoryHolding($wrong));

        try {
            $this->service->ensureDefaultRialAccount('purchaser-1');
            self::fail('Provisioning continued against a rial configured at minor unit 2');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('minor unit', $e->getMessage());
        }

        self::assertCount(
            0,
            $this->accounts->findActiveByUserUid('purchaser-1'),
            'A refused provisioning must not leave an account behind'
        );
    }

    public function testAnEmptyUserIsRefusedRatherThanProvisionedAnOrphanAccount(): void
    {
        // An account with no owner is a balance nobody can be charged and nobody can spend from.
        $this->expectException(InvalidArgumentException::class);
        $this->service->ensureDefaultRialAccount('   ');
    }

    // --- FR-020's three clauses, as the sheet-creation path reads them ---

    public function testASingleActiveAccountIsUsedWithoutProvisioningAnything(): void
    {
        $usd = Currency::create('USD', 'US Dollar', 2);
        $usd->id = 2;
        $this->currencies->save($usd);
        $existing = PettyCashAccount::create('acct-usd', 'purchaser-1', $usd);
        $this->accounts->save($existing);

        $resolved = $this->service->resolveAccountForNewSheet('purchaser-1');

        self::assertNotNull($resolved);
        self::assertSame('acct-usd', $resolved->id, 'FR-020: one active account fixes the currency');
        self::assertCount(1, $this->accounts->findActiveByUserUid('purchaser-1'));
    }

    public function testSeveralActiveAccountsLeaveTheChoiceToThePurchaser(): void
    {
        // FR-020: "a purchaser with multiple active accounts MUST choose from only those account
        // currencies". Choosing one here would make the server's pick the sheet's currency.
        $usd = Currency::create('USD', 'US Dollar', 2);
        $usd->id = 2;
        $this->currencies->save($usd);
        $this->accounts->save(PettyCashAccount::create('acct-usd', 'purchaser-1', $usd));
        $this->accounts->save(PettyCashAccount::create('acct-irr', 'purchaser-1', $this->rial()));

        self::assertNull($this->service->resolveAccountForNewSheet('purchaser-1'));
        self::assertCount(2, $this->accounts->findActiveByUserUid('purchaser-1'));
    }

    public function testNoActiveAccountProvisionsAndResolvesToTheSameAccount(): void
    {
        $resolved = $this->service->resolveAccountForNewSheet('purchaser-1');

        self::assertNotNull($resolved);
        self::assertSame('IRR', (string)$resolved->currencyCode());
        self::assertCount(1, $this->accounts->findActiveByUserUid('purchaser-1'));
    }

    public function testResolvingDoesNotProvisionATwiceResolvedAccount(): void
    {
        $first = $this->service->resolveAccountForNewSheet('purchaser-1');
        self::assertNotNull($first);

        $second = $this->service->resolveAccountForNewSheet('purchaser-1');

        self::assertNotNull($second);
        self::assertSame($first->id, $second->id);
        self::assertCount(1, $this->accounts->findActiveByUserUid('purchaser-1'));
    }

    private function rial(): Currency
    {
        $rial = $this->currencies->findByCode('IRR');
        self::assertInstanceOf(Currency::class, $rial);

        return $rial;
    }

    /**
     * A currency repository holding exactly the currencies given, and nothing else.
     *
     * `InMemoryCurrencyRepository` is unusable for the misconfiguration cases because its constructor
     * seeds `IRR` and it has no removal: `save()` keys by code, so arranging "IRR is absent" by saving
     * something else leaves the seeded row in place and the case silently proves nothing.
     */
    private function currencyRepositoryHolding(Currency ...$currencies): CurrencyRepository
    {
        return new class ($currencies) implements CurrencyRepository {
            /** @param list<Currency> $currencies */
            public function __construct(private array $currencies) {}

            public function findByCode(string $code): ?Currency
            {
                foreach ($this->currencies as $currency) {
                    if ($currency->code() === $code) {
                        return $currency;
                    }
                }

                return null;
            }

            public function findActiveByCode(string $code): ?Currency
            {
                $currency = $this->findByCode($code);

                return $currency !== null && $currency->active ? $currency : null;
            }

            public function findAllActive(): array
            {
                return array_values(array_filter($this->currencies, static fn (Currency $c): bool => $c->active));
            }

            public function save(Currency $currency): void
            {
                $this->currencies[] = $currency;
            }
        };
    }

    private function emptyCurrencyRepository(): CurrencyRepository
    {
        return $this->currencyRepositoryHolding();
    }

    private function serviceWithCurrencies(CurrencyRepository $currencies): AccountAdministrationService
    {
        $this->currencies = $currencies;

        return new AccountAdministrationService(
            $this->accounts,
            new CurrencyService(
                $currencies,
                new InMemoryAdministratorCheck(),
                new ConfigurationAuditService(new InMemoryAuditWriter(), new InMemoryTransactionManager())
            )
        );
    }

    public function testAProvisionedAccountCarriesAUsablePublicIdentifier(): void
    {
        $account = $this->service->ensureDefaultRialAccount('purchaser-1');

        self::assertNotNull($account);
        // `ExpenseSheet::open()` stores this as its account reference and `GET /accounts` hands it
        // back to the form, so a non-uuid here would only surface as a 400 much later.
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $account->id
        );
        self::assertTrue(
            CurrencyCode::fromString('IRR')->equals($account->currencyCode()),
            'The account resolves to the same currency the sheet will inherit'
        );
    }
}