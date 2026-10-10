<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Export;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\PettyCash\Infrastructure\Export\CsvExporter;
use PHPUnit\Framework\TestCase;

/**
 * T107: CSV export per `contracts/exports.md`.
 *
 * ## The test that justifies the design
 *
 * `testANegativeAmountIsNotFormulaEscaped` is the one that matters. The standard hardening advice —
 * prefix any cell starting with `=`, `+`, `-` or `@` — applied to a report of petty-cash amounts turns
 * every negative into text. A financial export that mangles its own numbers is a worse outcome than
 * no export, and it is the kind of defect that only appears once a cost centre returns money, months
 * after the exporter was written.
 *
 * `testAUserSuppliedAmountIsFormulaEscaped` is the case the hardening advice is actually for, and it
 * is the one that occurs in this application: a description or explanation a user typed. Both tests
 * pass together only because escaping keys on the PHP type rather than on the string.
 *
 * ## On mutation
 *
 * These tests are checked by mutation rather than trusted by reading, because "did the escape fire"
 * and "did the escape overfire" are both single-line changes and either could be made by accident.
 */
final class CsvExporterTest extends TestCase
{
    private CsvExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new CsvExporter();
    }

    // ===== Header =====

    public function testTheFirstLineIsAnExplicitHeaderRow(): void
    {
        $csv = $this->exporter->export([
            ['code' => 'IRR', 'name' => 'Rial'],
        ]);

        self::assertSame("code,name\nIRR,Rial\n", $csv);
    }

    public function testTheHeaderIsTheUnionOfEveryRowInFirstSeenOrder(): void
    {
        // A later row carrying a column the first row lacked must not have that column silently
        // dropped: a financial export with a missing column reads as a correct file.
        $csv = $this->exporter->export([
            ['code' => 'IRR', 'minorUnit' => 0],
            ['code' => 'USD', 'name' => 'Dollar', 'minorUnit' => 2],
        ]);

        self::assertSame("code,minorUnit,name\nIRR,0,\nUSD,2,Dollar\n", $csv);
    }

    public function testAMissingKeyBecomesAnEmptyCellNotAShiftedColumn(): void
    {
        $csv = $this->exporter->export([
            ['a' => '1', 'b' => '2'],
            ['a' => '3'],
        ]);

        self::assertSame("a,b\n1,2\n3,\n", $csv);
    }

    public function testAnEmptyResultSetIsAnEmptyFileRatherThanGuessedColumns(): void
    {
        self::assertSame('', $this->exporter->export([]));
    }

    // ===== Explicit header =====

    public function testAnExplicitHeaderIsWrittenEvenWithNoRows(): void
    {
        // The case that made the header an argument rather than derived: a caller that knows its column
        // set independently of the data. The evidence archive manifest needs exactly this, because a
        // recipient must be able to tell "no evidence was in scope" from "the export is broken".
        self::assertSame("a,b\n", $this->exporter->export([], ['a', 'b']));
    }

    public function testAnExplicitHeaderKeepsItsOrderAndAppendsUnlistedColumns(): void
    {
        $csv = $this->exporter->export(
            [['a' => '1', 'extra' => 'x']],
            ['b', 'a']
        );

        // Declared order first, then the unlisted key. Passing a header must not drop a column.
        self::assertSame("b,a,extra\n,1,x\n", $csv);
    }

    public function testAnExplicitHeaderIsStillFormulaSafeForUserControlledColumns(): void
    {
        $csv = $this->exporter->export([['title' => '=1+1']], ['title']);

        self::assertStringContainsString("'=1+1", $csv);
    }

    // ===== Formula escaping: the negative-amount case =====

    public function testANegativeAmountIsNotFormulaEscaped(): void
    {
        $csv = $this->exporter->export([
            ['description' => 'Refund', 'amount' => -125000.75],
        ]);

        self::assertStringContainsString('-125000.75', $csv);
        self::assertStringNotContainsString("'-", $csv);
    }

    public function testANegativeIntegerIsNotFormulaEscaped(): void
    {
        $csv = $this->exporter->export([['delta' => -42]]);

        self::assertSame("delta\n-42\n", $csv);
    }

    public function testAPositiveAmountIsWrittenPlainly(): void
    {
        $csv = $this->exporter->export([['amount' => 125000.75]]);

        self::assertSame("amount\n125000.75\n", $csv);
    }

    // ===== Formula escaping: the user-controlled case =====

    /**
     * @dataProvider formulaTriggeringStrings
     */
    public function testAUserSuppliedStringIsFormulaEscaped(string $input): void
    {
        $csv = $this->exporter->export([['description' => $input]]);

        // Asserted on the *parsed* value rather than by substring-matching the raw input. The raw
        // field is not a substring of the output for any value containing a quote, because fputcsv
        // doubles it — so a substring assertion would fail on correct escaping and pass on a mangled
        // field. What must hold is that the escape is present in the representation and the data is
        // unchanged underneath it.
        // The parsed value is the input with a literal apostrophe in front of it. That is the whole
        // mechanism: the marker travels in the field so a spreadsheet refuses to evaluate it, and the
        // application strips the marker on import. Asserting the value came back *unchanged* was wrong —
        // it cannot be, and a test that insisted it was would be asserting that the escape did nothing.
        self::assertSame(
            "'" . $input,
            $this->singleDataField($csv),
            'the field must be the original value behind a literal apostrophe, and nothing else changed'
        );
    }

    /** @return list<array{string}> */
    public static function formulaTriggeringStrings(): array
    {
        return [
            'equals' => ['=1+1'],
            'plus' => ['+1'],
            'minus' => ['-1+cmd'],
            'at' => ['@SUM(A1)'],
            'tab' => ["\t=cmd"],
            'carriage return' => ["\r=cmd"],
            'equals with a formula payload' => ['=HYPERLINK("http://evil","click")'],
            'equals with a leet payload' => ['=cmd|"/C calc"!A0'],
        ];
    }

    public function testAnOrdinaryStringIsNotEscaped(): void
    {
        $csv = $this->exporter->export([['description' => 'Taxi fare']]);

        self::assertStringContainsString('Taxi fare', $csv);
        self::assertStringNotContainsString("'", $csv);
    }

    public function testAnEmptyStringIsNotEscaped(): void
    {
        // Escaping an empty cell would write a bare apostrophe, which is visible data corruption.
        $csv = $this->exporter->export([['description' => '']]);

        self::assertSame("description\n\n", $csv);
    }

    // ===== Locale neutrality =====

    public function testLargeNumbersCarryNoThousandsSeparator(): void
    {
        $csv = $this->exporter->export([['total' => 1234567]]);

        self::assertStringContainsString('1234567', $csv);
        self::assertStringNotContainsString('1,234,567', $csv);
    }

    public function testAWholeFloatIsWrittenWithoutADecimalTail(): void
    {
        // CSV has no types, and the contract asks for "locale-neutral machine values" here — it is XLSX
        // that must carry typed numeric cells, and T108 owns that. So a whole float is written as the
        // whole number a reader expects to see in a money column, and the int/float distinction is
        // deliberately not carried. Recorded here because the opposite choice looked defensible and the
        // contract does not ask for it.
        $csv = $this->exporter->export([['balance' => 1200000.0]]);

        self::assertSame("balance\n1200000\n", $csv);
    }

    public function testTrailingZerosOnAFractionalAmountAreTrimmed(): void
    {
        $csv = $this->exporter->export([['amount' => 12.50]]);

        self::assertStringContainsString('12.5', $csv);
        self::assertStringNotContainsString('12.50', $csv);
    }

    public function testNullBecomesAnEmptyCell(): void
    {
        $csv = $this->exporter->export([['note' => null]]);

        self::assertSame("note\n\n", $csv);
    }

    public function testBooleansAreWrittenAsWords(): void
    {
        $csv = $this->exporter->export([['active' => true, 'archived' => false]]);

        self::assertStringContainsString('true', $csv);
        self::assertStringContainsString('false', $csv);
    }

    public function testADateIsWrittenAsIso8601(): void
    {
        $csv = $this->exporter->export([
            ['occurredAt' => new DateTimeImmutable('2026-03-15T10:30:00+00:00')],
        ]);

        self::assertStringContainsString('2026-03-15T10:30:00+00:00', $csv);
    }

    // ===== UTF-8 =====

    public function testPersianTextSurvivesUnchanged(): void
    {
        $persian = 'هزینهٔ ایاب و ذهاب';

        $csv = $this->exporter->export([['name' => $persian]]);

        self::assertStringContainsString($persian, $csv);
        self::assertTrue(mb_check_encoding($csv, 'UTF-8'), 'the export must be valid UTF-8');
    }

    public function testMixedDirectionTextIsNotAltered(): void
    {
        // A Latin identifier inside Persian text is exactly the mixed-direction case FR-071 raises,
        // and an export must reproduce the bytes rather than normalise them.
        $mixed = 'هزینهٔ سرویس AWS-12';

        $csv = $this->exporter->export([['description' => $mixed]]);

        self::assertStringContainsString($mixed, $csv);
    }

    // ===== Structural quoting =====

    public function testAValueContainingACommaIsQuoted(): void
    {
        $csv = $this->exporter->export([['name' => 'Rial, Iranian']]);

        self::assertStringContainsString('"Rial, Iranian"', $csv);
    }

    public function testAValueContainingAQuoteIsEscapedByDoubling(): void
    {
        $csv = $this->exporter->export([['description' => 'He said "no"']]);

        self::assertStringContainsString('"He said ""no"""', $csv);
    }

    public function testAValueContainingANewlineStaysInsideOneField(): void
    {
        $csv = $this->exporter->export([['description' => "line one\nline two"]]);

        // Asserted by *parsing* rather than by counting newlines. Counting cannot express the property:
        // the raw output legitimately contains three newline characters for one record, because the
        // embedded one is inside a quoted field. What must hold is that a CSV reader sees two records —
        // a header and one row — with the newline still inside a single field.
        self::assertStringContainsString("\"line one\nline two\"", $csv);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);
        $records = [];
        while (($record = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $records[] = $record;
        }
        fclose($handle);

        self::assertCount(2, $records, 'a header and exactly one record');
        self::assertSame(['description'], $records[0]);
        self::assertSame(["line one\nline two"], $records[1]);
    }

    // ===== Media type =====

    public function testTheMediaTypeDeclaresUtf8(): void
    {
        self::assertSame('text/csv; charset=UTF-8', $this->exporter->mediaType());
    }

    // ===== Refusals =====

    public function testANonArrayRowIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->exporter->export(['not a row']);
    }

    public function testAnUnrenderableValueIsRefusedRatherThanSilentlyDropped(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CSV cannot render a value of type array');

        $this->exporter->export([['nested' => ['a' => 'b']]]);
    }

    public function testAStringableObjectIsRendered(): void
    {
        $stringable = new class () {
            public function __toString(): string
            {
                return 'IRR';
            }
        };

        $csv = $this->exporter->export([['code' => $stringable]]);

        self::assertStringContainsString('IRR', $csv);
    }

    // ===== Helpers for reading the export back =====

    /**
     * The data field of the single record, as a CSV reader sees it.
     *
     * Index 0 of the second record, because these rows carry exactly one column. Written as a helper so
     * the shape is stated once: reading `[1][1]` here silently returns an empty string for every case,
     * which is how a passing-looking assertion can measure nothing.
     */
    private function singleDataField(string $csv): string
    {
        $records = $this->parse($csv);

        return $records[1][0] ?? '';
    }

    /** @return list<list<string>> */
    private function parse(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);
        $records = [];
        while (($record = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $records[] = $record;
        }
        fclose($handle);

        return $records;
    }

    // ===== The port =====

    public function testItSatisfiesTheExporterPort(): void
    {
        self::assertContains(
            \OCA\PettyCash\Application\Port\Exporter::class,
            class_implements(CsvExporter::class) ?: []
        );
    }
}
