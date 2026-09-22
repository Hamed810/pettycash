<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Evidence;

use DateTimeImmutable;

final class Evidence
{
    public function __construct(
        public string $id,
        public int $transactionId,
        public int $originRevisionId,
        public string $appDataKey,
        public string $originalName,
        public string $mediaType,
        public int $sizeBytes,
        public string $checksum,
        public bool $active = true,
        public ?string $uploadedBy = null,
        public ?DateTimeImmutable $uploadedAt = null,
        public ?string $deactivatedBy = null,
        public ?DateTimeImmutable $deactivatedAt = null,
        public ?string $deactivationReason = null,
        public ?int $dbId = null,
        public ?string $publicId = null
    ) {}

    public function deactivate(string $deactivatedBy, string $reason): void
    {
        $this->active = false;
        $this->deactivatedBy = $deactivatedBy;
        $this->deactivatedAt = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->deactivationReason = $reason;
    }
}