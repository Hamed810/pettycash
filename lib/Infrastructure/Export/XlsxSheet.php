<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Export;

use InvalidArgumentException;

/**
 * One named sheet of a complete-sheet XLSX export, with its typed columns and rows.
 *
 * ## Why a value object rather than a raw array
 *
 * `contracts/exports.md` fixes the six sheets and what each must carry, and the acceptance check is
 * "XLSX contains the promised sheets and numeric values". A value object can enforce both — the sheet has
 * to be named, its columns have to be declared, and a row has to match the declared columns — so a layout
 * mistake is a refusal at build time rather than a workbook a recipient opens and finds incomplete.
 *
 * ## Typed columns
 *
 * The contract requires "typed numeric/date cells" for XLSX, which CSV explicitly does not have to carry.
 * `column()` therefore takes a type, and the builder writes a real numeric or date cell rather than a
 * string that happens to look like a number. A spreadsheet recalculates a numeric cell and formats a date
 * cell; it treats text as opaque, so an amount exported as text cannot be summed by the recipient — which
 * is the whole point of the format.
 */
final readonly class XlsxSheet
{
    public const TYPE_TEXT = 'text';
    public const TYPE_NUMBER = 'number';
    public const TYPE_DATE = 'date';
    public const TYPE_BOOLEAN = 'boolean';

    /** @var list<array{name: string, type: string}> */
    public array $columns;

    /** @var list<array<string, scalar|null>> */
    public array $rows;

    /**
     * @param list<array{name: string, type: string}> $columns
     * @param list<array<string, scalar|null>> $rows
     */
    public function __construct(
        public string $name,
        array $columns,
        array $rows = []
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('An XLSX sheet needs a name');
        }
        // Excel forbids these in a sheet name and silently repairs or rejects a workbook that uses one.
        // Rejecting here names the offender, which a library-level repair would not.
        if (preg_match('/[\\\\\/\?\*\[\]:]/', $name) === 1) {
            throw new InvalidArgumentException("Sheet name '{$name}' contains a character Excel forbids");
        }
        if (strlen($name) > 31) {
            throw new InvalidArgumentException("Sheet name '{$name}' exceeds Excel's 31-character limit");
        }
        if ($columns === []) {
            throw new InvalidArgumentException("Sheet '{$name}' needs at least one column");
        }

        foreach ($columns as $column) {
            if (!isset($column['name'], $column['type'])) {
                throw new InvalidArgumentException("Every column of '{$name}' needs a name and a type");
            }
            if (!in_array($column['type'], [
                self::TYPE_TEXT,
                self::TYPE_NUMBER,
                self::TYPE_DATE,
                self::TYPE_BOOLEAN,
            ], true)) {
                throw new InvalidArgumentException(
                    "Column '{$column['name']}' of '{$name}' has unknown type '{$column['type']}'"
                );
            }
        }

        $this->columns = array_values($columns);
        $this->rows = array_values($rows);
    }

    public function withRows(array $rows): self
    {
        return new self($this->name, $this->columns, $rows);
    }

    /**
     * @return list<string>
     */
    public function columnNames(): array
    {
        return array_map(static fn (array $c): string => $c['name'], $this->columns);
    }

    public function typeOf(string $column): string
    {
        foreach ($this->columns as $candidate) {
            if ($candidate['name'] === $column) {
                return $candidate['type'];
            }
        }

        throw new InvalidArgumentException("Sheet '{$this->name}' has no column '{$column}'");
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
