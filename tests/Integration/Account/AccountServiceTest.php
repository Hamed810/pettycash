<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Account;

use OCA\PettyCash\Application\Account\AccountPayloadFormatter;
use OCA\PettyCash\Application\Account\AccountService;
use OCA\PettyCash\Domain\Account\PettyCashAccount;
use OCA\PettyCash\Domain\Account\Repository\AccountMovementRepository;
use OCA\PettyCash\Domain\Account\Repository\AccountRepository;
use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Domain\Configuration\Repository\CurrencyRepository;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Shared\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * Covers the account read projection that the purchaser's account picker depends on.
 *
 * Scope note: this exercises `AccountService`, not the SQL layer. The PHPUnit bootstrap loads only
 * the Composer autoloader and never Nextcloud's `lib/base.php`, so no test in this suite can reach
 * the real database — T236 is the open task that adds that harness, and duplicating it here would
 * be duplicate work. The live end-to-end check of the same path is the contract suite.
 */
final class AccountServiceTest extends TestCase
{
    private $container;
    private AccountRepository $accounts;
    private AccountMovementRepository $movements;
    private CurrencyRepository $currencies;
    private AccountService $service;
    private string $userUid;
    private string $currencyCode;

    protected function setUp(): void
    {
        $this->container = \TestContainerAccessor::getInstance();
        $this->accounts = $this->container->query(AccountRepository::class);
        $currencies = $this->container->query(CurrencyRepository::class);
        $this->currencies = $currencies instanceof CurrencyRepository ? $currencies : new \OCA\PettyCash\Tests\TestDoubles\InMemoryCurrencyRepository();
        $movements = $this->container->query(AccountMovementRepository::class);
        $this->movements = $movements instanceof AccountMovementRepository
            ? $movements
            : new \OCA\PettyCash\Tests\TestDoubles\InMemoryAccountMovementRepository();
        // The movement repository is a second constructor dependency because `listMovements()` is
        // the ledger read; passing only the account repository would make every test in this file die
        // in `setUp()` rather than on a behaviour. The four the writes need are passed as
        // containers too, so the reads this file exercises are not standing on a stub that happens
        // to satisfy a constructor.
        $this->service = new AccountService(
            $this->accounts,
            $this->movements,
            $this->container->query(CurrencyRepository::class),
            $this->container->query(RoleAssignmentRepository::class),
            $this->container->query(\OCA\PettyCash\Application\Command\IdempotencyService::class),
            $this->container->query(\OCA\PettyCash\Application\Port\CurrentUser::class),
            new AccountPayloadFormatter()
        );
        $this->userUid = 'account-service-test-user';
        // minorUnit 3 is deliberately a scale no hardcoded currency table would guess, so a
        // regression to a built-in IRR/USD/EUR/GBP lookup is detected rather than tolerated.
        $this->currencyCode = 'TST';
    }

    private function seedAccount(string $userUid, int $minorUnit, int $balanceMinor): PettyCashAccount
    {
        $currency = $this->currencies->findByCode($this->currencyCode);
        if ($currency === null) {
            $currency = Currency::create($this->currencyCode, 'Account Service Test Currency', $minorUnit);
            $this->currencies->save($currency);
            $currency = $this->currencies->findByCode($this->currencyCode);
            $this->assertNotNull($currency, 'the test currency must be persistable');
        }

        $account = PettyCashAccount::create((string)Uuid::random(), $userUid, $currency);
        $account->balanceMinor = $balanceMinor;
        $this->accounts->save($account);

        return $account;
    }

    public function testListForUserReturnsTheAccountsHeldForThatUser(): void
    {
        $account = $this->seedAccount($this->userUid, 3, 12345);

        $page = $this->service->listForUser($this->userUid);

        $this->assertCount(1, $page['items'], 'the seeded account must be returned');
        $this->assertSame(1, $page['total']);
        $this->assertSame(1, $page['page']);
        $this->assertSame($account->id, $page['items'][0]['id'], 'an account must be addressable by its public UUID');
        $this->assertSame($this->userUid, $page['items'][0]['userId']);
        $this->assertSame($this->currencyCode, $page['items'][0]['balance']['currency']);
        $this->assertSame(12345, $page['items'][0]['balance']['amountMinor']);
        $this->assertTrue($page['items'][0]['active']);
    }

    public function testMinorUnitComesFromTheConfiguredCurrencyRatherThanAGuess(): void
    {
        $this->seedAccount($this->userUid, 3, 1);

        $page = $this->service->listForUser($this->userUid);

        $this->assertSame(
            3,
            $page['items'][0]['balance']['minorUnit'],
            'the fraction digits must be read from the configured Currency aggregate, not from a built-in table',
        );
    }

    public function testOnlyActiveAccountsAreOfferedForSelection(): void
    {
        $active = $this->seedAccount($this->userUid, 2, 500);
        $inactive = $this->seedAccount($this->userUid, 2, 500);
        $inactive->active = false;
        $this->accounts->save($inactive);

        $ids = array_column($this->service->listForUser($this->userUid)['items'], 'id');

        $this->assertContains($active->id, $ids);
        $this->assertNotContains(
            $inactive->id,
            $ids,
            'an inactive account must not be offered for a new sheet',
        );
    }

    public function testAUserWithNoAccountsGetsAnEmptyListRatherThanAnError(): void
    {
        $page = $this->service->listForUser('a-user-who-has-no-pettycash-account');

        $this->assertSame([], $page['items']);
        $this->assertSame(0, $page['total'], 'having no account is a normal state, not an error');
    }

    public function testOneUserNeverSeesAnotherUsersAccounts(): void
    {
        $this->seedAccount($this->userUid, 2, 10);
        $other = $this->seedAccount('another-account-service-test-user', 2, 20);

        $ids = array_column($this->service->listForUser($this->userUid)['items'], 'id');

        $this->assertNotContains(
            $other->id,
            $ids,
            "the read path is scoped to the caller and must not leak another user's account",
        );
    }

    public function testPagingIsBoundedAndValidated(): void
    {
        $this->seedAccount($this->userUid, 2, 1);

        $page = $this->service->listForUser($this->userUid, 1, 100);

        $this->assertGreaterThanOrEqual(1, $page['total']);
        $this->assertCount(
            $page['total'],
            $page['items'],
            'a pageSize at the documented maximum of 100 must return the whole result set',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->service->listForUser($this->userUid, 1, 101);
    }
}
