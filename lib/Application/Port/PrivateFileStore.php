<?php
declare(strict_types=1);
namespace OCA\PettyCash\Application\Port;
interface PrivateFileStore
{
    public function put(string $key, string $content): void;
    public function read(string $key): string;
    public function deleteDraft(string $key): void;
    public function exists(string $key): bool;

    // Evidence-specific methods
    public function putEvidence(string $transactionUuid, int $revisionNumber, string $originalFilename, string $content): string;
    public function readEvidence(string $key): string;
    public function deleteDraftEvidence(string $key): void;
    public function getFileSize(string $key): int;
    /**
     * SHA-256 of the given bytes.
     *
     * A checksum is a pure function of the content, so it must be computable from
     * the bytes in hand. Reading a just-written file back through the storage layer
     * is unreliable inside the workflow's open database transaction, because the
     * filecache row is not yet visible to the connection the read runs on.
     */
    public function checksumOfContent(string $content): string;
}