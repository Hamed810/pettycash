<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Expense;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\PrivateFileStore;
use OCA\PettyCash\Application\Port\TransactionManager;
use OCA\PettyCash\Domain\Configuration\Repository\CostCenterRepository;
use OCA\PettyCash\Domain\Configuration\Repository\ExpenseTypeRepository;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Shared\Enum\AuditEventType;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCA\PettyCash\Domain\Workflow\TransactionStatus;

final readonly class TransactionService
{
    public function __construct(
        private ExpenseSheetRepository $sheetRepository,
        private ExpenseTransactionRepository $transactionRepository,
        private TransactionRevisionRepository $revisionRepository,
        private CostCenterRepository $costCenterRepository,
        private ExpenseTypeRepository $expenseTypeRepository,
        private EvidenceRepository $evidenceRepository,
        private PrivateFileStore $fileStore,
        private CurrentUser $currentUser,
        private TransactionManager $transactionManager,
        private AuditWriter $auditWriter
    ) {}

    /**
     * Create a draft transaction in an open sheet
     *
     * @param string $sheetId
     * @param string $expenseDate
     * @param int $amountMinor
     * @return ExpenseTransaction
     */
    public function createTransaction(string $sheetId, string $expenseDate, int $amountMinor): ExpenseTransaction
    {
        $ownerUid = $this->currentUser->uid();
        $sheet = $this->sheetRepository->findByPublicId($sheetId);

        if ($sheet === null || $sheet->ownerUid !== $ownerUid) {
            throw new InvalidArgumentException('Sheet not found');
        }

        if ($sheet->status !== \OCA\PettyCash\Domain\Workflow\SheetStatus::Open) {
            throw new InvalidArgumentException('Cannot add transactions to a submitted sheet');
        }

        $date = new DateTimeImmutable($expenseDate);
        $currency = $sheet->currency;
        $amount = Money::positive($amountMinor, $currency);

        $transactionId = (string)Uuid::random();
        $revisionId = (string)Uuid::random();

        $revision = TransactionRevision::draft($revisionId, 1, $date, $amount);
        $transaction = new ExpenseTransaction($transactionId, $ownerUid, $revision);
        $transaction->sheetId = $sheet->dbId;

        return $this->transactionManager->transactional(function () use ($sheet, $transaction, $revision): ExpenseTransaction {
            // `pettycash_transaction_revisions.transaction_id` is a NOT NULL join key onto
            // `pettycash_transactions.id`, and `pettycash_transactions.active_revision_id` points
            // back at the revision, so the two rows have to be written in three
            // steps inside one transaction: the transaction first (leaving
            // active_revision_id null), then the revision, then the back-link.
            $this->transactionRepository->save($transaction);

            $revision->transactionDbId = $transaction->dbId;
            $this->revisionRepository->save($revision);

            $this->transactionRepository->save($transaction);

            $sheet->addTransaction($transaction);
            $this->sheetRepository->save($sheet);

            // FR-051 "revision creation". Written inside the transaction so the event and the rows it
            // describes commit together -- an audit event for a revision that was rolled back would be a
            // record of something that never happened, and the reverse would lose the event silently.
            $this->auditWriter->append([
                'id' => (string)Uuid::random(),
                'sheet_id' => $sheet->dbId,
                'transaction_id' => $transaction->dbId,
                'revision_id' => $revision->dbId,
                'stage' => WorkflowStage::Draft->value,
                'decision_id' => null,
                'entity_type' => 'transaction',
                'entity_id' => $transaction->publicId,
                'event_type' => AuditEventType::RevisionCreated->value,
                'explanation' => '',
                // A first revision supersedes nothing, so there is no prior state to compare against.
                // Stated explicitly rather than left to look like a missing capture.
                'before' => [],
                'after' => [
                    'revision_number' => $revision->number(),
                    'amount_minor' => $revision->amount()->minorUnits(),
                    'currency' => (string) $revision->amount()->currency(),
                    'expense_date' => $revision->expenseDate()->format(\DateTimeInterface::ATOM),
                    'is_correction' => false,
                ],
                'command_id' => null,
            ]);

            return $transaction;
        });
    }

    /**
     * Update a draft transaction
     *
     * @param string $transactionId
     * @param string $expenseDate
     * @param int $amountMinor
     * @param string|null $description
     * @param string|null $costCenterId   public id; code and name are snapshotted from it
     * @param string|null $expenseTypeId  public id; the title is snapshotted from it
     * @param int $expectedVersion
     * @return ExpenseTransaction
     */
    public function updateTransaction(
        string $transactionId,
        string $expenseDate,
        int $amountMinor,
        ?string $description,
        ?string $costCenterId,
        ?string $expenseTypeId,
        int $expectedVersion
    ): ExpenseTransaction {
        $ownerUid = $this->currentUser->uid();
        $transaction = $this->transactionRepository->findByPublicId($transactionId);

        if ($transaction === null || $transaction->ownerUid !== $ownerUid) {
            throw new InvalidArgumentException('Transaction not found');
        }

        $sheet = $this->sheetRepository->findById($transaction->sheetId ?? 0);
        if ($sheet === null || $sheet->status !== \OCA\PettyCash\Domain\Workflow\SheetStatus::Open) {
            throw new InvalidArgumentException('Cannot update transactions in a submitted sheet');
        }

        if ($transaction->lockVersion !== $expectedVersion) {
            throw new InvalidArgumentException('Stale version');
        }

        $date = new DateTimeImmutable($expenseDate);
        $currency = $sheet->currency;
        $amount = Money::positive($amountMinor, $currency);

        // Create a new revision for the update
        $revisionId = (string)Uuid::random();
        $revision = TransactionRevision::draft($revisionId, $transaction->revision()->number() + 1, $date, $amount, $transaction->revision()->id());

        // A partial edit must not erase the fields it does not mention, and must not half-write the
        // ones it does.
        //
        // `PATCH /transactions/{id}` with only an expense date and an amount is a legitimate request,
        // and building a fresh revision left the description, cost centre and expense type null -- so
        // `assertSubmittable()` then rejected the purchaser's own transaction with "Revision is
        // incomplete", and had it succeeded it would have silently dropped real business data. The
        // current revision is therefore the source of truth for anything the caller omitted.
        //
        // Each of the two references is then resolved *independently*. The earlier version gated the
        // resolution on all three fields being present and used the raw caller values in the fallback,
        // which produced two silent corruptions, both measured by
        // `tests/Integration/Expense/TransactionServiceUpdateTest.php`:
        //
        // 1. **A UUID stored as a title.** The parameter was named `$expenseTypeTitle` while the full
        //    branch did `findByPublicId()` on it, so the partial branch passed the caller's *public id*
        //    straight into `complete()`'s `string $expenseTypeTitle` parameter. A PATCH that supplied a
        //    cost centre and an expense type but omitted the description wrote
        //    `88888888-8888-4888-8888-888888888888` into the expense-type-title column.
        // 2. **A new id with the previous centre's snapshot.** The partial branch took the caller's
        //    *new* cost-centre id while reusing the *current* revision's `costCenterCode` and
        //    `costCenterName`, so a revision claimed to be CC-200 by id and CC-100 by name.
        //
        // Both are worse than the failure they replaced, because the finance queue reads these columns
        // and neither value is wrong-looking. FR-037 requires the code and name to be snapshotted, and
        // a snapshot of a different record than the one referenced is not a snapshot.
        $current = $transaction->revision();

        if ($costCenterId !== null) {
            $costCenter = $this->costCenterRepository->findByPublicId($costCenterId);
            if ($costCenter === null) {
                throw new InvalidArgumentException("Cost center not found: {$costCenterId}");
            }

            $costCenterPublicId = $costCenter->publicId;
            $costCenterCode = $costCenter->code();
            $costCenterName = $costCenter->name;
            $costCenterDbId = $costCenter->id ?? 0;
        } else {
            $costCenterPublicId = $current->costCenterId();
            $costCenterCode = $current->costCenterCode;
            $costCenterName = $current->costCenterName;
            $costCenterDbId = $current->costCenterDbId ?? 0;
        }

        if ($expenseTypeId !== null) {
            $expenseType = $this->expenseTypeRepository->findByPublicId($expenseTypeId);
            if ($expenseType === null) {
                throw new InvalidArgumentException("Expense type not found: {$expenseTypeId}");
            }
            if (!$expenseType->active) {
                throw new InvalidArgumentException("Expense type is inactive: {$expenseTypeId}");
            }

            $expenseTypeTitle = $expenseType->title;
            $expenseTypeDbId = $expenseType->id ?? 0;
        } else {
            $expenseTypeTitle = $current->expenseTypeTitle();
            $expenseTypeDbId = $current->expenseTypeDbId ?? 0;
        }

        $revision->complete(
            $description ?? $current->description(),
            $costCenterPublicId,
            $costCenterCode,
            $costCenterName,
            $costCenterDbId,
            $expenseTypeTitle,
            $expenseTypeDbId,
            $current->hasEvidence()
        );

        $transaction->activate($revision);
        $transaction->lockVersion++;

        return $this->transactionManager->transactional(function () use ($transaction, $revision, $current): ExpenseTransaction {
            // See createTransaction(): the revision needs the transaction's internal
            // id, and the transaction's active_revision_id needs the revision's.
            $this->transactionRepository->save($transaction);

            $revision->transactionDbId = $transaction->dbId;
            $this->revisionRepository->save($revision);

            $this->transactionRepository->save($transaction);

            // FR-051 "transaction changes", and the one event kind whose whole purpose is a
            // before-and-after pair: a change is only legible as a diff. The prior values come from
            // `$current`, the revision as it stood *before* `complete()` ran, so the snapshot is the real
            // previous state and not a re-read of the already-mutated object.
            //
            // `command_id` is null because this entry point is not command-wrapped; see the report.
            $this->auditWriter->append([
                'id' => (string)Uuid::random(),
                'sheet_id' => $transaction->sheetId,
                'transaction_id' => $transaction->dbId,
                'revision_id' => $revision->dbId,
                'stage' => WorkflowStage::Draft->value,
                'decision_id' => null,
                'entity_type' => 'transaction',
                'entity_id' => $transaction->publicId,
                'event_type' => AuditEventType::TransactionChanged->value,
                'explanation' => '',
                'before' => [
                    'revision_number' => $current->number(),
                    'description' => $current->description(),
                    'cost_center_id' => $current->costCenterId(),
                    'cost_center_code' => $current->costCenterCode,
                    'cost_center_name' => $current->costCenterName,
                    'expense_type_title' => $current->expenseTypeTitle(),
                ],
                'after' => [
                    'revision_number' => $revision->number(),
                    'description' => $revision->description(),
                    'cost_center_id' => $revision->costCenterId(),
                    'cost_center_code' => $revision->costCenterCode,
                    'cost_center_name' => $revision->costCenterName,
                    'expense_type_title' => $revision->expenseTypeTitle(),
                ],
                'command_id' => null,
            ]);

            return $transaction;
        });
    }

    /**
     * Delete a draft transaction from an open sheet
     *
     * FR-025 lets a purchaser add, edit, and delete transactions before submission, and
     * FR-058 makes that the point after which records stop being removable. The delete
     * has to actually remove the row: filtering the sheet's in-memory collection and
     * re-saving it leaves the transaction in the database and in every later total
     * while the caller is told it is gone.
     *
     * The transaction's own draft evidence goes with it — the AppData binaries first,
     * then the metadata rows — because that evidence is only removable while it is
     * still pre-submission. Nothing belonging to a submitted transaction is touched.
     *
     * @param string $transactionId
     * @param int $expectedVersion
     */
    public function deleteTransaction(string $transactionId, int $expectedVersion): void
    {
        $ownerUid = $this->currentUser->uid();
        $transaction = $this->transactionRepository->findByPublicId($transactionId);

        if ($transaction === null || $transaction->ownerUid !== $ownerUid) {
            throw new InvalidArgumentException('Transaction not found');
        }

        $sheet = $this->sheetRepository->findById($transaction->sheetId ?? 0);
        if ($sheet === null || $sheet->status !== SheetStatus::Open) {
            throw new InvalidArgumentException('Cannot delete transactions from a submitted sheet');
        }

        if ($transaction->lockVersion !== $expectedVersion) {
            throw new InvalidArgumentException('Stale version');
        }

        // An open sheet is not sufficient on its own: a transaction that has already
        // been submitted and returned for correction sits in an open sheet again, and
        // deleting it would erase submitted business history.
        if ($transaction->status !== TransactionStatus::Draft) {
            throw new InvalidArgumentException('Only a draft transaction can be deleted');
        }

        $this->transactionManager->transactional(function () use ($sheet, $transaction): void {
            $transactionDbId = $transaction->dbId;
            if ($transactionDbId !== null) {
                // Remove the AppData binaries while the keys are still known. The
                // metadata rows are removed by the repository delete below, which
                // cannot reach the file store.
                foreach ($this->evidenceRepository->findByTransactionId($transactionDbId) as $evidence) {
                    $this->fileStore->deleteDraftEvidence($evidence->appDataKey);
                }
            }

            $sheet->removeTransaction($transaction->id);
            $this->transactionRepository->delete($transaction);
            $this->sheetRepository->save($sheet);
        });
    }

    /**
     * Get a transaction by ID with owner-scoped access
     *
     * @param string $transactionId
     * @return ExpenseTransaction|null
     */
    public function getTransaction(string $transactionId): ?ExpenseTransaction
    {
        $ownerUid = $this->currentUser->uid();
        $transaction = $this->transactionRepository->findByPublicId($transactionId);

        if ($transaction === null || $transaction->ownerUid !== $ownerUid) {
            return null;
        }

        return $transaction;
    }
}