<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Export;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\PettyCash\Infrastructure\Export\CsvExporter;
use OCA\PettyCash\Infrastructure\Export\EvidenceArchiveEntry;
use OCA\PettyCash\Infrastructure\Export\EvidenceArchiveExporter;
use OCA\PettyCash\Tests\TestDoubles\InMemoryTransientExportStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/**
 * T110: grouped evidence ZIP export per `contracts/exports.md`.
 *
 * ## What the archive is actually asserting
 *
 * Three properties, each of which fails in a different direction and so cannot be collapsed into one
 * test:
 *
 * 1. **Manifest-to-file completeness** — every file in the archive has a manifest row and every
 *    manifest row has a file. The contract states both halves ("the manifest lists only files actually
 *    present in the archive" and the acceptance check "the manifest-to-file mapping is complete"), and
 *    they are the same invariant read from opposite ends.
 * 2. **Path safety** — `originalName` is user-controlled, and a traversing member name is read by
 *    whatever extracts the archive. `addFromString()` does not prevent one.
 * 3. **Refusal vs omission** — a file that cannot be read is omitted from the archive *and* the
 *    manifest, and recorded with a reason. A file over FR-074's 100 MB ceiling is a refusal about the
 *    upload validation having failed, not a storage condition.
 *
 * Every test reads the produced ZIP back with `ZipArchive`, so none of them is satisfied by the
 * exporter's own report of what it did.
 */
final class EvidenceArchiveExporterTest extends TestCase
{
    private const SHEET = '11111111-1111-4111-8111-111111111111';
    private const TRANSACTION = '22222222-2222-4222-8222-222222222222';
    private const REVISION = '33333333-3333-4333-8333-333333333333';
    private const EVIDENCE = '44444444-4444-4444-8444-444444444444';

    private InMemoryTransientExportStore $store;

    private EvidenceArchiveExporter $exporter;

    protected function setUp(): void
    {
        $this->store = new InMemoryTransientExportStore();
        $this->exporter = new EvidenceArchiveExporter($this->store);
    }

    /** The stored archive's bytes, read back the way a streaming layer would. */
    private function artifact(string $name): string
    {
        return $this->store->artifactBytes($name);
    }

    // ===== Manifest-to-file completeness =====

    public function testTheArchiveContainsTheFileAndTheManifest(): void
    {
        $result = $this->buildOne();

        self::assertSame(1, $result->fileCount());
        self::assertTrue($this->zipHas($this->artifact($result->artifactName), EvidenceArchiveExporter::MANIFEST_NAME));
    }

    public function testTheManifestHasOneRowPerFileAndTheMappingIsComplete(): void
    {
        $result = $this->buildOne();

        self::assertSame(1, $result->manifestRowCount());
        self::assertTrue($result->manifestMatchesArchive());
    }

    public function testTheManifestCarriesEveryRequiredRelationshipColumn(): void
    {
        $row = $this->buildOne()->manifestRows[0];

        // Exactly the contract's "Group Evidence Manifest" required list, plus archivePath.
        foreach (EvidenceArchiveExporter::MANIFEST_COLUMNS as $column) {
            self::assertArrayHasKey($column, $row, "the manifest must carry {$column}");
        }
        self::assertSame(self::SHEET, $row['sheetPublicId']);
        self::assertSame('Sheikh Office June', $row['sheetTitle']);
        self::assertSame(self::TRANSACTION, $row['transactionPublicId']);
        self::assertSame('2', $row['revisionNumber']);
        self::assertSame(self::REVISION, $row['revisionPublicId']);
        self::assertSame(self::EVIDENCE, $row['evidencePublicId']);
        self::assertSame('receipt.pdf', $row['originalName']);
        self::assertSame('application/pdf', $row['mediaType']);
        self::assertSame(str_repeat('a', 64), $row['checksum']);
        self::assertSame('true', $row['active']);
        self::assertSame('uploader-uid', $row['uploaderUid']);
        self::assertSame('باربر', $row['uploaderDisplayName']);
        self::assertSame('2026-03-15T10:30:00+00:00', $row['uploadedAt']);
    }

    public function testTheManifestDoesNotLeakTheStorageKey(): void
    {
        $result = $this->buildOne();

        // A storage path is an internal detail. Publishing it hands the recipient the AppData layout,
        // which is the thing FR-060's secrecy and ADR-001 exist to protect.
        self::assertStringNotContainsString('roundtrip/', $this->artifact($result->artifactName));
        self::assertStringNotContainsString(
            'storageKey',
            (new CsvExporter())->export($result->manifestRows)
        );
    }

    public function testTheManifestSizeIsTheSizeActuallyArchivedNotTheSizeClaimed(): void
    {
        $entry = $this->entry();
        $bytes = 'exactly-twenty';

        $result = $this->exporter->build([$entry], static fn (): string => $bytes);

        // The entry claims 999999 bytes. The manifest must report what the archive contains, or the
        // row cannot be reconciled against the ZIP.
        self::assertSame((string) strlen($bytes), $result->manifestRows[0]['sizeBytes']);
    }

    public function testAnEmptyArchiveStillCarriesTheManifestHeader(): void
    {
        $result = $this->exporter->build([], static fn (): ?string => null);

        self::assertTrue($result->isEmpty());
        self::assertTrue($result->manifestMatchesArchive());
        $manifest = $this->zipRead($this->artifact($result->artifactName), EvidenceArchiveExporter::MANIFEST_NAME);
        self::assertStringContainsString('sheetPublicId', (string) $manifest);
        self::assertStringContainsString('archivePath', (string) $manifest);
    }

    // ===== The artifact lives in app-private transient storage, not the system temp directory =====

    public function testTheArchiveIsPersistedIntoTheTransientStore(): void
    {
        $result = $this->buildOne();

        // contracts/exports.md: "Generated artifacts use private transient application storage". The
        // previous implementation assembled the archive in the host's shared system temp directory and
        // called that compliant; this asserts the artifact actually reaches the store.
        self::assertSame(1, $this->store->persistCount());
        self::assertTrue($this->store->exists($result->artifactName));
        self::assertSame([$result->artifactName], $this->store->artifactNames());
    }

    public function testTheStoredArtifactIsAReadableZip(): void
    {
        $result = $this->buildOne();
        $bytes = $this->artifact($result->artifactName);

        self::assertSame('PK', substr($bytes, 0, 2), 'the persisted artifact must be a ZIP');
        self::assertTrue($this->zipHas($bytes, EvidenceArchiveExporter::MANIFEST_NAME));
    }

    public function testTheResultCarriesTheArtifactNameAndNotTheArchiveBytes(): void
    {
        $result = $this->buildOne();

        // Asserted structurally as well as behaviourally. Holding the archive in the result would put a
        // PHP memory limit in the middle of a group export whose size the contract does not cap, and it
        // would mean reading a multi-megabyte artifact out of app-private storage for no reason.
        self::assertObjectNotHasProperty('zipBytes', $result);
        self::assertStringEndsWith('.zip', $result->artifactName);
        self::assertGreaterThan(0, $result->artifactBytes);
    }

    public function testTheArtifactSizeMatchesWhatWasStored(): void
    {
        $result = $this->buildOne();

        self::assertSame(
            $this->store->size($result->artifactName),
            $result->artifactBytes,
            'the reported size must be the stored size, not the pre-persist one'
        );
    }

    public function testTwoBuildsGetDistinctArtifactNames(): void
    {
        $first = $this->buildOne();
        $second = $this->buildOne();

        // A timestamp or a counter would collide for two exports started in the same second, and one
        // overwriting the other is a download that serves the wrong evidence.
        self::assertNotSame($first->artifactName, $second->artifactName);
        self::assertCount(2, $this->store->artifactNames());
    }

    public function testNoArchiveIsLeftBehindInTheSystemTempDirectory(): void
    {
        $this->buildOne();

        // The whole point of the fix: the archive must not survive in the host's shared temp directory.
        self::assertSame([], glob(sys_get_temp_dir() . '/pettycash-evidence-*') ?: []);
        self::assertSame([], $this->store->survivingScratchFiles());
    }

    // ===== Omission, not a lying manifest =====

    public function testAnUnreadableFileIsOmittedFromTheArchiveAndTheManifest(): void
    {
        $result = $this->exporter->build([$this->entry()], static fn (): ?string => null);

        self::assertSame(0, $result->fileCount());
        self::assertSame(0, $result->manifestRowCount(), 'the manifest must not name a file that is absent');
        self::assertTrue($result->manifestMatchesArchive());
        self::assertCount(1, $result->omitted);
        self::assertSame(self::EVIDENCE, $result->omitted[0]['evidencePublicId']);
    }

    public function testAnOmissionCarriesAReason(): void
    {
        $result = $this->exporter->build([$this->entry()], static fn (): ?string => null);

        self::assertNotSame('', $result->omitted[0]['reason']);
    }

    public function testAReadableSiblingIsStillArchivedWhenAnotherFileIsMissing(): void
    {
        $present = $this->entry(self::EVIDENCE, 'present.pdf', 'storage/present.pdf');
        $missing = $this->entry(
            '55555555-5555-4555-8555-555555555555',
            'missing.pdf',
            'storage/missing.pdf'
        );

        $result = $this->exporter->build(
            [$present, $missing],
            static fn (string $key): ?string => $key === 'storage/present.pdf' ? 'bytes' : null
        );

        self::assertSame(1, $result->fileCount());
        self::assertSame(1, $result->manifestRowCount());
        self::assertTrue($result->manifestMatchesArchive());
        self::assertCount(1, $result->omitted);
    }

    public function testAFileOverTheSizeLimitIsRefusedRatherThanArchived(): void
    {
        $exporter = new EvidenceArchiveExporter($this->store, maxFileBytes: 10);
        $entry = $this->entry();
        // Claims 999999 bytes; the ceiling is 10.
        $result = $exporter->build([$entry], static fn (): string => 'far too many bytes');

        self::assertSame(0, $result->fileCount());
        self::assertCount(1, $result->omitted);
        self::assertSame('OVER_SIZE_LIMIT', $result->omitted[0]['reason']);
    }

    public function testTheDefaultSizeLimitIsTheHundredMegabyteCeilingFromFr074(): void
    {
        self::assertSame(104857600, EvidenceArchiveExporter::MAX_FILE_BYTES, 'FR-074 sets 100 MB per file');

        // Read from the result, which exposes it, rather than from the exporter's private property —
        // an earlier version of this test read a private field with `??` and therefore asserted nothing.
        $result = $this->exporter->build([], static fn (): ?string => null);

        self::assertSame(EvidenceArchiveExporter::MAX_FILE_BYTES, $result->maxFileBytes);
    }

    public function testAFileExactlyAtTheCeilingIsArchived(): void
    {
        $exporter = new EvidenceArchiveExporter($this->store, maxFileBytes: 5);
        $entry = new EvidenceArchiveEntry(
            self::EVIDENCE, 'exact.bin', 'application/octet-stream', 5, 'c', true,
            'u', 'n', new DateTimeImmutable(), self::TRANSACTION, 1, self::REVISION, self::SHEET, 't', 'k'
        );

        $result = $exporter->build([$entry], static fn (): string => '12345');

        // The boundary: the ceiling is inclusive, so exactly-at-limit is archived and one over is not.
        self::assertSame(1, $result->fileCount());
        self::assertSame([], $result->omitted);
    }

    public function testAFileOneByteOverTheCeilingIsRefused(): void
    {
        $exporter = new EvidenceArchiveExporter($this->store, maxFileBytes: 5);
        $entry = new EvidenceArchiveEntry(
            self::EVIDENCE, 'over.bin', 'application/octet-stream', 6, 'c', true,
            'u', 'n', new DateTimeImmutable(), self::TRANSACTION, 1, self::REVISION, self::SHEET, 't', 'k'
        );

        $result = $exporter->build([$entry], static fn (): string => '123456');

        self::assertSame(0, $result->fileCount());
        self::assertSame('OVER_SIZE_LIMIT', $result->omitted[0]['reason']);
    }

    // ===== Path safety =====

    /**
     * @dataProvider hostileFilenames
     */
    public function testAHostileFilenameCannotEscapeTheArchiveRoot(string $name): void
    {
        $entry = $this->entry(self::EVIDENCE, $name, 'storage/x.pdf');

        $result = $this->exporter->build([$entry], static fn (): string => 'bytes');

        $path = $result->memberPaths[0];
        self::assertStringNotContainsString('..', $path, "{$name} produced a traversing path");
        self::assertStringStartsWith('sheets/', $path);
        // Every member must remain a plain relative path.
        self::assertDoesNotMatchRegularExpression('#(^|/)(/|[a-zA-Z]:)#', $path);

        // No backslash anywhere in a member path. This is the assertion that makes the exporter's
        // explicit separator-stripping load-bearing rather than apparently redundant: `basename()` on
        // Linux already removes `/`, so removing that line alone changed nothing and the traversal
        // tests still passed. But `basename()` does **not** treat `\` as a separator, so a
        // backslash-bearing name reaches the member path intact — and on a Windows extractor `\` is a
        // separator, which is the platform where a traversing member name would actually be extracted.
        self::assertStringNotContainsString('\\', $path, "{$name} left a backslash in the member path");
    }

    /** @return list<array{string}> */
    public static function hostileFilenames(): array
    {
        return [
            'parent traversal' => ['../../../etc/passwd'],
            'embedded traversal' => ['sub/../../escape.pdf'],
            'windows separator' => ['..\\..\\windows\\system32\\config'],
            'absolute posix' => ['/etc/shadow'],
            'windows absolute' => ['C:\\Windows\\System32\\evil.dll'],
            'nul byte' => ["receipt.pdf\0.png"],
            'leading dot' => ['.htaccess'],
            'current dir' => ['.'],
            'parent only' => ['..'],
            'bare slash' => ['///'],
        ];
    }

    public function testANameThatReducesToNothingFallsBackToThePublicId(): void
    {
        $entry = $this->entry(self::EVIDENCE, '   ', 'storage/x.pdf');

        $result = $this->exporter->build([$entry], static fn (): string => 'bytes');

        self::assertStringContainsString(self::EVIDENCE, $result->memberPaths[0]);
    }

    public function testTheArchivePathRecordsTheRelationships(): void
    {
        $result = $this->buildOne();

        self::assertSame(
            sprintf('sheets/%s/transactions/%s/revisions/2/receipt.pdf', self::SHEET, self::TRANSACTION),
            $result->memberPaths[0]
        );
    }

    public function testTwoFilesWithTheSameNameInTheSameFolderBothSurvive(): void
    {
        $a = $this->entry(self::EVIDENCE, 'receipt.pdf', 'storage/a.pdf');
        $b = $this->entry('55555555-5555-4555-8555-555555555555', 'receipt.pdf', 'storage/b.pdf');

        $result = $this->exporter->build([$a, $b], static fn (): string => 'bytes');

        self::assertSame(2, $result->fileCount());
        self::assertCount(2, array_unique($result->memberPaths), 'member paths must be unique');
        self::assertTrue($result->manifestMatchesArchive());
    }

    public function testTheDisambiguatedPathIsStableAcrossRuns(): void
    {
        $a = $this->entry(self::EVIDENCE, 'receipt.pdf', 'storage/a.pdf');
        $b = $this->entry('55555555-5555-4555-8555-555555555555', 'receipt.pdf', 'storage/b.pdf');
        $reader = static fn (): string => 'bytes';

        $first = $this->exporter->build([$a, $b], $reader)->memberPaths;
        $second = $this->exporter->build([$a, $b], $reader)->memberPaths;

        // A group export is compared across runs, so the same input must produce the same paths. An
        // index or a timestamp would make every run differ.
        self::assertSame($first, $second);
    }

    // ===== Manifest content safety =====

    public function testTheManifestEscapesAUserSuppliedSheetTitleAsAFormula(): void
    {
        $entry = $this->entry(self::EVIDENCE, 'receipt.pdf', 'storage/x.pdf');
        $entry = new EvidenceArchiveEntry(
            $entry->evidencePublicId,
            $entry->originalName,
            $entry->mediaType,
            $entry->sizeBytes,
            $entry->checksum,
            $entry->active,
            $entry->uploaderUid,
            $entry->uploaderDisplayName,
            $entry->uploadedAt,
            $entry->transactionPublicId,
            $entry->revisionNumber,
            $entry->revisionPublicId,
            $entry->sheetPublicId,
            '=HYPERLINK("http://evil","click")',
            $entry->storageKey
        );

        $result = $this->exporter->build([$entry], static fn (): string => 'bytes');
        $manifest = (string) $this->zipRead($this->artifact($result->artifactName), EvidenceArchiveExporter::MANIFEST_NAME);

        // The manifest is a CSV, so it inherits the CSV exporter's formula escaping. A sheet title is
        // user-controlled and a recipient double-clicking the manifest would otherwise evaluate it.
        self::assertStringContainsString("'=HYPERLINK", $manifest);
    }

    public function testTheManifestPreservesPersianText(): void
    {
        $result = $this->buildOne();
        $manifest = (string) $this->zipRead($this->artifact($result->artifactName), EvidenceArchiveExporter::MANIFEST_NAME);

        self::assertStringContainsString('باربر', $manifest);
    }

    // ===== Entry validation =====

    public function testAnEntryWithoutAnEvidenceIdIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EvidenceArchiveEntry('', 'a.pdf', 'application/pdf', 1, 'c', true, 'u', 'n', new DateTimeImmutable(), self::TRANSACTION, 1, self::REVISION, self::SHEET, 't', 'k');
    }

    public function testAnEntryWithRevisionZeroIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EvidenceArchiveEntry(self::EVIDENCE, 'a.pdf', 'application/pdf', 1, 'c', true, 'u', 'n', new DateTimeImmutable(), self::TRANSACTION, 0, self::REVISION, self::SHEET, 't', 'k');
    }

    public function testAnEntryWithANegativeSizeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EvidenceArchiveEntry(self::EVIDENCE, 'a.pdf', 'application/pdf', -1, 'c', true, 'u', 'n', new DateTimeImmutable(), self::TRANSACTION, 1, self::REVISION, self::SHEET, 't', 'k');
    }

    // ===== No transient residue =====

    public function testTheScratchFileIsRemovedAfterTheBuild(): void
    {
        $this->exporter->build([$this->entry()], static fn (): string => 'bytes');

        // Asserted on what survived on disk rather than on a cleanup method having been called, because
        // a build that reported cleaning up and left the file is the failure mode.
        self::assertCount(1, $this->store->scratchPaths());
        self::assertSame([], $this->store->survivingScratchFiles());
    }

    public function testTheScratchFileIsRemovedEvenWhenTheBuildFails(): void
    {
        $exploding = function (): string {
            throw new RuntimeException('storage exploded');
        };

        try {
            $this->exporter->build([$this->entry()], $exploding);
            self::fail('the reader exception should have propagated');
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame([], $this->store->survivingScratchFiles());
    }

    public function testNothingIsPersistedWhenTheBuildFails(): void
    {
        $exploding = function (): string {
            throw new RuntimeException('storage exploded');
        };

        try {
            $this->exporter->build([$this->entry()], $exploding);
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame(0, $this->store->persistCount(), 'a failed build must not leave a half-written artifact');
    }

    // ===== Helpers =====

    private function buildOne(): \OCA\PettyCash\Infrastructure\Export\EvidenceArchiveResult
    {
        return $this->exporter->build([$this->entry()], static fn (): string => 'pdf-bytes');
    }

    private function entry(
        string $evidenceId = self::EVIDENCE,
        string $name = 'receipt.pdf',
        string $storageKey = 'storage/receipt.pdf'
    ): EvidenceArchiveEntry {
        return new EvidenceArchiveEntry(
            $evidenceId,
            $name,
            'application/pdf',
            999999,
            str_repeat('a', 64),
            true,
            'uploader-uid',
            'باربر',
            new DateTimeImmutable('2026-03-15T10:30:00+00:00'),
            self::TRANSACTION,
            2,
            self::REVISION,
            self::SHEET,
            'Sheikh Office June',
            $storageKey
        );
    }

    private function zipHas(string $zipBytes, string $member): bool
    {
        return $this->zipLocate($zipBytes, $member) !== false;
    }

    private function zipRead(string $zipBytes, string $member): ?string
    {
        $path = $this->writeTempZip($zipBytes);
        try {
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                return null;
            }
            $contents = $zip->getFromName($member);
            $zip->close();

            return $contents === false ? null : $contents;
        } finally {
            @unlink($path);
        }
    }

    private function zipLocate(string $zipBytes, string $member): int|false
    {
        $path = $this->writeTempZip($zipBytes);
        try {
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                return false;
            }
            $index = $zip->locateName($member);
            $zip->close();

            return $index;
        } finally {
            @unlink($path);
        }
    }

    private function writeTempZip(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pettycash-test-zip-');
        self::assertNotFalse($path);
        file_put_contents($path, $bytes);

        return $path;
    }
}
