<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\BugRepro;

use DateTimeImmutable;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Expense\TransactionService;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Domain\Configuration\CostCenter;
use OCA\PettyCash\Domain\Configuration\ExpenseType;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCommandResultRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCostCenterRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryEvidenceRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseSheetRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseTransactionRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseTypeRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryPrivateFileStore;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionManager;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionRevisionRepository;
use PHPUnit\Framework\TestCase;

/**
 * Smallest practical reproduction of the OPEN-sheet DRAFT status regression.
 *
 * It drives the exact production path used by both "Save draft" and "Update":
 * TransactionService::updateTransaction() for a DRAFT on an OPEN, never-submitted sheet.
 *
 * Expected: status remains DRAFT, stage remains DRAFT, owning sheet remains OPEN.
 * Observed on the current working tree: status becomes AWAITING_COST_CENTER_REVIEW
 * and stage becomes COST_CENTER.
 */
final class OpenSheetDraftStatusReproTest extends TestCase
{
    private const OWNER = 'purchaser-1';
    private const TRANSACTION_ID = '44444444-4444-4444-8444-444444444444';
    private const REVISION_ID = '55555555-5555-4555-8555-555555555555';
    private const COST_CENTER_ID = '66666666-6666-4666-8666-666666666666';
    private const EXPENSE_TYPE_ID = '88888888-8888-4888-8888-888888888888';
    private const COMMAND_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private InMemoryExpenseSheetRepository $sheets;
    private InMemoryExpenseTransactionRepository $transactions;
    private ExpenseSheet $sheet;
    private TransactionService $service;

    protected function setUp(): void
    {
        $this->sheets = new InMemoryExpenseSheetRepository();
        $this->transactions = new InMemoryExpenseTransactionRepository();
        $revisions = new InMemoryTransactionRevisionRepository();
        $costCenters = new InMemoryCostCenterRepository();
        $expenseTypes = new InMemoryExpenseTypeRepository();

        $costCenter = CostCenter::create('CC-100', 'Cost Center', null, new DateTimeImmutable('2026-01-01'), null);
        $costCenter->id = 1;
        $costCenter->publicId = self::COST_CENTER_ID;
        $costCenters->save($costCenter);

        $expenseType = ExpenseType::create('Expense Type', null, false);
        $expenseType->id = 1;
        $expenseType->publicId = self::EXPENSE_TYPE_ID;
        $expenseTypes->save($expenseType);

        $this->sheet = ExpenseSheet::open('sheet-1', self::OWNER, 'account-1', CurrencyCode::fromString('IRR'));
        $this->sheet->dbId = 1;
        $this->sheet->status = SheetStatus::Open;
        $this->sheets->save($this->sheet);

        $current = TransactionRevision::draft(
            self::REVISION_ID,
            1,
            new DateTimeImmutable('2026-01-01'),
            Money::positive(1000, CurrencyCode::fromString('IRR'))
        );
        $current->complete(
            'original description',
            self::COST_CENTER_ID,
            'CC-100',
            'Cost Center',
            1,
            'Expense Type',
            1,
            false,
            self::EXPENSE_TYPE_ID
        );

        $transaction = new ExpenseTransaction(self::TRANSACTION_ID, self::OWNER, $current);
        $transaction->dbId = 1;
        $transaction->sheetId = 1;
        $transaction->lockVersion = 0;
        $this->transactions->save($transaction);

        $current->transactionDbId = 1;
        $current->publicId = self::REVISION_ID;
        $revisions->save($current);

        $this->service = new TransactionService(
            $this->sheets,
            $this->transactions,
            $revisions,
            $costCenters,
            $expenseTypes,
            new InMemoryEvidenceRepository(),
            new InMemoryPrivateFileStore(),
            new InMemoryCurrentUser(self::OWNER),
            new InMemoryTransactionManager(),
            new InMemoryAuditWriter(),
            new IdempotencyService(new InMemoryCommandResultRepository())
        );
    }

    public function testUpdatingAnOpenSheetDraftPreservesDraftStatus(): void
    {
        $updated = $this->service->updateTransaction(
            self::TRANSACTION_ID,
            '2026-01-02',
            2000,
            'updated description',
            self::COST_CENTER_ID,
            self::EXPENSE_TYPE_ID,
            0,
            self::COMMAND_ID
        );

        $stored = $this->transactions->findByPublicId(self::TRANSACTION_ID);
        self::assertNotNull($stored);

        fwrite(STDERR, sprintf(
            "\nOBSERVED  returned: status=%s stage=%s | persisted: status=%s stage=%s | sheet=%s\n",
            $updated->status->value,
            $updated->stage->value,
            $stored->status->value,
            $stored->stage->value,
            $this->sheet->status->value
        ));

        self::assertSame(TransactionStatus::Draft, $updated->status, 'update must preserve DRAFT');
        self::assertSame(TransactionStatus::Draft, $stored->status, 'update must persist DRAFT');
        self::assertSame(SheetStatus::Open, $this->sheet->status, 'owning sheet must remain OPEN');
    }
}
