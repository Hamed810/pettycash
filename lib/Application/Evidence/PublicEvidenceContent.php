<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Evidence;

/**
 * What a public link access resolves to: the bytes, and the two headers FR-060 decided.
 *
 * Deliberately a value object rather than an `Evidence` plus a controller that re-derives the headers.
 * The media-type decision is FR-060's, and it has to be made **once**: if the service resolved the type
 * and the controller then re-derived it from the same stored value, a change to the policy's rules would
 * have to land in two places, and this route is the one where the served media type reaches a viewer who
 * never authenticated.
 */
final readonly class PublicEvidenceContent
{
    public function __construct(
        public string $bytes,
        /** The media type actually served — the stored one when inline-safe, otherwise the fallback. */
        public string $contentType,
        /** `inline` or `attachment`, already resolved against the stored media type. */
        public string $disposition,
        public string $originalName,
        public int $sizeBytes,
    ) {}

    /**
     * The `Content-Disposition` header value.
     *
     * Built from the **resolved** disposition rather than the requested one, so a caller who asked for
     * `inline` on a `text/html` receipt is answered `attachment`. That is the whole of FR-060 on this path.
     */
    public function contentDispositionHeader(): string
    {
        return sprintf('%s; filename="%s"', $this->disposition, $this->originalName);
    }
}