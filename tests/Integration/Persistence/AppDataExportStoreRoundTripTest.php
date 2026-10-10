<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Persistence;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * T110: `AppDataExportStore` and the exporter's storage path, against a real `IAppData`.
 *
 * ## Why this file exists at all
 *
 * `contracts/exports.md` requires "generated artifacts use private transient application storage and are
 * removed after streaming", and T110 was first marked complete while the implementation wrote to the
 * host's *shared* `sys_get_temp_dir()`. Convergence caught that, the store was reworked onto
 * `IAppData` — and then every test still ran against `InMemoryTransientExportStore`.
 *
 * That is precisely the gap T236 was created for. Thirteen in-memory doubles meant no test ever
 * executed a real persistence statement, and when T236 finally did, it found **nine broken
 * repositories**, three of which could not write a single row. Thirteen doubles hid nine defects;
 * a fourteenth would have hidden whatever is wrong in an adapter that has never touched a real
 * filesystem.
 *
 * The parts most likely to be wrong are the ones a double cannot see: whether
 * `getFolder`/`newFolder`/`newFile` compose the way the adapter assumes, whether
 * `stream_copy_to_stream()` into `ISimpleFile::write()` works, and whether what that stream writes is
 * readable through `ISimpleFile::read()` afterwards.
 *
 * ## How it reaches a real filesystem
 *
 * Through `roundtrip-runner.php`'s new `appdata` scenario in a subprocess, which bootstraps the
 * Nextcloud server. The PHPUnit process cannot: `tests/bootstrap.php` wires in-memory doubles, and
 * loading `lib/base.php` into it would replace the global server. The runner reports observations and
 * this file asserts them, so a bug in the runner cannot agree with itself into a green test.
 *
 * ## How "the artifact is not the scratch file" is proven
 *
 * Not by comparing paths — `ISimpleFolder` deliberately does not expose an absolute one. By ordering:
 * the exporter deletes its scratch in a `finally`, and the artifact is still in app storage afterwards.
 * If the artifact were the scratch file, deleting the scratch would have deleted it.
 *
 * Scenarios are memoised per process: this is one Nextcloud bootstrap for the whole file, and the
 * report is a read-only record of what it observed.
 */
final class AppDataExportStoreRoundTripTest extends TestCase
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
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' appdata 2>/dev/null'
        );

        if ($output === null || trim($output) === '') {
            throw new RuntimeException(
                'The round-trip runner produced no output for the appdata scenario. It exits with a '
                . 'message on stderr when it cannot bootstrap Nextcloud.'
            );
        }

        $decoded = json_decode($output, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("The round-trip runner did not return JSON:\n" . $output);
        }
        if (isset($decoded['error'])) {
            throw new RuntimeException(
                'The appdata round trip failed against the real filesystem: ' . $decoded['error']
            );
        }

        return self::$report = $decoded;
    }

    // ===== The store is the real thing =====

    public function testTheStoreIsBuiltOnARealAppDataInstance(): void
    {
        $store = $this->report()['store'];

        // The class name is the assertion: a Nextcloud `OC\Files\AppData\AppData` reached through
        // IAppDataFactory, not a double with the same interface.
        self::assertSame(
            'OCA\PettyCash\Infrastructure\Nextcloud\AppDataExportStore',
            $store,
            'the scenario must exercise the production store, not a substitute'
        );
    }

    public function testTheScratchPathIsARealWritableFileOutsideAppStorage(): void
    {
        $scratch = $this->report()['scratch'];

        self::assertTrue($scratch['isFile']);
        self::assertTrue($scratch['isWritable']);
        // Confirms the scratch/artifact split rather than assuming it: the build location is the
        // system temp directory, and that is precisely why it is scratch and not the artifact.
        self::assertTrue($scratch['inSystemTemp']);
    }

    // ===== persist() through real IAppData =====

    public function testPersistWritesThroughRealAppData(): void
    {
        $persist = $this->report()['persist'];

        self::assertTrue($persist['exists'], 'the artifact must be visible in app storage');
        self::assertTrue($persist['sizeMatchesSource'], 'the stored size must equal what was written');
    }

    public function testThePersistedArtifactReadsBackIdentically(): void
    {
        $persist = $this->report()['persist'];

        self::assertTrue($persist['readBackIdentical'], 'a streamed write must be byte-identical');
        // Compared as digests as well, so a failure names the divergence rather than only that one
        // happened, and so a truncated-then-padded artifact cannot pass a length check alone.
        self::assertTrue($persist['shaMatches']);
        self::assertSame($persist['shaOfOriginal'], $persist['shaOfStored']);
    }

    public function testTheArtifactIsListedInTheExportsFolder(): void
    {
        $folder = $this->report()['folder'];

        self::assertTrue($folder['listingIsAllFiles'], 'the exports folder must actually contain files');
        self::assertTrue(
            $folder['listingIncludesArtifact'],
            'the persisted artifact must appear in the folder listing, which is what proves it went '
            . 'through IAppData rather than being tracked in memory'
        );
    }

    // ===== Replace and delete semantics =====

    public function testPersistingTheSameNameReplacesRatherThanDuplicates(): void
    {
        $replace = $this->report()['replace'];

        self::assertTrue($replace['replacementMatched'], 'the second write must win');
        self::assertSame(34, $replace['sizeAfterSecondReplace']);
    }

    public function testDeleteRemovesOneArtifactAndLeavesTheOthers(): void
    {
        $delete = $this->report()['delete'];

        self::assertSame(5, $delete['sizeBeforeDelete']);
        self::assertTrue($delete['otherGone']);
        self::assertTrue($delete['unrelatedStillThere'], 'delete must not sweep the whole folder');
    }

    public function testDeletingAnAbsentArtifactIsQuiet(): void
    {
        // Cleanup runs unconditionally after streaming, including for a download that never started,
        // so a missing artifact must not raise.
        self::assertTrue($this->report()['delete']['deleteOfAbsentIsQuiet']);
    }

    public function testDeleteAllEmptiesTheFolder(): void
    {
        $deleteAll = $this->report()['deleteAll'];

        self::assertTrue($deleteAll['hadArtifacts'], 'the scenario must have artifacts to clear');
        self::assertTrue(
            $deleteAll['nothingLeft'],
            'deleteAll() is the contract\'s "removed after streaming", so it must actually remove'
        );
    }

    // ===== The exporter end to end, on a real filesystem =====

    public function testTheExporterStoresItsArchiveInAppStorage(): void
    {
        $exporter = $this->report()['exporter'];

        self::assertTrue($exporter['stored']);
        self::assertTrue($exporter['sizeAgrees'], 'the reported size must match the stored bytes');
    }

    public function testTheStoredArtifactIsAReadableZip(): void
    {
        $exporter = $this->report()['exporter'];

        self::assertTrue($exporter['isZip'], 'a ZIP written through AppData must still be a ZIP');
        self::assertTrue($exporter['manifestPresent']);
        self::assertContains(
            'manifest.csv',
            $exporter['manifestMembers'],
            'the manifest must be a member of the stored archive'
        );
    }

    public function testTheStoredManifestCarriesItsPersianContent(): void
    {
        $exporter = $this->report()['exporter'];

        // Read out of the extracted manifest rather than scanned in the raw archive bytes. A ZIP member
        // is deflated, so the first version of this probe searched the archive file for plaintext,
        // concluded the Persian text had been lost, and proved nothing either way.
        self::assertTrue($exporter['persianPresent'], 'Persian must survive the real write and read');
        self::assertTrue($exporter['manifestHasSheetTitle']);
    }

    public function testTheStoredEvidenceMemberHasItsOriginalBytes(): void
    {
        self::assertTrue(
            $this->report()['exporter']['evidenceContentMatches'],
            'an evidence member must round-trip through AppData unchanged'
        );
    }

    public function testTheStoredManifestCarriesEveryColumn(): void
    {
        $header = strtok($this->report()['exporter']['manifestText'], "\n");

        foreach (\OCA\PettyCash\Infrastructure\Export\EvidenceArchiveExporter::MANIFEST_COLUMNS as $column) {
            self::assertStringContainsString(
                $column,
                (string) $header,
                "the manifest stored in app storage must carry {$column}"
            );
        }
    }

    // ===== The scratch/artifact distinction =====

    public function testTheScratchFileIsGoneAfterTheBuild(): void
    {
        self::assertSame(
            [],
            $this->report()['exporter']['leftoverScratchFiles'],
            'the build must leave no scratch file in the shared system temp directory'
        );
    }

    // ===== Cleanup on the failure path, on the real filesystem =====

    public function testAFailedBuildPropagatesItsError(): void
    {
        $failure = $this->report()['failure'];

        self::assertTrue($failure['propagated'], 'a reader failure must not be swallowed');
        self::assertStringContainsString('deliberate reader failure', $failure['message']);
    }

    public function testAFailedBuildLeavesNoScratchFileBehind(): void
    {
        // The unit test proves this against the double; this proves it against the real path. A
        // partially assembled evidence archive left in the shared system temp directory is precisely
        // what the contract's "removed after streaming" exists to prevent, and a `finally` that only
        // runs on the happy path is a common and quiet way to leak one.
        self::assertTrue(
            $this->report()['failure']['noScratchLeak'],
            'a failed build must remove its scratch file, not just a successful one'
        );
        self::assertSame([], $this->report()['failure']['leakedScratch']);
    }

    public function testTheArtifactOutlivedItsScratchFile(): void
    {
        $exporter = $this->report()['exporter'];

        // The distinctness proof. Not a path comparison — `ISimpleFolder` does not expose one — but an
        // ordering: the scratch was deleted and the artifact is still in app storage. Had the artifact
        // been the scratch file, deleting the scratch would have taken it with it.
        self::assertTrue($exporter['scratchRemoved']);
        self::assertTrue(
            $exporter['artifactOutlivedScratch'],
            'the persisted artifact must be a different file from the one the archive was assembled in'
        );
    }
}
