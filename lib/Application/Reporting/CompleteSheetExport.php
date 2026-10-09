<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Reporting;

use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Audit\Repository\AuditEventRepository;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository;
use OCA\PettyCash\Domain\Workflow\Repository\WorkflowDecisionRepository;
use OCA\PettyCash\Infrastructure\Persistence\Expense\SheetSummaryQuery;

/**
 * T366 / FR-067: the six row groups of a complete-sheet export, for one sheet.
 *
 * ## Why this class exists
 *
 * `XlsxExporter::completeSheet()` builds the six-sheet layout the contract's `exports.md` specifies —
 * sheet, transactions, decisions, revisions, audit, evidence — and nothing called it. `ExportService`
 * routes every XLSX through `generateXlsx()`, which builds one flat `Rows` sheet, so a caller asking for
 * a complete sheet received a flat dump with no decisions, no revisions, no audit and no evidence. That
 * is FR-067's opposite: the requirement exists so a sheet can leave the system with its relationships
 * intact, and a flat row set carries none of them.
 *
 * ## Why one sheet, and why the controller decides that
 *
 * The layout is per-sheet by construction — every group carries the sheet's transactions' ids — so this
 * answers exactly one sheet. The controller routes `resource: sheet` with a single id here; anything else
 * keeps the flat rows path. A multi-sheet "complete" export would need one workbook per sheet or one
 * workbook with prefixed groups, and neither is specified, so neither is built.
 *
 * ## Why the groups are read here rather than in the controller
 *
 * The controller already holds two repositories and would need five more plus the summary query and the
 * user directory. `EvidenceSelectionQuery` and `AuditSelectionQuery` established the pattern this follows:
 * one query class per export shape, holding the reads its shape needs. The controller keeps the decision
 * of *which* shape; this class owns *what the shape contains*.
 *
 * ## Visibility
 *
 * The sheet itself is scope-checked here with the same rule the sheet list uses (`coversOwned`), and
 * everything beneath it inherits that visibility: decisions, revisions, audit events and evidence of a
 * transaction the caller may see are the transaction's own history, and FR-009/FR-055's per-stream rules
 * are about *which streams* a role may read, not about hiding a visible transaction's past from its
 * viewer. A sheet the caller may not see is refused with the same message as a sheet that does not
 * exist, so the endpoint cannot be used to discover which sheet ids are real.
 */
final class CompleteSheetExport
{
    public function __construct(
        private ExpenseSheetRepository $sheets,
        private ExpenseTransactionRepository $transactions,
        private TransactionRevisionRepository $revisions,
        private WorkflowDecisionRepository $decisions,
        private AuditEventRepository $audit,
        private EvidenceRepository $evidence,
        private SheetSummaryQuery $summary,
        private UserDirectory $users,
    ) {}

    /**
     * Date columns carry `DateTimeImmutable`, not formatted strings: `XlsxExporter` writes typed
     * date cells and refuses a string for one, so formatting here would trade a typed cell for text
     * a spreadsheet cannot total or sort as a date.
     *
     * @return array<string, list<array<string, mixed>>> keyed by the layout names
     *         `XlsxExporter::completeSheet()` reads
     * @throws UnsupportedSelection when the sheet is absent or outside the caller's scope
     */
    public function rowsBySheet(ReportScope $scope, string $sheetPublicId): array
    {
        $sheet = $this->sheets->findByPublicId($sheetPublicId);
        if ($sheet === null || !$scope->coversOwned($sheet->ownerUid)) {
            throw new UnsupportedSelection("No sheet is available to you with id '{$sheetPublicId}'.");
        }

        $totals = $this->summary->totalsForSheet($sheet->dbId);
        $sheetRow = [[
            'sheetPublicId' => $sheet->id,
            'title' => $sheet->title,
            'ownerUid' => $sheet->ownerUid,
            'ownerDisplayName' => $this->users->displayName($sheet->ownerUid),
            'currencyCode' => (string) $sheet->currency,
            'status' => $sheet->status->value,
            'createdAt' => $sheet->createdAt,
            'firstSubmittedAt' => $sheet->firstSubmittedAt,
            'currentTotalMinor' => $totals->current,
            'submittedTotalMinor' => $sheet->submittedTotalMinor,
            'effectiveApprovedMinor' => $totals->approved,
            'effectiveRejectedMinor' => $totals->rejected,
            'effectivePendingMinor' => $totals->pending,
            'costCenterApprovedMinor' => $totals->managerApproved,
            'seniorApprovedMinor' => $totals->seniorApproved,
            'financeApprovedMinor' => $totals->financeApproved,
        ]];

        $transactionRows = [];
        $decisionRows = [];
        $revisionRows = [];
        $auditRows = [];
        $evidenceRows = [];

        $transactions = $sheet->dbId === null
            ? []
            : $this->transactions->findBySheetId($sheet->dbId);

        foreach ($transactions as $transaction) {
            $active = $transaction->revision();
            $transactionRows[] = [
                'transactionPublicId' => $transaction->id,
                'revisionNumber' => $active?->number(),
                'expenseDate' => $active?->expenseDate(),
                'amountMinor' => $active === null ? null : $active->amount()->minorUnits(),
                'description' => $active?->description(),
                'costCenterCode' => $active?->costCenterCode,
                'costCenterName' => $active?->costCenterName,
                'expenseTypeTitle' => $active?->expenseTypeTitle(),
                'currentStage' => $transaction->stage->value,
                'currentStatus' => $transaction->status->value,
            ];

            if ($transaction->dbId === null) {
                continue;
            }

            foreach ($this->revisions->findByTransactionId($transaction->dbId) as $revision) {
                $revisionRows[] = [
                    'revisionPublicId' => $revision->id(),
                    'transactionPublicId' => $transaction->id,
                    'revisionNumber' => $revision->number(),
                    'active' => $revision->isActive(),
                    'expenseDate' => $revision->expenseDate(),
                    'amountMinor' => $revision->amount()->minorUnits(),
                    'description' => $revision->description(),
                    'costCenterCode' => $revision->costCenterCode,
                    'expenseTypeTitle' => $revision->expenseTypeTitle(),
                    'createdAt' => $revision->createdAt,
                    'createdBy' => $revision->createdBy,
                ];
            }

            foreach ($this->decisions->findByTransactionId($transaction->dbId) as $decision) {
                $decisionRows[] = [
                    'decisionPublicId' => $decision->id,
                    'transactionPublicId' => $decision->transactionId,
                    'stage' => $decision->stage->value,
                    'outcome' => $decision->decision->value,
                    'actorUid' => $decision->actorUid,
                    'actorDisplayName' => $decision->actorName,
                    'actorRole' => $decision->actorRole,
                    'explanation' => $decision->explanation,
                    'decidedAt' => $decision->decidedAt,
                ];
            }

            foreach ($this->audit->findByTransactionId($transaction->dbId) as $event) {
                $auditRows[] = [
                    'eventPublicId' => $event->id,
                    'occurredAt' => $event->occurredAt,
                    'eventType' => $event->eventType,
                    'stage' => $event->stage,
                    'actorUid' => $event->actorUid,
                    'actorDisplayName' => $event->actorName,
                    'actorRole' => $event->actorRole,
                    'explanation' => $event->explanation,
                    'beforeJson' => $event->beforeJson,
                    'afterJson' => $event->afterJson,
                ];
            }

            foreach ($this->evidence->findByTransactionId($transaction->dbId) as $item) {
                $evidenceRows[] = [
                    'evidencePublicId' => $item->id,
                    'transactionPublicId' => $transaction->id,
                    'revisionNumber' => $this->revisionNumberOf($item->originRevisionId),
                    'originatingRevisionPublicId' => $this->revisionPublicIdOf($item->originRevisionId),
                    'originalName' => $item->originalName,
                    'mediaType' => $item->mediaType,
                    'sizeBytes' => $item->sizeBytes,
                    'active' => $item->active,
                    'uploaderUid' => $item->uploadedBy,
                    'uploaderDisplayName' => $item->uploadedBy === null
                        ? null
                        : $this->users->displayName($item->uploadedBy),
                    'uploadedAt' => $item->uploadedAt,
                ];
            }
        }

        return [
            \OCA\PettyCash\Infrastructure\Export\XlsxExporter::SHEET => $sheetRow,
            \OCA\PettyCash\Infrastructure\Export\XlsxExporter::TRANSACTIONS => $transactionRows,
            \OCA\PettyCash\Infrastructure\Export\XlsxExporter::DECISIONS => $decisionRows,
            \OCA\PettyCash\Infrastructure\Export\XlsxExporter::REVISIONS => $revisionRows,
            \OCA\PettyCash\Infrastructure\Export\XlsxExporter::AUDIT => $auditRows,
            \OCA\PettyCash\Infrastructure\Export\XlsxExporter::EVIDENCE => $evidenceRows,
        ];
    }

    /**
     * An evidence row names its originating revision by surrogate id, and the layout names it by
     * number. Resolved per item rather than joined, because evidence is the smallest group and a join
     * across three tables for a display column would move a presentation concern into the query.
     */
    private function revisionNumberOf(int $revisionDbId): ?int
    {
        return $this->revisions->findById($revisionDbId)?->number();
    }

    private function revisionPublicIdOf(int $revisionDbId): ?string
    {
        return $this->revisions->findById($revisionDbId)?->id();
    }
}