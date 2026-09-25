<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Application\Port\PrivateFileStore;

final class InMemoryPrivateFileStore implements PrivateFileStore
{
    private array $files = [];

    public function put(string $key, string $content): void
    {
        $this->files[$key] = $content;
    }

    public function read(string $key): string
    {
        return $this->files[$key] ?? '';
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