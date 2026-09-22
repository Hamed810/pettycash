<?php
declare(strict_types=1);
namespace OCA\PettyCash\Tests\Unit\Domain\Expense;
use DateTimeImmutable;
use InvalidArgumentException;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;
final class SheetSubmissionPolicyTest extends TestCase {
 public function testDraftNeedsDateAndPositiveAmountAndSubmissionNeedsCompleteData(): void {
  $sheet = ExpenseSheet::open('sheet', 'u1', 'account', CurrencyCode::fromString('IRR'));
  $revision = TransactionRevision::draft('r1', 1, new DateTimeImmutable('2026-09-20'), Money::positive(10, CurrencyCode::fromString('IRR')));
  $sheet->addTransaction(new ExpenseTransaction('t1', 'u1', $revision));
  $this->expectException(InvalidArgumentException::class); $sheet->submit();
 }
 public function testSubmissionIsBalanceIndependent(): void {
  $sheet = ExpenseSheet::open('sheet', 'u1', 'account', CurrencyCode::fromString('IRR'));
  $revision = TransactionRevision::draft('r1', 1, new DateTimeImmutable('2026-09-20'), Money::positive(10, CurrencyCode::fromString('IRR')));
  $revision->complete('desc', 'cc', 'Travel', true);
  $sheet->addTransaction(new ExpenseTransaction('t1', 'u1', $revision));
  self::assertSame(10, $sheet->submit());
 }
}
