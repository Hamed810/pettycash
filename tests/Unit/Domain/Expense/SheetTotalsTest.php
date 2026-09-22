<?php
declare(strict_types=1);
namespace OCA\PettyCash\Tests\Unit\Domain\Expense;
use OCA\PettyCash\Domain\Expense\SheetTotals;
use OCA\PettyCash\Domain\Workflow\Decision;
use PHPUnit\Framework\TestCase;
final class SheetTotalsTest extends TestCase { public function testLatestEffectiveAndStageTotalsRemainDistinct(): void { $totals = SheetTotals::fromRows([['amount'=>10,'latest'=>Decision::Approve,'manager'=>Decision::Approve,'senior'=>Decision::Reject,'finance'=>null],['amount'=>5,'latest'=>null,'manager'=>Decision::Approve,'senior'=>null,'finance'=>null]]); self::assertSame(15,$totals->current); self::assertSame(10,$totals->approved); self::assertSame(15,$totals->managerApproved); self::assertSame(0,$totals->seniorApproved); self::assertSame(5,$totals->pending); } }
