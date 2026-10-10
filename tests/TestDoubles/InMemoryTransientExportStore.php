<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Application\Port\TransientExportStore;
use RuntimeException;

/**
 * In-memory transient export store.
 *
 * `scratchPath()` returns a real temporary file, because the code under test hands that path to
 * `ZipArchive`, which has no stream API — a double that returned a fake path would test the double. The
 * *artifacts* are held in memory so a test can read one back without a Nextcloud runtime.
 *
 * `persist()` counts its calls and the bytes it moved, so a test can assert that an artifact reached
 * storage rather than inferring it from a returned string.
 */
final class InMemoryTransientExportStore implements TransientExportStore
{
    /** @var array<string, string> */
    private array $artifacts = [];

    /** @var list<string> */
    private array $scratchPaths = [];

    private int $persistCount = 0;

    private int $deleteAllCount = 0;

    public function scratchPath(string $name): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pettycash-double-');
        if ($path === false) {
            throw new RuntimeException('Could not create a scratch file');
        }
        $this->scratchPaths[] = $path;

        return $path;
    }

    public function persist(string $name, string $scratchPath): void
    {
        $bytes = @file_get_contents($scratchPath);
        if ($bytes === false) {
            throw new RuntimeException("Nothing to persist at {$scratchPath}");
        }

        $this->artifacts[$name] = $bytes;
        $this->persistCount++;
    }

    public function read(string $name)
    {
        if (!isset($this->artifacts[$name])) {
            throw new RuntimeException("No stored artifact named {$name}");
        }

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new RuntimeException('Could not open a stream for the stored artifact');
        }
        fwrite($handle, $this->artifacts[$name]);
        rewind($handle);

        return $handle;
    }

    public function exists(string $name): bool
    {
        return isset($this->artifacts[$name]);
    }

    public function size(string $name): ?int
    {
        return isset($this->artifacts[$name]) ? strlen($this->artifacts[$name]) : null;
    }

    public function delete(string $name): void
    {
        unset($this->artifacts[$name]);
    }

    public function deleteAll(): void
    {
        $this->artifacts = [];
        $this->deleteAllCount++;
    }

    // ===== Test-facing state =====

    public function artifactNames(): array
    {
        return array_keys($this->artifacts);
    }

    public function artifactBytes(string $name): string
    {
        return $this->artifacts[$name] ?? '';
    }

    public function persistCount(): int
    {
        return $this->persistCount;
    }

    public function deleteAllCount(): int
    {
        return $this->deleteAllCount;
    }

    /** @return list<string> */
    public function scratchPaths(): array
    {
        return $this->scratchPaths;
    }

    /**
     * Scratch files the double created that are still on disk.
     *
     * A build is required to remove its scratch whether or not it succeeded, so a test asserts on what
     * survived rather than on a cleanup method having been called.
     *
     * @return list<string>
     */
    public function survivingScratchFiles(): array
    {
        return array_values(array_filter(
            $this->scratchPaths,
            static fn (string $path): bool => is_file($path)
        ));
    }
}
