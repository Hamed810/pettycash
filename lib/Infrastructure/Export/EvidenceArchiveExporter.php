<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Export;

use OCA\PettyCash\Application\Port\TransientExportStore;
use RuntimeException;
use ZipArchive;

/**
 * Grouped evidence ZIP export per `contracts/exports.md`.
 *
 * ## The three things this has to get right
 *
 * **Safe archive paths.** `originalName` comes from a user upload, so it can be `../../etc/passwd`, an
 * absolute path, or contain a NUL byte. A ZIP member path is interpreted by whatever extracts it, and
 * `ZipArchive::addFromString()` does not stop a traversing name from being written. Every path is
 * therefore built here from the public relationships, and the user-supplied name is reduced to a bare
 * basename before it is allowed anywhere near a path.
 *
 * **A manifest that lists only what is present.** The contract requires both that the manifest lists
 * only files actually in the archive and that the manifest-to-file mapping is complete. A requested
 * file whose bytes cannot be read is therefore omitted from **both**, and recorded in the result's
 * `omitted` list with a reason — never written as a manifest row pointing at a member that is not
 * there. A manifest that names a missing file is worse than no manifest, because it looks like evidence.
 *
 * **FR-074's 100 MB ceiling.** Enforced per file. A file over the limit is a refusal, not an omission,
 * and the distinction matters: an unreadable file is a storage condition, while an oversized one means
 * the upload validation did not hold, and silently dropping evidence from a financial export is the
 * failure mode FR-053 and FR-059 exist to prevent.
 *
 * ## What this deliberately does not do
 *
 * It does not implement the tabular `Exporter` port. That port models `iterable<array> => string`, and
 * a ZIP with binary members is not tabular — its rows are a manifest *derived from* the archive, not
 * the input to it. Forcing the shape would mean smuggling a reader callback through an `iterable`,
 * which is worse than a second, honest API. How the four exporters are composed is T111's decision.
 *
 * It also does not decide scope. "Evidence outside the requester's scope is omitted before the archive
 * is built" is an authorization rule, and it belongs to the layer above: this class is handed entries
 * that are already in scope and has no way to widen or narrow them.
 */
final class EvidenceArchiveExporter
{
    /** FR-074: 100 MB per file. */
    public const MAX_FILE_BYTES = 104857600;

    public const MANIFEST_NAME = 'manifest.csv';

    /**
     * Columns, in order, from the contract's "Group Evidence Manifest" required list.
     *
     * @var list<string>
     */
    public const MANIFEST_COLUMNS = [
        'sheetPublicId',
        'sheetTitle',
        'transactionPublicId',
        'revisionNumber',
        'revisionPublicId',
        'evidencePublicId',
        'originalName',
        'mediaType',
        'sizeBytes',
        'checksum',
        'active',
        'uploaderUid',
        'uploaderDisplayName',
        'uploadedAt',
        'archivePath',
    ];

    private const OVER_SIZE_LIMIT = 'OVER_SIZE_LIMIT';
    private const NOT_READABLE = 'NOT_READABLE';

    private readonly int $maxFileBytes;

    public function __construct(
        private readonly TransientExportStore $store,
        ?int $maxFileBytes = null
    ) {
        $this->maxFileBytes = $maxFileBytes ?? self::MAX_FILE_BYTES;
    }

    /**
     * Build the archive.
     *
     * @param list<EvidenceArchiveEntry> $entries already authorized, in scope
     * @param callable(string): ?string $reader returns the bytes for a storage key, or null when absent
     */
    public function build(array $entries, callable $reader): EvidenceArchiveResult
    {
        $artifactName = $this->artifactName();
        $scratchPath = $this->store->scratchPath($artifactName);

        try {
            $result = $this->buildInto($scratchPath, $entries, $reader);

            // The artifact is moved into app-private transient storage before the scratch is removed,
            // so the file that persists is never the file the archive was assembled in. The contract
            // requires private transient application storage; the scratch path exists only because
            // ZipArchive has no stream API.
            $this->store->persist($artifactName, $scratchPath);
            $bytes = $this->store->size($artifactName);

            return new EvidenceArchiveResult(
                $artifactName,
                $bytes ?? 0,
                $result->manifestRows,
                $result->omitted,
                $result->memberPaths,
                $this->maxFileBytes
            );
        } finally {
            // Removed whether or not the build succeeded. A group export can be large enough that
            // leaving a copy in the shared system temp directory matters, and scratch is scratch.
            if (is_file($scratchPath)) {
                @unlink($scratchPath);
            }
        }
    }

    /**
     * A unique artifact name, so two concurrent group exports cannot overwrite one another.
     *
     * A random suffix rather than a timestamp or a counter: two exports started in the same second must
     * not collide, and a predictable name in a shared storage location is a name another process could
     * pre-empt.
     */
    private function artifactName(): string
    {
        return 'evidence-' . bin2hex(random_bytes(8)) . '.zip';
    }

    /**
     * @param list<EvidenceArchiveEntry> $entries
     * @param callable(string): ?string $reader
     */
    private function buildInto(string $archivePath, array $entries, callable $reader): EvidenceArchiveResult
    {
        $zip = new ZipArchive();
        $opened = $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new RuntimeException("Could not open the evidence archive for writing (code {$opened})");
        }

        $manifestRows = [];
        $memberPaths = [];
        $omitted = [];
        $usedPaths = [];

        try {
            foreach ($entries as $entry) {
                if ($entry->sizeBytes > $this->maxFileBytes) {
                    // A refusal, not an omission: see the class docblock.
                    $omitted[] = [
                        'evidencePublicId' => $entry->evidencePublicId,
                        'reason' => self::OVER_SIZE_LIMIT,
                    ];
                    continue;
                }

                $bytes = $reader($entry->storageKey);
                if ($bytes === null) {
                    $omitted[] = [
                        'evidencePublicId' => $entry->evidencePublicId,
                        'reason' => self::NOT_READABLE,
                    ];
                    continue;
                }

                $memberPath = $this->memberPath($entry, $usedPaths);

                if (!$zip->addFromString($memberPath, $bytes)) {
                    throw new RuntimeException("Could not add {$memberPath} to the evidence archive");
                }

                $memberPaths[] = $memberPath;
                $manifestRows[] = $this->manifestRow($entry, $memberPath, strlen($bytes));
            }

            $zip->addFromString(self::MANIFEST_NAME, $this->renderManifest($manifestRows));
        } finally {
            $zip->close();
        }

        // The archive is not read back here. Its size is taken from the file on disk, which is what
        // `persist()` will move, and the bytes are read later by whoever streams it.
        $size = filesize($archivePath);

        return new EvidenceArchiveResult(
            '',
            $size === false ? 0 : $size,
            $manifestRows,
            $omitted,
            $memberPaths,
            $this->maxFileBytes
        );
    }

    /**
     * The archive-relative path for one entry, unique within the archive.
     *
     * Built from the public relationships so a member's location says what it belongs to, and so two
     * files with the same uploaded name cannot collide. Collisions are resolved by appending the
     * evidence public id, which is stable across runs — a timestamp or an index would make the same
     * export produce a different archive twice, and a group export is compared across runs.
     *
     * @param array<string, true> $usedPaths
     */
    private function memberPath(EvidenceArchiveEntry $entry, array &$usedPaths): string
    {
        $basename = $this->safeBasename($entry->originalName, $entry->evidencePublicId);

        $candidate = sprintf(
            'sheets/%s/transactions/%s/revisions/%d/%s',
            $this->safeSegment($entry->sheetPublicId),
            $this->safeSegment($entry->transactionPublicId),
            $entry->revisionNumber,
            $basename
        );

        if (!isset($usedPaths[$candidate])) {
            $usedPaths[$candidate] = true;

            return $candidate;
        }

        // Same name, same folder. Disambiguate with the public id rather than a counter.
        $extension = pathinfo($basename, PATHINFO_EXTENSION);
        $stem = $extension === '' ? $basename : substr($basename, 0, -(strlen($extension) + 1));

        $candidate = sprintf(
            'sheets/%s/transactions/%s/revisions/%d/%s-%s.%s',
            $this->safeSegment($entry->sheetPublicId),
            $this->safeSegment($entry->transactionPublicId),
            $entry->revisionNumber,
            $stem,
            $this->safeSegment($entry->evidencePublicId),
            $extension === '' ? 'bin' : $extension
        );

        // A third file with the same name in the same folder is not a case worth disambiguating
        // gracefully; it means the public ids are not what they claim to be.
        if (isset($usedPaths[$candidate])) {
            throw new RuntimeException("Could not find a unique archive path for {$entry->evidencePublicId}");
        }
        $usedPaths[$candidate] = true;

        return $candidate;
    }

    /**
     * Reduce a user-supplied filename to a single safe path segment.
     *
     * Everything that could change the meaning of the path is removed rather than encoded: directory
     * separators, drive letters, leading dots, control characters, and the characters Windows refuses
     * in a filename. A name that reduces to nothing falls back to the evidence public id, so an entry
     * can never end up with an empty filename.
     */
    private function safeBasename(string $originalName, string $evidencePublicId): string
    {
        // Normalise and cut at the first NUL, so a NUL cannot truncate the name into something that
        // looks safe while the bytes after it are not.
        $name = str_replace("\0", '', $originalName);
        $name = str_replace(['\\', '/'], ' ', $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = preg_replace('/[<>:"|?*]/u', '', $name) ?? '';
        $name = trim($name, " .");

        // Collapse any remaining run of two or more dots.
        //
        // Replacing the separators above already makes traversal impossible — the name is a single
        // segment, so a `..` can only ever be a *substring* of it, never a path component. Removing it
        // anyway is worth the line: an extractor that checks member names for `..` by substring would
        // otherwise reject or mishandle a legitimately named file, and a name containing `..` is a
        // standing reminder that the input was hostile.
        $name = preg_replace('/\.{2,}/', '_', $name) ?? '';

        // A name left with nothing but separators and filler is not a usable filename.
        if (trim($name, '_ .') === '') {
            return $this->safeSegment($evidencePublicId) . '.bin';
        }

        // Guard against a single path segment long enough to upset an extractor, without truncating
        // the extension so the file stays recognisable.
        if (strlen($name) > 120) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $stem = substr($name, 0, 120);
            $name = $extension === '' ? $stem : $stem . '.' . $extension;
        }

        return $name;
    }

    /**
     * Make a public id safe to use as one path segment.
     *
     * Public ids are generated UUIDs, so this is a second line of defence rather than the primary one.
     * It exists because a segment that is trusted to be clean is how a traversal bug becomes reachable
     * the day an id comes from somewhere else.
     */
    private function safeSegment(string $segment): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/u', '_', $segment) ?? '';
        $clean = trim($clean, '.');

        return $clean === '' ? 'unknown' : $clean;
    }

    /**
     * @return array<string, string>
     */
    private function manifestRow(EvidenceArchiveEntry $entry, string $memberPath, int $actualBytes): array
    {
        return [
            'sheetPublicId' => $entry->sheetPublicId,
            'sheetTitle' => $entry->sheetTitle,
            'transactionPublicId' => $entry->transactionPublicId,
            'revisionNumber' => (string) $entry->revisionNumber,
            'revisionPublicId' => $entry->revisionPublicId,
            'evidencePublicId' => $entry->evidencePublicId,
            'originalName' => $entry->originalName,
            'mediaType' => $entry->mediaType,
            // The size actually written, not the size the caller claimed. A manifest that reports a
            // size the archive does not contain is a manifest nobody can reconcile against the ZIP.
            'sizeBytes' => (string) $actualBytes,
            'checksum' => $entry->checksum,
            'active' => $entry->active ? 'true' : 'false',
            'uploaderUid' => $entry->uploaderUid,
            'uploaderDisplayName' => $entry->uploaderDisplayName,
            'uploadedAt' => $entry->uploadedAt->format(DATE_ATOM),
            'archivePath' => $memberPath,
        ];
    }

    /**
     * @param list<array<string, string>> $rows
     */
    private function renderManifest(array $rows): string
    {
        // Reuses the CSV exporter rather than hand-rolling a second CSV writer. The manifest is a CSV
        // with the same formula-escaping obligation — sheet titles and uploader display names are
        // user-controlled — and a second writer would be a second place to get that wrong.
        //
        // The header is passed explicitly so an archive with no readable evidence still carries its
        // manifest header. Deriving the columns from the rows would make an empty archive manifestless,
        // and a recipient could not then tell "no evidence was in scope" from "the export is broken".
        return (new CsvExporter())->export($rows, self::MANIFEST_COLUMNS);
    }
}
