<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Reporting;

/**
 * T315: the one shared definition of the report and export row projections.
 *
 * `ReportController::sheetsReport()` and `ExportController::sheetRows()` built
 * the same sheet columns, and the transaction equivalents the same twelve, in
 * two controllers. They agreed by construction rather than by sharing code,
 * and T306 changed both in the same commit to keep them that way. That is a
 * convention, not an invariant, and the failure mode is already known: T307,
 * where the filter and the rows each held a private idea of the row vocabulary
 * and neither matched the other.
 *
 * Both controllers now reference these constants and builders. A column added
 * to one and not the other fails `ReportProjectionTest` rather than shipping
 * a facet answerable on the export and refused on the report.
 *
 * ## Why two sheet column sets
 *
 * The export carries the three stage-approved figures beside the submitted
 * total (FR-062, T365); the report's sheet rows do not, because the report
 * controller holds no `SheetSummaryQuery` and a per-row summary query on a
 * bounded enumeration would be an N+1 the report never needed. The base seven
 * are therefore shared verbatim, and the extended ten are base plus the three
 * figures, in order. The report references BASE; the export references
 * EXTENDED. Neither declares its own literal list.
 */
final class ReportProjection
{
    /**
     * The seven sheet columns both endpoints agree on.
     *
     * @var list<string>
     */
    public const SHEET_BASE_COLUMNS = [
        'id', 'title', 'ownerUid', 'status', 'currency', 'submittedTotalMinor', 'createdAt',
    ];

    /**
     * The export's sheet columns: base plus the three stage-approved figures.
     *
     * Named exactly as `XlsxExporter::COMPLETE_SHEET_COLUMNS` names them, so
     * the flat and complete-sheet artifacts cannot drift into spelling the
     * same figure two ways (T365).
     *
     * @var list<string>
     */
    public const SHEET_COLUMNS = [
        'id', 'title', 'ownerUid', 'status', 'currency', 'submittedTotalMinor',
        'costCenterApprovedMinor', 'seniorApprovedMinor', 'financeApprovedMinor', 'createdAt',
    ];

    /**
     * The `SHEET_COLUMNS` entries that must be numeric cells in XLSX, not text.
     *
     * @var list<string>
     */
    public const SHEET_NUMERIC_COLUMNS = [
        'submittedTotalMinor', 'costCenterApprovedMinor', 'seniorApprovedMinor', 'financeApprovedMinor',
    ];

    /**
     * The twelve transaction columns both endpoints agree on.
     *
     * A transaction's cost centre, expense type, amount and currency come from
     * its own active revision, not its sheet (FR-028), which is why the
     * revision-derived columns are part of the shared shape rather than a
     * per-endpoint choice.
     *
     * @var list<string>
     */
    public const TRANSACTION_COLUMNS = [
        'id', 'ownerUid', 'stage', 'status', 'sheetId', 'createdAt',
        'costCenterDbId', 'costCenterCode', 'expenseTypeDbId', 'expenseTypeTitle', 'amountMinor', 'currency',
    ];

    /**
     * The columns of the Feature 007 `senior-queue` export row (T028).
     *
     * Combined Cost Sheet + Transaction shape with **separate** Cost Center and Senior decision
     * fields, per the additive contract notes (`contracts/senior-review-api.md`) and owner D4.
     * The two decision fields are never merged, and neither is ever rolled up to a sheet verdict
     * (FR-007-004/051).
     *
     * @var list<string>
     */
    public const SENIOR_QUEUE_COLUMNS = [
        'sheetId', 'sheetTitle', 'sheetCurrency', 'submittedTotalMinor',
        'transactionId', 'description', 'expenseType', 'amountMinor', 'currency', 'expenseDate',
        'stage', 'status', 'costCenterDecision', 'seniorDecision',
    ];

    /**
     * The `SENIOR_QUEUE_COLUMNS` entries that must be numeric cells in XLSX, not text.
     *
     * @var list<string>
     */
    public const SENIOR_QUEUE_NUMERIC_COLUMNS = [
        'submittedTotalMinor', 'amountMinor',
    ];

    /**
     * Build a base sheet row from a sheet aggregate.
     *
     * @param object $sheet ExpenseSheet aggregate (publicId, title, ownerUid, status, currency, submittedTotalMinor, createdAt)
     * @return array<string, mixed>
     */
    public static function sheetRow(object $sheet): array
    {
        return [
            'id' => $sheet->publicId,
            'title' => $sheet->title,
            'ownerUid' => $sheet->ownerUid,
            'status' => $sheet->status->value,
            'currency' => (string) $sheet->currency,
            'submittedTotalMinor' => $sheet->submittedTotalMinor,
            'createdAt' => $sheet->createdAt?->format(DATE_ATOM),
        ];
    }

    /**
     * Build an extended sheet row (base plus stage-approved figures).
     *
     * Key order matches SHEET_COLUMNS exactly: base through submittedTotalMinor,
     * then the three figures, then createdAt.
     *
     * @param object $sheet ExpenseSheet aggregate
     * @param array{costCenterApprovedMinor: int, seniorApprovedMinor: int, financeApprovedMinor: int} $stageTotals
     * @return array<string, mixed>
     */
    public static function sheetRowWithTotals(object $sheet, array $stageTotals): array
    {
        return [
            'id' => $sheet->publicId,
            'title' => $sheet->title,
            'ownerUid' => $sheet->ownerUid,
            'status' => $sheet->status->value,
            'currency' => (string) $sheet->currency,
            'submittedTotalMinor' => $sheet->submittedTotalMinor,
            'costCenterApprovedMinor' => $stageTotals['costCenterApprovedMinor'],
            'seniorApprovedMinor' => $stageTotals['seniorApprovedMinor'],
            'financeApprovedMinor' => $stageTotals['financeApprovedMinor'],
            'createdAt' => $sheet->createdAt?->format(DATE_ATOM),
        ];
    }

    /**
     * Build a transaction row from a transaction aggregate (reads its active revision).
     *
     * @param object $transaction ExpenseTransaction aggregate
     * @return array<string, mixed>
     */
    public static function transactionRow(object $transaction): array
    {
        $revision = $transaction->revision();

        return [
            'id' => $transaction->publicId,
            'ownerUid' => $transaction->ownerUid,
            'stage' => $transaction->stage->value,
            'status' => $transaction->status->value,
            'sheetId' => $transaction->sheetId,
            'createdAt' => $transaction->createdAt?->format(DATE_ATOM),
            'costCenterDbId' => $revision->costCenterDbId,
            'costCenterCode' => $revision->costCenterCode,
            'expenseTypeDbId' => $revision->expenseTypeDbId,
            'expenseTypeTitle' => $revision->expenseTypeTitle(),
            'amountMinor' => $revision->amount()->minorUnits(),
            'currency' => (string) $revision->amount()->currency(),
        ];
    }

    /**
     * Build a Feature 007 `senior-queue` export row (T028).
     *
     * One row per transaction: the owning sheet's identity snapshots plus the transaction's current
     * active revision facts, plus the **separate** current-revision Cost Center and Senior decision
     * values (an `APPROVE`/`REJECT`/`RETURN` string, or null when the current revision has none at
     * that stage). The decisions are emitted as their enum values, never merged and never rendered as
     * a sheet verdict.
     *
     * @param object|null $sheet ExpenseSheet aggregate (publicId, title, currency, submittedTotalMinor)
     * @param object $transaction ExpenseTransaction aggregate
     * @param \OCA\PettyCash\Domain\Workflow\WorkflowDecision|null $costCenterDecision current-revision CC decision
     * @param \OCA\PettyCash\Domain\Workflow\WorkflowDecision|null $seniorDecision current-revision Senior decision
     * @return array<string, mixed>
     */
    public static function seniorQueueRow(
        ?object $sheet,
        object $transaction,
        ?\OCA\PettyCash\Domain\Workflow\WorkflowDecision $costCenterDecision,
        ?\OCA\PettyCash\Domain\Workflow\WorkflowDecision $seniorDecision
    ): array {
        $revision = $transaction->revision();
        $amount = $revision->amount();

        return [
            'sheetId' => $sheet?->publicId ?? $transaction->sheetId,
            'sheetTitle' => $sheet?->title ?? '',
            'sheetCurrency' => $sheet !== null ? (string) $sheet->currency : '',
            'submittedTotalMinor' => $sheet?->submittedTotalMinor,
            'transactionId' => $transaction->publicId ?? $transaction->id,
            'description' => $revision->description() ?? '',
            'expenseType' => $revision->expenseTypeTitle() ?? '',
            'amountMinor' => $amount->minorUnits(),
            'currency' => (string) $amount->currency(),
            'expenseDate' => $revision->expenseDate()->format(DATE_ATOM),
            'stage' => $transaction->stage->value,
            'status' => $transaction->status->value,
            'costCenterDecision' => $costCenterDecision?->decision->value,
            'seniorDecision' => $seniorDecision?->decision->value,
        ];
    }
}
