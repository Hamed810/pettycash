<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Http;

use OCA\PettyCash\Domain\Configuration\Repository\CurrencyRepository;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseTransactionRepository;
use OCA\PettyCash\Infrastructure\Persistence\Expense\SheetSummaryQuery;

/**
 * The single source of truth for the sheet OCS payload.
 *
 * `contracts/ocs-api.yaml` lists `totals` among `SheetSummary`'s required members, and
 * `SheetController::formatSheet()` never emitted it — so no sheet response had ever carried totals.
 * That is why FR-062 was unimplemented end to end rather than merely mis-formatted: the three
 * stage-approved columns the requirement names have nowhere to be read from.
 *
 * `formatSheet()` also emitted an `accountId` the contract does not declare. It is dropped here
 * rather than kept as an extra: the schema is what the frontend is written against, and a field
 * the contract does not describe is one nothing can be tested against.
 */
final readonly class SheetPayloadFormatter
{
    public function __construct(
        private SheetSummaryQuery $summaryQuery,
        private ExpenseTransactionRepository $transactionRepository,
        private CurrencyRepository $currencyRepository
    ) {}

    /**
     * `SheetSummary` — the list and detail shape.
     *
     * @return array<string, mixed>
     */
    public function summary(ExpenseSheet $sheet): array
    {
        $currency = (string) $sheet->currency;

        return [
            'id' => $sheet->publicId ?? $sheet->id,
            'title' => $sheet->title,
            'ownerId' => $sheet->ownerUid,
            'ownerDisplayName' => '',
            'currency' => $currency,
            'status' => $sheet->status->value,
            // Counted through the repository, not from `$sheet->transactions()`: the aggregate is not
            // composed on read, so counting it reported 0 for a sheet that has transactions — a
            // response contradicting its own `status`. T279 owns that defect.
            'transactionCount' => $sheet->dbId === null
                ? 0
                : $this->transactionRepository->countBySheetId($sheet->dbId),
            'totals' => $this->totals($sheet),
            'createdAt' => $sheet->createdAt?->format(\DateTimeInterface::ATOM),
            'submittedAt' => $sheet->firstSubmittedAt?->format(\DateTimeInterface::ATOM),
            'completedAt' => $sheet->completedAt?->format(\DateTimeInterface::ATOM),
            'version' => $sheet->lockVersion,
        ];
    }

    /**
     * The contract's `SheetTotals` schema.
     *
     * Seven of the eight figures are derived from the active revisions on every read. `submitted` is
     * the exception and is not derived: `data-model.md` defines `submitted_total_minor` as a
     * "snapshot at first submission", held on the sheet, and nullable before it. Deriving it from
     * the current revisions instead would make it move every time a correction activates a new one,
     * which is the opposite of what a snapshot is for.
     *
     * @return array<string, array{amountMinor: int, currency: string, minorUnit: int}>
     */
    public function totals(ExpenseSheet $sheet): array
    {
        $currency = (string) $sheet->currency;
        $totals = $this->summaryQuery->totalsForSheet($sheet->dbId);

        return [
            'current' => $this->money($totals->current, $currency),
            'submitted' => $this->money($sheet->submittedTotalMinor ?? 0, $currency),
            'effectiveApproved' => $this->money($totals->approved, $currency),
            'effectiveRejected' => $this->money($totals->rejected, $currency),
            'effectivePending' => $this->money($totals->pending, $currency),
            'costCenterApproved' => $this->money($totals->managerApproved, $currency),
            'seniorApproved' => $this->money($totals->seniorApproved, $currency),
            'financeApproved' => $this->money($totals->financeApproved, $currency),
        ];
    }

    /**
     * The contract's `Money` schema.
     *
     * `minorUnit` comes from the administrator-configured currency rather than from a hardcoded
     * table, because `data-model.md` makes it a configured value in 0–4. An unknown currency
     * reports 0 digits rather than a guess; the same fallback and its caveat are documented on
     * `TransactionPayloadFormatter::minorUnit()`, which is the other place this is read.
     *
     * @return array{amountMinor: int, currency: string, minorUnit: int}
     */
    private function money(int $amountMinor, string $currencyCode): array
    {
        $currency = $this->currencyRepository->findActiveByCode($currencyCode)
            ?? $this->currencyRepository->findByCode($currencyCode);

        return [
            'amountMinor' => $amountMinor,
            'currency' => $currencyCode,
            'minorUnit' => $currency?->minorUnit ?? 0,
        ];
    }
}
