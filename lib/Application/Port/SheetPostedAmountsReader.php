<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Port;

use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;

/**
 * Read the actual financially posted amount for a set of sheets, in one batched read.
 *
 * The implementation joins `pettycash_account_movements` → `pettycash_transaction_revisions`
 * → `pettycash_transactions` → `pettycash_sheets`, summing `amount_minor` for consumption
 * movements actually linked to each sheet. Every requested sheet is present in the map; a sheet
 * with no linked consumption movements is reported with `postedAmount = 0`,
 * `postingCount = 0`, and the authoritative configured currency scale.
 *
 * @return array<int, array{postedAmount: int, postingCount: int, currency: string, minorUnit: int}>
 */
interface SheetPostedAmountsReader
{
    public function postedAmountsFor(array $sheetDbIds): array;

    /**
     * All consumption postings for one sheet's transactions, keyed by transaction public id.
     *
     * One batched read joining movements → revisions → transactions for the given sheet.
     * Returns `[]` when the sheet has no linked consumption movements.
     *
     * @param int $sheetDbId surrogate `pettycash_sheets.id`
     * @param CurrencyCode $currencyCode the sheet's currency
     * @return array<string, array{occurredAt: \DateTimeImmutable, amount: Money, balanceAfter: Money}>
     */
    public function postingsForSheet(int $sheetDbId, CurrencyCode $currencyCode): array;
}
