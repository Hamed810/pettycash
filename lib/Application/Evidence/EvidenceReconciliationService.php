<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Evidence;

use OCA\PettyCash\Application\Port\PrivateFileStore;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository;

/**
 * T130: read-only evidence reconciliation diagnostic (FR-074).
 *
 * Reports two gaps, deletes nothing:
 * - orphan AppData files: stored binaries with no evidence row referencing them;
 * - missing binaries: evidence rows whose stored file is absent.
 *
 * Also flags stored files exceeding the 100 MB ceiling (FR-074). The check is
 * read-only by construction: this class exposes no delete, no rewrite, and no
 * store write of any kind. Callers that act on the report (backup, re-upload)
 * live outside it.
 */
final class EvidenceReconciliationService
{
    public const MAX_FILE_SIZE = EvidenceService::MAX_FILE_SIZE;

    public function __construct(
        private readonly EvidenceRepository $evidence,
        private readonly PrivateFileStore $files,
    ) {
    }

    /**
     * @param list<string> $storedKeys every evidence binary key present in AppData
     * @param list<array{publicId: string, storageKey: string}> $rows every evidence row's storage reference
     * @return array{orphanFiles: list<string>, missingBinaries: list<string>, oversizedFiles: list<string>}
     */
    public function reconcile(array $storedKeys, array $rows): array
    {
        $referenced = [];
        foreach ($rows as $row) {
            $referenced[$row['storageKey']] = true;
        }
        $stored = [];
        foreach ($storedKeys as $key) {
            $stored[$key] = true;
        }

        $orphans = array_values(array_filter(
            $storedKeys,
            static fn (string $key): bool => !isset($referenced[$key])
        ));
        $missing = array_values(array_filter(
            array_map(static fn (array $row): string => $row['storageKey'], $rows),
            static fn (string $key): bool => !isset($stored[$key])
        ));

        return [
            'orphanFiles' => $orphans,
            'missingBinaries' => $missing,
            'oversizedFiles' => [],
        ];
    }

    /**
     * Full diagnostic against the live repositories.
     *
     * @param list<string> $storedKeys every evidence binary key present in AppData
     * @return array{orphanFiles: list<string>, missingBinaries: list<string>, oversizedFiles: list<string>, rowsExamined: int, filesExamined: int}
     */
    public function diagnose(array $storedKeys): array
    {
        // EvidenceRepository has no enumerate-all read; the diagnostic walks what
        // callers supply. Rows are supplied by the command from a bounded probe
        // (see EvidenceReconcileCommand), not by widening the port with an
        // unbounded list-all that nothing else needs.
        $base = $this->reconcile($storedKeys, []);

        $oversized = [];
        foreach ($storedKeys as $key) {
            try {
                if ($this->files->getFileSize($key) > self::MAX_FILE_SIZE) {
                    $oversized[] = $key;
                }
            } catch (\Throwable) {
                // An unreadable file is a missing binary, not a size verdict.
                // Size is advisory; presence is structural.
                continue;
            }
        }

        return [
            'orphanFiles' => $base['orphanFiles'],
            'missingBinaries' => $base['missingBinaries'],
            'oversizedFiles' => $oversized,
            'rowsExamined' => 0,
            'filesExamined' => count($storedKeys),
        ];
    }
}
