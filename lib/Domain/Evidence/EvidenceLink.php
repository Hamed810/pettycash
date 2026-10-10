<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Evidence;

use DateTimeImmutable;

final class EvidenceLink
{
    public function __construct(
        public string $id,
        public int $evidenceId,
        public string $tokenHash,
        public bool $active = false,
        public ?DateTimeImmutable $expiresAt = null,
        public ?string $createdBy = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?string $deactivatedBy = null,
        public ?DateTimeImmutable $deactivatedAt = null,
        public ?int $dbId = null,
        public ?string $publicId = null
    ) {}

    public function deactivate(string $deactivatedBy): void
    {
        $this->active = false;
        $this->deactivatedBy = $deactivatedBy;
        $this->deactivatedAt = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}