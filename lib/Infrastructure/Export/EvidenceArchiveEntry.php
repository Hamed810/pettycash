<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Export;

use InvalidArgumentException;

/**
 * One evidence file and the relationships the group-export manifest must record for it.
 *
 * Every field here is a required manifest column in `contracts/exports.md` ("Group Evidence Manifest"),
 * plus `storageKey`, which is not in the manifest at all and exists only so the exporter can ask the
 * caller for the bytes. It is deliberately **not** exported: a storage path is an internal detail and
 * publishing it would leak the AppData layout that ADR-001 and the FR-060 secrecy requirements exist to
 * keep out of the hands of a recipient.
 *
 * The relationships travel with the file rather than being looked up during the build. That is what
 * makes the manifest trustworthy: the row is written from the same values the archive path was derived
 * from, so a manifest cannot describe one transaction while the archive member belongs to another.
 */
final readonly class EvidenceArchiveEntry
{
    public function __construct(
        public string $evidencePublicId,
        public string $originalName,
        public string $mediaType,
        public int $sizeBytes,
        public string $checksum,
        public bool $active,
        public string $uploaderUid,
        public string $uploaderDisplayName,
        public \DateTimeInterface $uploadedAt,
        public string $transactionPublicId,
        public int $revisionNumber,
        public string $revisionPublicId,
        public string $sheetPublicId,
        public string $sheetTitle,
        /** Internal AppData key. Used to read the bytes; never written to the manifest. */
        public string $storageKey
    ) {
        if ($this->evidencePublicId === '') {
            throw new InvalidArgumentException('An archive entry needs an evidence public id');
        }
        if ($this->sizeBytes < 0) {
            throw new InvalidArgumentException('An archive entry cannot have a negative size');
        }
        if ($this->revisionNumber < 1) {
            throw new InvalidArgumentException('An archive entry needs a revision number of at least 1');
        }
    }
}
