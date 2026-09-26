<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Domain\Evidence\Evidence;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository;

final class InMemoryEvidenceRepository implements \OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository
{
    /**
     * @var array<int, Evidence>
     */
    private array $evidence = [];

    public function findById(int $id): ?Evidence
    {
        return $this->evidence[$id] ?? null;
    }

    public function findByPublicId(string $publicId): ?Evidence
    {
        foreach ($this->evidence as $ev) {
            if ($ev->publicId === $publicId) {
                return $ev;
            }
        }
        return null;
    }

    /**
     * @return list<Evidence>
     */
    public function findByTransactionId(int $transactionId, bool $activeOnly = false): array
    {
        return array_values(array_filter($this->evidence, function ($e) use ($transactionId, $activeOnly) {
            if ($e->transactionId !== $transactionId) {
                return false;
            }
            if ($activeOnly && !$e->active) {
                return false;
            }
            return true;
        }));
    }

    /**
     * @return list<Evidence>
     */
    public function findByRevisionId(int $revisionId): array
    {
        return array_values(array_filter($this->evidence, fn($e) => $e->originRevisionId === $revisionId));
    }

    public function save(Evidence $evidence): void
    {
        if ($evidence->dbId === null) {
            $evidence->dbId = count($this->evidence) + 1;
        }
        $this->evidence[$evidence->dbId] = $evidence;
    }

    public function delete(Evidence $evidence): void
    {
        if ($evidence->dbId !== null) {
            unset($this->evidence[$evidence->dbId]);
        }
    }
}