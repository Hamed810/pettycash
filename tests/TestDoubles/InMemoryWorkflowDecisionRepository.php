<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Domain\Workflow\WorkflowDecision;
use OCA\PettyCash\Domain\Workflow\Repository\WorkflowDecisionRepository;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;

final class InMemoryWorkflowDecisionRepository implements \OCA\PettyCash\Domain\Workflow\Repository\WorkflowDecisionRepository
{
    /**
     * @var array<int, WorkflowDecision>
     */
    private array $decisions = [];

    public function findById(int $id): ?WorkflowDecision
    {
        return $this->decisions[$id] ?? null;
    }

    public function findByPublicId(string $publicId): ?WorkflowDecision
    {
        foreach ($this->decisions as $decision) {
            if ($decision->publicId === $publicId) {
                return $decision;
            }
        }
        return null;
    }

    public function findByCommandId(string $commandId): ?WorkflowDecision
    {
        foreach ($this->decisions as $decision) {
            if ($decision->commandId === $commandId) {
                return $decision;
            }
        }
        return null;
    }

    /**
     * @return list<WorkflowDecision>
     */
    public function findByTransactionId(int $transactionId): array
    {
        return array_values(array_filter($this->decisions, fn($d) => $d->transactionId === $transactionId));
    }

    /**
     * @return list<WorkflowDecision>
     */
    public function findByRevisionAndStage(int $revisionId, \OCA\PettyCash\Domain\Workflow\WorkflowStage $stage): array
    {
        return array_values(array_filter($this->decisions, fn($d) => $d->revisionId === $revisionId && $d->stage === $stage->value));
    }

    public function save(WorkflowDecision $decision): void
    {
        if ($decision->dbId === null) {
            $decision->dbId = count($this->decisions) + 1;
        }
        $this->decisions[$decision->dbId] = $decision;
    }
}