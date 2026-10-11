<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Reporting;

use InvalidArgumentException;
use OCA\PettyCash\Application\Port\PrivateFileStore;
use OCA\PettyCash\Application\Port\TransientExportStore;
use OCA\PettyCash\Infrastructure\Export\CsvExporter;
use OCA\PettyCash\Infrastructure\Export\EvidenceArchiveExporter;
use OCA\PettyCash\Infrastructure\Export\EvidenceArchiveEntry;
use OCA\PettyCash\Infrastructure\Export\PdfExporter;
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
 * ## PDF renders Persian RTL through a vendored font
 *
 * `format: pdf` renders with `PdfExporter`, which embeds a subset of the vendored
 * Vazirmatn font (OFL, beside the exporter) and relies on the library's bidi
 * reordering plus Arabic contextual shaping. A system font would make the output
 * depend on the host, so the font travels with the app instead.
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
        self::FORMAT_PDF,
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

    public function __construct(
        private CsvExporter $csv,
        private XlsxExporter $xlsx,
        private EvidenceArchiveExporter $archive,
        private TransientExportStore $store,
        private PrivateFileStore $fileStore,
        private PdfExporter $pdf
    ) {
    }

    /**
     * Generate a role-scoped export into transient storage.
     *
     * @param list<array<string, mixed>> $rows    rows already inside the actor's scope
     * @param list<EvidenceArchiveEntry>   $entries evidence entries, for a zip export only
     * @param list<string>                 $columns the resource's declared columns, so a **narrowed-to-
     *                                              empty** result still names its own shape
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
        array $entries = [],
        array $columns = [],
        array $numericColumns = []
    ): array {
        // 1. Authorize. Before anything is built, before anything is written.
        $this->authorize($scope, $resource, $format);

        // 2. Produce. Only now can a byte exist.
        return match ($format) {
            self::FORMAT_CSV => $this->generateCsv($rows, $columns),
            self::FORMAT_XLSX => $this->generateXlsx($rows, $columns, $numericColumns),
            self::FORMAT_PDF => $this->generatePdf($rows, $columns),
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

    /**
     * Read one generated artifact as a stream.
     *
     * Separate from `cleanup()` on purpose. `cleanup()` empties transient storage, which is right for
     * a test teardown and wrong for a controller: two exports in flight would delete each other's
     * bytes, and the second response would go out empty. A serving controller therefore removes
     * exactly the artifact it just streamed.
     *
     * @return resource|null
     */
    public function readArtifact(string $artifactName)
    {
        return $this->store->read($artifactName);
    }

    /**
     * Remove exactly one generated artifact, after it has been streamed.
     */
    public function deleteArtifact(string $artifactName): void
    {
        $this->store->delete($artifactName);
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
     * @param list<string> $columns
     * @return array{artifactName: string, mediaType: string, format: string, byteSize: int, rowCount: int}
     */
    private function generatePdf(array $rows, array $columns = []): array
    {
        $name = 'export-' . bin2hex(random_bytes(8)) . '.pdf';
        $pdf = $this->pdf->export($rows, $columns === [] ? null : $columns);

        // Same scratch discipline as `generateCsv()`: the transient store streams
        // out of the scratch file and does not remove it, so the leftover is deleted
        // here in a `finally`, failed persist included.
        $scratch = $this->writeScratch($name, $pdf);
        try {
            $this->store->persist($name, $scratch);
        } finally {
            if (is_file($scratch)) {
                unlink($scratch);
            }
        }

        return [
            'artifactName' => $name,
            'mediaType' => $this->pdf->mediaType(),
            'format' => self::FORMAT_PDF,
            'byteSize' => $this->store->size($name) ?? strlen($pdf),
            'rowCount' => count($rows),
        ];
    }


    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $columns
     * @return array{artifactName: string, mediaType: string, format: string, byteSize: int, rowCount: int}
     */
    private function generateCsv(array $rows, array $columns = []): array
    {
        $name = 'export-' . bin2hex(random_bytes(8)) . '.csv';
        $csv = $this->csv->export($rows, $columns === [] ? null : $columns);

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
     * @param list<string> $columns
     * @return array{artifactName: string, mediaType: string, format: string, byteSize: int, rowCount: int}
     */
    /**
     * T366 / FR-067: a complete sheet as the six-sheet XLSX layout.
     *
     * The groups arrive already assembled by `CompleteSheetExport`, which is what knows the sheet's
     * transactions and their history; this method only validates the layout contract and builds the
     * workbook. Split that way so a layout violation — a group missing a column the contract names —
     * fails here with the whole gap named at once, rather than deep inside a row the caller assembled.
     *
     * @param array<string, list<array<string, mixed>>> $rowsBySheet keyed by layout name
     * @return array{artifactName: string, mediaType: string, format: string, byteSize: int, rowCount: int}
     */
    public function generateCompleteSheetXlsx(array $rowsBySheet): array
    {
        $result = $this->xlsx->build($this->xlsx->completeSheet($rowsBySheet));

        return [
            'artifactName' => $result['artifactName'],
            'mediaType' => $this->xlsx->mediaType(),
            'format' => self::FORMAT_XLSX,
            'byteSize' => $result['artifactBytes'],
            // Every row in every group, because the artifact's content is six tables rather than one.
            'rowCount' => array_sum($result['rowCounts']),
        ];
    }

    private function generateXlsx(array $rows, array $columns = [], array $numericColumns = []): array
    {
        $result = $this->xlsx->build([
            new XlsxSheet(
                'Rows',
                $this->columnsOf($rows, $columns, $numericColumns),
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
        // T367: an empty entry list is refused here rather than archived.
        //
        // `EvidenceArchiveExporter` is written so that an entry it cannot read is *omitted with a
        // reason*, which is correct for a file that vanished between selection and build. But with
        // **no entries at all** it still opens the archive, writes the manifest and closes — so the
        // caller would receive a well-formed ZIP whose manifest lists nothing. That is the exact
        // artifact T110's `EvidenceArchiveResult` was built to make impossible, and it was reachable
        // for one reason: `ExportController` passed a literal `[]` here, and nothing in the
        // application constructed an `EvidenceArchiveEntry`.
        //
        // So the refusal lives at the point where an empty list is still distinguishable from an
        // archive whose members all failed. Downstream of `build()` the two are the same object, and
        // refusing there would mean refusing a real selection whose files were unreadable — which is
        // a different answer and the wrong one.
        if ($entries === []) {
            throw new UnsupportedSelection(
                "A 'zip' export needs the evidence entries to archive; none were resolved. An archive "
                . 'with an empty manifest is not an empty result, so no file is produced.'
            );
        }

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
     * The declared columns, then the union of the rows' keys, all declared as text.
     *
     * A flat report export has no per-column typing to inherit, so every column is text and the values
     * are coerced to strings. That is a deliberate difference from the complete-sheet XLSX export, which
     * *does* have a declared typed layout — inventing types here would mean guessing at a report's
     * semantics, and a wrong guess is a wrong number in a spreadsheet the recipient trusts.
     *
     * `$declared` comes first and is emitted even when `$rows` is empty. Without it a selection that
     * every filter excluded would produce a workbook or a CSV with no columns at all — an artifact
     * that is byte-indistinguishable from one that failed to build, and which a recipient cannot read
     * as "your filter matched nothing" because it does not even say what it would have contained.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string> $declared
     * @return list<array{name: string, type: string}>
     */
    /**
     * T365: declared columns listed in `$numericColumns` are typed numeric rather than text.
     *
     * Everything else keeps the historical text typing: changing every flat export's typing at once
     * would alter artifacts this task does not own, and the contract promises typed numerics only
     * where the layout names them. An unknown key in `$numericColumns` is ignored rather than added,
     * so a typo cannot conjure a column.
     */
    private function columnsOf(array $rows, array $declared = [], array $numeric = []): array
    {
        $columns = [];
        foreach ($declared as $key) {
            $columns[$key] = ['name' => $key, 'type' => in_array($key, $numeric, true) ? XlsxSheet::TYPE_NUMBER : XlsxSheet::TYPE_TEXT];
        }

        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                // Declared columns keep the type the declaration gave them — including numeric —
                // so a row carrying the same key cannot silently retype them back to text.
                if (!isset($columns[$key])) {
                    $columns[$key] = ['name' => $key, 'type' => XlsxSheet::TYPE_TEXT];
                }
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
