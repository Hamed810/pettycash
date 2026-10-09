<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Application\Configuration;

use InvalidArgumentException;
use OCA\PettyCash\Application\Audit\ConfigurationAuditService;
use OCA\PettyCash\Application\Configuration\CurrencyService;
use OCA\PettyCash\Domain\Account\PettyCashAccount;
use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAccountRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAdministratorCheck;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrencyRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionManager;
use PHPUnit\Framework\TestCase;

/**
 * T422 / FR-023: a currency's decimal scale is **refused** while that currency holds amounts.
 *
 * ## Why this rule exists at all
 *
 * Amounts are stored as whole minor units and are read against the currency's configured `minor_unit`. The
 * scale is therefore not a display preference — it decides **what every stored amount means**. Changing the
 * scale on a currency that holds amounts would silently reinterpret every balance, sheet total and movement in
 * it: a rial balance of 50,000 becoming 500.00, with no migration, no audit of the reinterpretation, and no way
 * to tell afterwards which figure was meant.
 *
 * This is a Principle II matter, because it changes the **data meaning** of stored financial records without an
 * approved migration. `CurrencyService::update()` accepted `int $minorUnit` with nothing guarding it, so the
 * change was one form field away.
 *
 * ## Why the earlier clause this replaced was unsatisfiable
 *
 * An earlier version of this requirement refused "an amount carrying more decimal places than the scale
 * allows". No such value exists: `amountMinor` is an integer *already expressed in the currency's minor
 * units*, so every represented amount has exactly the scale's precision. The rule now asserted here is the one
 * that describes a state the system can actually be in.
 *
 * ## Why an account is the proxy for "an amount exists"
 *
 * Every amount reaches a balance through an account, and a sheet's currency is its account's currency. A
 * currency with **no** account has no balance, no movement, and no transaction. Counting accounts is therefore a
 * superset of "amounts exist", and **over-refusing is the safe direction**: an administrator whose scale change
 * is refused can deactivate the currency, whereas under-refusing would reinterpret balances silently.
 *
 * @see \OCA\PettyCash\Tests\Unit\Application\Configuration\CurrencyServiceTest for the pre-existing currency rules
 */
final class CurrencyScaleChangeTest extends TestCase
{
    private InMemoryCurrencyRepository $currencies;
    private InMemoryAccountRepository $accounts;
    private CurrencyService $service;

    protected function setUp(): void
    {
        // `IRR` is seeded with id 1 and no minor unit — a rial — so raising its scale is the change under
        // test, and it is the most consequential one: every rial amount in the system is a whole unit.
        $this->currencies = new InMemoryCurrencyRepository();
        $this->accounts = new InMemoryAccountRepository();
        $this->accounts->clear();

        $this->service = new CurrencyService(
            $this->currencies,
            new InMemoryAdministratorCheck(),
            new ConfigurationAuditService(new InMemoryAuditWriter(), new InMemoryTransactionManager()),
            $this->accounts
        );
    }

    // ===== the rule: refused while amounts exist =====

    /**
     * The headline: a used currency's scale is refused, and the message says why and what to do instead.
     *
     * The message is asserted on content, not just on being non-empty, because FR-023 requires it to name the
     * currency, the current scale and the requested one — and an administrator who is told only "invalid" has
     * no way to proceed. `deactivate` is named because it is the remedy the requirement deliberately leaves open.
     */
    public function testAScaleChangeIsRefusedWhileTheCurrencyHoldsAmounts(): void
    {
        $this->addRialAccount(balanceMinor: 50000);

        try {
            // Version 0: the double seeds IRR at lockVersion 0, and no prior update has run in this test.
            $this->service->update('test-admin', 'IRR', 'Iranian Rial', 2, true, 0);
            self::fail('changing the scale of a currency that holds amounts must be refused');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('IRR', $e->getMessage(), 'the message must name the currency');
            self::assertStringContainsString(
                'from 0 to 2',
                $e->getMessage(),
                'the message must name both the current and the requested scale'
            );
            self::assertStringContainsString(
                'deactivate',
                $e->getMessage(),
                'the message must name the remedy, or the caller is left with no way forward'
            );
        }

        // **The stored scale must not have moved.** Asserted separately because a refusal that still wrote the
        // new scale would leave the currency in exactly the state the rule exists to prevent, and the exception
        // alone would not show it.
        self::assertSame(
            0,
            $this->currencies->findByCode('IRR')?->minorUnit,
            'a refused scale change must not be partially applied'
        );
    }

    /**
     * The refund direction is the same hazard: lowering the scale divides every stored amount instead.
     *
     * Asserted because the natural implementation of "a scale change is locked" tests only `>` and would
     * let this through — and it silently *shrinks* every balance in the currency.
     */
    public function testLoweringTheScaleIsAlsoRefusedWhileTheCurrencyHoldsAmounts(): void
    {
        $this->addRialAccount(balanceMinor: 50000);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('from 0 to');

        // First raise the scale on the *unused* currency so the stored value is 2, then lower it to 0 while
        // amounts exist. Asserting only the raise direction would let a `>` comparison through, and lowering
        // silently divides every stored amount instead of multiplying it.
        $this->service->update('test-admin', 'IRR', 'Iranian Rial', 2, true, 0);
        $this->addRialAccount(balanceMinor: 50000);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('from 2 to 0');

        $this->service->update('test-admin', 'IRR', 'Iranian Rial', 0, true, 1);
    }

    // ===== the rule must not over-refuse =====

    /**
     * **The most important case in the file.** A rename and an activation change are harmless, and they must
     * still succeed on a currency that holds amounts.
     *
     * This is the failure mode of putting the guard in the wrong place: refusing the whole update because one
     * field is off-limits makes a live currency impossible to rename or to deactivate — and deactivating is the
     * remedy FR-023 tells an administrator to use, so a guard that blocked it would make the refusal a dead end.
     */
    public function testARenameAndAnActivationChangeStillSucceedOnAUsedCurrency(): void
    {
        $this->addRialAccount(balanceMinor: 50000);

        $updated = $this->service->update('test-admin', 'IRR', 'Iranian Rial (renamed)', 0, true, 0);

        self::assertSame('Iranian Rial (renamed)', $updated->name, 'renaming a used currency must still work');
        self::assertSame(0, $updated->minorUnit, 'the scale is untouched when it was not requested to change');

        // And deactivation specifically, because it is the documented remedy for a refused scale change.
        $deactivated = $this->service->update(
            'test-admin',
            'IRR',
            'Iranian Rial (renamed)',
            0,
            false,
            $updated->lockVersion
        );
        self::assertFalse($deactivated->active, 'deactivating a used currency must still work — it is the stated remedy');
    }

    /**
     * An **unused** currency may have its scale set freely.
     *
     * Without this the rule would make every currency created at configuration time permanently frozen, which
     * is the opposite of what a newly added currency needs.
     */
    public function testAnUnusedCurrencyMayHaveItsScaleSetFreely(): void
    {
        $usd = Currency::create('USD', 'United States Dollar', 0);
        $usd->id = 7;
        $this->currencies->save($usd);

        $updated = $this->service->update('test-admin', 'USD', 'United States Dollar', 2, true, 0);

        self::assertSame(2, $updated->minorUnit, 'a currency holding no amounts may have its scale set');
    }

    /**
     * Setting the scale to the value it already has is **not** a change.
     *
     * The guard compares the requested scale against the stored one, so re-submitting an unchanged form — which
     * every administrator's client does on save — is not refused for a field nobody altered.
     */
    public function testResubmittingTheSameScaleIsNotAChange(): void
    {
        $this->addRialAccount(balanceMinor: 50000);

        $updated = $this->service->update('test-admin', 'IRR', 'Iranian Rial', 0, true, 0);

        self::assertSame(0, $updated->minorUnit, 'saving an unchanged form must not be refused for the scale');
    }

    // ===== ordering: the version check must still come first =====

    /**
     * A stale caller is told to **reload**, not that the scale is locked.
     *
     * Both are refusals and only one is true of the request. Asserted because the scale guard was added after
     * the version check, and a future reordering would silently change which of the two reasons a caller is
     * given — and the reload remedy is the one that actually resolves a stale view.
     */
    public function testAStaleCallerIsToldToReloadRatherThanThatTheScaleIsLocked(): void
    {
        $this->addRialAccount(balanceMinor: 50000);

        try {
            $this->service->update('test-admin', 'IRR', 'Iranian Rial', 2, true, 999);
            self::fail('a stale caller must be refused');
        } catch (\OCA\PettyCash\Application\Configuration\ConfigurationConflict $e) {
            self::assertSame(
                'CONFIGURATION_CURRENCY_VERSION_CONFLICT',
                $e->errorCode(),
                'the version guard runs first, so a stale caller is told to reload rather than that the '
                . 'scale is locked — only one of those is true of the request'
            );
        }
    }

    // ===== the proxy itself =====

    /**
     * A **deactivated** account still blocks the scale change.
     *
     * The account's balance is still stored, and reinterpreting it would be exactly the harm the rule prevents —
     * so "inactive" is not a reason to allow it. This also pins the in-memory double to count accounts without
     * filtering on `active`, which a double that filtered would quietly agree with the wrong answer.
     */
    public function testADeactivatedAccountStillBlocksTheScaleChange(): void
    {
        $this->addRialAccount(balanceMinor: 50000, active: false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already holds amounts');

        $this->service->update('test-admin', 'IRR', 'Iranian Rial', 2, true, 0);
    }

    /**
     * An account in a **different** currency does not block this one.
     *
     * Without it, a single USD account would freeze every rial scale change in the instance.
     */
    public function testAnAccountInAnotherCurrencyDoesNotBlockTheChange(): void
    {
        $usd = Currency::create('USD', 'United States Dollar', 2);
        $usd->id = 7;
        $this->currencies->save($usd);
        $this->accounts->save(new PettyCashAccount(
            'acc-usd',
            'purchaser-1',
            CurrencyCode::fromString('USD'),
            $usd,
            1000,
            true
        ));

        $updated = $this->service->update('test-admin', 'IRR', 'Iranian Rial', 2, true, 0);

        self::assertSame(2, $updated->minorUnit, 'another currency\'s use must not freeze this one');
    }

    private function addRialAccount(int $balanceMinor, bool $active = true): void
    {
        $irr = $this->currencies->findByCode('IRR');
        self::assertNotNull($irr, 'the double seeds IRR with id 1');

        $this->accounts->save(new PettyCashAccount(
            'acc-' . $balanceMinor . '-' . ($active ? 'a' : 'i'),
            'purchaser-1',
            CurrencyCode::fromString('IRR'),
            $irr,
            $balanceMinor,
            $active
        ));
    }
}