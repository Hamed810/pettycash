<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Persistence;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * T111: `ExportService` against real app storage and a real evidence store.
 *
 * ## What this file is for
 *
 * `ExportServiceTest` proves the orchestration and the authorization ordering on doubles. Two claims in
 * that file cannot be settled that way, and both are claims about the *filesystem*, so a double would
 * report them green no matter what:
 *
 * 1. **The build scratch is actually removed.** `generateCsv()` writes to `$store->scratchPath()` — a
 *    real `tempnam()` file — and deletes it in a `finally`. An in-memory store can be told the scratch
 *    still exists, but only a real file on a real filesystem proves the deletion happened.
 * 2. **A missing evidence file really throws.** The service omits an unreadable evidence member by
 *    catching `RuntimeException`, which is only the right catch because
 *    `AppDataEvidenceStore::read()` converts `NotFoundException` into it. If the real store threw
 *    something else, the service would fail loudly instead of omitting; if it returned `''`, the
 *    "omitted rather than archived empty" guard would be dead code.
 *
 * The first claim is not hypothetical: the first version of `generateCsv()` never unlinked its scratch,
 * and the copy would have accumulated in the system temp directory with nothing tied to its lifetime.
 *
 * ## The runner is a probe, not an oracle
 *
 * The scenario runs in a subprocess through `roundtrip-runner.php`, because the PHPUnit process wires in
 * doubles and cannot load `lib/base.php`. The runner reports observations and this file asserts them,
 * so a bug in the probe cannot agree with itself into a green test.
 */
final class ExportServiceRealAdapterTest extends TestCase
{
    private static ?array $report = null;

    private function report(): array
    {
        if (self::$report !== null) {
            return self::$report;
        }

        $runner = __DIR__ . '/roundtrip-runner.php';
        if (!is_file($runner)) {
            throw new RuntimeException("The round-trip runner is missing at {$runner}.");
        }

        $output = shell_exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' exportservice 2>/dev/null'
        );

        if ($output === null || trim($output) === '') {
            throw new RuntimeException(
                'The round-trip runner produced no output for the exportservice scenario. It exits with a '
                . 'message on stderr when it cannot bootstrap Nextcloud.'
            );
        }

        $decoded = json_decode($output, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("The round-trip runner did not return JSON:\n" . $output);
        }
        if (isset($decoded['error'])) {
            throw new RuntimeException(
                'The export service failed against real adapters: ' . $decoded['error']
            );
        }

        return self::$report = $decoded;
    }

    // ===== Authorization before generation, on real storage =====

    public function testARefusedExportWritesNothingToRealAppStorage(): void
    {
        $report = $this->report();

        self::assertTrue($report['refusedThrew'], 'a purchaser with no scope must be refused');
        // The load-bearing half: not merely an exception, but an app-data folder byte-for-byte unchanged.
        self::assertTrue(
            $report['refusedAddedNothing'],
            'no artifact may reach real storage for a refused export'
        );
    }

    public function testTheRefusalReachesTheCallerAsAnException(): void
    {
        self::assertStringContainsString('scope', $this->report()['refusedReason']);
    }

    public function testPdfIsRefusedAgainstRealAdaptersToo(): void
    {
        $report = $this->report();

        self::assertFalse($report['pdfAllowed'], 'T109 is blocked, so pdf must be refused');
        self::assertTrue($report['pdfReasonMentionsT109']);
        self::assertTrue($report['pdfReasonMentionsFont'], 'the reason must say why, not just that');
    }

    public function testAScopedManagerIsAllowedWhereAPurchaserIsRefused(): void
    {
        $report = $this->report();

        self::assertTrue($report['scopedAllowed']);
        self::assertFalse($report['refusedRoleAllowed']);
    }

    // ===== CSV through real IAppData =====

    public function testACsvExportLandsInRealAppStorage(): void
    {
        $report = $this->report();

        self::assertTrue($report['csvStored']);
        self::assertSame('csv', $report['csvFormat']);
        self::assertSame(2, $report['csvRowCount']);
    }

    public function testTheStoredSizeMatchesWhatTheServiceReported(): void
    {
        self::assertTrue(
            $this->report()['csvSizeMatchesStore'],
            'the reported byte size must be the size real app storage holds, not a pre-write guess'
        );
    }

    public function testPersianSurvivesTheRoundTripThroughRealStorage(): void
    {
        $report = $this->report();

        self::assertTrue($report['csvHasPersian'], 'the UTF-8 bytes must not be mangled by the store');
        self::assertSame(3, $report['csvRows'], 'a header plus two data rows');
    }

    public function testTheBuildScratchIsActuallyRemovedFromTheFilesystem(): void
    {
        // The whole reason this file exists. A double cannot show a leaked temp file; the real store
        // writes to a real tempnam() path and this is the only place that difference is observable.
        self::assertSame(
            [],
            $this->report()['csvLeakedScratch'],
            'a generated export must not leave a copy of itself in the system temp directory'
        );
    }

    // ===== XLSX =====

    public function testAnXlsxExportIsARealWorkbookInRealAppStorage(): void
    {
        $report = $this->report();

        self::assertTrue($report['xlsxStored']);
        self::assertTrue($report['xlsxIsZipContainer'], 'an xlsx is a zip container; anything else is not one');
        self::assertSame(2, $report['xlsxRowCount']);
    }

    // ===== ZIP reads the real evidence store =====

    public function testAZipExportIncludesRealEvidenceBytes(): void
    {
        $report = $this->report();

        self::assertTrue($report['zipStored']);
        self::assertTrue($report['zipIncludedRealEvidence'], 'the member count must reflect real app-data content');
        self::assertSame(
            'real-evidence-bytes',
            $report['zipEvidenceMember'],
            'the archived member must be the bytes the real evidence store returned'
        );
    }

    public function testTheManifestStillCarriesPersianActorNames(): void
    {
        self::assertTrue(
            $this->report()['zipManifestHasPersian'],
            'the manifest is read from inside the deflated archive, not scanned in the raw bytes'
        );
    }

    public function testAGenuinelyAbsentEvidenceKeyIsOmittedNotArchivedEmpty(): void
    {
        $report = $this->report();

        self::assertSame(0, $report['absentRowCount'], 'an unreadable key must be omitted');
        self::assertTrue(
            $report['absentZipIsValidButEmpty'],
            'a valid archive with no evidence member — not a member that looks like evidence and is not'
        );
    }

    // ===== Cleanup =====

    public function testCleanupEmptiesRealAppStorage(): void
    {
        $report = $this->report();

        self::assertTrue($report['cleanupRemovedEverything'], 'artifacts must be gone after cleanup');
        self::assertTrue($report['cleanupEmptiedStore'], 'the real exports folder must be empty afterwards');
    }

    public function testCleanupIsSafeToCallAgainOnAnEmptyStore(): void
    {
        self::assertTrue($this->report()['cleanupSafeWhenEmpty']);
    }
}
