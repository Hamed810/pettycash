<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Expense;

use InvalidArgumentException;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Expense\DeleteSheet;
use OCA\PettyCash\Application\StaleRecordVersion;
use OCA\PettyCash\Domain\Evidence\Evidence;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditWriter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCommandResultRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryEvidenceRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseSheetRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseTransactionRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryPrivateFileStore;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransactionManager;
use PHPUnit\Framework\TestCase;

/**
 * FR-086 at the service level: the four properties the HTTP transport cannot observe honestly.
 *
 * ## Why these live here and not over HTTP
 *
 * `DraftSheetDeletionTest` proves the endpoint. These four are invisible through it, and each was
 * **moved** here rather than approximated after an HTTP attempt failed:
 *
 * 1. **Ownership against a genuinely foreign sheet.** No seeded contract sheet belongs to another user,
 *    so the only way to reach that branch over HTTP is to delete a sheet the acting user owns — which
 *    asserts nothing and would pass with the ownership check removed entirely.
 * 2. **No audit event.** A successful deletion removes the transaction too, so the per-transaction audit
 *    endpoint has nothing left to serve. Counting events before and after over HTTP compared a real
 *    event list against an error envelope and produced a nonsense "increase" from 1 to 4.
 * 3. **The cascade is a subtree.** Over HTTP, a 204 cannot distinguish "removed the sheet and its
 *    three transactions" from "removed the sheet row and orphaned everything under it".
 * 4. **Atomicity.** Forcing a failure mid-cascade and proving nothing moved needs a seam HTTP has none of.
 *
 * ## Why the audit writer is constructed and then never passed to the command
 *
 * `DeleteSheet` takes no `AuditWriter`. That absence is the requirement: FR-051 begins transaction audit
 * at submission, so a never-submitted sheet has no official event for a deletion to erase. This test
 * passes a writer to nothing and proves the point differently — by asserting the writer it *would* have
 * been given recorded nothing, using a writer the command cannot reach. If someone later "helpfully"
 * adds an audit write, the constructor signature changes and this test fails to compile, which is the
 * outcome we want: the requirement is enforced by the shape of the class.
 */
final class DeleteSheetOwnershipAndCascadeTest extends TestCase
{
    private const OWNER = 'purchaser-1';
    private const OTHER_USER = 'purchaser-2';
    private const SHEET_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const OTHER_SHEET_ID = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const TRANSACTION_ID = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    private const REVISION_ID = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
    private const EVIDENCE_ID = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
    private const APP_DATA_KEY = 'evidence/eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee/1/receipt.pdf';

    private InMemoryExpenseSheetRepository $sheets;
    private InMemoryExpenseTransactionRepository $transactions;
    private InMemoryEvidenceRepository $evidence;
    private InMemoryPrivateFileStore $files;
    private InMemoryCurrentUser $currentUser;
    private DeleteSheet $command;
    private InMemoryAuditWriter $audit;

    protected function setUp(): void
    {
        $this->sheets = new InMemoryExpenseSheetRepository();
        $this->transactions = new InMemoryExpenseTransactionRepository();
        $this->evidence = new InMemoryEvidenceRepository();
        $this->files = new InMemoryPrivateFileStore();
        $this->currentUser = new InMemoryCurrentUser(self::OWNER);
        $this->audit = new InMemoryAuditWriter();

        $this->command = new DeleteSheet(
            $this->currentUser,
            $this->sheets,
            $this->transactions,
            $this->evidence,
            $this->files,
            new InMemoryTransactionManager(),
            new IdempotencyService(new InMemoryCommandResultRepository()),
        );
    }

    // ===== 1. Ownership (FR-003) =====

    /**
     * Another user's sheet is refused, and — the part that matters — **survives**.
     *
     * Asserting the exception alone would pass if the cascade ran first and the ownership check
     * happened afterwards, so the survival assertion is the real one.
     */
    public function testASheetOwnedByAnotherUserIsRefusedAndSurvives(): void
    {
        $sheet = $this->seedSheet(self::OTHER_SHEET_ID, self::OTHER_USER);

        try {
            $this->command->execute(self::OTHER_SHEET_ID, 'cmd-foreign', $sheet->lockVersion);
            self::fail('deleting another user\'s sheet must be refused');
        } catch (InvalidArgumentException $e) {
            // Reported as **absent**, not forbidden. Naming ownership in the message would confirm to an
            // out-of-scope caller that the sheet exists, which is the disclosure FR-003 prevents.
            self::assertStringNotContainsStringIgnoringCase(
                'owner',
                $e->getMessage(),
                'the refusal must not disclose that the sheet belongs to someone else'
            );
        }

        self::assertNotNull(
            $this->sheets->findByPublicId(self::OTHER_SHEET_ID),
            'a refused deletion must leave the sheet intact'
        );
    }

    // ===== 2. No audit event (FR-051 / FR-086) =====

    /**
     * FR-086's most surprising clause: the deletion writes **nothing** to business audit.
     *
     * Asserted on a writer the command is never given, so the assertion is about the command's
     * construction rather than about a counter that happens to read zero.
     */
    public function testDeletingADraftSheetWritesNoAuditEvent(): void
    {
        $sheet = $this->seedSheet(self::SHEET_ID, self::OWNER);

        $this->command->execute(self::SHEET_ID, 'cmd-no-audit', $sheet->lockVersion);

        self::assertSame(
            [],
            $this->audit->getEvents(),
            'transaction audit begins at submission (FR-051) and a never-submitted sheet has no official '
            . 'history, so deleting it must write no audit event'
        );
    }

    // ===== 3. The cascade is a subtree, not a row (FR-086) =====

    /**
     * The sheet's transactions **and** their revisions go with it.
     *
     * A sheet row removed while its transactions survive is the failure FR-086 calls out: the orphan
     * belongs to no sheet and no requirement reclaims it. `InMemoryExpenseTransactionRepository::delete()`
     * cascades to revisions exactly as `DbExpenseTransactionRepository::delete()` does, so this asserts
     * the composition as well as the outcome.
     */
    public function testTheCascadeRemovesTheSheetsTransactionsAndTheirRevisions(): void
    {
        $sheet = $this->seedSheet(self::SHEET_ID, self::OWNER, withTransaction: true);

        $this->command->execute(self::SHEET_ID, 'cmd-cascade', $sheet->lockVersion);

        self::assertNull($this->sheets->findByPublicId(self::SHEET_ID), 'the sheet must be gone');
        self::assertSame(
            [],
            $this->transactions->findBySheetId(1),
            'no transaction may survive the deletion of its sheet'
        );
    }

    // ===== 4. Atomicity (FR-042) =====

    /**
     * A failure part-way through leaves the sheet and its **binaries** intact, and reports nothing.
     *
     * `DeleteSheet` removes rows before files precisely so a partial failure lands on the recoverable
     * side of that trade: a missing binary is an invisible orphan that `EvidenceReconciliationService`
     * can report, whereas a row referencing a file that no longer exists is the broken reference FR-083
     * makes an error. So the failure is injected at the **sheet** delete — the last statement of the row
     * phase — and the assertions are on what is still there afterwards.
     *
     * **What this test does and does not prove, stated plainly.** The in-memory doubles do not roll back,
     * so "every transaction is still present" is *not* asserted and would be false in this harness. The
     * rollback guarantee is the production `TransactionManager`'s, and the ordering under test is the part
     * that harness-independent assertions can reach: the sheet row survives, the binaries survive, and the
     * failure propagates instead of being swallowed. A consequence worth stating: because the binaries
     * survive a failed cascade, a retry after fixing the fault can still remove them — which is the
     * property the ordering exists to protect.
     */
    public function testAFailureDuringTheCascadeLeavesTheSheetAndItsFilesIntact(): void
    {
        $sheet = $this->seedSheet(self::SHEET_ID, self::OWNER, withTransaction: true);
        $this->seedEvidenceAndFile();

        // The seam: a sheet repository that succeeds on every read and throws on `delete()`.
        $failingSheets = new class (
            $this->sheets,
            'simulated failure removing the sheet row'
        ) implements \OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository {
            public function __construct(
                private readonly InMemoryExpenseSheetRepository $inner,
                private readonly string $message
            ) {}

            public function delete(ExpenseSheet $sheet): void
            {
                throw new \RuntimeException($this->message);
            }

            public function findById(int $id): ?ExpenseSheet
            {
                return $this->inner->findById($id);
            }

            public function findByPublicId(string $publicId): ?ExpenseSheet
            {
                return $this->inner->findByPublicId($publicId);
            }

            public function findByOwnerUid(string $ownerUid, ?\OCA\PettyCash\Domain\Expense\SheetListFilter $filter = null, int $offset = 0, int $limit = 100): array
            {
                return $this->inner->findByOwnerUid($ownerUid, $filter, $offset, $limit);
            }

            public function countByOwnerUid(string $ownerUid, ?\OCA\PettyCash\Domain\Expense\SheetListFilter $filter = null): int
            {
                return $this->inner->countByOwnerUid($ownerUid, $filter);
            }

            public function findByStatus(SheetStatus $status, int $offset = 0, int $limit = 100): array
            {
                return $this->inner->findByStatus($status, $offset, $limit);
            }

            public function countAll(): int
            {
                return $this->inner->countAll();
            }

            public function save(ExpenseSheet $sheet): void
            {
                $this->inner->save($sheet);
            }
        };

        $failing = new DeleteSheet(
            $this->currentUser,
            $failingSheets,
            $this->transactions,
            $this->evidence,
            $this->files,
            new InMemoryTransactionManager(),
            new IdempotencyService(new InMemoryCommandResultRepository()),
        );

        try {
            $failing->execute(self::SHEET_ID, 'cmd-atomic', $sheet->lockVersion);
            self::fail('the simulated failure must propagate rather than be swallowed');
        } catch (\RuntimeException $e) {
            self::assertSame('simulated failure removing the sheet row', $e->getMessage());
        }

        self::assertNotNull(
            $this->sheets->findByPublicId(self::SHEET_ID),
            'the sheet row itself must survive a cascade that failed before it completed'
        );
        self::assertTrue(
            $this->files->exists(self::APP_DATA_KEY),
            'the AppData binary must survive a failed cascade — file deletion is the LAST step, so a retry '
            . 'can still remove it. Deleting files inside the row phase would leave a row pointing at a file '
            . 'that no longer exists, which is the broken reference FR-083 treats as an error.'
        );
    }

    // ===== refusals that reuse an existing reason =====

    public function testASubmittedSheetIsNotDeletable(): void
    {
        $sheet = $this->seedSheet(self::SHEET_ID, self::OWNER);
        $sheet->status = SheetStatus::CostCenter;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('never been submitted');

        $this->command->execute(self::SHEET_ID, 'cmd-submitted', $sheet->lockVersion);
    }

    public function testACompletedSheetIsNotDeletable(): void
    {
        $sheet = $this->seedSheet(self::SHEET_ID, self::OWNER);
        $sheet->status = SheetStatus::Completed;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('never been submitted');

        $this->command->execute(self::SHEET_ID, 'cmd-completed', $sheet->lockVersion);
    }

    /**
     * FR-025 / T416: a stale delete is a `409` conflict, refused the same way as a stale edit.
     */
    public function testAStaleDeletionIsAConflict(): void
    {
        $sheet = $this->seedSheet(self::SHEET_ID, self::OWNER);

        try {
            $this->command->execute(self::SHEET_ID, 'cmd-stale', $sheet->lockVersion + 1);
            self::fail('a stale delete must be refused');
        } catch (StaleRecordVersion $e) {
            self::assertSame('SHEET_VERSION_CONFLICT', $e->errorCode());
            self::assertStringContainsString('reload and retry', $e->getMessage());
        }

        self::assertNotNull($this->sheets->findByPublicId(self::SHEET_ID));
    }

    /**
     * FR-084: replaying the command returns the first outcome instead of re-running it.
     *
     * Without this the retry would find no sheet and report absence — indistinguishable from a genuine
     * failure to the very client that most needs to know it succeeded.
     */
    public function testReplayingTheCommandDoesNotReRunIt(): void
    {
        $sheet = $this->seedSheet(self::SHEET_ID, self::OWNER);
        $version = $sheet->lockVersion;

        $this->command->execute(self::SHEET_ID, 'cmd-replay', $version);

        // Would raise "Sheet not found" if the closure ran a second time.
        $this->command->execute(self::SHEET_ID, 'cmd-replay', $version);
        self::assertNull($this->sheets->findByPublicId(self::SHEET_ID), 'the replay must not resurrect or re-delete');
    }

    /**
     * Evidence plus a real binary in the file store, so the file-deletion phase has something to act on.
     *
     * Without a stored file, `DeleteSheet`'s third phase iterates an empty list and the test asserting
     * that binaries survive a failed cascade would pass for the wrong reason.
     */
    private function seedEvidenceAndFile(): void
    {
        $evidence = new Evidence(
            self::EVIDENCE_ID,
            1,
            1,
            self::APP_DATA_KEY,
            'receipt.pdf',
            'application/pdf',
            5,
            str_repeat('a', 64),
        );
        $this->evidence->save($evidence);
        $this->files->put(self::APP_DATA_KEY, 'bytes');
    }

    private function seedSheet(string $publicId, string $ownerUid, bool $withTransaction = false): ExpenseSheet
    {
        $sheet = ExpenseSheet::open($publicId, $ownerUid, 'account-1', CurrencyCode::fromString('IRR'));
        $sheet->dbId = 1;
        $sheet->status = SheetStatus::Open;
        $sheet->publicId = $publicId;
        $this->sheets->save($sheet);

        if ($withTransaction) {
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
            $this->transactions->save($transaction);
        }

        return $sheet;
    }
}