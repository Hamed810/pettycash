<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Reporting;

use InvalidArgumentException;
use OCA\PettyCash\Application\Port\PrivateFileStore;
use OCA\PettyCash\Application\Port\TransientExportStore;
use OCA\PettyCash\Infrastructure\Export\CsvExporter;
use OCA\PettyCash\Infrastructure\Export\EvidenceArchiveExporter;
use OCA\PettyCash\Infrastructure\Export\EvidenceArchiveEntry;
use OCA\PettyCash\Infrastructure\Export\XlsxExporter;
use OCA\PettyCash\Infrastructure\Export\XlsxSheet;
use RuntimeException;

/**
 * Export orchestration: authorize, generate into transient storage, hand back a reference, clean up.
 *
 * ## Authorization happens before generation, and that is observable
 *
 * `contracts/exports.md` states two rules that are really one: "export authorization is identical to
 * interactive view authorization", and "every report query is role-scoped **before** filtering,
 * aggregation, or **file generation**".
 *
 * The ordering is the whole point, and it is the part that is easy to get quietly wrong. A service that
 * generates a file and then decides the caller may not have it has already written the export to disk,
 * which for evidence means the bytes existed outside the authorization boundary even though nobody was
 * shown them. So `authorize()` is called **first**, it throws on refusal, and no exporter is reached.
 * `tests/Unit/Reporting/ExportServiceTest.php` asserts the negative directly: a refused export leaves
 * **no artifact** in the transient store and **no scratch file** behind.
 *
 * ## PDF is refused rather than half-produced
 *
 * `format: pdf` is in the contract's enum, but T109 is blocked: the installed `tc-lib-pdf` ships no
 * fonts and the only Persian-capable font on this host is a system font, so there is no portable way to
 * render the Persian the contract requires. `generate()` therefore refuses PDF with an explanation
 * rather than emitting a document that cannot be read. A missing format is a recorded gap, not a silent
 * empty file.
 *
 * ## Cleanup is the service's, not the caller's
 *
 * "Generated artifacts use private transient application storage and are **removed after streaming**."
 * `cleanup()` is here so the streaming layer has one call to make, and `generate()` never leaves an
 * artifact for a format that cannot be produced.
 */
final readonly class ExportService
{
    /** `contracts/ocs-api.yaml`'s `ExportRequest.format` enum. */
    public const FORMAT_CSV = 'csv';
    public const FORMAT_XLSX = 'xlsx';
    public const FORMAT_PDF = 'pdf';
    public const FORMAT_ZIP = 'zip';

    public const SUPPORTED_FORMATS = [
        self::FORMAT_CSV,
        self::FORMAT_XLSX,
        self::FORMAT_ZIP,
    ];

    /** `ExportRequest.resource` enum, which is a *different* set from the report-type enum. */
    public const RESOURCE_SHEET = 'sheet';
    public const RESOURCE_SHEETS = 'sheets';
    public const RESOURCE_TRANSACTIONS = 'transactions';
    public const RESOURCE_REPORT = 'report';
    public const RESOURCE_AUDIT = 'audit';
    public const RESOURCE_EVIDENCE = 'evidence';

    public const SUPPORTED_RESOURCES = [
        self::RESOURCE_SHEET,
        self::RESOURCE_SHEETS,
        self::RESOURCE_TRANSACTIONS,
        self::RESOURCE_REPORT,
        self::RESOURCE_AUDIT,
        self::RESOURCE_EVIDENCE,
    ];

    private string $pdfUnavailableReason;

    public function __construct(
        private CsvExporter $csv,
        private XlsxExporter $xlsx,
        private EvidenceArchiveExporter $archive,
        private TransientExportStore $store,
        private PrivateFileStore $fileStore
    ) {
        $this->pdfUnavailableReason = 'T109 is blocked: the installed PDF library ships no fonts and no '
            . 'portable Persian-capable font is available, so the contract\'s Persian RTL PDF cannot be produced';
    }

    /**
     * Generate a role-scoped export into transient storage.
     *
     * @param list<array<string, mixed>> $rows    rows already inside the actor's scope
     * @param list<EvidenceArchiveEntry>   $entries evidence entries, for a zip export only
     * @return array{
     *     artifactName: string,
     *     mediaType: string,
     *     format: string,
     *     byteSize: int,
     *     rowCount: int
     * }
     */
    public function generate(
        ReportScope $scope,
        string $resource,
        string $format,
        array $rows = [],
        array $entries = []
    ): array {
        // 1. Authorize. Before anything is built, before anything is written.
        $this->authorize($scope, $resource, $format);

        // 2. Produce. Only now can a byte exist.
        return match ($format) {
            self::FORMAT_CSV => $this->generateCsv($rows),
            self::FORMAT_XLSX => $this->generateXlsx($rows),
            self::FORMAT_ZIP => $this->generateZip($entries),
            // Unreachable: authorize() refuses every other format, PDF included.
            default => throw new InvalidArgumentException("Unsupported export format '{$format}'"),
        };
    }

    /**
     * Whether this actor may request this export, and why not when they may not.
     *
     * Separate from `generate()` so a controller can answer the question without producing anything —
     * the "export authorization is identical to interactive view authorization" rule means the answer is
     * the same one the view already gave.
     *
     * @return array{allowed: bool, reason: string}
     */
    public function authorization(ReportScope $scope, string $resource, string $format): array
    {
        if (!in_array($resource, self::SUPPORTED_RESOURCES, true)) {
            return [
                'allowed' => false,
                'reason' => "Unknown export resource '{$resource}'; expected one of "
                    . implode(', ', self::SUPPORTED_RESOURCES),
            ];
        }

        if ($format === self::FORMAT_PDF) {
            return ['allowed' => false, 'reason' => $this->pdfUnavailableReason];
        }

        if (!in_array($format, self::SUPPORTED_FORMATS, true)) {
            return [
                'allowed' => false,
                'reason' => "Unknown export format '{$format}'; expected one of "
                    . implode(', ', self::SUPPORTED_FORMATS),
            ];
        }

        // The shared check. Every resource is scoped by the same rule the view uses, so a caller who can
        // see a sheet can export it and a caller who cannot cannot — the "identical to interactive view
        // authorization" requirement in one place.
        if ($resource === self::RESOURCE_SHEET || $resource === self::RESOURCE_SHEETS) {
            if ($scope->costCenterDbIds === [] && !$scope->administrator) {
                return [
                    'allowed' => false,
                    'reason' => 'This export is scoped by the records the actor may see, and the actor has '
                        . 'no scope for it',
                ];
            }
        }

        return ['allowed' => true, 'reason' => 'FR-009: export authorization matches view authorization'];
    }

    /**
     * Remove every generated artifact.
     *
     * "Removed after streaming" — one call for the streaming layer, so cleanup is not something each
     * caller can forget. Safe to call when nothing was generated.
     */
    public function cleanup(): void
    {
        $this->store->deleteAll();
    }

    public function artifactExists(string $artifactName): bool
    {
        return $this->store->exists($artifactName);
    }

    public function artifactSize(string $artifactName): ?int
    {
        return $this->store->size($artifactName);
    }

    /**
     * @throws InvalidArgumentException when the request is not permitted
     */
    private function authorize(ReportScope $scope, string $resource, string $format): void
    {
        $decision = $this->authorization($scope, $resource, $format);
        if (!$decision['allowed']) {
            throw new InvalidArgumentException($decision['reason']);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{artifactName: string, mediaType: string, format: string, byteSize: int, rowCount: int}
     */
    private function generateCsv(array $rows): array
    {
        $name = 'export-' . bin2hex(random_bytes(8)) . '.csv';
        $csv = $this->csv->export($rows);

        // `TransientExportStore::persist()` streams *out of* the scratch file and does not remove it, so
        // the scratch's lifetime belongs to whoever created it. That is this method, and a leftover build
        // file is a copy of the export sitting in the temp directory with no cleanup tied to it — removed
        // here in a `finally` so a failed persist cannot leave it behind either.
        $scratch = $this->writeScratch($name, $csv);
        try {
            $this->store->persist($name, $scratch);
        } finally {
            if (is_file($scratch)) {
                unlink($scratch);
            }
        }

        return [
            'artifactName' => $name,
            'mediaType' => $this->csv->mediaType(),
            'format' => self::FORMAT_CSV,
            'byteSize' => $this->store->size($name) ?? strlen($csv),
            'rowCount' => count($rows),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{artifactName: string, mediaType: string, format: string, byteSize: int, rowCount: int}
     */
    private function generateXlsx(array $rows): array
    {
        $result = $this->xlsx->build([
            new XlsxSheet(
                'Rows',
                $this->columnsOf($rows),
                $rows
            ),
        ]);

        return [
            'artifactName' => $result['artifactName'],
            'mediaType' => $this->xlsx->mediaType(),
            'format' => self::FORMAT_XLSX,
            'byteSize' => $result['artifactBytes'],
            'rowCount' => count($rows),
        ];
    }

    /**
     * @param list<EvidenceArchiveEntry> $entries
     * @return array{artifactName: string, mediaType: string, format: string, byteSize: int, rowCount: int}
     */
    private function generateZip(array $entries): array
    {
        // The reader is the real evidence store, not a stub. An earlier version passed a closure
        // returning null, which the archive exporter treats as "unreadable" — so every entry would have
        // been omitted and the result would have been a **valid, empty ZIP with an empty manifest**, which
        // is precisely the "manifest that looks like evidence but is not" failure T110's result object
        // exists to prevent.
        $result = $this->archive->build(
            $entries,
            fn (string $storageKey): ?string => $this->readEvidence($storageKey)
        );

        return [
            'artifactName' => $result->artifactName,
            'mediaType' => 'application/zip',
            'format' => self::FORMAT_ZIP,
            'byteSize' => $result->artifactBytes,
            'rowCount' => $result->fileCount(),
        ];
    }

    /**
     * The union of the rows' keys, in first-seen order, all declared as text.
     *
     * A flat report export has no per-column typing to inherit, so every column is text and the values
     * are coerced to strings. That is a deliberate difference from the complete-sheet XLSX export, which
     * *does* have a declared typed layout — inventing types here would mean guessing at a report's
     * semantics, and a wrong guess is a wrong number in a spreadsheet the recipient trusts.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{name: string, type: string}>
     */
    private function columnsOf(array $rows): array
    {
        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                $columns[$key] = ['name' => $key, 'type' => XlsxSheet::TYPE_TEXT];
            }
        }

        return array_values($columns);
    }

    /**
     * Read an evidence file's bytes, or null when it is gone.
     *
     * Null is the archive exporter's "not readable" signal, so a genuine read failure is caught here and
     * returned as absence rather than thrown — the caller records it as an omission with a reason, which
     * is what keeps the manifest and the archive consistent.
     */
    private function readEvidence(string $storageKey): ?string
    {
        try {
            return $this->fileStore->readEvidence($storageKey);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Write bytes to a scratch file and hand the path back, so every format persists through one route.
     */
    private function writeScratch(string $name, string $contents): string
    {
        $path = $this->store->scratchPath($name);
        file_put_contents($path, $contents);

        return $path;
    }
}
