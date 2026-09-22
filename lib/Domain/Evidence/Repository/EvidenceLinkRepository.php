<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Evidence\Repository;

use OCA\PettyCash\Domain\Evidence\EvidenceLink;

/** @extends \IteratorAggregate<int, EvidenceLink> */
interface EvidenceLinkRepository
{
    public function findById(int $id): ?EvidenceLink;
    public function findByPublicId(string $publicId): ?EvidenceLink;
    public function findByTokenHash(string $tokenHash): ?EvidenceLink;
    /** @return list<EvidenceLink> */
    public function findByEvidenceId(int $evidenceId): array;
    public function save(EvidenceLink $link): void;
}