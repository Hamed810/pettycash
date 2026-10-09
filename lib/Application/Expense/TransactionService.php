<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Expense;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\PrivateFileStore;
use OCA\PettyCash\Application\Port\TransactionManager;
use OCA\PettyCash\Application\StaleRecordVersion;
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
        private AuditWriter $auditWriter,
        private IdempotencyService $idempotencyService
    ) {}

    /**
     * Create a draft transaction in an open sheet.
     *
     * Wrapped in `IdempotencyService` because `contracts/ocs-api.yaml:436` declares
     * `X-PettyCash-Command-ID` `required: true` on this operation, and because FR-042 names "creating a
     * revision" as something that must complete atomically while FR-053 makes the audit rows permanent.
     * Without it, a retried request or a double-clicked button left a second transaction *and* a second
     * `REVISION_CREATED` event behind.
     *
     * The shape follows `SubmitSheet::execute()`: `transactional()`, then `execute()`, then re-read the
     * aggregate by public id. Two consequences of that shape are load-bearing here.
     *
     * The id is generated **inside** the closure, not before it. On a replay the closure never runs, so
     * an id minted outside would be a fresh value for a row that was never written, and re-reading by it
     * would return null for a command that in fact succeeded. The closure therefore returns the id it
     * used, and `execute()` hands that same value back on a replay, so there is exactly one source for
     * "which transaction did this command create".
     *
     * The sheet lookup and its ownership and status checks also moved inside, for the same reason: on a
     * replay they must not run, because re-validating a command that already succeeded is how a replay
     * turns into a spurious refusal.
     */
    public function createTransaction(
        string $sheetId,
        string $expenseDate,
        int $amountMinor,
        string $commandId
    ): ExpenseTransaction {
        $payload = [
            'sheet_id' => $sheetId,
            'expense_date' => $expenseDate,
            'amount_minor' => $amountMinor,
            'command_id' => $commandId,
        ];

        return $this->transactionManager->transactional(function () use ($sheetId, $expenseDate, $amountMinor, $commandId, $payload): ExpenseTransaction {
            $stored = $this->idempotencyService->execute($commandId, $payload, function () use ($sheetId, $expenseDate, $amountMinor, $commandId): array {
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

                // FR-051 "revision creation". Written inside the transaction so the event and the rows
                // it describes commit together -- an audit event for a revision that was rolled back
                // would be a record of something that never happened, and the reverse would lose the
                // event silently. Inside the idempotent closure, so a replay writes no second event.
                $this->auditWriter->append([
                    'id' => (string)Uuid::random(),
                    'sheet_id' => $sheet->dbId,
                    'transaction_id' => $transaction->dbId,
                    'revision_id' => $revision->dbId,
                    'stage' => WorkflowStage::Draft->value,
                    'decision_id' => null,
                    'entity_type' => 'transaction',
                    'entity_id' => $transaction->id,
                    'event_type' => AuditEventType::RevisionCreated->value,
                    'explanation' => '',
                    // A first revision supersedes nothing, so there is no prior state to compare
                    // against. Stated explicitly rather than left to look like a missing capture.
                    'before' => [],
                    'after' => [
                        'revision_number' => $revision->number(),
                        'amount_minor' => $revision->amount()->minorUnits(),
                        'currency' => (string) $revision->amount()->currency(),
                        'expense_date' => $revision->expenseDate()->format(\DateTimeInterface::ATOM),
                        'is_correction' => false,
                    ],
                    'command_id' => $commandId,
                ]);

                return ['transaction_id' => $transaction->id];
            });

            // Re-read the aggregate the command created, exactly as `SubmitSheet` re-reads its sheet.
            // On a replay `execute()` returned the stored response, so this resolves to the original
            // transaction rather than minting a second one.
            $transaction = $this->transactionRepository->findByPublicId($stored['transaction_id'] ?? '');
            if ($transaction === null) {
                throw new LogicException('Transaction not found after creation');
            }

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
     * @param string $commandId
     * @return ExpenseTransaction
     *
     * Wrapped in `IdempotencyService` because `contracts/ocs-api.yaml:466` declares
     * `X-PettyCash-Command-ID` `required: true` on this PATCH. It matters more here than on the create,
     * because this method creates a **new revision on every call** and writes a `TRANSACTION_CHANGED`
     * audit event for it, and FR-053 makes those rows permanent.
     *
     * `expectedVersion` is not a substitute. It refuses a *concurrent* edit, but a sequential retry of a
     * request that already succeeded would otherwise pass it, because the client resends the version it
     * originally read. Measured before this change: replaying a PATCH answered 422 stale-version,
     * which is the duplicate revision showing up as a confusing error rather than as a duplicate.
     *
     * The whole body -- ownership, sheet status, version, the revision build, the writes and the audit
     * event -- sits inside the closure, for the same reason as in `createTransaction()`: on a replay the
     * closure must not run, so none of these checks may fire a second time.
     */
    public function updateTransaction(
        string $transactionId,
        string $expenseDate,
        int $amountMinor,
        ?string $description,
        ?string $costCenterId,
        ?string $expenseTypeId,
        int $expectedVersion,
        string $commandId
    ): ExpenseTransaction {
        $payload = [
            'transaction_id' => $transactionId,
            'expense_date' => $expenseDate,
            'amount_minor' => $amountMinor,
            'description' => $description,
            'cost_center_id' => $costCenterId,
            'expense_type_id' => $expenseTypeId,
            'expected_version' => $expectedVersion,
            'command_id' => $commandId,
        ];

        return $this->transactionManager->transactional(function () use ($transactionId, $expenseDate, $amountMinor, $description, $costCenterId, $expenseTypeId, $expectedVersion, $commandId, $payload): ExpenseTransaction {
            $this->idempotencyService->execute($commandId, $payload, function () use ($transactionId, $expenseDate, $amountMinor, $description, $costCenterId, $expenseTypeId, $expectedVersion, $commandId): array {
        $ownerUid = $this->currentUser->uid();
        $transaction = $this->transactionRepository->findByPublicId($transactionId);

        if ($transaction === null || $transaction->ownerUid !== $ownerUid) {
            throw new InvalidArgumentException('Transaction not found');
        }

        $sheet = $this->sheetRepository->findById($transaction->sheetId ?? 0);
        if ($sheet === null) {
            // The sheet is still required even where the edit is permitted, because the revision
            // built below takes its currency from it. An unresolvable sheet is a data fault, not a
            // permission question, so it is refused here rather than defaulted.
            throw new InvalidArgumentException('Cannot update transactions in a submitted sheet');
        }

        // FR-026 forbids the purchaser from editing a transaction's business information after
        // submission, and names exactly one exception: correcting a *returned* transaction. That
        // exception was unreachable, because the check was made against the **sheet** and a returned
        // transaction's sheet is `CORRECTION` rather than `Open`. Every `PATCH` on a correction
        // draft was refused with "Cannot update transactions in a submitted sheet" (422), so US4/AC2
        // — "the purchaser changes its business information and resubmits it" — had no implementation
        // at all.
        //
        // The rule belongs to the transaction, not the sheet: what decides whether a purchaser may
        // edit is whether *this transaction* is still the purchaser's to correct. `AWAITING_PURCHASER_CORRECTION`
        // is exactly that state, and `workflow.md`'s Correction Contract clause 2 requires the owner
        // to be able to "create and edit the correction draft".
        //
        // This widens nothing else: a submitted, in-review, or settled transaction is still refused,
        // because the sheet is not open and its status is not `AWAITING_PURCHASER_CORRECTION`.
        $isCorrection = $transaction->status === \OCA\PettyCash\Domain\Workflow\TransactionStatus::AwaitingPurchaserCorrection;
        if ($sheet->status !== \OCA\PettyCash\Domain\Workflow\SheetStatus::Open && !$isCorrection) {
            throw new InvalidArgumentException('Cannot update transactions in a submitted sheet');
        }

        // FR-025 and `contracts/ocs-api.yaml:475` both require a `409`, not the `422` an
        // `InvalidArgumentException` becomes. See `StaleRecordVersion` for why this is a
        // conflict rather than a validation failure.
        if ($transaction->lockVersion !== $expectedVersion) {
            throw StaleRecordVersion::forResource(
                'transaction',
                $transactionId,
                $expectedVersion,
                $transaction->lockVersion
            );
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
            // T330. Carried alongside the title and the join key, mirroring how `$costCenterPublicId`
            // is carried alongside `$costCenterCode`. Without it the revision had no public id at all,
            // so `Revision.expenseType.id` had to be filled with the title -- and the correction form
            // sends that field straight back here, where it resolves to nothing.
            $expenseTypePublicId = $expenseType->publicId;
        } else {
            $expenseTypeTitle = $current->expenseTypeTitle();
            $expenseTypeDbId = $current->expenseTypeDbId ?? 0;
            $expenseTypePublicId = $current->expenseTypeId();
        }

        $revision->complete(
            $description ?? $current->description(),
            $costCenterPublicId,
            $costCenterCode,
            $costCenterName,
            $costCenterDbId,
            $expenseTypeTitle,
            $expenseTypeDbId,
            $current->hasEvidence(),
            $expenseTypePublicId
        );

        // A transaction being corrected must NOT be activated by an edit.
        //
        // `activate()` does three things at once — it marks the revision active, moves the stage to
        // `COST_CENTER`, and sets the status to `AWAITING_COST_CENTER_REVIEW`. On an ordinary draft
        // that is harmless, because nothing has been submitted yet. On a **correction draft** it meant
        // a purchaser's edit ended correction and routed the expense to a cost-centre manager before
        // they had resubmitted anything, contradicting `workflow.md`'s Correction Contract clause 5
        // ("Activation atomically switches the active revision and routes it to cost-center review" —
        // at resubmission) and FR-050's rule that the active revision determines current information.
        //
        // So a correction attaches its edited revision as the one being worked on and leaves stage,
        // status, and the revision's own `active` flag untouched. Everything else — the drafts on an
        // open sheet — keeps the previous behaviour, because `TransactionServiceUpdateTest` pins it.
        if ($isCorrection) {
            $transaction->attachWorkingRevision($revision);
        } else {
            $transaction->activate($revision);
            $transaction->lockVersion++;
        }

            // See createTransaction(): the revision needs the transaction's internal
            // id, and the transaction's active_revision_id needs the revision's.
            $this->transactionRepository->save($transaction);

            $revision->transactionDbId = $transaction->dbId;
            $this->revisionRepository->save($revision);

            $this->transactionRepository->save($transaction);

            // FR-051 "transaction changes", and the one event kind whose whole purpose is a
            // before-and-after pair: a change is only legible as a diff. The prior values come from
            // `$current`, the revision as it stood *before* `complete()` ran, so the snapshot is the real
            // previous state and not a re-read of the already-mutated object. It sits inside the
            // idempotent closure, so a replayed edit writes no second event.
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
                'command_id' => $commandId,
            ]);

                return ['transaction_id' => $transaction->id];
            });

            // Re-read, exactly as `SubmitSheet` re-reads its sheet and `createTransaction()` re-reads its
            // transaction. On a replay `execute()` returned the stored response, so this resolves to the
            // transaction as the *first* call left it -- same version, same active revision -- instead of
            // a second edit.
            $stored = $this->transactionRepository->findByPublicId($transactionId);
            if ($stored === null) {
                throw new LogicException('Transaction not found after update');
            }

            return $stored;
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

        // The contract declares `409` on this DELETE (`contracts/ocs-api.yaml:475` and the
        // `Conflict` response it references), so a stale write must not answer 422 here either.
        if ($transaction->lockVersion !== $expectedVersion) {
            throw StaleRecordVersion::forResource(
                'transaction',
                $transactionId,
                $expectedVersion,
                $transaction->lockVersion
            );
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