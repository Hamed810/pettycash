<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Export;

use Com\Tecnick\Pdf\Tcpdf;
use InvalidArgumentException;

/**
 * Persian RTL PDF export per `contracts/exports.md` (T109).
 *
 * ## Why this exists the way it does
 *
 * The contract requires a PDF that renders Persian RTL text correctly. Two facts
 * decided the construction: `tc-lib-pdf` reorders bidi text and substitutes
 * Arabic presentation forms inside `Text::prepareText()` (verified: shaped output
 * measured), but it ships no Persian-capable font — so the font is vendored
 * beside this class (`Fonts/vazirmatn-regular.ttf`, OFL, plus its converted
 * definition), and the definition file path is passed explicitly because
 * `Buffer` performs no search-path autodetection. A system font would make the
 * output depend on the host.
 *
 * The font is subset-embedded, so each document carries only the glyphs it uses
 * plus a `/ToUnicode` map: text stays selectable and readable by assistive
 * technology rather than becoming anonymous curves.
 *
 * No formula escaping is needed here, unlike `CsvExporter`: PDF text cells do
 * not execute. Values are rendered plainly and locale-neutrally (ISO dates,
 * plain numbers), matching the CSV reading of the same rows.
 */
final class PdfExporter implements \OCA\PettyCash\Application\Port\Exporter
{
    private const FONT_FAMILY = 'vazirmatn';

    private const FONT_SIZE_PT = 12.0;

    private const PAGE_WIDTH_MM = 180.0;

    private const LINE_HEIGHT_MM = 8.0;

    private const TOP_MM = 20.0;

    private const BOTTOM_LIMIT_MM = 270.0;

    /**
     * @param string $fontDirectory the app-owned directory holding `vazirmatn.json`
     * and its subset files. Defaults to the `Fonts/` directory beside this class so
     * production wiring needs no path, while tests may point at fixtures.
     */
    public function __construct(private readonly string $fontDirectory = __DIR__ . '/Fonts') {}

    /** @param iterable<array<string,mixed>> $rows */
    public function export(iterable $rows, ?array $header = null): string
    {
        // The definition directory is communicated the way the library documents:
        // `K_PATH_FONTS` is read when the font resolves, and the file helper
        // rejects explicit definition paths outside its allowlist — so there is
        // no per-call path to pass. Defined once; redefining is a no-op by the
        // guard, and the constant belongs to the library's contract, not to us.
        if (!defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', rtrim($this->fontDirectory, '/') . '/');
        }
        $definition = rtrim($this->fontDirectory, '/') . '/vazirmatn.json';
        if (!is_file($definition)) {
            throw new InvalidArgumentException('The vendored PDF font definition is missing: ' . $definition);
        }

        $pdf = new Tcpdf('mm', true, true);
        $pdf->font->insert($pdf->pon, self::FONT_FAMILY, '', self::FONT_SIZE_PT);

        $columns = $header ?? [];
        $normalised = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException(sprintf(
                    'PDF row %s is a %s; every row must be an associative array of column => value',
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

        $page = $pdf->addPage();
        $pid = $page['pid'];
        $y = self::TOP_MM;
        [$pid, $y] = $this->writeLine($pdf, $pid, $columns, $y);
        foreach ($normalised as $row) {
            $cells = array_map(fn (string $column): string => $this->cell($row[$column] ?? null), $columns);
            [$pid, $y] = $this->writeLine($pdf, $pid, $cells, $y);
        }

        return $pdf->getOutPDFString();
    }

    public function mediaType(): string
    {
        return 'application/pdf';
    }

    /**
     * @param list<string> $cells
     * @return array{0: int|string, 1: float} current page id and next baseline
     */
    private function writeLine(Tcpdf $pdf, int|string $pid, array $cells, float $y): array
    {
        // Columns joined with a separator rather than positioned: measuring
        // per-column widths would duplicate the layout engine, and a readable
        // one-line-per-record listing is what this format promises. Shaping and
        // bidi happen inside `getTextCell()`; `forcedir 'R'` anchors the
        // right-aligned Persian layout.
        if ($y > self::BOTTOM_LIMIT_MM) {
            $page = $pdf->addPage();
            $pid = $page['pid'];
            $y = self::TOP_MM;
        }
        $text = implode(' | ', $cells);
        if ($text !== '') {
            $operators = $pdf->getTextCell(
                $text,
                15.0,
                $y,
                self::PAGE_WIDTH_MM,
                self::LINE_HEIGHT_MM,
                valign: 'C',
                halign: 'R',
                fill: true,
                forcedir: 'R'
            );
            $pdf->page->addContent($operators, $pid);
        }
        return [$pid, $y + self::LINE_HEIGHT_MM];
    }

    private function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        return (string) $value;
    }
}
