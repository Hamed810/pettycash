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
        // `publicId` is assigned from the aggregate's own `id`, mirroring
        // `DbExpenseTransactionRepository::save()` (line 237) and `DbEvidenceRepository::save()`. The
        // real insert writes the `id` into the `public_id` column, so the two are the same value in
        // production -- but the property was left null here, which meant `findByPublicId()` could never
        // find anything a test had just saved, and a test could pass while production could not resolve
        // the id it had been given. Any double that does not mirror this hides that difference.
        if ($evidence->publicId === null) {
            $evidence->publicId = $evidence->id;
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