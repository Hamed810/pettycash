<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Expense;

use InvalidArgumentException;
use OCA\PettyCash\Application\Command\CommandConflict;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\PrivateFileStore;
use OCA\PettyCash\Application\Port\TransactionManager;
use OCA\PettyCash\Application\StaleRecordVersion;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Workflow\SheetStatus;

/**
 * FR-086: an owner may delete their own sheet that has **never been submitted**.
 *
 * ## Why this existed at all
 *
 * FR-025 let a purchaser delete an individual draft *transaction*, FR-043/FR-044 locked a completed
 * sheet, and nothing anywhere said whether the draft **sheet** itself could be discarded. A purchaser who
 * started a sheet by mistake, or filled one with drafts they abandoned, had no way to remove it — the
 * shell stayed in their sheet list forever, and no `DeleteSheet` command existed under `lib/`.
 *
 * ## Why no audit row is written, which is the requirement's most surprising clause
 *
 * Transaction audit **begins at submission** (FR-051). A sheet that was never submitted produced no
 * submission, no routing, no decision, no revision and no financial effect, so there is no official
 * event for a deletion to erase and nothing for Principle IV to protect. `DeleteSheet::execute()`
 * therefore takes **no `AuditWriter`** and could not write a business event if it tried.
 *
 * The alternative — an audited draft deletion — was rejected because it has nowhere honest to live.
 * Filing it in transaction audit would put an event before that stream's own start; filing it in
 * configuration audit would file a purchaser removing their own unentered work under FR-055's
 * administrative-configuration scope, which it is not.
 *
 * ## Why the cascade order is rows-then-files, which is the reverse of `TransactionService::deleteTransaction`
 *
 * `TransactionService::deleteTransaction()` removes the AppData binaries first, then hands the rows to
 * the repository. That order is safe there because the repository delete cascades to the evidence rows
 * itself. Here the sheet row is removed by a *different* call, and a partial failure has to be
 * survivable, so this class does the safer order:
 *
 * 1. **Read every evidence key first**, while the rows that carry them still exist.
 * 2. **Delete all rows** — transactions (which cascades to revisions and evidence rows) then the sheet —
 *    inside one transaction, so this half is genuinely atomic.
 * 3. **Then delete the files.**
 *
 * The reasoning is which partial state is recoverable. A file system delete cannot participate in a
 * database rollback, so *something* can be left behind; the question is which. Fail before step 2 and
 * nothing is touched. Fail during step 2 and the rows roll back with every file still present. Fail
 * during step 3 and the rows are gone but the binaries are orphaned — invisible, unreferenced, and
 * reportable by `EvidenceReconciliationService`. The reverse order fails into the one state that is
 * genuinely broken: rows that reference files which no longer exist, which is the broken reference
 * FR-083 makes an error rather than papering over.
 *
 * The consequence is stated rather than hidden: **this command is atomic for data and best-effort for
 * files**, and an orphan binary is a known, detectable residue — never a dangling reference.
 */
final class DeleteSheet
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly ExpenseSheetRepository $sheetRepository,
        private readonly ExpenseTransactionRepository $transactionRepository,
        private readonly EvidenceRepository $evidenceRepository,
        private readonly PrivateFileStore $fileStore,
        private readonly TransactionManager $transactionManager,
        private readonly IdempotencyService $idempotencyService,
    ) {}

    /**
     * @throws CommandConflict when the command id was already used with a different payload (FR-084)
     * @throws StaleRecordVersion when the caller's `expectedVersion` is not the live version (FR-025)
     * @throws InvalidArgumentException when the sheet is absent, not the caller's, or not deletable
     */
    public function execute(string $sheetId, string $commandId, int $expectedVersion): void
    {
        // FR-084 replay safety, and deliberately **not** the exemption `DELETE /transactions/{id}`
        // claims. That operation treats a draft-row delete as repeatable because the second attempt has
        // nothing left to remove. A sheet delete is different: without this gate a retried command
        // reaches `findByPublicId()`, finds nothing, and answers "Sheet not found" — so a client that
        // lost the first response could not distinguish its own successful deletion from a genuine
        // failure, which is the exact reason FR-084 exists. `expectedVersion` is part of the payload
        // because it is part of the command's intent: the same id with a different version is a
        // different command, not a retry.
        $this->idempotencyService->execute(
            $commandId,
            [
                'sheet_id' => $sheetId,
                'expected_version' => $expectedVersion,
            ],
            function () use ($sheetId, $expectedVersion): array {
                $this->deleteOnce($sheetId, $expectedVersion);

                return ['deleted' => true, 'sheetId' => $sheetId];
            }
        );
    }

    /**
     * @throws StaleRecordVersion when the caller's `expectedVersion` is not the live version (FR-025)
     * @throws InvalidArgumentException when the sheet is absent, not the caller's, or not deletable
     */
    private function deleteOnce(string $sheetId, int $expectedVersion): void
    {
        $ownerUid = $this->currentUser->uid();
        $sheet = $this->sheetRepository->findByPublicId($sheetId);

        // FR-003: another user's sheet is reported as absent, never as forbidden. Distinguishing the two
        // would confirm that a sheet with this identifier exists.
        if ($sheet === null || $sheet->ownerUid !== $ownerUid) {
            throw new InvalidArgumentException('Sheet not found');
        }

        // FR-086 permits exactly one state. `OPEN` is it; `SUBMITTED` is FR-026's lock and the two
        // terminal states are FR-044's. The check is on status rather than on a separate flag so the
        // rule cannot drift from the status that actually carries it.
        if ($sheet->status !== SheetStatus::Open) {
            throw new InvalidArgumentException(
                'Only a sheet that has never been submitted can be deleted; this sheet is '
                . $sheet->status->value
            );
        }

        // FR-025 on the sheet's own row: the same optimistic check the edit path performs, refused the
        // same way, because a delete is a write and must not silently discard a concurrent change.
        if ($sheet->lockVersion !== $expectedVersion) {
            throw StaleRecordVersion::forResource(
                'sheet',
                $sheetId,
                $expectedVersion,
                $sheet->lockVersion
            );
        }

        $sheetDbId = $sheet->dbId;
        if ($sheetDbId === null) {
            throw new InvalidArgumentException('Sheet not found');
        }

        // Step 1 — collect the AppData keys while the rows that carry them still exist.
        $transactions = $this->transactionRepository->findBySheetId($sheetDbId);
        $appDataKeys = [];
        foreach ($transactions as $transaction) {
            if ($transaction->dbId === null) {
                continue;
            }
            foreach ($this->evidenceRepository->findByTransactionId($transaction->dbId) as $evidence) {
                $appDataKeys[] = $evidence->appDataKey;
            }
        }

        // Step 2 — remove every row atomically. `delete()` on a transaction already removes that
        // transaction's revisions and evidence rows, so this covers the whole subtree below the sheet.
        $this->transactionManager->transactional(function () use ($sheet, $transactions): void {
            foreach ($transactions as $transaction) {
                $this->transactionRepository->delete($transaction);
            }
            $this->sheetRepository->delete($sheet);
        });

        // Step 3 — remove the binaries. Outside the transaction on purpose: see the class docblock.
        foreach ($appDataKeys as $key) {
            $this->fileStore->deleteDraftEvidence($key);
        }
    }
}