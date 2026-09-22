<?php
declare(strict_types=1);
namespace OCA\PettyCash\Tests\Unit\Domain\Expense;
use DateTimeImmutable;
use LogicException;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;
final class TransactionRevisionTest extends TestCase {
    public function testSubmittedRevisionIsImmutableAndCorrectionLinksPriorRevision(): void {
        $r = TransactionRevision::draft('r1', 1, new DateTimeImmutable('2026-09-20'), Money::positive(1, CurrencyCode::fromString('IRR')));
        $r->complete('description', 'cc', 'type', true);
        $r->activate();
        $copy = $r->correction('r2');
        self::assertSame(2, $copy->number());
        self::assertSame('r1', $copy->supersedes());
        $this->expectException(LogicException::class);
        $r->complete('x', 'cc', 'type', false);
    }
}
