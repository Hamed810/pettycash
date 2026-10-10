<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Application\Port\PrivateFileStore;
use RuntimeException;

final class InMemoryPrivateFileStore implements PrivateFileStore
{
    private array $files = [];

    public function put(string $key, string $content): void
    {
        $this->files[$key] = $content;
    }

    public function read(string $key): string
    {
        // Deliberately faithful to AppDataEvidenceStore::read(), which throws RuntimeException for a
        // missing AppData file. Returning '' for an absent key would make this double disagree with the
        // real adapter on exactly the branch the archive exporter uses to decide "unreadable, omit it",
        // and a test asserting that branch would then pass without proving anything.
        if (!array_key_exists($key, $this->files)) {
            throw new RuntimeException("AppData file not found: {$key}");
        }

        return $this->files[$key];
    }

    public function deleteDraft(string $key): void
    {
        unset($this->files[$key]);
    }

    public function exists(string $key): bool
    {
        return isset($this->files[$key]);
    }

    public function putEvidence(string $transactionUuid, int $revisionNumber, string $originalFilename, string $content): string
    {
        $key = "evidence/{$transactionUuid}/{$revisionNumber}/{$originalFilename}";
        $this->put($key, $content);
        return $key;
    }

    public function readEvidence(string $key): string
    {
        return $this->read($key);
    }

    public function deleteDraftEvidence(string $key): void
    {
        $this->deleteDraft($key);
    }

    public function getFileSize(string $key): int
    {
        return strlen($this->files[$key] ?? '');
    }

    public function checksumOfContent(string $content): string
    {
        return hash('sha256', $content);
    }
}