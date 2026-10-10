<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Account;

use DateTimeImmutable;
use OCA\PettyCash\Domain\Account\AccountMovement;
use OCA\PettyCash\Domain\Account\PettyCashAccount;
use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Enum\MovementType;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAccountMovementRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAccountRepository;
use PHPUnit\Framework\TestCase;

/**
 * T060: reconciliation per data-model.md — an account's stored balance must equal
 * its latest movement's `balance_after_minor`.
 */
final class AccountReconciliationTest extends TestCase
{
    private function account(string $publicId, int $balanceMinor): PettyCashAccount
    {
        $account = PettyCashAccount::create($publicId, 'user-1', Currency::create('IRR', 'Rial', 0));
        $account->balanceMinor = $balanceMinor;
        return $account;
    }

    private function movement(string $accountPublicId, int $balanceAfterMinor, int $dbId): AccountMovement
    {
        $movement = new AccountMovement(
            'mv-' . $dbId,
            MovementType::Charge,
            Money::of(100, CurrencyCode::fromString('IRR')),
            Money::of($balanceAfterMinor, CurrencyCode::fromString('IRR')),
            'probe',
            'cmd-' . $dbId,
            null,
            new DateTimeImmutable('2026-10-01T00:00:00Z'),
            $dbId,
            'mvpub-' . $dbId,
            $accountPublicId,
        );
        return $movement;
    }

    public function testMatchingBalancesReportNothing(): void
    {
        $movements = new InMemoryAccountMovementRepository();
        $accounts = new InMemoryAccountRepository($movements);
        $accounts->save($this->account('acct-1', 500));
        $movements->save($this->movement('acct-1', 500, 1));

        self::assertSame([], $accounts->findBalanceMismatches());
    }

    public function testMismatchedBalanceIsReported(): void
    {
        $movements = new InMemoryAccountMovementRepository();
        $accounts = new InMemoryAccountRepository($movements);
        $accounts->save($this->account('acct-1', 500));
        $movements->save($this->movement('acct-1', 500, 1));
        $accounts->save($this->account('acct-2', 700));
        $movements->save($this->movement('acct-2', 600, 2));

        self::assertSame(['acct-2'], $accounts->findBalanceMismatches());
    }

    public function testLatestMovementWinsAndMovementlessAccountsAreSkipped(): void
    {
        $movements = new InMemoryAccountMovementRepository();
        $accounts = new InMemoryAccountRepository($movements);
        $accounts->save($this->account('acct-1', 900));
        $movements->save($this->movement('acct-1', 500, 1));
        $movements->save($this->movement('acct-1', 900, 2));
        $accounts->save($this->account('acct-lonely', 123));

        self::assertSame([], $accounts->findBalanceMismatches());
    }

    public function testWithoutMovementsSourceEverythingIsConsistent(): void
    {
        $accounts = new InMemoryAccountRepository();
        $accounts->save($this->account('acct-1', 999));

        self::assertSame([], $accounts->findBalanceMismatches());
    }
}
