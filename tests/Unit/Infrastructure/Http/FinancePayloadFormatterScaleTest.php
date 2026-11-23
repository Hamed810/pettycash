<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Infrastructure\Http;

use DateTimeImmutable;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Infrastructure\Http\FinancePayloadFormatter;
use PHPUnit\Framework\TestCase;

/**
 * T014/T019 — the Finance wire formatter must use the configured currency scale.
 *
 * The contract fixtures denominate the ledger and completed-history probes in IRR, whose
 * configured scale is zero. That makes every `minorUnit` assertion agree with a formatter that
 * ignores the configured value entirely. These cases use USD, whose configured scale is two, so
 * the emitted values can only be right when the formatter threads the authoritative scale through
 * the row, transaction, and posting money objects.
 *
 * The projections are the smallest arrays the real services hand the formatter; no database or
 * shared fixture is involved.
 */
final class FinancePayloadFormatterScaleTest extends TestCase
{
    private const MINOR_UNIT = 2;

    public function testUsdLedgerRowsUseTheAccountConfiguredScale(): void
    {
        $usd = CurrencyCode::fromString('USD');
        $occurredAt = new DateTimeImmutable('2026-01-02T10:00:00Z');
        $sheetId = 'b4b0b3a4-0000-4000-8000-000000000001';

        $page = [
            'account' => [
                'id' => 'b4b0b3a4-0000-4000-8000-000000000002',
                'userId' => 'usd-owner',
                'currency' => 'USD',
                'minorUnit' => self::MINOR_UNIT,
                'balance' => Money::of(100000, $usd),
                'active' => true,
                'version' => 1,
                'movementCount' => 2,
                'lastMovementAt' => $occurredAt,
            ],
            'items' => [
                [
                    'id' => 'charge-1',
                    'type' => 'CHARGE',
                    'occurredAt' => $occurredAt,
                    'description' => 'Opening float',
                    'sheet' => null,
                    'approved' => null,
                    'recharge' => Money::of(50000, $usd),
                    'expense' => null,
                    'balanceAfter' => Money::of(50000, $usd),
                    'sourceRevisionId' => null,
                ],
                [
                    'id' => 'consumption-1',
                    'type' => 'CONSUMPTION',
                    'occurredAt' => $occurredAt,
                    'description' => 'USD settled sheet',
                    'sheet' => [
                        'id' => $sheetId,
                        'reference' => $sheetId,
                        'title' => 'USD settled sheet',
                    ],
                    'approved' => ['approvedCount' => 1, 'totalCount' => 1],
                    'recharge' => null,
                    'expense' => Money::of(12345, $usd),
                    'balanceAfter' => Money::of(37655, $usd),
                    'sourceRevisionId' => 'b4b0b3a4-0000-4000-8000-000000000003',
                ],
            ],
            'page' => 1,
            'pageSize' => 25,
            'total' => 2,
        ];

        $out = (new FinancePayloadFormatter())->ledgerPage($page);

        self::assertSame(self::MINOR_UNIT, $out['account']['minorUnit']);
        self::assertSame(self::MINOR_UNIT, $out['account']['balance']['minorUnit']);

        $charge = $out['items'][0];
        self::assertSame('CHARGE', $charge['type']);
        self::assertSame(self::MINOR_UNIT, $charge['recharge']['minorUnit'], 'a charge must use the account scale');
        self::assertSame(self::MINOR_UNIT, $charge['balanceAfter']['minorUnit'], 'a charge balance must use the account scale');
        self::assertNull($charge['expense']);

        $consumption = $out['items'][1];
        self::assertSame('CONSUMPTION', $consumption['type']);
        self::assertSame(self::MINOR_UNIT, $consumption['expense']['minorUnit'], 'a consumption must use the account scale');
        self::assertSame(self::MINOR_UNIT, $consumption['balanceAfter']['minorUnit'], 'a consumption balance must use the account scale');
        self::assertNull($consumption['recharge']);
    }

    public function testUsdCompletedDetailUsesTheSheetConfiguredScale(): void
    {
        $usd = CurrencyCode::fromString('USD');
        $occurredAt = new DateTimeImmutable('2026-01-02T10:00:00Z');
        $transactionId = 'b4b0b3a4-0000-4000-8000-000000000011';

        $detail = [
            'id' => 'b4b0b3a4-0000-4000-8000-000000000012',
            'reference' => 'b4b0b3a4-0000-4000-8000-000000000012',
            'title' => 'USD completed sheet',
            'purchaser' => ['userId' => 'usd-owner', 'displayName' => 'USD Owner'],
            'totalTransactionCount' => 1,
            'approvedTransactionCount' => 1,
            'rejectedTransactionCount' => 0,
            'postedAmount' => Money::of(12345, $usd),
            'postingCount' => 1,
            'completedAt' => $occurredAt,
            'status' => 'COMPLETED',
            'accountId' => 'b4b0b3a4-0000-4000-8000-000000000002',
            'currency' => 'USD',
            'minorUnit' => self::MINOR_UNIT,
            'createdAt' => $occurredAt,
            'firstSubmittedAt' => $occurredAt,
            'financeStartedAt' => $occurredAt,
            'transactions' => [
                [
                    'id' => $transactionId,
                    'reference' => $transactionId,
                    'revisionNumber' => 1,
                    'description' => 'USD completed expense',
                    'expenseDate' => '2026-01-02',
                    'amount' => Money::of(12345, $usd),
                    'finalOutcome' => 'APPROVED',
                    'status' => 'COMPLETED_APPROVED',
                    'decisions' => ['costCenter' => null, 'senior' => null, 'finance' => null],
                    'decisionHistory' => [],
                    'evidence' => [],
                    'posting' => [
                        'occurredAt' => $occurredAt,
                        'amount' => Money::of(12345, $usd),
                        'balanceAfter' => Money::of(37655, $usd),
                    ],
                ],
            ],
        ];

        $out = (new FinancePayloadFormatter())->completedSheetDetail($detail);

        self::assertSame(self::MINOR_UNIT, $out['minorUnit'], 'the completed detail header must use the sheet scale');
        self::assertSame(self::MINOR_UNIT, $out['postedAmount']['minorUnit']);

        $transaction = $out['transactions'][0];
        self::assertSame(self::MINOR_UNIT, $transaction['amount']['minorUnit'], 'a transaction amount must use the sheet scale');
        self::assertSame(self::MINOR_UNIT, $transaction['posting']['amount']['minorUnit'], 'a posting amount must use the sheet scale');
        self::assertSame(
            self::MINOR_UNIT,
            $transaction['posting']['balanceAfter']['minorUnit'],
            'a posting balance must use the sheet scale'
        );
    }
}
