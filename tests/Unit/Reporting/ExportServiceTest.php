<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Reporting;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\PettyCash\Application\Port\PrivateFileStore;
use OCA\PettyCash\Application\Reporting\ExportService;
use OCA\PettyCash\Application\Reporting\ReportScope;
use OCA\PettyCash\Infrastructure\Export\CsvExporter;
use OCA\PettyCash\Infrastructure\Export\EvidenceArchiveEntry;
use OCA\PettyCash\Infrastructure\Export\EvidenceArchiveExporter;
use OCA\PettyCash\Infrastructure\Export\XlsxExporter;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Tests\TestDoubles\InMemoryEvidenceRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryPrivateFileStore;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransientExportStore;
use PHPUnit\Framework\TestCase;

/**
 * T111: export orchestration, transient cleanup, and authorization **before** generation.
 *
 * ## The test that carries the task
 *
 * `testARefusedExportProducesNoArtifactAtAll` is the one that matters. `contracts/exports.md` requires
 * that authorization happen "before filtering, aggregation, or **file generation**", and the failure mode
 * of getting that wrong is not a wrong status code — it is a file written to disk that nobody is shown
 * but that exists outside the authorization boundary. For evidence that is the exact material FR-060
 * protects.
 *
 * So the assertion is on **absence**: no artifact in the transient store, and no scratch file on disk.
 * A test that only asserted the exception would pass for a service that generated the file and then
 * refused to return it, which is the defect.
 */
final class ExportServiceTest extends TestCase
{
    private InMemoryTransientExportStore $store;
    private InMemoryPrivateFileStore $files;
    private ExportService $exports;

    protected function setUp(): void
    {
        $this->store = new InMemoryTransientExportStore();
        $this->files = new InMemoryPrivateFileStore();
        $this->exports = new ExportService(
            new CsvExporter(),
            new XlsxExporter($this->store),
            new EvidenceArchiveExporter($this->store),
            $this->store,
            $this->files
        );
    }

    private function admin(): ReportScope
    {
        return new ReportScope('admin-1', [Role::Administrator], [], true);
    }

    private function manager(): ReportScope
    {
        return new ReportScope('manager-1', [Role::CostCenterManager], [11]);
    }

    private function rows(): array
    {
        return [
            ['id' => '1', 'title' => 'برگهٔ خردید', 'amount' => '125000'],
            ['id' => '2', 'title' => 'هزینهٔ ایاب', 'amount' => '42000'],
        ];
    }

    // ===== Authorization before generation =====

    public function testARefusedExportProducesNoArtifactAtAll(): void
    {
        $refused = new ReportScope('purchaser-1', [Role::Purchaser], []);

        try {
            $this->exports->generate($refused, ExportService::RESOURCE_SHEET, ExportService::FORMAT_CSV, $this->rows());
            self::fail('a sheet export with no scope must be refused');
        } catch (InvalidArgumentException) {
            // expected
        }

        // The load-bearing assertions: absence, not just an exception.
        self::assertSame([], $this->store->artifactNames(), 'no file may exist for a refused export');
        self::assertSame(0, $this->store->persistCount());
        self::assertSame([], $this->store->survivingScratchFiles(), 'and no scratch may be left behind');
    }

    public function testAPurchaserCannotExportSheetsTheyDoNotOwn(): void
    {
        $scope = new ReportScope('purchaser-1', [Role::Purchaser], []);

        $decision = $this->exports->authorization($scope, ExportService::RESOURCE_SHEET, ExportService::FORMAT_CSV);

        self::assertFalse($decision['allowed']);
    }

    public function testAManagerWithScopeMayExportSheets(): void
    {
        $decision = $this->exports->authorization(
            $this->manager(),
            ExportService::RESOURCE_SHEET,
            ExportService::FORMAT_CSV
        );

        self::assertTrue($decision['allowed']);
    }

    public function testAnAdministratorMayExportSheets(): void
    {
        self::assertTrue(
            $this->exports->authorization($this->admin(), ExportService::RESOURCE_SHEET, ExportService::FORMAT_CSV)['allowed']
        );
    }

    public function testTheRefusalReasonNamesTheRule(): void
    {
        $scope = new ReportScope('purchaser-1', [Role::Purchaser], []);

        $decision = $this->exports->authorization($scope, ExportService::RESOURCE_SHEET, ExportService::FORMAT_CSV);

        self::assertStringContainsString('scope', $decision['reason']);
    }

    // ===== The contract's enums =====

    public function testTheFormatEnumIsTheContractsSet(): void
    {
        self::assertSame(['csv', 'xlsx', 'zip'], ExportService::SUPPORTED_FORMATS);
    }

    public function testTheResourceEnumIsTheContractsSet(): void
    {
        self::assertSame(
            ['sheet', 'sheets', 'transactions', 'report', 'audit', 'evidence'],
            ExportService::SUPPORTED_RESOURCES
        );
    }

    public function testAnUnknownResourceIsRefused(): void
    {
        $decision = $this->exports->authorization($this->admin(), 'profit_and_loss', ExportService::FORMAT_CSV);

        self::assertFalse($decision['allowed']);
        self::assertStringContainsString('Unknown export resource', $decision['reason']);
    }

    public function testAnUnknownFormatIsRefused(): void
    {
        $decision = $this->exports->authorization($this->admin(), ExportService::RESOURCE_REPORT, 'docx');

        self::assertFalse($decision['allowed']);
        self::assertStringContainsString('Unknown export format', $decision['reason']);
    }

    // ===== PDF is refused, not half-produced =====

    public function testPdfIsRefusedWithTheRecordedReason(): void
    {
        // T109 is blocked on the font asset, so the honest answer is a refusal that says why. Emitting a
        // PDF that cannot render the contract's Persian would be worse than not emitting one.
        $decision = $this->exports->authorization($this->admin(), ExportService::RESOURCE_REPORT, ExportService::FORMAT_PDF);

        self::assertFalse($decision['allowed']);
        self::assertStringContainsString('T109', $decision['reason']);
        self::assertStringContainsString('font', $decision['reason']);
    }

    public function testARefusedPdfProducesNoArtifact(): void
    {
        try {
            $this->exports->generate($this->admin(), ExportService::RESOURCE_REPORT, ExportService::FORMAT_PDF);
            self::fail('pdf must be refused while T109 is blocked');
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame([], $this->store->artifactNames());
    }

    // ===== CSV generation =====

    public function testACsvExportIsPersistedAndReadable(): void
    {
        $result = $this->exports->generate(
            $this->admin(),
            ExportService::RESOURCE_SHEETS,
            ExportService::FORMAT_CSV,
            $this->rows()
        );

        self::assertSame('csv', $result['format']);
        self::assertStringStartsWith('text/csv', $result['mediaType']);
        self::assertSame(2, $result['rowCount']);
        self::assertTrue($this->store->exists($result['artifactName']));
        self::assertGreaterThan(0, $result['byteSize']);

        $bytes = $this->store->artifactBytes($result['artifactName']);
        self::assertStringContainsString('برگهٔ خردید', $bytes);
    }

    public function testNoScratchFileSurvivesASuccessfulExport(): void
    {
        $this->exports->generate(
            $this->admin(),
            ExportService::RESOURCE_SHEETS,
            ExportService::FORMAT_CSV,
            $this->rows()
        );

        self::assertSame([], $this->store->survivingScratchFiles());
    }

    // ===== XLSX generation =====

    public function testAnXlsxExportIsPersistedAsAWorkbook(): void
    {
        $result = $this->exports->generate(
            $this->admin(),
            ExportService::RESOURCE_REPORT,
            ExportService::FORMAT_XLSX,
            $this->rows()
        );

        self::assertSame('xlsx', $result['format']);
        self::assertTrue($this->store->exists($result['artifactName']));
        self::assertSame('PK', substr($this->store->artifactBytes($result['artifactName']), 0, 2));
    }

    // ===== ZIP generation reads the real store =====

    public function testAZipExportIncludesTheEvidenceItWasGiven(): void
    {
        $key = $this->files->putEvidence('tx-1', 1, 'receipt.pdf', 'pdf-bytes');

        $result = $this->exports->generate($this->admin(), ExportService::RESOURCE_EVIDENCE, ExportService::FORMAT_ZIP, [], [
            $this->entry($key),
        ]);

        self::assertSame('zip', $result['format']);
        self::assertSame(1, $result['rowCount'], 'the evidence member must be in the archive');
        self::assertTrue($this->store->exists($result['artifactName']));
    }

    public function testAnUnreadableEvidenceFileIsOmittedRatherThanArchivedAsEmpty(): void
    {
        $result = $this->exports->generate($this->admin(), ExportService::RESOURCE_EVIDENCE, ExportService::FORMAT_ZIP, [], [
            $this->entry('probe/does-not-exist.pdf'),
        ]);

        // A valid, empty archive is the failure this guards: it looks like evidence and is not.
        self::assertSame(0, $result['rowCount']);
    }

    // ===== Cleanup =====

    public function testCleanupRemovesEveryGeneratedArtifact(): void
    {
        $first = $this->exports->generate($this->admin(), ExportService::RESOURCE_SHEETS, ExportService::FORMAT_CSV, $this->rows());
        $second = $this->exports->generate($this->admin(), ExportService::RESOURCE_SHEETS, ExportService::FORMAT_CSV, $this->rows());

        self::assertCount(2, $this->store->artifactNames());

        // "Generated artifacts ... are removed after streaming" — one call for the streaming layer.
        $this->exports->cleanup();

        self::assertSame([], $this->store->artifactNames());
        self::assertFalse($this->store->exists($first['artifactName']));
        self::assertFalse($this->store->exists($second['artifactName']));
    }

    public function testCleanupIsSafeWhenNothingWasGenerated(): void
    {
        $this->exports->cleanup();

        self::assertSame([], $this->store->artifactNames());
    }

    public function testTwoExportsGetDistinctArtifactNames(): void
    {
        $first = $this->exports->generate($this->admin(), ExportService::RESOURCE_SHEETS, ExportService::FORMAT_CSV, $this->rows());
        $second = $this->exports->generate($this->admin(), ExportService::RESOURCE_SHEETS, ExportService::FORMAT_CSV, $this->rows());

        self::assertNotSame($first['artifactName'], $second['artifactName']);
    }

    public function testTheArtifactSizeMatchesWhatIsStored(): void
    {
        $result = $this->exports->generate(
            $this->admin(),
            ExportService::RESOURCE_SHEETS,
            ExportService::FORMAT_CSV,
            $this->rows()
        );

        self::assertSame($this->store->size($result['artifactName']), $result['byteSize']);
    }

    private function entry(string $storageKey): EvidenceArchiveEntry
    {
        return new EvidenceArchiveEntry(
            'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa',
            'receipt.pdf',
            'application/pdf',
            9,
            str_repeat('b', 64),
            true,
            'probe-actor',
            'کاربر آزمایشی',
            new DateTimeImmutable('2026-03-15T10:30:00+00:00'),
            'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb',
            1,
            'cccccccc-3333-4333-8333-cccccccccccc',
            'dddddddd-4444-4444-8444-dddddddddddd',
            'Probe Sheet',
            $storageKey
        );
    }
}
