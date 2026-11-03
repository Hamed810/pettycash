<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Expense;

use InvalidArgumentException;
use OCA\PettyCash\Application\Account\AccountAdministrationService;
use OCA\PettyCash\Application\Audit\ConfigurationAuditService;
use OCA\PettyCash\Application\Configuration\CurrencyService;
use OCA\PettyCash\Application\Expense\SheetService;
use OCA\PettyCash\Application\StaleRecordVersion;
use OCA\PettyCash\Domain\Account\PettyCashAccount;
use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAccountRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAdministratorCheck;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrencyRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseSheetRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionManager;
use PHPUnit\Framework\TestCase;

/**
 * T440 / FR-019 / FR-021 at the application boundary.
 *
 * `SheetService::updateSheet()` takes no account argument. The HTTP refusal of a supplied
 * `accountId` is covered by `SheetAccountUpdatePolicyTest`; this suite proves the service contract
 * that makes the refusal sufficient: an update preserves the stored account and currency, succeeds
 * when that account is inactive, and leaves the sheet untouched whenever an earlier guard refuses.
 *
 * The fixture deliberately uses the **same** account and currency through every assertion, so an
 * implementation that adopted another account (or derived a currency from something other than the
 * sheet) cannot pass by accident.
 */
final class SheetAccountUpdateTest extends TestCase
{
    private const OWNER = 'purchaser-t440';
    private const OTHER_OWNER = 'purchaser-other';
    private const SHEET_ID = '11111111-1111-4111-8111-111111111111';
    private const ACCOUNT_ID = '22222222-2222-4222-8222-222222222222';
    private const OTHER_ACCOUNT_ID = '33333333-3333-4333-8333-333333333333';

    private InMemoryExpenseSheetRepository $sheets;
    private InMemoryAccountRepository $accounts;
    private InMemoryCurrencyRepository $currencies;
    private SheetService $service;

    protected function setUp(): void
    {
        $this->sheets = new InMemoryExpenseSheetRepository();
        $this->accounts = new InMemoryAccountRepository();
        $this->currencies = new InMemoryCurrencyRepository();
        $this->service = $this->buildService(self::OWNER);
    }

    public function testATitleOnlyUpdatePreservesTheInactiveStoredAccountAndCurrency(): void
    {
        $this->seedAccount(self::ACCOUNT_ID, self::OWNER, false);
        $this->seedSheet(self::SHEET_ID, self::OWNER, self::ACCOUNT_ID, 'IRR', 'Original title', 4);

        $updated = $this->service->updateSheet(self::SHEET_ID, 'Renamed with an inactive account', 4);

        self::assertSame('Renamed with an inactive account', $updated->title);
        self::assertSame(self::ACCOUNT_ID, $updated->accountId, 'the inactive stored account must be preserved');
        self::assertSame('IRR', (string) $updated->currency, 'the sheet currency must not be re-derived');
        self::assertSame(5, $updated->lockVersion, 'one successful update increments the version once');

        $stored = $this->sheets->findByPublicId(self::SHEET_ID);
        self::assertNotNull($stored);
        self::assertSame('Renamed with an inactive account', $stored->title);
        self::assertSame(self::ACCOUNT_ID, $stored->accountId);
        self::assertSame('IRR', (string) $stored->currency);
        self::assertSame(5, $stored->lockVersion);
    }

    /**
     * A different account existing in the repository changes nothing: the service has no account
     * parameter and does not choose one.
     */
    public function testAnUpdateDoesNotAdoptAnotherOwnedAccount(): void
    {
        $this->seedAccount(self::ACCOUNT_ID, self::OWNER, true);
        $this->seedAccount(self::OTHER_ACCOUNT_ID, self::OWNER, true);
        $this->seedSheet(self::SHEET_ID, self::OWNER, self::ACCOUNT_ID, 'IRR', 'Original title', 0);

        $updated = $this->service->updateSheet(self::SHEET_ID, 'Still the same account', 0);

        self::assertSame(self::ACCOUNT_ID, $updated->accountId);
        self::assertSame(self::ACCOUNT_ID, $this->sheets->findByPublicId(self::SHEET_ID)?->accountId);
        self::assertSame('IRR', (string) $updated->currency);
    }

    public function testAnotherOwnersSheetIsRefusedAndNoTitleIsPersisted(): void
    {
        $this->seedSheet(self::SHEET_ID, self::OTHER_OWNER, self::ACCOUNT_ID, 'IRR', 'Original title', 2);

        try {
            $this->service->updateSheet(self::SHEET_ID, 'Should never be stored', 2);
            self::fail('another owner\'s sheet must not be updatable');
        } catch (InvalidArgumentException $refusal) {
            self::assertSame('Sheet not found', $refusal->getMessage());
        }

        $stored = $this->sheets->findByPublicId(self::SHEET_ID);
        self::assertNotNull($stored);
        self::assertSame('Original title', $stored->title);
        self::assertSame(2, $stored->lockVersion);
        self::assertSame(self::ACCOUNT_ID, $stored->accountId);
    }

    public function testAStaleVersionIsRefusedAndPersistsNothing(): void
    {
        $this->seedSheet(self::SHEET_ID, self::OWNER, self::ACCOUNT_ID, 'IRR', 'Original title', 3);

        $this->expectException(StaleRecordVersion::class);

        try {
            $this->service->updateSheet(self::SHEET_ID, 'Stale write', 2);
        } finally {
            $stored = $this->sheets->findByPublicId(self::SHEET_ID);
            self::assertNotNull($stored);
            self::assertSame('Original title', $stored->title, 'a stale refusal must not leave a partial title write');
            self::assertSame(3, $stored->lockVersion);
            self::assertSame(self::ACCOUNT_ID, $stored->accountId);
            self::assertSame('IRR', (string) $stored->currency);
        }
    }

    public function testANonOpenSheetIsRefusedAndPersistsNothing(): void
    {
        $this->seedSheet(self::SHEET_ID, self::OWNER, self::ACCOUNT_ID, 'IRR', 'Original title', 1, SheetStatus::CostCenter);

        try {
            $this->service->updateSheet(self::SHEET_ID, 'Submitted sheet rename', 1);
            self::fail('a sheet that is not open must not be updated');
        } catch (InvalidArgumentException $refusal) {
            self::assertSame('Only open sheets can be updated', $refusal->getMessage());
        }

        $stored = $this->sheets->findByPublicId(self::SHEET_ID);
        self::assertNotNull($stored);
        self::assertSame('Original title', $stored->title);
        self::assertSame(1, $stored->lockVersion);
        self::assertSame(self::ACCOUNT_ID, $stored->accountId);
    }

    private function buildService(string $uid): SheetService
    {
        $administration = new AccountAdministrationService(
            $this->accounts,
            new CurrencyService(
                $this->currencies,
                new InMemoryAdministratorCheck(),
                new ConfigurationAuditService(new InMemoryAuditWriter(), new InMemoryTransactionManager()),
                $this->accounts
            ),
            new InMemoryAuditWriter()
        );

        return new SheetService(
            $this->sheets,
            $this->accounts,
            new InMemoryCurrentUser($uid),
            new InMemoryTransactionManager(),
            $administration
        );
    }

    private function seedAccount(string $publicId, string $owner, bool $active): void
    {
        $currency = $this->currencies->findByCode('IRR');
        self::assertNotNull($currency);

        $account = PettyCashAccount::create($publicId, $owner, $currency);
        $account->active = $active;
        $this->accounts->save($account);
    }

    private function seedSheet(
        string $publicId,
        string $owner,
        string $accountId,
        string $currencyCode,
        string $title,
        int $lockVersion,
        SheetStatus $status = SheetStatus::Open
    ): void {
        $sheet = ExpenseSheet::open($publicId, $owner, $accountId, CurrencyCode::fromString($currencyCode), $title);
        $sheet->publicId = $publicId;
        $sheet->dbId = 1;
        $sheet->status = $status;
        $sheet->lockVersion = $lockVersion;
        $this->sheets->save($sheet);
    }
}
