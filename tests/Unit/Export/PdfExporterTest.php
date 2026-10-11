<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Export;

use InvalidArgumentException;
use OCA\PettyCash\Infrastructure\Export\PdfExporter;
use PHPUnit\Framework\TestCase;

/**
 * T103 (Persian RTL PDF clause): the exporter emits a well-formed PDF whose
 * Persian text is shaped and selectable, with the vendored subset font embedded.
 */
final class PdfExporterTest extends TestCase
{
    private PdfExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new PdfExporter();
    }

    public function testMediaTypeIsPdf(): void
    {
        self::assertSame('application/pdf', $this->exporter->mediaType());
    }

    public function testPersianRowsProduceAShapedSelectableDocument(): void
    {
        $bytes = $this->exporter->export([
            ['title' => 'برگهٔ خردید', 'amount' => 125000],
            ['title' => 'هزینهٔ ایاب', 'amount' => 42000],
        ], ['title', 'amount']);

        self::assertStringStartsWith('%PDF-', $bytes);
        // Subset-embedded Vazirmatn (AAAAAB+ prefix marker)...
        self::assertStringContainsString('Vazirmatn', $bytes);
        // ...with a ToUnicode map, so the shaped text stays selectable.
        self::assertStringContainsString('ToUnicode', $bytes);
    }

    public function testNonArrayRowsAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->exporter->export(['not-an-array']);
    }

    public function testMissingFontDefinitionFailsLoudly(): void
    {
        $exporter = new PdfExporter('/nonexistent-fonts-dir');

        $this->expectException(InvalidArgumentException::class);
        $exporter->export([['a' => 'b']]);
    }
}
