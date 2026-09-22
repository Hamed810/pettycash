<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Workflow\Repository;

use OCA\PettyCash\Domain\Workflow\WorkflowDecision;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;

/** @extends \IteratorAggregate<int, WorkflowDecision> */
interface WorkflowDecisionRepository
{
    public function findById(int $id): ?WorkflowDecision;
    public function findByPublicId(string $publicId): ?WorkflowDecision;
    public function findByCommandId(string $commandId): ?WorkflowDecision;
    /** @return list<WorkflowDecision> */
    public function findByTransactionId(int $transactionId): array;
    /** @return list<WorkflowDecision> */
    public function findByRevisionAndStage(int $revisionId, WorkflowStage $stage): array;
    public function save(WorkflowDecision $decision): void;
}