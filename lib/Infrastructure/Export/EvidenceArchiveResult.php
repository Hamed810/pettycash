<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Export;

/**
 * What a grouped evidence archive actually contains.
 *
 * The contract states two rules that pull in opposite directions and cannot both be checked from the
 * ZIP bytes alone: "the manifest lists only files actually present in the archive", and "the
 * manifest-to-file mapping is complete". A consumer of a group export needs to know which evidence was
 * requested, which made it into the archive, and — when those differ — **why**. Returning the archive
 * bytes alone would force that question to be answered by trusting the manifest, which is precisely the
 * thing the contract forbids.
 *
 * So the omissions travel with the result. An omission is a file the caller asked for that is not in
 * the archive, each with a reason. `OmittedReason::NOT_READABLE` and `OmittedReason::OVER_SIZE_LIMIT`
 * are the two the build can produce.
 */
final readonly class EvidenceArchiveResult
{
    /**
     * @param string $zipBytes the complete archive
     * @param list<array<string, string>> $manifestRows one row per file actually in the archive, in archive order
     * @param list<array{evidencePublicId: string, reason: string}> $omitted requested files that are not in the archive
     * @param list<string> $memberPaths archive-relative path of every file member, in archive order
     */
    public function __construct(
        public string $zipBytes,
        public array $manifestRows,
        public array $omitted,
        public array $memberPaths,
        public int $maxFileBytes
    ) {}

    public function fileCount(): int
    {
        return count($this->memberPaths);
    }

    public function manifestRowCount(): int
    {
        return count($this->manifestRows);
    }

    /**
     * Whether every file in the archive has a manifest row and every manifest row has a file.
     *
     * The contract's "the manifest lists only files actually present in the archive" and its acceptance
     * check "the manifest-to-file mapping is complete" are the same invariant read from both sides, and
     * this is where a caller asserts it rather than trusting that it holds.
     */
    public function manifestMatchesArchive(): bool
    {
        if ($this->manifestRowCount() !== $this->fileCount()) {
            return false;
        }

        $manifestPaths = [];
        foreach ($this->manifestRows as $row) {
            $manifestPaths[] = $row['archivePath'] ?? '';
        }
        sort($manifestPaths);
        $members = $this->memberPaths;
        sort($members);

        return $manifestPaths === $members;
    }

    public function isEmpty(): bool
    {
        return $this->fileCount() === 0;
    }
}
