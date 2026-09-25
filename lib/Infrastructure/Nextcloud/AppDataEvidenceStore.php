<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Nextcloud;

use OCA\PettyCash\Application\Port\PrivateFileStore;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use RuntimeException;

final readonly class AppDataEvidenceStore implements PrivateFileStore
{
    private const EVIDENCE_FOLDER = 'evidence';
    private const MAX_FILE_SIZE = 104857600; // 100 MB in bytes

    public function __construct(private IAppData $appData) {}

    private function getEvidenceFolder(): ISimpleFolder
    {
        try {
            return $this->appData->getFolder(self::EVIDENCE_FOLDER);
        } catch (NotFoundException) {
            return $this->appData->newFolder(self::EVIDENCE_FOLDER);
        }
    }

    private function getTransactionFolder(string $transactionUuid): ISimpleFolder
    {
        $evidenceFolder = $this->getEvidenceFolder();
        try {
            return $evidenceFolder->getFolder($transactionUuid);
        } catch (NotFoundException) {
            return $evidenceFolder->newFolder($transactionUuid);
        }
    }

    private function getRevisionFolder(string $transactionUuid, int $revisionNumber): ISimpleFolder
    {
        $transactionFolder = $this->getTransactionFolder($transactionUuid);
        $folderName = (string)$revisionNumber;
        try {
            return $transactionFolder->getFolder($folderName);
        } catch (NotFoundException) {
            return $transactionFolder->newFolder($folderName);
        }
    }

    #[\Override]
    public function put(string $key, string $content): void
    {
        if (strlen($content) > self::MAX_FILE_SIZE) {
            throw new RuntimeException('File size exceeds 100 MB limit');
        }
        $folder = $this->getEvidenceFolder();
        $file = $folder->newFile($key);
        $file->putContent($content);
    }

    #[\Override]
    public function read(string $key): string
    {
        $folder = $this->getEvidenceFolder();
        try {
            $file = $folder->getFile($key);
        } catch (NotFoundException) {
            throw new RuntimeException("AppData file not found: {$key}");
        }

        return $file->getContent();
    }

    #[\Override]
    public function deleteDraft(string $key): void
    {
        $folder = $this->getEvidenceFolder();
        try {
            $file = $folder->getFile($key);
            $file->delete();
        } catch (NotFoundException) {
            // Draft already deleted or never existed - idempotent
        }
    }

    #[\Override]
    public function exists(string $key): bool
    {
        $folder = $this->getEvidenceFolder();
        try {
            $folder->getFile($key);
            return true;
        } catch (NotFoundException) {
            return false;
        }
    }

    /**
     * Store evidence file with opaque key structure: evidence/<transaction-uuid>/<revision>/<filename>
     *
     * @param string $transactionUuid
     * @param int $revisionNumber
     * @param string $originalFilename
     * @param string $content
     * @return string The stored key
     */
    public function putEvidence(string $transactionUuid, int $revisionNumber, string $originalFilename, string $content): string
    {
        if (strlen($content) > self::MAX_FILE_SIZE) {
            throw new RuntimeException('File size exceeds 100 MB limit');
        }

        $revisionFolder = $this->getRevisionFolder($transactionUuid, $revisionNumber);
        $safeFilename = $this->sanitizeFilename($originalFilename);
        $file = $revisionFolder->newFile($safeFilename);
        $file->putContent($content);

        return "evidence/{$transactionUuid}/{$revisionNumber}/{$safeFilename}";
    }

    /**
     * Read evidence file by its opaque key
     *
     * @param string $key The opaque key (evidence/<transaction-uuid>/<revision>/<filename>)
     * @return string File content
     */
    public function readEvidence(string $key): string
    {
        return $this->read($key);
    }

    /**
     * Delete draft evidence (before submission)
     *
     * @param string $key The opaque key
     */
    public function deleteDraftEvidence(string $key): void
    {
        $this->deleteDraft($key);
    }

    /**
     * Get file size for validation
     *
     * @param string $key The opaque key
     * @return int File size in bytes
     */
    public function getFileSize(string $key): int
    {
        $folder = $this->getEvidenceFolder();
        try {
            $file = $folder->getFile($key);
            return (int)$file->getSize();
        } catch (NotFoundException) {
            throw new RuntimeException("AppData file not found: {$key}");
        }
    }

    /**
     * SHA-256 of the given bytes.
     *
     * Deliberately not a storage read: the upload path runs inside the workflow's
     * open database transaction, and reading the file back there fails with
     * `dirty table reads` because the filecache row is still uncommitted.
     */
    public function checksumOfContent(string $content): string
    {
        return hash('sha256', $content);
    }

    /**
     * Sanitize filename for safe storage
     *
     * @param string $filename
     * @return string Sanitized filename
     * @psalm-suppress InvalidNullableReturnType
     * @psalm-suppress NullableReturnStatement
     */
    private function sanitizeFilename(string $filename): string
    {
        // Remove path components
        $filename = basename($filename);
        // Remove any characters that could cause issues
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        // Limit length
        /** @psalm-suppress PossiblyNullArgument */
        if ($filename !== '' && strlen($filename) > 255) {
            $pathInfo = pathinfo($filename);
            $ext = $pathInfo['extension'] ?? '';
            $name = $pathInfo['filename'] ?? $filename;
            $maxNameLen = 255 - strlen($ext) - 1;
            if ($maxNameLen > 0) {
                $filename = substr($name, 0, $maxNameLen) . '.' . $ext;
            }
        }
        return $filename;
    }
}