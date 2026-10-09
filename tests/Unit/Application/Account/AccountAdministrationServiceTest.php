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

    /** FR-017: provisioning's `ACCOUNT_CREATED` row, retained so the tests can read it. */
    private InMemoryAuditWriter $auditWriter;

    protected function setUp(): void
    {
        $this->accounts = new InMemoryAccountRepository();
        $this->currencies = new InMemoryCurrencyRepository();
        $this->auditWriter = new InMemoryAuditWriter();
        $this->service = new AccountAdministrationService(
            $this->accounts,
            new CurrencyService(
                $this->currencies,
                new InMemoryAdministratorCheck(),
                new ConfigurationAuditService($this->auditWriter, new InMemoryTransactionManager()),
                // T422: the account repository the same class provisions into, so FR-023's scale guard
                // counts real accounts here rather than an empty set.
                $this->accounts
            ),
            $this->auditWriter
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

    // --- T410: the carve-out — an inactive *rial* record blocks provisioning ---

    /**
     * An inactive rial record MUST NOT trigger provisioning: it is a deliberately retired account, and a
     * sheet-creation click must not silently reverse an administrator's deactivation with a replacement
     * carrying neither the retired account's identity nor its history.
     *
     * The refusal names the account and the remedy, because a purchaser told only "no active account" has
     * neither a way to proceed nor a way to learn that one exists — the actionable-refusal rule the
     * settlement path already follows.
     */
    public function testAnInactiveRialRecordBlocksProvisioningWithAnActionableRefusal(): void
    {
        $irr = $this->currencies->findByCode('IRR');
        self::assertNotNull($irr);
        $retired = PettyCashAccount::create('acct-rial-retired', 'purchaser-1', $irr);
        $retired->setActiveByAdministrator(false);
        $this->accounts->save($retired);

        try {
            $this->service->ensureDefaultRialAccount('purchaser-1');
            self::fail('provisioning must not fire on a deliberately retired rial account');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('acct-rial-retired', $e->getMessage(), 'the refusal must name the account');
            self::assertStringContainsString('reactivate', $e->getMessage(), 'the refusal must state the remedy');
        }

        self::assertSame(
            [],
            $this->accounts->findActiveByUserUid('purchaser-1'),
            'the refusal must provision nothing — a replacement account would be the silent reversal'
        );
    }

    /**
     * The carve-out is rial-specific: an inactive **non-rial** record does not block provisioning.
     *
     * `testAnInactiveAccountDoesNotCountAsHavingAnActiveOne` above already pins this for USD; this states
     * the reason rather than repeating the case — the distinction is whether a *rial* record exists, not
     * the active flag alone.
     */
    public function testAnotherUsersInactiveRialRecordDoesNotBlockProvisioning(): void
    {
        $irr = $this->currencies->findByCode('IRR');
        self::assertNotNull($irr);
        $foreign = PettyCashAccount::create('acct-rial-foreign', 'purchaser-2', $irr);
        $foreign->setActiveByAdministrator(false);
        $this->accounts->save($foreign);

        // Only the holder's own accounts are read: another user's retired rial account is not this
        // purchaser's state and must not block them.
        $provisioned = $this->service->ensureDefaultRialAccount('purchaser-1');

        self::assertNotNull($provisioned);
        self::assertSame('purchaser-1', $provisioned->userUid);
    }

    /**
     * The sheet path surfaces the refusal rather than "must choose".
     *
     * `resolveAccountForNewSheet()` returns null when the caller holds several active accounts, and
     * `SheetService` answers that with "send accountId to choose". The inactive-rial case must not take
     * that branch — choosing cannot fix a retired account — so the refusal throws through instead.
     */
    public function testResolveAccountForNewSheetThrowsRatherThanAskingToChoose(): void
    {
        $irr = $this->currencies->findByCode('IRR');
        self::assertNotNull($irr);
        $retired = PettyCashAccount::create('acct-rial-retired', 'purchaser-1', $irr);
        $retired->setActiveByAdministrator(false);
        $this->accounts->save($retired);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('acct-rial-retired');

        $this->service->resolveAccountForNewSheet('purchaser-1');
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
                new ConfigurationAuditService(new InMemoryAuditWriter(), new InMemoryTransactionManager()),
                new InMemoryAccountRepository()
            ),
            // FR-017: the same writer instance, so a test that reconfigures currencies and then
            // provisions can still read the `ACCOUNT_CREATED` row off `$this->auditWriter`.
            $this->auditWriter
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

    /**
     * T391 / ADR-005: every account a deleted user held is closed, and none is removed.
     *
     * **Deactivated rather than deleted** is the assertion that matters, and it is asserted two ways
     * because either alone is weak: `active` must be false, *and* the row must still be readable with
     * its balance. A delete would satisfy the first check and fail the second.
     */
    public function testDeletingAUserDeactivatesEveryAccountTheyHeldAndRemovesNothing(): void
    {
        $this->service->ensureDefaultRialAccount('departing-user');
        $second = new PettyCashAccount(
            '22222222-2222-4222-8222-222222222222',
            'departing-user',
            CurrencyCode::fromString('IRR'),
            Currency::create('IRR', 'Iranian Rial', 0),
            5000,
            true
        );
        $this->accounts->save($second);

        $deactivated = $this->service->deactivateAccountsOfDeletedUser('departing-user');

        self::assertSame(2, $deactivated, 'both of the user accounts transition exactly once');

        $survivors = $this->accounts->findAllByUserUid('departing-user');
        self::assertCount(2, $survivors, 'FR-054: nothing may be deleted -- the rows must remain');
        foreach ($survivors as $account) {
            self::assertFalse($account->active, 'a deleted user account must not read as spendable');
        }

        // The balance is financial history, not incidental state: FR-053 and FR-057 forbid removing it.
        $kept = array_values(array_filter(
            $survivors,
            static fn (PettyCashAccount $a): bool => $a->balanceMinor === 5000
        ));
        self::assertCount(1, $kept, 'a balance with movements behind it must survive its owner');
    }

    /**
     * A deactivated account stops being offered by FR-020's resolve branch.
     *
     * Without this the account would be unreachable yet still handed to the next sheet creation, which
     * is the precise failure ADR-005 exists to end. `resolveAccountForNewSheet()` reads
     * `findActiveByUserUid()`, so this is a consequence rather than a new rule -- but it is the
     * consequence that makes the deactivation meaningful, so it is asserted directly.
     */
    public function testADeactivatedAccountIsNoLongerOfferedForANewSheet(): void
    {
        $account = $this->service->ensureDefaultRialAccount('departing-user');
        self::assertNotNull($account);
        self::assertCount(1, $this->accounts->findActiveByUserUid('departing-user'));

        $this->service->deactivateAccountsOfDeletedUser('departing-user');

        self::assertCount(
            0,
            $this->accounts->findActiveByUserUid('departing-user'),
            'FR-020 resolves from *active* accounts, so a deactivated one must not be offered'
        );
    }

    /**
     * A second deletion event is a no-op, and says so.
     *
     * **The count is of transitions, not rows visited**, which is what makes a retried
     * `UserDeletedEvent` reconcilable: reporting "5 deactivated" for five already-inactive rows would
     * be a figure no reader could check against the database.
     *
     * It also guards the version bump. `deactivateForDeletedOwner()` refuses rather than re-saving, so
     * `lockVersion` must not move on the second pass -- a version that changes without a state change
     * tells a concurrent holder of the old version that its data moved when it did not.
     */
    public function testASecondDeletionEventDeactivatesNothingAndDoesNotBumpTheVersion(): void
    {
        $this->service->ensureDefaultRialAccount('departing-user');

        self::assertSame(1, $this->service->deactivateAccountsOfDeletedUser('departing-user'));

        $afterFirst = $this->accounts->findAllByUserUid('departing-user')[0]->lockVersion;

        // **The first transition must move the version.** Asserted here rather than left implicit, because
        // an earlier version of this test only checked the *second* call and a mutation that dropped the
        // increment entirely survived it: "the version does not move on a no-op" is satisfied just as well
        // by "the version never moves". Both halves are needed — the first is what lets a concurrent reader
        // holding the old version notice that this row changed under them.
        self::assertSame(
            1,
            $afterFirst,
            'deactivating must bump lockVersion, or a concurrent holder of version 0 cannot detect the change'
        );

        self::assertSame(
            0,
            $this->service->deactivateAccountsOfDeletedUser('departing-user'),
            'a retried event changed nothing, so it must not claim it deactivated something'
        );

        self::assertSame(
            $afterFirst,
            $this->accounts->findAllByUserUid('departing-user')[0]->lockVersion,
            'lockVersion must not move for a no-op: a changed version implies changed data'
        );
    }

    /**
     * Another user's accounts are untouched.
     *
     * A delete event carries one uid. Reading the wrong scope would close the whole instance, so the
     * boundary is asserted rather than assumed.
     */
    public function testOneUsersDeletionDoesNotDeactivateAnotherUsersAccount(): void
    {
        $this->service->ensureDefaultRialAccount('departing-user');
        $staying = $this->service->ensureDefaultRialAccount('staying-user');

        $this->service->deactivateAccountsOfDeletedUser('departing-user');

        self::assertTrue($this->accounts->findAllByUserUid('staying-user')[0]->active);
    }
}
