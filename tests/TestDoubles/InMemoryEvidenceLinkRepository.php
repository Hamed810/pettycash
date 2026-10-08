<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Domain\Evidence\EvidenceLink;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceLinkRepository;

/**
 * Mirrors `DbEvidenceLinkRepository`, including the surrogate-id key it assigns on save.
 *
 * Built here rather than reused because the production adapter had **no** in-memory counterpart: the
 * repository, its interface and the migration all existed, and `findByTokenHash()` was called from
 * nowhere — so FR-031's consumption path had nothing to read a token through, and a test asserting it
 * had no seam to assert on.
 */
final class InMemoryEvidenceLinkRepository implements EvidenceLinkRepository
{
    /** @var array<int, EvidenceLink> */
    private array $links = [];

    public function findById(int $id): ?EvidenceLink
    {
        return $this->links[$id] ?? null;
    }

    public function findByPublicId(string $publicId): ?EvidenceLink
    {
        foreach ($this->links as $link) {
            if ($link->publicId === $publicId) {
                return $link;
            }
        }

        return null;
    }

    public function findByTokenHash(string $tokenHash): ?EvidenceLink
    {
        foreach ($this->links as $link) {
            if ($link->tokenHash === $tokenHash) {
                return $link;
            }
        }

        return null;
    }

    /** @return list<EvidenceLink> */
    public function findByEvidenceId(int $evidenceId): array
    {
        return array_values(array_filter($this->links, static fn (EvidenceLink $l): bool => $l->evidenceId === $evidenceId));
    }

    public function save(EvidenceLink $link): void
    {
        // Mirrors the adapter: the surrogate key and the `publicId` mirror are both assigned from the
        // aggregate on the way out, so a just-saved link resolves by either. Without the `publicId`
        // mirror, `findByPublicId()` could not find a row `save()` had just written.
        if ($link->dbId === null) {
            $link->dbId = count($this->links) + 1;
        }
        if ($link->publicId === null) {
            $link->publicId = $link->id;
        }
        $this->links[$link->dbId] = $link;
    }

    public function clear(): void
    {
        $this->links = [];
    }

    /** @return list<EvidenceLink> */
    public function all(): array
    {
        return array_values($this->links);
    }
}