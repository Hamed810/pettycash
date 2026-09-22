<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Evidence\Repository;

use OCA\PettyCash\Domain\Evidence\Evidence;

/** @extends \IteratorAggregate<int, Evidence> */
interface EvidenceRepository
{
    public function findById(int $id): ?Evidence;
    public function findByPublicId(string $publicId): ?Evidence;
    /** @return list<Evidence> */
    public function findByTransactionId(int $transactionId, bool $activeOnly = false): array;
    /** @return list<Evidence> */
    public function findByRevisionId(int $revisionId): array;
    public function save(Evidence $evidence): void;
}