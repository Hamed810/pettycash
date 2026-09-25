<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Persistence\Workflow;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Workflow\WorkflowDecision;
use OCA\PettyCash\Domain\Workflow\WorkflowStage;
use OCA\PettyCash\Domain\Workflow\Repository\WorkflowDecisionRepository;
use OCA\PettyCash\Domain\Workflow\Decision;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class DbWorkflowDecisionRepository implements WorkflowDecisionRepository
{
    public function __construct(
        private readonly IDBConnection $connection,
        private readonly UserDirectory $userDirectory,
        private readonly CurrentUser $currentUser
    ) {}

    public function findById(int $id): ?WorkflowDecision
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_workflow_decisions')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findByPublicId(string $publicId): ?WorkflowDecision
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_workflow_decisions')
            ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findByCommandId(string $commandId): ?WorkflowDecision
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_workflow_decisions')
            ->where($qb->expr()->eq('command_id', $qb->createNamedParameter($commandId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    /** @return list<WorkflowDecision> */
    public function findByTransactionId(int $transactionId): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('d.*')
            ->from('pettycash_workflow_decisions', 'd')
            ->join('d', 'pettycash_transaction_revisions', 'r', $qb->expr()->eq('d.revision_id', 'r.id'))
            ->join('r', 'pettycash_transactions', 't', $qb->expr()->eq('r.transaction_id', 't.id'))
            ->where($qb->expr()->eq('t.id', $qb->createNamedParameter($transactionId)))
            ->orderBy('d.decided_at', 'ASC');
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /** @return list<WorkflowDecision> */
    public function findByRevisionAndStage(int $revisionId, WorkflowStage $stage): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_workflow_decisions')
            ->where($qb->expr()->eq('revision_id', $qb->createNamedParameter($revisionId)))
            ->andWhere($qb->expr()->eq('stage', $qb->createNamedParameter($stage->value)))
            ->orderBy('decided_at', 'ASC');
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    public function save(WorkflowDecision $decision): void
    {
        $qb = $this->connection->getQueryBuilder();
        $existing = $decision->dbId ? $this->findById($decision->dbId) : $this->findByPublicId($decision->id);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($existing) {
            // Decisions are immutable - should not be updated
            return;
        } else {
            $qb->insert('pettycash_workflow_decisions')
                ->values([
                    'public_id' => $qb->createNamedParameter($decision->id),
                    'transaction_id' => $qb->createNamedParameter(
                        $this->getTransactionDbIdForRevision($decision->revisionId)
                    ),
                    'revision_id' => $qb->createNamedParameter($this->getRevisionDbId($decision->revisionId)),
                    'stage' => $qb->createNamedParameter($decision->stage->value),
                    'decision' => $qb->createNamedParameter($decision->decision->value),
                    'explanation' => $qb->createNamedParameter($decision->explanation ?? ''),
                    'actor_uid' => $qb->createNamedParameter($decision->actorUid ?? $this->currentUser->uid()),
                    'actor_name' => $qb->createNamedParameter($decision->actorName ?? $this->userDirectory->displayName($decision->actorUid ?? $this->currentUser->uid())),
                    'actor_role' => $qb->createNamedParameter($decision->actorRole ?? 'FINANCE'),
                    'command_id' => $qb->createNamedParameter($decision->commandId),
                    'decided_at' => $decision->decidedAt ? $qb->createNamedParameter($decision->decidedAt->format('Y-m-d H:i:s')) : $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE),
                ])
                ->executeStatement();
            
            $decision->dbId = $qb->getLastInsertId();
        }
    }

    /**
     * Resolve the owning transaction through the revision's own `transaction_id`
     * join key.
     *
     * Deliberately NOT routed through `pettycash_transactions.active_revision_id`: a
     * decision stays attached to the revision it was made against, and once a
     * correction activates a new revision the superseded one must still resolve,
     * otherwise an immutable decision would be silently orphaned.
     */
    private function getTransactionDbIdForRevision(string $revisionPublicId): int
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('r.transaction_id')
            ->from('pettycash_transaction_revisions', 'r')
            ->where($qb->expr()->eq('r.public_id', $qb->createNamedParameter($revisionPublicId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();

        if ($row === false || $row['transaction_id'] === null) {
            throw new \LogicException(
                'Cannot persist a workflow decision whose owning revision is unknown'
            );
        }

        return (int) $row['transaction_id'];
    }

    private function getRevisionDbId(string $publicId): int
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('id')
            ->from('pettycash_transaction_revisions')
            ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? (int) $row['id'] : 0;
    }

    /**
     * Resolve an internal `pettycash_transactions.id` to its external UUID.
     */
    private function getTransactionPublicId(int $id): string
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('public_id')
            ->from('pettycash_transactions')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();

        if ($row === false) {
            throw new \LogicException(
                'Workflow decision references a transaction that no longer exists'
            );
        }

        return (string) $row['public_id'];
    }

    /**
     * Resolve an internal `pettycash_transaction_revisions.id` to its external UUID.
     */
    private function getRevisionPublicId(int $id): string
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('public_id')
            ->from('pettycash_transaction_revisions')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();

        if ($row === false) {
            throw new \LogicException(
                'Workflow decision references a revision that no longer exists'
            );
        }

        return (string) $row['public_id'];
    }

    private function mapRow(array $row): WorkflowDecision
    {
        $decision = new WorkflowDecision(
            $row['public_id'],
            $this->getTransactionPublicId((int) $row['transaction_id']),
            $this->getRevisionPublicId((int) $row['revision_id']),
            WorkflowStage::from($row['stage']),
            Decision::from($row['decision']),
            $row['explanation'] ?? null,
            $row['command_id'],
            $row['decided_at'] ? new DateTimeImmutable($row['decided_at']) : null,
            $row['actor_uid'] ?? null,
            $row['actor_name'] ?? null,
            $row['actor_role'] ?? null
        );
        $decision->dbId = (int) $row['id'];
        $decision->publicId = $row['public_id'];
        return $decision;
    }
}