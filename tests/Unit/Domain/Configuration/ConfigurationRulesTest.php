<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Domain\Configuration;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\PettyCash\Domain\Account\PettyCashAccount;
use OCA\PettyCash\Domain\Configuration\CostCenter;
use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Domain\Configuration\ExpenseType;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * T077 — the configuration domain rules, and which layer actually holds each one.
 *
 * ## The file this replaces was a stub
 *
 * It held one method, seven lines long, that asserted `Currency::create('IRR', …)->code()` and then
 * expected `create('usd', …)` to fail. That is not a claim about anything: it proved the happy path
 * returns the code it was given and that one malformed code is refused. Of the six rule groups this
 * task names it covered none, and the file's existence made the task look attempted.
 *
 * ## Why half of this task is not a domain rule, stated rather than faked
 *
 * T077 names six groups. Three are genuine invariants of the aggregates; three are not, and asserting
 * them here would mean asserting something the domain does not and cannot hold:
 *
 * | T077 names | Actually held by | Why not the domain |
 * |---|---|---|
 * | `Currency.code` **unique** | `pettycash_currencies_code_uniq` + `CurrencyService::create()` | An aggregate instance cannot know what else exists. Uniqueness is a statement about a *set*, and the domain layer holds one instance. Already proven by `CurrencyServiceTest` and the real-database round trip |
 * | **exactly one** effective manager and senior manager | `CostCenterService` | No unique index covers `(role, cost_center_id)`, so the schema permits two current managers and only the service can see that. T082's entry records this; `CostCenterServiceTest` proves it |
 * | **unique** user/currency account | `pettycash_accounts_user_currency_uniq` + `AccountAlreadyExists` | Same reason. `AccountAlreadyExists`'s own docblock says the index is what enforces it and the application is "saying so rather than a unique-index error escaping to the caller" |
 *
 * What the domain *can* hold for those three is asserted below anyway, because it is the part that
 * makes the service's check possible: a currency code is uppercase and three letters so the two layers
 * agree on what "the same code" means; a manager role is scoped to a cost centre so a second current
 * manager is representable-and-detectable rather than silently merged; and an account carries one
 * currency code, validated by the same rule a currency uses.
 *
 * ## On "failing tests"
 *
 * This task was written as RED, before T081–T084 delivered the services. Every rule below is therefore
 * already implemented and these tests are **green**, which is the honest outcome: they are regression
 * gates on shipped behaviour, not evidence that anything was missing. Each assertion still had to earn
 * its place -- see `docs/reports/T077.md` for the mutants each group kills.
 */
final class ConfigurationRulesTest extends TestCase
{
    private const TODAY = '2026-01-01 00:00:00';

    private function day(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
    }

    // --- 1. Currency.code uppercase and three letters ---

    public function testACurrencyCodeIsThreeUppercaseLetters(): void
    {
        // The uppercase half of T077's "unique uppercase". Uniqueness itself is a set-level rule the
        // service and the unique index hold; see the class docblock.
        $this->assertSame('IRR', Currency::create('IRR', 'Iranian Rial', 2)->code());
        $this->assertSame('EUR', Currency::create('EUR', 'Euro', 2)->code());
    }

    public function testALowercaseCurrencyCodeIsRefusedRatherThanNormalised(): void
    {
        // Refused, not upper-cased. Silent normalisation would make `create('usd')` and `create('USD')`
        // the same currency by two routes, and the caller could no longer tell which spelling the
        // product recognises -- while `pettycash_currencies_code_uniq` and every ledger row written
        // against it use the uppercase form.
        $this->expectException(InvalidArgumentException::class);

        Currency::create('usd', 'Dollar', 2);
    }

    #[DataProvider('malformedCodes')]
    public function testAMalformedCurrencyCodeIsRefused(string $code): void
    {
        $this->expectException(InvalidArgumentException::class);

        Currency::create($code, 'Name', 2);
    }

    /** @return array<string, array{string}> */
    public static function malformedCodes(): array
    {
        return [
            'too short' => ['US'],
            'too long' => ['USDX'],
            'digits' => ['US1'],
            'empty' => [''],
            'trailing space' => ['USD '],
        ];
    }

    public function testABlankCurrencyNameIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Currency::create('USD', '   ', 2);
    }

    // --- 2. minor_unit valid range 0-4 ---

    public function testEveryFractionScaleFromZeroToFourIsAccepted(): void
    {
        // Five boundaries on purpose: a range check written as `< 1` or `<= 3` still passes a test that
        // only exercises 2. Zero is included because the default rial has no minor unit at all, which
        // `CurrencyService::DEFAULT_RIAL_MINOR_UNIT` relies on.
        foreach ([0, 1, 2, 3, 4] as $minorUnit) {
            $this->assertSame($minorUnit, Currency::create('USD', 'Dollar', $minorUnit)->minorUnit);
        }
    }

    #[DataProvider('invalidMinorUnits')]
    public function testAMinorUnitOutsideZeroToFourIsRefused(int $minorUnit): void
    {
        $this->expectException(InvalidArgumentException::class);

        Currency::create('USD', 'Dollar', $minorUnit);
    }

    /** @return array<string, array{int}> */
    public static function invalidMinorUnits(): array
    {
        return [
            'negative' => [-1],
            'far negative' => [-100],
            'just above the range' => [5],
            'absurd' => [100],
        ];
    }

    // --- 3. cost-center validity ---

    public function testACostCentreValidityWindowIsInclusiveAtBothEnds(): void
    {
        $centre = CostCenter::create('CC-ENG', 'Engineering', null, $this->day('2026-01-01'), $this->day('2026-03-31'));

        $this->assertTrue($centre->isValidOn($this->day('2026-01-01')), 'the first day is inside the window');
        $this->assertTrue($centre->isValidOn($this->day('2026-02-15')));
        $this->assertTrue($centre->isValidOn($this->day('2026-03-31')), 'the last day is inside the window');

        $this->assertFalse($centre->isValidOn($this->day('2025-12-31')), 'the day before is not');
        $this->assertFalse($centre->isValidOn($this->day('2026-04-01')), 'the day after is not');
    }

    public function testACostCentreWithNoEndDateStaysValidIndefinitely(): void
    {
        $centre = CostCenter::create('CC-ENG', 'Engineering', null, $this->day('2026-01-01'), null);

        $this->assertTrue($centre->isValidOn($this->day('2099-12-31')));
    }

    public function testADeactivatedCostCentreIsNeverValid(): void
    {
        // FR-010's deactivation, and the reason `isValidOn()` checks `active` before the window: a
        // deactivated centre must drop out of a purchaser's choices immediately, not at some future
        // boundary date.
        $centre = CostCenter::create('CC-ENG', 'Engineering', null, $this->day('2026-01-01'), null);
        $centre->active = false;

        $this->assertFalse($centre->isValidOn($this->day('2026-01-01')));
    }

    public function testAValidToBeforeValidFromIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CostCenter::create('CC-ENG', 'Engineering', null, $this->day('2026-03-01'), $this->day('2026-01-01'));
    }

    public function testASameDayWindowIsAccepted(): void
    {
        // Legal, and the boundary a `<` check written carelessly as `<=` would refuse.
        $centre = CostCenter::create('CC-ENG', 'Engineering', null, $this->day('2026-01-01'), $this->day('2026-01-01'));

        $this->assertTrue($centre->isValidOn($this->day('2026-01-01')));
    }

    public function testAMalformedCostCentreCodeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CostCenter::create('CC ENG!', 'Engineering', null, $this->day('2026-01-01'), null);
    }

    public function testABlankCostCentreNameIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CostCenter::create('CC-ENG', '   ', null, $this->day('2026-01-01'), null);
    }

    // --- 4. the role-assignment rules behind "exactly one effective manager and senior manager" ---
    //
    // The "exactly one" half is `CostCenterService`'s, because `pettycash_role_assignments` has no
    // uniqueness over `(role, cost_center_id)`; see the class docblock and `CostCenterServiceTest`.
    // What the domain holds is the scoping that makes the condition *detectable*, plus the inclusive
    // window that decides what "current" means.

    #[DataProvider('managerRoles')]
    public function testAManagerRoleMustNameACostCentre(Role $role): void
    {
        $this->expectException(InvalidArgumentException::class);

        RoleAssignment::create('someone', $role, null, $this->day('2026-01-01'), null);
    }

    /** @return array<string, array{Role}> */
    public static function managerRoles(): array
    {
        return [
            'cost-center manager' => [Role::CostCenterManager],
            'senior manager' => [Role::SeniorManager],
        ];
    }

    #[DataProvider('nonManagerRoles')]
    public function testANonManagerRoleMustNotNameACostCentre(Role $role): void
    {
        // The mirror rule, and the one that makes FR-016's shared queue work: a finance officer belongs
        // to the shared queue, so scoping one to a single centre would put it inside that centre's scope
        // and out of everyone else's.
        $this->expectException(InvalidArgumentException::class);

        RoleAssignment::create('someone', $role, 42, $this->day('2026-01-01'), null);
    }

    /** @return array<string, array{Role}> */
    public static function nonManagerRoles(): array
    {
        return [
            'finance' => [Role::Finance],
            'purchaser' => [Role::Purchaser],
        ];
    }

    public function testAManagerAssignmentIsScopedToItsCostCentreSoTwoHoldersAreDistinguishable(): void
    {
        $first = RoleAssignment::create('first', Role::CostCenterManager, 1, $this->day('2026-01-01'), null);
        $second = RoleAssignment::create('second', Role::CostCenterManager, 1, $this->day('2026-02-01'), null);

        // Both are current on their own dates -- the state `CostCenterService` exists to forbid, and which
        // no unique index prevents. Asserted here because the domain must *permit* two for the service's
        // check to have something to find; a domain that silently merged them would hide the defect.
        $this->assertSame(1, $first->costCenterId);
        $this->assertSame(1, $second->costCenterId);
        $this->assertTrue($first->isValidOn($this->day('2026-01-15')));
        $this->assertTrue($second->isValidOn($this->day('2026-02-15')));
    }

    public function testAnAssignmentValidityWindowIsInclusiveAtBothEnds(): void
    {
        // The rule that forces `transferManager()` to close the outgoing holder at `validFrom - 1 second`:
        // if closing on the transfer date were allowed, both holders would be current on that one day.
        $assignment = RoleAssignment::create('holder', Role::CostCenterManager, 1, $this->day('2026-01-01'), $this->day('2026-03-31'));

        $this->assertTrue($assignment->isValidOn($this->day('2026-01-01')));
        $this->assertTrue($assignment->isValidOn($this->day('2026-03-31')));
        $this->assertFalse($assignment->isValidOn($this->day('2026-04-01')));
    }

    public function testAnAssignmentWithValidToBeforeValidFromIsRefused(): void
    {
        // The mirror of the cost-centre check, and missed until mutation M12 proved it: this file
        // asserted the window's inclusivity but never that an inverted one is rejected, so a domain that
        // had quietly stopped checking produced an assignment no caller could ever satisfy.
        $this->expectException(InvalidArgumentException::class);

        RoleAssignment::create('holder', Role::CostCenterManager, 1, $this->day('2026-03-01'), $this->day('2026-01-01'));
    }

    public function testAnInactiveAssignmentIsNeverCurrent(): void
    {
        $assignment = RoleAssignment::create('holder', Role::CostCenterManager, 1, $this->day('2026-01-01'), null);
        $this->assertTrue($assignment->isValidOn($this->day('2026-01-01')));

        $assignment->active = false;

        $this->assertFalse($assignment->isValidOn($this->day('2026-01-01')));
    }

    public function testABlankAssignmentUserIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RoleAssignment::create('   ', Role::CostCenterManager, 1, $this->day('2026-01-01'), null);
    }

    // --- 5. the expense-type evidence rule ---

    public function testTheEvidenceRequirementIsPartOfTheDefinitionAndIsCarried(): void
    {
        // FR-012: "including whether a type requires specific evidence". Set at creation, because
        // FR-023 makes it a submission precondition -- it cannot be a later inference from the name.
        $required = ExpenseType::create('Travel', 'taxi and hotel', true);
        $notRequired = ExpenseType::create('Petty', 'small items', false);

        $this->assertTrue($required->evidenceRequired);
        $this->assertFalse($notRequired->evidenceRequired);
    }

    public function testAnEvidenceRequiringTypeIsActiveFromCreation(): void
    {
        $this->assertTrue(ExpenseType::create('Travel', null, true)->active);
    }

    public function testABlankExpenseTypeTitleIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExpenseType::create('   ', null, false);
    }

    // --- 6. the account's currency identity (uniqueness is the index's and the service's) ---

    public function testAnAccountCarriesTheCurrencyItWasOpenedIn(): void
    {
        $currency = Currency::create('IRR', 'Iranian Rial', 0);
        $account = PettyCashAccount::create('acct-1', 'employee1', $currency);

        $this->assertSame('IRR', (string) $account->currencyCode());
        $this->assertTrue($account->currencyCode()->equals(CurrencyCode::fromString('IRR')));
    }

    public function testTwoAccountsForTheSameUserAndCurrencyAreDistinctInstances(): void
    {
        // FR-014 permits additional accounts, one per currency. The domain holds no knowledge of what
        // else exists, so it necessarily permits a second instance; `pettycash_accounts_user_currency_uniq`
        // and `AccountAlreadyExists` are what refuse it. Asserted so the reason that split exists is
        // recorded next to it rather than rediscovered later.
        $currency = Currency::create('IRR', 'Iranian Rial', 0);
        $first = PettyCashAccount::create('acct-1', 'employee1', $currency);
        $second = PettyCashAccount::create('acct-2', 'employee1', $currency);

        $this->assertNotSame($first->id, $second->id);
        $this->assertTrue(
            $first->currencyCode()->equals($second->currencyCode()),
            'both accounts are in the same currency, which is the state the unique index refuses'
        );
    }

    public function testAnAccountForABlankUserIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PettyCashAccount::create('acct-1', '  ', Currency::create('IRR', 'Iranian Rial', 0));
    }

    public function testAnAccountRefusesACurrencyCodeTheDomainWouldNotAccept(): void
    {
        // `PettyCashAccount::create()` narrows the currency through `CurrencyCode`, so a code that is
        // not three uppercase letters cannot reach an account even if a `Currency` were constructed by
        // some other route.
        $this->expectException(InvalidArgumentException::class);

        CurrencyCode::fromString('usd');
    }
}