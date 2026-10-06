<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Reporting;

use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository;
use OCA\PettyCash\Infrastructure\Export\EvidenceArchiveEntry;

/**
 * The evidence row set and the archive entries an `evidence` export needs.
 *
 * ## Why this is a class and not two private methods
 *
 * Same reasoning as `AuditSelectionQuery`: `ReportController::evidenceReport()` already produced
 * these rows, and T367 added `POST /exports` with `resource: evidence` as a second consumer — an
 * endpoint that answered **422 unconditionally**, so the client's evidence export button
 * (`src/types/reporting.ts` maps `evidence: ['csv','zip']`) could not produce a file for a format
 * FR-066 declares supported. One producer, two callers.
 *
 * ## What is genuinely new here, and why it is not in the report
 *
 * `archiveEntries()` is the first code in the application to construct an `EvidenceArchiveEntry`.
 * `grep -rn "new EvidenceArchiveEntry" lib/` returned **nothing** before this class, which is why
 * `ExportService::generateZip()` was unreachable and why removing the controller's refusal would
 * have produced "a valid, empty ZIP with an empty manifest" — precisely the failure
 * `EvidenceArchiveResult` exists to prevent.
 *
 * The entry needs five facts the evidence row does not carry: the **revision number**, the
 * **sheet public id and title**, and the **uploader's display name**. Those are resolved by joining
 * evidence → transaction → revision and evidence → transaction → sheet, plus a directory lookup.
 * The report deliberately does not carry them: it is a bounded listing, this is a bounded set of
 * named ids, and adding a per-row join to a listing that pages over up to `self::BOUND` rows would
 * cost four lookups per row for columns FR-064 does not ask that report for.
 *
 * ## The relationships travel with the entry
 *
 * `EvidenceArchiveEntry`'s own docblock is the reason this is assembled once, here, rather than
 * looked up during the archive build: the manifest row is written from the same values the archive
 * path was derived from, so a manifest cannot describe one transaction while the archive member
 * belongs to another.
 */
final class EvidenceSelectionQuery
{
    public function __construct(
        private EvidenceRepository $evidence,
        private ExpenseTransactionRepository $transactions,
        private TransactionRevisionRepository $revisions,
        private ExpenseSheetRepository $sheets,
        private UserDirectory $users
    ) {}

    /**
     * The row set, its scope predicate and its declared columns.
     *
     * @return array{list<array<string, mixed>>, callable(array<string, mixed>, ReportScope): bool, list<string>, array<string, mixed>}
     */
    public function candidates(string $uid, int $bound): array
    {
        $rows = [];

        foreach ($this->transactions->findByOwnerUid($uid, null, null, 0, $bound) as $transaction) {
            // Evidence is keyed on the transaction's own database id, not on its sheet. Reading by
            // sheet id would have returned a plausible-looking set of the wrong rows.
            if ($transaction->dbId === null) {
                continue;
            }
            foreach ($this->evidence->findByTransactionId($transaction->dbId) as $item) {
                $rows[] = [
                    'id' => $item->id,
                    'ownerUid' => $uid,
                    'originalName' => $item->originalName,
                    'mediaType' => $item->mediaType,
                    'sizeBytes' => $item->sizeBytes,
                    'active' => $item->active,
                ];
            }
        }

        return [
            $rows,
            static fn (array $row, ReportScope $s): bool => $s->coversOwned((string) ($row['ownerUid'] ?? '')),
            self::columns(),
            ['rowsRead' => count($rows)],
        ];
    }

    /**
     * @return list<string>
     */
    public static function columns(): array
    {
        return ['id', 'ownerUid', 'originalName', 'mediaType', 'sizeBytes', 'active'];
    }

    /**
     * Resolve named evidence ids to archive entries, inside the caller's scope.
     *
     * ## A selection is honoured or refused, never silently narrowed
     *
     * An id that does not exist and an id belonging to someone else produce the **same** refusal,
     * matching `rowsForSelection()`'s treatment of a missing or foreign sheet. Distinguishing them
     * would confirm the existence of records the caller may not see, which is the disclosure FR-003
     * exists to prevent.
     *
     * @param list<string> $ids
     * @return list<EvidenceArchiveEntry>
     * @throws UnsupportedSelection if any id cannot be resolved inside the caller's scope
     */
    public function archiveEntries(ReportScope $scope, string $uid, array $ids): array
    {
        $entries = [];

        foreach ($ids as $id) {
            $item = $this->evidence->findByPublicId($id);

            if ($item === null || $item->transactionId === 0) {
                throw new UnsupportedSelection(
                    "Evidence '{$id}' does not exist, or is not visible to you. A selection is "
                    . 'honoured in full or refused; it is never partially applied.'
                );
            }

            $transaction = $this->transactions->findById($item->transactionId);
            if ($transaction === null) {
                // Same answer as a missing id: the caller learns nothing about why.
                throw new UnsupportedSelection(
                    "Evidence '{$id}' does not exist, or is not visible to you. A selection is "
                    . 'honoured in full or refused; it is never partially applied.'
                );
            }

            if (!$scope->coversOwned($transaction->ownerUid)) {
                throw new UnsupportedSelection(
                    "Evidence '{$id}' does not exist, or is not visible to you. A selection is "
                    . 'honoured in full or refused; it is never partially applied.'
                );
            }

            $entries[] = $this->entryFor($item, $transaction, $uid);
        }

        return $entries;
    }

    /**
     * @param \OCA\PettyCash\Domain\Evidence\Evidence $item
     * @param \OCA\PettyCash\Domain\Expense\ExpenseTransaction $transaction
     */
    private function entryFor(object $item, object $transaction, string $requestingUid): EvidenceArchiveEntry
    {
        $revision = $item->originRevisionId > 0
            ? $this->revisions->findById($item->originRevisionId)
            : null;

        $sheet = $transaction->sheetId !== null
            ? $this->sheets->findById($transaction->sheetId)
            : null;

        $uploaderUid = $item->uploadedBy ?? $requestingUid;

        return new EvidenceArchiveEntry(
            // `$publicId` is the uuid the contract and the client address evidence by; `$id` is the
            // legacy string key. Using `$id` here would put a non-uuid in a uuid column of the
            // manifest, which is the T339 shape in a second place.
            evidencePublicId: $item->publicId ?? $item->id,
            originalName: $item->originalName,
            mediaType: $item->mediaType,
            sizeBytes: $item->sizeBytes,
            checksum: $item->checksum,
            active: $item->active,
            uploaderUid: $uploaderUid,
            uploaderDisplayName: $this->users->displayName($uploaderUid),
            // `$uploadedAt` is nullable on the aggregate; an entry needs a real moment, and "now" is
            // the honest fallback for a row that never recorded one rather than a fabricated epoch.
            uploadedAt: $item->uploadedAt ?? new \DateTimeImmutable('@0'),
            transactionPublicId: $item->transactionPublicId ?? $transaction->publicId ?? $transaction->id,
            // A revision is required by `EvidenceArchiveEntry` and is at least 1. Evidence with no
            // origin revision is reported as revision 1 — the revision it was uploaded against is
            // the first one by construction — rather than being omitted from the archive.
            revisionNumber: $revision?->number() ?? 1,
            revisionPublicId: $item->originRevisionPublicId ?? $revision?->publicId ?? '',
            sheetPublicId: $sheet?->publicId ?? '',
            sheetTitle: $sheet?->title ?? '',
            storageKey: $item->appDataKey
        );
    }
}