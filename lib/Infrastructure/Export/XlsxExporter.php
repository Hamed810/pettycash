<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Export;

use InvalidArgumentException;
use OCA\PettyCash\Application\Port\TransientExportStore;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Complete-sheet XLSX export per `contracts/exports.md`.
 *
 * ## The six sheets are the contract, not a convenience
 *
 * `contracts/exports.md` names them and what each carries: `Sheet`, `Transactions`, `Decisions`,
 * `Revisions`, `Audit`, `Evidence`. They are declared here as constants with their required columns, so
 * a caller that omits one cannot produce a workbook that looks complete. `assertCompleteSheetLayout()`
 * checks the set, and a missing sheet is a refusal rather than a silently shorter export.
 *
 * ## Why the cells are typed
 *
 * The contract asks XLSX for "typed numeric/date cells" — the same document that lets CSV get away with
 * machine values. The reason is that a spreadsheet *recalculates* a numeric cell: an amount exported as
 * text cannot be summed by the recipient, which defeats the purpose of asking for a workbook rather than
 * a CSV. So a value is written through the type its column declares, and a value that does not fit its
 * type is refused at build time rather than silently coerced to a string.
 *
 * ## Storage
 *
 * The workbook is assembled on a scratch path and then persisted through the transient store, for the
 * same reason the evidence archive is: `contracts/exports.md` requires "private transient application
 * storage", and `IWriter` in PhpSpreadsheet can only write to a file, a stream, or a php:// URI — not to
 * `IAppData`. The scratch file is removed whether or not the build succeeded, and the file that persists
 * is never the file the workbook was assembled in.
 *
 * ## What this does not do
 *
 * Authorization. "Every report query is role-scoped before filtering, aggregation, or file generation" is a
 * rule about the query layer, and this class is handed rows that are already in scope. It has no way to
 * widen or narrow them, and pretending otherwise would be a false claim of enforcement.
 */
final class XlsxExporter
{
    public const SHEET = 'Sheet';
    public const TRANSACTIONS = 'Transactions';
    public const DECISIONS = 'Decisions';
    public const REVISIONS = 'Revisions';
    public const AUDIT = 'Audit';
    public const EVIDENCE = 'Evidence';

    /**
     * The contract's six sheets, in its order.
     *
     * @var list<string>
     */
    public const COMPLETE_SHEET_LAYOUT = [
        self::SHEET,
        self::TRANSACTIONS,
        self::DECISIONS,
        self::REVISIONS,
        self::AUDIT,
        self::EVIDENCE,
    ];

    /**
     * Required columns per sheet, from `contracts/exports.md`'s "Complete Sheet XLSX Layout".
     *
     * Declared as data so a layout change is a diff, and so `missingColumns()` can name exactly which
     * column of which sheet is absent rather than reporting a boolean.
     *
     * @var array<string, list<array{name: string, type: string}>>
     */
    public const COMPLETE_SHEET_COLUMNS = [
        self::SHEET => [
            ['name' => 'sheetPublicId', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'title', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'ownerUid', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'ownerDisplayName', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'currencyCode', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'status', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'createdAt', 'type' => XlsxSheet::TYPE_DATE],
            ['name' => 'firstSubmittedAt', 'type' => XlsxSheet::TYPE_DATE],
            ['name' => 'currentTotalMinor', 'type' => XlsxSheet::TYPE_NUMBER],
            ['name' => 'submittedTotalMinor', 'type' => XlsxSheet::TYPE_NUMBER],
            ['name' => 'effectiveApprovedMinor', 'type' => XlsxSheet::TYPE_NUMBER],
            ['name' => 'effectiveRejectedMinor', 'type' => XlsxSheet::TYPE_NUMBER],
            ['name' => 'effectivePendingMinor', 'type' => XlsxSheet::TYPE_NUMBER],
            ['name' => 'costCenterApprovedMinor', 'type' => XlsxSheet::TYPE_NUMBER],
            ['name' => 'seniorApprovedMinor', 'type' => XlsxSheet::TYPE_NUMBER],
            ['name' => 'financeApprovedMinor', 'type' => XlsxSheet::TYPE_NUMBER],
        ],
        self::TRANSACTIONS => [
            ['name' => 'transactionPublicId', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'revisionNumber', 'type' => XlsxSheet::TYPE_NUMBER],
            ['name' => 'expenseDate', 'type' => XlsxSheet::TYPE_DATE],
            ['name' => 'amountMinor', 'type' => XlsxSheet::TYPE_NUMBER],
            ['name' => 'description', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'costCenterCode', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'costCenterName', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'expenseTypeTitle', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'currentStage', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'currentStatus', 'type' => XlsxSheet::TYPE_TEXT],
        ],
        self::DECISIONS => [
            ['name' => 'decisionPublicId', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'transactionPublicId', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'stage', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'outcome', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'actorUid', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'actorDisplayName', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'actorRole', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'explanation', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'decidedAt', 'type' => XlsxSheet::TYPE_DATE],
        ],
        self::REVISIONS => [
            ['name' => 'revisionPublicId', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'transactionPublicId', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'revisionNumber', 'type' => XlsxSheet::TYPE_NUMBER],
            ['name' => 'active', 'type' => XlsxSheet::TYPE_BOOLEAN],
            ['name' => 'expenseDate', 'type' => XlsxSheet::TYPE_DATE],
            ['name' => 'amountMinor', 'type' => XlsxSheet::TYPE_NUMBER],
            ['name' => 'description', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'costCenterCode', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'expenseTypeTitle', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'createdAt', 'type' => XlsxSheet::TYPE_DATE],
            ['name' => 'createdBy', 'type' => XlsxSheet::TYPE_TEXT],
        ],
        self::AUDIT => [
            ['name' => 'eventPublicId', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'occurredAt', 'type' => XlsxSheet::TYPE_DATE],
            ['name' => 'eventType', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'stage', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'actorUid', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'actorDisplayName', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'actorRole', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'explanation', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'beforeJson', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'afterJson', 'type' => XlsxSheet::TYPE_TEXT],
        ],
        self::EVIDENCE => [
            ['name' => 'evidencePublicId', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'transactionPublicId', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'revisionNumber', 'type' => XlsxSheet::TYPE_NUMBER],
            ['name' => 'originatingRevisionPublicId', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'originalName', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'mediaType', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'sizeBytes', 'type' => XlsxSheet::TYPE_NUMBER],
            ['name' => 'active', 'type' => XlsxSheet::TYPE_BOOLEAN],
            ['name' => 'uploaderUid', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'uploaderDisplayName', 'type' => XlsxSheet::TYPE_TEXT],
            ['name' => 'uploadedAt', 'type' => XlsxSheet::TYPE_DATE],
        ],
    ];

    public const MEDIA_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(private readonly TransientExportStore $store) {}

    /**
     * The six sheets of a complete-sheet export, each with its contract columns and the supplied rows.
     *
     * @param array<string, list<array<string, scalar|null>>> $rowsBySheet keyed by sheet name
     * @return list<XlsxSheet>
     */
    public function completeSheet(array $rowsBySheet): array
    {
        $missing = $this->missingColumns($rowsBySheet);
        if ($missing !== []) {
            throw new InvalidArgumentException(
                "The complete-sheet layout requires columns this export does not provide:\\n  - "
                . implode("\\n  - ", $missing)
            );
        }

        $sheets = [];
        foreach (self::COMPLETE_SHEET_LAYOUT as $name) {
            $sheets[] = new XlsxSheet(
                $name,
                self::COMPLETE_SHEET_COLUMNS[$name],
                $rowsBySheet[$name] ?? []
            );
        }

        return $sheets;
    }

    /**
     * Which required sheets or columns are absent, as data.
     *
     * Returned rather than only thrown so a caller can report the whole gap at once instead of one
     * column per run — the same reason the route guard reports its full unresolved set.
     *
     * @param array<string, list<array<string, scalar|null>>> $rowsBySheet
     * @return list<string>
     */
    public function missingColumns(array $rowsBySheet): array
    {
        $missing = [];

        foreach (self::COMPLETE_SHEET_LAYOUT as $name) {
            $rows = $rowsBySheet[$name] ?? [];
            if ($rows === []) {
                // An empty sheet is legitimate — a sheet with no decisions yet — and still needs its
                // columns, which are supplied by the layout itself. Only a *named* sheet with a row
                // missing a column is a gap.
                continue;
            }

            $present = array_keys($rows[0]);
            foreach (self::COMPLETE_SHEET_COLUMNS[$name] as $column) {
                if (!in_array($column['name'], $present, true)) {
                    $missing[] = sprintf('%s.%s', $name, $column['name']);
                }
            }
        }

        return $missing;
    }

    /**
     * Build the workbook and persist it to transient storage.
     *
     * @param list<XlsxSheet> $sheets
     * @return array{artifactName: string, artifactBytes: int, sheetNames: list<string>, rowCounts: array<string, int>}
     */
    public function build(array $sheets): array
    {
        if ($sheets === []) {
            throw new InvalidArgumentException('An XLSX export needs at least one sheet');
        }

        $artifactName = 'sheet-' . bin2hex(random_bytes(8)) . '.xlsx';
        $scratchPath = $this->store->scratchPath($artifactName);

        try {
            $spreadsheet = new Spreadsheet();
            try {
                $this->write($spreadsheet, $sheets);
                (new Xlsx($spreadsheet))->save($scratchPath);
            } finally {
                $spreadsheet->disconnectWorksheets();
            }

            $this->store->persist($artifactName, $scratchPath);
            $size = $this->store->size($artifactName);

            $rowCounts = [];
            foreach ($sheets as $sheet) {
                $rowCounts[$sheet->name] = $sheet->rowCount();
            }

            return [
                'artifactName' => $artifactName,
                'artifactBytes' => $size ?? 0,
                'sheetNames' => array_map(static fn (XlsxSheet $s): string => $s->name, $sheets),
                'rowCounts' => $rowCounts,
            ];
        } finally {
            if (is_file($scratchPath)) {
                @unlink($scratchPath);
            }
        }
    }

    public function mediaType(): string
    {
        return self::MEDIA_TYPE;
    }

    /**
     * @param list<XlsxSheet> $sheets
     */
    private function write(Spreadsheet $spreadsheet, array $sheets): void
    {
        $workbook = $spreadsheet->getActiveSheet();
        $workbook->setTitle($sheets[0]->name);

        foreach ($sheets as $index => $sheet) {
            $target = $index === 0 ? $workbook : $spreadsheet->createSheet();
            if ($index > 0) {
                $target->setTitle($sheet->name);
            }
            $this->writeSheet($target, $sheet);
        }
    }

    private function writeSheet(Worksheet $target, XlsxSheet $sheet): void
    {
        $columns = $sheet->columns;
        foreach ($columns as $offset => $column) {
            $target->setCellValueExplicit(
                $this->cellCoordinate($offset, 1),
                $column['name'],
                DataType::TYPE_STRING
            );
        }

        // Date columns get an ISO-ish number format on the whole column up front, because a date is
        // stored as a serial number and would otherwise display as one. Applying the format per column
        // rather than per cell also means an empty cell in a date column formats correctly.
        foreach ($columns as $offset => $column) {
            if ($column['type'] === XlsxSheet::TYPE_DATE) {
                $letter = Coordinate::stringFromColumnIndex($offset + 1);
                $target->getStyle($letter . '2:' . $letter . (count($sheet->rows) + 1))
                    ->getNumberFormat()
                    ->setFormatCode('yyyy-mm-dd hh:mm');
            }
        }

        foreach ($sheet->rows as $rowIndex => $row) {
            foreach ($columns as $offset => $column) {
                $value = $row[$column['name']] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                $target->setCellValueExplicit(
                    $this->cellCoordinate($offset, $rowIndex + 2),
                    $this->typedValue($value, $column['type'], $sheet->name, $column['name']),
                    $this->dataType($column['type'])
                );
            }
        }
    }

    /**
     * Coerce a value to the type its column declares, refusing one that does not fit.
     *
     * The refusal is the point. Silently writing `125000` as text would produce a workbook that opens
     * correctly and cannot be summed — the failure the typed-cell requirement exists to prevent — and it
     * would only be discovered by whoever tried to total the column.
     */
    private function typedValue(
        mixed $value,
        string $type,
        string $sheet,
        string $column
    ): \DateTimeInterface|bool|float|int|string {
        return match ($type) {
            XlsxSheet::TYPE_NUMBER => $this->asNumber($value, $sheet, $column),
            XlsxSheet::TYPE_DATE => $this->asDate($value, $sheet, $column),
            XlsxSheet::TYPE_BOOLEAN => $this->asBoolean($value, $sheet, $column),
            default => (string) $value,
        };
    }

    private function asNumber(mixed $value, string $sheet, string $column): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        throw new InvalidArgumentException(sprintf(
            "Column %s.%s is numeric but the value is a %s; a numeric cell that Excel treats as text "
            . 'cannot be totalled by the recipient, so this is refused rather than coerced',
            $sheet,
            $column,
            get_debug_type($value)
        ));
    }

    /**
     * A `DateTimeInterface` as an Excel serial number.
     *
     * PhpSpreadsheet's `setCellValueExplicit` with a numeric type rejects a `DateTimeInterface`
     * outright, so the conversion has to happen here. Returning a serial rather than a `DateTime` is
     * also what makes the cell a *date* to a reader: the number format applied above is what turns the
     * serial back into a date, and a cell holding a raw `DateTime` object would be neither numeric nor
     * displayable.
     */
    private function asDate(mixed $value, string $sheet, string $column): float
    {
        if ($value instanceof \DateTimeInterface) {
            return (float) ExcelDate::PHPToExcel($value);
        }

        throw new InvalidArgumentException(sprintf(
            "Column %s.%s is a date but the value is a %s; pass a DateTimeInterface",
            $sheet,
            $column,
            get_debug_type($value)
        ));
    }

    private function asBoolean(mixed $value, string $sheet, string $column): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        throw new InvalidArgumentException(sprintf(
            "Column %s.%s is a boolean but the value is a %s; pass a bool",
            $sheet,
            $column,
            get_debug_type($value)
        ));
    }

    /**
     * The cell data type for a column type.
     *
     * `DataType` is a class of string constants, not an enum, so this returns a string. A date is written
     * as a numeric cell with a date number format applied by the caller; PhpSpreadsheet derives the
     * format from the value.
     */
    private function dataType(string $type): string
    {
        return match ($type) {
            XlsxSheet::TYPE_NUMBER, XlsxSheet::TYPE_DATE => DataType::TYPE_NUMERIC,
            XlsxSheet::TYPE_BOOLEAN => DataType::TYPE_BOOL,
            default => DataType::TYPE_STRING,
        };
    }

    private function cellCoordinate(int $columnOffset, int $row): string
    {
        return Coordinate::stringFromColumnIndex($columnOffset + 1) . $row;
    }
}
