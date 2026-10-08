<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Http;

use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Configuration\Repository\CurrencyRepository;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\SheetCapabilities;
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
        private CurrencyRepository $currencyRepository,
        private UserDirectory $userDirectory,
        private TransactionPayloadFormatter $transactionFormatter
    ) {}

    /**
     * `SheetSummary` — the list and detail shape.
     *
     * @param array{first: ?string, last: ?string}|null $expenseDates precomputed by
     *        `SheetSummaryQuery::expenseDateRanges()`; computed for this one sheet when null
     * @return array<string, mixed>
     */
    public function summary(ExpenseSheet $sheet, ?array $expenseDates = null): array
    {
        $currency = (string) $sheet->currency;
        $dates = $expenseDates ?? $this->summaryQuery->expenseDateRangeForSheet($sheet->dbId);

        return [
            'id' => $sheet->publicId ?? $sheet->id,
            'title' => $sheet->title,
            'ownerId' => $sheet->ownerUid,
            // The owner's **current** display name, resolved through the same `UserDirectory` port
            // `SessionController` already resolves the caller's own name through.
            //
            // It used to be the literal `''`, with no lookup and no `UserDirectory` injected — and it is
            // rendered: `SheetDetailView.vue` prints `sheetStore.currentSheet?.ownerDisplayName`, so the
            // Owner row was blank for every sheet in the application. A shape check passes on `''`, which
            // is why nothing failed (T340).
            //
            // **A uid the directory does not know falls back to the uid, not to an empty string.** The
            // member is declared in the contract and an Owner row that says "u1234" is legible where one
            // that says nothing is not, and an account deleted after the sheet was written is exactly the
            // case where the display name is gone. This is `SessionController::me()`'s rule, kept the
            // same so the two endpoints cannot disagree about a person.
            'ownerDisplayName' => $this->displayNameOf($sheet->ownerUid),
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
            // `contracts/ocs-api.yaml:1122-1123` declares both of these on `SheetSummary`, and this
            // formatter — a complete array literal with exactly one nested call — emitted neither.
            // They are `nullable` but not in `required`, so a strict validator accepted the absence and
            // the only reader was a frontend fixture. Emitted from the **active** revisions, because a
            // superseded revision's date is history rather than the sheet's contents (T337).
            'firstExpenseDate' => $dates['first'],
            'lastExpenseDate' => $dates['last'],
            'version' => $sheet->lockVersion,
            // FR-063's latest active transaction revision number (T385).
            //
            // The requirement named *"the active transaction revision"*, which cannot be rendered on a
            // sheet: `activeRevision` belongs to `TransactionSummary`, so a sheet has one per
            // transaction and a list row carries none at all. A *count* would also be useless -- it equals
            // the `transactionCount` two lines above -- so the field is the **highest revision number**
            // among the sheet's transactions' active revisions: a single value both surfaces can show,
            // and not one already on the row.
            //
            // 0 rather than null for a sheet with no transactions: an empty sheet has no revisions, and 0
            // is a value a reader comparing two sheets can order by. `null` is reserved for "not
            // computed", which this formatter never produces.
            'activeRevisionNumber' => $this->latestActiveRevisionNumber($sheet),
        ];
    }

    /**
 * A person's current display name, falling back to their uid.
 *
 * @see self::summary() for why the fallback is the uid rather than an empty string
 */
    /**
     * FR-063's latest active transaction revision number for one sheet (T385).
     *
     * The highest `number()` across the sheet's transactions' active revisions, and **0** when the sheet
     * holds no transactions.
     *
     * **Why 0 and not null.** An empty sheet genuinely has no revisions, and two sheets being compared is
     * the reason the number is on the row at all; a reader ordering by it needs a value, and `null` would
     * mean "not computed" rather than "none". The formatter always computes it, so `null` would be a lie
     * about work that was never skipped.
     *
     * **Read through `findBySheetId()`, which is the same read `transactionCount` is derived from**, so
     * the two figures on a row are computed from one snapshot of the sheet's transactions and cannot
     * disagree — which matters because a correction arriving between the two would otherwise show a count
     * and a revision number describing different moments.
     */
    private function latestActiveRevisionNumber(ExpenseSheet $sheet): int
    {
        if ($sheet->dbId === null) {
            return 0;
        }

        $highest = 0;
        foreach ($this->transactionRepository->findBySheetId($sheet->dbId) as $transaction) {
            $highest = max($highest, $transaction->revision()->number());
        }

        return $highest;
    }

    private function displayNameOf(string $uid): string
    {
        if ($uid === '') {
            return '';
        }

        if ($this->userDirectory->exists($uid)) {
            $name = $this->userDirectory->displayName($uid);
            if ($name !== '') {
                return $name;
            }
        }

        return $uid;
    }

/**
 * `SheetSummary` for a whole page, with the expense-date ranges read in one query.
     *
     * Exists so the list endpoint does not make the range an N+1: `summary()` computes the range for
     * its own sheet when none is supplied, which is right for a single sheet and wrong for a page of
     * them. The mapping stays here rather than in the controller for the reason `summary()` exists —
     * a private copy of the contract shape in a controller is a second place for it to drift.
     *
     * @param list<ExpenseSheet> $sheets
     * @return list<array<string, mixed>>
     */
    public function summaries(array $sheets): array
    {
        $ranges = $this->summaryQuery->expenseDateRanges(
            array_map(static fn (ExpenseSheet $sheet): ?int => $sheet->dbId, $sheets)
        );

        return array_values(array_map(
            fn (ExpenseSheet $sheet): array => $this->summary(
                $sheet,
                $sheet->dbId === null ? null : ($ranges[$sheet->dbId] ?? null)
            ),
            $sheets
        ));
    }

/**
     * `SheetDetail` — `SheetSummary` plus the two members `contracts/ocs-api.yaml:1125` adds.
     *
     * The contract defines `SheetDetail` as `allOf: [SheetSummary, {allowedActions, transactions}]`,
     * and both members were absent from every response the server could produce. That is not a
     * cosmetic drift: `src/stores/sheets.ts` derives `canSubmit` from `transactions.length`, so with
     * the property missing the computed was permanently `false` and the Submit button was never in
     * the DOM — a purchaser could create a sheet, add a draft transaction, and never submit it.
     *
     * **Why the transactions travel inside the sheet payload rather than on a list route.** The
     * contract declares `transactions` here and declares no paged list endpoint for them, so honouring
     * it needs no amendment and no second request for the two views that read them. Adding
     * `GET /sheets/{id}/transactions` was the alternative considered: it keeps this payload small and
     * serves a list view directly, but it is a contract amendment, and this repository requires an
     * amendment to be owned, ordered against T124's type regeneration, and reconciled with
     * `SheetDetail.transactions` rather than quietly left in place beside it. That is recorded rather
     * than decided silently, and it remains available as follow-up work.
     *
     * **The array is not truncated, and that is a deliberate choice.** `SheetDetail.transactions`
     * carries no pagination parameters and no truncation flag, so any cap added here would silently
     * hide a transaction from the purchaser who must review the whole sheet before submitting it
     * (FR-024) — a correctness defect of exactly the kind this ledger keeps recording, and the
     * undeclared truncation would read as a complete list. `transactionCount` in the same payload
     * states the true total, so a client can always detect the payload's size. If a sheet large
     * enough to matter is ever observed, the fix is a contract amendment that adds pagination, owned
     * explicitly — not a limit invented here.
     *
     * @return array<string, mixed>
     */
    public function detail(ExpenseSheet $sheet, string $actorUid): array
    {
        $transactions = $this->sheetTransactions($sheet);

        return $this->summary($sheet) + [
            'allowedActions' => SheetCapabilities::derive($sheet, $actorUid, count($transactions)),
            'transactions' => $transactions,
        ];
    }

    /**
     * The sheet's transactions as `TransactionSummary` values, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    private function sheetTransactions(ExpenseSheet $sheet): array
    {
        if ($sheet->dbId === null) {
            return [];
        }

        return array_map(
            fn ($transaction) => $this->transactionFormatter->summary($transaction),
            $this->transactionRepository->findBySheetId($sheet->dbId)
        );
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
