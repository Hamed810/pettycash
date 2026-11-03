<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Infrastructure\Http;

use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Configuration\Repository\CurrencyRepository;
use OCA\PettyCash\Domain\Configuration\Repository\ExpenseTypeRepository;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Workflow\Repository\WorkflowDecisionRepository;
use OCA\PettyCash\Infrastructure\Http\SheetPayloadFormatter;
use OCA\PettyCash\Infrastructure\Http\TransactionPayloadFormatter;
use OCA\PettyCash\Infrastructure\Persistence\Expense\SheetSummaryQuery;
use PHPUnit\Framework\TestCase;

/**
 * T439: `SheetDetail.accountId` is the stored account's public uuid, and it is detail-only.
 *
 * ## Why this is a unit case
 *
 * The two live contract tests (`PurchaserFlowControllerTest`, `SheetProvisioningContractTest`) prove
 * the projection over HTTP, but they can only observe whatever account the fixture happened to
 * provision. This case pins the direction at the formatter itself: the value is the sheet's own
 * `accountId` — the public id the repository resolved from `account_id` — and it is emitted by
 * `detail()` only. `summary()` and `summaries()` must not grow the field, because `SheetSummary` and
 * `GET /sheets` are unchanged and the Sheets List must not start carrying account identity.
 *
 * ## Why the collaborators are stubs rather than a database
 *
 * `detail()` on a sheet without a database row computes zero totals and an empty transaction list, so
 * none of the collaborator methods are reached. The test therefore needs only correctly typed
 * instances; it never asserts on their behaviour, and it touches no shared runtime state.
 */
final class SheetPayloadFormatterAccountTest extends TestCase
{
    private const ACCOUNT_UUID = 'b4b0b3a4-1111-4c2d-9e0f-000000000001';
    private const OTHER_ACCOUNT_UUID = 'b4b0b3a4-2222-4c2d-9e0f-000000000002';

    public function testDetailEmitsTheStoredAccountsPublicUuid(): void
    {
        $sheet = $this->sheet('sheet-a', self::ACCOUNT_UUID);

        $detail = $this->formatter()->detail($sheet, 'u-owner');

        self::assertArrayHasKey('accountId', $detail);
        self::assertSame(self::ACCOUNT_UUID, $detail['accountId']);
        self::assertNotSame('', $detail['accountId'], 'A guessed or empty account must not be emitted');
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            (string) $detail['accountId'],
            'SheetDetail.accountId is declared format: uuid'
        );
    }

    /**
     * Two sheets with different stored accounts must report their own account, not a shared value:
     * a "first account" or list-order projection would make them agree.
     */
    public function testEachDetailReportsItsOwnStoredAccount(): void
    {
        $first = $this->formatter()->detail($this->sheet('sheet-a', self::ACCOUNT_UUID), 'u-owner');
        $second = $this->formatter()->detail($this->sheet('sheet-b', self::OTHER_ACCOUNT_UUID), 'u-owner');

        self::assertSame(self::ACCOUNT_UUID, $first['accountId']);
        self::assertSame(self::OTHER_ACCOUNT_UUID, $second['accountId']);
        self::assertNotSame($first['accountId'], $second['accountId']);
    }

    public function testSummaryDoesNotEmitAccountId(): void
    {
        $summary = $this->formatter()->summary($this->sheet('sheet-a', self::ACCOUNT_UUID));

        self::assertArrayNotHasKey(
            'accountId',
            $summary,
            'SheetSummary and the GET /sheets list must not carry account identity'
        );
    }

    public function testSummariesDoNotEmitAccountId(): void
    {
        $summaries = $this->formatter()->summaries([
            $this->sheet('sheet-a', self::ACCOUNT_UUID),
            $this->sheet('sheet-b', self::OTHER_ACCOUNT_UUID),
        ]);

        self::assertCount(2, $summaries);
        foreach ($summaries as $summary) {
            self::assertArrayNotHasKey('accountId', $summary);
        }
    }

    private function sheet(string $id, string $accountId): ExpenseSheet
    {
        return ExpenseSheet::open($id, 'u-owner', $accountId, CurrencyCode::fromString('IRR'), 'Supplies');
    }

    private function formatter(): SheetPayloadFormatter
    {
        $userDirectory = $this->createStub(UserDirectory::class);
        $userDirectory->method('displayName')->willReturn('Owner');

        // `TransactionPayloadFormatter` is final, so it is composed from stubbed ports rather than
        // doubled. `detail()` returns before reaching it: an unsaved sheet has no transactions.
        $transactions = new TransactionPayloadFormatter(
            $this->createStub(WorkflowDecisionRepository::class),
            $this->createStub(EvidenceRepository::class),
            $this->createStub(CostCenterRepository::class),
            $this->createStub(CurrencyRepository::class),
            $this->createStub(RoleAssignmentRepository::class),
            $this->createStub(ExpenseSheetRepository::class),
            $this->createStub(CurrentUser::class),
            $this->createStub(ExpenseTypeRepository::class)
        );

        // `SheetSummaryQuery` is final and its `IDBConnection` dependency is not part of the unit
        // suite's autoload, so it is allocated without its constructor. Every method this case reaches
        // returns before touching the connection: an unsaved sheet has `dbId === null`, which yields
        // zero totals and a null expense-date range.
        $summaryQuery = (new \ReflectionClass(SheetSummaryQuery::class))->newInstanceWithoutConstructor();

        return new SheetPayloadFormatter(
            $summaryQuery,
            $this->createStub(ExpenseTransactionRepository::class),
            $this->createStub(CurrencyRepository::class),
            $userDirectory,
            $transactions
        );
    }
}
