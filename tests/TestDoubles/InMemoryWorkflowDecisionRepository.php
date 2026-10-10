<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\TestDoubles;

use OCA\PettyCash\Domain\Workflow\WorkflowDecision;
use OCA\PettyCash\Domain\Workflow\Repository\WorkflowDecisionRepository;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;

final class InMemoryWorkflowDecisionRepository implements \OCA\PettyCash\Domain\Workflow\Repository\WorkflowDecisionRepository
{
    /**
     * @param \OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository|null $revisions
     *        Resolves the *surrogate* revision id that `findByRevisionAndStage()` is declared in
     *        terms of. Optional so the double can still be built on its own, but a caller that omits
     *        it gets a double that can never match — see the note on that method.
     */
    public function __construct(
        private readonly ?\OCA\PettyCash\Domain\Expense\Repository\TransactionRevisionRepository $revisions = null,
    ) {}

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
        // **This had two independent defects and could never match anything.**
        //
        // 1. The stage comparison was `$d->stage === $stage->value`. `$d->stage` is a
        //    `WorkflowStage` enum case and `$stage->value` is a string, so that is `enum === string`
        //    and is always false. The DB adapter compares the stored *column*, which really is a
        //    string, so the double was reading the wrong side of the mapping it was standing in for.
        //
        // 2. The revision comparison was `$d->revisionId === $revisionId`. `revisionId` on the
        //    aggregate is the revision's **public UUID string**, while the parameter is the surrogate
        //    key — what the port declares, what `DbWorkflowDecisionRepository` queries
        //    (`WHERE revision_id = <surrogate>`), and what the real-database round-trip test passes.
        //
        // Together they made every call return `[]` while looking like coverage. That matters more
        // than a usual broken double: FR-085's already-decised check reads exactly this method, so
        // an in-memory suite could never have observed a superseded attempt being refused — and a
        // green test suite reads as evidence the branch is covered when it never was.
        return array_values(array_filter(
            $this->decisions,
            fn ($d) => $d->stage === $stage
                && $this->surrogateRevisionIdOf($d) === $revisionId,
        ));
    }

    private function surrogateRevisionIdOf(WorkflowDecision $decision): ?int
    {
        return $this->revisions?->findByPublicId($decision->revisionId)?->dbId;
    }

    public function save(WorkflowDecision $decision): void
    {
        if ($decision->dbId === null) {
            $decision->dbId = count($this->decisions) + 1;
        }
        $this->decisions[$decision->dbId] = $decision;
    }
}