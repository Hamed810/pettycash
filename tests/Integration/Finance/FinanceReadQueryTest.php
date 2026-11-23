<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Finance;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\PettyCash\Application\DataIntegrity\UnreadableRecordData;
use OCA\PettyCash\Application\Finance\FinanceLedgerService;
use OCA\PettyCash\Application\Finance\FinanceRead;
use OCA\PettyCash\Application\Finance\HolderDirectoryService;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Account\AccountMovement;
use OCA\PettyCash\Domain\Account\PettyCashAccount;
use OCA\PettyCash\Domain\Account\Repository\AccountMovementRepository;
use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Enum\MovementType;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAccountMovementRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAccountRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryUserDirectory;
use PHPUnit\Framework\TestCase;

/**
 * T006/T007/T008 holder-directory and holder-account service cases, driven over in-memory doubles.
 *
 * ## What this file proves and, deliberately, what it does not
 *
 * The PHPUnit bootstrap wires a container of `InMemory*` doubles and never loads Nextcloud's
 * `lib/base.php`, so no test in this file reaches the real database. The services under test here are
 * `HolderDirectoryService` and `FinanceLedgerService`; they depend on the `FinanceRead` port, and each
 * test supplies an in-memory implementation of that port (this file's `FakeFinanceRead`), never the
 * real `FinanceReadQuery`.
 *
 * That is the point of the separation: the *service logic* — holder status derivation, display-name
 * resolution that never adds or removes a holder, population-wide search before pagination, the
 * `null` not-found result indistinguishable from an unknown uid, and the single batched activity read
 * instead of N+1 — is proven here over controllable data. The *real SQL* of `FinanceReadQuery` is
 * proven separately against MariaDB by `FinanceReadQueryRoundTripTest`, which runs the real query
 * through `tests/Integration/Persistence/roundtrip-runner.php`. A double cannot lie about SQL; this
 * file does not pretend to.
 */
final class FinanceReadQueryTest extends TestCase
{
    private UserDirectory $directory;
    private FakeFinanceRead $read;
    private Currency $currency;

    protected function setUp(): void
    {
        $this->directory = new InMemoryUserDirectory();
        $this->read = new FakeFinanceRead();
        // minorUnit 3 is deliberately a scale no hardcoded currency table would guess.
        $this->currency = Currency::create('TST', 'Test Finance Currency', 3);
    }

    // ===== T006 — holder directory, no search =====

    public function testHolderListedOnceWithCorrectCountsAndStatus(): void
    {
        $this->read->holderCountValue = 3;
        $this->directory->addUser('alpha', 'Alpha Person');
        $this->directory->addUser('bravo', 'Bravo Person');
        $this->read->holders = [
            ['userId' => 'alpha', 'accountCount' => 2, 'activeAccountCount' => 2], // ACTIVE
            ['userId' => 'bravo', 'accountCount' => 2, 'activeAccountCount' => 0], // INACTIVE
            ['userId' => 'charlie', 'accountCount' => 2, 'activeAccountCount' => 1], // MIXED
        ];

        $page = $this->directoryService()->page(null, 1, 10);

        $this->assertSame(3, $page['total']);
        $this->assertCount(3, $page['items']);
        $this->assertSame('alpha', $page['items'][0]['userId']);
        $this->assertSame(2, $page['items'][0]['accountCount']);
        $this->assertSame(2, $page['items'][0]['activeAccountCount']);
        $this->assertSame(HolderDirectoryService::STATUS_ACTIVE, $page['items'][0]['status']);
        $this->assertSame(HolderDirectoryService::STATUS_INACTIVE, $page['items'][1]['status']);
        $this->assertSame(HolderDirectoryService::STATUS_MIXED, $page['items'][2]['status']);
    }

    public function testAccountLessUserIsAbsentFromTheDirectory(): void
    {
        // A directory user with no account can never enter the holder population.
        $this->directory->addUser('has-no-account', 'No Account Person');
        $this->read->holderCountValue = 1;
        $this->read->holders = [
            ['userId' => 'alpha', 'accountCount' => 1, 'activeAccountCount' => 1],
        ];

        $page = $this->directoryService()->page(null, 1, 25);

        $this->assertSame(1, $page['total']);
        $this->assertCount(1, $page['items']);
        $this->assertSame('alpha', $page['items'][0]['userId']);
    }

    public function testNoSearchTotalIsTheDistinctHolderCount(): void
    {
        $this->read->holderCountValue = 7;
        $this->read->holders = [
            ['userId' => 'alpha', 'accountCount' => 1, 'activeAccountCount' => 1],
        ];

        $page = $this->directoryService()->page(null, 2, 10);

        $this->assertSame(7, $page['total'], 'total must be the authoritative distinct-holder count, not the loaded page length');
        $this->assertSame(2, $page['page']);
        $this->assertSame(10, $page['pageSize']);
    }

    public function testDisplayNameResolutionNeverAddsOrRemovesAHolder(): void
    {
        $this->directory->addUser('alpha', 'Alpha Person');
        $this->read->holderCountValue = 1;
        $this->read->holders = [
            ['userId' => 'alpha', 'accountCount' => 1, 'activeAccountCount' => 1],
        ];

        $page = $this->directoryService()->page(null, 1, 25);

        $this->assertSame(1, $page['total']);
        $this->assertCount(1, $page['items']);
        $this->assertSame('Alpha Person', $page['items'][0]['displayName']);
    }

    public function testDisplayNameFallsBackToTheUidWhenTheDirectoryHasNoName(): void
    {
        // `InMemoryUserDirectory` returns the uid for an unknown user, so the fallback is free.
        $this->read->holderCountValue = 1;
        $this->read->holders = [
            ['userId' => 'ghost', 'accountCount' => 1, 'activeAccountCount' => 1],
        ];

        $page = $this->directoryService()->page(null, 1, 25);

        $this->assertSame('ghost', $page['items'][0]['displayName']);
    }

    public function testStatusIsDerivedNeverPersisted(): void
    {
        $this->read->holderCountValue = 3;
        $this->read->holders = [
            ['userId' => 'a', 'accountCount' => 1, 'activeAccountCount' => 1],
            ['userId' => 'b', 'accountCount' => 3, 'activeAccountCount' => 0],
            ['userId' => 'c', 'accountCount' => 3, 'activeAccountCount' => 2],
        ];

        $page = $this->directoryService()->page(null, 1, 25);

        $statuses = array_column($page['items'], 'status');
        $this->assertSame([
            HolderDirectoryService::STATUS_ACTIVE,
            HolderDirectoryService::STATUS_INACTIVE,
            HolderDirectoryService::STATUS_MIXED,
        ], $statuses);
    }

    public function testInvalidPageAndPageSizeAreRejected(): void
    {
        $service = $this->directoryService();
        $this->expectException(InvalidArgumentException::class);
        $service->page(null, 0, 25);
    }

    public function testPageSizeOutsideOneToHundredIsRejected(): void
    {
        $service = $this->directoryService();
        try {
            $service->page(null, 1, 0);
            $this->fail('pageSize 0 must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        $service->page(null, 1, 101);
    }

    // ===== T007 — holder search =====

    public function testSearchMatchesDisplayNameCaseInsensitively(): void
    {
        $this->directory->addUser('alpha', 'Alpha Person');
        $this->read->holders = [
            ['userId' => 'alpha', 'accountCount' => 1, 'activeAccountCount' => 1],
        ];

        $page = $this->directoryService()->page('alPHA pErSOn', 1, 25);

        $this->assertSame(1, $page['total']);
        $this->assertSame('alpha', $page['items'][0]['userId']);
    }

    public function testSearchMatchesUidCaseInsensitively(): void
    {
        $this->read->holders = [
            ['userId' => 'alpha', 'accountCount' => 1, 'activeAccountCount' => 1],
        ];

        $page = $this->directoryService()->page('ALPHA', 1, 25);

        $this->assertSame(1, $page['total']);
        $this->assertSame('alpha', $page['items'][0]['userId']);
    }

    public function testSearchTermIsTrimmed(): void
    {
        $this->directory->addUser('alpha', 'Alpha Person');
        $this->read->holders = [
            ['userId' => 'alpha', 'accountCount' => 1, 'activeAccountCount' => 1],
        ];

        $page = $this->directoryService()->page("  alpha  ", 1, 25);

        $this->assertSame(1, $page['total']);
    }

    public function testSearchFindsHoldersBeyondTheFirstPage(): void
    {
        // The match lives far past the first page's worth of holders and must still be found: the
        // search runs over the complete population before pagination, never over only the loaded page.
        $this->directory->addUser('needle', 'The Needle Finder');
        $holders = [];
        for ($i = 0; $i < 20; $i++) {
            $holders[] = ['userId' => 'filler-' . $i, 'accountCount' => 1, 'activeAccountCount' => 1];
        }
        $holders[] = ['userId' => 'needle', 'accountCount' => 1, 'activeAccountCount' => 0];
        $this->read->holders = $holders;

        $page = $this->directoryService()->page('needl', 1, 10);

        // One match in the whole population, reported even though it sorts after the first 10 holders.
        $this->assertSame(1, $page['total']);
        $this->assertCount(1, $page['items']);
        $this->assertSame('needle', $page['items'][0]['userId']);
    }

    public function testSearchFiltersTheFullPopulationBeforePagination(): void
    {
        // Two matching holders, separated by non-matching ones in uid order; total must be the
        // filtered count (2), not the page length (1 on the first page of size 1).
        $this->directory->addUser('abc', 'Alpha C');
        $this->directory->addUser('axy', 'Alph Y');
        $this->read->holders = [
            ['userId' => 'abc', 'accountCount' => 1, 'activeAccountCount' => 1],
            ['userId' => 'bcd', 'accountCount' => 1, 'activeAccountCount' => 1],
            ['userId' => 'axy', 'accountCount' => 1, 'activeAccountCount' => 1],
        ];

        $page = $this->directoryService()->page('alph', 1, 1);

        $this->assertSame(2, $page['total'], 'total must be the full filtered population count');
        $this->assertCount(1, $page['items'], 'page slice still applies');
        $this->assertSame('abc', $page['items'][0]['userId']);

        $page2 = $this->directoryService()->page('alph', 2, 1);
        $this->assertSame('axy', $page2['items'][0]['userId']);
    }

    public function testAccountLessUserNeverReturnedForAnySearchTerm(): void
    {
        // The account-less user has a directory entry whose display name matches every term below,
        // and must never surface because it is not part of the account-owning population.
        $this->directory->addUser('ghost', 'ghost ghost ghost');
        $this->read->holders = [
            ['userId' => 'alpha', 'accountCount' => 1, 'activeAccountCount' => 1],
        ];

        foreach (['ghost', 'GHOST', 'gh', ' ', null] as $term) {
            $page = $this->directoryService()->page($term, 1, 25);
            $uids = array_column($page['items'], 'userId');
            $this->assertNotContains('ghost', $uids, "account-less user must never appear for term '{$term}'");
        }
    }

    public function testSearchOverSixtyFourCharactersIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->directoryService()->page(str_repeat('x', 65), 1, 25);
    }

    public function testSearchNeverCallsHolderPage(): void
    {
        // The search path must read the whole population (allHolders), never the loaded page
        // (holderPage), so a match past the page boundary is never missed.
        $this->read->holders = [
            ['userId' => 'alpha', 'accountCount' => 1, 'activeAccountCount' => 1],
        ];
        $this->directoryService()->page('alpha', 1, 25);
        $this->assertSame(0, $this->read->holderPageCalls, 'search must not page the database results');
        $this->assertSame(1, $this->read->allHoldersCalls);
    }

    public function testDirectoryIsOnlyAskedForDisplayNamesOfAccountOwningUids(): void
    {
        // The holder search must never enumerate or probe the Nextcloud user directory: no `exists()`
        // call (that would be an enumeration/ownership probe) and `displayName` only for the
        // account-owning uids that are actually in the holder population.
        $directory = new RecordingUserDirectory(['alpha' => 'Alpha Person', 'ghost' => 'Ghost']);
        $this->directory = $directory;
        $this->read->holders = [
            ['userId' => 'alpha', 'accountCount' => 1, 'activeAccountCount' => 1],
        ];

        $this->directoryService()->page('ALPHA', 1, 25);

        $this->assertSame([], $directory->existsCalls, 'the holder read must never ask the directory whether a user exists');
        $this->assertSame(['alpha'], $directory->nameCalls, 'display names are resolved only for account-owning uids, never for the search term');
    }

    // ===== T008 — holder-account finance read =====

    private function ledgerService(
        InMemoryAccountRepository $accounts,
        ?AccountMovementRepository $movements = null
    ): FinanceLedgerService {
        return new FinanceLedgerService(
            $accounts,
            $movements ?? new InMemoryAccountMovementRepository(),
            $this->read
        );
    }

    private function seedAccount(
        InMemoryAccountRepository $accounts,
        string $uid,
        Currency $currency,
        int $balanceMinor,
        bool $active,
        int $dbId,
        int $version = 0
    ): PettyCashAccount {
        // The service orders a holder's accounts by currency then public id, so the seeded public id
        // must be a sortable surrogate of `$dbId` for the ordering assertions to be deterministic.
        $account = PettyCashAccount::create(sprintf('acc-%06d', $dbId), $uid, $currency);
        $account->balanceMinor = $balanceMinor;
        $account->active = $active;
        $account->lockVersion = $version;
        $account->dbId = $dbId;
        $accounts->save($account);
        return $account;
    }

    public function testAccountsForHolderIncludesActiveAndInactive(): void
    {
        $accounts = new InMemoryAccountRepository();
        $this->seedAccount($accounts, 'alice', $this->currency, 100, true, 1);
        $this->seedAccount($accounts, 'alice', $this->currency, 200, false, 2);
        $this->read->activity = [];

        $result = $this->ledgerService($accounts)->accountsForHolder('alice', 1, 25);

        $this->assertNotNull($result);
        $this->assertSame(2, $result['total']);
        $this->assertSame([true, false], array_column($result['items'], 'active'));
    }

    public function testAccountProjectionCarriesTheApprovedFields(): void
    {
        $accounts = new InMemoryAccountRepository();
        $acct = $this->seedAccount($accounts, 'alice', $this->currency, 12345, true, 7, 3);
        $this->read->activity = [
            7 => ['movementCount' => 4, 'lastMovementAt' => new DateTimeImmutable('2024-05-01 08:30:00')],
        ];

        $item = $this->ledgerService($accounts)->accountsForHolder('alice', 1, 25)['items'][0];

        $this->assertSame($acct->id, $item['id']);
        $this->assertSame('alice', $item['userId']);
        $this->assertSame('TST', $item['currency']);
        $this->assertSame(3, $item['minorUnit']);
        $this->assertSame(12345, $item['balance']->minorUnits());
        $this->assertTrue($item['active']);
        $this->assertSame(3, $item['version']);
        $this->assertSame(4, $item['movementCount']);
        $this->assertSame('2024-05-01 08:30:00', $item['lastMovementAt']->format('Y-m-d H:i:s'));
    }

    public function testBalanceIsTheAuthoritativeStoredBalance(): void
    {
        $accounts = new InMemoryAccountRepository();
        $this->seedAccount($accounts, 'alice', $this->currency, 9876, true, 1);

        $item = $this->ledgerService($accounts)->accountsForHolder('alice', 1, 25)['items'][0];

        $this->assertSame(9876, $item['balance']->minorUnits());
        $this->assertTrue($item['balance']->currency()->equals(CurrencyCode::fromString('TST')));
    }

    public function testUnusedAccountHasLastMovementAtNullAndZeroCount(): void
    {
        $accounts = new InMemoryAccountRepository();
        $this->seedAccount($accounts, 'alice', $this->currency, 0, true, 5);
        $this->read->activity = []; // account 5 has no movements

        $item = $this->ledgerService($accounts)->accountsForHolder('alice', 1, 25)['items'][0];

        $this->assertSame(0, $item['movementCount']);
        $this->assertNull($item['lastMovementAt']);
    }

    public function testActivityReadIsBatchedNotNPlusOne(): void
    {
        $accounts = new InMemoryAccountRepository();
        $a = $this->seedAccount($accounts, 'alice', $this->currency, 100, true, 11);
        $b = $this->seedAccount($accounts, 'alice', $this->currency, 200, true, 12);
        $c = $this->seedAccount($accounts, 'alice', $this->currency, 300, true, 13);
        $this->read->activity = [
            11 => ['movementCount' => 2, 'lastMovementAt' => new DateTimeImmutable('2024-01-01 00:00:00')],
            12 => ['movementCount' => 1, 'lastMovementAt' => new DateTimeImmutable('2024-02-01 00:00:00')],
            13 => ['movementCount' => 0, 'lastMovementAt' => null],
        ];

        $result = $this->ledgerService($accounts)->accountsForHolder('alice', 1, 25);

        $this->assertSame(1, $this->read->activityCalls, 'one grouped query must answer the whole page, never N+1');
        $expected = [$a->dbId, $b->dbId, $c->dbId];
        sort($expected);
        $actual = $this->read->lastActivityInput;
        sort($actual);
        $this->assertSame($expected, $actual, 'the batched read must receive every page account db id');
        $this->assertSame([2, 1, 0], array_column($result['items'], 'movementCount'));
    }

    public function testAccountsAreOrderedByCurrencyThenPublicId(): void
    {
        $accounts = new InMemoryAccountRepository();
        $eur = Currency::create('EUR', 'Euro', 2);
        // Seed out of order on purpose.
        $this->seedAccount($accounts, 'alice', $this->currency, 100, true, 1, 0); // TST
        $this->seedAccount($accounts, 'alice', $eur, 50, true, 2, 0);               // EUR
        $this->read->activity = [];

        $items = $this->ledgerService($accounts)->accountsForHolder('alice', 1, 25)['items'];

        $currencies = array_column($items, 'currency');
        $this->assertSame(['EUR', 'TST'], $currencies, 'deterministic page order: currency, then public id');
    }

    public function testUnknownUidAndAccountlessUidBothReturnNull(): void
    {
        $accounts = new InMemoryAccountRepository();

        $this->assertNull(
            $this->ledgerService($accounts)->accountsForHolder('nobody-here', 1, 25),
            'an unknown uid must be indistinguishable from an account-less owner (404 signal, no oracle)'
        );
        $this->assertNull(
            $this->ledgerService($accounts)->accountsForHolder('another-ghost', 1, 25)
        );
    }

    public function testUidThatOwnsNoPetcashAccountReturnsNullEvenWhenItExistsInTheDirectory(): void
    {
        $accounts = new InMemoryAccountRepository();
        $this->directory->addUser('real-user', 'Real User');

        $this->assertNull(
            $this->ledgerService($accounts)->accountsForHolder('real-user', 1, 25),
            'the holder-account read must not be an account-ownership oracle'
        );
    }

    public function testLedgerPaginationSlicesTheOwnedSet(): void
    {
        $accounts = new InMemoryAccountRepository();
        for ($i = 1; $i <= 3; $i++) {
            $this->seedAccount($accounts, 'alice', $this->currency, $i * 100, true, $i);
        }
        $this->read->activity = [];

        $page1 = $this->ledgerService($accounts)->accountsForHolder('alice', 1, 2);
        $page2 = $this->ledgerService($accounts)->accountsForHolder('alice', 2, 2);

        $this->assertSame(3, $page1['total']);
        $this->assertCount(2, $page1['items']);
        $this->assertCount(1, $page2['items']);
        $this->assertSame(100, $page1['items'][0]['balance']->minorUnits());
        $this->assertSame(200, $page1['items'][1]['balance']->minorUnits());
        $this->assertSame(300, $page2['items'][0]['balance']->minorUnits());
    }

    public function testLedgerRejectsInvalidPageAndPageSize(): void
    {
        $accounts = new InMemoryAccountRepository();
        $service = $this->ledgerService($accounts);
        $this->expectException(InvalidArgumentException::class);
        $service->accountsForHolder('alice', 0, 25);
    }

    public function testPurchaserActiveReadStillFiltersInactiveAccounts(): void
    {
        // T008 deliberately did not touch the purchaser-owned `findActiveByUserUid` read. Prove
        // through the in-memory double that it still excludes inactive accounts.
        $accounts = new InMemoryAccountRepository();
        $this->seedAccount($accounts, 'alice', $this->currency, 100, true, 1);
        $this->seedAccount($accounts, 'alice', $this->currency, 200, false, 2);

        $active = $accounts->findActiveByUserUid('alice');

        $this->assertCount(1, $active);
        $this->assertTrue($active[0]->active);
    }

    // ===== T011/T012/T013 — authoritative ledger page =====

    private function seededLedgerAccount(
        InMemoryAccountRepository $accounts,
        int $balanceMinor = 0,
        int $dbId = 21
    ): PettyCashAccount {
        return $this->seedAccount($accounts, 'alice', $this->currency, $balanceMinor, true, $dbId, 2);
    }

    private function chargeMovement(
        string $id,
        int $amountMinor,
        int $balanceAfterMinor,
        string $occurredAt,
        string $description = 'Monthly funding',
        int $dbId = 1
    ): AccountMovement {
        $currency = CurrencyCode::fromString('TST');

        return new AccountMovement(
            $id,
            MovementType::Charge,
            Money::of($amountMinor, $currency),
            Money::of($balanceAfterMinor, $currency),
            $description,
            'cmd-' . $id,
            null,
            new DateTimeImmutable($occurredAt),
            $dbId
        );
    }

    private function consumptionMovement(
        string $id,
        int $amountMinor,
        int $balanceAfterMinor,
        string $occurredAt,
        string $revisionPublicId,
        int $dbId = 1
    ): AccountMovement {
        $currency = CurrencyCode::fromString('TST');

        return new AccountMovement(
            $id,
            MovementType::Consumption,
            Money::of($amountMinor, $currency),
            Money::of($balanceAfterMinor, $currency),
            'Expense consumption',
            'cmd-' . $id,
            $revisionPublicId,
            new DateTimeImmutable($occurredAt),
            $dbId,
            null,
            'acc-000021',
            'decision-' . $id
        );
    }

    public function testChargeLedgerRowCarriesOnlyTheRechargeSide(): void
    {
        $accounts = new InMemoryAccountRepository();
        $account = $this->seededLedgerAccount($accounts, 500000);
        $movements = new FakeLedgerMovements();
        $movements->total = 1;
        $movements->page = [
            $this->chargeMovement('mov-charge', 500000, 500000, '2026-11-10 09:30:00', 'Monthly funding'),
        ];

        $page = $this->ledgerService($accounts, $movements)->ledger($account->id, 1, 25);

        $this->assertNotNull($page);
        $row = $page['items'][0];
        $this->assertSame('CHARGE', $row['type']);
        $this->assertSame(500000, $row['recharge']->minorUnits());
        $this->assertNull($row['expense'], 'a charge must not populate the expense side');
        $this->assertNull($row['sheet']);
        $this->assertNull($row['approved'], 'a charge has no approved/total pair');
        $this->assertNull($row['sourceRevisionId']);
        $this->assertSame('Monthly funding', $row['description'], 'the charge description is the stored movement description');
    }

    public function testConsumptionLedgerRowResolvesCostSheetTitleReferenceAndCounts(): void
    {
        $accounts = new InMemoryAccountRepository();
        $account = $this->seededLedgerAccount($accounts, 150000);
        $movements = new FakeLedgerMovements();
        $movements->total = 1;
        $movements->page = [
            $this->consumptionMovement('mov-consume', 25000, 150000, '2026-11-15 10:00:00', 'rev-1'),
        ];
        $this->read->ledgerContext = [
            'rev-1' => ['sheetId' => 'sheet-public-uuid', 'sheetTitle' => 'October travel'],
        ];
        $this->read->ledgerCounts = [
            'sheet-public-uuid' => ['approvedCount' => 2, 'totalCount' => 3],
        ];

        $row = $this->ledgerService($accounts, $movements)->ledger($account->id, 1, 25)['items'][0];

        $this->assertSame('CONSUMPTION', $row['type']);
        $this->assertSame(25000, $row['expense']->minorUnits());
        $this->assertNull($row['recharge'], 'a consumption must not populate the recharge side');
        $this->assertSame('October travel', $row['description'], 'the Cost Sheet title is the approved consumption presentation');
        $this->assertSame('sheet-public-uuid', $row['sheet']['id']);
        $this->assertSame('sheet-public-uuid', $row['sheet']['reference'], 'the public UUID is the authoritative reference');
        $this->assertSame('October travel', $row['sheet']['title']);
        $this->assertSame(['approvedCount' => 2, 'totalCount' => 3], $row['approved']);
        $this->assertArrayNotHasKey('rejectedCount', $row, 'the ledger row has no rejected-count field');
        $this->assertSame('rev-1', $row['sourceRevisionId']);
    }

    public function testEveryRowPopulatesExactlyOneMonetarySide(): void
    {
        $accounts = new InMemoryAccountRepository();
        $account = $this->seededLedgerAccount($accounts);
        $movements = new FakeLedgerMovements();
        $movements->total = 2;
        $movements->page = [
            $this->chargeMovement('mov-charge', 500000, 500000, '2026-11-10 09:30:00'),
            $this->consumptionMovement('mov-consume', 25000, 475000, '2026-11-15 10:00:00', 'rev-1'),
        ];
        $this->read->ledgerContext = ['rev-1' => ['sheetId' => 'sheet-1', 'sheetTitle' => 'Travel']];
        $this->read->ledgerCounts = ['sheet-1' => ['approvedCount' => 1, 'totalCount' => 1]];

        $items = $this->ledgerService($accounts, $movements)->ledger($account->id, 1, 25)['items'];

        foreach ($items as $row) {
            $sides = (int) ($row['recharge'] !== null) + (int) ($row['expense'] !== null);
            $this->assertSame(1, $sides, "row {$row['id']} must carry exactly one monetary side");
        }
    }

    public function testLedgerDateIsThePersistedMovementTimestampNotTheSheetDate(): void
    {
        $accounts = new InMemoryAccountRepository();
        $account = $this->seededLedgerAccount($accounts);
        $movements = new FakeLedgerMovements();
        $movements->total = 1;
        $movements->page = [
            $this->consumptionMovement('mov-consume', 25000, 0, '2024-02-03 04:05:06', 'rev-1'),
        ];
        $this->read->ledgerContext = ['rev-1' => ['sheetId' => 'sheet-1', 'sheetTitle' => 'Travel']];
        $this->read->ledgerCounts = ['sheet-1' => ['approvedCount' => 0, 'totalCount' => 1]];

        $row = $this->ledgerService($accounts, $movements)->ledger($account->id, 1, 25)['items'][0];

        $this->assertSame('2024-02-03 04:05:06', $row['occurredAt']->format('Y-m-d H:i:s'));
    }

    public function testLedgerPassesPersistedNegativeBalanceThroughUnchanged(): void
    {
        $accounts = new InMemoryAccountRepository();
        $account = $this->seededLedgerAccount($accounts, -90000);
        $movements = new FakeLedgerMovements();
        $movements->total = 1;
        $movements->page = [
            $this->consumptionMovement('mov-consume', 90000, -90000, '2024-02-03 04:05:06', 'rev-1'),
        ];
        $this->read->ledgerContext = ['rev-1' => ['sheetId' => 'sheet-1', 'sheetTitle' => 'Travel']];
        $this->read->ledgerCounts = ['sheet-1' => ['approvedCount' => 1, 'totalCount' => 1]];

        $row = $this->ledgerService($accounts, $movements)->ledger($account->id, 1, 25)['items'][0];

        $this->assertSame(-90000, $row['balanceAfter']->minorUnits(), 'a negative authoritative balance must not be clamped or recomputed');
    }

    public function testLedgerPaginationMetadataUsesTheIndependentCount(): void
    {
        $accounts = new InMemoryAccountRepository();
        $account = $this->seededLedgerAccount($accounts);
        $movements = new FakeLedgerMovements();
        $movements->total = 6; // the whole population
        $movements->page = [
            $this->chargeMovement('mov-a', 100, 100, '2024-01-02 00:00:00', 'a', 2),
            $this->chargeMovement('mov-b', 100, 200, '2024-01-01 00:00:00', 'b', 1),
        ];

        $page = $this->ledgerService($accounts, $movements)->ledger($account->id, 2, 2);

        $this->assertSame(2, $page['page']);
        $this->assertSame(2, $page['pageSize']);
        $this->assertSame(6, $page['total'], 'total must be the independent movement count, never the page length');
        $this->assertSame([['accountId' => 21, 'offset' => 2, 'limit' => 2]], $movements->pageRequests);
        $this->assertSame(1, $movements->countCalls);
    }

    public function testEmptyAccountIsAValidEmptyLedgerAndABeyondEndPageIsEmpty(): void
    {
        $accounts = new InMemoryAccountRepository();
        $account = $this->seededLedgerAccount($accounts);
        $movements = new FakeLedgerMovements();
        $movements->total = 0;
        $movements->page = [];

        $result = $this->ledgerService($accounts, $movements)->ledger($account->id, 3, 25);

        $this->assertSame([], $result['items']);
        $this->assertSame(0, $result['total']);
    }

    public function testUnknownAccountReturnsNull(): void
    {
        $accounts = new InMemoryAccountRepository();

        $this->assertNull($this->ledgerService($accounts)->ledger('no-such-account', 1, 25));
    }

    public function testDanglingConsumptionProvenanceRaisesTheExistingDataFault(): void
    {
        $accounts = new InMemoryAccountRepository();
        $account = $this->seededLedgerAccount($accounts);
        $movements = new FakeLedgerMovements();
        $movements->total = 1;
        $movements->page = [
            $this->consumptionMovement('mov-dangling', 25000, 0, '2024-02-03 04:05:06', 'rev-missing'),
        ];
        // No context entry for `rev-missing`: the chain does not resolve.
        $this->read->ledgerContext = [];
        $this->read->ledgerCounts = [];

        $this->expectException(UnreadableRecordData::class);

        $this->ledgerService($accounts, $movements)->ledger($account->id, 1, 25);
    }

    public function testConsumptionWhoseSheetCountsCannotBeReadIsAlsoADataFault(): void
    {
        $accounts = new InMemoryAccountRepository();
        $account = $this->seededLedgerAccount($accounts);
        $movements = new FakeLedgerMovements();
        $movements->total = 1;
        $movements->page = [
            $this->consumptionMovement('mov-consume', 25000, 0, '2024-02-03 04:05:06', 'rev-1'),
        ];
        $this->read->ledgerContext = ['rev-1' => ['sheetId' => 'sheet-1', 'sheetTitle' => 'Travel']];
        $this->read->ledgerCounts = [];

        $this->expectException(UnreadableRecordData::class);

        $this->ledgerService($accounts, $movements)->ledger($account->id, 1, 25);
    }

    public function testLedgerReadsAreBatchedNotPerRow(): void
    {
        $accounts = new InMemoryAccountRepository();
        $account = $this->seededLedgerAccount($accounts);
        $movements = new FakeLedgerMovements();
        $movements->total = 3;
        $movements->page = [
            $this->consumptionMovement('mov-1', 100, 900, '2024-02-03 04:05:06', 'rev-1', 3),
            $this->consumptionMovement('mov-2', 100, 800, '2024-02-02 04:05:06', 'rev-2', 2),
            $this->chargeMovement('mov-3', 1000, 1000, '2024-02-01 04:05:06', 'funding', 1),
        ];
        $this->read->ledgerContext = [
            'rev-1' => ['sheetId' => 'sheet-1', 'sheetTitle' => 'Travel'],
            'rev-2' => ['sheetId' => 'sheet-1', 'sheetTitle' => 'Travel'],
        ];
        $this->read->ledgerCounts = ['sheet-1' => ['approvedCount' => 2, 'totalCount' => 2]];
        $this->read->activity = [
            21 => ['movementCount' => 3, 'lastMovementAt' => new DateTimeImmutable('2024-02-03 04:05:06')],
        ];

        $result = $this->ledgerService($accounts, $movements)->ledger($account->id, 1, 25);

        $this->assertCount(3, $result['items']);
        $this->assertSame(1, $this->read->ledgerContextCalls, 'one batched provenance read for the whole page');
        $this->assertSame(1, $this->read->ledgerCountsCalls, 'one batched count read for the whole page');
        $this->assertSame(1, $this->read->activityCalls, 'one batched activity read for the header');
        $this->assertSame(1, $movements->countCalls);
        $this->assertSame(1, $movements->pageCalls);
        $contextInput = $this->read->lastLedgerContextInput;
        sort($contextInput);
        $this->assertSame(['rev-1', 'rev-2'], $contextInput, 'charges contribute no revision to the batched read');
        $this->assertSame(['sheet-1'], $this->read->lastLedgerCountsInput);
        $this->assertSame(3, $result['account']['movementCount'], 'the header reports the independent count');
        $this->assertSame(
            '2024-02-03 04:05:06',
            $result['account']['lastMovementAt']->format('Y-m-d H:i:s'),
            'the header timestamp is the authoritative maximum, independent of the page'
        );
    }

    public function testLedgerPageRejectsInvalidPageAndPageSize(): void
    {
        $accounts = new InMemoryAccountRepository();
        $account = $this->seededLedgerAccount($accounts);

        $this->expectException(InvalidArgumentException::class);
        $this->ledgerService($accounts)->ledger($account->id, 0, 25);
    }

    // ===== T015 — ledger integrity, pagination, query budget =====

    /**
     * Page 2 balances must equal the persisted values independently of page 1.
     * Loading another page must never recalculate previously displayed historical balances.
     */
    public function testPageTwoBalancesArePageIndependent(): void
    {
        $accounts = new InMemoryAccountRepository();
        $account = $this->seededLedgerAccount($accounts, 0, 31); // dbId 31
        $movements = new FakeLedgerMovements();
        $movements->total = 5;
        // Page 1: movements 5, 4 (newest first)
        // Page 2: movements 3, 2
        // Page 3: movement 1
        $movements->page = [
            $this->chargeMovement('mov-5', 1000, 5000, '2024-01-05 10:00:00', 'charge 5', 5),
            $this->consumptionMovement('mov-4', 500, 4500, '2024-01-04 10:00:00', 'rev-4', 4),
            $this->chargeMovement('mov-3', 1000, 5500, '2024-01-03 10:00:00', 'charge 3', 3),
            $this->consumptionMovement('mov-2', 500, 5000, '2024-01-02 10:00:00', 'rev-2', 2),
            $this->chargeMovement('mov-1', 1000, 5000, '2024-01-01 10:00:00', 'charge 1', 1),
        ];
        $this->read->ledgerContext = [
            'rev-4' => ['sheetId' => 'sheet-1', 'sheetTitle' => 'Sheet One'],
            'rev-2' => ['sheetId' => 'sheet-1', 'sheetTitle' => 'Sheet One'],
        ];
        $this->read->ledgerCounts = [
            'sheet-1' => ['approvedCount' => 2, 'totalCount' => 2],
        ];
        $this->read->activity = [
            31 => ['movementCount' => 5, 'lastMovementAt' => new DateTimeImmutable('2024-01-05 10:00:00')],
        ];

        $page1 = $this->ledgerService($accounts, $movements)->ledger($account->id, 1, 2);
        $page2 = $this->ledgerService($accounts, $movements)->ledger($account->id, 2, 2);
        $page3 = $this->ledgerService($accounts, $movements)->ledger($account->id, 3, 2);

        // Page 1 items
        self::assertSame(5000, $page1['items'][0]['balanceAfter']->minorUnits());
        self::assertSame(4500, $page1['items'][1]['balanceAfter']->minorUnits());

        // Page 2 items - balances must be the persisted values, not recalculated from page 1
        self::assertSame(5500, $page2['items'][0]['balanceAfter']->minorUnits());
        self::assertSame(5000, $page2['items'][1]['balanceAfter']->minorUnits());

        // Page 3 item
        self::assertSame(5000, $page3['items'][0]['balanceAfter']->minorUnits());

        // Total must be the independent count on all pages
        self::assertSame(5, $page1['total']);
        self::assertSame(5, $page2['total']);
        self::assertSame(5, $page3['total']);

        // Account header movementCount and lastMovementAt must be consistent across pages
        self::assertSame(5, $page1['account']['movementCount']);
        self::assertSame(5, $page2['account']['movementCount']);
        self::assertSame(5, $page3['account']['movementCount']);
        self::assertSame('2024-01-05 10:00:00', $page1['account']['lastMovementAt']->format('Y-m-d H:i:s'));
        self::assertSame('2024-01-05 10:00:00', $page2['account']['lastMovementAt']->format('Y-m-d H:i:s'));
        self::assertSame('2024-01-05 10:00:00', $page3['account']['lastMovementAt']->format('Y-m-d H:i:s'));
    }

    /**
     * Unrelated financial movements interleaved between one Cost Sheet's postings
     * must be preserved in chronological order. No regrouping by Cost Sheet.
     */
    public function testInterleavedMovementsRetainChronologicalOrder(): void
    {
        $accounts = new InMemoryAccountRepository();
        $account = $this->seededLedgerAccount($accounts, 0, 41);
        $movements = new FakeLedgerMovements();
        $movements->total = 5;
        // Chronological order (newest first):
        // 5. charge (interleaved)
        // 4. consumption from sheet-1 (second posting)
        // 3. charge (interleaved)
        // 2. consumption from sheet-1 (first posting)
        // 1. charge (oldest)
        $movements->page = [
            $this->chargeMovement('mov-5', 1000, 5000, '2024-01-05 10:00:00', 'charge 5', 5),
            $this->consumptionMovement('mov-4', 500, 4500, '2024-01-04 10:00:00', 'rev-4', 4),
            $this->chargeMovement('mov-3', 1000, 5500, '2024-01-03 10:00:00', 'charge 3', 3),
            $this->consumptionMovement('mov-2', 500, 5000, '2024-01-02 10:00:00', 'rev-2', 2),
            $this->chargeMovement('mov-1', 1000, 5000, '2024-01-01 10:00:00', 'charge 1', 1),
        ];
        $this->read->ledgerContext = [
            'rev-4' => ['sheetId' => 'sheet-1', 'sheetTitle' => 'Sheet One'],
            'rev-2' => ['sheetId' => 'sheet-1', 'sheetTitle' => 'Sheet One'],
        ];
        $this->read->ledgerCounts = [
            'sheet-1' => ['approvedCount' => 2, 'totalCount' => 2],
        ];
        $this->read->activity = [
            41 => ['movementCount' => 5, 'lastMovementAt' => new DateTimeImmutable('2024-01-05 10:00:00')],
        ];

        $result = $this->ledgerService($accounts, $movements)->ledger($account->id, 1, 25);

        // The order must be exactly the persisted chronological order (newest first)
        $ids = array_column($result['items'], 'id');
        self::assertSame(['mov-5', 'mov-4', 'mov-3', 'mov-2', 'mov-1'], $ids);

        // The two consumptions from the same sheet must NOT be adjacent in the ledger
        // because charges are interleaved between them
        $types = array_column($result['items'], 'type');
        self::assertSame(['CHARGE', 'CONSUMPTION', 'CHARGE', 'CONSUMPTION', 'CHARGE'], $types);

        // Each movement keeps its own balanceAfter
        $balances = array_column($result['items'], 'balanceAfter');
        self::assertSame([5000, 4500, 5500, 5000, 5000], array_map(fn ($b) => $b->minorUnits(), $balances));
    }

    /**
     * Each authoritative posting of the same Cost Sheet remains its own ledger row
     * with its own timestamp, amount, and balanceAfter. No artificial consolidation
     * into one Cost Sheet financial row.
     */
    public function testMultiplePostingsFromSameSheetRemainSeparateRows(): void
    {
        $accounts = new InMemoryAccountRepository();
        $account = $this->seededLedgerAccount($accounts, 0, 51);
        $movements = new FakeLedgerMovements();
        $movements->total = 3;
        // Two consumptions from the same sheet, plus one charge
        $movements->page = [
            $this->chargeMovement('mov-3', 1000, 3000, '2024-01-03 10:00:00', 'charge 3', 3),
            $this->consumptionMovement('mov-2', 500, 2000, '2024-01-02 10:00:00', 'rev-2', 2),
            $this->consumptionMovement('mov-1', 500, 1500, '2024-01-01 10:00:00', 'rev-1', 1),
        ];
        $this->read->ledgerContext = [
            'rev-2' => ['sheetId' => 'sheet-1', 'sheetTitle' => 'Sheet One'],
            'rev-1' => ['sheetId' => 'sheet-1', 'sheetTitle' => 'Sheet One'],
        ];
        $this->read->ledgerCounts = [
            'sheet-1' => ['approvedCount' => 2, 'totalCount' => 2],
        ];
        $this->read->activity = [
            51 => ['movementCount' => 3, 'lastMovementAt' => new DateTimeImmutable('2024-01-03 10:00:00')],
        ];

        $result = $this->ledgerService($accounts, $movements)->ledger($account->id, 1, 25);

        // Three separate rows, not consolidated
        self::assertCount(3, $result['items']);

        // Both consumptions have the same sheet reference but different movement IDs
        $consumptions = array_filter($result['items'], fn ($row) => $row['type'] === 'CONSUMPTION');
        self::assertCount(2, $consumptions);
        $consumptionIds = array_column($consumptions, 'id');
        self::assertSame(['mov-2', 'mov-1'], $consumptionIds);

        // Each has its own timestamp
        $timestamps = array_column($consumptions, 'occurredAt');
        self::assertNotSame($timestamps[0]->format('Y-m-d H:i:s'), $timestamps[1]->format('Y-m-d H:i:s'));

        // Each has its own amount
        $amounts = array_column($consumptions, 'expense');
        self::assertSame(500, $amounts[0]->minorUnits());
        self::assertSame(500, $amounts[1]->minorUnits());

        // Each has its own balanceAfter
        $balances = array_column($consumptions, 'balanceAfter');
        self::assertSame(2000, $balances[0]->minorUnits());
        self::assertSame(1500, $balances[1]->minorUnits());

        // Each has its own sourceRevisionId
        $revisionIds = array_column($consumptions, 'sourceRevisionId');
        self::assertSame(['rev-2', 'rev-1'], $revisionIds);

        // Both reference the same sheet
        foreach ($consumptions as $consumption) {
            self::assertSame('sheet-1', $consumption['sheet']['id']);
            self::assertSame('Sheet One', $consumption['sheet']['title']);
        }
    }

    /**
     * Negative persisted balances must appear verbatim in the ledger.
     */
    public function testNegativePersistedBalancesAppearVerbatim(): void
    {
        $accounts = new InMemoryAccountRepository();
        $account = $this->seededLedgerAccount($accounts, -5000, 61); // starting balance -5000
        $movements = new FakeLedgerMovements();
        $movements->total = 3;
        $movements->page = [
            $this->chargeMovement('mov-3', 2000, -3000, '2024-01-03 10:00:00', 'charge 3', 3),
            $this->consumptionMovement('mov-2', 1000, -4000, '2024-01-02 10:00:00', 'rev-2', 2),
            $this->consumptionMovement('mov-1', 1000, -5000, '2024-01-01 10:00:00', 'rev-1', 1),
        ];
        $this->read->ledgerContext = [
            'rev-2' => ['sheetId' => 'sheet-1', 'sheetTitle' => 'Sheet One'],
            'rev-1' => ['sheetId' => 'sheet-1', 'sheetTitle' => 'Sheet One'],
        ];
        $this->read->ledgerCounts = [
            'sheet-1' => ['approvedCount' => 2, 'totalCount' => 2],
        ];
        $this->read->activity = [
            61 => ['movementCount' => 3, 'lastMovementAt' => new DateTimeImmutable('2024-01-03 10:00:00')],
        ];

        $result = $this->ledgerService($accounts, $movements)->ledger($account->id, 1, 25);

        $balances = array_map(fn ($row) => $row['balanceAfter']->minorUnits(), $result['items']);
        self::assertSame([-3000, -4000, -5000], $balances, 'negative balances must be transmitted verbatim');

        // Account header balance is also negative
        self::assertSame(-5000, $result['account']['balance']->minorUnits());
    }

    /**
     * The per-request query count must match the six-query constant ledger budget:
     * 1. account read
     * 2. batched account activity aggregate
     * 3. movement count
     * 4. paged movement read
     * 5. batched ledger provenance/context read
     * 6. batched sheet approved/total count read
     *
     * No per-row query is allowed. The query count must not grow with page size.
     */
    public function testLedgerQueryBudgetIsSixConstantQueries(): void
    {
        $accounts = new InMemoryAccountRepository();
        $account = $this->seededLedgerAccount($accounts, 0, 71);
        $movements = new FakeLedgerMovements();
        $movements->total = 10;
        $movements->page = [
            $this->chargeMovement('mov-10', 1000, 10000, '2024-01-10 10:00:00', 'charge 10', 10),
            $this->consumptionMovement('mov-9', 500, 9500, '2024-01-09 10:00:00', 'rev-9', 9),
            $this->chargeMovement('mov-8', 1000, 10500, '2024-01-08 10:00:00', 'charge 8', 8),
            $this->consumptionMovement('mov-7', 500, 10000, '2024-01-07 10:00:00', 'rev-7', 7),
            $this->chargeMovement('mov-6', 1000, 11000, '2024-01-06 10:00:00', 'charge 6', 6),
            $this->consumptionMovement('mov-5', 500, 10500, '2024-01-05 10:00:00', 'rev-5', 5),
            $this->chargeMovement('mov-4', 1000, 11500, '2024-01-04 10:00:00', 'charge 4', 4),
            $this->consumptionMovement('mov-3', 500, 11000, '2024-01-03 10:00:00', 'rev-3', 3),
            $this->chargeMovement('mov-2', 1000, 12000, '2024-01-02 10:00:00', 'charge 2', 2),
            $this->consumptionMovement('mov-1', 500, 11500, '2024-01-01 10:00:00', 'rev-1', 1),
        ];
        $this->read->ledgerContext = [];
        foreach (range(1, 9, 2) as $i) {
            $this->read->ledgerContext["rev-$i"] = ['sheetId' => "sheet-$i", 'sheetTitle' => "Sheet $i"];
        }
        $this->read->ledgerCounts = [];
        foreach (range(1, 9, 2) as $i) {
            $this->read->ledgerCounts["sheet-$i"] = ['approvedCount' => 1, 'totalCount' => 1];
        }
        $this->read->activity = [
            71 => ['movementCount' => 10, 'lastMovementAt' => new DateTimeImmutable('2024-01-10 10:00:00')],
        ];

        // Load page 1 with pageSize 3
        $this->ledgerService($accounts, $movements)->ledger($account->id, 1, 3);
        $contextInputPage1 = $this->read->lastLedgerContextInput;
        $countsInputPage1 = $this->read->lastLedgerCountsInput;
        $callsPage1 = [
            'activity' => $this->read->activityCalls,
            'ledgerContext' => $this->read->ledgerContextCalls,
            'ledgerCounts' => $this->read->ledgerCountsCalls,
            'movementCount' => $movements->countCalls,
            'movementPage' => $movements->pageCalls,
        ];

        // Reset counters
        $this->read->activityCalls = 0;
        $this->read->ledgerContextCalls = 0;
        $this->read->ledgerCountsCalls = 0;
        $movements->countCalls = 0;
        $movements->pageCalls = 0;

        // Load page 2 with pageSize 3
        $this->ledgerService($accounts, $movements)->ledger($account->id, 2, 3);
        $contextInputPage2 = $this->read->lastLedgerContextInput;
        $countsInputPage2 = $this->read->lastLedgerCountsInput;
        $callsPage2 = [
            'activity' => $this->read->activityCalls,
            'ledgerContext' => $this->read->ledgerContextCalls,
            'ledgerCounts' => $this->read->ledgerCountsCalls,
            'movementCount' => $movements->countCalls,
            'movementPage' => $movements->pageCalls,
        ];

        // Reset counters
        $this->read->activityCalls = 0;
        $this->read->ledgerContextCalls = 0;
        $this->read->ledgerCountsCalls = 0;
        $movements->countCalls = 0;
        $movements->pageCalls = 0;

        // Load page 1 with pageSize 10 (full)
        $this->ledgerService($accounts, $movements)->ledger($account->id, 1, 10);
        $contextInputFull = $this->read->lastLedgerContextInput;
        $countsInputFull = $this->read->lastLedgerCountsInput;
        $callsFull = [
            'activity' => $this->read->activityCalls,
            'ledgerContext' => $this->read->ledgerContextCalls,
            'ledgerCounts' => $this->read->ledgerCountsCalls,
            'movementCount' => $movements->countCalls,
            'movementPage' => $movements->pageCalls,
        ];

        // Each page load must issue exactly:
        // - 1 activity read (account header)
        // - 1 movement count
        // - 1 paged movement read
        // - 1 ledgerContext read (batched provenance)
        // - 1 ledgerCounts read (batched approved/total)
        // Total: 5 queries from the doubles (account read is not counted here as it's on AccountRepository)
        self::assertSame(1, $callsPage1['activity'], 'activity read must be called once per page');
        self::assertSame(1, $callsPage1['movementCount'], 'movement count must be called once per page');
        self::assertSame(1, $callsPage1['movementPage'], 'paged movement read must be called once per page');
        self::assertSame(1, $callsPage1['ledgerContext'], 'batched provenance must be called once per page');
        self::assertSame(1, $callsPage1['ledgerCounts'], 'batched counts must be called once per page');

        // Same query count for page 2
        self::assertSame($callsPage1, $callsPage2, 'query count must not change with page number');

        // Same query count for full page
        self::assertSame($callsPage1, $callsFull, 'query count must not grow with page size');

        // Page 1 (pageSize 3): movements are mov-10 (charge), mov-9 (consumption), mov-8 (charge)
        // Only mov-9 has a revision → rev-9
        $expectedRevisionsPage1 = ['rev-9'];
        sort($contextInputPage1);
        self::assertSame($expectedRevisionsPage1, $contextInputPage1, 'provenance read must batch all consumption revisions from the page');

        // Page 2 (pageSize 3): movements are mov-7 (consumption), mov-6 (charge), mov-5 (consumption)
        // Revisions: rev-7, rev-5
        $expectedRevisionsPage2 = ['rev-5', 'rev-7'];
        sort($contextInputPage2);
        self::assertSame($expectedRevisionsPage2, $contextInputPage2, 'page 2 provenance must batch its consumption revisions');

        // Full page (pageSize 10): all 10 movements, 5 consumptions
        $expectedRevisionsFull = ['rev-1', 'rev-3', 'rev-5', 'rev-7', 'rev-9'];
        sort($contextInputFull);
        self::assertSame($expectedRevisionsFull, $contextInputFull, 'full page provenance must batch all consumption revisions');

        // Counts read must receive all sheet IDs from the page
        $expectedSheetsPage1 = ['sheet-9'];
        sort($countsInputPage1);
        self::assertSame($expectedSheetsPage1, $countsInputPage1, 'counts read must batch all sheet IDs from the page');

        $expectedSheetsPage2 = ['sheet-5', 'sheet-7'];
        sort($countsInputPage2);
        self::assertSame($expectedSheetsPage2, $countsInputPage2, 'page 2 counts must batch its sheet IDs');

        $expectedSheetsFull = ['sheet-1', 'sheet-3', 'sheet-5', 'sheet-7', 'sheet-9'];
        sort($countsInputFull);
        self::assertSame($expectedSheetsFull, $countsInputFull, 'full page counts must batch all sheet IDs');
    }

    // ===== helpers =====

    private function directoryService(): HolderDirectoryService
    {
        return new HolderDirectoryService($this->read, $this->directory);
    }
}

/**
 * In-memory `FinanceRead` double for the service cases above.
 *
 * Exposes the observable call metadata (`activityCalls`, `lastActivityInput`, `holderPageCalls`,
 * `allHoldersCalls`) so the tests can prove a single batched activity read (never N+1) and that the
 * search path reads the whole population rather than only a page.
 */
final class FakeFinanceRead implements FinanceRead
{
    /** @var int */
    public int $holderCountValue = 0;

    /** @var list<array{userId: string, accountCount: int, activeAccountCount: int}> */
    public array $holders = [];

    /** @var int */
    public int $activityCalls = 0;

    /** @var list<int>|null */
    public ?array $lastActivityInput = null;

    /** @var array<int, array{movementCount: int, lastMovementAt: ?DateTimeImmutable}> */
    public array $activity = [];

    /** @var int */
    public int $holderPageCalls = 0;

    /** @var int */
    public int $allHoldersCalls = 0;

    /** @var array<string, array{sheetId: string, sheetTitle: string}> */
    public array $ledgerContext = [];

    /** @var array<string, array{approvedCount: int, totalCount: int}> */
    public array $ledgerCounts = [];

    /** @var int */
    public int $ledgerContextCalls = 0;

    /** @var int */
    public int $ledgerCountsCalls = 0;

    /** @var list<string>|null */
    public ?array $lastLedgerContextInput = null;

    /** @var list<string>|null */
    public ?array $lastLedgerCountsInput = null;

    public function holderCount(): int
    {
        return $this->holderCountValue;
    }

    public function holderPage(int $offset, int $limit): array
    {
        $this->holderPageCalls++;
        return array_slice($this->holders, $offset, $limit);
    }

    public function allHolders(): array
    {
        $this->allHoldersCalls++;
        return $this->holders;
    }

    public function accountActivityFor(array $dbIds): array
    {
        $this->activityCalls++;
        $this->lastActivityInput = $dbIds;
        $result = [];
        foreach ($dbIds as $id) {
            if (isset($this->activity[$id])) {
                $result[$id] = $this->activity[$id];
            }
        }
        return $result;
    }

    public function ledgerContextFor(array $sourceRevisionPublicIds): array
    {
        $this->ledgerContextCalls++;
        $this->lastLedgerContextInput = $sourceRevisionPublicIds;
        $result = [];
        foreach ($sourceRevisionPublicIds as $revisionPublicId) {
            if (isset($this->ledgerContext[$revisionPublicId])) {
                $result[$revisionPublicId] = $this->ledgerContext[$revisionPublicId];
            }
        }
        return $result;
    }

    public function ledgerApprovedCountsFor(array $sheetPublicIds): array
    {
        $this->ledgerCountsCalls++;
        $this->lastLedgerCountsInput = $sheetPublicIds;
        $result = [];
        foreach ($sheetPublicIds as $sheetPublicId) {
            if (isset($this->ledgerCounts[$sheetPublicId])) {
                $result[$sheetPublicId] = $this->ledgerCounts[$sheetPublicId];
            }
        }
        return $result;
    }
}

/**
 * A movement repository whose page and total are set explicitly.
 *
 * The real paged SQL and its ordering are proven against MariaDB by `FinanceLedgerRoundTripTest`.
 * Here the page is supplied directly because the subject is the application projection: what matters
 * is which offset/limit the service asks for, that `total` comes from the count, and that a page is
 * never lengthened by the service to satisfy the total.
 */
final class FakeLedgerMovements implements AccountMovementRepository
{
    public int $total = 0;

    /** @var list<AccountMovement> */
    public array $page = [];

    public int $countCalls = 0;

    public int $pageCalls = 0;

    /** @var list<array{accountId: int, offset: int, limit: int}> */
    public array $pageRequests = [];

    public function findById(int $id): ?AccountMovement
    {
        return null;
    }

    public function findByCommandId(string $commandId): ?AccountMovement
    {
        return null;
    }

    public function findBySourceRevisionId(int|string $sourceRevisionId): ?AccountMovement
    {
        return null;
    }

    public function findByAccountId(int $accountId, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): array
    {
        return $this->page;
    }

    public function findByAccountIdPaged(int $accountId, int $offset, int $limit): array
    {
        $this->pageCalls++;
        $this->pageRequests[] = ['accountId' => $accountId, 'offset' => $offset, 'limit' => $limit];

        return array_slice($this->page, $offset, $limit);
    }

    public function countByAccountId(int $accountId): int
    {
        $this->countCalls++;

        return $this->total;
    }

    public function save(AccountMovement $movement): void
    {
        // Read-only subject.
    }
}

/**
 * Records which `UserDirectory` operations the holder read issues, so the search path can be proven
 * never to enumerate or probe the Nextcloud user directory.
 */
final class RecordingUserDirectory implements UserDirectory
{
    /** @var list<string> */
    public array $existsCalls = [];

    /** @var list<string> */
    public array $nameCalls = [];

    /** @param array<string, string> $names */
    public function __construct(private array $names)
    {
    }

    public function exists(string $uid): bool
    {
        $this->existsCalls[] = $uid;
        return isset($this->names[$uid]);
    }

    public function displayName(string $uid): string
    {
        $this->nameCalls[] = $uid;
        return $this->names[$uid] ?? $uid;
    }
}
