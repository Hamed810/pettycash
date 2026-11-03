<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Domain\Expense;

use Error;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use PHPUnit\Framework\TestCase;

/**
 * T440 / FR-021: a sheet's currency is fixed at construction.
 *
 * The update path no longer carries an account, so it can no longer adopt another account's currency
 * either. This test pins the second half of that invariant at the aggregate: even a future code path
 * that obtains a sheet cannot rewrite `$currency` after creation. Without the readonly property, a
 * caller could change the unit every amount on the sheet is denominated in while every stored amount
 * stayed numerically the same.
 */
final class ExpenseSheetCurrencyImmutableTest extends TestCase
{
    public function testTheCurrencyCannotBeRewrittenAfterConstruction(): void
    {
        $sheet = ExpenseSheet::open(
            '11111111-1111-4111-8111-111111111111',
            'purchaser-t440',
            '22222222-2222-4222-8222-222222222222',
            CurrencyCode::fromString('IRR')
        );

        try {
            /** @phpstan-ignore-next-line Deliberate write to prove the property is readonly. */
            $sheet->currency = CurrencyCode::fromString('USD');
            self::fail('a sheet currency must be readonly after construction');
        } catch (Error $refusal) {
            self::assertStringContainsString('readonly', $refusal->getMessage());
        }

        self::assertSame('IRR', (string) $sheet->currency, 'a refused write must leave the original unit intact');
    }
}
