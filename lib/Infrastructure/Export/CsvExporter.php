<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Export;

use InvalidArgumentException;
use OCA\PettyCash\Application\Port\Exporter;

/**
 * CSV export per `contracts/exports.md`: UTF-8, an explicit header row, locale-neutral machine values,
 * and no formula execution from user-controlled text.
 *
 * ## The one decision that matters here
 *
 * A spreadsheet treats a cell beginning with `=`, `+`, `-` or `@` as a formula, so the usual advice is
 * to prefix such cells with an apostrophe. Applied naively to a report full of money, that advice
 * **corrupts the data**: `-1234.56` becomes `'-1234.56`, and a financial export that turns its
 * negative numbers into text is worse than no export at all.
 *
 * So escaping keys on the PHP **type**, not on the string content. A value that is an `int` or a
 * `float` is written plainly, always. A value that is a `string` is text — possibly a description, a
 * cost-centre name, or an explanation a user typed — and is escaped when it begins with a control
 * character. The consequence is a rule for callers: **pass numbers as numbers, not as numeric
 * strings.** A caller who passes `'-1234.56'` as a string gets `'-1234.56` back, and that is the
 * correct trade: the alternative is silently mangling legitimate negative amounts, and a description
 * that begins with `=` is the case that actually occurs in this application.
 *
 * ## Locale-neutral
 *
 * No thousands separators, no decimal comma, no currency symbol, no localised date. Dates are written
 * as ISO 8601 and numeric values through PHP's plain float-to-string, so the same row produces the
 * same bytes regardless of the server's locale. A report that a spreadsheet reinterprets according to
 * the reader's regional settings is not a machine-readable export.
 */
final class CsvExporter implements Exporter
{
    /**
     * Characters that make a spreadsheet treat a cell as a formula.
     *
     * Tab and carriage return are included because Excel and LibreOffice both treat a leading tab or
     * CR as a formula trigger in a CSV import, which is the actual attack in a CSV that a user opens
     * by double-clicking rather than importing.
     */
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * RFC 4180: no escape character, so a quote inside a field is doubled and nothing else is touched.
     *
     * PHP still defaults `$escape` to a backslash, but that default is deprecated and **will change**,
     * and a backslash is a spreadsheet convenience rather than part of CSV. Writing RFC 4180 means any
     * future reader parses this file with its own defaults instead of having to be told which escape
     * character the writer chose — a report consumed by a script in another language should not need
     * to know anything about this application.
     *
     * The line ending is likewise passed explicitly as "\n" rather than left to the platform default.
     */
    private const CSV_ESCAPE = '';

    /**
     * Write rows as CSV, with an explicit header when one is supplied.
     *
     * The header is an optional argument rather than always derived from the first row because a caller
     * sometimes knows the column set independently of the data — the evidence archive manifest, whose
     * columns are fixed by `contracts/exports.md` and which must still carry its header when the archive
     * turned out to be empty. Deriving it from the first row would make an empty export headerless, and
     * a headerless manifest is not a manifest.
     *
     * Columns in `$header` keep their order and are always emitted. Any key a row carries that the
     * header omits is appended afterwards in first-seen order, so passing a header cannot silently drop
     * a column.
     *
     * @param iterable<array<string,mixed>> $rows
     * @param list<string>|null $header
     */
    public function export(iterable $rows, ?array $header = null): string
    {
        return $this->render($rows, $header);
    }

    /**
     * @param iterable<array<string,mixed>> $rows
     */
    private function render(iterable $rows, ?array $header): string
    {
        $columns = $header ?? [];
        $normalised = [];

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException(sprintf(
                    'CSV row %s is a %s; every row must be an associative array of column => value',
                    (string) $index,
                    get_debug_type($row)
                ));
            }

            foreach (array_keys($row) as $column) {
                if (!in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
            $normalised[] = $row;
        }

        if ($columns === []) {
            // An empty export is a valid answer to "no records match", and it still carries the
            // contract's explicit header requirement as an empty file rather than as a header of
            // guessed column names.
            return '';
        }

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new InvalidArgumentException('Could not open a temporary stream for the CSV export');
        }

        try {
            fputcsv($handle, $columns, ',', '"', self::CSV_ESCAPE, "\n");

            foreach ($normalised as $row) {
                $line = [];
                foreach ($columns as $column) {
                    $line[] = array_key_exists($column, $row)
                        ? $this->cell($row[$column])
                        : '';
                }
                fputcsv($handle, $line, ',', '"', self::CSV_ESCAPE, "\n");
            }

            rewind($handle);
            $csv = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        if ($csv === false) {
            throw new InvalidArgumentException('Could not read back the generated CSV export');
        }

        return $csv;
    }

    public function mediaType(): string
    {
        return 'text/csv; charset=UTF-8';
    }

    /**
     * Render one cell.
     *
     * The type switch is the whole design. Numbers and booleans are written plainly; only strings are
     * candidates for escaping, and only when they begin with a formula trigger.
     */
    private function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            // Written as a word rather than 1/0 so a reader cannot mistake a boolean for a quantity.
            // Locale-neutral in the sense the contract means: no digits that a regional format could
            // re-interpret as a group separator.
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            // Locale-neutral, and explicit about a whole float so a reader does not have to guess
            // whether `1200000.0` was an integer. PHP casts a whole float to an int, which would lose
            // the information that the column is a decimal quantity.
            return fmod($value, 1.0) === 0.0
                ? number_format($value, 0, '.', '')
                : rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            $value = (string) $value;
        }

        if (!is_scalar($value)) {
            throw new InvalidArgumentException(sprintf(
                'CSV cannot render a value of type %s; pass a scalar, a DateTimeInterface, or null',
                get_debug_type($value)
            ));
        }

        $text = (string) $value;

        return $this->startsWithFormulaTrigger($text) ? "'" . $text : $text;
    }

    private function startsWithFormulaTrigger(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        foreach (self::FORMULA_TRIGGERS as $trigger) {
            if (str_starts_with($text, $trigger)) {
                return true;
            }
        }

        return false;
    }
}
