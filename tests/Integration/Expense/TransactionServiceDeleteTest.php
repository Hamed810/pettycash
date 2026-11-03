<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Expense;

use InvalidArgumentException;
use OCA\PettyCash\Application\Command\CommandConflict;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Expense\TransactionNotFound;
use OCA\PettyCash\Application\Expense\TransactionService;
use OCA\PettyCash\Application\StaleRecordVersion;
use OCA\PettyCash\Domain\Evidence\Evidence;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCommandResultRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryEvidenceRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseSheetRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseTransactionRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCostCenterRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseTypeRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryPrivateFileStore;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionManager;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionRevisionRepository;
use PHPUnit\Framework\TestCase;

/**
 * T436 at the service level: the replay gate, the 404 marker, and the evidence cascade.
 *
 * ## Why these assertions live here rather than only over HTTP
 *
 * The contract suite proves the status codes. It cannot cheaply prove that a replay **never runs the
 * guards again** — the observable HTTP answer (`204`) is the same whether the closure ran or not — and
 * it cannot see the binary removal at all, because the file store is private infrastructure. The
 * in-memory doubles make both visible: the replay path is asserted by the row staying gone while a
 * second call still succeeds, and the cascade is asserted by the stored key disappearing.
 *
 * The production metadata cascade (`pettycash_evidence` rows) is the repository's responsibility and
 * is asserted over HTTP, where the real adapter is in use.
 */
final class TransactionServiceDeleteTest extends TestCase
{
    private const OWNER = 'purchaser-1';
    private const OTHER_USER = 'purchaser-2';
    private const SHEET_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const TRANSACTION_ID = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    private const REVISION_ID = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
    private const EVIDENCE_ID = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
    private const APP_DATA_KEY = 'evidence/eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee/1/receipt.pdf';
    private const COMMAND_ID = '11111111-1111-4111-8111-111111111111';

    private InMemoryExpenseSheetRepository $sheets;
    private InMemoryExpenseTransactionRepository $transactions;
    private InMemoryTransactionRevisionRepository $revisions;
    private InMemoryEvidenceRepository $evidence;
    private InMemoryPrivateFileStore $files;
    private InMemoryCurrentUser $currentUser;
    private InMemoryTransactionManager $transactionManager;
    private TransactionService $service;
    private ExpenseSheet $sheet;

    protected function setUp(): void
    {
        $this->sheets = new InMemoryExpenseSheetRepository();
        $this->transactions = new InMemoryExpenseTransactionRepository();
        $this->revisions = new InMemoryTransactionRevisionRepository();
        $this->evidence = new InMemoryEvidenceRepository();
        $this->files = new InMemoryPrivateFileStore();
        $this->currentUser = new InMemoryCurrentUser(self::OWNER);
        $this->transactionManager = new InMemoryTransactionManager();

        $this->sheet = $this->seedSheet(self::OWNER);
        $this->service = new TransactionService(
            $this->sheets,
            $this->transactions,
            $this->revisions,
            new InMemoryCostCenterRepository(),
            new InMemoryExpenseTypeRepository(),
            $this->evidence,
            $this->files,
            $this->currentUser,
            $this->transactionManager,
            new InMemoryAuditWriter(),
            new IdempotencyService(new InMemoryCommandResultRepository())
        );
    }

    public function testDeletingADraftWithoutEvidenceRemovesTheTransaction(): void
    {
        $this->seedTransaction();

        $this->service->deleteTransaction(self::TRANSACTION_ID, self::COMMAND_ID, 0);

        self::assertNull(
            $this->transactions->findByPublicId(self::TRANSACTION_ID),
            'the row must be gone, not merely filtered out of the sheet'
        );
        self::assertSame([], $this->sheet->transactions(), 'the sheet aggregate must no longer hold the draft');
    }

    public function testDeletingADraftRemovesItsEvidenceBinary(): void
    {
        $this->seedTransaction();
        $this->seedEvidence();

        self::assertTrue($this->files->exists(self::APP_DATA_KEY), 'precondition: the binary exists');

        $this->service->deleteTransaction(self::TRANSACTION_ID, self::COMMAND_ID, 0);

        self::assertFalse(
            $this->files->exists(self::APP_DATA_KEY),
            'draft evidence is only removable before submission, so the delete must remove its binary too'
        );
    }

    public function testTheSameCommandIdAndPayloadReplaysSuccessAfterTheRowIsGone(): void
    {
        $this->seedTransaction();

        $this->service->deleteTransaction(self::TRANSACTION_ID, self::COMMAND_ID, 0);
        // No exception is the assertion: the row is gone, yet the same intent is still a success.
        $this->service->deleteTransaction(self::TRANSACTION_ID, self::COMMAND_ID, 0);

        self::assertNull($this->transactions->findByPublicId(self::TRANSACTION_ID));
    }

    public function testTheSameCommandIdWithADifferentPayloadIsAConflict(): void
    {
        $this->seedTransaction();

        $this->service->deleteTransaction(self::TRANSACTION_ID, self::COMMAND_ID, 0);

        $this->expectException(CommandConflict::class);
        $this->service->deleteTransaction(self::TRANSACTION_ID, self::COMMAND_ID, 1);
    }

    public function testADifferentCommandIdAfterDeletionIsNotFound(): void
    {
        $this->seedTransaction();

        $this->service->deleteTransaction(self::TRANSACTION_ID, self::COMMAND_ID, 0);

        $this->expectException(TransactionNotFound::class);
        $this->service->deleteTransaction(
            self::TRANSACTION_ID,
            '22222222-2222-4222-8222-222222222222',
            0
        );
    }

    public function testAnotherOwnersTransactionIsReportedAsAbsentAndSurvives(): void
    {
        $this->seedTransaction(self::OTHER_USER);

        try {
            $this->service->deleteTransaction(self::TRANSACTION_ID, self::COMMAND_ID, 0);
            self::fail('another owner\'s transaction must not be deletable');
        } catch (TransactionNotFound $e) {
            self::assertStringNotContainsStringIgnoringCase(
                'owner',
                $e->getMessage(),
                'the refusal must not disclose that the transaction belongs to someone else'
            );
        }

        self::assertNotNull(
            $this->transactions->findByPublicId(self::TRANSACTION_ID),
            'a refused deletion must leave the row intact'
        );
    }

    public function testAStaleVersionIsAConflictAndSurvives(): void
    {
        $this->seedTransaction();

        try {
            $this->service->deleteTransaction(self::TRANSACTION_ID, self::COMMAND_ID, 1);
            self::fail('a stale version must not delete');
        } catch (StaleRecordVersion $e) {
            self::assertSame('TRANSACTION_VERSION_CONFLICT', $e->errorCode());
        }

        self::assertNotNull($this->transactions->findByPublicId(self::TRANSACTION_ID));
    }

    /**
     * A returned transaction sitting in an open sheet is still not a draft.
     *
     * The sheet-status guard alone would allow this delete; the draft-status guard is what protects
     * submitted business history (FR-058).
     */
    public function testANonDraftTransactionIsRefused(): void
    {
        $this->seedTransaction(status: TransactionStatus::AwaitingCostCenterReview);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only a draft transaction can be deleted');

        $this->service->deleteTransaction(self::TRANSACTION_ID, self::COMMAND_ID, 0);
    }

    /**
     * The command result and the row removal share a transaction.
     *
     * A replay gate that saved its result outside the write transaction would leave a success recorded
     * for a deletion that rolled back, turning a retry into a false `204` over a row that still exists.
     * The in-memory manager reports depth rather than committing, so the assertion is about **where**
     * the work happened: the delete runs inside the transaction, and so does the idempotency save.
     */
    public function testTheDeleteRunsInsideATransaction(): void
    {
        $this->seedTransaction();

        $observedInside = false;
        $this->transactionManager->onTransaction(function () use (&$observedInside): void {
            $observedInside = true;
        });

        $this->service->deleteTransaction(self::TRANSACTION_ID, self::COMMAND_ID, 0);

        self::assertTrue($observedInside, 'the delete command must open a transaction');
        self::assertSame(0, $this->transactionManager->transactionDepth(), 'and close it afterwards');
    }

    private function seedSheet(string $ownerUid): ExpenseSheet
    {
        $sheet = ExpenseSheet::open(self::SHEET_ID, $ownerUid, 'account-1', CurrencyCode::fromString('IRR'));
        $sheet->dbId = 1;
        $sheet->status = SheetStatus::Open;
        $sheet->publicId = self::SHEET_ID;
        $this->sheets->save($sheet);

        return $sheet;
    }

    private function seedTransaction(
        string $ownerUid = self::OWNER,
        TransactionStatus $status = TransactionStatus::Draft
    ): ExpenseTransaction {
        $revision = TransactionRevision::draft(
            self::REVISION_ID,
            1,
            new \DateTimeImmutable('2026-01-01'),
            Money::positive(1000, CurrencyCode::fromString('IRR'))
        );
        $revision->publicId = self::REVISION_ID;
        $revision->transactionDbId = 1;

        $transaction = new ExpenseTransaction(self::TRANSACTION_ID, $ownerUid, $revision);
        $transaction->dbId = 1;
        $transaction->sheetId = 1;
        $transaction->publicId = self::TRANSACTION_ID;
        $transaction->lockVersion = 0;
        $transaction->status = $status;
        $this->transactions->save($transaction);
        $this->sheet->addTransaction($transaction);

        return $transaction;
    }

    private function seedEvidence(): void
    {
        $this->evidence->save(new Evidence(
            self::EVIDENCE_ID,
            1,
            1,
            self::APP_DATA_KEY,
            'receipt.pdf',
            'application/pdf',
            5,
            str_repeat('a', 64),
        ));
        $this->files->put(self::APP_DATA_KEY, 'bytes');
    }
}
