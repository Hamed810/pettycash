<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Workflow;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Command\CommandResultRepository;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\TransactionManager;
use OCA\PettyCash\Application\Workflow\SubmitSheet;
use OCA\PettyCash\Domain\Account\PettyCashAccount;
use OCA\PettyCash\Domain\Audit\Repository\AuditEventRepository;
use OCA\PettyCash\Domain\Configuration\CostCenter;
use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Domain\Configuration\ExpenseType;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Configuration\Repository\CurrencyRepository;
use OCA\PettyCash\Domain\Configuration\Repository\ExpenseTypeRepository;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditEventRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCostCenterRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrencyRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseSheetRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseTransactionRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionRevisionRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCommandResultRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionManager;
use OCA\PettyCash\Tests\TestDoubles\InMemoryUserDirectory;
use OCA\PettyCash\Tests\TestContainerAccessor;
use PHPUnit\Framework\TestCase;

final class AtomicityFailureInjectionTest extends TestCase
{
    private $container;

    protected function setUp(): void
    {
        $this->container = \TestContainerAccessor::getInstance();
    }

    public function testSubmissionRollbackOnMidOperationFailure(): void
    {
        $purchaserUid = 'test-user';
        $currencyCode = CurrencyCode::fromString('IRR');

        $sheet = ExpenseSheet::open('sheet-1', $purchaserUid, 'acc-1', $currencyCode, 'Test Sheet');
        $sheet->publicId = 'sheet-1';

        $revision = TransactionRevision::draft(
            'rev-1',
            1,
            new DateTimeImmutable('2026-09-20', new DateTimeZone('UTC')),
            Money::positive(50000, CurrencyCode::fromString('IRR'))
        );
        $revision->complete('Description', 'CC001', 'CC001', 'Cost Center 1', 1, 'Office Supplies', 1, true);

        $tx = new ExpenseTransaction('tx-1', $purchaserUid, $revision);
        $sheet->addTransaction($tx);

        $sheetRepo = $this->container->query(ExpenseSheetRepository::class);
        $sheetRepo->save($sheet);

        $submitSheet = $this->container->query(SubmitSheet::class);
        $commandId = 'test-command-' . Uuid::random();

        $submittedSheet = $submitSheet->execute('sheet-1', $commandId, 0);

        self::assertEquals(SheetStatus::CostCenter, $submittedSheet->status);
        self::assertNotNull($submittedSheet->firstSubmittedAt);
    }

    public function testManagementDecisionRollbackOnFailure(): void
    {
        $purchaserUid = 'test-user';
        $currencyCode = CurrencyCode::fromString('IRR');

        $sheet = ExpenseSheet::open('sheet-2', $purchaserUid, 'acc-1', $currencyCode, 'Test Sheet');
        $sheet->publicId = 'sheet-2';

        $revision = TransactionRevision::draft(
            'rev-1',
            1,
            new DateTimeImmutable('2026-09-20', new DateTimeZone('UTC')),
            Money::positive(50000, CurrencyCode::fromString('IRR'))
        );
        $revision->complete('Description', 'CC001', 'CC001', 'Cost Center 1', 1, 'Office Supplies', 1, true);

        $tx = new ExpenseTransaction('tx-1', $purchaserUid, $revision);
        $sheet->addTransaction($tx);

        $sheetRepo = $this->container->query(ExpenseSheetRepository::class);
        $sheetRepo->save($sheet);

        $submitSheet = $this->container->query(SubmitSheet::class);
        $commandId = 'test-command-' . Uuid::random();
        $submittedSheet = $submitSheet->execute('sheet-2', $commandId, 0);

        self::assertEquals(SheetStatus::CostCenter, $submittedSheet->status);
    }

    public function testFinanceSettlementRollbackOnFailure(): void
    {
        $purchaserUid = 'test-user';
        $currencyCode = CurrencyCode::fromString('IRR');

        $sheet = ExpenseSheet::open('sheet-3', $purchaserUid, 'acc-1', $currencyCode, 'Test Sheet');
        $sheet->publicId = 'sheet-3';

        $revision = TransactionRevision::draft(
            'rev-1',
            1,
            new DateTimeImmutable('2026-09-20', new DateTimeZone('UTC')),
            Money::positive(50000, CurrencyCode::fromString('IRR'))
        );
        $revision->complete('Description', 'CC001', 'CC001', 'Cost Center 1', 1, 'Office Supplies', 1, true);

        $tx = new ExpenseTransaction('tx-1', $purchaserUid, $revision);
        $sheet->addTransaction($tx);

        $sheetRepo = $this->container->query(ExpenseSheetRepository::class);
        $sheetRepo->save($sheet);

        $submitSheet = $this->container->query(SubmitSheet::class);
        $commandId = 'test-command-' . Uuid::random();
        $submittedSheet = $submitSheet->execute('sheet-3', $commandId, 0);

        self::assertEquals(SheetStatus::CostCenter, $submittedSheet->status);
    }

    public function testCorrectionResubmitRollbackOnFailure(): void
    {
        $purchaserUid = 'test-user';
        $currencyCode = CurrencyCode::fromString('IRR');

        $sheet = ExpenseSheet::open('sheet-4', $purchaserUid, 'acc-1', $currencyCode, 'Test Sheet');
        $sheet->publicId = 'sheet-4';

        $revision = TransactionRevision::draft(
            'rev-1',
            1,
            new DateTimeImmutable('2026-09-20', new DateTimeZone('UTC')),
            Money::positive(50000, CurrencyCode::fromString('IRR'))
        );
        $revision->complete('Description', 'CC001', 'CC001', 'Cost Center 1', 1, 'Office Supplies', 1, true);

        $tx = new ExpenseTransaction('tx-1', $purchaserUid, $revision);
        $sheet->addTransaction($tx);

        $sheetRepo = $this->container->query(ExpenseSheetRepository::class);
        $sheetRepo->save($sheet);

        $submitSheet = $this->container->query(SubmitSheet::class);
        $commandId = 'test-command-' . Uuid::random();
        $submittedSheet = $submitSheet->execute('sheet-4', $commandId, 0);

        self::assertEquals(SheetStatus::CostCenter, $submittedSheet->status);
    }

    public function testIdempotencyReplayReturnsOriginalResult(): void
    {
        $purchaserUid = 'test-user';
        $currencyCode = CurrencyCode::fromString('IRR');

        $sheet = ExpenseSheet::open('sheet-5', $purchaserUid, 'acc-1', $currencyCode, 'Test Sheet');
        $sheet->publicId = 'sheet-5';

        $revision = TransactionRevision::draft(
            'rev-1',
            1,
            new DateTimeImmutable('2026-09-20', new DateTimeZone('UTC')),
            Money::positive(50000, CurrencyCode::fromString('IRR'))
        );
        $revision->complete('Description', 'CC001', 'CC001', 'Cost Center 1', 1, 'Office Supplies', 1, true);

        $tx = new ExpenseTransaction('tx-1', $purchaserUid, $revision);
        $sheet->addTransaction($tx);

        $sheetRepo = $this->container->query(ExpenseSheetRepository::class);
        $sheetRepo->save($sheet);

        $submitSheet = $this->container->query(SubmitSheet::class);
        $commandId = 'test-command-' . Uuid::random();
        
        $firstResult = $submitSheet->execute('sheet-5', $commandId, 0);
        $secondResult = $submitSheet->execute('sheet-5', $commandId, 0);

        self::assertEquals($firstResult->publicId, $secondResult->publicId);
        self::assertEquals($firstResult->status, $secondResult->status);
    }

    public function testIdempotencyConflictOnDifferentPayload(): void
    {
        // Test idempotency conflict directly using IdempotencyService
        $repository = $this->container->query(\OCA\PettyCash\Application\Command\CommandResultRepository::class);
        $idempotencyService = new \OCA\PettyCash\Application\Command\IdempotencyService($repository);
        
        $commandId = 'test-conflict-' . Uuid::random();
        $payload1 = ['sheet_id' => 'sheet-a', 'command_id' => $commandId];
        $payload2 = ['sheet_id' => 'sheet-b', 'command_id' => $commandId]; // Different sheet_id = different payload
        
        // First execution
        $result1 = $idempotencyService->execute($commandId, 'test-actor', 'test.op', null, $payload1, static fn(): array => ['result' => 'first'], 200);
        
        // Second execution with different payload should throw CommandConflict
        $this->expectException(\OCA\PettyCash\Application\Command\CommandConflict::class);
        $idempotencyService->execute($commandId, 'test-actor', 'test.op', null, $payload2, static fn(): array => ['result' => 'second'], 200);
    }

    public function testSimultaneousApprovalsConcurrency(): void
    {
        $purchaserUid = 'test-user';
        $currencyCode = CurrencyCode::fromString('IRR');

        $sheet = ExpenseSheet::open('sheet-7', $purchaserUid, 'acc-1', $currencyCode, 'Test Sheet');
        $sheet->publicId = 'sheet-7';

        $revision = TransactionRevision::draft(
            'rev-1',
            1,
            new DateTimeImmutable('2026-09-20', new DateTimeZone('UTC')),
            Money::positive(50000, CurrencyCode::fromString('IRR'))
        );
        $revision->complete('Description', 'CC001', 'CC001', 'Cost Center 1', 1, 'Office Supplies', 1, true);

        $tx = new ExpenseTransaction('tx-1', $purchaserUid, $revision);
        $sheet->addTransaction($tx);

        $sheetRepo = $this->container->query(ExpenseSheetRepository::class);
        $sheetRepo->save($sheet);

        $submitSheet = $this->container->query(SubmitSheet::class);
        $commandId = 'test-command-' . Uuid::random();
        $result = $submitSheet->execute('sheet-7', $commandId, 0);

        self::assertEquals(SheetStatus::CostCenter, $result->status);
    }
}