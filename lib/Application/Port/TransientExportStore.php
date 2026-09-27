<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Port;

/**
 * Private, transient storage for generated export artifacts.
 *
 * ## Why this exists as a port rather than a helper
 *
 * `contracts/exports.md` requires that "generated artifacts use private transient application storage
 * and are removed after streaming", and `plan.md` names Nextcloud `IAppData` as the mechanism. A
 * group evidence archive is exactly the material FR-060's secrecy requirement and ADR-001 exist to
 * protect, so "write it to the system temp directory and hope" is not a storage decision — in a shared
 * container the system temp directory is shared with every other app and every other process on the
 * host.
 *
 * The port is here so the exporter stays testable without a Nextcloud runtime, which is the same
 * reason `PrivateFileStore` exists for evidence.
 *
 * ## Why there is both a scratch path and a persisted artifact
 *
 * `ZipArchive` can only write to a filesystem path — it has no stream API — so building the archive
 * requires one. That build location is **scratch**, not an artifact: it holds bytes that are about to be
 * moved into app-private storage, and it is removed the moment they are. The **artifact** is what
 * `persist()` writes and what `read()` returns, and it lives in app-private storage for exactly as long
 * as the download takes.
 *
 * Separating the two is what lets the build use a filesystem path without the contract being quietly
 * violated: the file that persists is never the file the archive was assembled in.
 */
interface TransientExportStore
{
    /**
     * A writable filesystem path to assemble an artifact in, outside app-private storage.
     *
     * Scratch only. Whatever is at this path must be treated as temporary and must be removable
     * without regard to whether the build succeeded.
     */
    public function scratchPath(string $name): string;

    /**
     * Move a built scratch file into app-private transient storage under `$name`.
     *
     * Must stream rather than buffer, so that a large archive is bounded by disk rather than by PHP's
     * memory limit.
     */
    public function persist(string $name, string $scratchPath): void;

    /**
     * Open a persisted artifact for reading.
     *
     * @return resource
     */
    public function read(string $name);

    public function exists(string $name): bool;

    /**
     * Size in bytes of a persisted artifact, or null when it is absent.
     */
    public function size(string $name): ?int;

    public function delete(string $name): void;

    /**
     * Remove every persisted artifact this store owns.
     */
    public function deleteAll(): void;
}
