<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Infrastructure\Http;

use DateTimeImmutable;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\ExpenseTransaction;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Infrastructure\Http\TransactionPayloadFormatter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCostCenterRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrencyRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryEvidenceRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryExpenseSheetRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryRoleAssignmentRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryWorkflowDecisionRepository;
use PHPUnit\Framework\TestCase;

/**
 * T338: `TransactionSummary.sheetId` is a public uuid, and an unresolvable sheet reference does not
 * become a surrogate key.
 *
 * ## Why this is a unit case and not another live-HTTP probe
 *
 * The criterion asks for a contract test that also covers "a transaction whose sheet row has been
 * removed". Producing that fault means writing a transaction whose `sheet_id` matches no row — a second
 * corrupt-record probe. `CorruptRecordFixture`'s docblock is an argument against adding one casually: it
 * carries one probe already, its `insert()` **repairs before it writes** so a crashed run cannot
 * accumulate faults, and a probe whose failure mode is "leaves a landmine that breaks three reports" is
 * what produced seven leaked sheets in this instance once already.
 *
 * The branch is reachable in-process with no shared state at all, so it is asserted here and over HTTP
 * only where a live endpoint can be trusted — `TransactionSheetIdConformanceTest` covers the two live
 * routes.
 *
 * ## Why the fallback is `''` and not the integer, and not a 422
 *
 * * **Not the integer.** `contracts/ocs-api.yaml:1154` declares `sheetId` as `format: uuid`, and it is
 *   in `TransactionSummary`'s `required` list. An integer is a value a client will use — as a link, as
 *   a lookup key — and this one cannot be, so publishing it is the defect.
 * * **Not `null`.** The member is declared required and not nullable, so `null` would be a second
 *   contract violation in the other direction.
 * * **Not a 422 here.** FR-083 requires a broken reference to be answered as a data fault, and it names
 *   `GET /sheets`, `GET /sheets/{id}`, `GET /dashboard` and the report endpoints. The transaction route
 *   is not among them, and T313/T314 own extending the policy to it with their own tests. Pinning a
 *   status here would make that improvement fail a test it did not break.
 *
 * So this case pins **the direction**: an unresolvable reference reads as absent, and a resolvable one
 * reads as the sheet's public uuid.
 */
final class TransactionPayloadFormatterSheetIdTest extends TestCase
{
    private const SHEET_UUID = '3f6b1c2a-0000-4000-8000-000000000001';

    public function testTheSheetIdIsTheOwningSheetsPublicUuid(): void
    {
        $sheets = new InMemoryExpenseSheetRepository();
        $sheet = $this->sheet(self::SHEET_UUID);
        $sheets->save($sheet);

        $summary = $this->formatter($sheets)->summary($this->transaction(7));

        self::assertSame(self::SHEET_UUID, $summary['sheetId']);
    }

    /**
     * The defect this exists for: `$transaction->sheetId` is the surrogate key, and emitting it put an
     * integer in a uuid field — a value the three queue views rendered in their "Sheet" column.
     */
    public function testTheSheetIdIsNeverTheSurrogateKey(): void
    {
        $sheets = new InMemoryExpenseSheetRepository();
        $sheets->save($this->sheet(self::SHEET_UUID));

        $summary = $this->formatter($sheets)->summary($this->transaction(7));

        self::assertIsString($summary['sheetId']);
        self::assertNotSame('7', $summary['sheetId']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            (string) $summary['sheetId']
        );
    }

    /**
     * A stored transaction whose `sheet_id` matches no row.
     *
     * Asserted as `''` rather than as the dangling number: the key is still present, because the
     * contract declares it required, and what it says is "no sheet" instead of a join key.
     */
    public function testAnUnresolvableSheetReferenceReadsAsAbsentRatherThanAsAKey(): void
    {
        $sheets = new InMemoryExpenseSheetRepository();

        $summary = $this->formatter($sheets)->summary($this->transaction(4242));

        self::assertArrayHasKey('sheetId', $summary, 'the member is declared required, so the key is always present');
        self::assertSame('', $summary['sheetId'], 'an unresolvable reference must not publish the join key');
    }

    /**
     * A transaction built in memory and not yet saved has no sheet at all.
     *
     * A different state from a dangling reference, and it answers the same way — which is why both
     * cases are here: a formatter that returned the integer for one and `''` for the other would be
     * correct on paper and wrong on the wire for whichever it got wrong.
     */
    public function testATransactionWithNoSheetYetEmitsAnEmptyValue(): void
    {
        $summary = $this->formatter(new InMemoryExpenseSheetRepository())->summary($this->transaction(null));

        self::assertSame('', $summary['sheetId']);
    }

    // ===== helpers -----------------------------------------------------------

    private function formatter(InMemoryExpenseSheetRepository $sheets): TransactionPayloadFormatter
    {
        return new TransactionPayloadFormatter(
            new InMemoryWorkflowDecisionRepository(),
            new InMemoryEvidenceRepository(),
            new InMemoryCostCenterRepository(),
            new InMemoryCurrencyRepository(),
            new InMemoryRoleAssignmentRepository(),
            $sheets,
            new InMemoryCurrentUser('purchaser-1')
        );
    }

    private function sheet(string $publicId): ExpenseSheet
    {
        $sheet = ExpenseSheet::open('sheet-1', 'purchaser-1', 'account-1', CurrencyCode::fromString('IRR'));
        $sheet->publicId = $publicId;
        $sheet->dbId = 7;

        return $sheet;
    }

    private function transaction(?int $sheetDbId): ExpenseTransaction
    {
        $revision = TransactionRevision::draft(
            'r1',
            1,
            new DateTimeImmutable('2026-10-04'),
            Money::positive(1000, CurrencyCode::fromString('IRR'))
        );

        return new ExpenseTransaction('t1', 'purchaser-1', $revision, $sheetDbId, dbId: 1, publicId: 'tx-public-1');
    }
}