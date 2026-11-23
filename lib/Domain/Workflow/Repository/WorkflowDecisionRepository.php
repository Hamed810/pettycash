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

    /**
     * Every decision attached to a set of transactions, in one batched read.
     *
     * Added for T016/T018. The completed Cost Sheet detail renders the full decision history per
     * transaction; a per-transaction `findByTransactionId()` read would make the detail endpoint
     * N+1 in the number of transactions on the sheet. The rows are returned in stored order so the
     * caller can present them chronologically without a second sort, and an empty input returns an
     * empty list without issuing a query.
     *
     * @param list<int> $transactionDbIds surrogate `pettycash_transactions.id` values
     * @return list<WorkflowDecision>
     */
    public function findByTransactionIds(array $transactionDbIds): array;

    /** @return list<WorkflowDecision> */
    public function findByRevisionAndStage(int $revisionId, WorkflowStage $stage): array;
    public function save(WorkflowDecision $decision): void;
}